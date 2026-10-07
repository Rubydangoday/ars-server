<?php
// Chương trình buổi học của một lớp (mặc định 10 buổi): bài giảng, kiến thức trọng tâm, câu hỏi củng cố.
require __DIR__ . '/lib/bootstrap.php';
$t = require_login();

$classes = qall('SELECT id, name FROM classes WHERE teacher_id=? ORDER BY name', [$t['id']]);
if (!$classes) {
    page_head('Buổi học', 'lessons.php');
    echo '<h1>Buổi học</h1><div class="empty">Chưa có lớp nào. <a href="classes.php">Tạo lớp</a> trước — mỗi lớp có sẵn 10 buổi để tải bài giảng.</div>';
    page_foot(); exit;
}
$cid = (int)($_GET['class'] ?? $_SESSION['last_class'] ?? $classes[0]['id']);
if (!in_array($cid, array_map('intval', array_column($classes, 'id')), true)) $cid = (int)$classes[0]['id'];
$_SESSION['last_class'] = $cid;
$c = own_class($cid);

if (($_POST['action'] ?? '') === 'add_lesson') {
    $no = (int)qv('SELECT COALESCE(MAX(no),0)+1 FROM lessons WHERE class_id=?', [$cid]);
    if ($no <= 60) { q('INSERT INTO lessons (class_id, no) VALUES (?,?)', [$cid, $no]); redirect('lesson.php?id=' . db()->lastInsertId()); }
    redirect("lessons.php?class=$cid");
}
ensure_lessons($cid);
$lessons = qall('SELECT l.*, (SELECT COUNT(*) FROM questions q WHERE q.set_id=l.quiz_set_id) AS n_quiz, '
              . '(SELECT COUNT(*) FROM sessions s WHERE s.lesson_id=l.id) AS n_sessions, '
              . '(SELECT COUNT(DISTINCT a.student_code) FROM practice_attempts a WHERE a.lesson_id=l.id AND a.finished_at IS NOT NULL) AS n_done '
              . 'FROM lessons l WHERE l.class_id=? ORDER BY l.no', [$cid]);
$n_students = (int)qv('SELECT COUNT(*) FROM students WHERE class_id=?', [$cid]);
$with_file = count(array_filter($lessons, fn($l) => $l['file_store']));
$published = count(array_filter($lessons, fn($l) => $l['published']));

page_head('Buổi học — ' . $c['name'], 'lessons.php');
?>
<div class="page-head">
  <div><h1>📚 Buổi học</h1><p class="muted">Mỗi buổi: một file bài giảng, phần kiến thức trọng tâm và vài câu hỏi củng cố cho học sinh tự kiểm tra.</p></div>
  <form method="get" class="row" style="margin:0"><label>Lớp <select name="class" onchange="this.form.submit()">
    <?php foreach ($classes as $x): ?><option value="<?= $x['id'] ?>" <?= (int)$x['id'] === $cid ? 'selected' : '' ?>><?= h($x['name']) ?></option><?php endforeach; ?></select></label></form>
</div>

<div class="stat-row">
  <div class="stat"><span>Bài giảng đã tải</span><b><?= $with_file ?>/<?= count($lessons) ?></b><div class="meter"><span style="width:<?= round($with_file * 100 / max(1, count($lessons))) ?>%"></span></div></div>
  <div class="stat"><span>Đã công bố cho học sinh</span><b><?= $published ?></b><small>buổi</small></div>
  <div class="stat"><span>Câu hỏi củng cố</span><b><?= array_sum(array_column($lessons, 'n_quiz')) ?></b><small>câu trong <?= count(array_filter($lessons, fn($l) => $l['n_quiz'])) ?> buổi</small></div>
  <div class="stat"><span>Học sinh</span><b><?= $n_students ?></b><small><a href="class.php?id=<?= $cid ?>">quản lý lớp →</a></small></div>
</div>

<div class="tiles">
<?php foreach ($lessons as $l): $ext = $l['file_name'] ? strtolower(pathinfo($l['file_name'], PATHINFO_EXTENSION)) : ''; ?>
  <a class="tile <?= $l['file_store'] ? '' : 'empty-lesson' ?>" href="lesson.php?id=<?= $l['id'] ?>">
    <div class="no">Buổi <?= (int)$l['no'] ?><?= $l['taught_on'] ? ' · ' . h(date('d/m/Y', strtotime($l['taught_on']))) : '' ?></div>
    <h3><?= $l['title'] !== '' ? h($l['title']) : '<span class="muted">Chưa đặt tên</span>' ?></h3>
    <div class="checks">
      <span class="<?= $l['file_store'] ? 'on' : '' ?>"><?= $l['file_store'] ? '📄 ' . strtoupper($ext) : '📄 chưa có bài giảng' ?></span>
      <span class="<?= trim((string)$l['key_points']) !== '' ? 'on' : '' ?>">💡 kiến thức</span>
      <span class="<?= $l['n_quiz'] ? 'on' : '' ?>">❓ <?= (int)$l['n_quiz'] ?> câu</span>
    </div>
    <div class="meta">
      <?= $l['published'] ? '<span class="pill-ok">● Học sinh xem được</span>' : '<span>○ Chưa công bố</span>' ?>
      <?php if ($l['n_done']): ?><span><?= (int)$l['n_done'] ?>/<?= $n_students ?> đã làm câu hỏi</span><?php endif; ?>
      <?php if ($l['n_sessions']): ?><span><?= (int)$l['n_sessions'] ?> phiên clicker</span><?php endif; ?>
    </div>
  </a>
<?php endforeach; ?>
  <form method="post" class="tile new" onclick="this.submit()"><?= csrf_field() ?><input type="hidden" name="action" value="add_lesson"><b>＋</b><span>Thêm buổi</span></form>
</div>
<?php page_foot();
