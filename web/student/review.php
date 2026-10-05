<?php
// Xem lại một lượt ôn tập: điểm, từng câu (lựa chọn lần đầu, đáp án, giải thích, số lần làm).
require __DIR__ . '/../lib/student.php';
$me = require_student();
$a = my_attempt($me['code'], (int)($_GET['a'] ?? 0));
$aid = (int)$a['id'];
if (!$a['finished_at']) redirect("quiz.php?a=$aid");
$rows = qall('SELECT q.*, i.answer, i.is_correct, i.round, i.question_id FROM practice_items i JOIN questions q ON q.id=i.question_id '
           . 'WHERE i.attempt_id=? ORDER BY i.round, i.ord', [$aid]);
$first = []; $tries = [];
foreach ($rows as $r) {
    $qid = (int)$r['question_id'];
    if ((int)$r['round'] === 1) $first[$qid] = $r;
    $tries[$qid] = ($tries[$qid] ?? 0) + ($r['answer'] !== null ? 1 : 0);
}
$n = count($first);
$ok = count(array_filter($first, fn($r) => (int)$r['is_correct'] === 1));
$wrong_ids = array_keys(array_filter($first, fn($r) => (int)$r['is_correct'] !== 1));
$score = $n ? $ok * 10 / $n : 0;

student_head('Kết quả: ' . $a['title'], 'practice.php');
?>
<p class="crumbs"><a href="practice.php">Ôn tập</a> › kết quả</p>
<h1><?= h($a['title']) ?></h1>
<div class="result-hero <?= $score >= 8 ? 'good' : ($score >= 5 ? 'mid' : 'bad') ?>">
  <div class="big"><?= number_format($score, 1, ',', '') ?><small>/10</small></div>
  <div><b><?= $ok ?>/<?= $n ?></b> câu đúng ngay lần đầu<?= $a['mode'] === 'practice' && $wrong_ids ? '<br><span class="small">Các câu sai đã được hỏi lại cho đến khi bạn trả lời đúng.</span>' : '' ?>
    <br><span class="muted small"><?= h(substr($a['started_at'], 0, 16)) ?> · <?= $a['mode'] === 'exam' ? 'làm như kiểm tra' : 'luyện tập' ?></span></div>
</div>
<div class="row">
  <?php if ($wrong_ids): ?>
  <form method="post" action="start.php"><?= csrf_field() ?><input type="hidden" name="src" value="retry"><input type="hidden" name="attempt" value="<?= $aid ?>">
    <button class="primary">Ôn lại <?= count($wrong_ids) ?> câu sai của lượt này</button></form>
  <?php endif; ?>
  <a class="btn" href="practice.php">Về trang ôn tập</a>
</div>

<?php foreach ($first as $qid => $r):
    $good = (int)$r['is_correct'] === 1;
    $meta = '<span class="tag ' . ($good ? 'live' : 'off') . '">' . ($good ? 'đúng' : ($r['answer'] === null ? 'bỏ trống' : 'sai')) . '</span>'
          . ($r['topic'] ? ' <span class="tag topic">' . h($r['topic']) . '</span>' : '')
          . ($tries[$qid] > 1 ? ' <span class="muted small">làm ' . $tries[$qid] . ' lần</span>' : '');
    echo question_card($r, $r['answer'], ['meta' => $meta, 'cls' => $good ? 'ok' : 'ng', 'open_expl' => !$good]);
endforeach; ?>
<?php student_foot();
