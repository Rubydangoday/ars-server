<?php
// Điểm danh. Hai chế độ:
//   attendance.php?session=ID  — một buổi: tự động theo Remote (LOGIN / có câu trả lời), giáo viên sửa Có mặt / Vắng /
//                                 Vắng có phép; lưu xong cập nhật tin trong trang phụ huynh.
//   attendance.php?class=ID    — cả lớp: bảng buổi × học sinh, tỉ lệ đi học; ?format=xlsx|csv để xuất.
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/attendance.php';
require __DIR__ . '/lib/sheet.php';
$t = require_login();
$MARK = ['present' => '✓', 'absent' => '✗', 'excused' => 'P'];

// ------------------------------------------------------------------ một buổi
if (isset($_GET['session'])) {
    $ses = own_session((int)$_GET['session']);
    $sid = (int)$ses['id'];
    if (($_POST['action'] ?? '') === 'save') {
        db()->beginTransaction();
        foreach ((array)($_POST['st'] ?? []) as $seat => $st)
            attendance_set($sid, (int)$seat, (string)$st, (int)$t['id'], (string)($_POST['note'][$seat] ?? ''));
        db()->commit();
        $n = $ses['status'] === 'ended' ? parent_notify_session($sid) : 0;
        q('UPDATE sessions SET cloud_synced_at=NULL WHERE id=?', [$sid]);        // lần Sync to Cloud sau gửi lại điểm danh
        flash(t('Đã lưu điểm danh.') . ($n ? ' ' . t('Đã cập nhật {n} tin cho phụ huynh.', ['n' => $n]) : ''));
        redirect('attendance.php?session=' . $sid);
    }
    $rows = attendance_session($sid);
    $cnt = array_count_values(array_column($rows, 'status')) + ['present' => 0, 'absent' => 0, 'excused' => 0];
    $n_par = (int)qv('SELECT COUNT(*) FROM parent_links WHERE class_id=?', [$ses['class_id']]);
    page_head(t('Điểm danh') . ' — ' . $ses['class_name'], 'sessions.php');
    ?>
    <p class="crumbs"><a href="sessions.php"><?= te('Kết quả') ?></a> › <a href="results.php?id=<?= $sid ?>"><?= h($ses['class_name']) ?> · <?= h($ses['started_at']) ?></a> › <?= te('Điểm danh') ?></p>
    <div class="page-head"><div><h1>📋 <?= te('Điểm danh') ?>: <?= h($ses['set_title']) ?></h1>
      <p class="muted"><?= te('Tự động: học sinh đã đăng nhập bằng Remote hoặc có câu trả lời được nhận trong buổi là có mặt. Sửa khi cần (Remote hỏng, làm bài giấy, vắng có phép).') ?></p></div>
      <a class="btn" href="attendance.php?class=<?= (int)$ses['class_id'] ?>">🗓 <?= te('Điểm danh cả lớp') ?></a></div>
    <div class="ui-kpis">
      <?= ui_kpi('✅', (string)$cnt['present'], t('Có mặt'), '', 'green') ?>
      <?= ui_kpi('⚠️', (string)$cnt['absent'], t('Vắng'), '', 'orange') ?>
      <?= ui_kpi('📝', (string)$cnt['excused'], t('Vắng có phép'), '', 'violet') ?>
      <?= ui_kpi('📨', (string)$n_par, t('Phụ huynh nhận tin'), $ses['status'] === 'ended' ? t('đã gửi khi kết thúc buổi') : t('gửi khi kết thúc buổi'), 'blue') ?>
    </div>
    <section class="card">
    <?php if (!$rows): ?><?= ui_empty('👥', t('Lớp chưa có học sinh'), t('Thêm danh sách học sinh ở trang lớp.')) ?><?php else: ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save">
    <table class="grid compact"><thead><tr><th><?= te('Số') ?></th><th>MSSV</th><th><?= te('Họ tên') ?></th><th><?= te('Điểm danh') ?></th><th><?= te('Ghi chú') ?></th></tr></thead><tbody>
    <?php foreach ($rows as $seat => $r): $auto = $r['source'] === 'auto'; ?>
      <tr><td><?= $seat ?></td><td><?= h($r['student_code']) ?></td><td><?= h($r['full_name']) ?></td>
        <td><select name="st[<?= $seat ?>]">
          <option value="auto" <?= $auto ? 'selected' : '' ?>><?= $auto ? te('Tự động') . ' (' . te(ATTEND_STATUS[$r['status']]) . ')' : te('Tự động (theo Remote)') ?></option>
          <?php foreach (ATTEND_STATUS as $k => $label): ?><option value="<?= $k ?>" <?= !$auto && $r['status'] === $k ? 'selected' : '' ?>><?= te($label) ?></option><?php endforeach; ?>
        </select> <span class="tag <?= ['present' => 'live', 'absent' => 'off', 'excused' => 'info'][$r['status']] ?>"><?= $MARK[$r['status']] ?> <?= te(ATTEND_STATUS[$r['status']]) ?></span></td>
        <td><input name="note[<?= $seat ?>]" value="<?= h($r['note'] ?? '') ?>" maxlength="200" placeholder="<?= te('ví dụ: Remote hỏng, làm bài giấy') ?>"></td></tr>
    <?php endforeach; ?></tbody></table>
    <p><button class="primary"><?= te('Lưu điểm danh') ?></button>
      <span class="muted small"><?= $ses['status'] === 'ended' ? te('Lưu xong, tin trong trang phụ huynh được cập nhật và hiện lại là chưa đọc.') : te('Buổi đang chạy: tin cho phụ huynh được tạo khi bạn bấm Kết thúc buổi.') ?></span></p>
    </form><?php endif; ?>
    </section>
    <?php
    page_foot();
    exit;
}

// ------------------------------------------------------------------ cả lớp
$c = own_class((int)($_GET['class'] ?? 0));
$cid = (int)$c['id'];
$sessions = qall('SELECT s.id, s.started_at, s.kind, qs.title FROM sessions s JOIN question_sets qs ON qs.id=s.set_id WHERE s.class_id=? ORDER BY s.started_at, s.id', [$cid]);
$students = qall('SELECT seat_no, student_code, full_name FROM students WHERE class_id=? ORDER BY seat_no', [$cid]);
$grid = [];
foreach (qall('SELECT af.session_id, af.seat_no, af.status FROM attendance_final af WHERE af.class_id=?', [$cid]) as $r) $grid[(int)$r['seat_no']][(int)$r['session_id']] = $r['status'];
$table = [array_merge([t('Số'), 'MSSV', t('Họ tên')], array_map(fn($s) => date('d/m', strtotime($s['started_at'])) . ' ' . $s['title'], $sessions), [t('Có mặt'), t('Vắng'), t('Vắng có phép'), t('Tỉ lệ có mặt (%)')])];
$sum = [];
foreach ($students as $st) {
    $seat = (int)$st['seat_no'];
    $row = [$seat, $st['student_code'] ?? '', $st['full_name']];
    $k = ['present' => 0, 'absent' => 0, 'excused' => 0];
    foreach ($sessions as $s) { $v = $grid[$seat][(int)$s['id']] ?? 'absent'; $k[$v]++; $row[] = $MARK[$v]; }
    $row = array_merge($row, [$k['present'], $k['absent'], $k['excused'], $sessions ? round(100 * $k['present'] / count($sessions), 1) : '']);
    $sum[$seat] = $k;
    $table[] = $row;
}
$fname = 'ARS_diem_danh_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $c['name']) ?: 'lop');
if (($_GET['format'] ?? '') === 'csv') send_csv($table, $fname . '.csv');
if (($_GET['format'] ?? '') === 'xlsx') send_xlsx(['Diem danh' => $table], $fname . '.xlsx');

page_head(t('Điểm danh') . ' — ' . $c['name'], 'classes.php');
?>
<p class="crumbs"><a href="classes.php"><?= te('Lớp học') ?></a> › <a href="class.php?id=<?= $cid ?>"><?= h($c['name']) ?></a> › <?= te('Điểm danh') ?></p>
<div class="page-head"><div><h1>🗓 <?= te('Điểm danh') ?>: <?= h($c['name']) ?></h1>
  <p class="muted"><?= te('Mỗi buổi học / kiểm tra bằng Remote là một cột. ✓ có mặt · ✗ vắng · P vắng có phép. Bấm tên buổi để sửa.') ?></p></div>
  <div class="row" style="margin:0"><a class="btn" href="attendance.php?class=<?= $cid ?>&format=xlsx">⬇ <?= te('Xuất Excel') ?></a><a class="btn" href="attendance.php?class=<?= $cid ?>&format=csv">⬇ CSV</a></div></div>
<section class="card" style="overflow-x:auto">
<?php if (!$sessions || !$students): ?><?= ui_empty('🗓', t('Chưa có buổi học nào'), t('Bắt đầu một buổi bằng Remote ở trang lớp; điểm danh tự động theo Remote.')) ?><?php else: ?>
<table class="grid compact"><thead><tr><th><?= te('Số') ?></th><th><?= te('Họ tên') ?></th>
  <?php foreach ($sessions as $s): ?><th class="small"><a href="attendance.php?session=<?= (int)$s['id'] ?>" title="<?= h($s['title']) ?>"><?= h(date('d/m', strtotime($s['started_at']))) ?><?= $s['kind'] === 'test' ? ' 📝' : '' ?></a></th><?php endforeach; ?>
  <th><?= te('Có mặt') ?></th></tr></thead><tbody>
<?php foreach ($students as $st): $seat = (int)$st['seat_no']; $k = $sum[$seat]; ?>
  <tr><td><?= $seat ?></td><td><?= h($st['full_name']) ?></td>
  <?php foreach ($sessions as $s): $v = $grid[$seat][(int)$s['id']] ?? 'absent'; ?><td class="<?= ['present' => 'att-ok', 'absent' => 'att-bad', 'excused' => 'muted'][$v] ?>" style="text-align:center"><?= $MARK[$v] ?></td><?php endforeach; ?>
  <td><b><?= $k['present'] ?>/<?= count($sessions) ?></b></td></tr>
<?php endforeach; ?></tbody></table><?php endif; ?>
</section>
<?php page_foot();
