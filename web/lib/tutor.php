<?php
// Trợ lý học tập "Cú Học" cho học sinh: hỏi đáp, giải thích chi tiết phần còn yếu, câu hỏi ôn tập,
// bài tập nâng cao, tóm tắt buổi học, lộ trình ôn tập. Dùng cùng cấu hình AI giáo viên chọn trong Cài đặt;
// khi ở chế độ cơ bản (không có API key) vẫn trả lời bằng bài giảng + ngân hàng câu hỏi của lớp.
declare(strict_types=1);
require_once __DIR__ . '/ai.php';

const TUTOR_MODES = [
    'ask'      => ['💬', 'Hỏi đáp'],
    'explain'  => ['📖', 'Giải thích chi tiết'],
    'quiz'     => ['🎯', 'Câu hỏi ôn tập'],
    'advanced' => ['🚀', 'Bài tập nâng cao'],
    'summary'  => ['📝', 'Tóm tắt bài học'],
    'plan'     => ['🗺️', 'Lộ trình ôn tập'],
];
const TUTOR_LEVELS = ['th' => 'Tiểu học', 'thcs' => 'THCS', 'thpt' => 'THPT', 'dh' => 'Đại học / Cao đẳng'];

function tutor_enabled(): bool { return setting('tutor_enabled', '1') === '1'; }
function tutor_limit(): int { return max(0, (int)setting('tutor_daily_limit', '40')); }
function tutor_used_today(string $code): int {
    return (int)qv('SELECT COUNT(*) FROM tutor_messages WHERE student_code=? AND role="user" AND created_at >= CURDATE()', [$code]);
}
/** Đang làm bài dạng kiểm tra (có giờ, chưa nộp) → khoá trợ lý để đảm bảo công bằng. */
function tutor_exam_lock(string $code): ?string {
    $a = q1('SELECT title FROM practice_attempts WHERE student_code=? AND mode="exam" AND finished_at IS NULL AND (deadline IS NULL OR deadline > NOW()) ORDER BY id DESC LIMIT 1', [$code]);
    return $a ? $a['title'] : null;
}

/** Phần yếu của học sinh: chủ đề có tỉ lệ đúng thấp + câu sai gần đây. */
function tutor_weak(string $code): array {
    $items = latest_by_question(function_exists('all_wrong_sources')?all_wrong_sources($code):my_items($code));
    $by = [];
    foreach ($items as $r) {
        if ($r['status'] === 'absent') continue;
        $t = $r['topic'] ?: 'Chưa phân chủ đề';
        $by[$t]['n'] = ($by[$t]['n'] ?? 0) + 1;
        $by[$t]['ok'] = ($by[$t]['ok'] ?? 0) + ($r['status'] === 'right');
    }
    // câu luyện với trợ lý cũng tính
    foreach (qall('SELECT topic, COUNT(*) n, SUM(is_correct) ok FROM tutor_answers WHERE student_code=? AND topic IS NOT NULL GROUP BY topic', [$code]) as $r) {
        $by[$r['topic']]['n'] = ($by[$r['topic']]['n'] ?? 0) + (int)$r['n'];
        $by[$r['topic']]['ok'] = ($by[$r['topic']]['ok'] ?? 0) + (int)$r['ok'];
    }
    $topics = [];
    foreach ($by as $t => $x) $topics[] = ['topic' => $t, 'n' => $x['n'], 'ok' => $x['ok'], 'rate' => $x['n'] ? $x['ok'] / $x['n'] : 0];
    usort($topics, fn($a, $b) => $a['rate'] <=> $b['rate'] ?: $b['n'] <=> $a['n']);
    $wrong = array_values(array_filter($items, fn($r) => in_array($r['status'], ['wrong', 'blank'], true)));
    return ['topics' => $topics, 'wrong' => array_slice($wrong, 0, 8)];
}

/** Các buổi học đã công bố của học sinh (để chọn làm ngữ cảnh). */
function tutor_lessons(string $code): array {
    return qall('SELECT l.id, l.no, l.title, l.key_points, c.name AS class_name, (l.lecture_text IS NOT NULL) has_text FROM lessons l '
              . 'JOIN classes c ON c.id=l.class_id JOIN students s ON s.class_id=l.class_id AND s.student_code=? '
              . 'WHERE l.published=1 ORDER BY c.name, l.no', [$code]);
}

/** Tài liệu tham khảo cho câu trả lời: bài giảng đã chọn, hoặc bài giảng + câu hỏi trong ngân hàng khớp chủ đề. */
function tutor_material(string $code, ?int $lesson_id, string $topic, int $limit): array {
    $parts = []; $bank = [];
    $lessons = tutor_lessons($code);
    if ($lesson_id) {
        $l = q1('SELECT l.* FROM lessons l JOIN students s ON s.class_id=l.class_id AND s.student_code=? WHERE l.id=? AND l.published=1', [$code, $lesson_id]);
        if ($l) $parts[] = "Bài giảng Buổi {$l['no']}" . ($l['title'] !== '' ? " — {$l['title']}" : '') . ":\n"
                         . ($l['key_points'] ? "Kiến thức trọng tâm:\n{$l['key_points']}\n" : '') . (string)$l['lecture_text'];
    } elseif ($topic !== '') {
        $words = array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($topic)), fn($w) => mb_strlen($w) >= 2);
        foreach (qall('SELECT l.* FROM lessons l JOIN students s ON s.class_id=l.class_id AND s.student_code=? WHERE l.published=1', [$code]) as $l) {
            $hay = mb_strtolower($l['title'] . ' ' . $l['key_points'] . ' ' . $l['lecture_text']);
            $hit = 0; foreach ($words as $w) if (mb_strpos($hay, $w) !== false) $hit++;
            if ($words && $hit >= max(1, (int)ceil(count($words) / 2))) $parts[] = "Bài giảng Buổi {$l['no']} — {$l['title']}:\n" . ($l['key_points'] ? $l['key_points'] . "\n" : '') . $l['lecture_text'];
        }
    }
    // câu hỏi trong các bộ học sinh được phép ôn (không lộ đề kiểm tra chưa công bố)
    $sets = [];
    foreach (my_classes($code) as $c) $sets = array_merge($sets, visible_set_ids((int)$c['class_id']));
    if ($sets) {
        $in = implode(',', array_unique($sets));
        $where = $lesson_id ? "set_id = (SELECT quiz_set_id FROM lessons WHERE id=" . (int)$lesson_id . ")" : ($topic !== '' ? 'topic LIKE ' . db()->quote('%' . $topic . '%') : '1');
        $bank = qall("SELECT * FROM questions WHERE set_id IN ($in) AND correct IS NOT NULL AND correct<>'' AND $where ORDER BY RAND() LIMIT 20");
    }
    $text = mb_substr(implode("\n\n", $parts), 0, $limit);
    return ['text' => $text, 'bank' => $bank, 'lessons' => $lessons];
}

function tutor_system(string $level, array $weak, string $name): string {
    $lv = TUTOR_LEVELS[$level] ?? 'THPT';
    $w = array_slice(array_filter($weak['topics'], fn($t) => $t['rate'] < 0.7), 0, 5);
    return "Bạn là \"Cú Học\" 🦉 — trợ lý học tập thân thiện trong ứng dụng lớp học ARS. Học sinh tên {$name}, trình độ: {$lv}.\n"
         . "Nguyên tắc:\n"
         . "- Luôn viết tiếng Việt, giọng ấm áp, khích lệ, xưng \"mình\" và gọi học sinh là \"bạn\". Dùng từ ngữ phù hợp trình độ {$lv}"
         . ($level === 'th' ? ' (câu ngắn, ví dụ gần gũi đời thường, emoji vừa phải)' : '') . ".\n"
         . "- Chỉ hỗ trợ việc học. Từ chối nhẹ nhàng nội dung không phù hợp với học sinh và gợi ý quay lại bài học.\n"
         . "- Ưu tiên kiến thức trong TÀI LIỆU của lớp (nếu có). Không bịa số liệu; không chắc thì nói rõ và khuyên hỏi giáo viên.\n"
         . "- Khi học sinh hỏi đáp án bài tập: gợi mở từng bước để học sinh tự làm, chỉ đưa lời giải đầy đủ khi học sinh đã thử hoặc yêu cầu lại.\n"
         . "- Trình bày gọn: đoạn ngắn, gạch đầu dòng, **in đậm** ý chính, tiêu đề nhỏ bằng ###. Công thức viết dạng văn bản thường.\n"
         . "- Kết thúc bằng một câu hỏi ngắn hoặc gợi ý bước tiếp theo để học sinh tiếp tục ôn.\n"
         . ($w ? "Các phần học sinh đang yếu (tỉ lệ đúng): " . implode('; ', array_map(fn($t) => $t['topic'] . ' ' . round($t['rate'] * 100) . '%', $w)) . ".\n" : '');
}

function tutor_task_prompt(string $mode, string $text, string $topic, array $mat, array $weak, ?array $question): string {
    $doc = $mat['text'] !== '' ? "\n\n<tai_lieu_lop>\n{$mat['text']}\n</tai_lieu_lop>" : '';
    $bank = '';
    if ($mat['bank']) {
        $bank = "\n\nMột số câu trong ngân hàng của lớp (tham khảo cách hỏi, không chép nguyên):\n";
        foreach (array_slice($mat['bank'], 0, 8) as $q) $bank .= '- ' . $q['content'] . ' → ' . (options_of($q)[$q['correct']] ?? '') . "\n";
    }
    $qctx = '';
    if ($question) {
        $opts = ''; foreach (options_of($question) as $L => $o) $opts .= "$L. $o\n";
        $qctx = "\n\nCâu hỏi học sinh đang hỏi:\n{$question['content']}\n{$opts}Đáp án đúng: {$question['correct']}"
              . ($question['mine'] ? "\nHọc sinh đã chọn: {$question['mine']}" : '') . ($question['explanation'] ? "\nGiải thích của giáo viên: {$question['explanation']}" : '');
    }
    $about = $topic !== '' ? "Chủ đề: $topic. " : '';
    switch ($mode) {
        case 'explain':
            return "{$about}Hãy giảng lại CHI TIẾT phần này cho học sinh theo cấu trúc: ### Hiểu nhanh (2–3 câu) · ### Kiến thức chi tiết (các ý chính, định nghĩa, công thức) · "
                 . "### Ví dụ minh hoạ · ### Lỗi hay nhầm và cách tránh · ### Mẹo ghi nhớ · ### Tự kiểm tra (3 câu hỏi ngắn, KHÔNG kèm đáp án). "
                 . ($text !== '' ? "Học sinh nói thêm: $text" : '') . $qctx . $doc . $bank;
        case 'summary':
            return "Tóm tắt bài học thành: ### Ý chính (5–8 gạch đầu dòng) · ### Từ khoá cần nhớ · ### Sơ đồ tư duy dạng danh sách lồng nhau · ### 3 câu hỏi tự kiểm tra. "
                 . ($text !== '' ? "Yêu cầu thêm: $text" : '') . $doc;
        case 'plan':
            $w = array_slice($weak['topics'], 0, 6);
            $s = "Lập LỘ TRÌNH ÔN TẬP 7 ngày cho học sinh (mỗi ngày 20–30 phút), ưu tiên các phần yếu, mỗi ngày gồm: mục tiêu, việc cần làm (đọc lại/giải thích/làm câu hỏi), cách tự kiểm tra. ";
            $s .= $w ? "Kết quả hiện tại theo chủ đề: " . implode('; ', array_map(fn($t) => "{$t['topic']}: đúng {$t['ok']}/{$t['n']}", $w)) . '. ' : 'Chưa có nhiều dữ liệu kết quả. ';
            if ($weak['wrong']) $s .= "Một số câu vừa làm sai: " . implode(' | ', array_map(fn($r) => mb_strimwidth($r['content'], 0, 90, '…'), array_slice($weak['wrong'], 0, 5))) . '. ';
            return $s . ($text !== '' ? "Học sinh nói thêm: $text" : '') . "\nDanh sách buổi học: " . implode('; ', array_map(fn($l) => "Buổi {$l['no']} {$l['title']}", $mat['lessons']));
        default:
            return $text . $qctx . $doc . $bank;
    }
}

/** Câu hỏi luyện tập (quiz/advanced) → danh sách câu chuẩn hoá. */
function tutor_quiz(array $c, string $mode, string $level, string $name, string $text, string $topic, array $mat, array $weak, int $n): array {
    $diff = $mode === 'advanced' ? 'kho' : 'mix';
    if ($c['provider'] === 'offline') return tutor_offline_quiz($mode, $topic, $mat, $n);
    $prompt = ($mode === 'advanced'
        ? "Soạn $n BÀI TẬP NÂNG CAO dạng trắc nghiệm (vận dụng cao, tình huống nhiều bước, so sánh/phân tích) cho học sinh đã nắm cơ bản. "
        : "Soạn $n câu hỏi ôn tập trắc nghiệm, từ dễ đến khó, tập trung vào phần học sinh còn yếu. ")
        . ($topic !== '' ? "Chủ đề: $topic. " : '') . ($text !== '' ? "Yêu cầu của học sinh: $text. " : '')
        . "Mỗi câu 4 phương án, giải thích rõ vì sao đúng và vì sao phương án nhiễu sai.";
    $r = ai_call($c, tutor_system($level, $weak, $name), ai_user_prompt(['n' => $n, 'n_options' => 4, 'difficulty' => $diff, 'prompt' => $prompt, 'source' => $mat['text']]),
                 ai_question_schema(4), 6000);
    $out = [];
    foreach ($r['data']['questions'] ?? [] as $q) if (is_array($q)) { $nq = norm_question($q, 'ai'); if ($nq['content'] !== '' && $nq['correct'] !== '') $out[] = $nq; }
    if (!$out) throw new RuntimeException('Trợ lý chưa tạo được câu hỏi, bạn thử lại nhé.');
    return ['questions' => array_slice($out, 0, $n), 'usage' => $r];
}

// ---------------------------------------------------------------- chế độ cơ bản (không gọi AI)
function tutor_offline_quiz(string $mode, string $topic, array $mat, int $n): array {
    $qs = [];
    foreach ($mat['bank'] as $q) {
        if (count($qs) >= $n) break;
        $opts = array_values(options_of($q));
        $qs[] = norm_question(['content' => $q['content'], 'options' => $opts, 'correct' => $q['correct'], 'explanation' => (string)$q['explanation'],
                               'topic' => (string)$q['topic'], 'difficulty' => (string)$q['difficulty']], 'bank');
    }
    if (count($qs) < $n && $mat['text'] !== '') {
        require_once __DIR__ . '/ai_offline.php';
        try { $qs = array_merge($qs, offline_generate(['source' => $mat['text'], 'n' => $n - count($qs), 'n_options' => 4, 'prompt' => $topic, 'existing' => array_column($qs, 'content')])); }
        catch (Throwable $e) {}
    }
    if (!$qs) throw new RuntimeException('Chưa có câu hỏi cho phần này. Hãy chọn một buổi học đã có bài giảng, hoặc nhờ giáo viên bật trợ lý AI đầy đủ.');
    return ['questions' => $qs, 'usage' => null];
}

function tutor_offline_text(string $mode, string $text, string $topic, array $mat, array $weak, ?array $question): string {
    require_once __DIR__ . '/ai_offline.php';
    if ($mode === 'plan') {
        $w = array_slice($weak['topics'], 0, 5);
        if (!$w) return "### Lộ trình gợi ý\nBạn chưa có nhiều kết quả để phân tích. Hãy làm **Câu hỏi ôn tập** ở từng buổi học, sau đó mình sẽ lập lộ trình sát hơn nhé! 💪";
        $s = "### Lộ trình ôn tập 7 ngày\n";
        foreach ($w as $i => $t) $s .= "- **Ngày " . ($i + 1) . " – " . $t['topic'] . "** (đang đúng {$t['ok']}/{$t['n']}): đọc lại phần này trong tab Bài giảng, bấm “Giải thích chi tiết”, rồi làm 5 câu ôn tập.\n";
        $s .= "- **Ngày " . (count($w) + 1) . "**: làm lại tất cả câu sai ở tab *Câu sai*.\n- **Ngày 7**: làm một lượt “Câu hỏi ôn tập” tổng hợp để tự kiểm tra.\n\nBạn muốn bắt đầu với **" . $w[0]['topic'] . "** không?";
        return $s;
    }
    $src = $mat['text'];
    if ($mode === 'summary' && $src !== '') {
        try { return "### Ý chính của bài\n" . offline_summary($src) . "\n\nBạn muốn mình ra vài câu hỏi để tự kiểm tra không? 🎯"; } catch (Throwable $e) {}
    }
    $s = '';
    if ($question) {
        $o = options_of($question);
        $s .= "### Câu này đáp án đúng là **{$question['correct']}. " . ($o[$question['correct']] ?? '') . "**\n";
        if ($question['mine'] && $question['mine'] !== $question['correct']) $s .= "Bạn đã chọn **{$question['mine']}. " . ($o[$question['mine']] ?? '') . "** — đây là phương án dễ gây nhầm.\n";
        if ($question['explanation']) $s .= "\n**Giải thích:** {$question['explanation']}\n";
    }
    // tìm câu trong tài liệu khớp với câu hỏi
    $q = mb_strtolower($text . ' ' . $topic . ' ' . ($question['content'] ?? ''));
    $words = array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', $q), fn($w) => mb_strlen($w) >= 3 && !in_array($w, ['của', 'và', 'là', 'gì', 'nào', 'cho', 'mình', 'bạn', 'không', 'được', 'với', 'các', 'những', 'này', 'thế', 'như'], true)));
    $hits = [];
    if ($src !== '') foreach (offline_lines($src) as $ln) {
        $low = mb_strtolower($ln['s']); $k = 0;
        foreach ($words as $w) if (mb_strpos($low, $w) !== false) $k++;
        if ($k) $hits[] = [$k, $ln['s']];
    }
    usort($hits, fn($a, $b) => $b[0] <=> $a[0]);
    if ($hits) $s .= "\n### Trong bài giảng có nói\n" . implode("\n", array_map(fn($h) => '- ' . $h[1], array_slice($hits, 0, $mode === 'explain' ? 8 : 4))) . "\n";
    foreach (array_slice($mat['bank'], 0, 3) as $b) if ($b['explanation']) $s .= "\n💡 *" . mb_strimwidth($b['content'], 0, 80, '…') . "* → " . $b['explanation'];
    if (trim($s) === '') $s = "Mình chưa tìm thấy nội dung này trong bài giảng của lớp. Bạn thử chọn đúng **buổi học** ở ô phía trên, hoặc hỏi giáo viên nhé!";
    return $s . "\n\n_(Trợ lý đang ở chế độ cơ bản: trả lời bằng bài giảng của lớp. Giáo viên có thể bật AI đầy đủ trong Cài đặt.)_";
}
