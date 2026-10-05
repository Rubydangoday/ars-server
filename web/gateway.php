<?php
// Gateway Bluetooth cắm vào máy của giáo viên: trình duyệt (Chrome/Edge, Web Serial) đọc cổng USB
// và chuyển tiếp qua MQTT-WebSocket tới máy chủ — thay cho dịch vụ ars-bridge, không cần cài gì.
require __DIR__ . '/lib/bootstrap.php';
require_login();
$ses = own_session((int)($_GET['session'] ?? 0));
$mp = mqtt_public();
$room = (int)$ses['room_id'];
$bases = access_bases();
page_head('Gateway USB — ' . $ses['class_name'], 'sessions.php');
?>
<p class="crumbs"><a href="session.php?id=<?= (int)$ses['id'] ?>">‹ Bảng điều khiển</a></p>
<h1>🔌 Gateway Bluetooth qua USB máy này <span class="tag">room <?= $room ?></span></h1>
<div id="gwapp" data-room="<?= $room ?>" data-user="<?= h($mp['user']) ?>" data-pass="<?= h($mp['password']) ?>" data-path="<?= h($mp['ws_path']) ?>">
  <div id="need-secure" class="flash warn" hidden>
    Trình duyệt chỉ cho đọc cổng USB khi trang mở bằng <b>https://</b> hoặc <b>http://localhost</b>.
    <?php if (!empty($bases['public'])): ?>Mở trang này bằng link công khai:
      <a href="<?= h($bases['public'] . '/ars/gateway.php?session=' . (int)$ses['id']) ?>"><?= h($bases['public']) ?>/ars/gateway.php</a>
    <?php else: ?>Quản trị viên bật link công khai bằng <code>sudo ars public on</code>, hoặc cắm gateway vào máy chủ (dịch vụ ars-bridge).<?php endif; ?>
  </div>
  <div id="need-chrome" class="flash err" hidden>Trình duyệt này không hỗ trợ Web Serial. Dùng Google Chrome hoặc Microsoft Edge trên máy tính.</div>
  <section class="card">
    <ol class="small" style="margin:0 0 10px;padding-left:18px">
      <li>Cắm gateway nRF52840 vào cổng USB của máy này.</li>
      <li>Bấm <b>Kết nối gateway</b>, chọn cổng (thường tên “USB Serial” / “nRF52840” / “ttyACM”).</li>
      <li>Giữ tab này mở trong suốt buổi học (có thể thu nhỏ). Đóng tab = ngắt gateway.</li>
    </ol>
    <div class="row">
      <button class="primary" id="btn-connect">Kết nối gateway</button>
      <button id="btn-disconnect" disabled>Ngắt</button>
      <span class="gw" id="st-serial">USB: chưa kết nối</span>
      <span class="gw" id="st-mqtt">Máy chủ: đang nối…</span>
      <span class="gw" id="st-rate">0 gói/s</span>
    </div>
  </section>
  <section class="card">
    <h2>Nhật ký</h2>
    <div class="gwlog" id="log"></div>
  </section>
</div>
<script src="assets/vendor/mqtt.min.js"></script>
<?php page_foot('gateway.js');
