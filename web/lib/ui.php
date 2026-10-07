<?php
// Thành phần giao diện dùng chung (giáo viên / phụ huynh / học sinh): thẻ chỉ số, biểu đồ SVG, thanh tiến độ, trạng thái trống.
// Không thư viện ngoài — vẽ SVG phía máy chủ, chạy offline. CSS ở assets/style.css (mục "v18 ui-*").
declare(strict_types=1);

/** Thẻ chỉ số: biểu tượng màu + số lớn + nhãn + dòng phụ (tuỳ chọn). $tone: blue|green|orange|violet|pink|teal|red. */
function ui_kpi(string $icon, string $value, string $label, string $sub = '', string $tone = 'blue', string $extra = ''): string {
    return '<div class="ui-kpi tone-' . h($tone) . '"><span class="ui-ico">' . $icon . '</span><div class="ui-kpi-b"><b>' . $value . '</b><span>' . h($label) . '</span>'
         . ($sub !== '' ? '<small>' . $sub . '</small>' : '') . '</div>' . $extra . '</div>';
}
/** Vòng tròn phần trăm. */
function ui_ring(int $pct, string $tone = 'green'): string {
    $pct = max(0, min(100, $pct));
    return '<span class="ui-ring tone-' . h($tone) . '" style="--p:' . $pct . '"><i>' . $pct . '%</i></span>';
}
/** Thanh tiến độ ngang có nhãn. */
function ui_bar(string $label, float $pct, string $right = '', string $tone = ''): string {
    $pct = max(0, min(100, $pct));
    $tone = $tone ?: ($pct >= 80 ? 'green' : ($pct >= 50 ? 'orange' : 'red'));
    return '<div class="ui-bar"><div class="ui-bar-h"><span>' . h($label) . '</span><b>' . ($right !== '' ? $right : round($pct) . '%') . '</b></div>'
         . '<div class="ui-bar-t tone-' . $tone . '"><i style="width:' . round($pct, 1) . '%"></i></div></div>';
}
/** Trạng thái trống: biểu tượng + tiêu đề + mô tả + nút (tuỳ chọn). */
function ui_empty(string $icon, string $title, string $text = '', string $action = ''): string {
    return '<div class="ui-empty"><div class="ui-empty-ico">' . $icon . '</div><b>' . h($title) . '</b>' . ($text !== '' ? '<p>' . h($text) . '</p>' : '') . $action . '</div>';
}
/** Đầu thẻ: tiêu đề + mô tả + phần bên phải. */
function ui_card_head(string $title, string $sub = '', string $right = ''): string {
    return '<div class="ui-ch"><div><h3>' . $title . '</h3>' . ($sub !== '' ? '<p>' . h($sub) . '</p>' : '') . '</div>' . ($right !== '' ? '<div class="ui-ch-r">' . $right . '</div>' : '') . '</div>';
}
/** Ô điểm có màu theo mức (thang 10). */
function ui_score(?float $v, float $max = 10): string {
    if ($v === null) return '<span class="ui-score none">—</span>';
    $r = $max > 0 ? $v / $max : 0;
    return '<span class="ui-score ' . ($r >= .8 ? 'good' : ($r >= .5 ? 'mid' : 'low')) . '">' . h(rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.')) . '</span>';
}

/**
 * Biểu đồ đường + vùng tô (thang 0..$max), có lưới, nhãn trục, chấm có chú thích khi rê chuột.
 * $data: [nhãn trục x => giá trị|null] theo thứ tự thời gian.
 */
function ui_line_chart(array $data, float $max = 10, string $aria = '', string $unit = ''): string {
    $W = 640; $H = 230; $L = 36; $R = 14; $T = 14; $B = 34;
    $n = count($data);
    if (!$n) return '';
    $keys = array_keys($data); $vals = array_values($data);
    $x = fn(int $i) => $n > 1 ? $L + $i * ($W - $L - $R) / ($n - 1) : ($L + $W - $R) / 2;
    $y = fn(float $v) => $T + ($H - $T - $B) * (1 - $v / $max);
    $id = 'g' . substr(md5(json_encode($data) . $aria), 0, 6);
    $s = '<svg class="ui-chart" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="' . h($aria) . '" preserveAspectRatio="none">'
       . '<defs><linearGradient id="' . $id . '" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="currentColor" stop-opacity=".28"/><stop offset="1" stop-color="currentColor" stop-opacity="0"/></linearGradient></defs>';
    foreach ([0, .25, .5, .75, 1] as $f) {
        $yy = round($y($max * $f), 1);
        $s .= '<line class="grid" x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . $yy . '" y2="' . $yy . '"/><text class="ax" x="' . ($L - 8) . '" y="' . ($yy + 4) . '" text-anchor="end">' . h((string)round($max * $f, 1)) . '</text>';
    }
    $pts = [];
    foreach ($vals as $i => $v) if ($v !== null) $pts[] = [round($x($i), 1), round($y((float)$v), 1), $v, $keys[$i]];
    if ($pts) {
        $line = implode(' ', array_map(fn($p) => $p[0] . ',' . $p[1], $pts));
        $area = $pts[0][0] . ',' . ($H - $B) . ' ' . $line . ' ' . end($pts)[0] . ',' . ($H - $B);
        $s .= '<polygon class="area" points="' . $area . '" fill="url(#' . $id . ')"/>'
            . '<polyline class="line fx-line" pathLength="1" points="' . $line . '"/>';
        if (count($pts) === 1) $s .= '<text class="val" x="' . $pts[0][0] . '" y="' . ($pts[0][1] - 12) . '" text-anchor="middle">' . h((string)round((float)$pts[0][2], 1)) . '</text>';
        foreach ($pts as $j => $p) $s .= '<circle class="dot fx-dot" style="animation-delay:' . round(.2 + .9 * ($j + 1) / count($pts), 2) . 's" cx="' . $p[0] . '" cy="' . $p[1] . '" r="4.5"><title>'
            . h($p[3] . ': ' . round((float)$p[2], 1) . $unit) . '</title></circle>';
    }
    // nhãn trục x: tối đa ~7 nhãn
    $step = max(1, (int)ceil($n / 7));
    foreach ($keys as $i => $k) if ($i % $step === 0 || $i === $n - 1)
        $s .= '<text class="ax" x="' . round($x($i), 1) . '" y="' . ($H - 10) . '" text-anchor="' . ($i === 0 ? 'start' : ($i === $n - 1 ? 'end' : 'middle')) . '">' . h((string)$k) . '</text>';
    return $s . '</svg>';
}
/** Biểu đồ cột nhỏ (sparkline dạng cột) cho chuỗi giá trị 0..$max. */
function ui_spark(array $vals, float $max = 10): string {
    if (!$vals) return '';
    $n = count($vals); $w = 6; $gap = 3; $H = 28;
    $s = '<svg class="ui-spark" viewBox="0 0 ' . ($n * ($w + $gap)) . ' ' . $H . '" aria-hidden="true">';
    foreach (array_values($vals) as $i => $v) { $h = max(2, $H * min(1, (float)$v / $max)); $s .= '<rect x="' . ($i * ($w + $gap)) . '" y="' . ($H - $h) . '" width="' . $w . '" height="' . $h . '" rx="2"/>'; }
    return $s . '</svg>';
}

/** Chữ viết tắt từ tên (tối đa 2 ký tự): "Lớp kiểm thử" → "LK", "6A1 – Toán" → "6T". */
function initials(string $name): string {
    $w = array_values(array_filter(preg_split('/[\s\-–—·_]+/u', trim($name)), fn($x) => $x !== ''));
    if (!$w) return '?';
    $a = mb_substr($w[0], 0, 1) . (count($w) > 1 ? mb_substr(end($w), 0, 1) : mb_substr($w[0], 1, 1));
    return mb_strtoupper($a);
}
