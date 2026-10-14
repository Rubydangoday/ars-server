<?php
// Tự đồng bộ Cloud (chạy bởi ars-autosync.timer: 2 phút sau khi máy chủ bật, rồi mỗi 3 phút).
// Đẩy buổi mới của mọi giáo viên đã nhập địa chỉ Cloud + mã đồng bộ (trang Đồng bộ Cloud), trừ ai đã tắt "Tự động".
//   php deploy/ars-autosync.php          — như timer: bỏ qua khi không có gì mới / đang chờ thử lại sau lỗi mạng
//   php deploy/ars-autosync.php --now    — gửi ngay (lệnh "ars sync")
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require __DIR__ . '/../web/lib/sync.php';
$now = in_array('--now', $argv, true);
$lock = fopen(sys_get_temp_dir() . '/ars-autosync-' . getmyuid() . '.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit(0);                 // lần trước chưa xong (mạng chậm)
$res = sync_auto_run($now);
foreach ($res as $tid => $msg) echo "giáo viên #$tid: $msg\n";
if ($now && !$res) echo "Chưa có giáo viên nào bật đồng bộ Cloud (trang Đồng bộ Cloud: nhập địa chỉ Cloud + mã đồng bộ).\n";
exit($now && array_filter($res, fn($m) => str_starts_with($m, 'Lỗi')) ? 1 : 0);
