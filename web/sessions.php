<?php
// Tất cả buổi học của giáo viên.
require __DIR__ . '/lib/bootstrap.php';
$t = require_login();
$rows = qall('SELECT s.*, c.name AS class_name, c.room_id, qs.title, l.no AS lesson_no, l.id AS lesson_id2, '
           . '(SELECT COUNT(*) FROM session_logins l WHERE l.session_id=s.id) AS n_logged, '
           . '(SELECT COUNT(*) FROM session_questions sq WHERE sq.session_id=s.id) AS n_q '
           . 'FROM sessions s JOIN classes c ON c.id=s.class_id JOIN question_sets qs ON qs.id=s.set_id LEFT JOIN lessons l ON l.id=s.lesson_id '
           . 'WHERE c.teacher_id=? ORDER BY s.id DESC', [$t['id']]);
page_head('Lịch sử', 'sessions.php');
?>
<h1>Lịch sử</h1>
<nav class="subtabs"><a class="on" href="sessions.php">🗓 Phiên trả lời clicker</a><a href="practice_log.php">📝 Luyện tập của học sinh</a></nav>
<p class="muted">Mỗi lần giáo viên bấm <b>Bắt đầu</b> ở một lớp là một phiên trả lời bằng clicker. Nội dung từng buổi (bài giảng, kiến thức, câu hỏi củng cố) nằm ở tab <a href="lessons.php">Buổi học</a>.</p>
<?php if (!$rows): ?><p class="muted">Chưa có buổi học. Vào <a href="classes.php">một lớp</a> để bắt đầu.</p><?php else: ?>
<table class="grid">
  <thead><tr><th>Bắt đầu</th><th>Lớp</th><th>Buổi</th><th>Bộ câu hỏi</th><th>Điểm danh</th><th>Câu đã mở</th><th>Trạng thái</th><th></th></tr></thead>
  <tbody><?php foreach ($rows as $s): ?>
    <tr><td><?= h($s['started_at']) ?></td><td><?= h($s['class_name']) ?></td><td><?= $s['lesson_no'] ? '<a href="lesson.php?id=' . (int)$s['lesson_id2'] . '">Buổi ' . (int)$s['lesson_no'] . '</a>' : '—' ?></td><td><?= h($s['title']) ?> <?= $s['kind'] === 'test' ? '<span class="tag">kiểm tra</span>' : '' ?></td>
      <td><?= (int)$s['n_logged'] ?></td><td><?= (int)$s['n_q'] ?></td>
      <td><?= $s['status'] === 'running' ? '<span class="tag live">đang học</span>' : 'đã kết thúc' ?></td>
      <td class="right"><a href="session.php?id=<?= $s['id'] ?>">Điều khiển</a> · <a href="results.php?id=<?= $s['id'] ?>">Kết quả</a></td></tr>
  <?php endforeach; ?></tbody>
</table>
<?php endif; ?>
<?php page_foot();
