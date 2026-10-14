<?php
// Kết quả: tất cả phiên trả lời bằng clicker của giáo viên — chỉ số tổng, biểu đồ tỉ lệ đúng, lọc theo lớp.
require __DIR__ . '/lib/bootstrap.php';
$t = require_login();
$cls = (int)($_GET['class'] ?? 0);
$rows = qall('SELECT s.*, c.name AS class_name, c.room_id, qs.title, l.no AS lesson_no, l.id AS lesson_id2, '
           . '(SELECT COUNT(*) FROM students st WHERE st.class_id=s.class_id) AS n_students, '
           . '(SELECT COUNT(*) FROM attendance_final af WHERE af.session_id=s.id AND af.status="present") AS n_logged, '
           . '(SELECT COUNT(*) FROM session_questions sq WHERE sq.session_id=s.id) AS n_q, '
           . '(SELECT COUNT(*) FROM session_questions sq JOIN questions q ON q.id=sq.question_id WHERE sq.session_id=s.id AND q.correct IS NOT NULL AND q.correct<>"") AS n_scored, '
           . '(SELECT COUNT(*) FROM final_answers f JOIN session_questions sq ON sq.id=f.session_q_id JOIN questions q ON q.id=sq.question_id WHERE sq.session_id=s.id AND f.answer=q.correct) AS n_ok '
           . 'FROM sessions s JOIN classes c ON c.id=s.class_id JOIN question_sets qs ON qs.id=s.set_id LEFT JOIN lessons l ON l.id=s.lesson_id '
           . 'WHERE c.teacher_id=? ORDER BY s.id DESC', [$t['id']]);
$classes = [];
foreach ($rows as $r) $classes[(int)$r['class_id']] = $r['class_name'];
if ($cls && isset($classes[$cls])) $rows = array_values(array_filter($rows, fn($r) => (int)$r['class_id'] === $cls)); else $cls = 0;
$rate = fn($r) => $r['n_logged'] && $r['n_scored'] ? 100 * $r['n_ok'] / ($r['n_logged'] * $r['n_scored']) : null;
$attp = fn($r) => $r['n_students'] ? 100 * $r['n_logged'] / $r['n_students'] : 0;
$rates = array_values(array_filter(array_map($rate, $rows), fn($v) => $v !== null));
$running = count(array_filter($rows, fn($r) => $r['status'] === 'running'));
$series = [];
foreach (array_reverse(array_slice($rows, 0, 14)) as $r) if (($v = $rate($r)) !== null) $series[date('d/m', strtotime($r['started_at'])) . ' #' . (int)$r['id']] = $v;
$series2 = []; foreach ($series as $k => $v) $series2[preg_replace('/ #\d+$/', '', $k)] = $v;   // nhãn gọn (trùng ngày: giữ buổi cuối)
page_head('Lịch sử', 'sessions.php');
?>
<div class="page-head"><div><h1>📊 Kết quả</h1><p class="muted">Mỗi lần bấm <b>Bắt đầu</b> ở một lớp là một phiên trả lời bằng clicker. Bấm vào phiên để xem từng câu, từng học sinh.</p></div>
  <a class="btn primary" href="classes.php">▶ Bắt đầu buổi mới</a></div>
<nav class="subtabs"><a class="on" href="sessions.php">🗓 Phiên trả lời clicker</a><a href="practice_log.php">📝 Luyện tập của học sinh</a></nav>

<?php if (!$rows && !$cls): ?>
  <div class="card"><?= ui_empty('🗓', t('Chưa có buổi học.'), t('Vào một lớp và bấm Bắt đầu — kết quả từng câu sẽ hiện ở đây ngay khi học sinh bấm Remote.'), '<a class="btn primary" href="classes.php">' . te('Đến Lớp học') . '</a>') ?></div>
<?php page_foot(); exit; endif; ?>

<div class="ui-kpis">
  <?= ui_kpi('🗓', (string)count($rows), t('Phiên trả lời'), $running ? te('{n} đang diễn ra', ['n' => $running]) : '', 'blue') ?>
  <?= ui_kpi('🎯', $rates ? round(array_sum($rates) / count($rates)) . '%' : '—', t('Tỉ lệ đúng trung bình'), $rates ? te('trên {n} phiên có câu chấm điểm', ['n' => count($rates)]) : '', 'green') ?>
  <?= ui_kpi('🙋', $rows ? round(array_sum(array_map($attp, $rows)) / count($rows)) . '%' : '—', t('Điểm danh trung bình'), '', 'orange') ?>
  <?= ui_kpi('❓', (string)array_sum(array_column($rows, 'n_q')), t('Câu hỏi đã mở'), te('{n} lớp', ['n' => count($classes)]), 'violet') ?>
</div>

<?php if (count($series2) >= 2): ?>
<section class="card"><?= ui_card_head('📈 ' . te('Tỉ lệ đúng qua các phiên gần đây'), t('Phần trăm câu trả lời đúng của học sinh có mặt.')) ?>
  <div class="ui-chart-wrap tone-blue"><?= ui_line_chart($series2, 100, t('Tỉ lệ đúng'), '%') ?></div></section>
<?php endif; ?>

<section class="card flush">
  <div class="card-head">
    <nav class="pill-tabs mini"><a href="sessions.php" class="<?= $cls ? '' : 'on' ?>"><?= te('Tất cả lớp') ?></a>
      <?php foreach ($classes as $id => $n): ?><a href="sessions.php?class=<?= $id ?>" class="<?= $cls === $id ? 'on' : '' ?>"><?= h($n) ?></a><?php endforeach; ?></nav>
    <input type="search" class="tbl-filter" placeholder="🔎 Tìm theo lớp, bộ câu hỏi…" data-filter="#ses-table"></div>
  <table class="data" id="ses-table">
    <thead><tr><th>Thời gian</th><th>Lớp · bộ câu hỏi</th><th>Điểm danh</th><th>Câu</th><th class="right">Tỉ lệ đúng</th><th>Trạng thái</th><th></th></tr></thead>
    <tbody><?php foreach ($rows as $s): $rt = $rate($s); $ap = $attp($s); ?>
      <tr>
        <td class="nowrap"><div class="date-chip"><b><?= h(date('d', strtotime($s['started_at']))) ?></b><span><?= h(date('m/Y', strtotime($s['started_at']))) ?></span></div><small class="muted"><?= h(date('H:i', strtotime($s['started_at']))) ?></small></td>
        <td><a class="row-title" href="results.php?id=<?= (int)$s['id'] ?>"><?= h($s['title']) ?></a>
          <small class="muted"><?= h($s['class_name']) ?><?= $s['lesson_no'] ? ' · <a href="lesson.php?id=' . (int)$s['lesson_id2'] . '">' . te('Buổi {n}', ['n' => (int)$s['lesson_no']]) . '</a>' : '' ?><?= $s['kind'] === 'test' ? ' · <span class="tag warn">' . te('kiểm tra') . '</span>' : '' ?></small></td>
        <td style="min-width:130px"><b><?= (int)$s['n_logged'] ?></b><span class="muted">/<?= (int)$s['n_students'] ?></span><div class="meter-sm"><span style="width:<?= round(min(100, $ap)) ?>%"></span></div></td>
        <td><?= (int)$s['n_q'] ?></td>
        <td class="right"><?= $rt === null ? '<span class="ui-score none">—</span>' : ui_score($rt / 10) . '<small class="muted">' . round($rt) . '%</small>' ?></td>
        <td><?= $s['status'] === 'running' ? '<span class="tag ok"><span class="dot-live"></span>' . te('đang học') . '</span>' : '<span class="tag">' . te('đã kết thúc') . '</span>' ?></td>
        <td class="actions"><?php if ($s['status'] === 'running'): ?><a class="btn small primary" href="session.php?id=<?= (int)$s['id'] ?>"><?= te('Điều khiển') ?></a> <?php endif; ?><a class="btn small" href="results.php?id=<?= (int)$s['id'] ?>"><?= te('Kết quả') ?></a></td>
      </tr>
    <?php endforeach; ?></tbody>
  </table>
</section>
<?php page_foot();
