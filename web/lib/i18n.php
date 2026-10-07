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
function i18n_js(?string $a = null): string {
    if (lang() !== 'en') return '';
    return '<script>window.ARS_I18N=' . json_encode(i18n_dict(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . ';</script>'
         . '<script src="' . ($a ?? asset_base()) . av('i18n.js') . '"></script>';      // dịch cả nội dung JS chèn vào trang
}
/** Đường dẫn trang hiện tại với ?lang=xx (giữ các tham số khác). */
function lang_url(string $l): string {
    $q = $_GET; $q['lang'] = $l;
    return strtok($_SERVER['REQUEST_URI'] ?? '', '?') . '?' . http_build_query($q);
}
/** Dịch máy Google cho mọi ngôn ngữ khác (quản trị tắt được: Cài đặt → Thông tin chung). */
function machine_translate_on(): bool { return setting('machine_translate', '1') === '1'; }
/** Script dịch máy: luôn nạp (nhỏ), chỉ gọi Google khi người dùng chọn. $a = đường dẫn tới assets/. */
function gtranslate_script(string $a): string {
    return machine_translate_on() ? '<script defer src="' . $a . av('gtranslate.js') . '" data-base="' . lang() . '"></script>' : '';
}
/** Nút chọn ngôn ngữ: VI / EN dịch tay + "Ngôn ngữ khác" (Google Dịch, hơn 200 ngôn ngữ). */
function lang_switch(string $cls = ''): string {
    $cur = LANGS[lang()];
    $mt = !empty($_COOKIE['googtrans']) && machine_translate_on();
    $o = '<details class="lang-switch ' . h($cls) . '"><summary title="Language / Ngôn ngữ"><span>' . ($mt ? '🌐' : $cur[0]) . '</span> <b data-gt-current>' . $cur[2] . '</b></summary><div class="menu">';
    foreach (LANGS as $k => [$flag, $name]) $o .= '<a href="' . h(lang_url($k)) . '"' . ($k === lang() && !$mt ? ' class="on"' : '') . ' hreflang="' . $k . '" data-gt-clear>' . $flag . ' ' . h($name) . '</a>';
    if (machine_translate_on()) {
        $o .= '<hr><a href="#" data-gt-open>🌐 ' . te('Ngôn ngữ khác (Google Dịch)…') . '</a>'
            . ($mt ? '<a href="#" data-gt-off>✕ ' . te('Tắt Google Dịch') . '</a>' : '')
            . '<div class="gt-box" data-gt-box hidden><div id="gt-el"></div><small data-gt-status></small>'
            . '<small class="muted">' . te('Dịch máy bởi Google, cần Internet. Nội dung trang (kể cả tên, điểm học sinh) được gửi tới Google để dịch.') . '</small></div>';
    }
    return $o . '</div></details>';
}

// ---------------------------------------------------------------- dịch toàn trang (tầng xuất)
// Các trang viết chữ tiếng Việt thẳng trong HTML. Khi lang ≠ vi, toàn bộ HTML được lọc trước khi gửi:
// mỗi đoạn chữ (giữa hai thẻ) và thuộc tính placeholder/title/aria-label/alt, nút submit, confirm('…') được tra từ điển.
// Tra theo thứ tự: nguyên văn → bỏ biểu tượng/dấu câu ở đầu-cuối → thay số bằng {n} (vd "Còn 5 lượt" ↔ 'Còn {n} lượt').
// Dữ liệu người dùng (tên, câu hỏi…) không có trong từ điển nên giữ nguyên; giá trị ô nhập (value) không bao giờ bị đổi.
const I18N_VI = '/[À-ỹĐđ]/u';

function i18n_lookup(string $vi, bool $split = true): ?string {
    $d = i18n_dict();
    if (isset($d[$vi])) return $d[$vi];
    if ($split && ($r = i18n_lookup($vi, false)) !== null) return $r;
    if ($split) {                                            // chuỗi ghép "A · B — C": dịch từng mảnh, mảnh lạ (tên lớp, tên bài) giữ nguyên
        $ps = preg_split('/(\s+[·—›|]\s+|:\s+)/u', $vi, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (count($ps) > 1) {
            $hit = false;
            foreach ($ps as $i => $x) if ($i % 2 === 0 && $x !== '' && ($r = i18n_lookup($x, false)) !== null) { $ps[$i] = $r; $hit = true; }
            return $hit ? implode('', $ps) : null;
        }
        return null;
    }
    // biểu tượng / dấu câu đầu-cuối: "✨ Tạo tài khoản mới" , "Mã mời:" , "Đang tải…"
    if (preg_match('/^([^\p{L}\p{N}]*)(.*?)([\s:：.…!?·,;)]*)$/us', $vi, $m) && $m[2] !== '' && ($m[1] !== '' || $m[3] !== '')) {
        if (isset($d[$m[2]])) return $m[1] . $d[$m[2]] . $m[3];
        $core = $m[2]; $pre = $m[1]; $post = $m[3];
    } else { $core = $vi; $pre = $post = ''; }
    // số → {n}
    if (preg_match_all('/\d+(?:[.,]\d+)*/u', $core, $nm) && $nm[0]) {
        $key = preg_replace('/\d+(?:[.,]\d+)*/u', '{n}', $core);
        if (isset($d[$key])) { $i = 0; return $pre . preg_replace_callback('/\{n\}/', function () use (&$i, $nm) { return $nm[0][$i++] ?? ''; }, $d[$key]) . $post; }
    }
    return null;
}
function i18n_text(string $raw, bool $html = true): string {
    if (!preg_match(I18N_VI, $raw)) return $raw;
    $txt = $html ? html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8') : $raw;
    $core = trim($txt);
    $tr = i18n_lookup(preg_replace('/\s+/u', ' ', $core));
    if ($tr === null) return $raw;
    $lead = substr($txt, 0, strlen($txt) - strlen(ltrim($txt))); $trail = substr($txt, strlen(rtrim($txt)));
    return $html ? h($lead) . h($tr) . h($trail) : $lead . $tr . $trail;
}
function i18n_html(string $html): string {
    if (lang() === 'vi' || !preg_match(I18N_VI, $html)) return $html;
    foreach (headers_list() as $hd) if (stripos($hd, 'content-type:') === 0 && stripos($hd, 'text/html') === false) return $html;
    // không đụng vào script / style / textarea / pre
    $parts = preg_split('~(<(script|style|textarea|pre)\b.*?</\2\s*>)~is', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    $out = '';
    for ($i = 0; $i < count($parts); $i++) {
        if ($i % 3 === 1) { $out .= $parts[$i]; continue; }
        if ($i % 3 === 2) continue;                          // tên thẻ (nhóm bắt thứ 2)
        $p = $parts[$i];
        $p = preg_replace_callback('/>([^<>]+)</u', fn($m) => '>' . i18n_text($m[1]) . '<', $p);
        $p = preg_replace_callback('/\b(placeholder|title|aria-label|alt|data-tip)="([^"]*)"/u', fn($m) => $m[1] . '="' . i18n_text($m[2]) . '"', $p);
        $p = preg_replace_callback('/(<(?:input|button)\b[^>]*\btype="(?:submit|button)"[^>]*\bvalue=")([^"]*)"/u', fn($m) => $m[1] . i18n_text($m[2]) . '"', $p);
        $p = preg_replace_callback('/confirm\((?:&#039;|\')(.*?)(?:&#039;|\')\)/u', fn($m) => str_replace($m[1], i18n_text($m[1]), $m[0]), $p);
        $out .= $p;
    }
    return $out;
}
/** Dịch chuỗi thuần (thông báo lỗi JSON…). */
function i18n_plain(string $s): string { return lang() === 'vi' ? $s : i18n_text($s, false); }
