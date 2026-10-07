<?php
// Ôn tập: bài giáo viên giao (ôn trước kiểm tra / bài tập), tự ôn câu sai, ôn theo chủ đề, làm lại một buổi, lịch sử.
require __DIR__ . '/../lib/student.php';
$me = require_student();
$code = $me['code'];
$NOTOPIC = '(chưa gắn chủ đề)';
$classes = my_classes($code);
$cids = array_map('intval', array_column($classes, 'class_id')) ?: [0];
$in = implode(',', $cids);

$assigns = qall("SELECT a.*, c.name AS class_name, "
    . "(SELECT COUNT(*) FROM questions q WHERE q.set_id=a.set_id AND q.correct IS NOT NULL AND q.correct<>'') n_q, "
    . "(SELECT COUNT(*) FROM practice_attempts p WHERE p.assignment_id=a.id AND p.student_code=? AND p.finished_at IS NOT NULL) tries, "
    . "(SELECT MAX(n_first_ok*10/n_questions) FROM practice_attempts p WHERE p.assignment_id=a.id AND p.student_code=? AND p.finished_at IS NOT NULL) best, "
    . "(SELECT MAX(id) FROM practice_attempts p WHERE p.assignment_id=a.id AND p.student_code=? AND p.finished_at IS NULL) open_id, "
    . "(a.due_at IS NOT NULL AND a.due_at < NOW()) overdue "
    . "FROM practice_assignments a JOIN classes c ON c.id=a.class_id WHERE a.class_id IN ($in) "
    . "ORDER BY a.kind='pre_test' DESC, a.due_at IS NULL, a.due_at, a.id DESC", [$code, $code, $code]);

$items = my_items($code);
$wrong = array_filter(latest_by_question($items), fn($r) => in_array($r['status'], ['wrong', 'blank'], true));
$ms = mastery($code, array_column($wrong, 'question_id'));
$need = count(array_filter($wrong, fn($r) => $ms[$r['question_id']] !== 'thuoc'));

// chủ đề: từ các buổi đã có kết quả + các bộ được giao
$topics = [];
foreach ($items as $r) if ($r['status'] !== 'absent') {
    $k = $r['class_id'] . '|' . ($r['topic'] ?: $NOTOPIC);
    $topics[$k]['n'] = ($topics[$k]['n'] ?? 0) + 1;
    $topics[$k]['ok'] = ($topics[$k]['ok'] ?? 0) + ($r['status'] === 'right');
}
foreach ($classes as $c) {
    $sets = visible_set_ids((int)$c['class_id']);
    if (!$sets) continue;
    foreach (qall('SELECT COALESCE(NULLIF(topic,""), ?) tp, COUNT(*) n FROM questions WHERE set_id IN (' . implode(',', $sets) . ") AND correct IS NOT NULL AND correct<>'' GROUP BY tp", [$NOTOPIC]) as $t) {
        $k = $c['class_id'] . '|' . $t['tp'];
        $topics[$k]['bank'] = (int)$t['n'];
    }
}
ksort($topics);
$sessions = [];
foreach ($items as $r) $sessions[$r['session_id']] ??= $r;
$history = qall('SELECT * FROM practice_attempts WHERE student_code=? ORDER BY id DESC LIMIT 20', [$code]);

student_head('Ôn tập', 'practice.php');
?>
<div class="page-head"><div><h1>🎯 Ôn tập</h1><p class="muted">Làm bài thầy cô giao, ôn lại câu sai hoặc ôn theo từng chủ đề — câu sai được hỏi lại đến khi bạn thuộc.</p></div></div>

<section class="card">
  <h2>Giáo viên giao</h2>
  <?php if (!$assigns): ?><p class="muted">Chưa có bài ôn tập nào.</p><?php endif; ?>
  <?php foreach ($assigns as $a): ?>
    <div class="todo">
      <div><b><?= h($a['title']) ?></b> <span class="tag <?= $a['kind'] === 'pre_test' ? 'gold' : '' ?>"><?= $a['kind'] === 'pre_test' ? 'ôn trước kiểm tra' : 'bài tập' ?></span>
        <?= $a['best'] !== null ? '<span class="tag live">đã làm · cao nhất ' . number_format((float)$a['best'], 1, ',', '') . '/10</span>' : ($a['overdue'] ? '<span class="tag off">quá hạn</span>' : '') ?><br>
        <span class="muted small"><?= h($a['class_name']) ?> · <?= (int)$a['n_q'] ?> câu · <?= $a['mode'] === 'exam' ? 'như kiểm tra' . ($a['time_limit'] ? ', ' . (int)$a['time_limit'] . ' phút' : '') : 'luyện tập' ?><?= $a['due_at'] ? ' · hạn ' . h(substr($a['due_at'], 0, 16)) : '' ?><?= $a['tries'] ? ' · đã làm ' . (int)$a['tries'] . ' lần' : '' ?></span></div>
      <?php if ($a['open_id']): ?><a class="btn primary" href="quiz.php?a=<?= (int)$a['open_id'] ?>">Làm tiếp</a>
      <?php else: ?><form method="post" action="start.php"><?= csrf_field() ?><input type="hidden" name="src" value="assignment"><input type="hidden" name="aid" value="<?= $a['id'] ?>"><button class="<?= $a['best'] === null ? 'primary' : '' ?>"><?= $a['best'] === null ? 'Bắt đầu' : 'Làm lại' ?></button></form><?php endif; ?>
    </div>
  <?php endforeach; ?>
</section>

<section class="card">
  <h2>Tự ôn</h2>
  <div class="todo">
    <div><b>Ôn lại câu sai</b><br><span class="muted small"><?= $need ?> câu chưa thuộc / <?= count($wrong) ?> câu sai hoặc bỏ trống</span></div>
    <form method="post" action="start.php"><?= csrf_field() ?><input type="hidden" name="src" value="wrong"><button class="primary" <?= $wrong ? '' : 'disabled' ?>>Bắt đầu</button></form>
  </div>
  <h3>Theo chủ đề</h3>
  <?php if (!$topics): ?><p class="muted">Chưa có chủ đề nào.</p><?php endif; ?>
  <?php foreach ($topics as $k => $x): [$cid, $tp] = explode('|', $k, 2); $rate = isset($x['n']) ? $x['ok'] / $x['n'] : null; ?>
    <div class="todo">
      <div><b><?= h($tp) ?></b><?= count($classes) > 1 ? ' <span class="muted small">' . h(my_class($code, (int)$cid)['class_name']) . '</span>' : '' ?><br>
        <span class="muted small"><?= $rate !== null ? 'Đúng ' . $x['ok'] . '/' . $x['n'] . ' câu' : 'Chưa làm câu nào' ?><?= isset($x['bank']) ? ' · ' . $x['bank'] . ' câu để ôn' : '' ?></span>
        <?php if ($rate !== null): ?><div class="meter <?= $rate < 0.5 ? 'bad' : ($rate < 0.8 ? 'mid' : '') ?>"><span style="width:<?= round($rate * 100) ?>%"></span></div><?php endif; ?></div>
      <form method="post" action="start.php"><?= csrf_field() ?><input type="hidden" name="src" value="topic"><input type="hidden" name="class_id" value="<?= (int)$cid ?>"><input type="hidden" name="topic" value="<?= h($tp) ?>"><button>Ôn</button></form>
    </div>
  <?php endforeach; ?>
  <?php if ($sessions): ?>
  <h3>Làm lại bài của một buổi</h3>
  <form method="post" action="start.php" class="row"><?= csrf_field() ?><input type="hidden" name="src" value="session">
    <select name="sid"><?php foreach ($sessions as $sid => $r): ?><option value="<?= (int)$sid ?>"><?= h(substr($r['started_at'], 0, 10) . ' — ' . $r['set_title']) ?><?= $r['kind'] === 'test' ? ' (kiểm tra)' : '' ?></option><?php endforeach; ?></select>
    <button>Làm lại</button></form>
  <?php endif; ?>
</section>

<?php if ($history): ?>
<section class="card">
  <h2>Lịch sử ôn tập</h2>
  <?php foreach ($history as $h): $sc = $h['n_questions'] ? $h['n_first_ok'] * 10 / $h['n_questions'] : 0; ?>
    <a class="row-link" href="<?= $h['finished_at'] ? 'review.php' : 'quiz.php' ?>?a=<?= $h['id'] ?>">
      <span><b><?= h($h['title']) ?></b><br><span class="muted small"><?= h(substr($h['started_at'], 0, 16)) ?> · <?= (int)$h['n_questions'] ?> câu</span></span>
      <span class="score"><?= $h['finished_at'] ? number_format($sc, 1, ',', '') . '/10' : '<span class="tag">đang làm</span>' ?></span>
    </a>
  <?php endforeach; ?>
</section>
<?php endif; ?>
<?php student_foot();
