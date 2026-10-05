<?php
// Xem lại bài của một buổi học / bài kiểm tra (chỉ khi buổi đã kết thúc và giáo viên đã công bố).
require __DIR__ . '/../lib/student.php';
$me = require_student();
$sid = (int)($_GET['id'] ?? 0);
$items = array_values(array_filter(my_items($me['code']), fn($r) => (int)$r['session_id'] === $sid));
if (!$items) { http_response_code(404); student_head('Không tìm thấy'); echo '<div class="empty">Buổi này chưa được công bố kết quả.</div>'; student_foot(); exit; }
$S = $items[0];
$n = count($items);
$ok = count(array_filter($items, fn($r) => $r['status'] === 'right'));
$absent = !array_filter($items, fn($r) => $r['status'] !== 'absent');
student_head($S['set_title'], 'home.php');
?>
<p class="crumbs"><a href="home.php">Tổng quan</a> › <?= h($S['class_name']) ?></p>
<h1><?= h($S['set_title']) ?> <?= $S['kind'] === 'test' ? '<span class="tag">kiểm tra</span>' : '' ?></h1>
<?php if ($absent): ?><div class="flash warn">Bạn vắng mặt ở buổi học này. Dưới đây là câu hỏi và đáp án để bạn tự ôn tập.</div>
<?php else: ?>
<div class="result-hero <?= $ok * 10 / $n >= 8 ? 'good' : ($ok * 10 / $n >= 5 ? 'mid' : 'bad') ?>">
  <div class="big"><?= $ok ?><small>/<?= $n ?></small></div>
  <div>câu đúng<br><span class="muted small"><?= h(substr($S['started_at'], 0, 16)) ?></span></div>
</div>
<?php endif; ?>
<div class="row">
  <a class="btn" href="lessons.php?session=<?= $sid ?>">Rút kinh nghiệm bài này</a>
  <a class="btn" href="wrong.php?session=<?= $sid ?>">Chỉ xem câu sai</a>
  <form method="post" action="start.php"><?= csrf_field() ?><input type="hidden" name="src" value="session"><input type="hidden" name="sid" value="<?= $sid ?>"><button>Làm lại cả bài</button></form>
</div>
<nav class="qnav">
  <?php foreach ($items as $r): ?><a href="#c<?= (int)$r['position'] ?>" class="<?= $r['status'] ?>"><?= (int)$r['position'] ?></a><?php endforeach; ?>
</nav>
<?php foreach ($items as $r):
    $lab = ['right' => ['đúng', 'live'], 'wrong' => ['sai', 'off'], 'blank' => ['bỏ trống', ''], 'absent' => ['vắng', '']][$r['status']];
    $meta = '<b>Câu ' . (int)$r['position'] . '</b> <span class="tag ' . $lab[1] . '">' . $lab[0] . '</span>'
          . ($r['topic'] ? ' <span class="tag topic">' . h($r['topic']) . '</span>' : '')
          . '<span class="spacer"></span>' . ($r['class_rate'] !== null ? '<span class="muted small">' . round($r['class_rate'] * 100) . ' % lớp đúng</span>' : '');
    echo '<div id="c' . (int)$r['position'] . '"></div>' . question_card($r, $r['answer'], ['meta' => $meta, 'cls' => $r['status'] === 'right' ? 'ok' : 'ng', 'open_expl' => $r['status'] !== 'right']);
endforeach; ?>
<?php student_foot();
