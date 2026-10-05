<?php
// Tổng quan: tỉ lệ đúng, câu sai cần ôn, bài ôn tập được giao, chủ đề yếu, các buổi gần đây.
require __DIR__ . '/../lib/student.php';
$me = require_student();
$code = $me['code'];
$classes = my_classes($code);
$items = my_items($code);
$done = array_filter($items, fn($r) => $r['status'] !== 'absent');
$right = count(array_filter($done, fn($r) => $r['status'] === 'right'));
$wrongs = array_filter(latest_by_question($items), fn($r) => $r['status'] !== 'right');
$ms = mastery($code, array_column($wrongs, 'question_id'));
$need = count(array_filter($wrongs, fn($r) => $ms[$r['question_id']] !== 'thuoc'));

// chủ đề yếu (tỉ lệ đúng thấp nhất, ít nhất 2 câu)
$tp = [];
foreach ($done as $r) {
    $k = $r['class_id'] . '|' . ($r['topic'] ?: '(chưa gắn chủ đề)');
    $tp[$k]['n'] = ($tp[$k]['n'] ?? 0) + 1;
    $tp[$k]['ok'] = ($tp[$k]['ok'] ?? 0) + ($r['status'] === 'right');
}
$weak = array_filter($tp, fn($x) => $x['n'] >= 2 && $x['ok'] / $x['n'] < 0.8);
uasort($weak, fn($a, $b) => $a['ok'] / $a['n'] <=> $b['ok'] / $b['n']);

$cids = array_map('intval', array_column($classes, 'class_id')) ?: [0];
$in = implode(',', $cids);
$assigns = qall("SELECT a.*, c.name AS class_name, (SELECT COUNT(*) FROM questions q WHERE q.set_id=a.set_id AND q.correct IS NOT NULL AND q.correct<>'') n_q, "
              . "(SELECT MAX(n_first_ok*100/n_questions) FROM practice_attempts p WHERE p.assignment_id=a.id AND p.student_code=? AND p.finished_at IS NOT NULL) AS best "
              . "FROM practice_assignments a JOIN classes c ON c.id=a.class_id WHERE a.class_id IN ($in) ORDER BY a.due_at IS NULL, a.due_at, a.id DESC", [$code]);
$todo = array_filter($assigns, fn($a) => $a['best'] === null);

$sess = [];
foreach ($items as $r) {
    $k = $r['session_id'];
    $sess[$k] ??= ['r' => $r, 'n' => 0, 'ok' => 0, 'absent' => true];
    $sess[$k]['n']++;
    $sess[$k]['ok'] += $r['status'] === 'right';
    if ($r['status'] !== 'absent') $sess[$k]['absent'] = false;
}

student_head('Tổng quan', 'home.php');
?>
<?php $st = stu_stats($me['code']); ?>
<div class="stu-hero">
  <div class="mascot">🦉</div>
  <div><h1>Xin chào, <?= h($me['full_name']) ?>!</h1>
    <p><?= $st['streak'] ? '🔥 Bạn đã học ' . $st['streak'] . ' ngày liên tiếp' . ($st['today'] ? ' — tuyệt lắm!' : ' — học hôm nay để giữ chuỗi nhé!') : 'Bắt đầu chuỗi ngày học đầu tiên của bạn hôm nay nhé!' ?></p>
    <p class="small">Cấp <?= $st['level'] ?> · ⭐ <?= $st['xp'] ?> điểm · còn <?= 100 - $st['lv_pct'] ?> điểm để lên cấp</p>
    <div class="lvbar"><span style="width:<?= $st['lv_pct'] ?>%"></span></div></div>
  <div class="cta"><a class="btn" href="tutor.php">🦉 Học cùng Cú Học</a></div>
</div>
<p class="muted"><?= h(implode(' · ', array_column($classes, 'class_name'))) ?></p>

<div class="kpis">
  <div><b><?= $done ? round($right * 100 / count($done)) : 0 ?> %</b><span>câu đúng (<?= $right ?>/<?= count($done) ?>)</span></div>
  <div><b><?= $need ?></b><span>câu sai chưa thuộc</span><a href="wrong.php?st=need">Xem →</a></div>
  <div><b><?= count($todo) ?></b><span>bài ôn tập chưa làm</span><a href="practice.php">Làm →</a></div>
  <div><b><?= count($sess) ?></b><span>buổi đã có kết quả</span></div>
</div>

<?php if ($todo): ?>
<section class="card">
  <h2>Cần làm</h2>
  <?php foreach ($todo as $a): ?>
    <div class="todo">
      <div><b><?= h($a['title']) ?></b> <span class="tag"><?= $a['kind'] === 'pre_test' ? 'ôn trước kiểm tra' : 'bài tập' ?></span><br>
        <span class="muted small"><?= h($a['class_name']) ?> · <?= (int)$a['n_q'] ?> câu · <?= $a['mode'] === 'exam' ? 'làm như kiểm tra' : 'luyện tập, biết đúng sai ngay' ?><?= $a['due_at'] ? ' · hạn ' . h(substr($a['due_at'], 0, 16)) : '' ?></span></div>
      <form method="post" action="start.php"><?= csrf_field() ?><input type="hidden" name="src" value="assignment"><input type="hidden" name="aid" value="<?= $a['id'] ?>"><button class="primary">Bắt đầu</button></form>
    </div>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<?php
$newl = qall('SELECT l.id, l.no, l.title, c.name AS class_name, (SELECT COUNT(*) FROM questions q WHERE q.set_id=l.quiz_set_id) nq, '
           . '(SELECT COUNT(*) FROM practice_attempts a WHERE a.lesson_id=l.id AND a.student_code=? AND a.finished_at IS NOT NULL) done '
           . 'FROM lessons l JOIN classes c ON c.id=l.class_id JOIN students s ON s.class_id=l.class_id AND s.student_code=? '
           . 'WHERE l.published=1 ORDER BY l.updated_at DESC LIMIT 3', [$me['code'], $me['code']]);
if ($newl): ?>
<section class="card">
  <h2>Bài giảng mới</h2>
  <?php foreach ($newl as $x): ?>
    <a class="row-link" href="lecture.php?id=<?= $x['id'] ?>"><span><b>Buổi <?= (int)$x['no'] ?><?= $x['title'] !== '' ? ' · ' . h($x['title']) : '' ?></b><br><span class="muted small"><?= h($x['class_name']) ?></span></span>
      <span><?= $x['nq'] && !$x['done'] ? '<span class="tag gold">' . (int)$x['nq'] . ' câu củng cố</span>' : ($x['done'] ? '<span class="tag live">đã làm</span>' : '') ?></span></a>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<?php if ($weak): ?>
<section class="card">
  <h2>Phần cần ôn kỹ</h2>
  <?php foreach (array_slice($weak, 0, 4, true) as $k => $x): [$cid, $topic] = explode('|', $k, 2); $r = $x['ok'] / $x['n']; ?>
    <div class="todo">
      <div><b><?= h($topic) ?></b><br><span class="muted small">đúng <?= $x['ok'] ?>/<?= $x['n'] ?> câu</span>
        <div class="meter"><span style="width:<?= round($r * 100) ?>%"></span></div></div>
      <form method="post" action="start.php"><?= csrf_field() ?><input type="hidden" name="src" value="topic"><input type="hidden" name="class_id" value="<?= (int)$cid ?>"><input type="hidden" name="topic" value="<?= h($topic) ?>"><button>Ôn kỹ phần này</button></form>
      <a class="btn small" href="tutor.php?mode=explain&topic=<?= urlencode($topic) ?>" title="Nhờ trợ lý giảng lại phần này">🦉</a>
    </div>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<section class="card">
  <h2>Các buổi gần đây</h2>
  <?php if (!$sess): ?><p class="muted">Chưa có buổi nào được công bố kết quả.</p><?php endif; ?>
  <?php foreach (array_slice($sess, 0, 8, true) as $sid => $x): $r = $x['r']; ?>
    <a class="row-link" href="session.php?id=<?= (int)$sid ?>">
      <span><b><?= h($r['set_title']) ?></b> <?= $r['kind'] === 'test' ? '<span class="tag">kiểm tra</span>' : '' ?><br><span class="muted small"><?= h($r['class_name']) ?> · <?= h(substr($r['started_at'], 0, 16)) ?></span></span>
      <span class="score"><?= $x['absent'] ? '<span class="muted">vắng</span>' : $x['ok'] . '/' . $x['n'] ?></span>
    </a>
  <?php endforeach; ?>
</section>
<?php student_foot();
