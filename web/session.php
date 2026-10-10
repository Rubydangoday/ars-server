<?php
// Bảng điều khiển buổi học (máy giáo viên). Nội dung cập nhật mỗi giây bằng assets/control.js.
require __DIR__ . '/lib/bootstrap.php';
require_login();
$ses = own_session((int)($_GET['id'] ?? 0));
page_head(t('Điều khiển') . ' — ' . $ses['class_name'], 'session.php');
?>
<div id="ctl" data-session="<?= (int)$ses['id'] ?>" data-csrf="<?= h(csrf_token()) ?>">
  <div class="ctl-head">
    <div>
      <p class="crumbs"><a href="class.php?id=<?= (int)$ses['class_id'] ?>"><?= h($ses['class_name']) ?></a> › <?= te('buổi') ?> <?= h($ses['started_at']) ?></p>
      <h1><?= h($ses['set_title']) ?> <span class="tag">room <?= (int)$ses['room_id'] ?></span> <span id="st-status" class="tag"></span></h1>
    </div>
    <div class="ctl-actions">
      <span class="head-live" id="head-live" aria-live="off"></span>
      <span class="tag topic" title="<?= te('Remote nRF52840 phát Bluetooth, laptop chạy máy chủ thu trực tiếp') ?>">🔵 Bluetooth · nRF52840</span>
      <span id="gw" class="gw">Bluetooth …</span>
      <a class="btn" href="projector.php?id=<?= (int)$ses['id'] ?>" target="_blank"><?= te('Mở màn chiếu ↗') ?></a>
      <a class="btn" href="results.php?id=<?= (int)$ses['id'] ?>"><?= te('Kết quả') ?></a>
      <button class="danger" data-act="end" data-confirm="<?= te('Kết thúc buổi học? Mọi câu đang mở sẽ đóng.') ?>"><?= te('Kết thúc buổi') ?></button>
    </div>
  </div>

  <div class="ctl-body">
    <aside class="card qlist">
      <h2><?= te('Câu hỏi') ?></h2>
      <ol id="qlist"></ol>
      <label class="check"><input type="checkbox" id="allow_change"> <?= te('Cho đổi đáp án khi câu còn mở') ?></label>
    </aside>

    <section class="card current">
      <div id="cur-empty" class="muted"><?= te('Chưa chọn câu nào. Bấm “Mở” ở danh sách bên trái.') ?></div>
      <div id="cur" hidden>
        <div class="cur-top">
          <span class="qno" id="cur-no"></span>
          <span id="cur-state" class="tag"></span>
          <span id="cur-timer" class="timer"></span>
          <span class="spacer"></span>
          <span class="big-count"><b id="cur-count">0</b> / <span id="cur-logged">0</span> <?= te('đã trả lời') ?></span>
        </div>
        <div class="qtext" id="cur-text"></div>
        <div id="cur-bars"></div>
        <div class="row">
          <button class="primary" id="btn-open" data-act="open"><?= te('Mở câu') ?></button>
          <button id="btn-close" data-act="close"><?= te('Đóng câu') ?></button>
          <button id="btn-show" data-act="show_result"><?= te('Hiện kết quả trên màn chiếu') ?></button>
          <button id="btn-next" data-act="next"><?= te('Đóng và sang câu tiếp ▶') ?></button>
        </div>
      </div>
    </section>
  </div>

  <section class="card">
    <div class="row seats-head">
      <h2><?= te('Lớp') ?>: <span id="n-logged">0</span>/<span id="n-students">0</span> <?= te('đã điểm danh') ?></h2>
      <span class="legend"><i class="sq ans"></i> <?= te('đã trả lời câu đang chiếu') ?> <i class="sq pres"></i> <?= te('có mặt, chưa trả lời') ?> <i class="sq abs"></i> <?= te('chưa điểm danh') ?> <i class="sq low"></i> <?= te('pin yếu') ?></span>
    </div>
    <div id="seats" class="seats"></div>
    <p class="muted small"><?= te('Bấm vào ô của một học sinh đã điểm danh để “giải phóng ID” (khi học sinh đổi sang thiết bị khác hoặc ID bị thiết bị khác chiếm).') ?></p>
  </section>

  <section class="card">
    <h2><?= te('Gói bị server từ chối (10 phút gần nhất)') ?></h2>
    <table class="grid compact"><thead><tr><th><?= te('Giờ') ?></th><th><?= te('Lý do') ?></th><th><?= te('Ghế') ?></th><th><?= te('Câu') ?></th><th><?= te('Chữ') ?></th><th>MAC</th><th><?= te('Lần') ?></th></tr></thead><tbody id="warn"></tbody></table>
  </section>
</div>
<?php page_foot('control.js');
