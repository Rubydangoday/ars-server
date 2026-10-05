<?php
// Đưa một hoặc nhiều câu (giáo viên đã xác nhận) vào bộ câu hỏi. Tạo bộ mới nếu set_id = "new".
//   POST set_id | (new_title, purpose), questions = JSON [câu chuẩn hoá, ...]
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/ai.php';
require __DIR__ . '/../lib/qbank.php';
$t = require_login_json();
try {
    $sid = $_POST['set_id'] ?? '';
    if ($sid === 'new') {
        $title = trim($_POST['new_title'] ?? '');
        if ($title === '') throw new RuntimeException('Nhập tên bộ câu hỏi mới.');
        $purpose = ($_POST['purpose'] ?? '') === 'practice' ? 'practice' : 'test';
        q('INSERT INTO question_sets (teacher_id, title, purpose) VALUES (?,?,?)', [$t['id'], mb_substr($title, 0, 200), $purpose]);
        $sid = (int)db()->lastInsertId();
    }
    $set = q1('SELECT * FROM question_sets WHERE id=? AND teacher_id=?', [(int)$sid, $t['id']]);
    if (!$set) throw new RuntimeException('Chọn bộ câu hỏi đích.');
    $list = json_decode($_POST['questions'] ?? '[]', true);
    if (!is_array($list)) $list = [];
    if (!$list && ($_POST['set_id'] ?? '') !== 'new') throw new RuntimeException('Không có câu hỏi nào để thêm.');
    $res = [];
    foreach ($list as $q) {
        try {
            $q = norm_question((array)$q, (string)($q['origin'] ?? 'manual')) + ['time_limit' => $q['time_limit'] ?? null];
            $res[] = ['ok' => true, 'position' => qbank_add($set, $q)];
        } catch (Throwable $e) { $res[] = ['ok' => false, 'error' => $e->getMessage()]; }
    }
    json_out(['set' => ['id' => (int)$set['id'], 'title' => $set['title'], 'purpose' => $set['purpose'],
                        'n' => (int)qv('SELECT COUNT(*) FROM questions WHERE set_id=?', [$set['id']])], 'results' => $res]);
} catch (Throwable $e) {
    json_out(['error' => $e->getMessage()], 400);
}
