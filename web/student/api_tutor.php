<?php
// API trợ lý học tập (JSON): send | answer | history | clear
require __DIR__ . '/../lib/lms.php';
require __DIR__ . '/../lib/tutor.php';
$me = stu();
if (!$me || !empty($me['must_change'])) json_out(['error' => 'Phiên đăng nhập đã hết hạn. Vui lòng đăng nhập lại.'], 401);
$code = $me['code'];
@set_time_limit(200);

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
    return ['id' => (int)$m['id'], 'role' => $m['role'], 'mode' => $m['mode'], 'content' => $m['content'], 'quiz' => strip_quiz($m['quiz'], $ans), 'at' => $m['created_at']];
}

try {
    $act = $_POST['action'] ?? 'history';
    if ($act === 'history') {
        $rows = array_reverse(qall('SELECT * FROM tutor_messages WHERE student_code=? ORDER BY id DESC LIMIT 40', [$code]));
        json_out(['messages' => array_map(fn($m) => msg_out($m, $code), $rows), 'stats' => stu_stats($code)]);
    }
    if ($act === 'clear') { q('DELETE FROM tutor_messages WHERE student_code=?', [$code]); json_out(['ok' => true]); }

    if ($act === 'answer') {
        $m = q1('SELECT * FROM tutor_messages WHERE id=? AND student_code=? AND quiz IS NOT NULL', [(int)$_POST['message_id'], $code]);
        if (!$m) throw new RuntimeException('Không tìm thấy câu hỏi.');
        $qs = json_decode($m['quiz'], true); $i = (int)$_POST['q_index'];
        if (!isset($qs[$i])) throw new RuntimeException('Không tìm thấy câu hỏi.');
        $q = $qs[$i]; $ans = strtoupper(substr((string)$_POST['answer'], 0, 1));
        $old = q1('SELECT * FROM tutor_answers WHERE message_id=? AND q_index=?', [$m['id'], $i]);
        if (!$old) q('INSERT INTO tutor_answers (student_code, message_id, q_index, answer, is_correct, topic) VALUES (?,?,?,?,?,?)',
                     [$code, $m['id'], $i, $ans, (int)($ans === $q['correct']), mb_substr((string)($q['topic'] ?? ''), 0, 100) ?: null]);
        $ok = $old ? (bool)$old['is_correct'] : $ans === $q['correct'];
        json_out(['ok' => $ok, 'answer' => $old['answer'] ?? $ans, 'correct' => $q['correct'], 'explanation' => $q['explanation'] ?? '', 'stats' => stu_stats($code)]);
    }

    if ($act !== 'send') throw new RuntimeException('Yêu cầu không hợp lệ.');
    if (!tutor_enabled()) throw new RuntimeException('Giáo viên đang tắt trợ lý học tập.');
    if ($t = tutor_exam_lock($code)) throw new RuntimeException("Bạn đang làm bài kiểm tra “{$t}”. Trợ lý tạm khoá đến khi bạn nộp bài để đảm bảo công bằng.");
    $c = ai_conf();
    if ($c['provider'] !== 'offline' && tutor_limit() && tutor_used_today($code) >= tutor_limit())
        throw new RuntimeException('Hôm nay bạn đã hỏi đủ ' . tutor_limit() . ' lượt. Nghỉ ngơi một chút rồi mai học tiếp nhé! 🌙');

    $mode = array_key_exists($_POST['mode'] ?? '', TUTOR_MODES) ? $_POST['mode'] : 'ask';
    $level = array_key_exists($_POST['level'] ?? '', TUTOR_LEVELS) ? $_POST['level'] : 'thpt';
    $text = trim(mb_substr((string)($_POST['text'] ?? ''), 0, 2000));
    $topic = trim(mb_substr((string)($_POST['topic'] ?? ''), 0, 100));
    $lesson_id = (int)($_POST['lesson_id'] ?? 0) ?: null;
    $n = max(3, min(10, (int)($_POST['n'] ?? 5)));
    $question = null;
    if (!empty($_POST['question_id'])) {
        foreach (array_merge(my_items($code),homework_wrong_items($code),practice_wrong_items($code)) as $r) if ((int)$r['question_id'] === (int)$_POST['question_id']) { $question = $r + ['mine' => $r['answer'] ?? null]; }
        if ($question) $topic = $topic ?: (string)$question['topic'];
    }
    if ($mode === 'ask' && $text === '' && !$question) throw new RuntimeException('Bạn hãy nhập câu hỏi nhé.');

    $label = TUTOR_MODES[$mode][0] . ' ' . TUTOR_MODES[$mode][1] . ($topic !== '' ? ' · ' . $topic : '');
    if ($lesson_id && ($l = q1('SELECT no, title FROM lessons WHERE id=?', [$lesson_id]))) $label .= ' · Buổi ' . $l['no'] . ($l['title'] !== '' ? ' ' . $l['title'] : '');
    $user_content = $text !== '' ? ($mode === 'ask' ? $text : $label . "\n" . $text) : ($question ? $label . "\n“" . mb_strimwidth($question['content'], 0, 140, '…') . '”' : $label);
    q('INSERT INTO tutor_messages (student_code, role, mode, content) VALUES (?,?,?,?)', [$code, 'user', $mode, $user_content]);

    $weak = tutor_weak($code);
    $mat = tutor_material($code, $lesson_id, $topic ?: ($question ? '' : $text), $c['provider'] === 'anthropic' ? 60000 : 16000);
    $t0 = microtime(true);
    $quiz = null;
    if ($mode === 'quiz' || $mode === 'advanced') {
        $r = tutor_quiz($c, $mode, $level, $me['full_name'], $text, $topic, $mat, $weak, $n);
        $quiz = $r['questions'];
        $content = ($mode === 'advanced' ? "🚀 Thử sức với **" . count($quiz) . " bài nâng cao** nhé! Chọn đáp án để xem giải thích." : "🎯 Đây là **" . count($quiz) . " câu ôn tập** cho bạn. Chọn đáp án, mình chấm ngay!")
                 . ($c['provider'] === 'offline' ? "\n_(Câu hỏi lấy từ ngân hàng và bài giảng của lớp.)_" : '');
        ai_log('tutor_quiz', $c, $label, count($quiz), $r['usage'], null, (int)((microtime(true) - $t0) * 1000));
    } elseif ($c['provider'] === 'offline') {
        $content = tutor_offline_text($mode, $text, $topic, $mat, $weak, $question);
    } else {
        $hist = [];
        if ($mode === 'ask') foreach (array_reverse(qall('SELECT role, content FROM tutor_messages WHERE student_code=? AND quiz IS NULL ORDER BY id DESC LIMIT 9', [$code])) as $h) $hist[] = ['role' => $h['role'], 'content' => $h['content']];
        array_pop($hist);                                    // tin vừa lưu: thay bằng bản có tài liệu kèm theo
        while ($hist && $hist[0]['role'] !== 'user') array_shift($hist);
        $hist[] = ['role' => 'user', 'content' => tutor_task_prompt($mode, $text, $topic, $mat, $weak, $question)];
        $fold = [];                                          // gộp tin liền nhau cùng vai (API yêu cầu xen kẽ user/assistant)
        foreach ($hist as $h) { if ($fold && end($fold)['role'] === $h['role']) $fold[count($fold) - 1]['content'] .= "\n\n" . $h['content']; else $fold[] = $h; }
        $hist = $fold;
        $r = ai_call($c, tutor_system($level, $weak, $me['full_name']), $hist, null, $mode === 'ask' ? 1500 : 3000);
        $content = trim((string)$r['data']) ?: 'Mình chưa trả lời được, bạn thử hỏi lại nhé.';
        ai_log('tutor', $c, $label, 1, $r, null, (int)((microtime(true) - $t0) * 1000));
    }
    q('INSERT INTO tutor_messages (student_code, role, mode, content, quiz) VALUES (?,?,?,?,?)',
      [$code, 'assistant', $mode, $content, $quiz ? json_encode($quiz, JSON_UNESCAPED_UNICODE) : null]);
    $m = q1('SELECT * FROM tutor_messages WHERE id=?', [(int)db()->lastInsertId()]);
    json_out(['message' => msg_out($m, $code), 'stats' => stu_stats($code),
              'left' => $c['provider'] !== 'offline' && tutor_limit() ? max(0, tutor_limit() - tutor_used_today($code)) : null]);
} catch (Throwable $e) {
    json_out(['error' => $e->getMessage()], 400);
}
