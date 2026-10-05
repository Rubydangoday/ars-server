<?php
// Danh sách lớp + tạo lớp mới.
require __DIR__ . '/lib/bootstrap.php';
$t = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    $name = trim($_POST['name'] ?? '');
    $room = (int)($_POST['room_id'] ?? 0);
    if ($name === '' || $room < 1 || $room > 255) flash('Nhập tên lớp và mã lớp (room) từ 1 đến 255.', 'err');
    elseif ($o = room_taken($room)) flash("Mã lớp $room đang được dùng cho lớp “{$o['name']}” của {$o['teacher']}. Chọn mã khác.", 'err');
    else {
        q('INSERT INTO classes (teacher_id, name, room_id) VALUES (?,?,?)', [$t['id'], $name, $room]);
        $new = (int)db()->lastInsertId();
        ensure_lessons($new);
        flash('Đã tạo lớp. Bước tiếp theo: nhập danh sách học sinh.');
        redirect('class.php?id=' . $new);
    }
    redirect('classes.php');
}

$classes = qall('SELECT c.*, (SELECT COUNT(*) FROM students s WHERE s.class_id=c.id) AS n_students, '
              . '(SELECT COUNT(*) FROM sessions x WHERE x.class_id=c.id) AS n_sessions, '
              . '(SELECT MAX(started_at) FROM sessions x WHERE x.class_id=c.id) AS last_at, '
              . '(SELECT COUNT(*) FROM lessons l WHERE l.class_id=c.id AND l.file_store IS NOT NULL) AS n_lectures, '
              . '(SELECT id FROM sessions x WHERE x.class_id=c.id AND x.status="running" ORDER BY id DESC LIMIT 1) AS running '
              . 'FROM classes c WHERE c.teacher_id=? ORDER BY c.name', [$t['id']]);
$next_room = next_free_room();

page_head('Lớp học', 'classes.php');
?>
<div class="page-head">
  <div><h1>Lớp học</h1><p class="muted"><?= count($classes) ?> lớp · bấm vào một lớp để quản lý danh sách, bắt đầu buổi học hoặc giao bài ôn tập.</p></div>
</div>

<div class="tiles">
  <?php foreach ($classes as $c): ?>
    <a class="tile" href="class.php?id=<?= $c['id'] ?>">
      <div class="no">Mã lớp (room) <?= (int)$c['room_id'] ?></div>
      <h3><?= h($c['name']) ?></h3>
      <div class="meta"><span>👥 <?= (int)$c['n_students'] ?> học sinh</span><span>🗓 <?= (int)$c['n_sessions'] ?> phiên</span><span>📄 <?= (int)$c['n_lectures'] ?>/10 bài giảng</span></div>
      <div class="meta"><?= $c['last_at'] ? 'Lần dạy gần nhất: ' . h(date('d/m/Y H:i', strtotime($c['last_at']))) : 'Chưa dạy buổi nào' ?></div>
      <div class="actions">
        <?php if ($c['running']): ?><span class="tag live"><span class="dot-live"></span>Đang học</span><?php endif; ?>
        <span class="btn primary small">Mở lớp →</span>
      </div>
    </a>
  <?php endforeach; ?>
  <label class="tile new" for="new-class-name" onclick="document.getElementById('new-class').open=true">
    <b>＋</b><span>Tạo lớp mới</span>
  </label>
</div>

<details class="create card" id="new-class" <?= $classes ? '' : 'open' ?>>
  <summary><h2 style="margin:0">Tạo lớp mới</h2></summary>
  <form method="post" class="row">
    <?= csrf_field() ?><input type="hidden" name="action" value="create">
    <label>Tên lớp <input id="new-class-name" name="name" required placeholder="IoT – Nhóm 02"></label>
    <label>Mã lớp (room) <input name="room_id" type="number" min="1" max="255" value="<?= $next_room ?>" required></label>
    <button class="primary">Tạo lớp</button>
  </form>
  <p class="muted small">Mã lớp là số gateway phát trong beacon; clicker ghi nhớ mã này khi học sinh nhập ID. Cấu hình <code>[gateway] room</code> trong config.ini phải trùng với mã lớp đang học. Mỗi lớp mới có sẵn 10 buổi học để tải bài giảng.</p>
</details>
<?php page_foot();
