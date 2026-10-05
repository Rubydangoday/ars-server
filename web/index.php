<?php
// Đăng nhập. Lần chạy đầu (chưa có tài khoản nào) → tạo tài khoản giáo viên đầu tiên.
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/oauth.php';

if (me()) redirect('dashboard.php');
$first_run = (int)qv('SELECT COUNT(*) FROM teachers') === 0;
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = trim($_POST['username'] ?? '');
    $p = $_POST['password'] ?? '';
    if ($first_run) {
        $name = trim($_POST['full_name'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9_.]{3,50}$/', $u)) $err = 'Tên đăng nhập 3–50 ký tự: chữ không dấu, số, dấu chấm, gạch dưới.';
        elseif (mb_strlen($p) < 6) $err = 'Mật khẩu tối thiểu 6 ký tự.';
        elseif ($name === '') $err = 'Nhập họ tên.';
        else {
            q('INSERT INTO teachers (username, full_name, password_hash, is_admin) VALUES (?,?,?,1)', [$u, $name, password_hash($p, PASSWORD_DEFAULT)]);
            $_SESSION['show_recovery'] = ['user' => $u, 'code' => new_recovery_code((int)db()->lastInsertId())];
            flash('Đã tạo tài khoản. Mời đăng nhập.');
            redirect('index.php');
        }
    } else {
        $t = q1('SELECT * FROM teachers WHERE username=?', [$u]);
        if ($t && password_verify($p, $t['password_hash']) && !(int)$t['active']) {
            $err = 'Tài khoản này đang bị khoá. Liên hệ giáo viên quản trị server.';
        } elseif ($t && password_verify($p, $t['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['teacher'] = ['id' => (int)$t['id'], 'username' => $t['username'], 'full_name' => $t['full_name'], 'is_admin' => (int)$t['is_admin'] === 1];
            q('UPDATE teachers SET last_login=NOW() WHERE id=?', [$t['id']]);
            redirect('dashboard.php');
        } else $err = 'Sai tên đăng nhập hoặc mật khẩu.';
        usleep(400000);
    }
}

page_head('Đăng nhập', '', true);
?>
<div class="login">
  <h1>ARS — Hệ thống phản hồi lớp học</h1>
  <p class="muted"><?= $first_run ? 'Lần chạy đầu: tạo tài khoản giáo viên.' : 'Đăng nhập dành cho giáo viên.' ?></p>
  <?php if ($err): ?><div class="flash err"><?= h($err) ?></div><?php endif; ?>
  <?php if (isset($_GET['locked'])): ?><div class="flash err">Tài khoản đã bị khoá. Liên hệ giáo viên quản trị server.</div><?php endif; ?>
  <?php if (!empty($_SESSION['show_recovery'])): $rc = $_SESSION['show_recovery']; unset($_SESSION['show_recovery']); ?>
    <div class="recovery"><b>Mã khôi phục mật khẩu của “<?= h($rc['user']) ?>”</b><code><?= h($rc['code']) ?></code>
      <span class="small">Chép hoặc chụp lại mã này. Khi quên mật khẩu, dùng mã ở trang “Quên mật khẩu”. Mã chỉ hiện một lần.</span></div>
  <?php endif; ?>
  <form method="post" class="stack">
    <?= csrf_field() ?>
    <?php if ($first_run): ?>
      <label>Họ tên <input name="full_name" required value="<?= h($_POST['full_name'] ?? '') ?>"></label>
    <?php endif; ?>
    <label>Tên đăng nhập <input name="username" required autofocus value="<?= h($_POST['username'] ?? '') ?>"></label>
    <label>Mật khẩu <input name="password" type="password" required></label>
    <button class="primary"><?= $first_run ? 'Tạo tài khoản' : 'Đăng nhập' ?></button>
  </form>
  <?= $first_run ? '' : oauth_buttons('teacher', 'login') ?>
  <?php if (!$first_run): ?><p class="small" style="display:flex;justify-content:space-between;margin:8px 0 0">
    <?php if (setting('teacher_signup', 'invite') !== 'closed'): ?><a href="register.php">Tạo tài khoản giáo viên mới</a><?php else: ?><span></span><?php endif; ?>
    <a href="forgot.php">Quên mật khẩu?</a></p><?php endif; ?>
  <p class="muted small">Học sinh: vào <a href="student/">cổng học sinh</a> (đăng nhập bằng MSSV).</p>
</div>
<?php page_foot();
