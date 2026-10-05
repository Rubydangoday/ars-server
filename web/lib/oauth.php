<?php
// Đăng nhập bằng Google / Facebook (OAuth 2.0 authorization code). Dùng cho cả giáo viên, học sinh, phụ huynh.
// Không tự ghép tài khoản theo email: người dùng đăng nhập bằng mật khẩu một lần rồi bấm "Liên kết",
// hoặc phụ huynh đăng ký mới kèm mã mời. Nhận diện bằng mã người dùng (sub / id) của nhà cung cấp.
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

const OAUTH_PROVIDERS = ['google' => 'Google', 'facebook' => 'Facebook'];
const OAUTH_TYPES = ['teacher', 'student', 'parent'];
const OAUTH_FLOW_TTL = 600;     // giây từ lúc bấm nút tới khi quay lại

/** Điểm cuối của nhà cung cấp. [oauth_endpoints] trong config.ini ghi đè được (chỉ dùng khi kiểm thử). */
function oauth_endpoint(string $provider, string $k): string {
    $def = [
        'google' => ['auth' => 'https://accounts.google.com/o/oauth2/v2/auth', 'token' => 'https://oauth2.googleapis.com/token',
                     'userinfo' => 'https://openidconnect.googleapis.com/v1/userinfo'],
        'facebook' => ['auth' => 'https://www.facebook.com/v21.0/dialog/oauth', 'token' => 'https://graph.facebook.com/v21.0/oauth/access_token',
                       'userinfo' => 'https://graph.facebook.com/v21.0/me'],
    ];
    return cfg()['oauth_endpoints'][$provider . '_' . $k] ?? $def[$provider][$k];
}
function oauth_client(string $p): array {
    return ['id' => (string)setting('oauth_' . $p . '_id', ''), 'secret' => (string)setting('oauth_' . $p . '_secret', '')];
}
/** Nhà cung cấp đã cấu hình. Google/Facebook chỉ chấp nhận https (trừ localhost) nên ẩn nút khi truy cập bằng http qua IP LAN. */
function oauth_enabled(): array {
    $host = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
    if (!is_https() && !in_array($host, ['localhost', '127.0.0.1'], true) && empty(cfg()['oauth_endpoints'])) return [];
    return array_filter(OAUTH_PROVIDERS, fn($n, $p) => oauth_client($p)['id'] !== '' && oauth_client($p)['secret'] !== '', ARRAY_FILTER_USE_BOTH);
}
/** Đường dẫn gốc thư mục web (VD "/ars" khi Apache đặt alias), suy ra từ trang đang chạy. */
function web_root_path(): string {
    return preg_replace('#/(student/|parent/|api/)?[^/]*$#', '', $_SERVER['SCRIPT_NAME'] ?? '/');
}
/** Redirect URI phải trùng từng ký tự với địa chỉ đã khai báo trong Google Cloud Console / Facebook Developers. */
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
    if ($provider === 'google') $q += ['scope' => 'openid email profile', 'prompt' => 'select_account',
        'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256'];
    else $q += ['scope' => 'public_profile,email'];
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
    if ($p === 'google') {
        $tok = oauth_http(oauth_endpoint($p, 'token'), ['code' => $code, 'client_id' => $c['id'], 'client_secret' => $c['secret'],
            'redirect_uri' => oauth_redirect_uri(), 'grant_type' => 'authorization_code', 'code_verifier' => $flow['verifier']]);
        $u = oauth_http(oauth_endpoint($p, 'userinfo'), null, ['Authorization: Bearer ' . ($tok['access_token'] ?? '')]);
        $prof = ['subject' => (string)($u['sub'] ?? ''), 'email' => !empty($u['email_verified']) ? ($u['email'] ?? null) : null, 'name' => $u['name'] ?? null];
    } else {
        $tok = oauth_http(oauth_endpoint($p, 'token'), ['code' => $code, 'client_id' => $c['id'], 'client_secret' => $c['secret'], 'redirect_uri' => oauth_redirect_uri()]);
        $at = (string)($tok['access_token'] ?? '');
        $u = oauth_http(oauth_endpoint($p, 'userinfo') . '?' . http_build_query(['fields' => 'id,name,email', 'access_token' => $at,
            'appsecret_proof' => hash_hmac('sha256', $at, $c['secret'])]));
        $prof = ['subject' => (string)($u['id'] ?? ''), 'email' => $u['email'] ?? null, 'name' => $u['name'] ?? null];
    }
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
    if ($o && $o['user_key'] !== $key) throw new RuntimeException('Tài khoản ' . OAUTH_PROVIDERS[$provider] . ' này đã liên kết với một tài khoản ARS khác. Gỡ liên kết ở tài khoản kia trước.');
    if (!$o && qv('SELECT 1 FROM oauth_identities WHERE user_type=? AND user_key=? AND provider=?', [$type, $key, $provider]))
        throw new RuntimeException('Tài khoản đã liên kết một ' . OAUTH_PROVIDERS[$provider] . ' khác. Gỡ liên kết cũ trước.');
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
    return $html . '<button class="oauth-btn oauth-' . $p . '"><span class="oauth-ico">' . ($p === 'google' ? 'G' : 'f') . '</span>' . h($label . ' ' . OAUTH_PROVIDERS[$p]) . '</button></form>';
}
/** Các nút "Đăng nhập với Google / Facebook" (chỉ nhà cung cấp đã bật). */
function oauth_buttons(string $for, string $mode, string $root = '', string $label = 'Đăng nhập với', array $hidden = []): string {
    $html = '';
    foreach (oauth_enabled() as $p => $name) $html .= oauth_button($p, $for, $mode, $root, $label, $hidden);
    return $html ? '<div class="oauth">' . $html . '</div>' : '';
}
/** Khối "Đăng nhập bằng Google / Facebook" trong trang tài khoản: liên kết / gỡ liên kết. */
function oauth_manage_html(string $for, string $key, string $root = ''): string {
    $links = oauth_links($for, $key); $on = oauth_enabled();
    if (!$on && !$links) return '';
    $html = '<div class="oauth-manage"><h3>Đăng nhập bằng Google / Facebook</h3>';
    foreach (OAUTH_PROVIDERS as $p => $name) {
        if (isset($links[$p])) $html .= '<form method="post" action="' . h($root) . 'oauth.php" class="row">' . csrf_field() . '<input type="hidden" name="action" value="unlink"><input type="hidden" name="provider" value="' . $p . '"><input type="hidden" name="for" value="' . $for . '">'
            . '<span>✅ ' . $name . ': ' . h($links[$p]['email'] ?? $links[$p]['name'] ?? 'đã liên kết') . '</span><button class="small" onclick="return confirm(\'Gỡ liên kết ' . $name . '?\')">Gỡ liên kết</button></form>';
        elseif (isset($on[$p])) $html .= oauth_button($p, $for, 'link', $root, 'Liên kết');
    }
    return $html . '<p class="muted small">Sau khi liên kết, bấm nút Google / Facebook ở màn hình đăng nhập để vào thẳng. Mật khẩu ARS vẫn dùng được.</p></div>';
}
