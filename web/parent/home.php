<?php
require __DIR__.'/../lib/lms.php';require_once __DIR__.'/../lib/notify.php';require __DIR__.'/../lib/attendance.php';require __DIR__.'/../lib/rewards.php';require __DIR__.'/../lib/oauth.php';$p=require_parent();
if(($_POST['action']??'')==='notify'){ // phụ huynh tự đăng ký nhận tin (đồng ý), có thể tắt bất cứ lúc nào
 $ch=$_POST['channel']??'none';$phone=normalize_phone((string)($_POST['phone']??''));
 if($ch!=='none'&&!isset(notify_channels()[$ch]))flash(t('Kênh này chưa được nhà trường bật.'),'bad');
 elseif($ch!=='none'&&!$phone)flash(t('Số điện thoại di động Việt Nam 10 số, VD 0912345678.'),'bad');
 else{q('UPDATE parents SET phone=?,notify_channel=? WHERE id=?',[$ch==='none'?null:$phone,$ch,$p['id']]);flash($ch==='none'?t('Đã tắt nhận tin.'):t('Đã đăng ký nhận tin qua {c}.',['c'=>NOTIFY_CHANNELS[$ch]]));}
 redirect('home.php');
}
if($_SERVER['REQUEST_METHOD']==='POST')try{
 db()->beginTransaction();$i=q1('SELECT * FROM parent_invites WHERE token_hash=? AND used_at IS NULL AND expires_at>NOW() FOR UPDATE',[parent_invite_hash((string)($_POST['invite']??''))]);if(!$i||!qv('SELECT 1 FROM students WHERE class_id=? AND student_code=?',[$i['class_id'],$i['student_code']])){parent_invite_fail();throw new RuntimeException(t('Mã mời không hợp lệ.'));}q('INSERT IGNORE INTO parent_links(parent_id,student_code,class_id) VALUES(?,?,?)',[$p['id'],$i['student_code'],$i['class_id']]);q('UPDATE parent_invites SET used_at=NOW() WHERE id=?',[$i['id']]);db()->commit();redirect('home.php');
}catch(Throwable $e){if(db()->inTransaction())db()->rollBack();flash($e->getMessage(),'bad');}
$children=qall('SELECT st.*,c.name AS class_name FROM parent_links l JOIN students st ON st.student_code=l.student_code AND st.class_id=l.class_id JOIN classes c ON c.id=l.class_id WHERE l.parent_id=? ORDER BY st.full_name,c.name',[$p['id']]);
$tab = ($_GET['tab'] ?? '') === 'account' ? 'account' : 'home';
parent_head(t($tab === 'account' ? 'Tài khoản' : 'Tiến độ của con'));
$first = fn(string $n) => trim(strrchr(' ' . $n, ' '));
$link_form = '<form method="post" class="p-link">' . csrf_field() . '<input name="invite" required autocomplete="off" placeholder="' . te('Mã mời, VD: K7PM-3QXA') . '"><button class="primary">' . te('Liên kết') . '</button></form>';

// ======================================================== TÀI KHOẢN
if ($tab === 'account') {
    $pp = q1('SELECT phone,notify_channel,username FROM parents WHERE id=?', [$p['id']]); $chs = notify_channels();
    echo '<div class="page-head"><div><h1>' . te('Tài khoản') . '</h1><p class="muted">' . te('Liên kết thêm con, nhận thông báo kết quả, đăng nhập bằng Google.') . '</p></div></div><div class="p-acc">';
    echo '<section class="card">' . ui_card_head('👪 ' . te('Con đã liên kết'), t('Mỗi mã mời dùng cho một học sinh trong một lớp.'));
    foreach ($children as $c) echo '<div class="p-acc-child"><span class="p-ava">' . h(mb_strtoupper(mb_substr($first($c['full_name']), 0, 1))) . '</span><div><b>' . h($c['full_name']) . '</b><small>' . h($c['class_name']) . ' · ' . h($c['student_code']) . '</small></div></div>';
    if (!$children) echo '<p class="muted">' . te('Chưa liên kết học sinh nào.') . '</p>';
    echo '<h4>' . te('Liên kết thêm con / lớp') . '</h4>' . $link_form . '<p class="muted small">' . te('Mã 8 ký tự hoặc mã QR do giáo viên gửi riêng cho bạn.') . '</p></section>';
    echo '<section class="card">' . ui_card_head('📨 ' . te('Nhận kết quả buổi học'), t('Sau mỗi buổi học thầy cô công bố, bạn nhận tin dạng “cháu trả lời đúng 8/10 câu hỏi bằng Remote”.'));
    if ($chs) {
        echo '<form method="post" class="stack">' . csrf_field() . '<input type="hidden" name="action" value="notify"><label>' . te('Kênh nhận tin') . '<select name="channel"><option value="none">' . te('Không nhận') . '</option>';
        foreach ($chs as $k => $v) echo '<option value="' . $k . '"' . ($pp['notify_channel'] === $k ? ' selected' : '') . '>' . te('Qua {c}', ['c' => $v]) . '</option>';
        echo '</select></label><label>' . te('Số điện thoại') . '<input name="phone" inputmode="tel" placeholder="09xxxxxxxx" value="' . h($pp['phone'] ?? '') . '"></label><button class="primary">' . te('Lưu') . '</button></form>';
    } else echo '<p class="muted">' . te('Nhà trường chưa bật kênh thông báo Zalo / SMS.') . '</p>';
    echo '</section>';
    echo '<section class="card">' . ui_card_head('🔒 ' . te('Đăng nhập và mật khẩu')) . '<p>' . te('Tên đăng nhập') . ': <b translate="no">' . h($pp['username']) . '</b></p>'
       . '<a class="btn" href="password.php">' . te('Đổi mật khẩu') . '</a></section>';
    if ($om = oauth_manage_html('parent', (string)$p['id'], '../')) echo '<section class="card" id="account">' . $om . '</section>';
    echo '</div>';
    page_foot(); exit;
}

// ======================================================== TIẾN ĐỘ CỦA CON
if (!$children) {
    echo '<div class="card p-welcome">' . ui_empty('👪', t('Chào mừng bạn đến cổng phụ huynh'), t('Nhập mã mời thầy cô gửi (8 ký tự) hoặc quét mã QR trên phiếu mời để xem kết quả học tập của con.'), $link_form) . '</div>';
    page_foot(); exit;
}
$ci = max(0, min(count($children) - 1, (int)($_GET['child'] ?? 0)));
$child = $children[$ci];
$code = $child['student_code']; $cid = (int)$child['class_id'];
$items = my_items($code, $cid); $topics = [];
foreach ($items as $r) {
    $tp = $r['topic'] ?: t('Chưa gắn chủ đề'); $topics[$tp] ??= ['n' => 0, 'ok' => 0];
    if ($r['status'] !== 'absent') { $topics[$tp]['n']++; $topics[$tp]['ok'] += (int)($r['status'] === 'right'); }
}
uasort($topics, fn($a, $b) => ($a['n'] ? $a['ok'] / $a['n'] : 0) <=> ($b['n'] ? $b['ok'] / $b['n'] : 0));
$pts = null; foreach (class_points($cid) as $row) if ($row['code'] === $code) $pts = $row;
// Chuyên cần tính theo điểm danh thật, kể cả buổi không có câu chấm điểm.
$session_rows = qall('SELECT s.id,s.started_at,qs.title,(SELECT COUNT(*) FROM attendance_final af WHERE af.session_id=s.id AND af.seat_no=? AND af.status="present") AS present,(SELECT COUNT(*) FROM session_questions sq JOIN questions q ON q.id=sq.question_id WHERE sq.session_id=s.id AND q.correct IS NOT NULL) AS n,(SELECT COUNT(*) FROM final_answers f JOIN session_questions sq ON sq.id=f.session_q_id JOIN questions q ON q.id=sq.question_id WHERE sq.session_id=s.id AND f.seat_no=? AND f.answer=q.correct) AS ok FROM sessions s JOIN question_sets qs ON qs.id=s.set_id WHERE s.class_id=? AND s.status="ended" AND s.released=1 ORDER BY s.started_at DESC', [$child['seat_no'], $child['seat_no'], $cid]);
$n_ses = count($session_rows); $n_present = count(array_filter($session_rows, fn($r) => $r['present']));
$scores = array_map(fn($r) => 10 * $r['ok'] / $r['n'], array_filter($session_rows, fn($r) => $r['present'] && $r['n']));
$avg = $scores ? round(array_sum($scores) / count($scores), 1) : null;
$hw = qall('SELECT h.title,h.due_at,s.submitted_at,s.released,s.id FROM homework h LEFT JOIN homework_submissions s ON s.homework_id=h.id AND s.student_code=? WHERE h.class_id=? AND h.published=1 ORDER BY h.id DESC', [$code, $cid]);
$att = $n_ses ? (int)round(100 * $n_present / $n_ses) : 0;
$n_sub = count(array_filter($hw, fn($r) => $r['submitted_at']));
$period = ($_GET['period'] ?? '') === 'week' ? 'week' : 'day';
$series = [];
foreach (array_reverse($session_rows) as $r) {
    $d = new DateTimeImmutable($r['started_at']); if ($period === 'week') $d = $d->modify('monday this week');
    $k = $d->format('d/m'); $series[$k] ??= [];
    if ($r['present'] && $r['n']) $series[$k][] = 10 * $r['ok'] / $r['n'];
}
$series = array_slice(array_map(fn($v) => $v ? array_sum($v) / count($v) : null, $series), -14, null, true);
$cm = qall('SELECT tc.*,t.full_name FROM teacher_comments tc JOIN teachers t ON t.id=tc.teacher_id WHERE tc.class_id=? AND tc.student_code=? ORDER BY tc.id DESC LIMIT 20', [$cid, $code]);
$ls = qall('SELECT id,no,title,file_name FROM lessons WHERE class_id=? AND published=1 AND file_store IS NOT NULL ORDER BY no DESC', [$cid]);
$last3 = array_slice(array_filter($session_rows, fn($r) => $r['present'] && $r['n']), 0, 3);
$trend = $last3 ? round(array_sum(array_map(fn($r) => 10 * $r['ok'] / $r['n'], $last3)) / count($last3), 1) : null;

// chọn con
if (count($children) > 1) {
    echo '<nav class="p-kids">';
    foreach ($children as $i => $c) echo '<a href="home.php?child=' . $i . '" class="' . ($i === $ci ? 'on' : '') . '"><span class="p-ava sm">' . h(mb_strtoupper(mb_substr($first($c['full_name']), 0, 1))) . '</span><span><b>' . h($c['full_name']) . '</b><small>' . h($c['class_name']) . '</small></span></a>';
    echo '</nav>';
}
// banner
$mood = $avg === null ? t('Chưa có buổi học nào được công bố kết quả.') : ($avg >= 8 ? t('Con đang học rất tốt — hãy khen ngợi con nhé! 🎉') : ($avg >= 5 ? t('Con học khá ổn, còn vài phần cần ôn thêm.') : t('Con cần thêm hỗ trợ — xem phần kiến thức cần ôn bên dưới.')));
echo '<section class="p-hero"><span class="p-ava lg">' . h(mb_strtoupper(mb_substr($first($child['full_name']), 0, 1))) . '</span><div class="p-hero-b"><span class="eyebrow">' . h(t_date(time())) . '</span>'
   . '<h1>' . h($child['full_name']) . '</h1><p>' . h($child['class_name']) . ' · ' . te('Mã học sinh') . ' ' . h($code) . '</p><p class="p-mood">' . h($mood) . '</p></div>'
   . '<details class="pop p-add"><summary class="btn">＋ ' . te('Liên kết thêm con') . '</summary><div class="pop-body">' . $link_form . '</div></details></section>';
// chỉ số
echo '<div class="ui-kpis">'
   . ui_kpi('🏆', (string)($pts['points'] ?? 0), t('Điểm thi đua'), $pts ? te('hạng {r} trong lớp', ['r' => $pts['rank']]) : '', 'orange')
   . ui_kpi('📅', $n_present . '/' . $n_ses, t('Chuyên cần (buổi có mặt)'), te('{p}% số buổi', ['p' => $att]), 'green', ui_ring($att))
   . ui_kpi('🎯', $avg === null ? '—' : (string)$avg, t('Điểm trung bình trên lớp / 10'), $trend !== null ? te('3 buổi gần nhất: {v}', ['v' => $trend]) : '', 'blue')
   . ui_kpi('📝', $n_sub . '/' . count($hw), t('Bài tập đã nộp'), count($hw) - $n_sub ? te('{n} bài chưa nộp', ['n' => count($hw) - $n_sub]) : (count($hw) ? te('Đã nộp đủ ✓') : ''), 'violet')
   . '</div>';
// thông báo từ lớp học: điểm danh + kết quả mỗi buổi (lib/attendance.php), chưa đọc hiện trước
$msgs = array_values(array_filter(parent_messages_for((int)$p['id'], 40), fn($m) => $m['student_code'] === $code));
$unread = count(array_filter($msgs, fn($m) => $m['read_at'] === null));
echo '<section class="card p-inbox">' . ui_card_head('🔔 ' . te('Thông báo') . ($unread ? ' <span class="tag warn">' . te('{n} mới', ['n' => $unread]) . '</span>' : ''),
        t('Điểm danh và kết quả sau mỗi buổi học / kiểm tra bằng Remote.'));
if (!$msgs) echo '<p class="muted">' . te('Chưa có thông báo nào. Sau mỗi buổi học thầy cô kết thúc, thông báo điểm danh của con sẽ hiện ở đây.') . '</p>';
foreach (array_slice($msgs, 0, 10) as $m)
    echo '<div class="p-msg' . ($m['read_at'] === null ? ' unread' : '') . '"><b>' . h($m['title']) . '</b>' . ($m['read_at'] === null ? ' <span class="tag info">' . te('mới') . '</span>' : '')
       . '<p>' . h($m['body']) . '</p><small class="muted">' . h($m['class_name']) . ' · ' . h(date('d/m/Y H:i', strtotime($m['created_at']))) . '</small></div>';
echo '</section>';
if ($unread) q('UPDATE parent_messages SET read_at=NOW() WHERE parent_id=? AND student_code=? AND read_at IS NULL', [(int)$p['id'], $code]);
echo '<div class="p-grid"><div class="p-main">';
// biểu đồ
echo '<section class="card">' . ui_card_head('📈 ' . te('Tiến độ theo ngày / tuần'), t('Điểm trung bình các buổi Remote trên lớp (thang 10).'),
        '<nav class="seg-mini"><a href="?child=' . $ci . '&period=day" class="' . ($period === 'day' ? 'on' : '') . '">' . te('Theo ngày') . '</a><a href="?child=' . $ci . '&period=week" class="' . ($period === 'week' ? 'on' : '') . '">' . te('Theo tuần') . '</a></nav>');
echo array_filter($series, fn($v) => $v !== null) ? '<div class="ui-chart-wrap tone-green">' . ui_line_chart($series, 10, t('Tiến độ điểm trung bình')) . '</div>' : ui_empty('📉', t('Chưa có dữ liệu.'), t('Biểu đồ hiện khi có buổi học được công bố kết quả.'));
echo '</section>';
// buổi học
echo '<section class="card flush">' . ui_card_head('🗓 ' . te('Điểm và chuyên cần trên lớp'), t('Các buổi học bằng Remote đã công bố kết quả.'));
if (!$session_rows) echo ui_empty('🗓', t('Chưa có buổi học nào.'));
else {
    echo '<table class="data"><thead><tr><th>' . te('Ngày') . '</th><th>' . te('Bài') . '</th><th>' . te('Có mặt') . '</th><th class="right">' . te('Điểm / 10') . '</th></tr></thead><tbody>';
    foreach (array_slice($session_rows, 0, 12) as $r) echo '<tr><td class="nowrap">' . h(date('d/m/Y', strtotime($r['started_at']))) . '<small class="muted">' . h(date('H:i', strtotime($r['started_at']))) . '</small></td><td>' . h($r['title']) . '</td><td>'
        . ($r['present'] ? '<span class="tag ok">✓ ' . te('Có mặt') . '</span>' : '<span class="tag bad">' . te('Vắng') . '</span>') . '</td><td class="right">' . ui_score($r['present'] && $r['n'] ? 10 * $r['ok'] / $r['n'] : null) . '</td></tr>';
    echo '</tbody></table>';
}
echo '</section>';
// bài tập
echo '<section class="card flush">' . ui_card_head('📝 ' . te('Bài tập về nhà'), t('Trạng thái nộp bài và điểm thầy cô đã công bố.'));
if (!$hw) echo ui_empty('📭', t('Chưa có bài tập.'));
else {
    echo '<ul class="p-list">';
    foreach ($hw as $r) {
        $score = $r['released'] ? q1('SELECT SUM(a.score) AS score,SUM(hq.max_score) AS max_score FROM homework_answers a JOIN homework_questions hq ON hq.id=a.hw_question_id WHERE a.submission_id=?', [$r['id']]) : null;
        $late = !$r['submitted_at'] && $r['due_at'] && strtotime($r['due_at']) < time();
        echo '<li><span class="ui-ico sm tone-' . ($r['submitted_at'] ? 'green' : ($late ? 'red' : 'orange')) . '">' . ($r['submitted_at'] ? '✓' : ($late ? '!' : '…')) . '</span><div><b>' . h($r['title']) . '</b><small class="muted">'
           . ($r['due_at'] ? te('Hạn nộp') . ' ' . h(date('d/m H:i', strtotime($r['due_at']))) : te('Không giới hạn thời gian')) . '</small></div><span class="sp"></span>'
           . ($r['submitted_at'] ? '<span class="tag ok">' . te('Đã nộp') . '</span>' : ($late ? '<span class="tag bad">' . te('Quá hạn') . '</span>' : '<span class="tag warn">' . te('Chưa nộp') . '</span>'))
           . ($score && $score['max_score'] ? ' ' . ui_score((float)$score['score'], (float)$score['max_score']) . '<small class="muted">/' . h(rtrim(rtrim($score['max_score'], '0'), '.')) . '</small>' : '') . '</li>';
    }
    echo '</ul>';
}
echo '</section></div><aside class="p-side">';
// kiến thức
echo '<section class="card">' . ui_card_head('🧠 ' . te('Kiến thức trên lớp'), t('Tỉ lệ trả lời đúng theo chủ đề — phần yếu nhất ở trên.'));
if (!$topics) echo ui_empty('🧠', t('Chưa có dữ liệu.'));
foreach (array_slice($topics, 0, 8, true) as $tp => $v) echo ui_bar($tp, $v['n'] ? 100 * $v['ok'] / $v['n'] : 0, ($v['n'] ? round(100 * $v['ok'] / $v['n']) : 0) . '% · ' . te('{n} câu', ['n' => $v['n']]));
echo '</section>';
// nhận xét
echo '<section class="card">' . ui_card_head('💬 ' . te('Nhận xét giáo viên'));
if (!$cm) echo ui_empty('💬', t('Chưa có nhận xét.'));
else { echo '<ol class="p-timeline">'; foreach ($cm as $r) echo '<li><p>' . nl2br(h($r['comment'])) . '</p><small>' . h($r['full_name']) . ' · ' . h(date('d/m/Y', strtotime($r['created_at']))) . '</small></li>'; echo '</ol>'; }
echo '</section>';
// tài liệu
echo '<section class="card">' . ui_card_head('📚 ' . te('Tài liệu bài giảng'));
if (!$ls) echo ui_empty('📚', t('Chưa có tài liệu.'));
else { echo '<ul class="p-files">'; foreach ($ls as $r) { $ext = strtoupper(pathinfo((string)$r['file_name'], PATHINFO_EXTENSION) ?: 'PDF'); echo '<li><a href="../file.php?lesson=' . (int)$r['id'] . '"><span class="ext ext-' . h(strtolower($ext)) . '">' . h($ext) . '</span><span>' . h(lesson_label($r)) . '</span></a></li>'; } echo '</ul>'; }
echo '</section></aside></div>';
page_foot();
