<?php
// Tình trạng các clicker server đã nghe thấy: pin, RSSI, lần cuối thấy, tỉ lệ gói trùng; trạng thái bộ thu (Bluetooth laptop / gateway USB).
require __DIR__ . '/lib/bootstrap.php';
$t = require_login();
if (($_POST['action'] ?? '') === 'label') {
    q('UPDATE devices SET label=? WHERE mac=? AND (?=1 OR last_room IN (SELECT room_id FROM classes WHERE teacher_id=?))', [mb_substr(trim($_POST['label'] ?? ''),0,20) ?: null, $_POST['mac'] ?? '',(int)is_admin(),$t['id']]);
    redirect('devices.php');
}
$low = (int)(cfg()['ingest']['low_batt_mv'] ?? 2600);
$devs = qall('SELECT d.*, TIMESTAMPDIFF(SECOND, d.last_seen, NOW()) AS age FROM devices d WHERE ?=1 OR d.last_room IN (SELECT room_id FROM classes WHERE teacher_id=?) ORDER BY d.last_room, d.last_seat, d.mac',[(int)is_admin(),$t['id']]);
$gws = qall('SELECT *, TIMESTAMPDIFF(SECOND, updated_at, NOW()) AS age FROM gateways WHERE ?=1 OR room_id IN (SELECT room_id FROM classes WHERE teacher_id=?) ORDER BY room_id',[(int)is_admin(),$t['id']]);
function ago(?int $s): string {
    if ($s === null) return '—';
    if ($s < 60) return "$s giây trước";
    if ($s < 3600) return intdiv($s, 60) . ' phút trước';
    if ($s < 86400) return intdiv($s, 3600) . ' giờ trước';
    return intdiv($s, 86400) . ' ngày trước';
}
page_head('Thiết bị', 'devices.php');
?>
<div class="page-head"><div><h1>📡 Thiết bị</h1><p class="muted">Bộ thu Bluetooth và Remote đang hoạt động: pin, sóng, số gói nhận được. Ghép Remote với học sinh để chống bấm hộ.</p></div><a class="btn primary" href="mapping.php">🔗 Ghép Remote với học sinh</a></div>
<section class="card">
  <h2>Bộ thu Bluetooth</h2>
  <?php if (!$gws): ?><p class="muted">Chưa nhận được trạng thái bộ thu nào. Kiểm tra dịch vụ ars-ble (Bluetooth của laptop) đang chạy: ars status, ars logs.</p><?php else: ?>
  <table class="grid compact"><thead><tr><th>Room</th><th>Trạng thái</th><th>Cổng</th><th>Gói/giây</th><th>Cập nhật</th></tr></thead><tbody>
  <?php foreach ($gws as $g): $on = $g['online'] && $g['age'] < 15; ?>
    <tr><td><?= (int)$g['room_id'] ?></td><td><?= $on ? '<span class="tag live">đang nhận</span>' : '<span class="tag off">mất kết nối</span>' ?></td>
      <td class="mono"><?= h($g['port']) ?></td><td><?= h($g['pkt_per_s']) ?></td><td><?= ago((int)$g['age']) ?></td></tr>
  <?php endforeach; ?></tbody></table><?php endif; ?>
</section>
<section class="card">
  <h2>Clicker (<?= count($devs) ?>)</h2>
  <table class="grid compact">
    <thead><tr><th>MAC</th><th>Số trên vỏ</th><th>Room</th><th>Ghế gần nhất</th><th>Pin</th><th>RSSI</th><th>Lần cuối thấy</th><th>Gói</th><th>Trùng</th></tr></thead>
    <tbody><?php foreach ($devs as $d): ?>
      <tr>
        <td class="mono"><?= h($d['mac']) ?></td>
        <td><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="label"><input type="hidden" name="mac" value="<?= h($d['mac']) ?>">
          <input name="label" value="<?= h($d['label']) ?>" class="w5" onchange="this.form.submit()"></form></td>
        <td><?= h($d['last_room']) ?></td><td><?= h($d['last_seat']) ?></td>
        <td class="<?= $d['last_batt_mv'] && $d['last_batt_mv'] < $low ? 'wrong-a' : '' ?>"><?= $d['last_batt_mv'] ? number_format($d['last_batt_mv'] / 1000, 2, ',', '') . ' V' : '—' ?></td>
        <td><?= $d['last_rssi'] !== null ? (int)$d['last_rssi'] . ' dBm' : '—' ?></td>
        <td><?= ago($d['age'] === null ? null : (int)$d['age']) ?></td>
        <td><?= (int)$d['pkt_total'] ?></td>
        <td><?= $d['pkt_total'] ? round($d['pkt_dup'] * 100 / $d['pkt_total']) . ' %' : '—' ?></td>
      </tr>
    <?php endforeach; ?></tbody>
  </table>
  <p class="muted small">Pin dưới <?= number_format($low / 1000, 2, ',', '') ?> V bị tô đỏ (đổi trong config.ini, mục [ingest] low_batt_mv). Tỉ lệ trùng khoảng 70–80 % là bình thường vì mỗi lần bấm OK clicker phát 5 lần.</p>
</section>
<?php page_foot();
