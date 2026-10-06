<?php
// Quên mật khẩu giáo viên.
//  - Mở trực tiếp trên laptop chạy server (localhost): đặt lại ngay, không cần mã (người ngồi ở máy chủ là chủ máy).
//  - Mở từ máy khác: cần mã khôi phục (hiện khi tạo tài khoản hoặc tạo lại trong Cài đặt).
//  - Cách khác trên terminal của laptop: ars reset-gv <tên đăng nhập>
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/auth_ui.php';
if (me()) redirect('settings.php#account');
$local = is_local_request();
$users = $local ? qall('SELECT username, full_name FROM teachers ORDER BY id') : [];
$err = ''; $new_code = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    usleep(500000);
    $u = trim($_POST['username'] ?? '');
    $p1 = $_POST['p1'] ?? ''; $p2 = $_POST['p2'] ?? '';
    $t = q1('SELECT * FROM teachers WHERE username=?', [$u]);
    $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $_POST['code'] ?? ''));
    $code = $code !== '' ? implode('-', str_split($code, 4)) : '';
    if (!$t) $err = 'Không có tài khoản giáo viên với tên đăng nhập này.';
    elseif (!$local && (!$t['recovery_hash'] || !password_verify($code, $t['recovery_hash'])))
        $err = 'Mã khôi phục không đúng. Nếu không còn mã, hãy mở trang này trực tiếp trên laptop chạy server (http://localhost/ars/forgot.php).';
    elseif (mb_strlen($p1) < 6) $err = 'Mật khẩu mới phải có ít nhất 6 ký tự.';
    elseif ($p1 !== $p2) $err = 'Mật khẩu xác nhận không khớp.';
    else {
        q('UPDATE teachers SET password_hash=? WHERE id=?', [password_hash($p1, PASSWORD_DEFAULT), $t['id']]);
        $new_code = new_recovery_code((int)$t['id']);          // mã cũ đã dùng → cấp mã mới
        flash('Đặt lại mật khẩu thành công. Mời đăng nhập.');
        $_SESSION['show_recovery'] = ['user' => $t['username'], 'code' => $new_code];
        redirect('index.php');
    }
}
auth_open('teacher', 'Đặt lại mật khẩu', 'Dành cho tài khoản giáo viên.');
?>
  <?php if ($local): ?>
    <p class="muted"><?= te('Bạn đang mở trang này trực tiếp trên máy chủ nên có thể đặt lại mật khẩu mà không cần mã khôi phục.') ?></p>
  <?php else: ?>
    <p class="muted"><?= te('Nhập mã khôi phục (hiện khi tạo tài khoản, hoặc tạo lại trong Cài đặt → Tài khoản). Không còn mã: mở') ?>
      <code>http://localhost/ars/forgot.php</code> <?= te('ngay trên laptop chạy server, hoặc chạy lệnh') ?> <code>ars reset-gv</code> <?= te('trên terminal.') ?></p>
  <?php endif; ?>
  <?php if ($err): ?><div class="flash err"><?= h($err) ?></div><?php endif; ?>
  <form method="post" class="stack" autocomplete="off"><?= csrf_field() ?>
    <?php if ($local && $users): ?>
      <label><?= te('Tài khoản') ?> <select name="username"><?php foreach ($users as $x): ?><option value="<?= h($x['username']) ?>" <?= ($_POST['username'] ?? '') === $x['username'] ? 'selected' : '' ?>><?= h($x['username']) ?> — <?= h($x['full_name']) ?></option><?php endforeach; ?></select></label>
    <?php else: ?>
      <label><?= te('Tên đăng nhập') ?> <input name="username" required value="<?= h($_POST['username'] ?? '') ?>"></label>
      <label><?= te('Mã khôi phục') ?> <input name="code" required placeholder="ABCD-EFGH-JKLM" style="text-transform:uppercase;letter-spacing:2px"></label>
    <?php endif; ?>
    <label><?= te('Mật khẩu mới') ?> <input name="p1" type="password" required minlength="6" autocomplete="new-password"></label>
    <label><?= te('Xác nhận mật khẩu mới') ?> <input name="p2" type="password" required minlength="6" autocomplete="new-password"></label>
    <button class="primary"><?= te('Đặt lại mật khẩu') ?></button>
  </form>
  <p class="small"><a href="index.php"><?= te('← Quay lại đăng nhập') ?></a></p>
<?php auth_close();
