<?php
// Đa ngôn ngữ giao diện (giáo viên, phụ huynh): Tiếng Việt là chuỗi gốc trong mã nguồn, tiếng Anh tra từ điển web/lang/en.php.
// Chuỗi chưa có bản dịch hiện nguyên tiếng Việt (không bao giờ trống chữ).
//   t('Lớp học')                         → "Classes" khi lang=en
//   t('Điểm danh {n}/{all}', ['n'=>3, 'all'=>30])
// Đổi ngôn ngữ: thêm ?lang=en | ?lang=vi vào bất kỳ trang nào (lưu cookie 1 năm + theo tài khoản nếu đã đăng nhập).
declare(strict_types=1);

const LANGS = ['vi' => ['🇻🇳', 'Tiếng Việt', 'VI'], 'en' => ['🇬🇧', 'English', 'EN']];

function lang(): string {
    static $l = null;
    if ($l === null) {
        $c = $_COOKIE['ars_lang'] ?? '';
        if (isset(LANGS[$c])) $l = $c;
        else {                                               // chưa chọn: theo trình duyệt (giáo viên nước ngoài mở lần đầu thấy tiếng Anh)
            $l = str_starts_with(strtolower(trim($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')), 'en') ? 'en' : 'vi';
        }
    }
    return $l;
}
function set_lang(string $l): void {
    if (!isset(LANGS[$l])) return;
    setcookie('ars_lang', $l, ['expires' => time() + 365 * 86400, 'path' => '/', 'samesite' => 'Lax', 'httponly' => false, 'secure' => is_https()]);
    $_COOKIE['ars_lang'] = $l;
}
function i18n_dict(): array {
    static $d = null;
    if ($d === null) $d = lang() === 'en' && is_file(__DIR__ . '/../lang/en.php') ? (require __DIR__ . '/../lang/en.php') : [];
    return $d;
}
function t(string $vi, array $vars = []): string {
    $s = i18n_dict()[$vi] ?? $vi;
    if ($vars) $s = strtr($s, array_combine(array_map(fn($k) => '{' . $k . '}', array_keys($vars)), array_map('strval', array_values($vars))));
    return $s;
}
/** Chuỗi đã escape HTML (dùng trong template). */
function te(string $vi, array $vars = []): string { return h(t($vi, $vars)); }
/** Thứ trong tuần + ngày theo ngôn ngữ. */
function t_date(int $ts): string {
    if (lang() === 'en') return date('l, j F Y', $ts);
    return ['Chủ nhật', 'Thứ hai', 'Thứ ba', 'Thứ tư', 'Thứ năm', 'Thứ sáu', 'Thứ bảy'][(int)date('w', $ts)] . ', ' . date('d/m/Y', $ts);
}
/** Từ điển cho JavaScript (chỉ khi tiếng Anh). */
function i18n_js(): string {
    return lang() === 'en' ? '<script>window.ARS_I18N=' . json_encode(i18n_dict(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . ';</script>' : '';
}
/** Đường dẫn trang hiện tại với ?lang=xx (giữ các tham số khác). */
function lang_url(string $l): string {
    $q = $_GET; $q['lang'] = $l;
    return strtok($_SERVER['REQUEST_URI'] ?? '', '?') . '?' . http_build_query($q);
}
/** Nút chọn ngôn ngữ (dropdown). */
function lang_switch(string $cls = ''): string {
    $cur = LANGS[lang()];
    $o = '<details class="lang-switch ' . h($cls) . '"><summary title="Language / Ngôn ngữ"><span>' . $cur[0] . '</span> ' . $cur[2] . '</summary><div class="menu">';
    foreach (LANGS as $k => [$flag, $name]) $o .= '<a href="' . h(lang_url($k)) . '"' . ($k === lang() ? ' class="on"' : '') . ' hreflang="' . $k . '">' . $flag . ' ' . h($name) . '</a>';
    return $o . '</div></details>';
}
