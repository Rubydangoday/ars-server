<?php
// Cloud: nhận dữ liệu từ server lớp học (POST JSON, header X-ARS-Sync-Token). Không dùng cookie đăng nhập.
const ARS_TOKEN_API = true;
require __DIR__ . '/../lib/sync.php';
require_once __DIR__ . '/../lib/notify.php';
require __DIR__ . '/../lib/attendance.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'error' => 'Chỉ nhận POST.'], 405);
$tok = sync_token_auth((string)($_SERVER['HTTP_X_ARS_SYNC_TOKEN'] ?? ''));
if (!$tok) { usleep(300000); json_out(['ok' => false, 'error' => 'Mã đồng bộ sai hoặc đã bị thu hồi.'], 403); }
$raw = file_get_contents('php://input', false, null, 0, 32 * 1024 * 1024);
$p = json_decode((string)$raw, true);
if (!is_array($p)) json_out(['ok' => false, 'error' => 'Gói dữ liệu không phải JSON.'], 400);
q('UPDATE sync_tokens SET last_used_at=NOW() WHERE id=?', [$tok['id']]);
if (!empty($p['ping'])) json_out(['ok' => true, 'teacher' => qv('SELECT full_name FROM teachers WHERE id=?', [$tok['teacher_id']]), 'version' => SYNC_VERSION]);
try {
    [$count, $released, $received] = sync_receive((int)$tok['teacher_id'], $p);
} catch (Throwable $e) {
    sync_log((int)$tok['teacher_id'], 'receive', false, $tok['label'] . ': ' . $e->getMessage());
    json_out(['ok' => false, 'error' => $e instanceof PDOException ? 'Lỗi CSDL trên Cloud, xem sync_log.' : $e->getMessage()], 422);
}
// Tin trong trang phụ huynh (điểm danh + điểm) cho mọi buổi vừa nhận — phụ huynh ở nhà dùng Cloud.
foreach ($received as $sid) {
    try { parent_notify_session($sid); } catch (Throwable $e) { error_log('parent_notify_session: ' . $e->getMessage()); }
}
// Thông báo tự động cho phụ huynh khi buổi vừa được công bố trên Cloud (nếu quản trị bật).
$notified = 0;
if ($released && setting('notify_auto') === '1') {
    foreach ($released as $sid) notify_enqueue_session($sid);
    $notified = notify_send_pending(100)['sent'];
}
sync_log((int)$tok['teacher_id'], 'receive', true, $tok['label'] . ': ' . json_encode($count) . ($notified ? " · $notified thông báo" : ''));
json_out(['ok' => true, 'count' => $count, 'notified' => $notified]);
