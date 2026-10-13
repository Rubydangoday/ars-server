<?php
// Phần "Đường dẫn" và "Kết nối clicker" của trang Cài đặt (include từ settings.php; có $admin).
$bases = access_bases();
$mp = mqtt_public();
$lan_host = preg_replace('#^https?://#', '', $bases['lan_name']);
$ip = lan_ip();
$srv_room = (int)(cfg()['gateway']['room'] ?? 0);
$srv_gw = $srv_room ? q1('SELECT online, pkt_per_s, TIMESTAMPDIFF(SECOND, updated_at, NOW()) age FROM gateways WHERE room_id=?', [$srv_room]) : null;
$labels = ['public' => ['🌐 Qua Internet (ở đâu cũng vào được, có HTTPS)', 'Link cố định, không đổi khi đổi mạng Wi-Fi. Bật bằng lệnh sudo ars public on trên máy chủ.'],
           'lan_name' => ['🏫 Trong mạng nội bộ — tên cố định', 'Máy tính, iPhone, Android 12+ cùng mạng với máy chủ. Máy nào không vào được thì dùng link IP bên dưới.'],
           'lan_ip' => ['🔢 Trong mạng nội bộ — theo IP', 'IP có thể đổi khi router cấp lại. Nên đặt “DHCP reservation” cho máy chủ trong router để IP cố định.']];
?>
<section class="card" id="links">
  <h2>🔗 Đường dẫn cố định</h2>
  <p class="muted small">Chỉ cần nhớ 2 đường dẫn: <b>/gv</b> cho giáo viên và <b>/hs</b> cho học sinh. Nhiều giáo viên và học sinh dùng cùng lúc; mỗi giáo viên đăng nhập tài khoản riêng.
    Bấm <b>QR</b> để chiếu mã cho học sinh quét.</p>
  <?php if (empty($bases['public'])): ?>
    <div class="flash warn small">Chưa có link Internet. Muốn học sinh vào từ nhà hoặc giáo viên ở phòng khác mạng: trên máy chủ chạy <code>sudo ars public on</code>
      (dùng Tailscale Funnel, miễn phí) — lệnh tự điền link vào đây. Xem hướng dẫn trong HUONG_DAN_CAI_DAT.md mục “Link cố định”.</div>
  <?php endif; ?>
  <?php foreach ($bases as $k => $b): ?>
    <h3 style="margin-top:14px"><?= h($labels[$k][0]) ?></h3>
    <p class="muted small" style="margin:0 0 4px"><?= h($labels[$k][1]) ?></p>
    <?= link_box(link_gv($b), 'Giáo viên') ?>
    <?= link_box(link_hs($b), 'Học sinh') ?>
  <?php endforeach; ?>
  <?php if ($admin): ?>
    <form method="post" class="row" style="margin-top:14px"><?= csrf_field() ?><input type="hidden" name="action" value="links">
      <label style="flex:2">Link công khai (Internet) <input name="public_url" value="<?= h(setting('public_url', '')) ?>" placeholder="https://ars-server.tail1234.ts.net"></label>
      <label style="flex:1">Tên trong mạng nội bộ <input name="lan_name" value="<?= h(setting('lan_name', 'ars.local')) ?>" placeholder="ars.local"></label>
      <button class="primary">Lưu</button>
    </form>
    <p class="muted small" style="margin:0">Tên <code>.local</code> do dịch vụ <code>ars-mdns</code> trên máy chủ phát; đổi tên ở đây thì chạy <code>sudo ars lan-name tên-mới.local</code>.</p>
  <?php endif; ?>
</section>

<section class="card" id="conn">
  <h2>🔵 Kết nối clicker (Bluetooth · nRF52840)</h2>
  <p class="muted small">Mỗi học sinh một Remote nRF52840; Remote phát Bluetooth, <b>gateway nRF52840</b> cắm USB thu cả lớp rồi chuyển về máy chủ. Room trên Remote phải trùng mã lớp (room) đang học.</p>
  <div class="gw-ways">
    <div class="mode"><b><span class="ico">🖥</span>Gateway cắm vào máy chủ</b>
      <span class="small">Dịch vụ <code>ars-bridge</code> đọc cổng USB (cấu hình <code>[gateway] room</code> = <?= $srv_room ?: '?' ?> trong config.ini) —
      <?= $srv_gw && (int)$srv_gw['online'] && (int)$srv_gw['age'] < 15 ? '<span class="tag live">đang nhận ' . h((string)(float)$srv_gw['pkt_per_s']) . ' gói/s</span>' : '<span class="tag off">chưa thấy gateway</span>' ?></span></div>
    <div class="mode"><b><span class="ico">💻</span>Gateway cắm vào máy giáo viên</b>
      <span class="small">Mở bảng điều khiển buổi học bằng Chrome/Edge, bấm <b>“Gateway USB máy này”</b> (cần link https hoặc localhost). Trình duyệt nối về máy chủ qua WebSocket
      <code><?= h(preg_replace('#^http#', 'ws', $bases['lan_name'])) . h($mp['ws_path']) ?></code><?= $mp['password'] === '' ? ' — <span style="color:var(--bad)">chưa có tài khoản MQTT, chạy lại install_ubuntu.sh</span>' : '' ?>.</span></div>
  </div>
  <p class="muted small" style="margin:10px 0 0">Chưa có gateway: dùng tạm Bluetooth của laptop bằng lệnh <code>sudo ars gateway ble</code> (quay lại gateway: <code>sudo ars gateway usb</code>).</p>
  <p class="muted small" style="margin:6px 0 0">Kiểm tra pin, sóng và số gói của từng Remote ở <a href="devices.php">Thêm → Thiết bị</a>; ghép Remote cố định với học sinh ở <a href="mapping.php">Ghép Remote</a>.</p>
</section>
