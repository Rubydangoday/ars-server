<?php
// Bài giảng: các buổi giáo viên đã công bố — file bài giảng, kiến thức trọng tâm, câu hỏi củng cố.
require __DIR__ . '/../lib/student.php';
$me = require_student();
$code = $me['code'];
$classes = my_classes($code);
student_head('Bài giảng', 'lectures.php');
?>
<div class="page-head"><div><h1>📚 Bài giảng</h1><p class="muted">Xem lại bài giảng từng buổi và làm vài câu củng cố để nhớ lâu hơn.</p></div></div>
<?php if (!$classes): ?><div class="empty">Bạn chưa có tên trong lớp nào.</div><?php endif; ?>
<?php foreach ($classes as $c):
    $ls = qall('SELECT l.*, (SELECT COUNT(*) FROM questions q WHERE q.set_id=l.quiz_set_id AND q.correct IS NOT NULL) AS n_quiz, '
             . '(SELECT MAX(a.n_first_ok / a.n_questions) FROM practice_attempts a WHERE a.lesson_id=l.id AND a.student_code=? AND a.finished_at IS NOT NULL) AS best '
             . 'FROM lessons l WHERE l.class_id=? AND l.published=1 ORDER BY l.no', [$code, $c['class_id']]); ?>
  <section class="card">
    <h2><?= h($c['class_name']) ?> <span class="muted small">· <?= h($c['teacher_name']) ?></span></h2>
    <?php if (!$ls): ?><p class="muted">Giáo viên chưa công bố bài giảng nào.</p><?php endif; ?>
    <?php foreach ($ls as $l): $ext = $l['file_name'] ? strtoupper(pathinfo($l['file_name'], PATHINFO_EXTENSION)) : ''; ?>
      <a class="row-link" href="lecture.php?id=<?= $l['id'] ?>">
        <span><b>Buổi <?= (int)$l['no'] ?><?= $l['title'] !== '' ? ' · ' . h($l['title']) : '' ?></b><br>
          <span class="muted small"><?= $l['taught_on'] ? h(date('d/m/Y', strtotime($l['taught_on']))) . ' · ' : '' ?><?= $ext ? '📄 ' . h($ext) : 'chưa có file' ?><?= $l['n_quiz'] ? ' · ❓ ' . (int)$l['n_quiz'] . ' câu củng cố' : '' ?></span></span>
        <span class="score"><?= $l['best'] !== null ? '<span class="tag ' . ($l['best'] >= 0.8 ? 'live' : 'gold') . '">' . round($l['best'] * 100) . ' %</span>' : ($l['n_quiz'] ? '<span class="tag">chưa làm</span>' : '') ?></span>
      </a>
    <?php endforeach; ?>
  </section>
<?php endforeach; ?>
<?php student_foot();
