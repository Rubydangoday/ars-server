<?php
// Giáo viên chưa xác nhận nhập mã mời giáo viên (thanh nhắc đầu trang) → dùng đầy đủ AI và tải tệp.
require __DIR__ . '/lib/bootstrap.php';
$t = require_login();
$back = (string)($_POST['back'] ?? '');
if (!preg_match('/^[a-z_]+\.php(\?[^\s<>"]*)?$/', $back)) $back = 'dashboard.php';    // chỉ quay về trang trong ARS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !teacher_verified()) {
    if (teacher_invite_ok((string)($_POST['invite'] ?? ''))) {
        q('UPDATE teachers SET verified=1 WHERE id=?', [$t['id']]);
        flash('Đã xác nhận tài khoản giáo viên. Bạn đã dùng được đầy đủ trợ lý AI và tải tệp lên.');
    } else {
        usleep(600000);                                                                   // chống dò mã
        flash('Mã mời giáo viên không đúng. Xin mã ở giáo viên quản trị server.', 'err');
    }
}
redirect($back);
