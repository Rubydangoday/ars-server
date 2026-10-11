<?php
// Đăng ký tài khoản học sinh: MSSV + họ tên + mật khẩu (hoặc Google). Mã tham gia lớp không bắt buộc:
// chưa có lớp thì tạo tài khoản trước, vào lớp sau ở trang Tổng quan bằng mã giáo viên cung cấp.
require __DIR__ . '/../lib/student.php';
require __DIR__ . '/../lib/oauth.php';
if (stu()) redirect('home.php' . (!empty($_GET['code']) ? '?join=' . rawurlencode((string)$_GET['code']) : ''));   // link /hs/MÃLỚP khi đã đăng nhập
$err = '';
$pending = oauth_pending('student');          // đến từ "Đăng nhập / Đăng ký với Google": không cần mật khẩu
$f = ['code' => trim($_POST['code'] ?? ''), 'name' => trim($_POST['name'] ?? ($pending['name'] ?? '')), 'join' => strtoupper(trim($_POST['join'] ?? ($_GET['code'] ?? '')))];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $p1 = $_POST['p1'] ?? ''; $p2 = $_POST['p2'] ?? '';
    $u = q1('SELECT * FROM student_users WHERE student_code=?', [$f['code']]);
    $in = q1('SELECT full_name FROM students WHERE student_code=? ORDER BY id DESC LIMIT 1', [$f['code']]);
    if (!preg_match('/^[A-Za-z0-9]{4,20}$/', $f['code'])) $err = 'MSSV / mã học sinh gồm 4–20 chữ hoặc số, không dấu cách.';
    elseif ($u && $u['password_hash']) $err = 'MSSV này đã có tài khoản. Hãy đăng nhập bằng mật khẩu' . ($pending ? ' — ARS sẽ hỏi để liên kết ' . OAUTH_PROVIDERS[$pending['provider']] . '.' : '; nếu quên mật khẩu, nhờ giáo viên bấm “đặt lại MK” rồi đăng ký lại.');
    elseif (!$in && $f['name'] === '') $err = 'Nhập họ và tên.';
    elseif (!$pending && mb_strlen($p1) < 6) $err = 'Mật khẩu phải có ít nhất 6 ký tự.';
    elseif (!$pending && $p1 !== $p2) $err = 'Mật khẩu xác nhận không khớp.';
    elseif (!$pending && $p1 === $f['code']) $err = 'Mật khẩu không được trùng với MSSV.';
    else {
        $name = $in['full_name'] ?? mb_substr($f['name'], 0, 100);
        db()->beginTransaction();
        try {
            // Chưa có trong danh sách lớp nào và không có mã lớp: chưa gì xác nhận đúng là học sinh này → đánh dấu chưa xác minh.
            q('INSERT INTO student_users (student_code, password_hash, must_change, last_login, full_name, unverified) VALUES (?,?,0,NOW(),?,?) '
            . 'ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash), must_change=0, last_login=NOW(), full_name=VALUES(full_name), unverified=VALUES(unverified)',
              [$f['code'], password_hash($pending ? bin2hex(random_bytes(24)) : $p1, PASSWORD_DEFAULT), $name, $in ? 0 : 1]);   // Google: mật khẩu ngẫu nhiên, không dùng mặc định = MSSV
            $msg = 'Tạo tài khoản thành công. Chào mừng bạn!';
            if ($f['join'] !== '') $msg .= ' ' . join_class($f['code'], $name, $f['join']);
            elseif (!$in) $msg .= ' Khi giáo viên cho mã tham gia lớp, nhập ở trang Tổng quan để vào lớp.';
            if ($pending && ($n = oauth_attach_pending('student', $f['code']))) $msg .= ' Lần sau bấm “Đăng nhập với ' . $n . '”.';
            db()->commit();
            session_regenerate_id(true);
            $_SESSION['student'] = ['code' => $f['code'], 'full_name' => $name, 'must_change' => false];
            flash($msg);
            redirect('home.php');
        } catch (RuntimeException $e) { if (db()->inTransaction()) db()->rollBack(); $err = $e->getMessage(); }
    }
}
student_head('Đăng ký');
?>
<div class="login">
  <div class="mascot">🦉</div>
  <h1>Tạo tài khoản học sinh</h1>
  <p class="muted">Chỉ cần MSSV và họ tên. Có <b>mã tham gia lớp</b> giáo viên cho thì nhập luôn; chưa có thì vào lớp sau.</p>
  <?php if ($err): ?><div class="flash err"><?= h($err) ?></div><?php endif; ?>
  <?= $pending ? oauth_pending_banner($pending, '../') : oauth_buttons('student', 'register', '../', 'Đăng ký với', [], true) . '<div class="or"><span>hoặc</span></div>' ?>
  <form method="post" class="stack" autocomplete="off"><?= csrf_field() ?>
    <label>MSSV / mã học sinh <input name="code" required inputmode="numeric" value="<?= h($f['code']) ?>" autofocus></label>
    <label>Họ và tên <input name="name" value="<?= h($f['name']) ?>" placeholder="Nguyễn Văn An"></label>
    <label>Mã tham gia lớp (không bắt buộc) <input name="join" value="<?= h($f['join']) ?>" maxlength="6" style="text-transform:uppercase;letter-spacing:3px" placeholder="VD: K7M2QX"></label>
    <?php if (!$pending): ?>
    <label>Mật khẩu <input name="p1" type="password" required minlength="6" autocomplete="new-password"></label>
    <label>Nhập lại mật khẩu <input name="p2" type="password" required minlength="6" autocomplete="new-password"></label>
    <?php endif; ?>
    <button class="primary">Tạo tài khoản</button>
  </form>
  <p class="small center">Đã có tài khoản? <a href="index.php">Đăng nhập</a></p>
</div>
<?php student_foot();
