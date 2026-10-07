<?php
// Câu đúng: củng cố lại kiến thức; đánh dấu câu "đúng nhưng ít bạn đúng" và câu trả lời chậm (có thể chưa chắc).
require __DIR__ . '/../lib/lms.php';
$me = require_student();
$code = $me['code'];
$NOTOPIC = '(chưa gắn chủ đề)';
$cls = (int)($_GET['class'] ?? 0) ?: null;
// Câu đúng ở mọi nguồn: Remote trên lớp, luyện tập trên web, bài tập về nhà (trạng thái theo lần làm gần nhất)
$items = array_values(array_filter(latest_by_question(all_items($code, $cls)), fn($r) => $r['status'] === 'right'));
$src = $_GET['src'] ?? '';
if (isset(SOURCE_LABEL[$src])) $items = array_values(array_filter($items, fn($r) => $r['source'] === $src));
$topics = array_count_values(array_map(fn($r) => $r['topic'] ?: $NOTOPIC, $items));
arsort($topics);
if (!empty($_GET['topic'])) $items = array_values(array_filter($items, fn($r) => ($r['topic'] ?: $NOTOPIC) === $_GET['topic']));

// thời gian trả lời trung vị của cả lớp cho từng câu
$median = [];
foreach (array_filter(array_unique(array_column($items, 'sq_id'))) as $sq) {
    $ts = array_map('floatval', array_column(qall('SELECT TIMESTAMPDIFF(MICROSECOND, sq.opened_at, f.received_at)/1e6 t FROM final_answers f '
        . 'JOIN session_questions sq ON sq.id=f.session_q_id WHERE f.session_q_id=?', [$sq]), 't'));
    sort($ts);
    $median[$sq] = $ts ? $ts[intdiv(count($ts), 2)] : null;
}
$classes = my_classes($code);
student_head('Câu đúng', 'right.php');
?>
<div class="page-head"><div><h1>✅ Câu đúng <span class="count-pill"><?= count($items) ?></span></h1><p class="muted">Những câu bạn đã làm đúng — xem lại giải thích để nhớ lâu và hiểu sâu hơn.</p></div></div>
<form class="filters" method="get">
  <?php if (count($classes) > 1): ?><select name="class" onchange="this.form.submit()"><option value="">Mọi lớp</option>
    <?php foreach ($classes as $c): ?><option value="<?= $c['class_id'] ?>" <?= $cls === (int)$c['class_id'] ? 'selected' : '' ?>><?= h($c['class_name']) ?></option><?php endforeach; ?></select><?php endif; ?>
  <select name="topic" onchange="this.form.submit()"><option value="">Mọi chủ đề</option>
    <?php foreach ($topics as $tp => $n): ?><option value="<?= h($tp) ?>" <?= ($_GET['topic'] ?? '') === $tp ? 'selected' : '' ?>><?= h($tp) ?> (<?= $n ?>)</option><?php endforeach; ?></select>
  <select name="src" onchange="this.form.submit()"><option value="">Mọi nguồn</option>
    <?php foreach (SOURCE_LABEL as $k => [$ico, $lab]): ?><option value="<?= $k ?>" <?= $src === $k ? 'selected' : '' ?>><?= $ico ?> <?= h($lab) ?></option><?php endforeach; ?></select>
</form>
<p class="muted small">“Câu khó” = dưới 50 % lớp làm đúng. “Trả lời lâu” = lâu hơn gấp đôi thời gian trung vị của lớp — có thể bạn chưa chắc chắn, nên xem lại phần giải thích.</p>
<?php if (!$items): ?><div class="empty">Chưa có câu đúng nào. Câu đúng ở buổi học bấm Remote (đã công bố), lượt luyện tập đã nộp và bài tập về nhà đã chấm sẽ hiện ở đây.</div><?php endif; ?>
<?php foreach ($items as $r):
    $slow = $r['sq_id'] && ($median[$r['sq_id']] ?? null) && $r['t_answer'] > 2 * $median[$r['sq_id']];
    [$sico, $slab] = SOURCE_LABEL[$r['source']];
    $hard = $r['class_rate'] !== null && $r['class_rate'] < 0.5;
    $meta = '<span class="tag live">đúng</span>' . ($r['n_wrong'] ? ' <span class="tag gold">trước đó sai ' . $r['n_wrong'] . ' lần</span>' : '') . ($hard ? ' <span class="tag gold">câu khó</span>' : '') . ($slow ? ' <span class="tag">trả lời lâu</span>' : '')
          . ($r['topic'] ? ' <span class="tag topic">' . h($r['topic']) . '</span>' : '')
          . ' <span class="tag">' . $sico . ' ' . h($slab) . '</span>'
          . '<span class="spacer"></span><span class="muted small">' . h($r['set_title']) . ' · câu ' . (int)$r['position']
          . ($r['class_rate'] !== null ? ' · ' . round($r['class_rate'] * 100) . ' % lớp đúng' : '') . '</span>';
    echo question_card($r, $r['answer'], ['meta' => $meta, 'cls' => 'ok compact']);
endforeach; ?>
<?php student_foot();
