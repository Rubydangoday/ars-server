<?php
// Khung màn hình đăng nhập / đăng ký (giáo viên, phụ huynh): nửa trái giới thiệu + minh hoạ động, nửa phải là thẻ đăng nhập.
// Chỉ là giao diện; xử lý đăng nhập vẫn nằm ở từng trang.
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

const AUTH_ROLES = [
    'teacher' => ['🧑‍🏫', 'Giáo viên', 'index.php'],
    'student' => ['🎒', 'Học sinh', 'student/'],
    'parent'  => ['👪', 'Phụ huynh', 'parent/'],
];
const AUTH_HERO = [
    'teacher' => ['Lớp học tương tác <br>bằng Remote', 'Cả lớp cùng trả lời — không cần điện thoại cho học sinh.', [
        ['📡', 'Nhận đáp án Remote theo thời gian thực, chạy cả khi không có Internet'],
        ['📊', 'Báo cáo, ma trận lỗ hổng kiến thức tự động sau mỗi buổi'],
        ['✨', 'Trợ lý AI soạn câu hỏi và gợi ý chấm tự luận'],
        ['☁️', 'Đồng bộ lên Cloud để học sinh, phụ huynh xem ở nhà'],
    ]],
    'parent' => ['Đồng hành cùng con <br>mỗi ngày', 'Biết con học thế nào trên lớp — chỉ trong một phút.', [
        ['📈', 'Điểm bấm Remote, chuyên cần theo ngày / tuần'],
        ['📝', 'Bài tập về nhà và nhận xét của thầy cô'],
        ['📨', 'Nhận kết quả buổi học qua Zalo / SMS'],
        ['📚', 'Tải tài liệu bài giảng con đã học'],
    ]],
];

/** Mở khung. $root: đường dẫn tới thư mục web ("" ở trang giáo viên, "../" ở cổng phụ huynh). */
function auth_open(string $role, string $title, string $subtitle, string $root = ''): void {
    $flash = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);                 // hiện trong thẻ đăng nhập thay vì đầu trang
    $title = t($title); $subtitle = t($subtitle);   // tiêu đề / phụ đề là khoá dịch (phụ đề có thể chứa thẻ <b>)
    page_head($title, '', true);
    [$headline, $tagline, $points] = AUTH_HERO[$role];
    $headline = str_replace(' <br>', ' ', $headline);
    $headline = preg_replace('/^(.*?) (\S+ \S+)$/u', '$1 <br>$2', h(t($headline)));   // xuống dòng trước 2 từ cuối
    echo '<div class="auth auth-' . $role . '"><aside class="auth-hero"><a class="auth-brand" href="' . h($root) . 'portal.php"><span class="logo">A</span><span>ARS<small>'
       . h(setting('org_name', t('Lớp học tương tác'))) . '</small></span></a>'
       . '<h2>' . $headline . '</h2><p class="auth-tag">' . te($tagline) . '</p>' . auth_art($role)
       . '<ul class="auth-points">' . implode('', array_map(fn($x) => '<li><i>' . $x[0] . '</i>' . te($x[1]) . '</li>', $points)) . '</ul></aside>'
       . '<section class="auth-main"><div class="auth-lang">' . lang_switch() . '</div><nav class="auth-roles" aria-label="' . te('Chọn vai trò') . '">';
    foreach (AUTH_ROLES as $k => [$ico, $name, $href]) {
        echo '<a href="' . h($root . $href) . '"' . ($k === $role ? ' class="on" aria-current="page"' : '') . '><i>' . $ico . '</i>' . te($name) . '</a>';
    }
    echo '</nav><div class="auth-card"><h1>' . h($title) . '</h1><p class="muted">' . $subtitle . '</p>';
    foreach ($flash as [$k, $m]) echo '<div class="flash ' . h($k) . '">' . h(t($m)) . '</div>';
}
function auth_close(): void {
    $root = str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/parent/') ? '../' : '';
    echo '</div><p class="auth-foot muted small">© ' . date('Y') . ' ARS · ' . te('Dữ liệu lớp học lưu trên máy chủ của trường') . ' · <a href="' . $root . 'privacy.php">' . te('Chính sách quyền riêng tư') . '</a></p></section></div>';
    // Hiện/ẩn mật khẩu cho mọi ô password trong thẻ
    echo '<script>document.querySelectorAll(".auth-card input[type=password]").forEach(i=>{const b=document.createElement("button");b.type="button";b.className="pw-eye";b.setAttribute("aria-label",' . json_encode(t('Hiện mật khẩu'), JSON_UNESCAPED_UNICODE) . ');b.textContent="👁";'
       . 'b.onclick=()=>{const s=i.type==="password";i.type=s?"text":"password";b.textContent=s?"🙈":"👁";b.setAttribute("aria-label",s?' . json_encode(t('Ẩn mật khẩu'), JSON_UNESCAPED_UNICODE) . ':' . json_encode(t('Hiện mật khẩu'), JSON_UNESCAPED_UNICODE) . ');i.focus();};i.parentElement.classList.add("has-eye");i.after(b);});</script>';
    page_foot();
}
function auth_divider(string $text = 'hoặc đăng nhập bằng tài khoản'): string { return '<div class="auth-or"><span>' . te($text) . '</span></div>'; }

/** Minh hoạ động bằng SVG (không ảnh ngoài): giáo viên = Remote phát sóng → biểu đồ lớp; phụ huynh = điện thoại nhận tin + đường tiến bộ. */
function auth_art(string $role): string {
    if ($role === 'parent') return '<svg class="auth-art" viewBox="0 0 420 260" aria-hidden="true">
  <rect x="24" y="26" width="236" height="200" rx="18" class="art-panel"/>
  <text x="44" y="58" class="art-t">' . te('Tiến độ của con') . '</text>
  <path d="M44 196 H240" class="art-axis"/>
  <polyline class="art-line" pathLength="1" points="48,178 86,170 124,150 162,156 200,120 238,96"/>
  <g class="art-dots"><circle cx="124" cy="150" r="5"/><circle cx="200" cy="120" r="5"/><circle cx="238" cy="96" r="6"/></g>
  <text x="44" y="216" class="art-s">T2   T3   T4   T5   T6   T7</text>
  <g class="art-phone"><rect x="276" y="40" width="120" height="200" rx="22" class="art-phone-body"/><rect x="288" y="62" width="96" height="152" rx="10" class="art-phone-screen"/>
    <g class="art-msg"><rect x="294" y="82" width="84" height="54" rx="10" class="art-bubble"/><text x="302" y="102" class="art-bt">📨 ' . te('Hôm nay') . '</text><text x="302" y="122" class="art-bt b">' . te('Đúng 8/10 câu') . '</text></g>
    <g class="art-msg m2"><rect x="294" y="146" width="84" height="40" rx="10" class="art-bubble"/><text x="302" y="171" class="art-bt">🏆 ' . te('Hạng 3 lớp') . '</text></g></g>
</svg>';
    $bars = '';
    foreach ([['A', 210, .45], ['B', 262, 1], ['C', 314, .3], ['D', 366, .2]] as $i => [$L, $x, $hgt])
        $bars .= '<rect class="art-bar' . ($L === 'B' ? ' ok' : '') . '" style="--h:' . $hgt . ';animation-delay:' . (0.15 * $i) . 's" x="' . $x . '" y="70" width="34" height="110" rx="6"/><text x="' . ($x + 17) . '" y="200" class="art-l">' . $L . '</text>';
    return '<svg class="auth-art" viewBox="0 0 420 260" aria-hidden="true">
  <rect x="190" y="20" width="214" height="196" rx="18" class="art-panel"/>
  <text x="208" y="50" class="art-t">' . te('Câu 3 · 2 + 3 = ?') . '</text>' . $bars . '
  <g class="art-chip"><rect x="250" y="226" width="150" height="26" rx="13"/><text x="325" y="244" class="art-ct">' . te('✓ 28/30 đã trả lời') . '</text></g>
  <g class="art-waves"><path d="M118 92 q14 12 0 24"/><path d="M130 82 q24 22 0 44"/><path d="M142 72 q34 32 0 64"/></g>
  <g class="art-remote"><rect x="34" y="64" width="78" height="150" rx="20" class="art-r-body"/><rect x="46" y="78" width="54" height="30" rx="6" class="art-r-lcd"/><text x="73" y="100" class="art-r-l">B</text>
    <g class="art-keys"><circle cx="54" cy="126" r="7"/><circle cx="73" cy="126" r="7"/><circle cx="92" cy="126" r="7"/><circle cx="54" cy="146" r="7"/><circle cx="73" cy="146" r="7" class="hit"/><circle cx="92" cy="146" r="7"/><circle cx="54" cy="166" r="7"/><circle cx="73" cy="166" r="7"/><circle cx="92" cy="166" r="7"/></g>
    <rect x="52" y="182" width="42" height="18" rx="9" class="art-ok"/><text x="73" y="195" class="art-okt">OK</text></g>
</svg>';
}
