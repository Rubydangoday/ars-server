<?php
// Giáo viên tự tạo tài khoản. Chế độ "cần mã mời": có mã đúng → dùng đầy đủ ngay; để trống → vào web ngay, nhập mã sau
// (tài khoản chưa xác nhận: AI chế độ Cơ bản, không tải tệp lên — lib/bootstrap.php account_limited()). Mã sai → báo lỗi.
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
    $code = trim($_POST['invite'] ?? '');
    $verified = $mode === 'open' || ($code !== '' && teacher_invite_ok($code)) ? 1 : 0;
    if ($mode === 'invite' && $code !== '' && !$verified) {
        $err = 'Mã mời giáo viên không đúng. Xin mã ở giáo viên quản trị server.'; usleep(600000);
    }
    elseif ($name === '') $err = 'Nhập họ tên.';
    elseif (!preg_match('/^[a-zA-Z0-9_.]{3,50}$/', $u)) $err = 'Tên đăng nhập 3–50 ký tự: chữ không dấu, số, dấu chấm, gạch dưới.';
    elseif (q1('SELECT 1 FROM teachers WHERE username=?', [$u])) $err = 'Tên đăng nhập đã có người dùng.';
    elseif (!$pending && mb_strlen($p) < 6) $err = 'Mật khẩu tối thiểu 6 ký tự.';
    elseif (!$pending && $p !== $p2) $err = 'Mật khẩu nhập lại không khớp.';
    elseif ($pending) {
        // Mật khẩu ngẫu nhiên không ai biết: đăng nhập bằng Google. Muốn có mật khẩu: Cài đặt → mã khôi phục → Quên mật khẩu.
        q('INSERT INTO teachers (username, full_name, password_hash, verified) VALUES (?,?,?,?)', [$u, mb_substr($name, 0, 100), password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT), $verified]);
        $tid = (int)db()->lastInsertId();
        $n = oauth_attach_pending('teacher', (string)$tid);
        session_regenerate_id(true);
        $_SESSION['teacher'] = ['id' => $tid, 'username' => $u, 'full_name' => $name, 'is_admin' => false];
        q('UPDATE teachers SET last_login=NOW() WHERE id=?', [$tid]);
        flash('Đã tạo tài khoản giáo viên. Lần sau bấm “Đăng nhập với ' . $n . '” để vào thẳng.');
        if (!$verified) flash('Nhập mã mời giáo viên ở thanh phía trên khi có mã để dùng đầy đủ.', 'warn');
        redirect('dashboard.php');
    } else {
        q('INSERT INTO teachers (username, full_name, password_hash, verified) VALUES (?,?,?,?)', [$u, $name, password_hash($p, PASSWORD_DEFAULT), $verified]);
        $tid = (int)db()->lastInsertId();
        $_SESSION['show_recovery'] = ['user' => $u, 'code' => new_recovery_code($tid)];
        // vào thẳng web (không phải đăng nhập lại); mã khôi phục hiện ở trang tổng quan
        session_regenerate_id(true);
        $_SESSION['teacher'] = ['id' => $tid, 'username' => $u, 'full_name' => $name, 'is_admin' => false];
        q('UPDATE teachers SET last_login=NOW() WHERE id=?', [$tid]);
        flash('Đã tạo tài khoản giáo viên. Chào mừng bạn!');
        if (!$verified) flash('Nhập mã mời giáo viên ở thanh phía trên khi có mã để dùng đầy đủ.', 'warn');
        redirect('dashboard.php');
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
    <?php if ($mode === 'invite'): ?><p class="muted small"><?= te('Chưa có mã mời giáo viên? Cứ để trống và tạo tài khoản, vào dùng ngay; nhập mã sau để mở trợ lý AI và tải tệp lên.') ?></p><?php endif; ?>
    <form method="post" class="stack" autocomplete="off">
      <?= csrf_field() ?>
      <?php if ($mode === 'invite'): ?>
        <label><?= te('Mã mời giáo viên (có thể nhập sau)') ?> <input name="invite" value="<?= h($_POST['invite'] ?? ($_GET['code'] ?? '')) ?>" placeholder="VD: GV-7K3P-Q9XA" style="text-transform:uppercase"></label>
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
