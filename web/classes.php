<?php
// Danh sách lớp + tạo lớp mới.
require __DIR__ . '/lib/bootstrap.php';
$t = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    $name = trim($_POST['name'] ?? '');
    $room = (int)($_POST['room_id'] ?? 0);
    if ($name === '' || $room < 1 || $room > 255) flash(t('Nhập tên lớp và mã lớp (room) từ 1 đến 255.'), 'err');
    elseif ($o = room_taken($room)) flash(t('Mã lớp {r} đang được dùng cho lớp “{c}” của {t}. Chọn mã khác.', ['r' => $room, 'c' => $o['name'], 't' => $o['teacher']]), 'err');
    else {
        q('INSERT INTO classes (teacher_id, name, room_id) VALUES (?,?,?)', [$t['id'], $name, $room]);
        $new = (int)db()->lastInsertId();
        ensure_lessons($new);
        flash(t('Đã tạo lớp. Bước tiếp theo: nhập danh sách học sinh.'));
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

page_head(t('Lớp học'), 'classes.php');
?>
<div class="page-head">
  <div><h1><?= te('Lớp học') ?></h1><p class="muted"><?= te('{n} lớp · bấm vào một lớp để quản lý danh sách, bắt đầu buổi học hoặc giao bài ôn tập.', ['n' => count($classes)]) ?></p></div>
</div>

<div class="tiles">
  <?php foreach ($classes as $c): ?>
    <a class="tile cls-tile" href="class.php?id=<?= $c['id'] ?>" style="--hue:<?= (crc32((string)$c['name']) % 360) ?>">
      <div class="cls-band"><span class="cls-ini"><?= h(initials((string)$c['name'])) ?></span><span class="cls-room">room <?= (int)$c['room_id'] ?></span></div>
      <div class="no"><?= te('Mã lớp (room)') ?> <?= (int)$c['room_id'] ?></div>
      <h3><?= h($c['name']) ?></h3>
      <div class="meta"><span>👥 <?= te('{n} học sinh', ['n' => (int)$c['n_students']]) ?></span><span>🗓 <?= te('{n} phiên', ['n' => (int)$c['n_sessions']]) ?></span><span>📄 <?= te('{n}/10 bài giảng', ['n' => (int)$c['n_lectures']]) ?></span></div>
      <div class="meta"><?= $c['last_at'] ? te('Lần dạy gần nhất: {d}', ['d' => date('d/m/Y H:i', strtotime($c['last_at']))]) : te('Chưa dạy buổi nào') ?></div>
      <div class="actions">
        <?php if ($c['running']): ?><span class="tag live"><span class="dot-live"></span><?= te('Đang học') ?></span><?php endif; ?>
        <span class="btn primary small"><?= te('Mở lớp →') ?></span>
      </div>
    </a>
  <?php endforeach; ?>
  <label class="tile new" for="new-class-name" onclick="const d=document.getElementById('new-class');d.open=true;d.scrollIntoView({behavior:'smooth',block:'center'})">
    <b>＋</b><span><?= te('Tạo lớp mới') ?></span>
  </label>
</div>

<details class="create card<?= $classes ? ' hide-closed' : '' ?>" id="new-class" <?= $classes ? '' : 'open' ?>>
  <summary><h2 style="margin:0"><?= te('Tạo lớp mới') ?></h2></summary>
  <form method="post" class="row">
    <?= csrf_field() ?><input type="hidden" name="action" value="create">
    <label><?= te('Tên lớp') ?> <input id="new-class-name" name="name" required placeholder="<?= te('VD: 6A1 – Toán') ?>"></label>
    <label><?= te('Mã lớp (room)') ?> <input name="room_id" type="number" min="1" max="255" value="<?= $next_room ?>" required></label>
    <button class="primary"><?= te('Tạo lớp') ?></button>
  </form>
  <p class="muted small"><?= te('Mã lớp là số máy chủ phát trong beacon Bluetooth; Remote ghi nhớ mã này khi học sinh nhập ID. Cấu hình [gateway] room trong config.ini phải trùng với mã lớp đang học.') ?></p>
</details>
<?php page_foot();
