<?php
// Kết quả một buổi: theo câu (p, D, phương án nhiễu, thời gian) và theo học sinh; xuất Excel.
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/stats.php';
require_once __DIR__ . '/lib/notify.php';
require __DIR__ . '/lib/attendance.php';
require_login();
$ses = own_session((int)($_GET['id'] ?? 0));
if (($_POST['action'] ?? '') === 'notify') {
    if ($ses['status'] !== 'ended' || !$ses['released']) flash(t('Kết thúc buổi và công bố kết quả trước khi báo phụ huynh.'), 'err');
    elseif (!notify_channels()) flash(t('Chưa cấu hình Zalo/SMS. Quản trị viên vào Cloud → Thông báo Zalo / SMS.'), 'err');
    else {
        $new = notify_enqueue_session((int)$ses['id']);
        $r = notify_send_pending(200, [(int)$ses['id']]);
        flash(t('Đã tạo {n} tin mới · gửi được {s}', ['n' => $new, 's' => $r['sent']]) . ($r['failed'] ? ' · ' . t('lỗi {f} (xem Cloud → Thông báo)', ['f' => $r['failed']]) : '') . '.', $r['failed'] ? 'err' : 'ok');
    }
    redirect('results.php?id=' . (int)$ses['id']);
}
if (($_POST['action'] ?? '') === 'release') {
    q('UPDATE sessions SET released=?, cloud_synced_at=NULL WHERE id=?', [(int)($_POST['on'] ?? 1), $ses['id']]);   // lần Sync to Cloud sau gửi lại trạng thái công bố
    parent_notify_session((int)$ses['id']);          // buổi đã kết thúc: tin phụ huynh thêm / bỏ điểm theo trạng thái công bố
    flash((int)($_POST['on'] ?? 1) ? t('Đã công bố: học sinh xem lại được bài này sau khi buổi kết thúc.') : t('Đã ẩn kết quả với học sinh.'));
    redirect('results.php?id=' . (int)$ses['id']);
}
$S = session_stats($ses);
$rej = qall('SELECT reason, COUNT(*) n FROM rejected WHERE session_id=? GROUP BY reason ORDER BY n DESC', [$ses['id']]);
$dup = (int)qv('SELECT COALESCE(SUM(d.pkt_dup),0) FROM devices d JOIN session_logins l ON l.mac=d.mac AND l.session_id=?', [$ses['id']]);
$answered_any = count(array_filter($S['students'], fn($s) => $s['answered'] > 0));

page_head(t('Kết quả') . ' — ' . $ses['class_name'], 'results.php');
?>
<p class="crumbs"><a href="sessions.php"><?= te('Buổi học') ?></a> › <?= h($ses['class_name']) ?> › <?= h($ses['started_at']) ?></p>
<h1><?= te('Kết quả:') ?> <?= h($ses['set_title']) ?></h1>
<div class="row">
  <a class="btn primary" href="export.php?id=<?= (int)$ses['id'] ?>"><?= te('Xuất Excel (.xlsx)') ?></a>
  <a class="btn" href="attendance.php?session=<?= (int)$ses['id'] ?>">📋 <?= te('Điểm danh') ?></a>
  <a class="btn" href="export.php?id=<?= (int)$ses['id'] ?>&format=csv" title="<?= te('Bảng điểm theo học sinh, mở được bằng Excel / Google Sheets') ?>"><?= te('Xuất điểm CSV') ?></a>
  <a class="btn" href="session.php?id=<?= (int)$ses['id'] ?>"><?= te('Bảng điều khiển') ?></a>
  <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="release"><input type="hidden" name="on" value="<?= $ses['released'] ? 0 : 1 ?>">
    <button><?= $ses['released'] ? t('Ẩn kết quả với học sinh') : t('Công bố cho học sinh xem lại') ?></button></form>
  <?php if ($ses['status'] === 'ended' && $ses['released']):
      $n_par = (int)qv('SELECT COUNT(*) FROM parent_links l JOIN parents p ON p.id=l.parent_id WHERE l.class_id=? AND p.notify_channel<>"none" AND p.phone IS NOT NULL', [$ses['class_id']]);
      $n_sent = (int)qv('SELECT COUNT(*) FROM notifications WHERE session_id=? AND status="sent"', [$ses['id']]); ?>
    <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="notify">
      <button title="<?= $n_par ?> phụ huynh đã đăng ký nhận tin"<?= $n_par ? '' : ' disabled' ?>><?= te('📨 Gửi báo cáo Zalo/SMS cho phụ huynh (') ?><?= $n_sent ?>/<?= $n_par ?>)</button></form>
  <?php endif; ?>
  <span class="muted small"><?= $ses['kind'] === 'test' ? t('Bài kiểm tra') : t('Buổi học') ?> <?= te('· học sinh') ?> <?= $ses['released'] ? ($ses['status'] === 'ended' ? t('đang xem lại được') : t('xem lại được khi buổi kết thúc')) : t('chưa xem được') ?></span>
</div>

<div class="kpis">
  <div><b><?= $S['logged'] ?>/<?= $S['n_students'] ?></b><span><?= te('điểm danh') ?></span></div>
  <div><b><?= count($S['questions']) ?></b><span><?= te('câu đã mở') ?></span></div>
  <div><b><?= $S['logged'] ? round($answered_any * 100 / $S['logged']) : 0 ?> %</b><span><?= te('học sinh có mặt đã trả lời ít nhất 1 câu') ?></span></div>
  <div><b><?= array_sum(array_column($rej, 'n')) ?></b><span><?= te('gói bị từ chối') ?></span></div>
</div>

<section class="card">
  <h2><?= te('Theo câu hỏi') ?></h2>
  <table class="grid">
    <thead><tr><th><?= te('Câu') ?></th><th><?= te('Nội dung') ?></th><th><?= te('Trả lời') ?></th><th>Tham gia</th><th><?= te('Độ khó p') ?></th><th><?= te('Độ phân biệt D') ?></th><th><?= te('Thời gian (trung vị)') ?></th><th><?= te('Phương án nhiễu nhiều nhất') ?></th></tr></thead>
    <tbody><?php foreach ($S['questions'] as $r): $q = $r['sq']; $top = $r['distractors'] ? array_key_first($r['distractors']) : null; ?>
      <tr>
        <td><?= (int)$q['position'] ?></td>
        <td class="qtext"><?= h(mb_strimwidth($q['content'], 0, 80, '…')) ?></td>
        <td><?= $r['n'] ?></td>
        <td><?= pct($r['participation']) ?></td>
        <td><?= $q['correct'] ? pct($r['p']) . ' <span class="muted small">' . judge_p($r['p']) . '</span>' : ('<span class="muted">' . t('khảo sát') . '</span>') ?></td>
        <td><?= $q['correct'] ? num($r['D']) . ' <span class="muted small">' . judge_D($r['D']) . '</span>' : '' ?></td>
        <td><?= $r['median_t'] !== null ? num($r['median_t'], 1) . ' s' : '—' ?></td>
        <td><?= $top !== null && $r['distractors'][$top] > 0 ? h($top) . ' (' . pct($r['distractors'][$top]) . ')' : '—' ?></td>
      </tr>
    <?php endforeach; ?></tbody>
  </table>
  <p class="muted small">p = số trả lời đúng / số trả lời. D = p của 27 % học sinh điểm cao − p của 27 % điểm thấp (D ≥ 0,4 tốt; 0,2–0,4 được; &lt; 0,2 nên xem lại câu). Thời gian tính từ lúc mở câu tới lần gửi cuối của mỗi học sinh.</p>
</section>

<?php $topics = [];
foreach ($S['questions'] as $r) if ($r['sq']['correct'] && $r['n']) {
    $tp = $r['sq']['topic'] ?: t('(chưa gắn chủ đề)');
    $topics[$tp]['n'] = ($topics[$tp]['n'] ?? 0) + $r['n'];
    $topics[$tp]['ok'] = ($topics[$tp]['ok'] ?? 0) + $r['dist'][$r['sq']['correct']];
    $topics[$tp]['q'][] = (int)$r['sq']['position'];
}
uasort($topics, fn($a, $b) => $a['ok'] / $a['n'] <=> $b['ok'] / $b['n']);
if ($topics): ?>
<section class="card">
  <h2><?= te('Theo chủ đề') ?></h2>
  <table class="grid compact"><thead><tr><th><?= te('Chủ đề') ?></th><th><?= te('Câu') ?></th><th><?= te('Tỉ lệ đúng') ?></th><th></th></tr></thead><tbody>
  <?php foreach ($topics as $tp => $x): $r = $x['ok'] / $x['n']; ?>
    <tr><td><?= h($tp) ?></td><td><?= implode(', ', $x['q']) ?></td><td><?= pct($r) ?></td><td><?= $r < 0.5 ? ('<span class="tag off">' . t('cần dạy lại') . '</span>') : ($r < 0.7 ? ('<span class="tag">' . t('cần ôn thêm') . '</span>') : '') ?></td></tr>
  <?php endforeach; ?></tbody></table>
</section>
<?php endif; ?>

<?php foreach ($S['questions'] as $r): $q = $r['sq']; ?>
<section class="card qresult">
  <h3><?= te('Câu') ?> <?= (int)$q['position'] ?>: <span class="qtext"><?= h($q['content']) ?></span></h3>
  <div class="cols">
    <ol class="opts"><?php foreach (options_of($q) as $L => $o): ?><li class="<?= $L === $q['correct'] ? 'correct' : '' ?>"><b><?= $L ?>.</b> <?= h($o) ?></li><?php endforeach; ?></ol>
    <?= bar_svg($r['dist'], $q['correct'] ?: null) ?>
  </div>
</section>
<?php endforeach; ?>

<section class="card">
  <h2><?= te('Theo học sinh') ?></h2>
  <table class="grid compact">
    <thead><tr><th><?= te('Số') ?></th><th>MSSV</th><th><?= te('Họ tên') ?></th><th><?= te('Có mặt') ?></th><th><?= te('Đã trả lời') ?></th><th><?= te('Đúng') ?></th><th><?= te('Điểm (thang 10)') ?></th>
      <?php foreach ($S['questions'] as $r): ?><th class="c">C<?= (int)$r['sq']['position'] ?></th><?php endforeach; ?></tr></thead>
    <tbody><?php foreach ($S['students'] as $s): ?>
      <tr class="<?= $s['present'] ? '' : 'dim' ?>"><td><?= (int)$s['seat_no'] ?></td><td><?= h($s['student_code']) ?></td><td><?= h($s['full_name']) ?></td>
        <td><?= $s['present'] ? '✓' : t('vắng') ?></td><td><?= $s['answered'] ?>/<?= $s['n_q'] ?></td><td><?= $s['correct'] ?>/<?= $s['n_graded'] ?></td>
        <td><?= $s['n_graded'] ? num($s['correct'] * 10 / $s['n_graded'], 1) : '—' ?></td>
        <?php foreach ($S['questions'] as $i => $r): $a = $s['answers'][$i]; $ok = $r['sq']['correct'] && $a === $r['sq']['correct']; ?>
          <td class="c <?= $a === '' ? '' : ($r['sq']['correct'] ? ($ok ? 'right-a' : 'wrong-a') : '') ?>"><?= h($a ?: '·') ?></td>
        <?php endforeach; ?></tr>
    <?php endforeach; ?></tbody>
  </table>
</section>

<section class="card">
  <h2><?= te('Chất lượng đường truyền') ?></h2>
  <p><?= te('Gói trùng đã loại (mỗi lần bấm OK clicker phát 5 lần):') ?> <b><?= $dup ?></b>.</p>
  <?php if ($rej): ?><table class="grid compact"><thead><tr><th><?= te('Lý do từ chối') ?></th><th><?= te('Số gói') ?></th></tr></thead><tbody>
    <?php foreach ($rej as $x): ?><tr><td><?= h(REJECT_REASONS[$x['reason']] ?? $x['reason']) ?></td><td><?= (int)$x['n'] ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
</section>
<?php page_foot();
