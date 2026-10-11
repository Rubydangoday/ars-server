<?php
// Đăng nhập / liên kết / đăng ký (phụ huynh) bằng Google.
//   POST action=start   → chuyển sang trang đăng nhập của nhà cung cấp
//   GET  ?code&state    → nhà cung cấp gọi lại (Redirect URI)
//   POST action=unlink  → gỡ liên kết khỏi tài khoản đang đăng nhập
require __DIR__ . '/lib/lms.php';
require __DIR__ . '/lib/oauth.php';

const OAUTH_HOME = ['teacher' => 'dashboard.php', 'student' => 'student/home.php', 'parent' => 'parent/home.php'];
const OAUTH_LOGIN = ['teacher' => 'index.php', 'student' => 'student/index.php', 'parent' => 'parent/index.php'];
const OAUTH_ACCOUNT = ['teacher' => 'settings.php#account', 'student' => 'student/account.php', 'parent' => 'parent/home.php?tab=account#account'];

/** Khoá tài khoản ARS của người đang đăng nhập theo cổng; NULL nếu chưa đăng nhập cổng đó. */
function current_key(string $for): ?string {
    if ($for === 'teacher') return me() ? (string)require_login()['id'] : null;
    if ($for === 'student') return stu()['code'] ?? null;
    return parent_me() ? (string)parent_me()['id'] : null;
}
function fail(string $msg, string $to): void { flash($msg, 'err'); redirect($to); }

/** Đăng nhập vào tài khoản đã liên kết, giống đăng nhập bằng mật khẩu của từng cổng. */
function login_as(string $for, string $key): void {
    if ($for === 'teacher') {
        $t = q1('SELECT * FROM teachers WHERE id=?', [(int)$key]);
        if (!$t) return;
        if (!(int)$t['active']) fail('Tài khoản này đang bị khoá. Liên hệ giáo viên quản trị server.', 'index.php');
        session_regenerate_id(true);
        $_SESSION['teacher'] = ['id' => (int)$t['id'], 'username' => $t['username'], 'full_name' => $t['full_name'], 'is_admin' => (int)$t['is_admin'] === 1];
        q('UPDATE teachers SET last_login=NOW() WHERE id=?', [$t['id']]);
        apply_user_lang($t['lang'] ?? null);
    } elseif ($for === 'student') {
        $u = q1('SELECT * FROM student_users WHERE student_code=?', [$key]);
        $nm = student_display_name($key);
        if (!$u && $nm === null) return;                       // tài khoản đã bị xoá → bỏ liên kết cũ
        if (!$u) q('INSERT INTO student_users (student_code, must_change) VALUES (?,1)', [$key]);
        q('UPDATE student_users SET last_login=NOW() WHERE student_code=?', [$key]);
        session_regenerate_id(true);
        $_SESSION['student'] = ['code' => $key, 'full_name' => $nm ?? $key, 'must_change' => !$u || $u['must_change']];
    } else {
        $p = q1('SELECT * FROM parents WHERE id=?', [(int)$key]);
        if (!$p) return;
        session_regenerate_id(true);
        $_SESSION['parent'] = ['id' => $p['id'], 'full_name' => $p['full_name']];
        apply_user_lang($p['lang'] ?? null);
    }
    redirect(OAUTH_HOME[$for]);
}

/** Dùng mã mời phụ huynh (một lần, 7 ngày). Trả về dòng mời đã khoá trong giao dịch đang mở. */
function take_invite(string $token): array {
    $i = q1('SELECT * FROM parent_invites WHERE token_hash=? AND used_at IS NULL AND expires_at>NOW() FOR UPDATE', [parent_invite_hash($token)]);
    if (!$i || !qv('SELECT 1 FROM students WHERE class_id=? AND student_code=?', [$i['class_id'], $i['student_code']])) throw new RuntimeException('Mã mời đã hết hạn hoặc đã dùng.');
    q('UPDATE parent_invites SET used_at=NOW() WHERE id=?', [$i['id']]);
    return $i;
}

// ------------------------------------------------------------------ POST: bắt đầu / gỡ liên kết
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $p = $_POST['provider'] ?? ''; $for = $_POST['for'] ?? ''; $act = $_POST['action'] ?? 'start';   // nút formaction trong form đăng ký phụ huynh không gửi action
    if (!isset(OAUTH_PROVIDERS[$p]) || !in_array($for, OAUTH_TYPES, true)) { http_response_code(400); exit('Yêu cầu không hợp lệ.'); }
    if ($act === 'unlink') {
        $key = current_key($for);
        if ($key === null) redirect(OAUTH_LOGIN[$for]);
        q('DELETE FROM oauth_identities WHERE user_type=? AND user_key=? AND provider=?', [$for, $key, $p]);
        flash('Đã gỡ liên kết ' . OAUTH_PROVIDERS[$p] . '.');
        redirect(OAUTH_ACCOUNT[$for]);
    }
    if ($act === 'cancel') { oauth_pending_clear(); redirect(OAUTH_LOGIN[$for]); }
    if ($act === 'confirm_link' || $act === 'skip_link') {
        // Sau khi đăng nhập bằng mật khẩu: người dùng xác nhận gắn tài khoản Google vừa bấm vào tài khoản này.
        $key = current_key($for);
        if ($key === null) redirect(OAUTH_LOGIN[$for]);
        if ($act === 'confirm_link') {
            try { if ($n = oauth_attach_pending($for, $key)) flash('Đã liên kết ' . $n . '. Lần sau bấm “Đăng nhập với ' . $n . '” để vào thẳng.'); }
            catch (Throwable $e) { oauth_pending_clear(); flash($e->getMessage(), 'err'); }
        } else oauth_pending_clear();
        redirect(OAUTH_HOME[$for]);
    }
    $mode = $_POST['mode'] ?? '';
    $back = $mode === 'link' ? OAUTH_ACCOUNT[$for] : OAUTH_LOGIN[$for];
    try {
        if (!in_array($mode, ['login', 'link', 'register'], true)) throw new RuntimeException('Yêu cầu không hợp lệ.');
        $extra = [];
        if ($mode === 'link') {
            if (($extra['key'] = current_key($for)) === null) redirect(OAUTH_LOGIN[$for]);
        } elseif ($mode === 'register' && $for === 'parent' && trim((string)($_POST['invite'] ?? '')) !== '') {
            // Phụ huynh đã nhập sẵn mã mời: tạo tài khoản ngay khi Google gọi lại
            $tok = trim((string)$_POST['invite']);
            parent_invite_hash($tok);                                 // kiểm tra dạng mã (8 ký tự) + chống dò
            $extra['invite'] = $tok;
        }
        redirect(oauth_start($p, $for, $mode, $extra));
    } catch (Throwable $e) { fail($e->getMessage(), $back); }
}

// ------------------------------------------------------------------ GET: nhà cung cấp gọi lại
$for = $_SESSION['oauth_flow']['for'] ?? 'teacher';
$mode = $_SESSION['oauth_flow']['mode'] ?? 'login';
try {
    [$flow, $prof] = oauth_finish($_GET);
} catch (Throwable $e) { fail($e->getMessage(), $mode === 'link' ? OAUTH_ACCOUNT[$for] : OAUTH_LOGIN[$for]); }
$p = $flow['provider']; $name = OAUTH_PROVIDERS[$p];

if ($flow['mode'] === 'link') {
    // Người đang đăng nhập phải đúng người đã bấm "Liên kết" (phiên không bị đổi giữa chừng).
    if (current_key($for) !== $flow['key']) fail('Phiên đăng nhập đã thay đổi. Đăng nhập lại rồi liên kết.', OAUTH_LOGIN[$for]);
    try { oauth_link($p, $prof, $for, $flow['key']); flash('Đã liên kết ' . $name . '. Lần sau bấm “Đăng nhập với ' . $name . '”.'); }
    catch (Throwable $e) { flash($e->getMessage(), 'err'); }
    redirect(OAUTH_ACCOUNT[$for]);
}

$o = oauth_identity($p, $prof['subject'], $for);
if ($flow['mode'] === 'register' && !empty($flow['invite'])) {
    // Phụ huynh đăng ký bằng Google + mã mời. Đã có tài khoản liên kết → chỉ thêm con/lớp.
    try {
        db()->beginTransaction();
        $i = take_invite($flow['invite']);
        if ($o) $pid = (int)$o['user_key'];
        else {
            $base = ($p === 'google' ? 'gg_' : 'fb_') . bin2hex(random_bytes(6));   // tên đăng nhập nội bộ, không lộ mã nhà cung cấp
            q('INSERT INTO parents (username, full_name, password_hash) VALUES (?,?,?)',
              [$base, $prof['name'] ?: 'Phụ huynh', password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT)]);   // mật khẩu ngẫu nhiên: chỉ đăng nhập qua nhà cung cấp
            $pid = (int)db()->lastInsertId();
            oauth_link($p, $prof, 'parent', (string)$pid);
        }
        q('INSERT IGNORE INTO parent_links (parent_id, student_code, class_id) VALUES (?,?,?)', [$pid, $i['student_code'], $i['class_id']]);
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        fail($e instanceof PDOException ? 'Chưa tạo được tài khoản. Thử lại.' : $e->getMessage(), 'parent/index.php');
    }
    q('UPDATE oauth_identities SET last_login_at=NOW() WHERE provider=? AND subject=? AND user_type="parent"', [$p, $prof['subject']]);
    login_as('parent', (string)$pid);
}

if ($o) {
    q('UPDATE oauth_identities SET last_login_at=NOW(), email=COALESCE(?, email) WHERE provider=? AND subject=? AND user_type=?', [$prof['email'], $p, $prof['subject'], $for]);
    login_as($for, $o['user_key']);
    q('DELETE FROM oauth_identities WHERE provider=? AND subject=? AND user_type=?', [$p, $prof['subject'], $for]);   // tài khoản ARS đã bị xoá
}
// Chưa có tài khoản ARS: giữ hồ sơ Google trong phiên và chuyển tới bước cuối của vai trò (không cần mật khẩu).
oauth_pending_set($for, $p, $prof);
$who = $prof['email'] ?: ($prof['name'] ?: $name);
if ($for === 'teacher' && setting('teacher_signup', 'invite') === 'closed') {
    flash("Server đang đóng đăng ký giáo viên mới. Nếu bạn đã có tài khoản, đăng nhập bằng mật khẩu một lần — ARS sẽ hỏi để liên kết $who.", 'warn');
    redirect('index.php');
}
if ($for === 'teacher' && setting('teacher_signup', 'invite') === 'open' && (int)qv('SELECT COUNT(*) FROM teachers') > 0) {
    // Server cho đăng ký tự do: tạo tài khoản giáo viên ngay từ hồ sơ Google (tên đăng nhập gợi ý từ email, mật khẩu ngẫu nhiên).
    q('INSERT INTO teachers (username, full_name, password_hash) VALUES (?,?,?)',
      [oauth_username_suggest($prof['email'] ?? $prof['name'], 'teachers'), $prof['name'] ?: 'Giáo viên', password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT)]);
    $tid = (string)db()->lastInsertId();
    oauth_attach_pending('teacher', $tid);
    flash("Đã tạo tài khoản giáo viên bằng $name. Chào mừng bạn!");
    login_as('teacher', $tid);
}
flash(['teacher' => "Bước cuối để tạo tài khoản giáo viên bằng $name ($who). Đã có tài khoản? Đăng nhập bằng mật khẩu một lần để liên kết.",
       'student' => "Bước cuối: nhập mã học sinh (và mã lớp nếu có) để tạo tài khoản bằng $name ($who).",
       'parent' => "Bước cuối: nhập mã mời thầy cô gửi để tạo tài khoản bằng $name ($who)."][$for]);
redirect(['teacher' => 'register.php', 'student' => 'student/register.php', 'parent' => 'parent/index.php?tab=register'][$for]);
