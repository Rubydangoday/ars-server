<?php
// Đăng nhập bằng Google (OAuth 2.0 authorization code + PKCE). Dùng cho cả giáo viên, học sinh, phụ huynh.
// Không tự ghép tài khoản theo email: người dùng đăng nhập bằng mật khẩu một lần rồi bấm "Liên kết",
// hoặc phụ huynh đăng ký mới kèm mã mời. Nhận diện bằng mã người dùng (sub / id) của nhà cung cấp.
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

const OAUTH_PROVIDERS = ['google' => 'Google'];   // Facebook đã bỏ (v12 có, nay chỉ Google)
const OAUTH_TYPES = ['teacher', 'student', 'parent'];
const OAUTH_FLOW_TTL = 600;     // giây từ lúc bấm nút tới khi quay lại

/** Điểm cuối của nhà cung cấp. [oauth_endpoints] trong config.ini ghi đè được (chỉ dùng khi kiểm thử). */
function oauth_endpoint(string $provider, string $k): string {
    $def = [
        'google' => ['auth' => 'https://accounts.google.com/o/oauth2/v2/auth', 'token' => 'https://oauth2.googleapis.com/token',
                     'userinfo' => 'https://openidconnect.googleapis.com/v1/userinfo'],
    ];
    return cfg()['oauth_endpoints'][$provider . '_' . $k] ?? $def[$provider][$k];
}
function oauth_client(string $p): array {
    return ['id' => (string)setting('oauth_' . $p . '_id', ''), 'secret' => (string)setting('oauth_' . $p . '_secret', '')];
}
/** Nhà cung cấp đã cấu hình. Google chỉ chấp nhận https (trừ localhost) nên ẩn nút khi truy cập bằng http qua IP LAN. */
function oauth_enabled(): array {
    return array_filter(OAUTH_PROVIDERS, fn($n, $p) => oauth_status($p) === '', ARRAY_FILTER_USE_BOTH);
}
/** '' = dùng được; ngược lại là lý do hiển thị dưới nút bị mờ. */
function oauth_status(string $p): string {
    if (oauth_client($p)['id'] === '' || oauth_client($p)['secret'] === '') return t('Quản trị server chưa bật (Cài đặt → 🔐 Đăng nhập Google).');
    $host = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
    if (!is_https() && !in_array($host, ['localhost', '127.0.0.1'], true) && empty(cfg()['oauth_endpoints']))
        return t('Google chỉ cho đăng nhập qua link https') . (setting('public_url') ? ': ' . setting('public_url') . web_root_path() . '/' : ' ' . t('(bật link Internet: sudo ars public on).'));
    return '';
}
/** Logo chính thức dạng SVG (không tải từ Internet). */
function oauth_logo(string $p): string {
    return '<svg class="oauth-logo" viewBox="0 0 48 48" aria-hidden="true"><path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 7.9 3l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.4-.4-3.5z"/><path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 7.9 3l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/><path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-8l-6.5 5C9.5 39.6 16.2 44 24 44z"/><path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.3-.1-2.4-.4-3.5z"/></svg>';
}
/** Đường dẫn gốc thư mục web (VD "/ars" khi Apache đặt alias), suy ra từ trang đang chạy. */
function web_root_path(): string {
    return preg_replace('#/(student/|parent/|api/)?[^/]*$#', '', $_SERVER['SCRIPT_NAME'] ?? '/');
}
/** Redirect URI phải trùng từng ký tự với địa chỉ đã khai báo trong Google Cloud Console. */
function oauth_redirect_uri(): string { return current_base() . web_root_path() . '/oauth.php'; }

function oauth_http(string $url, ?array $form = null, array $headers = []): array {
    if (!function_exists('curl_init')) throw new RuntimeException('Thiếu PHP cURL (sudo apt install php-curl).');
    $ch = curl_init($url);
    $opt = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 15, CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers)];
    if ($form !== null) $opt += [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($form)];
    curl_setopt_array($ch, $opt);
    $res = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    if ($res === false) throw new RuntimeException('Không kết nối được máy chủ đăng nhập: ' . $err);
    $j = json_decode((string)$res, true);
    if ($code !== 200 || !is_array($j)) throw new RuntimeException('Máy chủ đăng nhập từ chối (HTTP ' . $code . ').');
    return $j;
}

/** Bước 1: tạo state + PKCE, lưu vào phiên, trả về URL chuyển sang trang đăng nhập của nhà cung cấp. */
function oauth_start(string $provider, string $for, string $mode, array $extra = []): string {
    if (!isset(oauth_enabled()[$provider])) throw new RuntimeException('Đăng nhập bằng ' . (OAUTH_PROVIDERS[$provider] ?? $provider) . ' chưa được bật.');
    $state = bin2hex(random_bytes(24));
    $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    $_SESSION['oauth_flow'] = ['state' => $state, 'provider' => $provider, 'for' => $for, 'mode' => $mode, 'verifier' => $verifier, 'at' => time()] + $extra;
    $c = oauth_client($provider);
    $q = ['client_id' => $c['id'], 'redirect_uri' => oauth_redirect_uri(), 'response_type' => 'code', 'state' => $state];
    $q += ['scope' => 'openid email profile', 'prompt' => 'select_account',
        'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256'];
    return oauth_endpoint($provider, 'auth') . '?' . http_build_query($q);
}

/** Bước 2: kiểm tra state, đổi code lấy token, đọc hồ sơ. Trả về [flow, profile{subject,email,name}]. */
function oauth_finish(array $get): array {
    $flow = $_SESSION['oauth_flow'] ?? null;
    unset($_SESSION['oauth_flow']);                       // state dùng một lần
    if (!$flow || !is_string($get['state'] ?? null) || !hash_equals($flow['state'], $get['state'])) throw new RuntimeException('Phiên đăng nhập không hợp lệ hoặc đã dùng. Thử lại.');
    if (time() - $flow['at'] > OAUTH_FLOW_TTL) throw new RuntimeException('Quá thời gian đăng nhập. Thử lại.');
    if (!empty($get['error'])) throw new RuntimeException('Bạn đã huỷ hoặc nhà cung cấp từ chối đăng nhập.', 1);
    $code = (string)($get['code'] ?? '');
    if ($code === '' || strlen($code) > 2048) throw new RuntimeException('Thiếu mã đăng nhập.');
    $p = $flow['provider']; $c = oauth_client($p);
    if (!isset(OAUTH_PROVIDERS[$p])) throw new RuntimeException('Nhà cung cấp đăng nhập không còn được hỗ trợ.');
    $tok = oauth_http(oauth_endpoint($p, 'token'), ['code' => $code, 'client_id' => $c['id'], 'client_secret' => $c['secret'],
        'redirect_uri' => oauth_redirect_uri(), 'grant_type' => 'authorization_code', 'code_verifier' => $flow['verifier']]);
    $u = oauth_http(oauth_endpoint($p, 'userinfo'), null, ['Authorization: Bearer ' . ($tok['access_token'] ?? '')]);
    $prof = ['subject' => (string)($u['sub'] ?? ''), 'email' => !empty($u['email_verified']) ? ($u['email'] ?? null) : null, 'name' => $u['name'] ?? null];
    if (!preg_match('/^[A-Za-z0-9_-]{1,100}$/', $prof['subject'])) throw new RuntimeException('Không đọc được tài khoản từ nhà cung cấp.');
    $prof['email'] = $prof['email'] !== null ? mb_substr((string)$prof['email'], 0, 255) : null;
    $prof['name'] = $prof['name'] !== null ? mb_substr(trim((string)$prof['name']), 0, 100) : null;
    return [$flow, $prof];
}

function oauth_identity(string $provider, string $subject, string $type): ?array {
    return q1('SELECT * FROM oauth_identities WHERE provider=? AND subject=? AND user_type=?', [$provider, $subject, $type]);
}
function oauth_link(string $provider, array $prof, string $type, string $key): void {
    $o = oauth_identity($provider, $prof['subject'], $type);
    if ($o && $o['user_key'] !== $key) throw new RuntimeException(t('Tài khoản {p} này đã liên kết với một tài khoản ARS khác. Gỡ liên kết ở tài khoản kia trước.', ['p' => OAUTH_PROVIDERS[$provider]]));
    if (!$o && qv('SELECT 1 FROM oauth_identities WHERE user_type=? AND user_key=? AND provider=?', [$type, $key, $provider]))
        throw new RuntimeException(t('Tài khoản đã liên kết một {p} khác. Gỡ liên kết cũ trước.', ['p' => OAUTH_PROVIDERS[$provider]]));
    q('INSERT INTO oauth_identities (provider, subject, user_type, user_key, email, name) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE email=VALUES(email), name=VALUES(name)',
      [$provider, $prof['subject'], $type, $key, $prof['email'], $prof['name']]);
}
function oauth_links(string $type, string $key): array {
    return array_column(qall('SELECT * FROM oauth_identities WHERE user_type=? AND user_key=?', [$type, $key]), null, 'provider');
}

/** Một nút gửi POST (có CSRF) tới oauth.php. $root = đường dẫn tới thư mục web ("" hoặc "../"). */
function oauth_button(string $p, string $for, string $mode, string $root, string $label, array $hidden = []): string {
    $html = '<form method="post" action="' . h($root) . 'oauth.php" class="oauth-form">' . csrf_field()
          . '<input type="hidden" name="action" value="start"><input type="hidden" name="provider" value="' . $p . '"><input type="hidden" name="for" value="' . $for . '"><input type="hidden" name="mode" value="' . $mode . '">';
    foreach ($hidden as $k => $v) $html .= '<input type="hidden" name="' . h($k) . '" value="' . h($v) . '">';
    $why = oauth_status($p);
    return $html . '<button class="oauth-btn oauth-' . $p . '"' . ($why ? ' disabled title="' . h($why) . '"' : '') . '>' . oauth_logo($p) . '<span>' . h(t($label) . ' ' . OAUTH_PROVIDERS[$p]) . '</span></button></form>';
}
/** Nút "Đăng nhập với Google". $always: hiện cả nút chưa dùng được (mờ + lý do) để người dùng biết có tính năng. */
function oauth_buttons(string $for, string $mode, string $root = '', string $label = 'Đăng nhập với', array $hidden = [], bool $always = false): string {
    $html = ''; $why = [];
    foreach (OAUTH_PROVIDERS as $p => $name) {
        if (!$always && oauth_status($p) !== '') continue;
        $html .= oauth_button($p, $for, $mode, $root, $label, $hidden);
        if ($s = oauth_status($p)) $why[$s][] = $name;
    }
    if (!$html) return '';
    return '<div class="oauth">' . $html . ($why ? '<p class="oauth-note">ⓘ ' . implode('<br>ⓘ ', array_map(fn($s, $who) => h(implode(', ', $who) . ': ' . $s), array_keys($why), $why)) . '</p>' : '') . '</div>';
}
/** Khối "Đăng nhập bằng Google" trong trang tài khoản: liên kết / gỡ liên kết. */
function oauth_manage_html(string $for, string $key, string $root = ''): string {
    $links = oauth_links($for, $key); $on = oauth_enabled();
    if (!$on && !$links) return '';
    $html = '<div class="oauth-manage"><h3>' . te('Đăng nhập bằng Google') . '</h3>';
    foreach (OAUTH_PROVIDERS as $p => $name) {
        if (isset($links[$p])) $html .= '<form method="post" action="' . h($root) . 'oauth.php" class="row">' . csrf_field() . '<input type="hidden" name="action" value="unlink"><input type="hidden" name="provider" value="' . $p . '"><input type="hidden" name="for" value="' . $for . '">'
            . '<span>✅ ' . $name . ': ' . h($links[$p]['email'] ?? $links[$p]['name'] ?? t('đã liên kết')) . '</span><button class="small" onclick="return confirm(' . h(json_encode(t('Gỡ liên kết {p}?', ['p' => $name]), JSON_UNESCAPED_UNICODE)) . ')">' . te('Gỡ liên kết') . '</button></form>';
        elseif (isset($on[$p])) $html .= oauth_button($p, $for, 'link', $root, 'Liên kết');
    }
    return $html . '<p class="muted small">' . te('Sau khi liên kết, bấm nút Google ở màn hình đăng nhập để vào thẳng. Mật khẩu ARS vẫn dùng được.') . '</p></div>';
}

// ------------------------------------------------------------------ đăng ký nhanh: tài khoản Google "đang chờ"
// Bấm "Đăng nhập với Google" mà chưa có tài khoản ARS → lưu hồ sơ vào phiên (15 phút) và chuyển tới bước cuối của vai trò:
// giáo viên (mã mời nếu server yêu cầu), học sinh (mã lớp / mã học sinh), phụ huynh (mã mời của con). Không cần mật khẩu.
// Nếu người đó đã có tài khoản và đăng nhập bằng mật khẩu, ARS hỏi xác nhận trước khi liên kết (an toàn cho máy dùng chung).
const OAUTH_PENDING_TTL = 900;
function oauth_pending_set(string $for, string $provider, array $prof): void {
    $_SESSION['oauth_pending'] = ['for' => $for, 'provider' => $provider, 'at' => time()] + $prof;
}
function oauth_pending(string $for): ?array {
    $p = $_SESSION['oauth_pending'] ?? null;
    return $p && $p['for'] === $for && time() - $p['at'] <= OAUTH_PENDING_TTL ? $p : null;
}
function oauth_pending_clear(): void { unset($_SESSION['oauth_pending']); }
/** Gắn tài khoản đang chờ vào tài khoản ARS vừa tạo / vừa xác nhận. Trả về tên nhà cung cấp hoặc NULL. */
function oauth_attach_pending(string $for, string $key): ?string {
    $p = oauth_pending($for);
    if (!$p) return null;
    oauth_link($p['provider'], $p, $for, $key);
    q('UPDATE oauth_identities SET last_login_at=NOW() WHERE provider=? AND subject=? AND user_type=?', [$p['provider'], $p['subject'], $for]);
    oauth_pending_clear();
    return OAUTH_PROVIDERS[$p['provider']];
}
/** Gợi ý tên đăng nhập từ email (phần trước @), chỉ chữ không dấu / số / chấm / gạch dưới, chưa ai dùng. */
function oauth_username_suggest(?string $email, string $table): string {
    $base = strtolower(preg_replace('/[^A-Za-z0-9_.]/', '', explode('@', (string)$email)[0])) ?: 'user';
    $base = substr(str_pad($base, 3, '0'), 0, 40);
    for ($i = 0, $u = $base; $i < 50 && qv("SELECT 1 FROM $table WHERE username=?", [$u]); $i++) $u = $base . random_int(10, 9999);
    return $u;
}
/** Dải thông báo "Đang tạo tài khoản bằng Google: email · Huỷ" trên trang đăng ký. */
function oauth_pending_banner(array $p, string $root): string {
    return '<div class="oauth-pending">' . oauth_logo($p['provider']) . '<div><b>' . te('Đang tạo tài khoản bằng {p}', ['p' => OAUTH_PROVIDERS[$p['provider']]]) . '</b><br><span class="small">'
         . h($p['email'] ?? $p['name'] ?? '') . ' · ' . te('không cần mật khẩu') . '</span></div><form method="post" action="' . h($root) . 'oauth.php">' . csrf_field()
         . '<input type="hidden" name="action" value="cancel"><input type="hidden" name="provider" value="' . h($p['provider']) . '"><input type="hidden" name="for" value="' . h($p['for']) . '"><button class="small">' . te('Huỷ') . '</button></form></div>';
}
