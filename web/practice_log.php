<?php
// Lịch sử luyện tập của học sinh trên web (bài được giao, ôn câu sai, ôn chủ đề, câu hỏi củng cố, làm lại buổi)
// + câu hỏi luyện với trợ lý AI. Xem theo lớp / học sinh / thời gian, mở chi tiết từng lượt, xuất Excel.
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/sheet.php';
$t = require_login();
const SOURCE_LABEL = ['assignment' => 'Bài được giao', 'wrong' => 'Ôn câu sai', 'topic' => 'Ôn theo chủ đề', 'session' => 'Làm lại buổi', 'lesson' => 'Câu hỏi củng cố'];

$classes = qall('SELECT id, name FROM classes WHERE teacher_id=? ORDER BY name', [$t['id']]);
$cids = array_map('intval', array_column($classes, 'id')) ?: [0];
$in = implode(',', $cids);

// ---------------------------------------------------------------- chi tiết một lượt
if (!empty($_GET['a'])) {
    $a = q1("SELECT a.*, c.name AS class_name, st.full_name, st.seat_no FROM practice_attempts a JOIN classes c ON c.id=a.class_id "
          . "LEFT JOIN students st ON st.class_id=a.class_id AND st.student_code=a.student_code WHERE a.id=? AND a.class_id IN ($in)", [(int)$_GET['a']]);
    if (!$a) { http_response_code(404); exit('Không tìm thấy lượt luyện tập.'); }
    $items = qall('SELECT i.*, q.content, q.correct, q.topic, q.n_options, q.opt_a,q.opt_b,q.opt_c,q.opt_d,q.opt_e,q.opt_f,q.opt_g,q.opt_h,q.opt_i,q.opt_j '
                . 'FROM practice_items i JOIN questions q ON q.id=i.question_id WHERE i.attempt_id=? ORDER BY i.round, i.ord', [$a['id']]);
    $retries = [];
    foreach ($items as $it) if ($it['round'] > 1) $retries[$it['question_id']][] = $it;
    $first = array_values(array_filter($items, fn($i) => (int)$i['round'] === 1));
    $wrong = count(array_filter($first, fn($i) => $i['answer'] !== null && !$i['is_correct']));
    $blank = count(array_filter($first, fn($i) => $i['answer'] === null));
    page_head('Chi tiết luyện tập', 'sessions.php');
    ?>
    <p class="crumbs"><a href="practice_log.php">Luyện tập của học sinh</a> › <?= h($a['full_name'] ?? $a['student_code']) ?></p>
    <div class="page-head"><div><h1><?= h($a['title']) ?></h1>
      <p class="muted"><?= h($a['full_name'] ?? '') ?> · MSSV <?= h($a['student_code']) ?> · <?= h($a['class_name']) ?> · <?= h(SOURCE_LABEL[$a['source']] ?? $a['source']) ?>
        · <?= $a['mode'] === 'exam' ? 'làm như kiểm tra' : 'luyện tập' ?> · bắt đầu <?= h(date('d/m/Y H:i', strtotime($a['started_at']))) ?></p></div>
      <a class="btn" href="practice_log.php?code=<?= urlencode($a['student_code']) ?>">Mọi lượt của học sinh này</a></div>
    <div class="stat-row">
      <?php $okn = count(array_filter($first, fn($i) => (int)$i['is_correct'] === 1)); ?>
      <div class="stat"><span>Đúng ngay lần đầu</span><b><?= $okn ?>/<?= (int)$a['n_questions'] ?></b><small><?= $a['n_questions'] ? round($okn * 100 / $a['n_questions']) : 0 ?> %</small></div>
      <div class="stat"><span>Câu sai</span><b style="color:var(--bad)"><?= $wrong ?></b><small><?= $blank ? $blank . ' câu chưa làm' : '&nbsp;' ?></small></div>
      <div class="stat"><span>Lượt hỏi lại</span><b><?= count($items) - count($first) ?></b><small>câu sai được hỏi lại tới khi đúng</small></div>
      <div class="stat"><span>Trạng thái</span><b style="font-size:18px"><?= $a['finished_at'] ? 'Đã hoàn thành' : 'Đang làm' ?></b><small><?= $a['finished_at'] ? h(date('d/m H:i', strtotime($a['finished_at']))) : '' ?></small></div>
    </div>
    <section class="card">
      <table class="grid compact">
        <thead><tr><th>#</th><th>Câu hỏi</th><th>Chủ đề</th><th>Học sinh chọn</th><th>Đáp án</th><th>Kết quả</th><th>Làm lại</th></tr></thead>
        <tbody><?php foreach ($first as $i => $it): $o = options_of($it); $rt = $retries[$it['question_id']] ?? []; ?>
          <tr class="<?= $it['answer'] !== null && !$it['is_correct'] ? 'bad' : '' ?>"><td><?= $i + 1 ?></td><td><?= h(mb_strimwidth($it['content'], 0, 140, '…')) ?></td><td class="small"><?= h($it['topic']) ?></td>
            <td><?= $it['answer'] !== null ? '<b>' . h($it['answer']) . '.</b> ' . h(mb_strimwidth($o[$it['answer']] ?? '', 0, 50, '…')) : '<span class="muted">chưa làm</span>' ?></td>
            <td><b><?= h($it['correct']) ?>.</b> <?= h(mb_strimwidth($o[$it['correct']] ?? '', 0, 50, '…')) ?></td>
            <td><?= $it['answer'] === null ? '—' : ($it['is_correct'] ? '<span class="pill-ok">✓ đúng</span>' : '<span style="color:var(--bad);font-weight:600">✗ sai</span>') ?></td>
            <td class="small"><?= $rt ? implode(' → ', array_map(fn($r) => ($r['answer'] ?? '·') . ($r['is_correct'] ? ' ✓' : ($r['answer'] !== null ? ' ✗' : '')), $rt)) : '' ?></td></tr>
        <?php endforeach; ?></tbody>
      </table>
    </section>
    <?php page_foot(); exit;
}

// ---------------------------------------------------------------- danh sách
$f = ['class' => (int)($_GET['class'] ?? 0), 'code' => trim($_GET['code'] ?? ''), 'src' => $_GET['src'] ?? '', 'days' => (int)($_GET['days'] ?? 30)];
$where = ["a.class_id IN ($in)"]; $args = [];
if ($f['class'] && in_array($f['class'], $cids, true)) { $where[] = 'a.class_id=?'; $args[] = $f['class']; }
if ($f['code'] !== '') { $where[] = '(a.student_code LIKE ? OR st.full_name LIKE ?)'; $args[] = '%' . $f['code'] . '%'; $args[] = '%' . $f['code'] . '%'; }
if (isset(SOURCE_LABEL[$f['src']])) { $where[] = 'a.source=?'; $args[] = $f['src']; }
if ($f['days'] > 0) { $where[] = 'a.started_at > NOW() - INTERVAL ' . $f['days'] . ' DAY'; }
$W = implode(' AND ', $where);
$rows = qall("SELECT a.*, c.name AS class_name, st.full_name, st.seat_no, "
    . "(SELECT COUNT(*) FROM practice_items i WHERE i.attempt_id=a.id AND i.round=1 AND i.answer IS NOT NULL AND i.is_correct=0) AS n_wrong, "
    . "(SELECT COUNT(*) FROM practice_items i WHERE i.attempt_id=a.id AND i.round=1 AND i.answer IS NOT NULL) AS n_done, "
    . "(SELECT COUNT(*) FROM practice_items i WHERE i.attempt_id=a.id AND i.round>1) AS n_retry, "
    . "(SELECT COUNT(*) FROM practice_items i WHERE i.attempt_id=a.id AND i.round=1 AND i.is_correct=1) AS n_ok "
    . "FROM practice_attempts a JOIN classes c ON c.id=a.class_id LEFT JOIN students st ON st.class_id=a.class_id AND st.student_code=a.student_code "
    . "WHERE $W ORDER BY a.id DESC LIMIT 500", $args);

// tổng hợp theo học sinh (gồm cả câu luyện với trợ lý AI)
$by = [];
foreach ($rows as $r) {
    $k = $r['student_code'];
    $by[$k] ??= ['code' => $k, 'name' => $r['full_name'], 'class' => $r['class_name'], 'n' => 0, 'done' => 0, 'ok' => 0, 'wrong' => 0, 'last' => $r['started_at'], 'tq' => 0, 'tok' => 0];
    $by[$k]['n']++; $by[$k]['done'] += $r['n_done']; $by[$k]['ok'] += $r['n_ok']; $by[$k]['wrong'] += $r['n_wrong'];
}
// học sinh chỉ luyện với trợ lý AI (chưa có lượt luyện tập) cũng được liệt kê
if (!isset(SOURCE_LABEL[$f['src']])) {
    $tw = ["st.class_id IN ($in)"]; $ta = [];
    if ($f['class'] && in_array($f['class'], $cids, true)) { $tw[] = 'st.class_id=?'; $ta[] = $f['class']; }
    if ($f['code'] !== '') { $tw[] = '(st.student_code LIKE ? OR st.full_name LIKE ?)'; $ta[] = '%' . $f['code'] . '%'; $ta[] = '%' . $f['code'] . '%'; }
    foreach (qall('SELECT DISTINCT st.student_code, st.full_name, c.name AS class_name FROM tutor_answers x JOIN students st ON st.student_code=x.student_code '
                . 'JOIN classes c ON c.id=st.class_id WHERE ' . implode(' AND ', $tw) . ($f['days'] > 0 ? ' AND x.answered_at > NOW() - INTERVAL ' . $f['days'] . ' DAY' : ''), $ta) as $x)
        $by[$x['student_code']] ??= ['code' => $x['student_code'], 'name' => $x['full_name'], 'class' => $x['class_name'], 'n' => 0, 'done' => 0, 'ok' => 0, 'wrong' => 0, 'last' => null, 'tq' => 0, 'tok' => 0];
}
if ($by) {
    $codes = array_keys($by);
    $ph = implode(',', array_fill(0, count($codes), '?'));
    foreach (qall("SELECT student_code, COUNT(*) n, SUM(is_correct) ok FROM tutor_answers WHERE student_code IN ($ph)"
                  . ($f['days'] > 0 ? ' AND answered_at > NOW() - INTERVAL ' . $f['days'] . ' DAY' : '') . ' GROUP BY student_code', $codes) as $x) {
        $by[$x['student_code']]['tq'] = (int)$x['n']; $by[$x['student_code']]['tok'] = (int)$x['ok'];
    }
}
uasort($by, fn($a, $b) => $b['wrong'] <=> $a['wrong']);

if (($_GET['export'] ?? '') === 'xlsx') {
    $sheet1 = [['Thời gian', 'MSSV', 'Họ tên', 'Lớp', 'Bài', 'Loại', 'Chế độ', 'Số câu', 'Đã làm', 'Đúng lần đầu', 'Sai', 'Hỏi lại', 'Hoàn thành']];
    foreach ($rows as $r) $sheet1[] = [$r['started_at'], $r['student_code'], $r['full_name'], $r['class_name'], $r['title'], SOURCE_LABEL[$r['source']] ?? $r['source'],
        $r['mode'] === 'exam' ? 'kiểm tra' : 'luyện tập', (int)$r['n_questions'], (int)$r['n_done'], (int)$r['n_ok'], (int)$r['n_wrong'], (int)$r['n_retry'], $r['finished_at'] ?? ''];
    $sheet2 = [['MSSV', 'Họ tên', 'Lớp', 'Số lượt', 'Câu đã làm', 'Đúng lần đầu', 'Sai', 'Tỉ lệ đúng %', 'Câu luyện với trợ lý AI', 'Đúng với trợ lý']];
    foreach ($by as $s) $sheet2[] = [$s['code'], $s['name'], $s['class'], $s['n'], $s['done'], $s['ok'], $s['wrong'], $s['done'] ? round($s['ok'] * 100 / $s['done']) : 0, $s['tq'], $s['tok']];
    send_xlsx(['Luot luyen tap' => $sheet1, 'Theo hoc sinh' => $sheet2], 'luyen_tap_hoc_sinh_' . date('Ymd') . '.xlsx');
}

$tot = ['n' => count($rows), 'hs' => count($by), 'done' => array_sum(array_column($rows, 'n_done')), 'ok' => array_sum(array_column($rows, 'n_ok')), 'wrong' => array_sum(array_column($rows, 'n_wrong'))];
page_head('Luyện tập của học sinh', 'sessions.php');
?>
<div class="page-head"><div><h1>📝 Luyện tập của học sinh</h1><p class="muted">Học sinh luyện tập trên web: bài giáo viên giao, ôn câu sai, ôn theo chủ đề, câu hỏi củng cố cuối buổi và câu hỏi với trợ lý AI.</p></div>
  <a class="btn" href="?<?= h(http_build_query($f + ['export' => 'xlsx'])) ?>">⬇ Xuất Excel</a></div>
<nav class="subtabs"><a href="sessions.php">🗓 Phiên trả lời clicker</a><a class="on" href="practice_log.php">📝 Luyện tập của học sinh</a></nav>

<form method="get" class="filters">
  <select name="class" onchange="this.form.submit()"><option value="0">Tất cả lớp</option><?php foreach ($classes as $c): ?><option value="<?= $c['id'] ?>" <?= $f['class'] === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?></option><?php endforeach; ?></select>
  <select name="src" onchange="this.form.submit()"><option value="">Mọi loại bài</option><?php foreach (SOURCE_LABEL as $k => $v): ?><option value="<?= $k ?>" <?= $f['src'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select>
  <select name="days" onchange="this.form.submit()"><?php foreach ([7 => '7 ngày', 30 => '30 ngày', 90 => '90 ngày', 0 => 'Toàn bộ'] as $k => $v): ?><option value="<?= $k ?>" <?= $f['days'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select>
  <input name="code" value="<?= h($f['code']) ?>" placeholder="MSSV hoặc tên học sinh"><button>Lọc</button>
  <?php if ($f['code'] !== '' || $f['class'] || $f['src']): ?><a href="practice_log.php">Bỏ lọc</a><?php endif; ?>
</form>

<div class="stat-row">
  <div class="stat"><span>Lượt luyện tập</span><b><?= $tot['n'] ?></b><small><?= $tot['hs'] ?> học sinh</small></div>
  <div class="stat"><span>Câu đã làm</span><b><?= $tot['done'] ?></b><small>lần đầu mỗi câu</small></div>
  <div class="stat"><span>Tỉ lệ đúng lần đầu</span><b><?= $tot['done'] ? round($tot['ok'] * 100 / $tot['done']) : 0 ?> %</b><small><?= $tot['ok'] ?> câu đúng</small></div>
  <div class="stat"><span>Câu sai</span><b style="color:var(--bad)"><?= $tot['wrong'] ?></b><small>cần xem lại</small></div>
</div>

<?php if (!$rows && !$by): ?><div class="empty">Chưa có lượt luyện tập nào trong khoảng này.</div><?php else: ?>
<div class="cols">
<section class="card">
  <h2>Theo học sinh <span class="muted small">(nhiều câu sai trước)</span></h2>
  <table class="grid compact">
    <thead><tr><th>Học sinh</th><th>Lượt</th><th>Đúng</th><th>Sai</th><th>Trợ lý AI</th></tr></thead>
    <tbody><?php foreach ($by as $s): $pct = $s['done'] ? round($s['ok'] * 100 / $s['done']) : 0; ?>
      <tr><td><a href="?code=<?= urlencode($s['code']) ?>&days=<?= $f['days'] ?>"><b><?= h($s['name'] ?? $s['code']) ?></b></a><br><span class="small muted"><?= h($s['code']) ?> · <?= h($s['class']) ?></span></td>
        <td><?= $s['n'] ?></td>
        <td><?= $pct ?> %<div class="meter <?= $pct < 50 ? 'bad' : ($pct < 80 ? 'mid' : '') ?>" style="width:70px"><span style="width:<?= $pct ?>%"></span></div></td>
        <td style="color:var(--bad);font-weight:700"><?= $s['wrong'] ?></td>
        <td class="small"><?= $s['tq'] ? $s['tok'] . '/' . $s['tq'] . ' đúng' : '—' ?></td></tr>
    <?php endforeach; ?></tbody>
  </table>
</section>
<section class="card">
  <h2>Từng lượt làm bài</h2>
  <table class="grid compact">
    <thead><tr><th>Thời gian</th><th>Học sinh · bài</th><th>Đúng</th><th>Sai</th><th></th></tr></thead>
    <tbody><?php foreach ($rows as $r): ?>
      <tr><td class="small"><?= h(date('d/m H:i', strtotime($r['started_at']))) ?></td>
        <td><b><?= h($r['full_name'] ?? $r['student_code']) ?></b> <span class="small muted"><?= h($r['student_code']) ?></span><br>
          <span class="small"><?= h($r['title']) ?></span> <span class="tag"><?= h(SOURCE_LABEL[$r['source']] ?? $r['source']) ?></span><?= $r['mode'] === 'exam' ? ' <span class="tag blue">kiểm tra</span>' : '' ?><?= $r['finished_at'] ? '' : ' <span class="tag warn">đang làm</span>' ?></td>
        <td><?= (int)$r['n_ok'] ?>/<?= (int)$r['n_questions'] ?></td>
        <td style="color:var(--bad);font-weight:700"><?= (int)$r['n_wrong'] ?: '' ?></td>
        <td class="right"><a href="?a=<?= $r['id'] ?>">Chi tiết</a></td></tr>
    <?php endforeach; ?></tbody>
  </table>
</section>
</div>
<?php endif; ?>
<?php page_foot();
