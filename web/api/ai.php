<?php
// Trợ lý AI: task=generate (soạn câu hỏi), summary (tóm tắt bài giảng), test (kiểm tra kết nối).
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/ai.php';
require __DIR__ . '/../lib/qbank.php';
$t = require_login_json();
@set_time_limit(200);
try {
    $task = $_POST['task'] ?? 'generate';
    if ($task === 'test') {
        $c = ai_conf();
        if ($c['provider'] === 'offline') json_out(['ok' => true, 'msg' => 'Chế độ cơ bản hoạt động (không cần Internet).']);
        $t0 = microtime(true);
        $r = ai_call($c, 'Trả lời đúng một từ.', 'Trả lời "OK".', null, 20);
        ai_log('test', $c, 'test', 1, $r, null, (int)((microtime(true) - $t0) * 1000));
        json_out(['ok' => true, 'msg' => 'Kết nối thành công với ' . ai_label($c) . ' (' . round(microtime(true) - $t0, 1) . ' s): ' . mb_substr((string)$r['data'], 0, 40)]);
    }
    // nguồn tài liệu: văn bản dán vào hoặc bài giảng của một buổi
    $source = trim((string)($_POST['source_text'] ?? ''));
    $subject = '';
    if (!empty($_POST['lesson_id'])) {
        $l = own_lesson((int)$_POST['lesson_id']);
        $source = trim(($l['key_points'] ? "Kiến thức trọng tâm:\n" . $l['key_points'] . "\n\n" : '') . (string)$l['lecture_text']) ?: $source;
        $subject = $l['class_name'] . ' — ' . lesson_label($l);
        if ($task === 'summary') json_out(['text' => ai_summary((string)$l['lecture_text'], lesson_label($l)), 'provider' => ai_label()]);
    }
    if ($task === 'summary') json_out(['text' => ai_summary($source), 'provider' => ai_label()]);

    $avoid = array_filter(array_map('intval', explode(',', (string)($_POST['avoid_sets'] ?? ''))));
    $own = $avoid ? array_column(qall('SELECT id FROM question_sets WHERE teacher_id=? AND id IN (' . implode(',', $avoid) . ')', [$t['id']]), 'id') : [];
    $r = ai_generate([
        'prompt' => mb_substr((string)($_POST['prompt'] ?? ''), 0, 2000),
        'n' => (int)($_POST['n'] ?? 5), 'n_options' => (int)($_POST['n_options'] ?? 4),
        'difficulty' => (string)($_POST['difficulty'] ?? 'mix'),
        'source' => $source, 'subject' => $subject,
        'existing' => array_merge(array_slice(array_filter((array)json_decode((string)($_POST['avoid_text'] ?? '[]'), true), 'is_string'), -40), qbank_stems($own)),
    ]);
    json_out($r);
} catch (Throwable $e) {
    json_out(['error' => $e->getMessage()], 400);
}
