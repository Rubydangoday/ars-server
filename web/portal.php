<?php
// Trang chào ở địa chỉ gốc: chọn Giáo viên hoặc Học sinh.
require __DIR__ . '/lib/bootstrap.php';
if (me()) redirect('dashboard.php');
page_head(setting('org_name', 'ARS — Lớp học tương tác'), '', true);
?>
<div class="portal">
  <h1 style="font-size:30px;margin-bottom:6px">ARS — Hệ thống phản hồi lớp học</h1>
  <p class="muted"><?= h(setting('org_name', 'Lớp học tương tác')) ?></p>
  <div class="tiles"><a class="tile" href="parent/"><span class="big">👪</span><b>Phụ huynh</b><span class="muted small">Điểm, chuyên cần và nhận xét của con</span></a>
    <a class="tile" href="index.php"><span class="big">🧑‍🏫</span><b>Giáo viên</b><span class="muted small">Quản lý lớp, soạn câu hỏi, điều khiển buổi học</span></a>
    <a class="tile" href="student/"><span class="big">🎒</span><b>Học sinh</b><span class="muted small">Bài giảng, ôn tập, xem lại bài, trợ lý học tập</span></a>
  </div>
  <p class="muted small" style="margin-top:22px">Lần sau vào thẳng: <b><?= h(current_base()) ?>/gv</b> (giáo viên) · <b><?= h(current_base()) ?>/hs</b> (học sinh)</p>
</div>
<?php page_foot();
