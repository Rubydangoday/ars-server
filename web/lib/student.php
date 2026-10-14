<?php
// Dùng chung cho cổng học sinh (web/student/*).
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

const REASONS = [
    'chua_hoc'       => 'Chưa học / quên kiến thức',
    'nham_khai_niem' => 'Nhầm khái niệm',
    'doc_sai_de'     => 'Đọc sai / hiểu sai đề',
    'tinh_sai'       => 'Tính toán / suy luận sai',
    'bam_nham'       => 'Bấm nhầm phím',
    'doan'           => 'Đoán mò',
    'khac'           => 'Lý do khác',
];
// Gợi ý rút kinh nghiệm theo lý do sai
const ADVICE = [
    'chua_hoc'       => 'Đọc lại phần lý thuyết của chủ đề rồi làm lượt "Ôn kỹ phần này" cho tới khi đúng hết.',
    'nham_khai_niem' => 'Lập bảng so sánh các khái niệm hay nhầm (định nghĩa, ví dụ, điểm khác nhau) và đọc lại lời giải thích từng câu.',
    'doc_sai_de'     => 'Gạch chân từ khoá trong đề (KHÔNG, luôn, tối đa, ít nhất…) và đọc hết các phương án trước khi chọn.',
    'tinh_sai'       => 'Viết từng bước ra giấy, kiểm tra đơn vị và thử lại kết quả với đáp án.',
    'bam_nham'       => 'Nhìn chữ hiện trên LED trước khi bấm OK; bấm nhầm thì chọn lại chữ đúng rồi OK lần nữa khi câu còn mở.',
    'doan'           => 'Câu đoán mò là phần chưa chắc: đánh dấu và ôn lại kể cả khi đoán đúng.',
    'khac'           => 'Ghi rõ lý do vào ô ghi chú để lần sau đọc lại.',
];

function stu(): ?array { return $_SESSION['student'] ?? null; }
function require_student(): array {
    $s = stu();
    if (!$s) { header('Location: index.php'); exit; }
    if (!empty($s['must_change']) && basename($_SERVER['SCRIPT_NAME']) !== 'account.php') { header('Location: account.php'); exit; }
    return $s;
}

/**
 * Học sinh vào lớp bằng mã tham gia lớp (6 ký tự giáo viên cung cấp). Trả về thông báo; lỗi thì ném RuntimeException.
 * Khoá dòng lớp để hai học sinh vào cùng lúc không bị trùng số ghế.
 */
function join_class(string $code, string $name, string $join): string {
    $join = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $join));
    if ($join === '') throw new RuntimeException('Nhập mã tham gia lớp (6 ký tự giáo viên cung cấp).');
    $own = db()->inTransaction();
    if (!$own) db()->beginTransaction();
    try {
        $cls = q1('SELECT * FROM classes WHERE join_code=? FOR UPDATE', [$join]);
        if (!$cls) throw new RuntimeException('Mã lớp không đúng. Kiểm tra lại mã giáo viên cung cấp (6 ký tự).');
        if (!$cls['join_open']) throw new RuntimeException('Lớp này đang khoá đăng ký. Nhờ giáo viên mở đăng ký.');
        if (q1('SELECT 1 FROM students WHERE class_id=? AND student_code=?', [$cls['id'], $code])) {
            $msg = 'Bạn đã ở trong lớp ' . $cls['name'] . '.';
        } else {
            $used = array_map('intval', array_column(qall('SELECT seat_no FROM students WHERE class_id=?', [$cls['id']]), 'seat_no'));
            $seat = 0; for ($i = 1; $i <= 99; $i++) if (!in_array($i, $used, true)) { $seat = $i; break; }
            if (!$seat) throw new RuntimeException('Lớp đã đủ 99 học sinh.');
            q('INSERT INTO students (class_id, seat_no, student_code, full_name) VALUES (?,?,?,?)', [$cls['id'], $seat, $code, mb_substr($name, 0, 100)]);
            $msg = 'Bạn đã vào lớp ' . $cls['name'] . ' — số thứ tự (ID trên clicker) của bạn là ' . $seat . '.';
        }
        q('UPDATE student_users SET unverified=0 WHERE student_code=?', [$code]);   // mã lớp do giáo viên đưa: đủ tin là học sinh của lớp
        if (!$own) db()->commit();
        return $msg;
    } catch (Throwable $e) { if (!$own && db()->inTransaction()) db()->rollBack(); throw $e; }
}
/** Họ tên hiển thị: theo danh sách lớp (mới nhất), nếu chưa thuộc lớp nào thì theo tài khoản. */
function student_display_name(string $code): ?string {
    return qv('SELECT full_name FROM students WHERE student_code=? ORDER BY id DESC LIMIT 1', [$code])
        ?? qv('SELECT full_name FROM student_users WHERE student_code=?', [$code]);
}

/** Các lớp học sinh thuộc về (theo MSSV). */
function my_classes(string $code): array {
    return qall('SELECT st.class_id, st.seat_no, st.full_name, c.name AS class_name, c.teacher_id, t.full_name AS teacher_name '
              . 'FROM students st JOIN classes c ON c.id=st.class_id JOIN teachers t ON t.id=c.teacher_id '
              . 'WHERE st.student_code=? ORDER BY c.name', [$code]);
}
function my_class(string $code, int $class_id): array {
    foreach (my_classes($code) as $c) if ((int)$c['class_id'] === $class_id) return $c;
    http_response_code(404); exit('Không tìm thấy lớp.');
}

/**
 * Mọi câu hỏi có đáp án đúng trong các buổi học đã kết thúc và đã công bố của học sinh.
 * status: right | wrong | blank (có mặt, không trả lời) | absent (vắng buổi)
 */
function my_items(string $code, ?int $class_id = null): array {
    $rows = qall(
        'SELECT st.class_id, c.name AS class_name, st.seat_no, s.id AS session_id, s.kind, s.started_at, qs.title AS set_title, '
      . 'sq.id AS sq_id, q.id AS question_id, q.position, q.content, q.topic, q.explanation, q.n_options, q.correct, '
      . 'q.opt_a,q.opt_b,q.opt_c,q.opt_d,q.opt_e,q.opt_f,q.opt_g,q.opt_h,q.opt_i,q.opt_j, '
      . 'f.answer, TIMESTAMPDIFF(MICROSECOND, sq.opened_at, f.received_at)/1e6 AS t_answer, '
      . '(SELECT 1 FROM attendance_final af WHERE af.session_id=s.id AND af.seat_no=st.seat_no AND af.status="present") AS present, '
      . '(SELECT COUNT(*) FROM final_answers x WHERE x.session_q_id=sq.id) AS class_n, '
      . '(SELECT COUNT(*) FROM final_answers x WHERE x.session_q_id=sq.id AND x.answer=q.correct) AS class_ok '
      . 'FROM students st JOIN classes c ON c.id=st.class_id '
      . 'JOIN sessions s ON s.class_id=st.class_id AND s.status="ended" AND s.released=1 '
      . 'JOIN question_sets qs ON qs.id=s.set_id '
      . 'JOIN session_questions sq ON sq.session_id=s.id JOIN questions q ON q.id=sq.question_id '
      . 'LEFT JOIN final_answers f ON f.session_q_id=sq.id AND f.seat_no=st.seat_no '
      . 'WHERE st.student_code=? AND q.correct IS NOT NULL AND q.correct<>"" ' . ($class_id ? 'AND st.class_id=? ' : '')
      . 'ORDER BY s.started_at DESC, q.position', $class_id ? [$code, $class_id] : [$code]);
    foreach ($rows as &$r) {
        $r['status'] = $r['answer'] !== null ? ($r['answer'] === $r['correct'] ? 'right' : 'wrong') : ($r['present'] ? 'blank' : 'absent');
        $r['class_rate'] = $r['class_n'] ? $r['class_ok'] / $r['class_n'] : null;
    }
    return $rows;
}

/**
 * Một câu có thể được hỏi ở nhiều buổi: giữ lần gần nhất (trạng thái hiện tại của học sinh với câu đó),
 * kèm số lần đã sai trước đó. Buổi vắng không tính.
 */
function latest_by_question(array $items): array {
    $out = [];
    foreach ($items as $r) {                            // my_items đã sắp mới nhất trước
        if ($r['status'] === 'absent') continue;
        $q = (int)$r['question_id'];
        if (!isset($out[$q])) { $out[$q] = $r; $out[$q]['n_wrong'] = 0; $out[$q]['n_seen'] = 0; }
        $out[$q]['n_seen']++;
        if ($r['status'] !== 'right') $out[$q]['n_wrong']++;
    }
    return array_values($out);
}

/** Mức độ đã ôn của từng câu, dựa trên các lượt ôn tập: thuoc | da_on | van_sai | chua_on */
function mastery(string $code, array $question_ids): array {
    $out = array_fill_keys($question_ids, 'chua_on');
    if (!$question_ids) return $out;
    $in = implode(',', array_map('intval', $question_ids));
    $hist = [];
    foreach (qall("SELECT i.question_id, i.is_correct FROM practice_items i JOIN practice_attempts a ON a.id=i.attempt_id "
                . "WHERE a.student_code=? AND i.question_id IN ($in) AND i.is_correct IS NOT NULL ORDER BY i.answered_at, i.id", [$code]) as $r)
        $hist[(int)$r['question_id']][] = (int)$r['is_correct'];
    foreach ($hist as $qid => $h) {
        $n = count($h);
        if ($n >= 2 && $h[$n - 1] && $h[$n - 2]) $out[$qid] = 'thuoc';
        elseif ($h[$n - 1]) $out[$qid] = 'da_on';
        else $out[$qid] = 'van_sai';
    }
    return $out;
}
const MASTERY_LABEL = ['chua_on' => ['chưa ôn', 'tag off'], 'van_sai' => ['ôn vẫn sai', 'tag off'], 'da_on' => ['đã ôn đúng 1 lần', 'tag'], 'thuoc' => ['đã thuộc', 'tag live']];

/** Bộ câu hỏi học sinh được phép ôn trong lớp: bộ đã dùng ở buổi đã công bố + bộ được giao ôn tập. */
function visible_set_ids(int $class_id): array {
    return array_map('intval', array_column(qall(
        'SELECT set_id FROM sessions WHERE class_id=? AND status="ended" AND released=1 '
      . 'UNION SELECT set_id FROM practice_assignments WHERE class_id=? '
      . 'UNION SELECT quiz_set_id FROM lessons WHERE class_id=? AND published=1 AND quiz_set_id IS NOT NULL', [$class_id, $class_id, $class_id]), 'set_id'));
}

/** Tạo một lượt ôn tập với danh sách câu hỏi cho trước; trả về id lượt. */
function create_attempt(string $code, int $class_id, string $source, string $title, array $question_ids, string $mode = 'practice',
                        ?int $assignment_id = null, ?int $minutes = null): int {
    $question_ids = array_values(array_unique(array_map('intval', $question_ids)));
    if (!$question_ids) throw new RuntimeException('Không có câu hỏi nào để ôn.');
    shuffle($question_ids);
    db()->beginTransaction();
    q('INSERT INTO practice_attempts (student_code, class_id, assignment_id, source, title, mode, deadline, n_questions) '
    . 'VALUES (?,?,?,?,?,?,IF(? IS NULL, NULL, NOW() + INTERVAL ? MINUTE),?)',
      [$code, $class_id, $assignment_id, $source, mb_substr($title, 0, 200), $mode, $minutes, $minutes, count($question_ids)]);
    $aid = (int)db()->lastInsertId();
    foreach ($question_ids as $i => $qid) q('INSERT INTO practice_items (attempt_id, question_id, ord) VALUES (?,?,?)', [$aid, $qid, $i + 1]);
    db()->commit();
    return $aid;
}

function finish_attempt(int $aid): void {
    $ok = (int)qv('SELECT COUNT(*) FROM practice_items WHERE attempt_id=? AND round=1 AND is_correct=1', [$aid]);
    q('UPDATE practice_attempts SET finished_at=COALESCE(finished_at, NOW()), n_first_ok=? WHERE id=?', [$ok, $aid]);
}

function my_attempt(string $code, int $aid): array {
    $a = q1('SELECT a.*, (a.deadline IS NOT NULL AND a.deadline < NOW()) AS expired, '
          . 'TIMESTAMPDIFF(SECOND, NOW(), a.deadline) AS left_s FROM practice_attempts a WHERE a.id=? AND a.student_code=?', [$aid, $code]);
    if (!$a) { http_response_code(404); exit('Không tìm thấy lượt ôn tập.'); }
    return $a;
}

// ---------------------------------------------------------------- điểm thưởng, chuỗi ngày học
/** ⭐ điểm (10/câu đúng ngay lần đầu khi ôn tập, 5/câu đúng với trợ lý), 🔥 chuỗi ngày học liên tiếp, cấp độ. */
function stu_stats(string $code): array {
    static $cache = [];
    if (isset($cache[$code])) return $cache[$code];
    $p = (int)qv('SELECT COUNT(*) FROM practice_items i JOIN practice_attempts a ON a.id=i.attempt_id WHERE a.student_code=? AND i.round=1 AND i.is_correct=1', [$code]);
    $t = (int)qv('SELECT COUNT(*) FROM tutor_answers WHERE student_code=? AND is_correct=1', [$code]);
    $hw=(int)qv('SELECT COUNT(*) FROM homework_submissions WHERE student_code=? AND submitted_at IS NOT NULL',[$code]);
    $xp = $p * 10 + $t * 5 + $hw * 20;
    $days = array_column(qall('SELECT DISTINCT d FROM ('
        . 'SELECT DATE(i.answered_at) d FROM practice_items i JOIN practice_attempts a ON a.id=i.attempt_id WHERE a.student_code=? AND i.answered_at IS NOT NULL '
        . 'UNION SELECT DATE(answered_at) FROM tutor_answers WHERE student_code=? '
        . 'UNION SELECT DATE(created_at) FROM tutor_messages WHERE student_code=? AND role="user") x ORDER BY d DESC LIMIT 400', [$code, $code, $code]), 'd');
    $set = array_flip($days);
    $day = new DateTime('today');
    if (!isset($set[$day->format('Y-m-d')])) $day->modify('-1 day');
    $streak = 0;
    while (isset($set[$day->format('Y-m-d')])) { $streak++; $day->modify('-1 day'); }
    $lv = intdiv($xp, 100) + 1;
    return $cache[$code] = ['xp' => $xp, 'streak' => $streak, 'level' => $lv, 'lv_pct' => $xp % 100, 'today' => isset($set[date('Y-m-d')]), 'badge'=>($hw>=10?'Chăm học':($hw>=1?'Bài tập đầu tiên':''))];
}

// ---------------------------------------------------------------- giao diện học sinh
const STU_TABS = [
    'home.php' => ['🏠', 'Tổng quan', 'pink'], 'homework.php' => ['🏡', 'Bài tập về nhà', 'orange'], 'lectures.php' => ['📚', 'Bài giảng', 'blue'],
    'practice.php' => ['🎯', 'Ôn tập', 'orange'], 'tutor.php' => ['🦉', 'Trợ lý AI', 'violet'],
    'wrong.php' => ['❌', 'Câu sai', 'red'], 'right.php' => ['✅', 'Câu đúng', 'green'], 'lessons.php' => ['📝', 'Rút kinh nghiệm', 'teal'],
    'rewards.php' => ['🏆', 'Thi đua', 'orange'],
];
const STU_GROUPS = ['Học tập' => ['home.php', 'homework.php', 'lectures.php', 'practice.php', 'tutor.php'], 'Kết quả của tôi' => ['wrong.php', 'right.php', 'lessons.php'], 'Cộng đồng' => ['rewards.php']];
function student_head(string $title, string $active = ''): void {
    $s = stu();
    $app = $s && empty($s['must_change']);
    echo '<!doctype html><html lang="' . lang() . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<meta name="csrf" content="' . h(csrf_token()) . '"><meta name="theme-color" content="#7c3aed">'
       . '<title>' . h($title) . ' — ARS học sinh</title>' . str_replace('<meta name="theme-color" content="#1f6feb">', '', favicon_links('../assets/')) . '<link rel="stylesheet" href="../assets/' . av('style.css') . '"><link rel="stylesheet" href="../assets/' . av('student.css') . '">'.str_replace('assets/','../assets/',math_assets()) . i18n_js('../assets/') . gtranslate_script('../assets/') . '</head>'
       . '<body class="stu' . ($app ? ' stu-app-on' : ' stu-bare') . '"' . fx_attr() . '>';
    if ($app) {
        $st = stu_stats($s['code']);
        $first = trim(strrchr(' ' . $s['full_name'], ' '));
        $ava = qv('SELECT avatar FROM student_profiles WHERE student_code=?', [$s['code']]);   // avatar đổi bằng điểm thi đua
        echo '<div class="stu-app"><aside class="stu-side"><a class="brand" href="home.php"><span class="logo">🦉</span><span>ARS<small>Học mà vui</small></span></a><nav class="tabs stu-nav" aria-label="Điều hướng">';
        foreach (STU_GROUPS as $g => $pages) {
            echo '<h6>' . h($g) . '</h6>';
            foreach ($pages as $href) { [$ico, $label, $color] = STU_TABS[$href]; echo '<a href="' . $href . '" class="c-' . $color . ($active === $href ? ' on' : '') . '"><i>' . $ico . '</i><span>' . $label . '</span></a>'; }
        }
        echo '</nav><div class="stu-lvcard"><div class="lv-h"><b>Cấp ' . $st['level'] . '</b><span>' . $st['xp'] . ' ⭐</span></div><div class="lv-bar"><i style="width:' . (int)$st['lv_pct'] . '%"></i></div>'
           . '<small>Còn ' . (100 - (int)$st['lv_pct']) . ' điểm để lên cấp ' . ($st['level'] + 1) . '</small></div></aside><div class="stu-body">'
           . '<header class="stu-top"><a class="brand mobile-only" href="home.php"><span class="logo">🦉</span><span>ARS</span></a><div class="stu-hi"><b>' . h($title) . '</b></div>'
           . '<div class="badges"><span class="b-xp" title="Điểm thưởng">⭐ ' . $st['xp'] . '</span>'
           . '<span class="b-fire' . ($st['today'] ? ' on' : '') . '" title="Chuỗi ngày học liên tiếp">🔥 ' . $st['streak'] . '</span>'
           . '<span class="b-lv" title="Cấp độ">Cấp ' . $st['level'] . '</span></div>'
           . lang_switch('stu-lang') . '<details class="me"><summary><span class="avatar">' . h($ava ?: mb_strtoupper(mb_substr($first, 0, 1))) . '</span></summary>'
           . '<div class="menu"><b>' . h($s['full_name']) . '</b><span class="muted small">MSSV ' . h($s['code']) . '</span>'
           . '<a href="account.php">🔑 Đổi mật khẩu</a><button type="button" class="link" data-sound-toggle>🔊 Âm thanh</button><a href="logout.php">🚪 Đăng xuất</a></div></details></header>';
        if ($st['badge'] && empty($_COOKIE['ars_badge_seen_' . md5($st['badge'])])) echo '<div class="stu-toast" data-badge="' . h(md5($st['badge'])) . '">🏅 <span>Huy hiệu mới: <b>' . h($st['badge']) . '</b></span><button type="button" aria-label="Đóng">✕</button></div>';
    }
    else echo '<div class="stu-lang-float">' . lang_switch('stu-lang') . '</div>';   // trang đăng nhập / đăng ký
    echo '<main>';
    foreach ($_SESSION['flash'] ?? [] as [$k, $m]) echo '<div class="flash ' . h($k) . '">' . h($m) . '</div>';
    unset($_SESSION['flash']);
    if ($app) echo oauth_pending_prompt('student', '../');
}
function student_foot(): void {
    echo '</main>' . (stu() && empty(stu()['must_change']) ? '</div></div>' : '')
       . '<script>document.querySelectorAll(".stu-toast button").forEach(b=>b.addEventListener("click",()=>{const t=b.parentElement;document.cookie="ars_badge_seen_"+t.dataset.badge+"=1;path=/;max-age=31536000";t.remove()}))</script></body></html>';
}

/** Thẻ một câu hỏi kiểu "xem lại bài" (Moodle review): chữ đã chọn, đáp án đúng, giải thích. */
function question_card(array $q, ?string $mine, array $opt = []): string {
    $html = '<article class="qcard ' . h($opt['cls'] ?? '') . '">';
    $html .= '<div class="qmeta">' . ($opt['meta'] ?? '') . '</div>';
    $html .= '<div class="qtext">' . nl2br(h($q['content'])) . '</div>'.question_media_html((int)($q['question_id']??$q['id']), '../').'<ul class="choices">';
    foreach (options_of($q) as $L => $text) {
        $c = [];
        if ($L === $q['correct']) $c[] = 'is-correct';
        if ($mine !== null && $L === $mine) $c[] = $L === $q['correct'] ? 'mine-ok' : 'mine-bad';
        $tag = $mine === $L ? '<em>' . ($L === $q['correct'] ? 'Đáp án của bạn · đúng' : 'Đáp án của bạn') . '</em>' : ($L === $q['correct'] ? '<em>Đáp án đúng</em>' : '');
        $html .= '<li class="' . implode(' ', $c) . '"><b>' . $L . '</b><span>' . h($text) . '</span>' . $tag . '</li>';
    }
    $html .= '</ul>';
    if (!empty($q['explanation'])) $html .= '<details class="expl"' . (!empty($opt['open_expl']) ? ' open' : '') . '><summary>Giải thích</summary>' . nl2br(h($q['explanation'])) . '</details>';
    return $html . ($opt['extra'] ?? '') . '</article>';
}

function question_media_html(int $qid,string $base='',?int $homework=null): string {
 $html='';foreach(qall('SELECT id,kind,name FROM question_media WHERE question_id=? ORDER BY id',[$qid]) as $m){$url=$base.'question_file.php?id='.$m['id'].($homework?'&homework='.$homework:'');if($m['kind']==='image')$html.='<img src="'.h($url).'" alt="'.h($m['name']).'" style="max-width:100%;max-height:360px">';else $html.='<'.$m['kind'].' controls preload="metadata" src="'.h($url).'" style="max-width:100%;max-height:360px"></'.$m['kind'].'>'; }return $html;
}
