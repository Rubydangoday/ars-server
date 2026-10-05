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
  <h2>📶 Kết nối clicker: Bluetooth hoặc Wi-Fi</h2>
  <p class="muted small">Mỗi lớp chọn một kiểu ở trang lớp hoặc bảng điều khiển buổi học. Server chỉ nhận gói đúng kiểu lớp đang chọn.</p>
  <div class="modes" style="pointer-events:none">
    <div class="mode" style="padding-left:14px"><b><span class="ico">🔵</span>Bluetooth</b>
      <span class="small">Clicker nRF52840 → Bluetooth → <b>gateway USB</b>. Gateway cắm vào:<br>
      • <b>máy chủ</b> (dịch vụ ars-bridge, lớp room <?= $srv_room ?: '?' ?>) — <?= $srv_gw && (int)$srv_gw['online'] && (int)$srv_gw['age'] < 15 ? '<span class="tag live">đang nhận</span>' : '<span class="tag off">chưa thấy gateway</span>' ?><br>
      • hoặc <b>máy của giáo viên</b>: mở bảng điều khiển bằng Chrome/Edge, bấm “Kết nối gateway USB” (cần link https hoặc localhost).</span></div>
    <div class="mode" style="padding-left:14px"><b><span class="ico">📶</span>Wi-Fi</b>
      <span class="small">Clicker ESP32 → Wi-Fi → gửi thẳng lên MQTT của máy chủ. Không cần gateway. Clicker cần các thông số dưới đây.</span></div>
  </div>
  <h3 style="margin-top:12px">Thông số cho clicker Wi-Fi</h3>
  <dl class="kv">
    <dt>Wi-Fi (SSID)</dt><dd><?= h(setting('wifi_ssid', '— chưa nhập —')) ?></dd>
    <dt>Mật khẩu Wi-Fi</dt><dd><?= setting('wifi_pass') ? '<span class="secret" data-v="' . h(setting('wifi_pass')) . '">••••••••</span> <a href="#" onclick="const s=this.previousElementSibling;s.textContent=s.dataset.v;this.remove();return false">hiện</a>' : '—' ?></dd>
    <dt>MQTT trong LAN</dt><dd>mqtt://<?= h($lan_host) ?>:<?= $mp['lan_port'] ?><?= $ip ? '  (hoặc mqtt://' . h($ip) . ':' . $mp['lan_port'] . ')' : '' ?></dd>
    <dt>MQTT qua WebSocket</dt><dd><?= h(preg_replace('#^http#', 'ws', $bases['lan_name'])) . h($mp['ws_path']) ?><?= !empty($bases['public']) ? '<br>' . h(preg_replace('#^http#', 'ws', $bases['public'])) . h($mp['ws_path']) . '  (Internet)' : '' ?></dd>
    <dt>Tài khoản MQTT</dt><dd><?= h($mp['user']) ?> / <?= $mp['password'] !== '' ? '<span class="secret" data-v="' . h($mp['password']) . '">••••••••</span> <a href="#" onclick="const s=this.previousElementSibling;s.textContent=s.dataset.v;this.remove();return false">hiện</a>' : '<span style="color:var(--bad)">chưa tạo — chạy lại install_ubuntu.sh</span>' ?></dd>
    <dt>Gửi đáp án lên</dt><dd>ars/&lt;room&gt;/resp  (JSON như gateway + "via":"wifi", QoS 1)</dd>
    <dt>Nhận trạng thái</dt><dd>ars/&lt;room&gt;/state  (JSON giữ lại: qid, open, answered, attend, acks)</dd>
  </dl>
  <?php if ($admin): ?>
    <form method="post" class="row" style="margin-top:12px"><?= csrf_field() ?><input type="hidden" name="action" value="wifi">
      <label>Wi-Fi cho clicker (SSID) <input name="wifi_ssid" value="<?= h(setting('wifi_ssid', '')) ?>" maxlength="32"></label>
      <label>Mật khẩu Wi-Fi <input name="wifi_pass" type="password" value="<?= h(setting('wifi_pass', '')) ?>" maxlength="63" autocomplete="off"></label>
      <button class="primary">Lưu</button>
    </form>
  <?php endif; ?>
</section>
