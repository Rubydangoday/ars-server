<?php
// Giáo viên tự tạo tài khoản. Mặc định cần "mã mời giáo viên" do quản trị viên cấp (trang Giáo viên).
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/auth_ui.php';
require __DIR__ . '/lib/oauth.php';

if (me()) redirect('dashboard.php');
if ((int)qv('SELECT COUNT(*) FROM teachers') === 0) redirect('index.php');      // chưa có ai → trang tạo tài khoản đầu tiên
$mode = setting('teacher_signup', 'invite');
$invite = (string)setting('teacher_invite_code', '');
$err = '';
$pending = oauth_pending('teacher');          // đến từ "Đăng nhập / Đăng ký với Google": không cần mật khẩu

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
    elseif (!$pending && mb_strlen($p) < 6) $err = 'Mật khẩu tối thiểu 6 ký tự.';
    elseif (!$pending && $p !== $p2) $err = 'Mật khẩu nhập lại không khớp.';
    elseif ($pending) {
        // Mật khẩu ngẫu nhiên không ai biết: đăng nhập bằng Google/Facebook. Muốn có mật khẩu: Cài đặt → mã khôi phục → Quên mật khẩu.
        q('INSERT INTO teachers (username, full_name, password_hash) VALUES (?,?,?)', [$u, mb_substr($name, 0, 100), password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT)]);
        $tid = (int)db()->lastInsertId();
        $n = oauth_attach_pending('teacher', (string)$tid);
        session_regenerate_id(true);
        $_SESSION['teacher'] = ['id' => $tid, 'username' => $u, 'full_name' => $name, 'is_admin' => false];
        q('UPDATE teachers SET last_login=NOW() WHERE id=?', [$tid]);
        flash('Đã tạo tài khoản giáo viên. Lần sau bấm “Đăng nhập với ' . $n . '” để vào thẳng.');
        redirect('dashboard.php');
    } else {
        q('INSERT INTO teachers (username, full_name, password_hash) VALUES (?,?,?)', [$u, $name, password_hash($p, PASSWORD_DEFAULT)]);
        $_SESSION['show_recovery'] = ['user' => $u, 'code' => new_recovery_code((int)db()->lastInsertId())];
        flash('Đã tạo tài khoản giáo viên. Mời đăng nhập.');
        redirect('index.php');
    }
}

auth_open('teacher', 'Tạo tài khoản giáo viên', 'Mỗi giáo viên có lớp, câu hỏi và bài giảng riêng.');
?>
  <?php if ($mode === 'closed'): ?>
    <div class="flash warn"><?= te('Server đang tạm đóng đăng ký giáo viên. Liên hệ giáo viên quản trị để được tạo tài khoản.') ?></div>
  <?php else: ?>
    <?php if ($err): ?><div class="flash err"><?= h($err) ?></div><?php endif; ?>
    <?php if ($pending): ?><?= oauth_pending_banner($pending, '') ?>
    <?php else: ?><?= oauth_buttons('teacher', 'register', '', t('Đăng ký với'), [], true) ?><?= auth_divider(t('hoặc tạo tài khoản bằng mật khẩu')) ?><?php endif; ?>
    <?php if ($mode === 'invite'): ?><p class="muted small"><?= te('Cần') ?> <b><?= te('mã mời giáo viên') ?></b> <?= te('do quản trị viên server cấp.') ?></p><?php endif; ?>
    <form method="post" class="stack" autocomplete="off">
      <?= csrf_field() ?>
      <?php if ($mode === 'invite'): ?>
        <label><?= te('Mã mời giáo viên') ?> <input name="invite" required value="<?= h($_POST['invite'] ?? ($_GET['code'] ?? '')) ?>" placeholder="VD: GV-7K3P-Q9XA" style="text-transform:uppercase"></label>
      <?php endif; ?>
      <label><?= te('Họ tên') ?> <input name="full_name" required value="<?= h($_POST['full_name'] ?? ($pending['name'] ?? '')) ?>"></label>
      <label><?= te('Tên đăng nhập') ?> <input name="username" required value="<?= h($_POST['username'] ?? ($pending ? oauth_username_suggest($pending['email'] ?? $pending['name'], 'teachers') : '')) ?>" placeholder="<?= te('vd: nguyenvana') ?>"></label>
      <?php if (!$pending): ?>
      <label><?= te('Mật khẩu') ?> <input name="password" type="password" required minlength="6"></label>
      <label><?= te('Nhập lại mật khẩu') ?> <input name="password2" type="password" required minlength="6"></label>
      <?php endif; ?>
      <button class="primary"><?= te('Tạo tài khoản') ?></button>
    </form>
  <?php endif; ?>
  <p class="small" style="margin-top:12px"><a href="index.php"><?= te('← Đã có tài khoản? Đăng nhập') ?></a></p>
<?php auth_close();
