<?php
// Tổng quan: tỉ lệ đúng, câu sai cần ôn, bài ôn tập được giao, chủ đề yếu, các buổi gần đây.
require __DIR__ . '/../lib/student.php';
$me = require_student();
$code = $me['code'];
if (($_POST['action'] ?? '') === 'join') {        // vào lớp bằng mã tham gia lớp giáo viên cung cấp
    try { flash(join_class($code, $me['full_name'], (string)($_POST['join'] ?? '')), 'ok'); fx_once('celebrate'); }
    catch (RuntimeException $e) { flash($e->getMessage(), 'err'); }
    redirect('home.php');
}
$classes = my_classes($code);
$join_form = fn(bool $big) => '<form method="post" class="' . ($big ? 'card join-big' : 'join-small') . '">' . csrf_field() . '<input type="hidden" name="action" value="join">'
    . ($big ? '<h2>🏫 Vào lớp học</h2><p class="muted">Bạn chưa ở lớp nào. Nhập <b>mã tham gia lớp</b> (6 ký tự) giáo viên cho để xem bài giảng, bài tập và kết quả bấm Remote.</p>' : '<span class="muted small">Vào thêm lớp khác:</span>')
    . '<div class="row"><input name="join" maxlength="6" required placeholder="VD: K7M2QX" value="' . h(strtoupper((string)($_GET['join'] ?? ''))) . '" style="text-transform:uppercase;letter-spacing:3px;max-width:180px">'
    . '<button class="primary' . ($big ? '' : ' small') . '">Vào lớp</button></div></form>';
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

$hw_todo = qall('SELECT h.*, c.name AS class_name FROM homework h JOIN classes c ON c.id=h.class_id JOIN students st ON st.class_id=c.id AND st.student_code=? '
               . 'LEFT JOIN homework_submissions s ON s.homework_id=h.id AND s.student_code=? WHERE h.published=1 AND s.submitted_at IS NULL AND (h.due_at IS NULL OR h.due_at>NOW()) ORDER BY h.due_at IS NULL, h.due_at', [$code, $code]);
$newl = qall('SELECT l.id, l.no, l.title, c.name AS class_name, (SELECT COUNT(*) FROM questions q WHERE q.set_id=l.quiz_set_id) nq, '
           . '(SELECT COUNT(*) FROM practice_attempts a WHERE a.lesson_id=l.id AND a.student_code=? AND a.finished_at IS NOT NULL) done '
           . 'FROM lessons l JOIN classes c ON c.id=l.class_id JOIN students s ON s.class_id=l.class_id AND s.student_code=? '
           . 'WHERE l.published=1 ORDER BY l.updated_at DESC LIMIT 3', [$me['code'], $me['code']]);
$st = stu_stats($me['code']);
$pct = $done ? (int)round($right * 100 / count($done)) : 0;
$series = [];
foreach (array_reverse(array_slice($sess, 0, 10, true), true) as $x) if (!$x['absent'] && $x['n']) $series[date('d/m', strtotime($x['r']['started_at']))] = 10 * $x['ok'] / $x['n'];
student_head('Tổng quan', 'home.php');
?>
<section class="sh-hero">
  <div class="sh-hero-b">
    <span class="sh-date"><?= h(t_date(time())) ?></span>
    <h1><?= te('Xin chào, {name}', ['name' => $me['full_name']]) ?>! 👋</h1>
    <p><?= $st['streak'] ? '🔥 Bạn đã học ' . $st['streak'] . ' ngày liên tiếp' . ($st['today'] ? ' — tuyệt lắm!' : ' — học hôm nay để giữ chuỗi nhé!') : 'Bắt đầu chuỗi ngày học đầu tiên của bạn hôm nay nhé!' ?></p>
    <div class="sh-cta"><a class="btn sh-btn" href="<?= $hw_todo ? 'homework.php?id=' . (int)$hw_todo[0]['id'] : ($todo ? 'practice.php' : 'tutor.php') ?>"><?= $hw_todo ? '📝 Làm bài tập tiếp theo' : ($todo ? '🎯 Làm bài ôn tập' : '🦉 Học cùng Cú Học') ?></a>
      <?php if ($need): ?><a class="btn sh-btn ghost" href="wrong.php?st=need">❌ Ôn <?= $need ?> câu sai</a><?php endif; ?></div>
  </div>
  <div class="sh-mascot" aria-hidden="true">🦉</div>
</section>
<?php if (!$classes): ?><?= $join_form(true) ?><?php endif; ?>

<div class="ui-kpis">
  <?= ui_kpi('🎯', $pct . '%', 'Tỉ lệ câu đúng', $right . '/' . count($done) . ' câu', 'green', ui_ring($pct)) ?>
  <?= ui_kpi('❌', (string)$need, 'Câu sai chưa thuộc', $need ? '<a href="wrong.php?st=need">Ôn ngay →</a>' : 'Tuyệt vời!', 'red') ?>
  <?= ui_kpi('📝', (string)(count($todo) + count($hw_todo)), 'Bài cần làm', count($hw_todo) . ' bài về nhà · ' . count($todo) . ' bài ôn', 'orange') ?>
  <?= ui_kpi('🗓', (string)count($sess), 'Buổi đã có kết quả', '', 'violet') ?>
</div>

<div class="sh-grid"><div class="sh-main">
<section class="card">
  <?= ui_card_head('✅ Việc cần làm', 'Bài tập về nhà và bài ôn tập thầy cô giao.') ?>
  <?php if (!$todo && !$hw_todo): ?><?= ui_empty('🎉', 'Bạn đã làm hết bài được giao', 'Ôn lại câu sai hoặc hỏi Cú Học để nhớ lâu hơn nhé.', '<a class="btn" href="practice.php">🎯 Tự ôn tập</a>') ?><?php endif; ?>
  <ul class="sh-list">
  <?php foreach ($hw_todo as $h): $left = $h['due_at'] ? strtotime($h['due_at']) - time() : null; ?>
    <li><span class="ui-ico sm tone-orange">🏡</span><div><b><?= h($h['title']) ?></b><small><?= h($h['class_name']) ?> · <?= $left === null ? 'Không giới hạn thời gian' : 'Hạn ' . h(date('d/m H:i', strtotime($h['due_at']))) ?></small></div>
      <?php if ($left !== null && $left < 86400): ?><span class="tag bad">⏰ còn <?= max(1, (int)ceil($left / 3600)) ?> giờ</span><?php endif; ?>
      <a class="btn small primary" href="homework.php?id=<?= (int)$h['id'] ?>">Làm bài</a></li>
  <?php endforeach; ?>
  <?php foreach ($todo as $a): ?>
    <li><span class="ui-ico sm tone-blue">🎯</span><div><b><?= h($a['title']) ?></b><small><?= h($a['class_name']) ?> · <?= (int)$a['n_q'] ?> câu · <?= $a['mode'] === 'exam' ? 'làm như kiểm tra' : 'luyện tập, biết đúng sai ngay' ?><?= $a['due_at'] ? ' · hạn ' . h(date('d/m H:i', strtotime($a['due_at']))) : '' ?></small></div>
      <form method="post" action="start.php"><?= csrf_field() ?><input type="hidden" name="src" value="assignment"><input type="hidden" name="aid" value="<?= $a['id'] ?>"><button class="small primary">Bắt đầu</button></form></li>
  <?php endforeach; ?>
  </ul>
</section>

<section class="card">
  <?= ui_card_head('📈 Điểm các buổi gần đây', 'Điểm Remote trên lớp (thang 10).') ?>
  <?= count($series) >= 1 ? '<div class="ui-chart-wrap tone-violet">' . ui_line_chart($series, 10, 'Điểm các buổi') . '</div>' : ui_empty('📉', 'Chưa có buổi nào được công bố kết quả') ?>
  <?php if ($sess): ?><ul class="sh-list sh-sess">
  <?php foreach (array_slice($sess, 0, 6, true) as $sid => $x): $r = $x['r']; ?>
    <li><a href="session.php?id=<?= (int)$sid ?>"><div class="date-chip"><b><?= h(date('d', strtotime($r['started_at']))) ?></b><span><?= h(date('m/Y', strtotime($r['started_at']))) ?></span></div>
      <div><b><?= h($r['set_title']) ?></b><small><?= h($r['class_name']) ?><?= $r['kind'] === 'test' ? ' · kiểm tra' : '' ?></small></div>
      <span class="sp"></span><?= $x['absent'] ? '<span class="tag">vắng</span>' : ui_score($x['n'] ? 10 * $x['ok'] / $x['n'] : null) . '<small class="muted">' . $x['ok'] . '/' . $x['n'] . '</small>' ?></a></li>
  <?php endforeach; ?></ul><?php endif; ?>
</section>
</div>
<aside class="sh-side">
<section class="card">
  <?= ui_card_head('🧠 Phần cần ôn kỹ') ?>
  <?php if (!$weak): ?><?= ui_empty('💪', 'Chưa có phần yếu', 'Các chủ đề bạn đúng dưới 80% sẽ hiện ở đây.') ?><?php endif; ?>
  <?php foreach (array_slice($weak, 0, 5, true) as $k => $x): [$cid, $topic] = explode('|', $k, 2); ?>
    <div class="sh-weak"><?= ui_bar($topic, 100 * $x['ok'] / $x['n'], $x['ok'] . '/' . $x['n']) ?>
      <div class="row" style="margin:0"><form method="post" action="start.php"><?= csrf_field() ?><input type="hidden" name="src" value="topic"><input type="hidden" name="class_id" value="<?= (int)$cid ?>"><input type="hidden" name="topic" value="<?= h($topic) ?>"><button class="small">Ôn kỹ phần này</button></form>
      <a class="btn small" href="tutor.php?mode=explain&topic=<?= urlencode($topic) ?>" title="Nhờ trợ lý giảng lại phần này">🦉</a></div></div>
  <?php endforeach; ?>
</section>
<?php if ($newl): ?>
<section class="card">
  <?= ui_card_head('📚 Bài giảng mới') ?>
  <ul class="sh-list">
  <?php foreach ($newl as $x): ?>
    <li><a href="lecture.php?id=<?= $x['id'] ?>"><span class="ui-ico sm tone-blue">📖</span><div><b>Buổi <?= (int)$x['no'] ?><?= $x['title'] !== '' ? ' · ' . h($x['title']) : '' ?></b><small><?= h($x['class_name']) ?></small></div>
      <span class="sp"></span><?= $x['nq'] && !$x['done'] ? '<span class="tag warn">' . (int)$x['nq'] . ' câu</span>' : ($x['done'] ? '<span class="tag ok">✓</span>' : '') ?></a></li>
  <?php endforeach; ?></ul>
</section>
<?php endif; ?>
<section class="card">
  <?= ui_card_head('🏫 Lớp của tôi') ?>
  <?php foreach ($classes as $c): ?><div class="sh-class"><span class="sh-cls-ava" style="--hue:<?= crc32((string)$c['class_name']) % 360 ?>"><?= h(initials((string)$c['class_name'])) ?></span><b><?= h($c['class_name']) ?></b></div><?php endforeach; ?>
  <?= $classes ? $join_form(false) : '' ?>
</section>
</aside></div>
<?php student_foot();
