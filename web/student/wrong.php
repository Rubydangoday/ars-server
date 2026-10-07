<?php
// Câu sai: xem lại đáp án đúng + giải thích, ghi "vì sao sai" (sổ tay lỗi sai), ôn lại các câu đang lọc.
require __DIR__ . '/../lib/lms.php';
$me = require_student();
$code = $me['code'];

if (($_POST['action'] ?? '') === 'note') {
    $qid = (int)$_POST['qid'];
    $reason = array_key_exists($_POST['reason'] ?? '', REASONS) ? $_POST['reason'] : null;
    $note = mb_substr(trim($_POST['note'] ?? ''), 0, 1000);
    // chỉ ghi cho câu thật sự nằm trong danh sách câu sai của học sinh
    if (in_array($qid, array_map('intval', array_column(array_filter(array_merge(my_items($code),homework_wrong_items($code),practice_wrong_items($code)), fn($r) => $r['status'] !== 'right'), 'question_id')), true)) {
        q('INSERT INTO wrong_notes (student_code, question_id, reason, note) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE reason=VALUES(reason), note=VALUES(note)',
          [$code, $qid, $reason, $note ?: null]);
        flash('Đã lưu ghi chú.');
    }
    redirect('wrong.php?' . http_build_query(array_intersect_key($_GET, array_flip(['class', 'topic', 'st', 'session']))) . '#q' . $qid);
}

$NOTOPIC = '(chưa gắn chủ đề)';
$cls = (int)($_GET['class'] ?? 0) ?: null;
$items = array_values(array_filter(latest_by_question(all_wrong_sources($code, $cls)), fn($r) => in_array($r['status'], ['wrong', 'blank'], true)));
$topics = array_count_values(array_map(fn($r) => $r['topic'] ?: $NOTOPIC, $items));
arsort($topics);
if (!empty($_GET['topic'])) $items = array_values(array_filter($items, fn($r) => ($r['topic'] ?: $NOTOPIC) === $_GET['topic']));
if (!empty($_GET['session'])) $items = array_values(array_filter($items, fn($r) => (int)$r['session_id'] === (int)$_GET['session']));
$ms = mastery($code, array_column($items, 'question_id'));
$st = $_GET['st'] ?? 'all';
if ($st === 'need') $items = array_values(array_filter($items, fn($r) => $ms[$r['question_id']] !== 'thuoc'));
$notes = [];
foreach (qall('SELECT * FROM wrong_notes WHERE student_code=?', [$code]) as $n) $notes[(int)$n['question_id']] = $n;
$classes = my_classes($code);

student_head('Câu sai', 'wrong.php');
?>
<div class="page-head"><div><h1>❌ Câu sai <span class="count-pill"><?= count($items) ?></span></h1><p class="muted">Ghi lý do sai, xem đáp án đúng và ôn lại — đúng 2 lần liên tiếp là “đã thuộc”.</p></div></div>
<form class="filters" method="get">
  <?php if (count($classes) > 1): ?><select name="class" onchange="this.form.submit()"><option value="">Mọi lớp</option>
    <?php foreach ($classes as $c): ?><option value="<?= $c['class_id'] ?>" <?= $cls === (int)$c['class_id'] ? 'selected' : '' ?>><?= h($c['class_name']) ?></option><?php endforeach; ?></select><?php endif; ?>
  <select name="topic" onchange="this.form.submit()"><option value="">Mọi chủ đề</option>
    <?php foreach ($topics as $tp => $n): ?><option value="<?= h($tp) ?>" <?= ($_GET['topic'] ?? '') === $tp ? 'selected' : '' ?>><?= h($tp) ?> (<?= $n ?>)</option><?php endforeach; ?></select>
  <select name="st" onchange="this.form.submit()">
    <option value="all" <?= $st === 'all' ? 'selected' : '' ?>>Tất cả câu sai</option>
    <option value="need" <?= $st === 'need' ? 'selected' : '' ?>>Chỉ câu chưa thuộc</option></select>
  <?php if (!empty($_GET['session'])): ?><input type="hidden" name="session" value="<?= (int)$_GET['session'] ?>"><a href="wrong.php">bỏ lọc buổi</a><?php endif; ?>
</form>

<?php if ($items): ?>
<form method="post" action="start.php" class="sticky-cta"><?= csrf_field() ?><input type="hidden" name="src" value="wrong">
  <input type="hidden" name="class_id" value="<?= (int)$cls ?>"><input type="hidden" name="topic" value="<?= h($_GET['topic'] ?? '') ?>"><input type="hidden" name="session" value="<?= (int)($_GET['session'] ?? 0) ?>">
  <button class="primary">Ôn lại các câu này</button>
  <span class="muted small">Câu sai sẽ được hỏi lại cho đến khi bạn trả lời đúng; đúng 2 lần liên tiếp được tính là “đã thuộc”.</span>
</form>
<?php else: ?>
  <div class="empty">Không có câu sai nào<?= $st === 'need' ? ' cần ôn' : '' ?>.</div>
<?php endif; ?>

<?php foreach ($items as $r):
    $qid = (int)$r['question_id'];
    [$ml, $mc] = MASTERY_LABEL[$ms[$qid]];
    $meta = '<span class="tag ' . ($r['status'] === 'blank' ? '' : 'off') . '">' . ($r['status'] === 'blank' ? 'bỏ trống' : 'sai') . '</span> '
          . '<span class="' . $mc . '">' . $ml . '</span>' . ($r['n_wrong'] > 1 ? ' <span class="tag off">sai ' . $r['n_wrong'] . ' lần</span>' : '')
          . ($r['topic'] ? ' <span class="tag topic">' . h($r['topic']) . '</span>' : '')
          . '<span class="spacer"></span><span class="muted small">' . h($r['set_title']) . ' · câu ' . (int)$r['position'] . ' · ' . h(substr($r['started_at'], 0, 10))
          . ($r['class_rate'] !== null ? ' · ' . round($r['class_rate'] * 100) . ' % lớp làm đúng' : '') . '</span>';
    $n = $notes[$qid] ?? null;
    $extra = '<form method="post" class="note-form"><input type="hidden" name="csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="note"><input type="hidden" name="qid" value="' . $qid . '">'
           . '<label>Nguyên nhân sai <select name="reason"><option value="">— chọn —</option>';
    foreach (REASONS as $k => $lab) $extra .= '<option value="' . $k . '"' . (($n['reason'] ?? '') === $k ? ' selected' : '') . '>' . h($lab) . '</option>';
    $extra .= '</select></label><label>Ghi chú rút kinh nghiệm <input name="note" value="' . h($n['note'] ?? '') . '" placeholder="VD: nhầm QoS 1 với QoS 2"></label><button>Lưu</button>'
           . '<a class="btn small ask-owl" href="tutor.php?mode=explain&qid=' . $qid . '">🦉 Hỏi Cú Học vì sao sai</a></form>';
    echo '<div id="q' . $qid . '"></div>' . question_card($r, $r['answer'], ['meta' => $meta, 'cls' => 'ng', 'open_expl' => true, 'extra' => $extra]);
endforeach; ?>
<?php student_foot();
