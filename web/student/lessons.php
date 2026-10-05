<?php
// Rút kinh nghiệm sau kiểm tra: gom câu sai theo chủ đề và theo lý do, đưa ra bài học + kế hoạch ôn kỹ từng phần.
require __DIR__ . '/../lib/student.php';
$me = require_student();
$code = $me['code'];
$NOTOPIC = '(chưa gắn chủ đề)';
$items = my_items($code);

// danh sách buổi: bài kiểm tra trước, rồi buổi học; mới nhất trước
$sessions = [];
foreach ($items as $r) $sessions[$r['session_id']] ??= $r;
uasort($sessions, fn($a, $b) => [$b['kind'] === 'test', $b['started_at']] <=> [$a['kind'] === 'test', $a['started_at']]);
$sid = (int)($_GET['session'] ?? array_key_first($sessions) ?? 0);
$S = $sessions[$sid] ?? null;
$notes = [];
foreach (qall('SELECT * FROM wrong_notes WHERE student_code=?', [$code]) as $n) $notes[(int)$n['question_id']] = $n;

student_head('Rút kinh nghiệm', 'lessons.php');
echo '<h1>Rút kinh nghiệm</h1>';
if (!$S) { echo '<div class="empty">Chưa có bài nào được công bố kết quả.</div>'; student_foot(); exit; }
?>
<form class="filters" method="get">
  <select name="session" onchange="this.form.submit()">
    <?php foreach ($sessions as $id => $r): ?><option value="<?= (int)$id ?>" <?= $id === $sid ? 'selected' : '' ?>><?= $r['kind'] === 'test' ? '[Kiểm tra] ' : '' ?><?= h(substr($r['started_at'], 0, 10) . ' — ' . $r['set_title']) ?></option><?php endforeach; ?>
  </select>
  <button type="button" onclick="window.print()">In / lưu PDF</button>
</form>
<?php
$mine = array_values(array_filter($items, fn($r) => (int)$r['session_id'] === $sid));
$absent = !array_filter($mine, fn($r) => $r['status'] !== 'absent');
$n = count($mine);
$ok = count(array_filter($mine, fn($r) => $r['status'] === 'right'));
$wrong = array_values(array_filter($mine, fn($r) => in_array($r['status'], ['wrong', 'blank'], true)));
$blank = count(array_filter($mine, fn($r) => $r['status'] === 'blank'));
// điểm trung bình lớp (học sinh có mặt)
$cls = q1('SELECT COUNT(DISTINCT l.seat_no) n_present, SUM(f.answer=q.correct) n_ok FROM session_logins l '
        . 'LEFT JOIN session_questions sq ON sq.session_id=l.session_id LEFT JOIN questions q ON q.id=sq.question_id AND q.correct IS NOT NULL AND q.correct<>"" '
        . 'LEFT JOIN final_answers f ON f.session_q_id=sq.id AND f.seat_no=l.seat_no AND q.id IS NOT NULL WHERE l.session_id=?', [$sid]);
$class_avg = ($cls && $cls['n_present'] && $n) ? $cls['n_ok'] / $cls['n_present'] * 10 / $n : null;
$score = $n ? $ok * 10 / $n : 0;

// theo chủ đề
$by = [];
foreach ($mine as $r) {
    $tp = $r['topic'] ?: $NOTOPIC;
    $by[$tp]['n'] = ($by[$tp]['n'] ?? 0) + 1;
    $by[$tp]['ok'] = ($by[$tp]['ok'] ?? 0) + ($r['status'] === 'right');
    if ($r['status'] !== 'right') $by[$tp]['wrong'][] = (int)$r['question_id'];
}
uasort($by, fn($a, $b) => $a['ok'] / $a['n'] <=> $b['ok'] / $b['n']);
// theo lý do
$reasons = []; $no_reason = 0;
foreach ($wrong as $r) {
    $k = $notes[(int)$r['question_id']]['reason'] ?? null;
    if ($k) $reasons[$k] = ($reasons[$k] ?? 0) + 1; else $no_reason++;
}
arsort($reasons);
$ms = mastery($code, array_column($wrong, 'question_id'));
?>

<?php if ($absent): ?>
  <div class="flash warn">Bạn vắng mặt ở buổi học này. Bạn có thể làm lại toàn bộ bài trong mục Ôn tập.</div>
<?php else: ?>
<div class="result-hero <?= $score >= 8 ? 'good' : ($score >= 5 ? 'mid' : 'bad') ?>">
  <div class="big"><?= number_format($score, 1, ',', '') ?><small>/10</small></div>
  <div><b><?= $ok ?>/<?= $n ?></b> câu đúng · <?= count($wrong) - $blank ?> sai · <?= $blank ?> bỏ trống
    <?php if ($class_avg !== null): ?><br><span class="small">Trung bình lớp: <?= number_format($class_avg, 1, ',', '') ?>/10</span><?php endif; ?>
    <br><span class="muted small"><?= h($S['set_title']) ?> · <?= h($S['class_name']) ?> · <?= h(substr($S['started_at'], 0, 16)) ?></span></div>
</div>

<?php if (!$wrong): ?>
  <div class="empty">Bạn đã trả lời đúng tất cả các câu. Xem lại mục <a href="right.php">Câu đúng</a> để ghi nhớ lâu hơn.</div>
<?php else: ?>
<div class="cols">
<section class="card">
  <h2>Kết quả theo chủ đề</h2>
  <?php foreach ($by as $tp => $x): $rate = $x['ok'] / $x['n']; ?>
    <div class="topic-row">
      <span><b><?= h($tp) ?></b> <span class="muted small">đúng <?= $x['ok'] ?>/<?= $x['n'] ?></span></span>
      <div class="meter <?= $rate < 0.5 ? 'bad' : ($rate < 0.8 ? 'mid' : '') ?>"><span style="width:<?= round($rate * 100) ?>%"></span></div>
    </div>
  <?php endforeach; ?>
</section>
<section class="card">
  <h2>Nguyên nhân sai</h2>
  <?php foreach ($reasons as $k => $c): ?>
    <div class="topic-row"><span><?= h(REASONS[$k]) ?></span><span class="muted"><?= $c ?> câu</span></div>
  <?php endforeach; ?>
  <?php if ($no_reason): ?>
    <p class="muted small"><?= $no_reason ?> câu chưa ghi lý do. <a href="wrong.php?session=<?= $sid ?>">Ghi lý do cho từng câu sai →</a><br>Ghi lý do giúp phần “bài học rút ra” bên dưới cụ thể hơn.</p>
  <?php endif; ?>
</section>
</div>

<section class="card lessons">
  <h2>Bài học rút ra</h2>
  <ol>
  <?php $first = true; foreach ($by as $tp => $x): if (empty($x['wrong'])) continue; $weakp = $x['ok'] / $x['n'] < 0.5; ?>
    <li><b><?= h($tp) ?>:</b> sai <?= count($x['wrong']) ?>/<?= $x['n'] ?> câu.
      <?= $weakp ? ($first ? 'Phần yếu nhất' : 'Phần yếu') . ' — học lại lý thuyết trước khi làm bài ôn.' : 'Ôn lại các câu sai và làm thêm câu cùng chủ đề.' ?></li>
  <?php $first = false; endforeach; ?>
  <?php foreach ($reasons as $k => $c): ?><li><b><?= h(REASONS[$k]) ?> (<?= $c ?> câu):</b> <?= h(ADVICE[$k]) ?></li><?php endforeach; ?>
  <?php if ($blank): ?><li><b>Bỏ trống <?= $blank ?> câu:</b> bài trắc nghiệm không trừ điểm câu sai, nên chọn phương án hợp lý nhất trước khi hết giờ.</li><?php endif; ?>
  <?php foreach ($wrong as $r): $nn = $notes[(int)$r['question_id']]['note'] ?? ''; if ($nn !== ''): ?>
    <li class="note">Câu <?= (int)$r['position'] ?>: “<?= h($nn) ?>”</li>
  <?php endif; endforeach; ?>
  </ol>
</section>

<section class="card">
  <h2>Kế hoạch ôn kỹ</h2>
  <p class="muted small">Mỗi phần: làm lượt “Ôn kỹ” (câu sai + câu cùng chủ đề), câu nào đúng 2 lần liên tiếp thì tính là đã thuộc.</p>
  <?php foreach ($by as $tp => $x): if (empty($x['wrong'])) continue;
      $done = count(array_filter($x['wrong'], fn($q) => $ms[$q] === 'thuoc')); $tot = count($x['wrong']); ?>
    <div class="todo">
      <div><span class="check-box <?= $done === $tot ? 'on' : '' ?>"><?= $done === $tot ? '✓' : '' ?></span>
        <b><?= h($tp) ?></b><br><span class="muted small">đã thuộc <?= $done ?>/<?= $tot ?> câu sai</span>
        <div class="meter"><span style="width:<?= round($done * 100 / $tot) ?>%"></span></div></div>
      <form method="post" action="start.php"><?= csrf_field() ?><input type="hidden" name="src" value="topic"><input type="hidden" name="class_id" value="<?= (int)$S['class_id'] ?>"><input type="hidden" name="topic" value="<?= h($tp) ?>"><button class="<?= $done < $tot ? 'primary' : '' ?>">Ôn kỹ phần này</button></form>
    </div>
  <?php endforeach; ?>
  <div class="row">
    <form method="post" action="start.php"><?= csrf_field() ?><input type="hidden" name="src" value="wrong"><input type="hidden" name="session" value="<?= $sid ?>"><button>Ôn lại tất cả câu sai của bài này</button></form>
    <form method="post" action="start.php"><?= csrf_field() ?><input type="hidden" name="src" value="session"><input type="hidden" name="sid" value="<?= $sid ?>"><button>Làm lại cả bài</button></form>
  </div>
</section>
<?php endif; ?>
<?php endif; ?>
<?php student_foot();
