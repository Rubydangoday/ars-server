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
            if ($words && $hit >= max(1, (int)ceil(count($words) / 3))) $parts[] = "Bài giảng Buổi {$l['no']} — {$l['title']}:\n" . ($l['key_points'] ? $l['key_points'] . "\n" : '') . $l['lecture_text'];
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
         . (function_exists('lang') && lang() !== 'vi' ? "- Học sinh đang dùng giao diện tiếng Anh: TRẢ LỜI BẰNG TIẾNG ANH (giữ thuật ngữ gốc trong tài liệu khi cần), giọng ấm áp, khích lệ.\n" : '')
         . "- Viết tiếng Việt (trừ khi học sinh dùng ngôn ngữ khác thì trả lời theo ngôn ngữ đó), giọng ấm áp, khích lệ, xưng \"mình\" và gọi học sinh là \"bạn\". Dùng từ ngữ phù hợp trình độ {$lv}"
         . ($level === 'th' ? ' (câu ngắn, ví dụ gần gũi đời thường, emoji vừa phải)' : '') . ".\n"
         . "- Chỉ hỗ trợ việc học. Từ chối nhẹ nhàng nội dung không phù hợp với học sinh và gợi ý quay lại bài học.\n"
         . "- Ưu tiên kiến thức trong TÀI LIỆU của lớp (nếu có). Không bịa số liệu; không chắc thì nói rõ và khuyên hỏi giáo viên.\n"
         . "- Khi học sinh hỏi đáp án bài tập: gợi mở từng bước để học sinh tự làm, chỉ đưa lời giải đầy đủ khi học sinh đã thử hoặc yêu cầu lại.\n"
         . "- Trình bày gọn: đoạn ngắn, gạch đầu dòng, **in đậm** ý chính, tiêu đề nhỏ bằng ###. Công thức viết dạng văn bản thường.\n"
         . "- Đọc kỹ lịch sử trò chuyện. Khi học sinh xin giải thích lại / cách khác / đơn giản hơn: TUYỆT ĐỐI không lặp lại câu trả lời trước — đổi cách tiếp cận (ví dụ đời thường, phép so sánh, các bước, sơ đồ dạng danh sách, câu hỏi gợi mở).\n"
         . "- Trả lời đúng trọng tâm câu hỏi, độ dài vừa đủ: câu chào/cảm ơn thì đáp ngắn, câu hỏi khó thì giải thích từng bước.\n"
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

/** Từ khoá có nghĩa trong câu hỏi (bỏ hư từ và các từ chỉ ý định như "giải thích", "cách khác"). */
function tutor_words(string $s): array {
    static $stop = ['của', 'và', 'là', 'gì', 'nào', 'cho', 'mình', 'bạn', 'không', 'được', 'với', 'các', 'những', 'này', 'thế', 'như', 'thì', 'mà', 'có', 'một',
        'giải', 'thích', 'lại', 'cách', 'khác', 'đơn', 'giản', 'hơn', 'giúp', 'nhé', 'nha', 'hãy', 'kỹ', 'chi', 'tiết', 'dụ', 'gần', 'gũi', 'nội', 'dung', 'vừa',
        'rồi', 'hiểu', 'chưa', 'tại', 'sao', 'ví', 'hoạt', 'động', 'thế', 'bước', 'đâu', 'đó', 'kia', 'ấy', 'làm', 'muốn', 'biết', 'hỏi', 'em', 'ạ', 'vậy', 'nữa', 'thêm', 'câu', 'trả', 'lời', 'theo', 'đâu', 'khi', 'nên', 'cần',
        'the', 'what', 'how', 'why', 'is', 'are', 'and', 'please', 'explain'];
    return array_values(array_unique(array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($s)),
        fn($w) => mb_strlen($w) >= 2 && !in_array($w, $stop, true) && !preg_match('/^\d$/', $w))));
}
/** Ý định của câu hỏi (chế độ cơ bản). */
function tutor_intent(string $t): string {
    $t = mb_strtolower(trim($t));
    if ($t === '') return 'topic';
    if (mb_strlen($t) <= 30 && preg_match('/^(hi|hello|helo|hey|alo|chào|xin chào|chao)\b/u', $t)) return 'greet';
    if (preg_match('/(cảm ơn|cám ơn|thank|tks|thanks)/u', $t)) return 'thanks';
    if (preg_match('/(bạn là ai|bạn tên gì|bạn làm được gì|who are you)/u', $t)) return 'who';
    if (preg_match('/(cách khác|đơn giản|dễ hiểu|chưa hiểu|không hiểu|khó hiểu|nói lại)/u', $t)) return 'simple';
    if (preg_match('/(kỹ hơn|chi tiết|sâu hơn|nói thêm|mở rộng|đầy đủ)/u', $t)) return 'deep';
    if (preg_match('/(so sánh|khác nhau|giống nhau|phân biệt|khác gì)/u', $t)) return 'compare';
    if (preg_match('/(ví dụ|minh hoạ|minh họa|example)/u', $t)) return 'example';
    if (preg_match('/(tại sao|vì sao|lý do|why)/u', $t)) return 'why';
    if (preg_match('/(như thế nào|thế nào|cách nào|làm sao|các bước|quy trình|hoạt động|how)/u', $t)) return 'how';
    if (preg_match('/(là gì|nghĩa là|định nghĩa|what is)/u', $t)) return 'what';
    return 'ask';
}
function tutor_pick(array $a): string { return $a[random_int(0, count($a) - 1)]; }

/** Chế độ cơ bản (không gọi AI): trả lời bằng bài giảng + ngân hàng câu hỏi của lớp.
 *  $ctx: prev = các câu trả lời trước trong cuộc trò chuyện (để không lặp lại), prev_user = câu hỏi trước, name = tên học sinh. */
function tutor_offline_text(string $mode, string $text, string $topic, array $mat, array $weak, ?array $question, array $ctx = []): string {
    require_once __DIR__ . '/ai_offline.php';
    $name = $ctx['name'] ?? 'bạn';
    $prev = mb_strtolower(str_replace(['**', '*'], '', implode("\n", $ctx['prev'] ?? [])));
    $note = "\n\n_(Chế độ cơ bản: trả lời từ bài giảng của lớp. Giáo viên bật AI đầy đủ trong Cài đặt → Trợ lý AI để hỏi đáp tự do.)_";
    $weak_t = array_values(array_filter($weak['topics'], fn($t) => $t['rate'] < 0.8));

    if ($mode === 'plan') {
        $w = array_slice($weak['topics'], 0, 5);
        if (!$w) return "### Lộ trình gợi ý\nBạn chưa có nhiều kết quả để phân tích. Hãy làm **Câu hỏi ôn tập** ở từng buổi học, sau đó mình sẽ lập lộ trình sát hơn nhé! 💪";
        $days = (int)preg_replace('/\D/', '', $text) ?: 7; $days = max(2, min(30, $days));
        $s = "### Lộ trình ôn tập $days ngày\n";
        $i = 0;
        foreach ($w as $t) { if (++$i >= $days - 1) break; $s .= "- **Ngày $i – {$t['topic']}** (đang đúng {$t['ok']}/{$t['n']}): " . tutor_pick(['đọc lại phần này trong tab Bài giảng, bấm “Giải thích chi tiết”, rồi làm 5 câu ôn tập.', 'xem lại các câu sai của phần này, ghi lý do sai, rồi làm 5 câu ôn tập.', 'tóm tắt phần này ra giấy bằng 5 gạch đầu dòng, sau đó làm câu hỏi ôn tập để kiểm tra.']) . "\n"; }
        $s .= "- **Ngày " . ($days - 1) . "**: làm lại tất cả câu sai ở tab *Câu sai*.\n- **Ngày $days**: làm một lượt “Câu hỏi ôn tập” tổng hợp để tự kiểm tra.\n\nBạn muốn bắt đầu với **" . $w[0]['topic'] . "** không?";
        return $s;
    }
    $intent = $mode === 'explain' ? ($question ? 'question' : 'deep') : ($mode === 'summary' ? 'summary' : tutor_intent($text));
    if ($question && $mode === 'ask') $intent = 'question';

    // ---- câu xã giao
    if ($intent === 'greet' || $intent === 'who') {
        $s = tutor_pick(["Chào $name! 👋", "Hi $name! 🦉", "Xin chào $name, rất vui được học cùng bạn! ✨"]) . ' '
           . ($intent === 'who' ? "Mình là **Cú Học**, trợ lý học tập của lớp. Mình đọc bài giảng và ngân hàng câu hỏi của lớp để giải thích, ra câu hỏi ôn tập và lập lộ trình học cho bạn.\n\n" : "Hôm nay bạn muốn ôn phần nào?\n\n");
        $s .= "Bạn có thể thử:\n";
        if ($weak_t) $s .= "- 📖 **Giải thích chi tiết** phần *{$weak_t[0]['topic']}* — phần bạn đang đúng {$weak_t[0]['ok']}/{$weak_t[0]['n']} câu\n";
        $s .= "- 🎯 **Câu hỏi ôn tập** để tự kiểm tra\n- 📝 **Tóm tắt** một buổi học (chọn buổi ở nút ⚙)\n- Hoặc gõ thẳng câu hỏi, ví dụ: *“" . ($weak_t ? $weak_t[0]['topic'] : 'MQTT') . " là gì?”*";
        return $s;
    }
    if ($intent === 'thanks') return tutor_pick(['Không có gì đâu! 😊', 'Rất vui vì giúp được bạn! 🦉', 'Bạn học chăm quá, cố lên nhé! 💪'])
        . ' ' . tutor_pick(['Làm vài câu ôn tập để nhớ lâu hơn không?', 'Còn phần nào chưa rõ thì cứ hỏi mình nhé.', 'Muốn mình tóm tắt lại những ý chính vừa rồi không?']);

    // ---- nguồn: câu trong bài giảng, định nghĩa, số liệu, câu hỏi ngân hàng (ưu tiên câu chưa nói ở trên)
    $src = $mat['text'];
    $words = tutor_words($text . ' ' . $topic . ' ' . ($question['content'] ?? ''));
    $follow = in_array($intent, ['simple', 'deep'], true) && $topic === '';
    if ((!$words || $follow) && !empty($ctx['prev_user'])) $words = tutor_words($ctx['prev_user']);   // "giải thích lại" → chủ đề câu hỏi trước
    $said = fn(string $x) => $prev !== '' && mb_strpos($prev, mb_strtolower(mb_substr($x, 0, 50))) !== false;
    $lines = []; $defs = []; $nums = [];
    if ($src !== '') {
        foreach (offline_lines($src) as $ln) {
            if (preg_match('/^Bài giảng Buổi \\d+/u', $ln['s'])) continue;
            $k = offline_relevance($ln['s'], $words); if ($k || !$words || $follow) $lines[] = $ln + ['k' => $k];
        }
        usort($lines, fn($a, $b) => $b['k'] <=> $a['k']);
        [$d0, $n0] = offline_facts($src);
        foreach ($d0 as $d) { $k = offline_relevance($d['term'] . ' ' . $d['def'], $words) + 2 * offline_relevance($d['term'], $words); if ($k || !$words || $follow) $defs[] = $d + ['k' => $k]; }
        usort($defs, fn($a, $b) => $b['k'] <=> $a['k']);
        foreach ($n0 as $n) if (!$words || offline_relevance($n['src'], $words)) $nums[] = $n;
    }
    $fresh = array_values(array_filter($lines, fn($l) => !$said($l['s'])));
    $fresh_defs = array_values(array_filter($defs, fn($d) => !$said($d['src']) && !$said($d['def'])));
    $bank = array_values(array_filter($mat['bank'], fn($b) => !$words || offline_relevance($b['content'] . ' ' . $b['explanation'] . ' ' . $b['topic'], $words)));
    $bank_fresh = array_values(array_filter($bank, fn($b) => !$said(mb_strimwidth($b['content'], 0, 60))));
    $about = $topic !== '' ? $topic : ($defs[0]['term'] ?? ($words ? implode(' ', array_slice($words, 0, 3)) : 'phần này'));
    $ans = fn(array $b) => (options_of($b)[$b['correct']] ?? $b['correct']);
    $def_line = fn(array $d) => '**' . $d['term'] . '** ' . ($d['kind'] === 'dung' ? $d['verb'] . ' ' . mb_lcfirst($d['def']) : 'là ' . mb_lcfirst($d['def']));
    $bullets = fn(array $xs, int $n) => implode("\n", array_map(fn($l) => '- ' . $l['s'], array_slice($xs, 0, $n)));

    $s = '';
    if ($question) {
        $o = options_of($question);
        $s .= "### Đáp án đúng: **{$question['correct']}. " . ($o[$question['correct']] ?? '') . "**\n";
        if ($question['mine'] && $question['mine'] !== $question['correct'])
            $s .= "Bạn đã chọn **{$question['mine']}. " . ($o[$question['mine']] ?? '') . "** — " . tutor_pick(['phương án này dễ gây nhầm vì nghe có vẻ hợp lý.', 'đây là lỗi nhiều bạn cùng mắc.', 'gần đúng nhưng chưa chính xác.']) . "\n";
        if ($question['explanation']) $s .= "\n**Vì sao?** {$question['explanation']}\n";
        if ($fresh_defs) $s .= "\n### Kiến thức liên quan\n- " . $def_line($fresh_defs[0]) . "\n";
        if ($fresh) $s .= ($fresh_defs ? '' : "\n### Kiến thức liên quan\n") . $bullets($fresh, 3) . "\n";
        $s .= "\n💡 " . tutor_pick(['Lần sau gặp dạng này, hãy gạch chân từ khoá trong câu hỏi trước khi chọn.', 'Mẹo: loại trừ các phương án chắc chắn sai trước, rồi so sánh hai phương án còn lại.', 'Hãy tự giải thích lại bằng lời của bạn — nếu nói được là bạn đã hiểu!']);
        return $s . "\n\nBạn muốn làm thêm vài câu tương tự để chắc chắn không? 🎯" . $note;
    }
    if ($intent === 'summary') {
        if ($src === '') return "Bạn chọn **buổi học** ở nút ⚙ (Ngữ cảnh) rồi bấm Tóm tắt nhé — mình cần biết tóm tắt bài nào. 📚";
        try { return "### Ý chính của bài\n" . offline_summary($src) . ($nums ? "\n\n### Số liệu cần nhớ\n" . implode("\n", array_map(fn($n) => '- ' . $n['src'], array_slice($nums, 0, 4))) : '') . "\n\nBạn muốn mình ra vài câu hỏi để tự kiểm tra không? 🎯"; } catch (Throwable $e) {}
    }
    if (!$lines && !$defs && !$bank) {
        $s = tutor_pick(['Mình chưa tìm thấy', 'Tiếc quá, mình không thấy', 'Hmm, bài giảng của lớp chưa có']) . " nội dung về **" . ($text !== '' ? mb_strimwidth($text, 0, 60, '…') : $about) . "**. 🤔\n\n"
           . "Bạn thử:\n- Chọn đúng **buổi học** ở nút ⚙ rồi hỏi lại\n- Hỏi ngắn gọn bằng từ khoá, ví dụ *“" . ($weak_t ? $weak_t[0]['topic'] : 'MQTT') . " là gì?”*\n- Hoặc hỏi thầy cô nhé!";
        return $s . $note;
    }
    $direct = !in_array($intent, ['simple', 'deep', 'topic'], true);       // câu hỏi trực tiếp: ưu tiên câu khớp nhất; câu nối tiếp: ưu tiên ý chưa nói
    $use = $direct ? $lines : ($fresh ?: $lines); $use_defs = $direct ? $defs : ($fresh_defs ?: $defs); $use_bank = $bank_fresh ?: $bank;
    $repeat = !$fresh && !$fresh_defs && $prev !== '';
    switch ($intent) {
        case 'simple':
            $s .= tutor_pick(['Ok, mình nói theo cách khác nhé! 😊', 'Không sao, mình giải thích đơn giản hơn nhé.', 'Mình thử cách dễ hình dung hơn nha 👇']) . "\n\n### Nói ngắn gọn\n";
            if ($use_defs) $s .= "Hiểu đơn giản: " . $def_line($use_defs[0]) . ".\n";
            elseif ($use) $s .= "Ý quan trọng nhất: **" . $use[0]['s'] . "**.\n";
            $pts = array_values(array_filter($use, fn($l) => !$use_defs || $l['s'] !== $use_defs[0]['src']));
            if ($pts) $s .= "\n### " . tutor_pick(['3 ý cần nhớ', 'Gói gọn trong 3 ý', 'Những điều quan trọng']) . "\n" . implode("\n", array_map(fn($l, $i) => ['1️⃣', '2️⃣', '3️⃣'][$i] . ' ' . $l['s'], array_slice($pts, 0, 3), [0, 1, 2])) . "\n";
            $vd = array_values(array_filter($lines, fn($l) => preg_match('/(ví dụ|chẳng hạn|như là)/u', $l['s']) && !$said($l['s']) && !in_array($l['s'], array_column(array_slice($pts, 0, 3), 's'), true)));
            if ($vd) $s .= "\n### Ví dụ gần gũi\n- " . $vd[0]['s'] . "\n";
            $ex = array_slice($use_bank, 0, 2);
            if ($ex) { $s .= "\n### Ví dụ cụ thể\n"; foreach ($ex as $b) $s .= "- Hỏi: *" . $b['content'] . "* → **" . $ans($b) . "**" . ($b['explanation'] ? " — vì " . mb_lcfirst(rtrim($b['explanation'], '.')) : '') . "\n"; }
            $kw = array_slice(array_unique(array_map(fn($d) => $d['term'], $use_defs)), 0, 4);
            if ($kw) $s .= "\n### Ghi nhớ bằng từ khoá\n" . implode(' → ', array_map(fn($k) => "**$k**", $kw)) . "\n";
            $s .= "\n" . tutor_pick(['Đã dễ hiểu hơn chưa? Nếu chưa, bạn chỉ ra chỗ nào còn khó nhé.', 'Bạn thử tự nói lại ý chính bằng một câu xem!', 'Muốn mình ra 3 câu thật dễ để kiểm tra không? 🎯']);
            break;
        case 'deep':
            $s .= "### " . tutor_pick(['Đi sâu hơn vào', 'Tìm hiểu kỹ', 'Chi tiết về']) . " $about\n";
            if ($use_defs) $s .= "\n**Khái niệm**\n" . implode("\n", array_map(fn($d) => '- ' . $def_line($d), array_slice($use_defs, 0, 4))) . "\n";
            $by = [];
            $dsrc = array_column(array_slice($use_defs, 0, 4), 'src');
            foreach (array_slice(array_values(array_filter($use, fn($l) => !in_array($l['s'], $dsrc, true))), 0, 10) as $l) $by[$l['topic'] ?: 'Nội dung chính'][] = $l;
            foreach ($by as $h => $ls) $s .= "\n**" . $h . "**\n" . $bullets($ls, 5) . "\n";
            if ($nums) $s .= "\n**Số liệu cần nhớ**\n" . implode("\n", array_map(fn($n) => '- ' . $n['src'], array_slice($nums, 0, 3))) . "\n";
            if ($use_bank) $s .= "\n### Lỗi hay nhầm\n" . implode("\n", array_map(fn($b) => '- *' . mb_strimwidth($b['content'], 0, 90, '…') . '* → đáp án đúng là **' . $ans($b) . '**', array_slice($use_bank, 0, 2))) . "\n";
            $s .= "\n### Tự kiểm tra\n" . ($use_defs ? "- " . $use_defs[0]['term'] . " là gì? Dùng để làm gì?\n" : '') . "- Hãy nêu 2 ý quan trọng nhất của phần **$about**.\n";
            break;
        case 'example':
            $s .= "### Ví dụ về $about\n";
            if ($use_bank) foreach (array_slice($use_bank, 0, 3) as $i => $b) $s .= ($i + 1) . ". " . $b['content'] . "\n   → **" . $ans($b) . "**" . ($b['explanation'] ? ". " . $b['explanation'] : '') . "\n";
            else $s .= $bullets(array_values(array_filter($use, fn($l) => preg_match('/(ví dụ|chẳng hạn|như|vd)/iu', $l['s']))) ?: $use, 4) . "\n";
            $s .= "\nBạn thử nghĩ thêm một ví dụ của riêng mình rồi gửi mình xem nhé! ✍️";
            break;
        case 'why':
            $r = array_values(array_filter($use, fn($l) => preg_match('/(vì|do|nên|để|giúp|nhờ|nhằm|bởi)/u', $l['s']))) ?: $use;
            $s .= "### Vì sao?\n" . $bullets($r, 4) . "\n";
            if ($use_defs) $s .= "\nNói gọn: " . $def_line($use_defs[0]) . ".\n";
            $s .= "\n" . tutor_pick(['Bạn thấy lý do nào thuyết phục nhất?', 'Hiểu “vì sao” sẽ giúp bạn nhớ lâu hơn học thuộc đấy! 🧠']);
            break;
        case 'how':
            $st = array_values(array_filter($use, fn($l) => preg_match('/(bước|đầu tiên|sau đó|tiếp theo|cuối cùng|gửi|nhận|kết nối|thực hiện|hoạt động)/u', $l['s']))) ?: $use;
            $s .= "### $about hoạt động thế nào?\n";
            foreach (array_slice($st, 0, 5) as $i => $l) $s .= ($i + 1) . ". " . $l['s'] . "\n";
            $s .= "\n" . tutor_pick(['Bạn thử vẽ sơ đồ các bước này ra giấy nhé — rất dễ nhớ!', 'Bước nào bạn thấy khó nhất?']);
            break;
        case 'compare':
            $s .= "### So sánh\n";
            foreach (array_slice($use_defs, 0, 3) as $d) $s .= "- " . $def_line($d) . "\n";
            $s .= $use_defs ? '' : $bullets($use, 4) . "\n";
            $s .= "\n👉 Mẹo: kẻ bảng 2 cột (giống nhau / khác nhau) để so sánh dễ hơn.";
            break;
        default:   // what / ask / topic
            if ($repeat) $s .= tutor_pick(['Mình đã nêu các ý chính ở trên rồi, giờ nhìn lại theo cách khác nhé:', 'Tóm lại những gì quan trọng nhất nhé:']) . "\n\n";
            if ($use_defs && ($intent === 'what' || $use_defs[0]['k'] >= 2)) {
                $s .= "### " . $use_defs[0]['term'] . "\n" . $def_line($use_defs[0]) . ".\n";
                $rest = array_values(array_filter($use, fn($l) => $l['s'] !== $use_defs[0]['src']));
                if ($rest) $s .= "\n" . tutor_pick(['**Thêm vài ý quan trọng:**', '**Bạn cần nhớ thêm:**', '**Trong bài giảng còn nói:**']) . "\n" . $bullets($rest, 3) . "\n";
            } else {
                $s .= tutor_pick(['### Trong bài giảng có nói', '### Mình tìm được trong bài giảng', '### Những ý liên quan']) . "\n" . $bullets($use, 4) . "\n";
            }
            if ($use_bank && random_int(0, 1)) { $b = $use_bank[0]; $s .= "\n💡 Ví dụ: *" . mb_strimwidth($b['content'], 0, 90, '…') . "* → **" . $ans($b) . "**\n"; }
            $s .= "\n" . tutor_pick(['Bạn muốn mình giải thích kỹ hơn, hay cho vài câu hỏi để luyện?', 'Còn chỗ nào chưa rõ không?', 'Thử làm 3 câu ôn tập về phần này nhé? 🎯', 'Muốn xem ví dụ cụ thể không?']);
    }
    return $s . $note;
}

// ---- Cuộc trò chuyện (dùng chung cho student/tutor.php và student/api_tutor.php)
function strip_quiz(?string $json, array $answered): ?array {
    if (!$json) return null;
    $qs = json_decode($json, true) ?: [];
    return array_map(function ($q, $i) use ($answered) {
        $a = $answered[$i] ?? null;
        return ['content' => $q['content'], 'options' => $q['options'], 'topic' => $q['topic'] ?? '', 'difficulty' => $q['difficulty'] ?? '',
                'answered' => $a ? ['answer' => $a['answer'], 'correct' => $q['correct'], 'ok' => (bool)$a['is_correct'], 'explanation' => $q['explanation'] ?? ''] : null];
    }, $qs, array_keys($qs));
}
function msg_out(array $m, string $code): array {
    $ans = [];
    if ($m['quiz']) foreach (qall('SELECT * FROM tutor_answers WHERE message_id=?', [$m['id']]) as $a) $ans[(int)$a['q_index']] = $a;
    return ['id' => (int)$m['id'], 'role' => $m['role'], 'mode' => $m['mode'], 'content' => $m['content'], 'quiz' => strip_quiz($m['quiz'], $ans), 'at' => $m['created_at'],
            'feedback' => isset($m['feedback']) ? (int)$m['feedback'] : null];
}
/** Cuộc trò chuyện của học sinh (NULL nếu không phải của mình / không tồn tại). */
function my_conv(string $code, int $id): ?array {
    return $id ? q1('SELECT * FROM tutor_conversations WHERE id=? AND student_code=?', [$id, $code]) : null;
}
function convs(string $code): array {
    return array_map(fn($c) => ['id' => (int)$c['id'], 'title' => $c['title'], 'updated_at' => $c['updated_at']],
        qall('SELECT * FROM tutor_conversations WHERE student_code=? ORDER BY updated_at DESC, id DESC LIMIT 60', [$code]));
}
/** Một cuộc trò chuyện + 80 tin nhắn gần nhất (rỗng nếu không phải của mình). */
function conv_history(string $code, int $id): array {
    $conv = my_conv($code, $id);
    $rows = $conv ? array_reverse(qall('SELECT * FROM tutor_messages WHERE conversation_id=? ORDER BY id DESC LIMIT 80', [$conv['id']])) : [];
    return ['conversation' => $conv ? ['id' => (int)$conv['id'], 'title' => $conv['title']] : null,
            'messages' => array_map(fn($m) => msg_out($m, $code), $rows)];
}
