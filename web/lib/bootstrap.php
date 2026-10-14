<?php
// Khởi tạo chung: cấu hình, CSDL, phiên đăng nhập, CSRF, giao diện.
declare(strict_types=1);
mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Ho_Chi_Minh');

const LETTERS = 'ABCDEFGHIJ';
const REJECT_REASONS = [
    'no_session' => 'Lớp chưa bắt đầu buổi', 'unknown_seat' => 'ID không có trong lớp', 'seat_taken' => 'ID đã có máy khác đăng nhập',
    'mac_bound' => 'Máy này đã đăng nhập ID khác', 'not_logged_in' => 'Máy chưa điểm danh', 'id_mismatch' => 'ID trong gói khác ID đã đăng nhập',
    'q_not_open' => 'Câu chưa mở', 'q_closed' => 'Câu đã đóng', 'late' => 'Hết giờ', 'bad_option' => 'Chữ vượt số lựa chọn', 'seq_reused' => 'Remote dùng lại số thứ tự cũ (bấm lại)',
    'no_change' => 'Không cho đổi đáp án', 'malformed' => 'Gói hỏng',
    'mapping_mismatch' => 'Remote không đúng học sinh đã ghép',
    'wrong_link' => 'Sai kiểu kết nối (lớp đang chọn kiểu khác)',
];
const CONN_MODES = ['ble' => 'Bluetooth'];        // phần cứng chỉ dùng nRF52840: Remote BLE → gateway USB

function cfg(): array {
    static $c = null;
    if ($c === null) {
        $path = getenv('ARS_CONFIG') ?: __DIR__ . '/../../config.ini';
        $c = @parse_ini_file($path, true);
        if (!$c) { http_response_code(500); exit('Không đọc được config.ini (' . htmlspecialchars($path) . ')'); }
    }
    return $c;
}

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $d = cfg()['db'];
        $pdo = new PDO("mysql:host={$d['host']};port=" . ($d['port'] ?? 3306) . ";dbname={$d['name']};charset=utf8mb4",
            $d['user'], $d['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
    }
    return $pdo;
}

function q(string $sql, array $args = []): PDOStatement {
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st;
}
function q1(string $sql, array $args = []): ?array { $r = q($sql, $args)->fetch(); return $r === false ? null : $r; }
function qall(string $sql, array $args = []): array { return q($sql, $args)->fetchAll(); }
function qv(string $sql, array $args = []) { $r = q($sql, $args)->fetchColumn(); return $r === false ? null : $r; }

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ---------------------------------------------------------------- phiên & quyền
/** Yêu cầu đi qua proxy/đường hầm (Tailscale Funnel, Cloudflare Tunnel, reverse proxy)? */
function via_proxy(): bool {
    foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED_HOST', 'HTTP_X_REAL_IP', 'HTTP_FORWARDED', 'HTTP_CF_CONNECTING_IP',
              'HTTP_TAILSCALE_USER_LOGIN', 'HTTP_TAILSCALE_FUNNEL_REQUEST'] as $h) if (!empty($_SERVER[$h])) return true;
    return false;
}
function is_https(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => is_https()]);
    session_start();
}

function me(): ?array { return $_SESSION['teacher'] ?? null; }

/** Đăng nhập + kiểm tra tài khoản còn hoạt động (quản trị có thể khoá bất cứ lúc nào). */
function require_login(): array {
    if (!me()) { header('Location: index.php'); exit; }
    static $checked = false;
    if (!$checked) {
        $checked = true;
        $row = q1('SELECT active, is_admin, full_name, school_id, school_role FROM teachers WHERE id=?', [me()['id']]);
        if (!$row || !(int)$row['active']) {
            $_SESSION = []; session_destroy();
            header('Location: index.php?locked=1'); exit;
        }
        $_SESSION['teacher']['is_admin'] = (int)$row['is_admin'] === 1;
        $_SESSION['teacher']['full_name'] = $row['full_name'];
        $_SESSION['teacher']['school_admin'] = $row['school_role'] === 'school_admin' && $row['school_id'] !== null;
    }
    return me();
}
function is_admin(): bool { return !empty(me()['is_admin']); }

/** Giáo viên đã nhập mã mời giáo viên (hoặc được quản trị viên duyệt). Đăng ký ở chế độ "cần mã mời" mà chưa nhập mã:
 *  vào web ngay nhưng chưa xác nhận — xem account_limited(). */
function teacher_verified(): bool {
    static $v = [];
    $id = (int)(me()['id'] ?? 0);
    if (!$id) return true;
    return $v[$id] ??= (int)qv('SELECT verified FROM teachers WHERE id=?', [$id]) === 1;
}
/** Tài khoản bị giới hạn tài nguyên server (AI trả phí → chế độ Cơ bản offline, không tải tệp lên): giáo viên chưa xác nhận,
 *  hoặc học sinh chỉ học các lớp của giáo viên chưa xác nhận (người lạ tự tạo lớp + học sinh qua link Internet). */
function account_limited(): bool {
    if (me()) return !teacher_verified();
    $code = $_SESSION['student']['code'] ?? null;
    if ($code === null) return false;
    static $st = [];
    return $st[$code] ??= !qv('SELECT 1 FROM students s JOIN classes c ON c.id=s.class_id JOIN teachers t ON t.id=c.teacher_id '
                            . 'WHERE s.student_code=? AND t.verified=1 LIMIT 1', [$code])
                        && (bool)qv('SELECT 1 FROM students WHERE student_code=? LIMIT 1', [$code]);
}
/** So mã mời giáo viên (không phân biệt hoa thường, bỏ dấu gạch / khoảng trắng). */
function teacher_invite_ok(string $code): bool {
    $norm = fn(string $c) => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $c));
    $inv = $norm((string)setting('teacher_invite_code', ''));
    return $inv !== '' && hash_equals($inv, $norm($code));
}
/** Admin trường (Ban giám hiệu / chủ trung tâm): quản lý giáo viên và xem báo cáo các lớp trong trường mình. */
function is_school_admin(): bool { return !empty(me()['school_admin']); }
function require_admin(): array {
    $t = require_login();
    if (!is_admin()) { http_response_code(403); page_head('Không có quyền'); echo '<div class="card"><h1>Chỉ quản trị viên</h1><p>Trang này dành cho giáo viên quản trị server.</p></div>'; page_foot(); exit; }
    return $t;
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'; }
function check_csrf(): void {
    // ARS_TOKEN_API: endpoint xác thực bằng mã trong header, không dùng cookie phiên → không cần CSRF
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !defined('ARS_TOKEN_API') && !hash_equals(csrf_token(), $_POST['csrf'] ?? '')) {
        http_response_code(400); exit('Phiên làm việc hết hạn, tải lại trang rồi thử lại.');
    }
}
check_csrf();

require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/ui.php';
// ?lang=vi|en trên bất kỳ trang nào: lưu lựa chọn (cookie + tài khoản đang đăng nhập) rồi tải lại trang không kèm tham số.
if (isset($_GET['lang']) && isset(LANGS[$_GET['lang']]) && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    set_lang($_GET['lang']);
    try {
        if (!empty($_SESSION['teacher']['id'])) q('UPDATE teachers SET lang=? WHERE id=?', [$_GET['lang'], $_SESSION['teacher']['id']]);
        if (!empty($_SESSION['parent']['id'])) q('UPDATE parents SET lang=? WHERE id=?', [$_GET['lang'], $_SESSION['parent']['id']]);
    } catch (Throwable $e) {}                    // chưa chạy migration 009: vẫn lưu theo cookie
    $qs = $_GET; unset($qs['lang']);
    header('Location: ' . strtok($_SERVER['REQUEST_URI'] ?? '', '?') . ($qs ? '?' . http_build_query($qs) : ''));
    exit;
}
// Ngôn ngữ khác tiếng Việt: dịch toàn bộ HTML trước khi gửi (lib/i18n.php → i18n_html).
if (PHP_SAPI !== 'cli' && lang() !== 'vi') ob_start('i18n_html');
/** Trước khi gửi file lớn (readfile): tắt bộ đệm dịch để không giữ cả file trong RAM. */
function output_raw(): void { while (ob_get_level()) ob_end_clean(); }
/** Sau khi đăng nhập: dùng ngôn ngữ đã lưu theo tài khoản (nếu có). */
function apply_user_lang(?string $l): void { if ($l) set_lang($l); }

function flash(string $msg, string $kind = 'ok'): void { $_SESSION['flash'][] = [$kind, $msg]; }
/** Hiệu ứng chạy một lần ở trang kế tiếp (assets/fx.js): celebrate | correct | wrong | success. */
function fx_once(string $name): void { $_SESSION['fx'] = $name; }
/** Sau khi đăng nhập bằng mật khẩu: hỏi có liên kết tài khoản Google vừa bấm (lib/oauth.php lưu trong phiên) không.
 *  Không tự liên kết: trên máy dùng chung, Google của người trước không được gắn nhầm vào tài khoản người sau. */
function oauth_pending_prompt(string $for, string $root): string {
    $p = $_SESSION['oauth_pending'] ?? null;
    if (!$p || $p['for'] !== $for || time() - $p['at'] > 900) return '';
    $name = ['google' => 'Google'][$p['provider']] ?? '';
    $btn = fn($act, $label, $cls) => '<form method="post" action="' . h($root) . 'oauth.php" style="display:inline">' . csrf_field() . '<input type="hidden" name="action" value="' . $act . '">'
        . '<input type="hidden" name="provider" value="' . h($p['provider']) . '"><input type="hidden" name="for" value="' . $for . '"><button class="' . $cls . '">' . $label . '</button></form>';
    return '<div class="flash warn oauth-confirm"><b>' . te('Liên kết {p} {e} với tài khoản này?', ['p' => $name, 'e' => $p['email'] ?? $p['name'] ?? '']) . '</b> '
         . te('Lần sau bấm “Đăng nhập với {p}” là vào thẳng. Chỉ đồng ý nếu đó là tài khoản {p} của chính bạn.', ['p' => $name]) . ' '
         . $btn('confirm_link', te('Liên kết'), 'primary small') . ' ' . $btn('skip_link', te('Không'), 'small') . '</div>';
}
function fx_attr(): string { $f = $_SESSION['fx'] ?? ''; unset($_SESSION['fx']); return $f ? ' data-fx="' . h($f) . '"' : ''; }
function redirect(string $url): void { header('Location: ' . $url); exit; }

// Lớp / bộ đề / buổi phải thuộc giáo viên đang đăng nhập
function own_class(int $id): array {
    $c = q1('SELECT * FROM classes WHERE id=? AND teacher_id=?', [$id, me()['id']]);
    if (!$c) { http_response_code(404); exit('Không tìm thấy lớp.'); }
    return $c;
}
/** Bộ câu hỏi bị sửa (thêm / xoá / sửa / đổi thứ tự câu) → về trạng thái "Đang soạn" để giáo viên xác nhận lại. */
function set_unfinalize(int $set_id): void {
    try { q('UPDATE question_sets SET finalized_at=NULL WHERE id=? AND finalized_at IS NOT NULL', [$set_id]); } catch (Throwable $e) {}   // chưa chạy migration 011
}
function own_set(int $id): array {
    $s = q1('SELECT * FROM question_sets WHERE id=? AND teacher_id=?', [$id, me()['id']]);
    if (!$s) { http_response_code(404); exit('Không tìm thấy bộ câu hỏi.'); }
    return $s;
}
function own_session(int $id): array {
    $s = q1('SELECT s.*, c.name AS class_name, c.room_id, c.conn_mode, qs.title AS set_title FROM sessions s '
          . 'JOIN classes c ON c.id=s.class_id JOIN question_sets qs ON qs.id=s.set_id '
          . 'WHERE s.id=? AND c.teacher_id=?', [$id, me()['id']]);
    if (!$s) { http_response_code(404); exit('Không tìm thấy buổi học.'); }
    return $s;
}

// Web chỉ ghi DB; tăng state_rev để ingest.py phát lại STATE cho gateway
function bump_state(int $session_id): void { q('UPDATE sessions SET state_rev=state_rev+1 WHERE id=?', [$session_id]); }

function options_of(array $q): array {
    $o = [];
    for ($i = 0; $i < (int)$q['n_options']; $i++) {
        $L = LETTERS[$i];
        $o[$L] = $q['opt_' . strtolower($L)] ?? '';
    }
    return $o;
}

// ---------------------------------------------------------------- cài đặt, tệp tải lên, JSON
function setting(string $k, ?string $default = null): ?string {
    static $all = null;
    if ($all === null) {
        try { $all = array_column(qall('SELECT k, v FROM settings'), 'v', 'k'); } catch (Throwable $e) { $all = []; }
    }
    return array_key_exists($k, $all) && $all[$k] !== null && $all[$k] !== '' ? $all[$k] : $default;
}
function setting_set(string $k, ?string $v): void {
    q('INSERT INTO settings (k, v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)', [$k, $v]);
}

/** Thư mục lưu file bài giảng (ngoài thư mục web; installer tạo /var/lib/ars/uploads). */
function uploads_dir(): string {
    $d = cfg()['storage']['uploads'] ?? (__DIR__ . '/../../data/uploads');
    if (!is_dir($d)) @mkdir($d, 0770, true);
    return rtrim($d, '/\\');
}

function json_out($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    if (is_array($data) && function_exists('lang') && lang() !== 'vi')
        foreach (['error', 'msg', 'message_text'] as $k) if (isset($data[$k]) && is_string($data[$k])) $data[$k] = i18n_plain($data[$k]);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
function require_login_json(): array {
    if (!me()) json_out(['error' => 'Phiên đăng nhập đã hết hạn. Vui lòng tải lại trang.'], 401);
    return require_login();
}

function human_size(int $b): string {
    foreach (['B', 'KB', 'MB', 'GB'] as $u) { if ($b < 1024 || $u === 'GB') return ($u === 'B' ? $b : number_format($b, 1, ',', '.')) . ' ' . $u; $b /= 1024; }
    return '';
}

const DIFFICULTY = ['de' => 'Dễ', 'tb' => 'Trung bình', 'kho' => 'Khó'];
const PURPOSE = ['test' => 'Kiểm tra', 'practice' => 'Ôn tập'];

/** Mỗi lớp có sẵn 10 buổi học (tạo khi cần). */
function ensure_lessons(int $class_id, int $n = 10): void {
    $have = array_map('intval', array_column(qall('SELECT no FROM lessons WHERE class_id=?', [$class_id]), 'no'));
    for ($i = 1; $i <= $n; $i++) if (!in_array($i, $have, true)) q('INSERT IGNORE INTO lessons (class_id, no) VALUES (?,?)', [$class_id, $i]);
}
function own_lesson(int $id): array {
    $l = q1('SELECT l.*, c.name AS class_name, c.id AS class_id FROM lessons l JOIN classes c ON c.id=l.class_id WHERE l.id=? AND c.teacher_id=?', [$id, me()['id']]);
    if (!$l) { http_response_code(404); exit('Không tìm thấy buổi học.'); }
    return $l;
}
/** Tạo mã khôi phục mật khẩu giáo viên mới (dạng ABCD-EFGH-JKLM), lưu bản băm, trả về mã để hiện một lần. */
function new_recovery_code(int $teacher_id): string {
    $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; $c = '';
    for ($i = 0; $i < 12; $i++) $c .= ($i && $i % 4 === 0 ? '-' : '') . $abc[random_int(0, strlen($abc) - 1)];
    q('UPDATE teachers SET recovery_hash=? WHERE id=?', [password_hash($c, PASSWORD_DEFAULT), $teacher_id]);
    return $c;
}
/** Người dùng ngồi ngay tại máy chủ (mở http://localhost). Yêu cầu từ Internet qua đường hầm
 *  cũng đến từ 127.0.0.1 nên phải loại các yêu cầu có header của proxy và Host không phải localhost. */
function is_local_request(): bool {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $loop = $ip === '::1' || str_starts_with($ip, '127.');
    $host = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
    return $loop && !via_proxy() && in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
}

// ---------------------------------------------------------------- đường dẫn cố định
/** IP LAN của máy chủ (địa chỉ đầu tiên của hostname -I). */
function lan_ip(): ?string {
    static $ip = false;
    if ($ip === false) {
        $ip = null;
        $out = function_exists('shell_exec') ? @shell_exec('hostname -I 2>/dev/null') : null;
        foreach (preg_split('/\s+/', trim((string)$out)) as $a) {
            if (filter_var($a, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && !str_starts_with($a, '127.') && !str_starts_with($a, '100.') && !str_starts_with($a, '172.17.')) { $ip = $a; break; }
        }
        if (!$ip && !empty($_SERVER['SERVER_ADDR']) && !str_starts_with($_SERVER['SERVER_ADDR'], '127.')) $ip = $_SERVER['SERVER_ADDR'];
    }
    return $ip;
}
/** Địa chỉ gốc của yêu cầu hiện tại, ví dụ https://ars-server.tail1234.ts.net */
function current_base(): string {
    $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? ($_SERVER['HTTP_HOST'] ?? 'localhost');
    return (is_https() ? 'https' : 'http') . '://' . explode(',', $host)[0];
}
/** Các địa chỉ gốc dùng được: công khai (Internet), tên cố định trong LAN, IP LAN. */
function access_bases(): array {
    $b = [];
    if ($p = setting('public_url')) $b['public'] = rtrim($p, '/');
    $b['lan_name'] = 'http://' . (setting('lan_name') ?: 'ars.local');
    if ($ip = lan_ip()) $b['lan_ip'] = 'http://' . $ip;
    return $b;
}
/** Địa chỉ gốc nên đưa cho học sinh / giáo viên khác: ưu tiên link công khai. */
function share_base(): string {
    $b = access_bases();
    return $b['public'] ?? $b['lan_ip'] ?? $b['lan_name'];
}
function link_gv(?string $base = null): string { return ($base ?? share_base()) . '/gv'; }
function link_hs(?string $base = null): string { return ($base ?? share_base()) . '/hs'; }
/** Ô hiển thị một đường dẫn + nút chép + mã QR (vẽ bằng assets/qr.js). */
function link_box(string $url, string $label = '', bool $qr = true): string {
    return '<div class="linkbox">' . ($label ? '<span class="lb-label">' . h($label) . '</span>' : '')
         . '<code class="lb-url">' . h($url) . '</code>'
         . '<button type="button" class="lb-copy" data-copy="' . h($url) . '">Chép</button>'
         . ($qr ? '<button type="button" class="lb-qr" data-qr="' . h($url) . '">QR</button>' : '') . '</div>';
}

/** Mã lớp (room) đã dùng cho lớp khác (của bất kỳ giáo viên nào)? Trả về tên lớp + giáo viên. */
function room_taken(int $room, int $except_class = 0): ?array {
    return q1('SELECT c.name, t.full_name AS teacher FROM classes c JOIN teachers t ON t.id=c.teacher_id WHERE c.room_id=? AND c.id<>? LIMIT 1', [$room, $except_class]);
}
function next_free_room(): int {
    $used = array_map('intval', array_column(qall('SELECT DISTINCT room_id FROM classes'), 'room_id'));
    for ($r = 1; $r <= 255; $r++) if (!in_array($r, $used, true)) return $r;
    return 255;
}
// ---------------------------------------------------------------- MQTT cho gateway nRF52840 nối qua trình duyệt (WebSocket /mqtt)
function mqtt_public(): array {
    $m = cfg()['mqtt_public'] ?? [];
    return ['user' => $m['user'] ?? 'clicker', 'password' => $m['password'] ?? '', 'lan_port' => (int)($m['lan_port'] ?? 1884),
            'ws_path' => $m['ws_path'] ?? '/mqtt'];
}
/** Mã tham gia lớp 6 ký tự (không có 0/O/1/I để đọc không nhầm); tạo khi chưa có hoặc khi $renew. */
function class_join_code(int $class_id, bool $renew = false): string {
    $c = (string)qv('SELECT join_code FROM classes WHERE id=?', [$class_id]);
    if ($c !== '' && !$renew) return $c;
    $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    for ($try = 0; $try < 20; $try++) {
        $c = ''; for ($i = 0; $i < 6; $i++) $c .= $abc[random_int(0, strlen($abc) - 1)];
        if (!q1('SELECT 1 FROM classes WHERE join_code=?', [$c])) break;
    }
    q('UPDATE classes SET join_code=? WHERE id=?', [$c, $class_id]);
    return $c;
}
function lesson_label(array $l): string { return t('Buổi {n}', ['n' => (int)$l['no']]) . ($l['title'] !== '' ? ' · ' . $l['title'] : ''); }

// ---------------------------------------------------------------- giao diện
// Thanh trên kiểu Socrative: 5 tab chính + menu "Thêm"; nhãn tiếng Việt là khoá dịch (lib/i18n.php).
const NAV = [
    'dashboard.php' => ['Tổng quan', ['dashboard.php']],
    'classes.php'   => ['Lớp học', ['classes.php', 'class.php', 'session.php', 'projector.php', 'gateway.php']],
    'compose.php'   => ['Câu hỏi', ['compose.php', 'sets.php', 'set.php', 'media.php']],
    'homework.php'  => ['Bài tập', ['homework.php', 'homework_grade.php']],
    'sessions.php'  => ['Kết quả', ['sessions.php', 'results.php']],
];
const NAV_MORE = [
    'lessons.php'   => ['📚', 'Buổi học', ['lessons.php', 'lesson.php']],
    'library.php'   => ['🗂', 'Kho đề', ['library.php', 'variants.php']],
    'analytics.php' => ['📈', 'Báo cáo', ['analytics.php']],
    'rewards.php'   => ['🏆', 'Thi đua', ['rewards.php']],
    'family.php'    => ['👪', 'Phụ huynh', ['family.php']],
    'devices.php'   => ['📡', 'Thiết bị', ['devices.php', 'mapping.php']],
    'sync.php'      => ['☁️', 'Cloud', ['sync.php', 'notify.php']],
];
function asset_base(): string {return str_contains($_SERVER['SCRIPT_NAME']??'', '/parent/')?'../assets/':'assets/';}
/** Gắn phiên bản (thời điểm sửa file) vào đường dẫn CSS/JS trong assets/ để trình duyệt luôn tải bản mới sau mỗi lần cập nhật. */
function av(string $file): string {
    $m = @filemtime(__DIR__ . '/../assets/' . $file);
    return $file . ($m ? '?v=' . $m : '');
}
function math_assets(): string {$a=asset_base();return '<link rel="stylesheet" href="'.$a.'vendor/katex/katex.min.css"><script defer src="'.$a.'vendor/katex/katex.min.js"></script><script defer src="'.$a.'vendor/katex/contrib/mhchem.min.js"></script><script defer src="'.$a.'vendor/katex/contrib/auto-render.min.js"></script><script defer src="'.$a.av('math.js').'"></script><script defer src="'.$a.av('fx.js').'"></script>';}
/** Biểu tượng tab trình duyệt (favicon) + biểu tượng khi thêm vào màn hình chính điện thoại. $a = đường dẫn tới assets/. */
function favicon_links(string $a): string {
    return '<link rel="icon" href="' . $a . 'favicon.svg" type="image/svg+xml"><link rel="icon" href="' . $a . 'favicon-32.png" sizes="32x32" type="image/png">'
         . '<link rel="apple-touch-icon" href="' . $a . 'apple-touch-icon.png"><meta name="theme-color" content="#1f6feb">';
}
/** Logo ARS: chữ A + sóng Remote (SVG, không tải ngoài). */
function ars_logo(): string {
    return '<svg class="ars-logo" viewBox="0 0 40 40" aria-hidden="true"><rect width="40" height="40" rx="11" fill="currentColor"/><path d="M11 29 18.5 10h3L29 29h-3.6l-1.7-4.6h-7.4L14.6 29zm6.5-7.6h5.2L20 15z" fill="#fff"/>'
         . '<path d="M30 9.5a7 7 0 0 1 3 4.5M30.6 5.6a11 11 0 0 1 5 7" fill="none" stroke="#ffb15c" stroke-width="2.2" stroke-linecap="round"/></svg>';
}
/** Viên trạng thái buổi học đang chạy: lớp · điểm danh · sóng gateway. Bấm để về bảng điều khiển. */
function live_pill(int $teacher_id): string {
    try {
        $r = q1('SELECT s.id, c.name, c.room_id, (SELECT COUNT(*) FROM session_logins l WHERE l.session_id=s.id) n_in, '
              . '(SELECT COUNT(*) FROM students st WHERE st.class_id=c.id) n_all, '
              . '(SELECT g.online AND g.updated_at > NOW() - INTERVAL 15 SECOND FROM gateways g WHERE g.room_id=c.room_id) gw '
              . 'FROM sessions s JOIN classes c ON c.id=s.class_id WHERE c.teacher_id=? AND s.status="running" ORDER BY s.id DESC LIMIT 1', [$teacher_id]);
    } catch (Throwable $e) { return ''; }
    if (!$r) return '';
    $ok = (int)$r['gw'] === 1;
    return '<a class="live-pill' . ($ok ? '' : ' weak') . '" href="session.php?id=' . (int)$r['id'] . '" title="' . te('Buổi học đang chạy — mở bảng điều khiển') . '">'
         . '<span class="sig"><i></i><i></i><i></i></span><b>' . h($r['name']) . '</b><span>' . (int)$r['n_in'] . '/' . (int)$r['n_all'] . '</span></a>';
}
function page_head(string $title, string $active = '', bool $bare = false): void {
    $t = me();
    $page = $active ?: basename($_SERVER['SCRIPT_NAME'] ?? '');
    $parent = str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/parent/');
    echo '<!doctype html><html lang="' . lang() . '"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<meta name="csrf" content="' . h(csrf_token()) . '">'
       . '<title>' . h($title) . ' — ARS</title>' . favicon_links(asset_base()) . '<link rel="stylesheet" href="'.asset_base().av('style.css').'">'.i18n_js().math_assets().gtranslate_script(asset_base()).'</head>'
       . '<body class="' . ($bare ? 'bare' : 'app') . ($parent ? ' parent-portal' : '') . '"' . fx_attr() . '>';
    if (!$bare && $t && !$parent) {
        echo '<header class="top"><a class="brand" href="dashboard.php">' . ars_logo() . '<span><span translate="no">ARS</span><small>' . h(setting('org_name', t('Lớp học tương tác'))) . '</small></span></a>'
           . '<nav class="tabs-main" aria-label="' . te('Điều hướng') . '">';
        foreach (NAV as $href => [$label, $pages]) echo '<a href="' . $href . '"' . (in_array($page, $pages, true) ? ' class="on" aria-current="page"' : '') . '>' . te($label) . '</a>';
        $more = NAV_MORE;
        if (!empty($t['school_admin'])) $more['school.php'] = ['🏫', 'Trường của tôi', ['school.php', 'cohort.php']];
        if (!empty($t['is_admin'])) { $more['teachers.php'] = ['🧑‍🏫', 'Giáo viên', ['teachers.php']]; $more['schools.php'] = ['🏛', 'Trường', ['schools.php']]; }
        $in_more = false; $items = '';
        foreach ($more as $href => [$ico, $label, $pages]) {
            $on = in_array($page, $pages, true); $in_more = $in_more || $on;
            $items .= '<a href="' . $href . '"' . ($on ? ' class="on"' : '') . '><i>' . $ico . '</i>' . te($label) . '</a>';
        }
        echo '</nav><details class="more' . ($in_more ? ' on' : '') . '"><summary>' . te('Thêm') . '</summary><div class="menu">' . $items . '</div></details>'
           . '<div class="top-right">' . live_pill((int)$t['id'])
           . '<a class="btn launch" href="classes.php">▶ ' . te('Bắt đầu') . '</a>' . lang_switch()
           . '<details class="user"><summary title="' . h($t['full_name']) . '"><span class="avatar">' . h(mb_strtoupper(mb_substr(trim(strrchr(' ' . $t['full_name'], ' ')), 0, 1))) . '</span></summary>'
           . '<div class="menu"><b>' . h($t['full_name']) . '</b><a href="settings.php"' . ($page === 'settings.php' ? ' class="on"' : '') . '>⚙ ' . te('Cài đặt') . '</a>'
           . '<a href="privacy.php">🔒 ' . te('Quyền riêng tư') . '</a><a href="logout.php">↩ ' . te('Đăng xuất') . '</a></div></details></div></header>';
    }
    if (!$bare && $parent && !empty($_SESSION['parent'])) {      // thanh trên cổng phụ huynh (xanh lá mạ)
        $pn = (string)$_SESSION['parent']['full_name']; $acc = ($_GET['tab'] ?? '') === 'account';
        echo '<header class="p-top"><a class="brand" href="home.php">' . ars_logo() . '<span><span translate="no">ARS</span><small>' . te('Cổng phụ huynh') . '</small></span></a>'
           . '<nav><a href="home.php"' . (!$acc ? ' class="on"' : '') . '>📊 ' . te('Tiến độ của con') . '</a><a href="home.php?tab=account"' . ($acc ? ' class="on"' : '') . '>⚙ ' . te('Tài khoản') . '</a></nav>'
           . '<div class="right">' . lang_switch() . '<details class="user"><summary title="' . h($pn) . '"><span class="avatar">' . h(mb_strtoupper(mb_substr(trim(strrchr(' ' . $pn, ' ')), 0, 1))) . '</span></summary>'
           . '<div class="menu"><b>' . h($pn) . '</b><a href="home.php?tab=account">⚙ ' . te('Tài khoản') . '</a><a href="../privacy.php">🔒 ' . te('Quyền riêng tư') . '</a><a href="index.php?logout=1">↩ ' . te('Đăng xuất') . '</a></div></details></div></header>';
    }
    echo '<main>';
    foreach ($_SESSION['flash'] ?? [] as [$k, $m]) echo '<div class="flash ' . h($k) . '">' . h(t($m)) . '</div>';
    unset($_SESSION['flash']);
    if ($parent) { if (!empty($_SESSION['parent'])) echo oauth_pending_prompt('parent', '../'); }
    elseif ($t) echo oauth_pending_prompt('teacher', '');
    if (!$bare && !$parent && $t && !empty($_SESSION['show_recovery'])) {   // vừa đăng ký xong vào thẳng: mã khôi phục hiện một lần
        $rc = $_SESSION['show_recovery']; unset($_SESSION['show_recovery']);
        echo '<div class="recovery"><b>' . te('Mã khôi phục mật khẩu của “') . h($rc['user']) . '”</b><code>' . h($rc['code']) . '</code>'
           . '<span class="small">' . te('Chép hoặc chụp lại mã này. Khi quên mật khẩu, dùng mã ở trang “Quên mật khẩu”. Mã chỉ hiện một lần.') . '</span></div>';
    }
    if (!$bare && !$parent && $t && !teacher_verified())        // đăng ký chưa có mã mời: nhắc nhập mã ở mọi trang
        echo '<form method="post" action="verify.php" class="flash warn verify-bar">' . csrf_field()
           . '<span>🔑 ' . te('Tài khoản chưa xác nhận: trợ lý AI chạy chế độ Cơ bản và chưa tải tệp lên được. Nhập mã mời giáo viên (xin ở quản trị viên server) để dùng đầy đủ.') . '</span>'
           . '<input name="invite" required autocomplete="off" placeholder="GV-XXXX-XXXX" style="text-transform:uppercase;width:11em">'
           . '<input type="hidden" name="back" value="' . h(basename((string)($_SERVER['SCRIPT_NAME'] ?? 'dashboard.php')) . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '')) . '">'
           . '<button class="primary small">' . te('Xác nhận') . '</button></form>';
}

function page_foot(string $script = ''): void {
    echo '</main>';
    echo '<script src="'.asset_base().'vendor/qrcode.js"></script><script src="'.asset_base().av('qr.js').'"></script>';
    if ($script) echo '<script src="assets/' . h(av($script)) . '"></script>';
    echo '</body></html>';
}

// Biểu đồ cột ngang bằng SVG (không cần thư viện ngoài, chạy được khi không có Internet)
function bar_svg(array $counts, ?string $correct = null, int $width = 520): string {
    $n = count($counts);
    if ($n === 0) return '';
    $max = max(1, max($counts));
    $total = max(1, array_sum($counts));
    $rowh = 30; $labw = 28; $barw = $width - $labw - 90;
    $svg = '<svg class="bars" viewBox="0 0 ' . $width . ' ' . ($n * $rowh) . '" role="img">';
    $i = 0;
    foreach ($counts as $L => $v) {
        $y = $i * $rowh;
        $w = (int)round($barw * $v / $max);
        $cls = ($correct !== null && $L === $correct) ? 'bar ok' : 'bar';
        $svg .= '<text x="0" y="' . ($y + 20) . '" class="lab">' . h($L) . '</text>'
              . '<rect x="' . $labw . '" y="' . ($y + 5) . '" width="' . max($w, 1) . '" height="' . ($rowh - 10) . '" rx="3" class="' . $cls . '"/>'
              . '<text x="' . ($labw + $w + 6) . '" y="' . ($y + 20) . '" class="val">' . $v . ' (' . round($v * 100 / $total) . '%)</text>';
        $i++;
    }
    return $svg . '</svg>';
}
