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
        json_out(['ok' => true, 'msg' => 'Kết nối thành công với ' . ai_label($c) . ' (' . round(microtime(true) - $t0, 1) . ' s): ' . mb_substr((string)$r['data'], 0, 40) . (!empty($r['fallback']) ? ' — model ' . $c['model'] . ' đang quá tải, đã dùng tạm ' . $r['fallback'] : '')]);
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

    $history = array_values(array_filter(array_map(fn($x) => mb_substr(trim((string)$x), 0, 400), (array)json_decode((string)($_POST['history'] ?? '[]'), true)), fn($x) => $x !== ''));
    if ($task === 'chat') {                          // hỏi đáp giảng dạy (giáo án, rubric, cách giảng…)
        $c = ai_conf();
        $msg = trim(mb_substr((string)($_POST['prompt'] ?? ''), 0, 4000));
        if ($msg === '') throw new RuntimeException('Nhập câu hỏi.');
        if ($c['provider'] === 'offline') {
            $tip = $source !== '' ? "\n\n### Ý chính của tài liệu đang chọn\n" . ai_summary($source) : '';
            json_out(['text' => "Chế độ cơ bản chưa trò chuyện tự do được — mình chỉ soạn được câu hỏi từ tài liệu. "
                . "Bật AI đầy đủ (Google Gemini miễn phí, Claude, OpenAI hoặc Ollama chạy trên máy) ở **Cài đặt → ✨ Trợ lý AI** để hỏi về giáo án, rubric, cách giảng…" . $tip,
                'provider' => ai_label($c)]);
        }
        $turns = [];
        foreach (array_slice((array)json_decode((string)($_POST['turns'] ?? '[]'), true), -8) as $m)
            if (is_array($m) && in_array($m['role'] ?? '', ['user', 'assistant'], true) && trim((string)($m['content'] ?? '')) !== '')
                $turns[] = ['role' => $m['role'], 'content' => mb_substr((string)$m['content'], 0, 6000)];
        while ($turns && $turns[0]['role'] !== 'user') array_shift($turns);
        $turns[] = ['role' => 'user', 'content' => $msg . ($source !== '' ? "\n\n<tai_lieu" . ($subject ? ' mon="' . $subject . '"' : '') . ">\n" . mb_substr($source, 0, 40000) . "\n</tai_lieu>" : '')];
        $fold = [];
        foreach ($turns as $m) { if ($fold && end($fold)['role'] === $m['role']) $fold[count($fold) - 1]['content'] .= "\n\n" . $m['content']; else $fold[] = $m; }
        $t0 = microtime(true);
        $r = ai_call($c, ai_teacher_system(), $fold, null, 3000);
        ai_log('teacher_chat', $c, mb_substr($msg, 0, 300), 1, $r, null, (int)((microtime(true) - $t0) * 1000));
        json_out(['text' => trim((string)$r['data']), 'provider' => ai_label($c)]);
    }
    $avoid = array_filter(array_map('intval', explode(',', (string)($_POST['avoid_sets'] ?? ''))));
    $own = $avoid ? array_column(qall('SELECT id FROM question_sets WHERE teacher_id=? AND id IN (' . implode(',', $avoid) . ')', [$t['id']]), 'id') : [];
    $r = ai_generate([
        'prompt' => mb_substr((string)($_POST['prompt'] ?? ''), 0, 2000),
        'n' => (int)($_POST['n'] ?? 5), 'n_options' => (int)($_POST['n_options'] ?? 4),
        'difficulty' => (string)($_POST['difficulty'] ?? 'mix'),
        'source' => $source, 'subject' => $subject, 'history' => $history,
        'existing' => array_merge(array_slice(array_filter((array)json_decode((string)($_POST['avoid_text'] ?? '[]'), true), 'is_string'), -40), qbank_stems($own)),
    ]);
    json_out($r);
} catch (Throwable $e) {
    json_out(['error' => $e->getMessage()], 400);
}
