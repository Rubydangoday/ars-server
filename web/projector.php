<?php
// Màn chiếu cho cả lớp: câu đang chiếu, đồng hồ, số đã trả lời; biểu đồ chỉ hiện khi GV bấm "Hiện kết quả".
require __DIR__ . '/lib/bootstrap.php';
require_login();
$ses = own_session((int)($_GET['id'] ?? 0));
if($_SERVER['REQUEST_METHOD']==='POST'){q('UPDATE sessions SET live_distribution=? WHERE id=?',[(int)isset($_POST['live']),$ses['id']]);redirect('projector.php?id='.$ses['id']);}
page_head(t('Màn chiếu') . ' — ' . $ses['class_name'], '', true);
?>
<form method="post" class="pj-tools"><?= csrf_field() ?><label><input type="checkbox" name="live" <?= $ses['live_distribution'] ? 'checked' : '' ?>> <?= te('Hiện biểu đồ lựa chọn khi đang nhận (có thể ảnh hưởng câu trả lời)') ?></label><button><?= te('Lưu') ?></button></form>
<div class="pj-tools"><button id="pj-full"><?= te('Toàn màn hình') ?></button><button id="pj-sound" aria-pressed="false"><?= te('Bật âm đếm ngược') ?></button></div>
<div id="pj" class="projector" data-session="<?= (int)$ses['id'] ?>">
  <header><span id="pj-class"><?= h($ses['class_name']) ?></span><span id="pj-no"></span><span id="pj-timer" class="timer"></span></header>
  <div id="pj-wait" class="pj-wait"><?= te('Chờ giáo viên mở câu hỏi…') ?><br><small><?= te('Điểm danh: bấm MODE → nhập số thứ tự → bấm OK.') ?></small></div>
  <div id="pj-q" hidden>
    <div id="pj-text" class="pj-text"></div><div id="pj-media"></div>
    <ol id="pj-opts" class="pj-opts"></ol>
    <div id="pj-bars"></div>
  </div>
  <footer>
    <div class="pj-progress"><span id="pj-fill"></span></div>
    <div><b id="pj-count">0</b> / <span id="pj-logged">0</span> <?= te('đã trả lời') ?> · <span id="pj-state"></span></div>
    <div class="muted"><?= te('Phím 1–9 = A–I, phím 0 = J, rồi bấm OK') ?></div>
  </footer>
</div>
<?php page_foot('projector.js');
