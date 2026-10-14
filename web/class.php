<?php
// Một lớp: sửa thông tin, danh sách học sinh (thêm/sửa/xoá/nhập file), bắt đầu buổi học.
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/sheet.php';
require __DIR__ . '/lib/attendance.php';
require __DIR__ . '/lib/roster.php';
require_once __DIR__ . '/lib/notify.php';
$t = require_login();
$c = own_class((int)($_GET['id'] ?? 0));
$cid = (int)$c['id'];
$act = $_POST['action'] ?? '';

if ($act === 'update') {
    $name = trim($_POST['name'] ?? ''); $room = (int)($_POST['room_id'] ?? 0);
    if ($name === '' || $room < 1 || $room > 255) flash(t('Tên lớp và mã lớp 1–255.'), 'err');
    elseif ($o = room_taken($room, $cid)) flash(t('Mã lớp {r} đang được dùng cho lớp “{c}” của {t}. Chọn mã khác.', ['r' => $room, 'c' => $o['name'], 't' => $o['teacher']]), 'err');
    else {
        q('UPDATE classes SET name=?, room_id=?, subject=?, cohort=? WHERE id=?', [$name, $room, mb_substr(trim($_POST['subject'] ?? ''), 0, 60) ?: null,
          mb_substr(trim($_POST['cohort'] ?? ''), 0, 30) ?: null, $cid]);
        flash(t('Đã lưu.'));
    }
    redirect("class.php?id=$cid");
}
if ($act === 'add_student' || $act === 'edit_student') {
    $seat = (int)($_POST['seat_no'] ?? 0); $name = trim($_POST['full_name'] ?? ''); $code = trim($_POST['student_code'] ?? '');
    $ph_in = trim($_POST['parent_phone'] ?? ''); $phone = normalize_phone($ph_in);
    if ($seat < 1 || $seat > 99 || $name === '') flash(t('Số thứ tự 1–99 và họ tên là bắt buộc.'), 'err');
    elseif ($ph_in !== '' && !$phone) flash(t('SĐT phụ huynh không hợp lệ (10 số, VD 0912345678).'), 'err');
    else {
        q('INSERT INTO students (class_id, seat_no, student_code, full_name, parent_phone) VALUES (?,?,?,?,?) '
        . 'ON DUPLICATE KEY UPDATE student_code=VALUES(student_code), full_name=VALUES(full_name), parent_phone=VALUES(parent_phone)', [$cid, $seat, $code ?: null, $name, $phone]);
        flash(t('Đã lưu học sinh số {n}.', ['n' => $seat]));
    }
    redirect("class.php?id=$cid");
}
if ($act === 'delete_student') {
    q('DELETE FROM students WHERE class_id=? AND seat_no=?', [$cid, (int)$_POST['seat_no']]);
    redirect("class.php?id=$cid");
}
if ($act === 'delete_class') {
    if (q1('SELECT 1 FROM sessions WHERE class_id=?', [$cid]) && ($_POST['confirm'] ?? '') !== 'XOA') {
        flash(t('Lớp đã có buổi học. Gõ XOA vào ô xác nhận để xoá cả kết quả.'), 'err'); redirect("class.php?id=$cid");
    }
    q('DELETE FROM classes WHERE id=?', [$cid]);
    flash(t('Đã xoá lớp.')); redirect('classes.php');
}
if ($act === 'start_session') {
    $set = own_set((int)($_POST['set_id'] ?? 0));
    if ($busy = q1('SELECT c.name, t.full_name FROM sessions s JOIN classes c ON c.id=s.class_id JOIN teachers t ON t.id=c.teacher_id '
                 . 'WHERE s.status="running" AND c.room_id=? AND c.id<>?', [$c['room_id'], $cid])) {
        flash(t('Mã lớp (room) {r} đang chạy buổi học của lớp “{c}” ({t}). Đổi mã lớp ở “Thông tin lớp” rồi bắt đầu lại.', ['r' => $c['room_id'], 'c' => $busy['name'], 't' => $busy['full_name']]), 'err');
        redirect("class.php?id=$cid");
    }
    q('UPDATE classes SET conn_mode="ble" WHERE id=? AND conn_mode<>"ble"', [$cid]);   // chỉ dùng Remote nRF52840 (Bluetooth)
    if (!q1('SELECT 1 FROM questions WHERE set_id=?', [$set['id']])) { flash(t('Bộ câu hỏi chưa có câu nào.'), 'err'); redirect("class.php?id=$cid"); }
    $was_running = array_column(qall('SELECT id FROM sessions WHERE class_id=? AND status="running"', [$cid]), 'id');
    q('UPDATE sessions SET status="ended", ended_at=NOW() WHERE class_id=? AND status="running"', [$cid]);
    foreach ($was_running as $old) parent_notify_session((int)$old);     // buổi cũ tự kết thúc: vẫn báo phụ huynh
    $kind = ($_POST['kind'] ?? 'class') === 'test' ? 'test' : 'class';
    // bài kiểm tra: học sinh chỉ xem lại được khi GV bấm "Công bố" ở trang kết quả
    $lid = (int)($_POST['lesson_id'] ?? 0);
    $lid = $lid && q1('SELECT 1 FROM lessons WHERE id=? AND class_id=?', [$lid, $cid]) ? $lid : null;
    q('INSERT INTO sessions (class_id, set_id, lesson_id, kind, allow_change, released) VALUES (?,?,?,?,?,?)',
      [$cid, $set['id'], $lid, $kind, isset($_POST['allow_change']) ? 1 : 0, $kind === 'test' ? 0 : 1]);
    redirect('session.php?id=' . db()->lastInsertId());
}

if ($act === 'assign') {
    $set = own_set((int)($_POST['set_id'] ?? 0));
    $title = trim($_POST['title'] ?? '') ?: $set['title'];
    $mode = ($_POST['mode'] ?? 'practice') === 'exam' ? 'exam' : 'practice';
    $tl = (int)($_POST['time_limit'] ?? 0);
    $due = trim($_POST['due_at'] ?? '');
    q('INSERT INTO practice_assignments (class_id, set_id, title, kind, mode, time_limit, due_at) VALUES (?,?,?,?,?,?,?)',
      [$cid, $set['id'], $title, ($_POST['kind'] ?? '') === 'homework' ? 'homework' : 'pre_test', $mode,
       $mode === 'exam' && $tl > 0 ? $tl : null, $due !== '' ? str_replace('T', ' ', $due) : null]);
    flash(t('Đã giao bài ôn tập cho lớp.'));
    redirect("class.php?id=$cid#on-tap");
}
if ($act === 'unassign') {
    q('DELETE FROM practice_assignments WHERE id=? AND class_id=?', [(int)$_POST['aid'], $cid]);
    redirect("class.php?id=$cid#on-tap");
}
if ($act === 'join_renew') { class_join_code($cid, true); flash(t('Đã tạo mã tham gia mới. Mã cũ không dùng được nữa.')); redirect("class.php?id=$cid#join"); }
if ($act === 'join_toggle') { q('UPDATE classes SET join_open=1-join_open WHERE id=?', [$cid]); redirect("class.php?id=$cid#join"); }
if ($act === 'reset_pw') {
    $code = (string)qv('SELECT student_code FROM students WHERE class_id=? AND seat_no=?', [$cid, (int)$_POST['seat_no']]);
    if ($code !== '') {
        q('DELETE FROM student_users WHERE student_code=?', [$code]);
        q('DELETE FROM oauth_identities WHERE user_type="student" AND user_key=?', [$code]);   // thu hồi cả đăng nhập Google đã gắn
        flash(t('Đã đặt lại mật khẩu của {c} về mặc định (= MSSV).', ['c' => $code])); }
    redirect("class.php?id=$cid");
}

// ---- nhập danh sách từ Excel / CSV / Word / TXT (lib/roster.php): bước 1 xem trước, bước 2 xác nhận
$preview = null;
if ($act === 'import_upload' && !empty($_FILES['file']['tmp_name'])) {
    try {
        $preview = [];
        $seen = [];
        foreach (roster_rows(roster_table($_FILES['file']['tmp_name'], $_FILES['file']['name'])) as $x) {
            ['line' => $line, 'seat' => $seat, 'code' => $code, 'name' => $name] = $x;
            $errs = [];
            $phone = $x['phone'] !== '' ? normalize_phone($x['phone']) : null;
            $warn = $x['phone'] !== '' && !$phone ? t('SĐT phụ huynh không hợp lệ, bỏ qua:') . ' ' . $x['phone'] : '';
            if (!ctype_digit($seat) || (int)$seat < 1 || (int)$seat > 99) $errs[] = t('số thứ tự phải 1–99');
            elseif (isset($seen[(int)$seat])) $errs[] = t('trùng số thứ tự với dòng') . ' ' . $seen[(int)$seat];
            if ($name === '') $errs[] = t('thiếu họ tên');
            if (ctype_digit($seat)) $seen[(int)$seat] = $line;
            $preview[] = ['line' => $line, 'seat' => $seat, 'code' => $code, 'name' => $name, 'phone' => $phone, 'warn' => $warn, 'errs' => $errs, 'auto' => $x['auto']];
        }
        if (!$preview) throw new RuntimeException(t('không tìm thấy học sinh nào (cần cột Họ tên, hoặc mỗi dòng một học sinh).'));
        $_SESSION['import_students'][$cid] = $preview;
    } catch (Throwable $e) { $preview = null; flash(t('Không đọc được file:') . ' ' . $e->getMessage(), 'err'); }
}
if ($act === 'import_confirm') {
    $rows = $_SESSION['import_students'][$cid] ?? [];
    $ok = array_filter($rows, fn($r) => !$r['errs']);
    db()->beginTransaction();
    if (isset($_POST['replace'])) q('DELETE FROM students WHERE class_id=?', [$cid]);
    // file không có SĐT phụ huynh: giữ SĐT đã nhập trước đó
    foreach ($ok as $r) q('INSERT INTO students (class_id, seat_no, student_code, full_name, parent_phone) VALUES (?,?,?,?,?) '
        . 'ON DUPLICATE KEY UPDATE student_code=VALUES(student_code), full_name=VALUES(full_name), parent_phone=COALESCE(VALUES(parent_phone), parent_phone)',
        [$cid, (int)$r['seat'], $r['code'] ?: null, $r['name'], $r['phone'] ?? null]);
    db()->commit();
    unset($_SESSION['import_students'][$cid]);
    flash(t('Đã nhập') . ' ' . count($ok) . ' ' . t('học sinh') . (count($ok) < count($rows) ? ' ' . t('(bỏ') . ' ' . (count($rows) - count($ok)) . ' ' . t('dòng lỗi)') : '') . '.');
    redirect("class.php?id=$cid");
}

$students = qall('SELECT * FROM students WHERE class_id=? ORDER BY seat_no', [$cid]);
$sets = qall('SELECT qs.*, (SELECT COUNT(*) FROM questions q WHERE q.set_id=qs.id) n FROM question_sets qs WHERE teacher_id=? ORDER BY purpose, id DESC', [$t['id']]);
ensure_lessons($cid);
$lessons = qall('SELECT id, no, title, taught_on, quiz_set_id FROM lessons WHERE class_id=? ORDER BY no', [$cid]);
$next_lesson = (int)qv('SELECT COALESCE(MIN(no), 1) FROM lessons l WHERE class_id=? AND NOT EXISTS (SELECT 1 FROM sessions s WHERE s.lesson_id=l.id)', [$cid]);
$sessions = qall('SELECT s.*, qs.title FROM sessions s JOIN question_sets qs ON qs.id=s.set_id WHERE s.class_id=? ORDER BY s.id DESC', [$cid]);
$assigns = qall('SELECT a.*, qs.title AS set_title, (SELECT COUNT(*) FROM questions q WHERE q.set_id=a.set_id) AS n_q, '
              . '(SELECT COUNT(DISTINCT student_code) FROM practice_attempts p WHERE p.assignment_id=a.id AND p.finished_at IS NOT NULL) AS n_done '
              . 'FROM practice_assignments a JOIN question_sets qs ON qs.id=a.set_id WHERE a.class_id=? ORDER BY a.id DESC', [$cid]);
$acc_rows = qall('SELECT u.student_code, u.last_login, u.unverified FROM student_users u JOIN students s ON s.student_code=u.student_code WHERE s.class_id=?', [$cid]);
$accounts = array_column($acc_rows, 'last_login', 'student_code');
$unverified = array_flip(array_column(array_filter($acc_rows, fn($r) => (int)$r['unverified'] === 1), 'student_code'));   // tự đăng ký trước khi có trong danh sách lớp
$edit = isset($_GET['edit']) ? q1('SELECT * FROM students WHERE class_id=? AND seat_no=?', [$cid, (int)$_GET['edit']]) : null;
$next_seat = (int)qv('SELECT COALESCE(MAX(seat_no),0)+1 FROM students WHERE class_id=?', [$cid]);

page_head($c['name'], 'classes.php');
?>
<p class="crumbs"><a href="classes.php"><?= te('Lớp học') ?></a> › <?= h($c['name']) ?></p>
<div class="page-head">
  <h1><?= h($c['name']) ?> <span class="tag">room <?= (int)$c['room_id'] ?></span> <span class="tag topic">🔵 Bluetooth · nRF52840</span></h1>
  <div class="quick" style="margin:0">
    <a class="btn" href="lessons.php?class=<?= $cid ?>">📚 <?= te('10 buổi học & bài giảng') ?></a>
    <a class="btn" href="attendance.php?class=<?= $cid ?>">🗓 <?= te('Điểm danh') ?></a>
    <a class="btn" href="compose.php"><?= te('✍ Soạn câu hỏi') ?></a>
    <a class="btn" href="#on-tap"><?= te('📝 Giao bài ôn tập') ?></a>
  </div>
</div>

<div class="cols">
<section class="card">
  <h2><?= te('Bắt đầu buổi học') ?></h2>
  <?php if (!$students): ?><p class="muted"><?= te('Nhập danh sách học sinh trước.') ?></p>
  <?php elseif (!$sets): ?><p class="muted"><?= te('Chưa có bộ câu hỏi.') ?> <a href="sets.php"><?= te('Tạo bộ câu hỏi') ?></a>.</p>
  <?php else: ?>
  <form method="post" class="stack">
    <?= csrf_field() ?><input type="hidden" name="action" value="start_session">
    <label><?= te('Buổi học') ?>
      <select name="lesson_id"><option value=""><?= te('— không gắn với buổi nào —') ?></option>
        <?php foreach ($lessons as $l): ?><option value="<?= $l['id'] ?>" <?= (int)$l['no'] === $next_lesson ? 'selected' : '' ?>><?= h(lesson_label($l)) ?></option><?php endforeach; ?></select>
    </label>
    <label><?= te('Bộ câu hỏi') ?>
      <select name="set_id"><?php foreach (PURPOSE as $pk => $pl): ?><optgroup label="<?= te('Bộ') ?> <?= h(mb_strtolower(t($pl))) ?>">
        <?php foreach ($sets as $s): if ($s['purpose'] !== $pk) continue; ?><option value="<?= $s['id'] ?>"><?= h($s['title']) ?> (<?= (int)$s['n'] ?> <?= te('câu') ?><?= !empty($s['finalized_at']) ? ' ✓' : ' · ' . te('đang soạn') ?>)</option><?php endforeach; ?></optgroup><?php endforeach; ?></select>
    </label>
    <label><?= te('Loại') ?>
      <select name="kind"><option value="class"><?= te('Buổi học (học sinh xem lại ngay sau buổi)') ?></option><option value="test"><?= te('Bài kiểm tra (xem lại sau khi GV công bố)') ?></option></select>
    </label>
    <label class="check"><input type="checkbox" name="allow_change" checked> <?= te('Cho học sinh đổi đáp án khi câu còn mở') ?></label>
    <p class="small muted" style="margin:0">🔵 <?= te('Remote nRF52840 gửi qua gateway nRF52840 cắm USB (máy chủ hoặc máy giáo viên).') ?></p>
    <button class="primary"><?= te('Bắt đầu') ?></button>
  </form>
  <?php endif; ?>
</section>

<section class="card">
  <h2><?= te('Thông tin lớp') ?></h2>
  <form method="post" class="stack">
    <?= csrf_field() ?><input type="hidden" name="action" value="update">
    <label><?= te('Tên lớp') ?> <input name="name" value="<?= h($c['name']) ?>" required></label>
    <label><?= te('Mã lớp (room, 1–255)') ?> <input name="room_id" type="number" min="1" max="255" value="<?= (int)$c['room_id'] ?>" required></label>
    <label><?= te('Môn học') ?> <input name="subject" maxlength="60" value="<?= h($c['subject'] ?? '') ?>" placeholder="<?= te('Toán') ?>"></label>
    <label><?= te('Lớp hành chính') ?> <input name="cohort" maxlength="30" value="<?= h($c['cohort'] ?? '') ?>" placeholder="10A1"> <span class="muted small"><?= te('GV chủ nhiệm của lớp này (do Admin trường gán) xem được báo cáo môn của bạn.') ?></span></label>
    <button><?= te('Lưu') ?></button>
  </form>
</section>
</div>

<?php if ($preview !== null): ?>
<section class="card">
  <h2><?= te('Xem trước danh sách nhập') ?></h2>
  <table class="grid">
    <thead><tr><th><?= te('Dòng') ?></th><th><?= te('Số thứ tự') ?></th><th>MSSV</th><th><?= te('Họ tên') ?></th><th><?= te('SĐT phụ huynh') ?></th><th><?= te('Lỗi') ?></th></tr></thead>
    <tbody><?php foreach ($preview as $r): ?>
      <tr class="<?= $r['errs'] ? 'bad' : '' ?>"><td><?= $r['line'] ?></td><td><?= h($r['seat']) ?><?= !empty($r['auto']) ? ' <span class="muted small">(' . te('tự đánh số') . ')</span>' : '' ?></td><td><?= h($r['code']) ?></td><td><?= h($r['name']) ?></td><td><?= h($r['phone'] ?? '') ?></td><td><?= h(implode('; ', array_filter([...$r['errs'], $r['warn'] ?? '']))) ?></td></tr>
    <?php endforeach; ?></tbody>
  </table>
  <form method="post" class="row">
    <?= csrf_field() ?><input type="hidden" name="action" value="import_confirm">
    <label class="check"><input type="checkbox" name="replace"> <?= te('Xoá danh sách cũ trước khi nhập') ?></label>
    <button class="primary"><?= te('Xác nhận nhập') ?> <?= count(array_filter($preview, fn($r) => !$r['errs'])) ?> <?= te('dòng hợp lệ') ?></button>
    <a href="class.php?id=<?= $cid ?>"><?= te('Huỷ') ?></a>
  </form>
</section>
<?php endif; ?>

<?php $jc = class_join_code($cid); $c = own_class($cid); $portal = link_hs() . '/' . $jc; ?>
<section class="card join-card" id="join">
  <div class="join-code"><span class="small muted"><?= te('Mã tham gia lớp') ?></span><b><?= h($jc) ?></b></div>
  <div style="flex:1;min-width:240px">
    <p style="margin:0 0 6px"><?= te('Học sinh mở') ?> <b><?= te('cổng học sinh → Đăng ký') ?></b><?= te(', nhập MSSV, họ tên, mật khẩu và mã này để tự tạo tài khoản và vào lớp (được cấp số thứ tự trống tiếp theo làm ID clicker).
      Học sinh đã có trong danh sách chỉ cần MSSV, không cần mã.') ?></p>
    <?= link_box($portal, t('Link vào lớp (đã điền mã)')) ?>
    <p class="small muted" style="margin:0"><?= te('Đăng ký:') ?> <?= $c['join_open'] ? ('<span class="tag live">' . t('đang mở') . '</span>') : ('<span class="tag off">' . t('đã khoá') . '</span>') ?> <?= te('· bấm') ?> <b>QR</b> <?= te('để chiếu cho cả lớp quét.') ?></p>
  </div>
  <div class="row" style="margin:0">
    <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="join_toggle"><button class="small"><?= $c['join_open'] ? t('Khoá đăng ký') : t('Mở đăng ký') ?></button></form>
    <form method="post" class="inline" onsubmit="return confirm(<?= h(json_encode(t('Tạo mã mới? Mã cũ sẽ hết hiệu lực.'), JSON_UNESCAPED_UNICODE)) ?>)"><?= csrf_field() ?><input type="hidden" name="action" value="join_renew"><button class="small"><?= te('Đổi mã') ?></button></form>
  </div>
</section>

<section class="card">
  <div class="card-head"><h2><?= te('Học sinh (') ?><?= count($students) ?>)</h2><a class="btn small" href="family.php?class=<?= $cid ?>#pacc">📱 <?= te('Tài khoản phụ huynh') ?></a></div>
  <div class="row">
    <form method="post" enctype="multipart/form-data" class="row">
      <?= csrf_field() ?><input type="hidden" name="action" value="import_upload">
      <input type="file" name="file" accept=".xlsx,.csv,.docx,.txt" required>
      <button><?= te('Nhập danh sách (Excel, CSV, Word, TXT)') ?></button>
    </form>
    <a class="btn" href="template.php"><?= te('Tải file mẫu') ?></a>
  </div>
  <p class="muted small"><?= te('Nhận Excel (.xlsx), CSV, Word (.docx — bảng hoặc mỗi dòng một học sinh) và .txt. Tự nhận cột theo tiêu đề: STT (số ghế 1–99, là ID học sinh gõ trên Remote), MSSV, Họ tên (hoặc Họ đệm + Tên), SĐT phụ huynh (dùng để cấp tài khoản phụ huynh). Không có cột STT thì tự đánh số 1, 2, 3… Luôn xem trước rồi mới xác nhận.') ?></p>
  <?php if ($students): ?>
  <table class="grid compact">
    <thead><tr><th><?= te('Số') ?></th><th>MSSV</th><th><?= te('Họ tên') ?></th><th><?= te('SĐT phụ huynh') ?></th><th><?= te('Tài khoản học sinh') ?></th><th></th></tr></thead>
    <tbody><?php foreach ($students as $s): $acc = $s['student_code'] !== null && array_key_exists($s['student_code'], $accounts); ?>
      <tr><td><?= (int)$s['seat_no'] ?></td><td><?= h($s['student_code']) ?></td><td><?= h($s['full_name']) ?></td><td class="small"><?= h($s['parent_phone'] ?? '') ?></td>
        <td><?php if (!$s['student_code']): ?><span class="muted small"><?= te('cần MSSV để đăng nhập') ?></span>
            <?php elseif ($acc): ?><span class="small"><?= te('đã vào') ?> <?= h(substr((string)$accounts[$s['student_code']], 0, 16)) ?></span>
              <?php if (isset($unverified[$s['student_code']])): ?><span class="tag off" title="<?= te('Tài khoản này được tự tạo trước khi MSSV có trong danh sách lớp, chưa vào lớp bằng mã tham gia. Hỏi học sinh có tự tạo không; nếu không, bấm “đặt lại MK” để thu hồi.') ?>"><?= te('⚠ tự tạo, cần kiểm tra') ?></span><?php endif; ?>
              <form method="post" class="inline" onsubmit="return confirm(<?= h(json_encode(t('Đặt lại mật khẩu về MSSV?'), JSON_UNESCAPED_UNICODE)) ?>)"><?= csrf_field() ?><input type="hidden" name="action" value="reset_pw"><input type="hidden" name="seat_no" value="<?= (int)$s['seat_no'] ?>"><button class="link" title="<?= te('Xoá mật khẩu: học sinh đăng nhập lại bằng MSSV hoặc đăng ký lại') ?>"><?= te('đặt lại MK') ?></button></form>
            <?php else: ?><span class="muted small"><?= te('chưa đăng nhập') ?></span><?php endif; ?></td>
        <td class="right">
          <?php if ($s['student_code']): ?><a href="practice_log.php?code=<?= urlencode($s['student_code']) ?>&days=0" title="<?= te('Lịch sử luyện tập') ?>"><?= te('Luyện tập') ?></a> · <?php endif; ?>
          <a href="class.php?id=<?= $cid ?>&edit=<?= (int)$s['seat_no'] ?>#form-hs"><?= te('Sửa') ?></a>
          <form method="post" class="inline" onsubmit="return confirm(<?= h(json_encode(t('Xoá học sinh này?'), JSON_UNESCAPED_UNICODE)) ?>)"><?= csrf_field() ?>
            <input type="hidden" name="action" value="delete_student"><input type="hidden" name="seat_no" value="<?= (int)$s['seat_no'] ?>">
            <button class="link danger"><?= te('Xoá') ?></button></form>
        </td></tr>
    <?php endforeach; ?></tbody>
  </table>
  <?php endif; ?>
  <form method="post" class="row" id="form-hs">
    <?= csrf_field() ?><input type="hidden" name="action" value="<?= $edit ? 'edit_student' : 'add_student' ?>">
    <label><?= te('Số') ?> <input name="seat_no" type="number" min="1" max="99" value="<?= $edit ? (int)$edit['seat_no'] : $next_seat ?>" <?= $edit ? 'readonly' : '' ?> required class="w5"></label>
    <label>MSSV <input name="student_code" value="<?= h($edit['student_code'] ?? '') ?>"></label>
    <label><?= te('Họ tên') ?> <input name="full_name" value="<?= h($edit['full_name'] ?? '') ?>" required></label>
    <label><?= te('SĐT phụ huynh') ?> <input name="parent_phone" type="tel" inputmode="tel" value="<?= h($edit['parent_phone'] ?? '') ?>" placeholder="0912345678" style="width:9em"></label>
    <button class="primary"><?= $edit ? t('Lưu') : t('Thêm') ?></button>
  </form>
</section>

<section class="card" id="on-tap">
  <h2><?= te('Ôn tập giao cho lớp') ?></h2>
  <p class="muted small"><?= te('Học sinh vào cổng học sinh (') ?><code>/student/</code><?= te(', đăng nhập bằng MSSV) để làm. Chế độ') ?> <b><?= te('luyện tập') ?></b> <?= te('báo đúng/sai và giải thích ngay, câu sai được hỏi lại cuối lượt tới khi đúng; chế độ') ?> <b><?= te('như kiểm tra') ?></b> <?= te('chỉ chấm khi nộp bài.') ?></p>
  <?php if ($assigns): ?>
  <table class="grid compact"><thead><tr><th><?= te('Tên') ?></th><th><?= te('Bộ câu hỏi') ?></th><th><?= te('Loại') ?></th><th><?= te('Chế độ') ?></th><th><?= te('Hạn') ?></th><th><?= te('Đã làm') ?></th><th></th></tr></thead><tbody>
  <?php foreach ($assigns as $a): ?>
    <tr><td><?= h($a['title']) ?></td><td><?= h($a['set_title']) ?> (<?= (int)$a['n_q'] ?> <?= te('câu)') ?></td>
      <td><?= $a['kind'] === 'pre_test' ? t('Ôn trước kiểm tra') : t('Bài tập về nhà') ?></td>
      <td><?= $a['mode'] === 'exam' ? t('Như kiểm tra') . ($a['time_limit'] ? ' · ' . (int)$a['time_limit'] . ' ' . t('phút') : '') : t('Luyện tập') ?></td>
      <td><?= $a['due_at'] ? h(substr($a['due_at'], 0, 16)) : '—' ?></td><td><?= (int)$a['n_done'] ?>/<?= count($students) ?></td>
      <td class="right"><form method="post" class="inline" onsubmit="return confirm(<?= h(json_encode(t('Bỏ bài ôn tập này? Kết quả học sinh đã làm vẫn giữ.'), JSON_UNESCAPED_UNICODE)) ?>)"><?= csrf_field() ?><input type="hidden" name="action" value="unassign"><input type="hidden" name="aid" value="<?= $a['id'] ?>"><button class="link danger"><?= te('Bỏ') ?></button></form></td></tr>
  <?php endforeach; ?></tbody></table>
  <?php endif; ?>
  <?php if ($sets): ?>
  <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="action" value="assign">
    <label><?= te('Bộ câu hỏi') ?> <select name="set_id"><?php foreach ($sets as $s): ?><option value="<?= $s['id'] ?>"><?= h($s['title']) ?> (<?= (int)$s['n'] ?> <?= te('câu') ?><?= !empty($s['finalized_at']) ? ' ✓' : ' · ' . te('đang soạn') ?>)</option><?php endforeach; ?></select></label>
    <label><?= te('Tên hiển thị') ?> <input name="title" placeholder="<?= te('Ôn tập chương 3') ?>"></label>
    <label><?= te('Loại') ?> <select name="kind"><option value="pre_test"><?= te('Ôn trước kiểm tra') ?></option><option value="homework"><?= te('Bài tập về nhà') ?></option></select></label>
    <label><?= te('Chế độ') ?> <select name="mode"><option value="practice"><?= te('Luyện tập') ?></option><option value="exam"><?= te('Như kiểm tra') ?></option></select></label>
    <label><?= te('Thời gian (phút)') ?> <input name="time_limit" type="number" min="1" max="300" class="w5" placeholder="—"></label>
    <label><?= te('Hạn') ?> <input name="due_at" type="datetime-local"></label>
    <button class="primary">Giao</button>
  </form>
  <?php endif; ?>
</section>

<?php if ($sessions): ?>
<section class="card">
  <h2><?= te('Các buổi đã dạy') ?> <a class="btn small" href="attendance.php?class=<?= $cid ?>">🗓 <?= te('Điểm danh cả lớp') ?></a></h2>
  <table class="grid compact">
    <thead><tr><th><?= te('Bắt đầu') ?></th><th><?= te('Bộ câu hỏi') ?></th><th><?= te('Trạng thái') ?></th><th></th></tr></thead>
    <tbody><?php foreach ($sessions as $s): ?>
      <tr><td><?= h($s['started_at']) ?></td><td><?= h($s['title']) ?> <?= $s['kind'] === 'test' ? ('<span class="tag">' . t('kiểm tra') . '</span>') : '' ?></td><td><?= $s['status'] === 'running' ? ('<b>' . t('đang học') . '</b>') : t('đã kết thúc') ?><?= $s['kind'] === 'test' && !$s['released'] ? (' · ' . '<span class="muted small">' . t('chưa công bố') . '</span>') : '' ?></td>
        <td class="right"><a href="session.php?id=<?= $s['id'] ?>"><?= te('Điều khiển') ?></a> · <a href="results.php?id=<?= $s['id'] ?>"><?= te('Kết quả') ?></a> · <a href="attendance.php?session=<?= $s['id'] ?>"><?= te('Điểm danh') ?></a></td></tr>
    <?php endforeach; ?></tbody>
  </table>
</section>
<?php endif; ?>

<details class="danger-zone"><summary><?= te('Xoá lớp') ?></summary>
  <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="action" value="delete_class">
    <label><?= te('Gõ XOA để xác nhận (xoá cả kết quả các buổi)') ?> <input name="confirm"></label>
    <button class="danger"><?= te('Xoá lớp') ?></button></form>
</details>
<?php page_foot();
