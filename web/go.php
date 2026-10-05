<?php
// Đường dẫn ngắn cố định (Apache AliasMatch trỏ về file này):
//   /        → trang chọn Giáo viên / Học sinh
//   /gv      → web giáo viên          /ars/
//   /hs      → cổng học sinh          /ars/student/
//   /hs/ABC123 → đăng ký vào lớp có mã tham gia ABC123
// Chuyển hướng bằng đường dẫn tương đối để giữ nguyên https:// khi đi qua đường hầm (Tailscale Funnel…).
$path = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/');
$qs = $_SERVER['QUERY_STRING'] ?? '';
$to = '/ars/portal.php';
if ($path === '/gv') $to = '/ars/';
elseif ($path === '/hs') $to = '/ars/student/';
elseif (preg_match('#^/hs/([A-Za-z0-9]{4,10})$#', $path, $m)) $to = '/ars/student/register.php?code=' . strtoupper($m[1]);
if ($qs !== '' && !str_contains($to, '?')) $to .= '?' . $qs;
header('Cache-Control: no-store');
header('Location: ' . $to, true, 302);
