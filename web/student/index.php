<?php
// Đăng nhập học sinh bằng MSSV. Lần đầu: mật khẩu = MSSV, sau đó bắt buộc đổi.
require __DIR__ . '/../lib/student.php';
require __DIR__ . '/../lib/oauth.php';
if (stu()) redirect('home.php');
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = trim($_POST['code'] ?? '');
    $pw = $_POST['password'] ?? '';
    $inClass = q1('SELECT full_name FROM students WHERE student_code=? ORDER BY id DESC LIMIT 1', [$code]);
    $u = $inClass ? q1('SELECT * FROM student_users WHERE student_code=?', [$code]) : null;
    $ok = $inClass && ($u && $u['password_hash'] ? password_verify($pw, $u['password_hash']) : hash_equals($code, $pw));
    if ($ok) {
        if (!$u) q('INSERT INTO student_users (student_code, must_change) VALUES (?,1)', [$code]);
        q('UPDATE student_users SET last_login=NOW() WHERE student_code=?', [$code]);
        session_regenerate_id(true);
        $_SESSION['student'] = ['code' => $code, 'full_name' => $inClass['full_name'], 'must_change' => !$u || $u['must_change']];
        redirect('home.php');
    }
    $err = !$inClass ? 'MSSV này chưa có trong lớp nào. Bấm “Đăng ký” và nhập mã tham gia lớp giáo viên cung cấp.'
         : ($u && $u['password_hash'] ? 'Mật khẩu không đúng. Quên mật khẩu: nhờ giáo viên bấm “đặt lại MK”.'
                                      : 'Mật khẩu không đúng. Với lần đăng nhập đầu tiên, mật khẩu mặc định là MSSV của bạn.');
    usleep(400000);
}
student_head('Đăng nhập');
?>
<div class="login">
  <div class="mascot">🦉</div>
  <h1>Chào mừng bạn đến lớp học!</h1>
  <p class="muted">Xem lại bài, ôn tập cùng trợ lý AI và theo dõi tiến bộ của bạn.</p>
  <?php if ($err): ?><div class="flash err"><?= h($err) ?></div><?php endif; ?>
  <form method="post" class="stack"><?= csrf_field() ?>
    <label>MSSV <input name="code" required autofocus inputmode="numeric" value="<?= h($_POST['code'] ?? '') ?>"></label>
    <label>Mật khẩu <input name="password" type="password" required></label>
    <button class="primary">Đăng nhập</button>
  </form>
  <?= oauth_buttons('student', 'login', '../') ?>
  <div class="or"><span>hoặc</span></div>
  <a class="btn big alt" href="register.php">✨ Tạo tài khoản mới</a>
  <p class="muted small center">Quên mật khẩu: nhờ giáo viên bấm “đặt lại MK” ở trang lớp.</p>
</div>
<?php student_foot();
