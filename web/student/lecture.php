<?php
// Một buổi học: xem/tải bài giảng, kiến thức trọng tâm, làm câu hỏi củng cố.
require __DIR__ . '/../lib/student.php';
$me = require_student();
$code = $me['code'];
$l = q1('SELECT l.*, c.name AS class_name FROM lessons l JOIN classes c ON c.id=l.class_id '
      . 'JOIN students s ON s.class_id=l.class_id AND s.student_code=? WHERE l.id=? AND l.published=1', [$code, (int)($_GET['id'] ?? 0)]);
if (!$l) { http_response_code(404); student_head('Không tìm thấy'); echo '<div class="empty">Buổi học chưa được công bố.</div>'; student_foot(); exit; }
$nq = $l['quiz_set_id'] ? (int)qv("SELECT COUNT(*) FROM questions WHERE set_id=? AND correct IS NOT NULL AND correct<>''", [$l['quiz_set_id']]) : 0;
$tries = qall('SELECT * FROM practice_attempts WHERE lesson_id=? AND student_code=? ORDER BY id DESC LIMIT 5', [$l['id'], $code]);
$ext = $l['file_name'] ? strtolower(pathinfo($l['file_name'], PATHINFO_EXTENSION)) : '';
$kp = array_values(array_filter(array_map('trim', preg_split('/\R/u', (string)$l['key_points'])), fn($x) => $x !== ''));

student_head('Buổi ' . $l['no'], 'lectures.php');
?>
<p class="crumbs"><a href="lectures.php">Bài giảng</a> › <?= h($l['class_name']) ?></p>
<h1>Buổi <?= (int)$l['no'] ?><?= $l['title'] !== '' ? ': ' . h($l['title']) : '' ?></h1>
<?php if ($l['taught_on']): ?><p class="muted">Ngày học: <?= h(date('d/m/Y', strtotime($l['taught_on']))) ?></p><?php endif; ?>

<?php if ($l['file_store']): ?>
<section class="card">
  <h2>📄 Bài giảng</h2>
  <div class="filebox"><span class="ext <?= h($ext) ?>"><?= h(strtoupper($ext)) ?></span>
    <div class="info"><b><?= h($l['file_name']) ?></b><span class="small muted"><?= human_size((int)$l['file_size']) ?></span></div>
    <a class="btn primary small" href="../file.php?lesson=<?= $l['id'] ?>">Tải xuống</a></div>
  <?php if ($ext === 'pdf'): ?><iframe class="pdf" src="../file.php?lesson=<?= $l['id'] ?>&inline=1" title="Bài giảng"></iframe><?php endif; ?>
</section>
<?php endif; ?>

<?php if ($kp): ?>
<section class="card">
  <h2>💡 Kiến thức trọng tâm</h2>
  <ul class="keypoints"><?php foreach ($kp as $k): ?><li><?= h(preg_replace('/^[\-\*•●]\s*/u', '', $k)) ?></li><?php endforeach; ?></ul>
</section>
<?php endif; ?>

<section class="card">
  <h2>❓ Câu hỏi củng cố</h2>
  <?php if (!$nq): ?><p class="muted">Buổi này chưa có câu hỏi củng cố.</p><?php else: ?>
    <p><?= $nq ?> câu ngắn để tự kiểm tra kiến thức của buổi. Câu sai sẽ được hỏi lại đến khi bạn trả lời đúng.</p>
    <form method="post" action="start.php"><?= csrf_field() ?><input type="hidden" name="src" value="lesson"><input type="hidden" name="lid" value="<?= $l['id'] ?>">
      <button class="primary"><?= $tries ? 'Làm lại' : 'Bắt đầu làm' ?></button></form>
    <?php foreach ($tries as $a): ?>
      <a class="row-link" href="<?= $a['finished_at'] ? 'review.php?a=' . $a['id'] : 'quiz.php?a=' . $a['id'] ?>">
        <span class="small"><?= h(date('d/m H:i', strtotime($a['started_at']))) ?></span>
        <span class="score"><?= $a['finished_at'] ? (int)$a['n_first_ok'] . '/' . (int)$a['n_questions'] : '<span class="tag">đang làm</span>' ?></span></a>
    <?php endforeach; ?>
  <?php endif; ?>
</section>
<?php student_foot();
