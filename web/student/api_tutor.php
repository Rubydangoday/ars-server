<?php
// API trợ lý học tập (JSON): conversations | history | send | answer | feedback | rename | delete | clear
// Mỗi học sinh có nhiều cuộc trò chuyện (tutor_conversations); tin nhắn gắn conversation_id.
require __DIR__ . '/../lib/lms.php';
require __DIR__ . '/../lib/tutor.php';
$me = stu();
if (!$me || !empty($me['must_change'])) json_out(['error' => 'Phiên đăng nhập đã hết hạn. Vui lòng đăng nhập lại.'], 401);
$code = $me['code'];
@set_time_limit(200);

try {
    $act = $_POST['action'] ?? 'history';
    $cid = (int)($_POST['conversation_id'] ?? 0);
    if ($act === 'conversations') json_out(['conversations' => convs($code)]);
    if ($act === 'history') {
        json_out(conv_history($code, $cid) + ['stats' => stu_stats($code)]);
    }
    if ($act === 'rename') {
        if (!my_conv($code, $cid)) throw new RuntimeException('Không tìm thấy cuộc trò chuyện.');
        $t = trim(mb_substr((string)($_POST['title'] ?? ''), 0, 120));
        if ($t !== '') q('UPDATE tutor_conversations SET title=? WHERE id=?', [$t, $cid]);
        json_out(['ok' => true, 'conversations' => convs($code)]);
    }
    if ($act === 'delete') {
        if (!my_conv($code, $cid)) throw new RuntimeException('Không tìm thấy cuộc trò chuyện.');
        q('DELETE FROM tutor_messages WHERE conversation_id=? AND student_code=?', [$cid, $code]);   // câu đã làm (tutor_answers) và điểm thưởng vẫn giữ
        q('DELETE FROM tutor_conversations WHERE id=?', [$cid]);
        json_out(['ok' => true, 'conversations' => convs($code)]);
    }
    if ($act === 'feedback') {
        $v = (int)($_POST['value'] ?? 0); $v = $v > 0 ? 1 : ($v < 0 ? -1 : null);
        q('UPDATE tutor_messages SET feedback=? WHERE id=? AND student_code=? AND role="assistant"', [$v, (int)($_POST['message_id'] ?? 0), $code]);
        json_out(['ok' => true]);
    }
    if ($act === 'clear') { q('DELETE FROM tutor_messages WHERE student_code=?', [$code]); q('DELETE FROM tutor_conversations WHERE student_code=?', [$code]); json_out(['ok' => true]); }

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
    // Cuộc trò chuyện: dùng cuộc đang mở, chưa có thì tạo mới với tiêu đề lấy từ câu hỏi đầu tiên
    $conv = my_conv($code, $cid);
    if (!$conv) {
        $title = mb_strimwidth(preg_replace('/\s+/u', ' ', $text !== '' ? $text : ($question ? $question['content'] : $label)), 0, 60, '…');
        q('INSERT INTO tutor_conversations (student_code, title) VALUES (?,?)', [$code, $title]);
        $conv = q1('SELECT * FROM tutor_conversations WHERE id=?', [(int)db()->lastInsertId()]);
    }
    $cid = (int)$conv['id'];
    q('UPDATE tutor_conversations SET updated_at=NOW() WHERE id=?', [$cid]);
    q('INSERT INTO tutor_messages (student_code, role, mode, content, conversation_id) VALUES (?,?,?,?,?)', [$code, 'user', $mode, $user_content, $cid]);

    $weak = tutor_weak($code);
    $look = $topic ?: ($question ? '' : implode(' ', tutor_words($text)));
    if (!$topic && !$question && in_array(tutor_intent($text), ['simple', 'deep'], true)) $look = '';
    if ($look === '' && !$question && !$lesson_id)                 // câu nối tiếp ("giải thích lại", "kỹ hơn") → chủ đề của câu hỏi trước
        foreach (qall('SELECT content FROM tutor_messages WHERE conversation_id=? AND role="user" ORDER BY id DESC LIMIT 1, 6', [$cid]) as $h)
            if (!in_array(tutor_intent($h['content']), ['simple', 'deep', 'thanks', 'greet', 'who'], true) && ($look = implode(' ', tutor_words($h['content']))) !== '') break;
    $mat = tutor_material($code, $lesson_id, $look, $c['provider'] === 'anthropic' ? 60000 : 16000);
    $t0 = microtime(true);
    $quiz = null;
    if ($mode === 'quiz' || $mode === 'advanced') {
        $r = tutor_quiz($c, $mode, $level, $me['full_name'], $text, $topic, $mat, $weak, $n);
        $quiz = $r['questions'];
        $content = ($mode === 'advanced' ? "🚀 Thử sức với **" . count($quiz) . " bài nâng cao** nhé! Chọn đáp án để xem giải thích." : "🎯 Đây là **" . count($quiz) . " câu ôn tập** cho bạn. Chọn đáp án, mình chấm ngay!")
                 . ($c['provider'] === 'offline' ? "\n_(Câu hỏi lấy từ ngân hàng và bài giảng của lớp.)_" : '');
        ai_log('tutor_quiz', $c, $label, count($quiz), $r['usage'], null, (int)((microtime(true) - $t0) * 1000));
    } elseif ($c['provider'] === 'offline') {
        $prev = array_reverse(qall('SELECT role, content FROM tutor_messages WHERE conversation_id=? ORDER BY id DESC LIMIT 12', [$cid]));
        array_pop($prev);                                    // bỏ tin vừa lưu
        $first = trim(strrchr(' ' . $me['full_name'], ' '));
        $prev_user = '';                                     // câu hỏi "thật" gần nhất (không tính "giải thích lại", "cảm ơn"…)
        foreach ($prev as $h) if ($h['role'] === 'user' && !in_array(tutor_intent($h['content']), ['simple', 'deep', 'thanks', 'greet', 'who'], true) && tutor_words($h['content'])) $prev_user = $h['content'];
        $content = tutor_offline_text($mode, $text, $topic, $mat, $weak, $question,
            ['prev' => array_column(array_filter($prev, fn($h) => $h['role'] === 'assistant'), 'content'), 'prev_user' => $prev_user, 'name' => $first]);
    } else {
        $hist = [];
        foreach (array_reverse(qall('SELECT role, content FROM tutor_messages WHERE conversation_id=? AND quiz IS NULL ORDER BY id DESC LIMIT 9', [$cid])) as $h) $hist[] = ['role' => $h['role'], 'content' => $h['content']];
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
    q('INSERT INTO tutor_messages (student_code, role, mode, content, quiz, conversation_id) VALUES (?,?,?,?,?,?)',
      [$code, 'assistant', $mode, $content, $quiz ? json_encode($quiz, JSON_UNESCAPED_UNICODE) : null, $cid]);
    $m = q1('SELECT * FROM tutor_messages WHERE id=?', [(int)db()->lastInsertId()]);
    json_out(['message' => msg_out($m, $code), 'stats' => stu_stats($code), 'conversation' => ['id' => $cid, 'title' => $conv['title']],
              'left' => $c['provider'] !== 'offline' && tutor_limit() ? max(0, tutor_limit() - tutor_used_today($code)) : null]);
} catch (Throwable $e) {
    json_out(['error' => $e->getMessage()], 400);
}
