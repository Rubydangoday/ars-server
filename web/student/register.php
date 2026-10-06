<?php
// Đăng ký tài khoản học sinh: MSSV + họ tên + mật khẩu; vào lớp mới bằng mã tham gia lớp của giáo viên.
require __DIR__ . '/../lib/student.php';
require __DIR__ . '/../lib/oauth.php';
if (stu()) redirect('home.php');
$err = '';
$pending = oauth_pending('student');          // đến từ "Đăng nhập / Đăng ký với Google": không cần mật khẩu
$f = ['code' => trim($_POST['code'] ?? ''), 'name' => trim($_POST['name'] ?? ($pending['name'] ?? '')), 'join' => strtoupper(trim($_POST['join'] ?? ($_GET['code'] ?? '')))];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $p1 = $_POST['p1'] ?? ''; $p2 = $_POST['p2'] ?? '';
    $u = q1('SELECT * FROM student_users WHERE student_code=?', [$f['code']]);
    $in = q1('SELECT full_name FROM students WHERE student_code=? ORDER BY id DESC LIMIT 1', [$f['code']]);
    $cls = $f['join'] !== '' ? q1('SELECT * FROM classes WHERE join_code=?', [$f['join']]) : null;
    if (!preg_match('/^[A-Za-z0-9]{4,20}$/', $f['code'])) $err = 'MSSV / mã học sinh gồm 4–20 chữ hoặc số, không dấu cách.';
    elseif ($u && $u['password_hash']) $err = 'MSSV này đã có tài khoản. Hãy đăng nhập bằng mật khẩu' . ($pending ? ' — ARS sẽ hỏi để liên kết ' . OAUTH_PROVIDERS[$pending['provider']] . '.' : '; nếu quên mật khẩu, nhờ giáo viên bấm “đặt lại MK” rồi đăng ký lại.');
    elseif (!$in && $f['name'] === '') $err = 'Nhập họ và tên.';
    elseif (!$pending && mb_strlen($p1) < 6) $err = 'Mật khẩu phải có ít nhất 6 ký tự.';
    elseif (!$pending && $p1 !== $p2) $err = 'Mật khẩu xác nhận không khớp.';
    elseif (!$pending && $p1 === $f['code']) $err = 'Mật khẩu không được trùng với MSSV.';
    elseif ($f['join'] !== '' && !$cls) $err = 'Mã lớp không đúng. Kiểm tra lại mã giáo viên cung cấp (6 ký tự).';
    elseif ($cls && !$cls['join_open']) $err = 'Lớp này đang khoá đăng ký. Nhờ giáo viên mở đăng ký.';
    elseif (!$in && !$cls) $err = 'MSSV chưa có trong danh sách lớp nào. Nhập mã tham gia lớp do giáo viên cung cấp.';
    else {
        $msg = 'Tạo tài khoản thành công. Chào mừng bạn!';
        db()->beginTransaction();
        if ($cls && !q1('SELECT 1 FROM students WHERE class_id=? AND student_code=?', [$cls['id'], $f['code']])) {
            $used = array_map('intval', array_column(qall('SELECT seat_no FROM students WHERE class_id=?', [$cls['id']]), 'seat_no'));
            $seat = 0; for ($i = 1; $i <= 99; $i++) if (!in_array($i, $used, true)) { $seat = $i; break; }
            if (!$seat) { db()->rollBack(); $err = 'Lớp đã đủ 99 học sinh.'; }
            else {
                q('INSERT INTO students (class_id, seat_no, student_code, full_name) VALUES (?,?,?,?)', [$cls['id'], $seat, $f['code'], $in['full_name'] ?? $f['name']]);
                $msg .= ' Bạn đã vào lớp ' . $cls['name'] . ' — số thứ tự (ID trên clicker) của bạn là ' . $seat . '.';
            }
        }
        if (!$err) {
            q('INSERT INTO student_users (student_code, password_hash, must_change, last_login) VALUES (?,?,0,NOW()) '
            . 'ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash), must_change=0, last_login=NOW()',
              [$f['code'], password_hash($pending ? bin2hex(random_bytes(24)) : $p1, PASSWORD_DEFAULT)]);   // Google: mật khẩu ngẫu nhiên, không dùng mặc định = MSSV
            db()->commit();
            if ($pending && ($n = oauth_attach_pending('student', $f['code']))) $msg .= ' Lần sau bấm “Đăng nhập với ' . $n . '”.';
            session_regenerate_id(true);
            $_SESSION['student'] = ['code' => $f['code'], 'full_name' => $in['full_name'] ?? $f['name'], 'must_change' => false];
            flash($msg);
            redirect('home.php');
        }
    }
}
student_head('Đăng ký');
?>
<div class="login">
  <div class="mascot">🦉</div>
  <h1>Tạo tài khoản học sinh</h1>
  <p class="muted">Đã có tên trong danh sách lớp? Chỉ cần MSSV. Lớp mới? Nhập thêm <b>mã tham gia lớp</b> giáo viên cho.</p>
  <?php if ($err): ?><div class="flash err"><?= h($err) ?></div><?php endif; ?>
  <?= $pending ? oauth_pending_banner($pending, '../') : oauth_buttons('student', 'register', '../', 'Đăng ký với', [], true) . '<div class="or"><span>hoặc</span></div>' ?>
  <form method="post" class="stack" autocomplete="off"><?= csrf_field() ?>
    <label>MSSV / mã học sinh <input name="code" required inputmode="numeric" value="<?= h($f['code']) ?>" autofocus></label>
    <label>Họ và tên <input name="name" value="<?= h($f['name']) ?>" placeholder="Nguyễn Văn An"></label>
    <label>Mã tham gia lớp (nếu có) <input name="join" value="<?= h($f['join']) ?>" maxlength="6" style="text-transform:uppercase;letter-spacing:3px" placeholder="VD: K7M2QX"></label>
    <?php if (!$pending): ?>
    <label>Mật khẩu <input name="p1" type="password" required minlength="6" autocomplete="new-password"></label>
    <label>Nhập lại mật khẩu <input name="p2" type="password" required minlength="6" autocomplete="new-password"></label>
    <?php endif; ?>
    <button class="primary">Tạo tài khoản</button>
  </form>
  <p class="small center">Đã có tài khoản? <a href="index.php">Đăng nhập</a></p>
</div>
<?php student_foot();
