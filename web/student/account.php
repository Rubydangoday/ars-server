<?php
// Đổi mật khẩu (bắt buộc ở lần đăng nhập đầu).
require __DIR__ . '/../lib/student.php';
require __DIR__ . '/../lib/oauth.php';
$s = stu();
if (!$s) redirect('index.php');
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $p1 = $_POST['p1'] ?? ''; $p2 = $_POST['p2'] ?? '';
    if (mb_strlen($p1) < 6) $err = 'Mật khẩu phải có ít nhất 6 ký tự.';
    elseif ($p1 !== $p2) $err = 'Mật khẩu xác nhận không khớp.';
    elseif ($p1 === $s['code']) $err = 'Mật khẩu mới không được trùng với MSSV.';
    else {
        q('UPDATE student_users SET password_hash=?, must_change=0 WHERE student_code=?', [password_hash($p1, PASSWORD_DEFAULT), $s['code']]);
        $_SESSION['student']['must_change'] = false;
        flash('Đổi mật khẩu thành công.');
        redirect('home.php');
    }
}
student_head('Tài khoản');
?>
<div class="login">
  <h1>Đổi mật khẩu</h1>
  <p class="muted"><?= h($s['full_name']) ?> · MSSV <?= h($s['code']) ?><?= !empty($s['must_change']) ? '<br>Đây là lần đăng nhập đầu tiên. Vui lòng đặt mật khẩu mới để tiếp tục.' : '' ?></p>
  <?php if ($err): ?><div class="flash err"><?= h($err) ?></div><?php endif; ?>
  <form method="post" class="stack"><?= csrf_field() ?>
    <label>Mật khẩu mới <input name="p1" type="password" required minlength="6"></label>
    <label>Xác nhận mật khẩu mới <input name="p2" type="password" required minlength="6"></label>
    <button class="primary">Lưu mật khẩu</button>
  </form>
  <?= empty($s['must_change']) ? oauth_manage_html('student', $s['code'], '../') : '' ?>
  <?php if (empty($s['must_change'])): ?><p><a href="home.php">← Về tổng quan</a></p><?php endif; ?>
</div>
<?php student_foot();
