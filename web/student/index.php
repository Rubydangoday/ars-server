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
    $u = $code !== '' ? q1('SELECT * FROM student_users WHERE student_code=?', [$code]) : null;
    // Có mật khẩu riêng: đăng nhập được cả khi chưa vào lớp nào. Mật khẩu mặc định = MSSV chỉ dành cho học sinh có trong danh sách lớp.
    $ok = $u && $u['password_hash'] ? password_verify($pw, $u['password_hash']) : ($inClass && hash_equals($code, $pw));
    if ($ok) {
        if (!$u) q('INSERT INTO student_users (student_code, must_change) VALUES (?,1)', [$code]);
        q('UPDATE student_users SET last_login=NOW() WHERE student_code=?', [$code]);
        session_regenerate_id(true);
        $_SESSION['student'] = ['code' => $code, 'full_name' => student_display_name($code) ?? $code, 'must_change' => !$u || $u['must_change']];
        redirect('home.php');
    }
    $err = !$inClass && !$u ? 'MSSV này chưa có tài khoản. Bấm “Tạo tài khoản mới” để đăng ký.'
         : ($u && $u['password_hash'] ? 'Mật khẩu không đúng. Quên mật khẩu: nhờ giáo viên bấm “đặt lại MK”.'
                                      : 'Mật khẩu không đúng. Với lần đăng nhập đầu tiên, mật khẩu mặc định là MSSV của bạn.');
    usleep(400000);
}
student_head('Đăng nhập');
?>
<nav class="stu-roles"><a href="../index.php">🧑‍🏫 Giáo viên</a><a class="on" href="./">🎒 Học sinh</a><a href="../parent/">👪 Phụ huynh</a></nav>
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
  <?= oauth_buttons('student', 'login', '../', 'Đăng nhập với', [], true) ?>
  <div class="or"><span>hoặc</span></div>
  <a class="btn big alt" href="register.php">✨ Tạo tài khoản mới</a>
  <p class="muted small center">Quên mật khẩu: nhờ giáo viên bấm “đặt lại MK” ở trang lớp.</p>
</div>
<?php student_foot();
