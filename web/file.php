<?php
// Tải / xem file bài giảng. Giáo viên của lớp luôn xem được; học sinh của lớp chỉ khi buổi đã công bố.
require __DIR__ . '/lib/bootstrap.php';
$l = q1('SELECT l.*, c.teacher_id FROM lessons l JOIN classes c ON c.id=l.class_id WHERE l.id=?', [(int)($_GET['lesson'] ?? 0)]);
$ok = false;
if ($l && $l['file_store']) {
    if (me() && (int)me()['id'] === (int)$l['teacher_id']) $ok = true;
    elseif (!empty($_SESSION['student']['code']) && $l['published']
        && q1('SELECT 1 FROM students WHERE class_id=? AND student_code=?', [$l['class_id'], $_SESSION['student']['code']])) $ok = true;
}
if ($l && $l['file_store'] && $l['published'] && !empty($_SESSION['parent']['id']) && qv('SELECT 1 FROM parent_links pl JOIN students st ON st.class_id=pl.class_id AND st.student_code=pl.student_code WHERE pl.parent_id=? AND pl.class_id=?', [$_SESSION['parent']['id'],$l['class_id']])) $ok=true;
$path = $ok ? uploads_dir() . '/' . basename($l['file_store']) : '';
if (!$ok || !is_file($path)) { http_response_code(404); exit('Không tìm thấy file.'); }

$ext = strtolower(pathinfo($l['file_name'], PATHINFO_EXTENSION));
$types = ['pdf' => 'application/pdf', 'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
          'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'ppt' => 'application/vnd.ms-powerpoint',
          'doc' => 'application/msword', 'txt' => 'text/plain; charset=utf-8', 'md' => 'text/plain; charset=utf-8'];
$inline = !empty($_GET['inline']) && $ext === 'pdf';
$ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $l['file_name']) ?: 'bai_giang.' . $ext);
session_write_close();
header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($l['file_name']));
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=300');
output_raw(); readfile($path);
