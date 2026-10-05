<?php
// Tách câu hỏi từ văn bản dán vào hoặc file Word/Excel tải lên → JSON (chưa ghi CSDL).
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/qparse.php';
require_login_json();
try {
    if (!empty($_FILES['file']['tmp_name'])) {
        if ($_FILES['file']['size'] > 20 * 1024 * 1024) throw new RuntimeException('File lớn hơn 20 MB.');
        $r = parse_upload($_FILES['file']['tmp_name'], $_FILES['file']['name']);
        $r['source'] = $_FILES['file']['name'];
    } elseif (trim($_POST['text'] ?? '') !== '') {
        $r = parse_paragraphs(text_paragraphs($_POST['text']), 'text');
        $r['source'] = 'văn bản';
    } else throw new RuntimeException('Dán nội dung câu hỏi hoặc chọn một file.');
    json_out($r);
} catch (Throwable $e) {
    json_out(['error' => $e->getMessage()], 400);
}
