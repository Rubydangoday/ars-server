<?php
require __DIR__.'/../lib/lms.php';require __DIR__.'/../lib/notify.php';require __DIR__.'/../lib/rewards.php';require __DIR__.'/../lib/oauth.php';$p=require_parent();
if(($_POST['action']??'')==='notify'){ // phụ huynh tự đăng ký nhận tin (đồng ý), có thể tắt bất cứ lúc nào
 $ch=$_POST['channel']??'none';$phone=normalize_phone((string)($_POST['phone']??''));
 if($ch!=='none'&&!isset(notify_channels()[$ch]))flash(t('Kênh này chưa được nhà trường bật.'),'bad');
 elseif($ch!=='none'&&!$phone)flash(t('Số điện thoại di động Việt Nam 10 số, VD 0912345678.'),'bad');
 else{q('UPDATE parents SET phone=?,notify_channel=? WHERE id=?',[$ch==='none'?null:$phone,$ch,$p['id']]);flash($ch==='none'?t('Đã tắt nhận tin.'):t('Đã đăng ký nhận tin qua {c}.',['c'=>NOTIFY_CHANNELS[$ch]]));}
 redirect('home.php');
}
if($_SERVER['REQUEST_METHOD']==='POST')try{
 db()->beginTransaction();$i=q1('SELECT * FROM parent_invites WHERE token_hash=? AND used_at IS NULL AND expires_at>NOW() FOR UPDATE',[hash('sha256',trim($_POST['invite']??''))]);if(!$i||!qv('SELECT 1 FROM students WHERE class_id=? AND student_code=?',[$i['class_id'],$i['student_code']]))throw new RuntimeException(t('Mã mời không hợp lệ.'));q('INSERT IGNORE INTO parent_links(parent_id,student_code,class_id) VALUES(?,?,?)',[$p['id'],$i['student_code'],$i['class_id']]);q('UPDATE parent_invites SET used_at=NOW() WHERE id=?',[$i['id']]);db()->commit();redirect('home.php');
}catch(Throwable $e){if(db()->inTransaction())db()->rollBack();flash($e->getMessage(),'bad');}
$children=qall('SELECT st.*,c.name AS class_name FROM parent_links l JOIN students st ON st.student_code=l.student_code AND st.class_id=l.class_id JOIN classes c ON c.id=l.class_id WHERE l.parent_id=? ORDER BY st.full_name,c.name',[$p['id']]);
parent_head(t('Tiến độ của con'));
$period = ($_GET['period'] ?? '') === 'week' ? 'week' : 'day';
echo '<div class="page-head"><div><h1>' . te('Tiến độ của con') . '</h1><p class="muted">' . h(t_date(time())) . '</p></div>'
   . '<form method="post" class="row" style="margin:0">' . csrf_field() . '<input name="invite" required placeholder="' . te('Mã mời thêm con / lớp') . '"><button>' . te('Liên kết') . '</button></form></div>';
if (!$children) echo '<div class="card muted">' . te('Chưa liên kết học sinh nào. Nhập mã mời thầy cô gửi ở ô phía trên.') . '</div>';
foreach ($children as $child) {
    $code = $child['student_code']; $cid = (int)$child['class_id'];
    $items = my_items($code, $cid); $topics = [];
    foreach ($items as $r) {
        $tp = $r['topic'] ?: t('Chưa gắn chủ đề'); $topics[$tp] ??= ['n' => 0, 'ok' => 0];
        if ($r['status'] !== 'absent') { $topics[$tp]['n']++; $topics[$tp]['ok'] += (int)($r['status'] === 'right'); }
    }
    $pts = null; foreach (class_points($cid) as $row) if ($row['code'] === $code) $pts = $row;
    // Chuyên cần tính theo điểm danh thật, kể cả buổi không có câu chấm điểm.
    $session_rows = qall('SELECT s.id,s.started_at,qs.title,(SELECT COUNT(*) FROM session_logins l WHERE l.session_id=s.id AND l.seat_no=?) AS present,(SELECT COUNT(*) FROM session_questions sq JOIN questions q ON q.id=sq.question_id WHERE sq.session_id=s.id AND q.correct IS NOT NULL) AS n,(SELECT COUNT(*) FROM final_answers f JOIN session_questions sq ON sq.id=f.session_q_id JOIN questions q ON q.id=sq.question_id WHERE sq.session_id=s.id AND f.seat_no=? AND f.answer=q.correct) AS ok FROM sessions s JOIN question_sets qs ON qs.id=s.set_id WHERE s.class_id=? AND s.status="ended" AND s.released=1 ORDER BY s.started_at DESC', [$child['seat_no'], $child['seat_no'], $cid]);
    $n_ses = count($session_rows); $n_present = count(array_filter($session_rows, fn($r) => $r['present']));
    $scores = array_map(fn($r) => 10 * $r['ok'] / $r['n'], array_filter($session_rows, fn($r) => $r['present'] && $r['n']));
    $avg = $scores ? round(array_sum($scores) / count($scores), 1) : null;
    $hw = qall('SELECT h.title,s.submitted_at,s.released,s.id FROM homework h LEFT JOIN homework_submissions s ON s.homework_id=h.id AND s.student_code=? WHERE h.class_id=? AND h.published=1 ORDER BY h.id DESC', [$code, $cid]);
    $att = $n_ses ? round(100 * $n_present / $n_ses) : 0;
    echo '<section class="card"><div class="child-head"><span class="ava">' . h(mb_strtoupper(mb_substr(trim(strrchr(' ' . $child['full_name'], ' ')), 0, 1))) . '</span>'
       . '<div><h2>' . h($child['full_name']) . '</h2><p class="muted">' . h($child['class_name']) . ' · ' . te('Mã học sinh') . ' ' . h($code) . '</p></div></div>';
    echo '<div class="p-kpis">'
       . '<div class="p-kpi accent"><b data-count="' . ($pts['points'] ?? 0) . '">' . ($pts['points'] ?? 0) . '</b><span>🏆 ' . te('Điểm thi đua') . ($pts ? ' · ' . te('hạng {r} trong lớp', ['r' => $pts['rank']]) : '') . '</span></div>'
       . '<div class="p-kpi"><span class="p-ring" style="--p:' . $att . '" data-v="' . $att . '%"></span><b>' . $n_present . '/' . $n_ses . '</b><span>📅 ' . te('Chuyên cần (buổi có mặt)') . '</span></div>'
       . '<div class="p-kpi"><b>' . ($avg ?? '—') . '</b><span>🎯 ' . te('Điểm trung bình trên lớp / 10') . '</span></div>'
       . '<div class="p-kpi"><b>' . count(array_filter($hw, fn($r) => $r['submitted_at'])) . '/' . count($hw) . '</b><span>📝 ' . te('Bài tập đã nộp') . '</span></div></div>';
    echo '<div class="p-sec"><h3>📈 ' . te('Tiến độ theo ngày / tuần') . '</h3><form method="get" class="row"><select name="period"><option value="day">' . te('Theo ngày') . '</option><option value="week"' . ($period === 'week' ? ' selected' : '') . '>' . te('Theo tuần') . '</option></select><button>' . te('Xem') . '</button></form>'
       . parent_progress($session_rows, $period) . '</div>';
    echo '<div class="p-sec"><h3>🗓 ' . te('Điểm và chuyên cần trên lớp') . '</h3><table class="grid"><tr><th>' . te('Ngày / đề') . '</th><th>' . te('Có mặt') . '</th><th>' . te('Điểm / 10') . '</th></tr>';
    foreach ($session_rows as $r) echo '<tr><td>' . h($r['started_at'] . ' · ' . $r['title']) . '</td><td>' . ($r['present'] ? te('Có') : te('Vắng')) . '</td><td>' . ($r['present'] && $r['n'] ? round(10 * $r['ok'] / $r['n'], 1) : '—') . '</td></tr>';
    echo '</table></div><div class="p-sec"><h3>📝 ' . te('Bài tập về nhà') . '</h3>';
    if (!$hw) echo '<p class="muted">' . te('Chưa có bài tập.') . '</p>';
    foreach ($hw as $r) {
        $score = $r['released'] ? q1('SELECT SUM(a.score) AS score,SUM(hq.max_score) AS max_score FROM homework_answers a JOIN homework_questions hq ON hq.id=a.hw_question_id WHERE a.submission_id=?', [$r['id']]) : null;
        echo '<p>' . h($r['title']) . ' · ' . ($r['submitted_at'] ? '<span class="tag live">' . te('Đã nộp') . '</span>' : '<span class="tag off">' . te('Chưa nộp') . '</span>') . ($score ? ' · <b>' . h($score['score']) . '/' . h($score['max_score']) . '</b>' : '') . '</p>';
    }
    echo '</div><div class="p-sec"><h3>🧠 ' . te('Kiến thức trên lớp') . '</h3>';
    if (!$topics) echo '<p class="muted">' . te('Chưa có dữ liệu.') . '</p>';
    foreach ($topics as $tp => $v) { $pct = $v['n'] ? round(100 * $v['ok'] / $v['n']) : 0; echo '<p>' . h($tp) . ' · ' . $pct . '% (' . te('{n} câu', ['n' => $v['n']]) . ')</p><progress data-animate value="' . $pct . '" max="100" style="width:100%"></progress>'; }
    echo '</div><div class="p-sec"><h3>💬 ' . te('Nhận xét giáo viên') . '</h3>';
    $cm = qall('SELECT tc.*,t.full_name FROM teacher_comments tc JOIN teachers t ON t.id=tc.teacher_id WHERE tc.class_id=? AND tc.student_code=? ORDER BY tc.id DESC', [$cid, $code]);
    if (!$cm) echo '<p class="muted">' . te('Chưa có nhận xét.') . '</p>';
    foreach ($cm as $r) echo '<blockquote>' . nl2br(h($r['comment'])) . '<small> — ' . h($r['full_name'] . ' · ' . $r['created_at']) . '</small></blockquote>';
    echo '</div><div class="p-sec"><h3>📚 ' . te('Tài liệu bài giảng') . '</h3>';
    $ls = qall('SELECT id,no,title FROM lessons WHERE class_id=? AND published=1 AND file_store IS NOT NULL ORDER BY no', [$cid]);
    if (!$ls) echo '<p class="muted">' . te('Chưa có tài liệu.') . '</p>';
    foreach ($ls as $r) echo '<p><a href="../file.php?lesson=' . $r['id'] . '">' . h(lesson_label($r)) . '</a></p>';
    echo '</div></section>';
}
$pp = q1('SELECT phone,notify_channel FROM parents WHERE id=?', [$p['id']]); $chs = notify_channels();
if ($chs) {
    echo '<form method="post" class="card row">' . csrf_field() . '<input type="hidden" name="action" value="notify"><b>📨 ' . te('Nhận kết quả buổi học') . '</b><select name="channel"><option value="none">' . te('Không nhận') . '</option>';
    foreach ($chs as $k => $v) echo '<option value="' . $k . '"' . ($pp['notify_channel'] === $k ? ' selected' : '') . '>' . te('Qua {c}', ['c' => $v]) . '</option>';
    echo '</select><input name="phone" inputmode="tel" placeholder="09xxxxxxxx" value="' . h($pp['phone'] ?? '') . '"><button>' . te('Lưu') . '</button><span class="muted small">' . te('Sau mỗi buổi học thầy cô công bố, bạn nhận tin dạng “cháu trả lời đúng 8/10 câu hỏi bằng Remote”.') . '</span></form>';
}
if ($om = oauth_manage_html('parent', (string)$p['id'], '../')) echo '<section class="card" id="account">' . $om . '</section>';
page_foot();
