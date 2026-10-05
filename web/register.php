<?php
// Giáo viên tự tạo tài khoản. Mặc định cần "mã mời giáo viên" do quản trị viên cấp (trang Giáo viên).
require __DIR__ . '/lib/bootstrap.php';

if (me()) redirect('dashboard.php');
if ((int)qv('SELECT COUNT(*) FROM teachers') === 0) redirect('index.php');      // chưa có ai → trang tạo tài khoản đầu tiên
$mode = setting('teacher_signup', 'invite');
$invite = (string)setting('teacher_invite_code', '');
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $mode !== 'closed') {
    $u = trim($_POST['username'] ?? '');
    $p = $_POST['password'] ?? '';
    $p2 = $_POST['password2'] ?? '';
    $name = trim($_POST['full_name'] ?? '');
    $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $_POST['invite'] ?? ''));
    if ($mode === 'invite' && ($invite === '' || !hash_equals(strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $invite)), $code))) {
        $err = 'Mã mời giáo viên không đúng. Xin mã ở giáo viên quản trị server.'; usleep(600000);
    }
    elseif ($name === '') $err = 'Nhập họ tên.';
    elseif (!preg_match('/^[a-zA-Z0-9_.]{3,50}$/', $u)) $err = 'Tên đăng nhập 3–50 ký tự: chữ không dấu, số, dấu chấm, gạch dưới.';
    elseif (q1('SELECT 1 FROM teachers WHERE username=?', [$u])) $err = 'Tên đăng nhập đã có người dùng.';
    elseif (mb_strlen($p) < 6) $err = 'Mật khẩu tối thiểu 6 ký tự.';
    elseif ($p !== $p2) $err = 'Mật khẩu nhập lại không khớp.';
    else {
        q('INSERT INTO teachers (username, full_name, password_hash) VALUES (?,?,?)', [$u, $name, password_hash($p, PASSWORD_DEFAULT)]);
        $_SESSION['show_recovery'] = ['user' => $u, 'code' => new_recovery_code((int)db()->lastInsertId())];
        flash('Đã tạo tài khoản giáo viên. Mời đăng nhập.');
        redirect('index.php');
    }
}

page_head('Tạo tài khoản giáo viên', '', true);
?>
<div class="login">
  <h1>Tạo tài khoản giáo viên</h1>
  <?php if ($mode === 'closed'): ?>
    <div class="flash warn">Server đang tạm đóng đăng ký giáo viên. Liên hệ giáo viên quản trị để được tạo tài khoản.</div>
  <?php else: ?>
    <p class="muted"><?= $mode === 'invite' ? 'Nhập mã mời giáo viên do quản trị viên cấp. Mỗi giáo viên có lớp, câu hỏi và bài giảng riêng.' : 'Mỗi giáo viên có lớp, câu hỏi và bài giảng riêng.' ?></p>
    <?php if ($err): ?><div class="flash err"><?= h($err) ?></div><?php endif; ?>
    <form method="post" class="stack" autocomplete="off">
      <?= csrf_field() ?>
      <?php if ($mode === 'invite'): ?>
        <label>Mã mời giáo viên <input name="invite" required value="<?= h($_POST['invite'] ?? ($_GET['code'] ?? '')) ?>" placeholder="VD: GV-7K3P-Q9XA" style="text-transform:uppercase"></label>
      <?php endif; ?>
      <label>Họ tên <input name="full_name" required value="<?= h($_POST['full_name'] ?? '') ?>"></label>
      <label>Tên đăng nhập <input name="username" required value="<?= h($_POST['username'] ?? '') ?>" placeholder="vd: nguyenvana"></label>
      <label>Mật khẩu <input name="password" type="password" required minlength="6"></label>
      <label>Nhập lại mật khẩu <input name="password2" type="password" required minlength="6"></label>
      <button class="primary">Tạo tài khoản</button>
    </form>
  <?php endif; ?>
  <p class="small" style="margin-top:12px"><a href="index.php">← Đã có tài khoản? Đăng nhập</a></p>
</div>
<?php page_foot();
