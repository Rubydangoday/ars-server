<?php
// Một lớp: sửa thông tin, danh sách học sinh (thêm/sửa/xoá/nhập file), bắt đầu buổi học.
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/sheet.php';
$t = require_login();
$c = own_class((int)($_GET['id'] ?? 0));
$cid = (int)$c['id'];
$act = $_POST['action'] ?? '';

if ($act === 'update') {
    $name = trim($_POST['name'] ?? ''); $room = (int)($_POST['room_id'] ?? 0);
    if ($name === '' || $room < 1 || $room > 255) flash('Tên lớp và mã lớp 1–255.', 'err');
    elseif ($o = room_taken($room, $cid)) flash("Mã lớp $room đang được dùng cho lớp “{$o['name']}” của {$o['teacher']}. Chọn mã khác.", 'err');
    else {
        q('UPDATE classes SET name=?, room_id=?, subject=?, cohort=? WHERE id=?', [$name, $room, mb_substr(trim($_POST['subject'] ?? ''), 0, 60) ?: null,
          mb_substr(trim($_POST['cohort'] ?? ''), 0, 30) ?: null, $cid]);
        flash('Đã lưu.');
    }
    redirect("class.php?id=$cid");
}
if ($act === 'add_student' || $act === 'edit_student') {
    $seat = (int)($_POST['seat_no'] ?? 0); $name = trim($_POST['full_name'] ?? ''); $code = trim($_POST['student_code'] ?? '');
    if ($seat < 1 || $seat > 99 || $name === '') flash('Số thứ tự 1–99 và họ tên là bắt buộc.', 'err');
    else {
        q('INSERT INTO students (class_id, seat_no, student_code, full_name) VALUES (?,?,?,?) '
        . 'ON DUPLICATE KEY UPDATE student_code=VALUES(student_code), full_name=VALUES(full_name)', [$cid, $seat, $code ?: null, $name]);
        flash("Đã lưu học sinh số $seat.");
    }
    redirect("class.php?id=$cid");
}
if ($act === 'delete_student') {
    q('DELETE FROM students WHERE class_id=? AND seat_no=?', [$cid, (int)$_POST['seat_no']]);
    redirect("class.php?id=$cid");
}
if ($act === 'delete_class') {
    if (q1('SELECT 1 FROM sessions WHERE class_id=?', [$cid]) && ($_POST['confirm'] ?? '') !== 'XOA') {
        flash('Lớp đã có buổi học. Gõ XOA vào ô xác nhận để xoá cả kết quả.', 'err'); redirect("class.php?id=$cid");
    }
    q('DELETE FROM classes WHERE id=?', [$cid]);
    flash('Đã xoá lớp.'); redirect('classes.php');
}
if ($act === 'conn_mode') {
    $m = ($_POST['conn_mode'] ?? 'ble') === 'wifi' ? 'wifi' : 'ble';
    q('UPDATE classes SET conn_mode=? WHERE id=?', [$m, $cid]);
    flash('Lớp dùng kết nối ' . CONN_MODES[$m] . '.'); redirect("class.php?id=$cid");
}
if ($act === 'start_session') {
    $set = own_set((int)($_POST['set_id'] ?? 0));
    if ($busy = q1('SELECT c.name, t.full_name FROM sessions s JOIN classes c ON c.id=s.class_id JOIN teachers t ON t.id=c.teacher_id '
                 . 'WHERE s.status="running" AND c.room_id=? AND c.id<>?', [$c['room_id'], $cid])) {
        flash("Mã lớp (room) {$c['room_id']} đang chạy buổi học của lớp “{$busy['name']}” ({$busy['full_name']}). Đổi mã lớp ở “Thông tin lớp” rồi bắt đầu lại.", 'err');
        redirect("class.php?id=$cid");
    }
    $cm = ($_POST['conn_mode'] ?? $c['conn_mode']) === 'wifi' ? 'wifi' : 'ble';
    q('UPDATE classes SET conn_mode=? WHERE id=?', [$cm, $cid]);
    if (!q1('SELECT 1 FROM questions WHERE set_id=?', [$set['id']])) { flash('Bộ câu hỏi chưa có câu nào.', 'err'); redirect("class.php?id=$cid"); }
    q('UPDATE sessions SET status="ended", ended_at=NOW() WHERE class_id=? AND status="running"', [$cid]);
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
    flash('Đã giao bài ôn tập cho lớp.');
    redirect("class.php?id=$cid#on-tap");
}
if ($act === 'unassign') {
    q('DELETE FROM practice_assignments WHERE id=? AND class_id=?', [(int)$_POST['aid'], $cid]);
    redirect("class.php?id=$cid#on-tap");
}
if ($act === 'join_renew') { class_join_code($cid, true); flash('Đã tạo mã tham gia mới. Mã cũ không dùng được nữa.'); redirect("class.php?id=$cid#join"); }
if ($act === 'join_toggle') { q('UPDATE classes SET join_open=1-join_open WHERE id=?', [$cid]); redirect("class.php?id=$cid#join"); }
if ($act === 'reset_pw') {
    $code = (string)qv('SELECT student_code FROM students WHERE class_id=? AND seat_no=?', [$cid, (int)$_POST['seat_no']]);
    if ($code !== '') {
        q('DELETE FROM student_users WHERE student_code=?', [$code]);
        q('DELETE FROM oauth_identities WHERE user_type="student" AND user_key=?', [$code]);   // thu hồi cả đăng nhập Google/Facebook đã gắn
        flash("Đã đặt lại mật khẩu của $code về mặc định (= MSSV)."); }
    redirect("class.php?id=$cid");
}

// ---- nhập danh sách từ Excel/CSV: bước 1 xem trước, bước 2 xác nhận
$preview = null;
if ($act === 'import_upload' && !empty($_FILES['file']['tmp_name'])) {
    try {
        $sheets = sheet_read($_FILES['file']['tmp_name'], $_FILES['file']['name']);
        $rows = $sheets['Danh sach lop'] ?? $sheets['Danh sách lớp'] ?? reset($sheets);
        $preview = [];
        $seen = [];
        foreach (data_rows($rows) as $line => $r) {
            $seat = trim($r[0] ?? ''); $code = trim($r[1] ?? ''); $name = trim($r[2] ?? '');
            $errs = [];
            if (!ctype_digit($seat) || (int)$seat < 1 || (int)$seat > 99) $errs[] = 'số thứ tự phải 1–99';
            elseif (isset($seen[(int)$seat])) $errs[] = 'trùng số thứ tự với dòng ' . $seen[(int)$seat];
            if ($name === '') $errs[] = 'thiếu họ tên';
            if (ctype_digit($seat)) $seen[(int)$seat] = $line;
            $preview[] = ['line' => $line, 'seat' => $seat, 'code' => $code, 'name' => $name, 'errs' => $errs];
        }
        $_SESSION['import_students'][$cid] = $preview;
    } catch (Throwable $e) { $preview = null; flash('Không đọc được file: ' . $e->getMessage(), 'err'); }
}
if ($act === 'import_confirm') {
    $rows = $_SESSION['import_students'][$cid] ?? [];
    $ok = array_filter($rows, fn($r) => !$r['errs']);
    db()->beginTransaction();
    if (isset($_POST['replace'])) q('DELETE FROM students WHERE class_id=?', [$cid]);
    foreach ($ok as $r) q('INSERT INTO students (class_id, seat_no, student_code, full_name) VALUES (?,?,?,?) '
        . 'ON DUPLICATE KEY UPDATE student_code=VALUES(student_code), full_name=VALUES(full_name)', [$cid, (int)$r['seat'], $r['code'] ?: null, $r['name']]);
    db()->commit();
    unset($_SESSION['import_students'][$cid]);
    flash('Đã nhập ' . count($ok) . ' học sinh' . (count($ok) < count($rows) ? ' (bỏ ' . (count($rows) - count($ok)) . ' dòng lỗi)' : '') . '.');
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
<p class="crumbs"><a href="classes.php">Lớp học</a> › <?= h($c['name']) ?></p>
<div class="page-head">
  <h1><?= h($c['name']) ?> <span class="tag">room <?= (int)$c['room_id'] ?></span> <span class="tag topic"><?= $c['conn_mode'] === 'wifi' ? '📶 Wi-Fi' : '🔵 Bluetooth' ?></span></h1>
  <div class="quick" style="margin:0">
    <a class="btn" href="lessons.php?class=<?= $cid ?>">📚 10 buổi học &amp; bài giảng</a>
    <a class="btn" href="compose.php">✍ Soạn câu hỏi</a>
    <a class="btn" href="#on-tap">📝 Giao bài ôn tập</a>
  </div>
</div>

<div class="cols">
<section class="card">
  <h2>Bắt đầu buổi học</h2>
  <?php if (!$students): ?><p class="muted">Nhập danh sách học sinh trước.</p>
  <?php elseif (!$sets): ?><p class="muted">Chưa có bộ câu hỏi. <a href="sets.php">Tạo bộ câu hỏi</a>.</p>
  <?php else: ?>
  <form method="post" class="stack">
    <?= csrf_field() ?><input type="hidden" name="action" value="start_session">
    <label>Buổi học
      <select name="lesson_id"><option value="">— không gắn với buổi nào —</option>
        <?php foreach ($lessons as $l): ?><option value="<?= $l['id'] ?>" <?= (int)$l['no'] === $next_lesson ? 'selected' : '' ?>><?= h(lesson_label($l)) ?></option><?php endforeach; ?></select>
    </label>
    <label>Bộ câu hỏi
      <select name="set_id"><?php foreach (PURPOSE as $pk => $pl): ?><optgroup label="Bộ <?= mb_strtolower($pl) ?>">
        <?php foreach ($sets as $s): if ($s['purpose'] !== $pk) continue; ?><option value="<?= $s['id'] ?>"><?= h($s['title']) ?> (<?= (int)$s['n'] ?> câu)</option><?php endforeach; ?></optgroup><?php endforeach; ?></select>
    </label>
    <label>Loại
      <select name="kind"><option value="class">Buổi học (học sinh xem lại ngay sau buổi)</option><option value="test">Bài kiểm tra (xem lại sau khi GV công bố)</option></select>
    </label>
    <label class="check"><input type="checkbox" name="allow_change" checked> Cho học sinh đổi đáp án khi câu còn mở</label>
    <?= conn_mode_picker($c['conn_mode']) ?>
    <button class="primary">Bắt đầu</button>
  </form>
  <?php endif; ?>
</section>

<section class="card">
  <h2>Thông tin lớp</h2>
  <form method="post" class="stack">
    <?= csrf_field() ?><input type="hidden" name="action" value="update">
    <label>Tên lớp <input name="name" value="<?= h($c['name']) ?>" required></label>
    <label>Mã lớp (room, 1–255) <input name="room_id" type="number" min="1" max="255" value="<?= (int)$c['room_id'] ?>" required></label>
    <label>Môn học <input name="subject" maxlength="60" value="<?= h($c['subject'] ?? '') ?>" placeholder="Toán"></label>
    <label>Lớp hành chính <input name="cohort" maxlength="30" value="<?= h($c['cohort'] ?? '') ?>" placeholder="10A1"> <span class="muted small">GV chủ nhiệm của lớp này (do Admin trường gán) xem được báo cáo môn của bạn.</span></label>
    <button>Lưu</button>
  </form>
  <form method="post" class="stack" style="margin-top:14px"><?= csrf_field() ?><input type="hidden" name="action" value="conn_mode">
    <?= conn_mode_picker($c['conn_mode']) ?>
    <div><button>Lưu kiểu kết nối</button></div>
  </form>
</section>
</div>

<?php if ($preview !== null): ?>
<section class="card">
  <h2>Xem trước danh sách nhập</h2>
  <table class="grid">
    <thead><tr><th>Dòng</th><th>Số thứ tự</th><th>MSSV</th><th>Họ tên</th><th>Lỗi</th></tr></thead>
    <tbody><?php foreach ($preview as $r): ?>
      <tr class="<?= $r['errs'] ? 'bad' : '' ?>"><td><?= $r['line'] ?></td><td><?= h($r['seat']) ?></td><td><?= h($r['code']) ?></td><td><?= h($r['name']) ?></td><td><?= h(implode('; ', $r['errs'])) ?></td></tr>
    <?php endforeach; ?></tbody>
  </table>
  <form method="post" class="row">
    <?= csrf_field() ?><input type="hidden" name="action" value="import_confirm">
    <label class="check"><input type="checkbox" name="replace"> Xoá danh sách cũ trước khi nhập</label>
    <button class="primary">Xác nhận nhập <?= count(array_filter($preview, fn($r) => !$r['errs'])) ?> dòng hợp lệ</button>
    <a href="class.php?id=<?= $cid ?>">Huỷ</a>
  </form>
</section>
<?php endif; ?>

<?php $jc = class_join_code($cid); $c = own_class($cid); $portal = link_hs() . '/' . $jc; ?>
<section class="card join-card" id="join">
  <div class="join-code"><span class="small muted">Mã tham gia lớp</span><b><?= h($jc) ?></b></div>
  <div style="flex:1;min-width:240px">
    <p style="margin:0 0 6px">Học sinh mở <b>cổng học sinh → Đăng ký</b>, nhập MSSV, họ tên, mật khẩu và mã này để tự tạo tài khoản và vào lớp (được cấp số thứ tự trống tiếp theo làm ID clicker).
      Học sinh đã có trong danh sách chỉ cần MSSV, không cần mã.</p>
    <?= link_box($portal, 'Link vào lớp (đã điền mã)') ?>
    <p class="small muted" style="margin:0">Đăng ký: <?= $c['join_open'] ? '<span class="tag live">đang mở</span>' : '<span class="tag off">đã khoá</span>' ?> · bấm <b>QR</b> để chiếu cho cả lớp quét.</p>
  </div>
  <div class="row" style="margin:0">
    <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="join_toggle"><button class="small"><?= $c['join_open'] ? 'Khoá đăng ký' : 'Mở đăng ký' ?></button></form>
    <form method="post" class="inline" onsubmit="return confirm('Tạo mã mới? Mã cũ sẽ hết hiệu lực.')"><?= csrf_field() ?><input type="hidden" name="action" value="join_renew"><button class="small">Đổi mã</button></form>
  </div>
</section>

<section class="card">
  <h2>Học sinh (<?= count($students) ?>)</h2>
  <div class="row">
    <form method="post" enctype="multipart/form-data" class="row">
      <?= csrf_field() ?><input type="hidden" name="action" value="import_upload">
      <input type="file" name="file" accept=".xlsx,.csv" required>
      <button>Nhập từ Excel/CSV</button>
    </form>
    <a class="btn" href="template.php">Tải file mẫu</a>
  </div>
  <p class="muted small">Sheet "Danh sach lop": cột A số thứ tự (1–99, là ID học sinh gõ trên clicker), cột B MSSV, cột C họ tên. Dòng đầu là tiêu đề.</p>
  <?php if ($students): ?>
  <table class="grid compact">
    <thead><tr><th>Số</th><th>MSSV</th><th>Họ tên</th><th>Tài khoản học sinh</th><th></th></tr></thead>
    <tbody><?php foreach ($students as $s): $acc = $s['student_code'] !== null && array_key_exists($s['student_code'], $accounts); ?>
      <tr><td><?= (int)$s['seat_no'] ?></td><td><?= h($s['student_code']) ?></td><td><?= h($s['full_name']) ?></td>
        <td><?php if (!$s['student_code']): ?><span class="muted small">cần MSSV để đăng nhập</span>
            <?php elseif ($acc): ?><span class="small">đã vào <?= h(substr((string)$accounts[$s['student_code']], 0, 16)) ?></span>
              <?php if (isset($unverified[$s['student_code']])): ?><span class="tag off" title="Tài khoản này được tự tạo trước khi MSSV có trong danh sách lớp, chưa vào lớp bằng mã tham gia. Hỏi học sinh có tự tạo không; nếu không, bấm “đặt lại MK” để thu hồi.">⚠ tự tạo, cần kiểm tra</span><?php endif; ?>
              <form method="post" class="inline" onsubmit="return confirm('Đặt lại mật khẩu về MSSV?')"><?= csrf_field() ?><input type="hidden" name="action" value="reset_pw"><input type="hidden" name="seat_no" value="<?= (int)$s['seat_no'] ?>"><button class="link" title="Xoá mật khẩu: học sinh đăng nhập lại bằng MSSV hoặc đăng ký lại">đặt lại MK</button></form>
            <?php else: ?><span class="muted small">chưa đăng nhập</span><?php endif; ?></td>
        <td class="right">
          <?php if ($s['student_code']): ?><a href="practice_log.php?code=<?= urlencode($s['student_code']) ?>&days=0" title="Lịch sử luyện tập">Luyện tập</a> · <?php endif; ?>
          <a href="class.php?id=<?= $cid ?>&edit=<?= (int)$s['seat_no'] ?>#form-hs">Sửa</a>
          <form method="post" class="inline" onsubmit="return confirm('Xoá học sinh này?')"><?= csrf_field() ?>
            <input type="hidden" name="action" value="delete_student"><input type="hidden" name="seat_no" value="<?= (int)$s['seat_no'] ?>">
            <button class="link danger">Xoá</button></form>
        </td></tr>
    <?php endforeach; ?></tbody>
  </table>
  <?php endif; ?>
  <form method="post" class="row" id="form-hs">
    <?= csrf_field() ?><input type="hidden" name="action" value="<?= $edit ? 'edit_student' : 'add_student' ?>">
    <label>Số <input name="seat_no" type="number" min="1" max="99" value="<?= $edit ? (int)$edit['seat_no'] : $next_seat ?>" <?= $edit ? 'readonly' : '' ?> required class="w5"></label>
    <label>MSSV <input name="student_code" value="<?= h($edit['student_code'] ?? '') ?>"></label>
    <label>Họ tên <input name="full_name" value="<?= h($edit['full_name'] ?? '') ?>" required></label>
    <button class="primary"><?= $edit ? 'Lưu' : 'Thêm' ?></button>
  </form>
</section>

<section class="card" id="on-tap">
  <h2>Ôn tập giao cho lớp</h2>
  <p class="muted small">Học sinh vào cổng học sinh (<code>/student/</code>, đăng nhập bằng MSSV) để làm. Chế độ <b>luyện tập</b> báo đúng/sai và giải thích ngay, câu sai được hỏi lại cuối lượt tới khi đúng; chế độ <b>như kiểm tra</b> chỉ chấm khi nộp bài.</p>
  <?php if ($assigns): ?>
  <table class="grid compact"><thead><tr><th>Tên</th><th>Bộ câu hỏi</th><th>Loại</th><th>Chế độ</th><th>Hạn</th><th>Đã làm</th><th></th></tr></thead><tbody>
  <?php foreach ($assigns as $a): ?>
    <tr><td><?= h($a['title']) ?></td><td><?= h($a['set_title']) ?> (<?= (int)$a['n_q'] ?> câu)</td>
      <td><?= $a['kind'] === 'pre_test' ? 'Ôn trước kiểm tra' : 'Bài tập về nhà' ?></td>
      <td><?= $a['mode'] === 'exam' ? 'Như kiểm tra' . ($a['time_limit'] ? ' · ' . (int)$a['time_limit'] . ' phút' : '') : 'Luyện tập' ?></td>
      <td><?= $a['due_at'] ? h(substr($a['due_at'], 0, 16)) : '—' ?></td><td><?= (int)$a['n_done'] ?>/<?= count($students) ?></td>
      <td class="right"><form method="post" class="inline" onsubmit="return confirm('Bỏ bài ôn tập này? Kết quả học sinh đã làm vẫn giữ.')"><?= csrf_field() ?><input type="hidden" name="action" value="unassign"><input type="hidden" name="aid" value="<?= $a['id'] ?>"><button class="link danger">Bỏ</button></form></td></tr>
  <?php endforeach; ?></tbody></table>
  <?php endif; ?>
  <?php if ($sets): ?>
  <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="action" value="assign">
    <label>Bộ câu hỏi <select name="set_id"><?php foreach ($sets as $s): ?><option value="<?= $s['id'] ?>"><?= h($s['title']) ?> (<?= (int)$s['n'] ?> câu)</option><?php endforeach; ?></select></label>
    <label>Tên hiển thị <input name="title" placeholder="Ôn tập chương 3"></label>
    <label>Loại <select name="kind"><option value="pre_test">Ôn trước kiểm tra</option><option value="homework">Bài tập về nhà</option></select></label>
    <label>Chế độ <select name="mode"><option value="practice">Luyện tập</option><option value="exam">Như kiểm tra</option></select></label>
    <label>Thời gian (phút) <input name="time_limit" type="number" min="1" max="300" class="w5" placeholder="—"></label>
    <label>Hạn <input name="due_at" type="datetime-local"></label>
    <button class="primary">Giao</button>
  </form>
  <?php endif; ?>
</section>

<?php if ($sessions): ?>
<section class="card">
  <h2>Các buổi đã dạy</h2>
  <table class="grid compact">
    <thead><tr><th>Bắt đầu</th><th>Bộ câu hỏi</th><th>Trạng thái</th><th></th></tr></thead>
    <tbody><?php foreach ($sessions as $s): ?>
      <tr><td><?= h($s['started_at']) ?></td><td><?= h($s['title']) ?> <?= $s['kind'] === 'test' ? '<span class="tag">kiểm tra</span>' : '' ?></td><td><?= $s['status'] === 'running' ? '<b>đang học</b>' : 'đã kết thúc' ?><?= $s['kind'] === 'test' && !$s['released'] ? ' · <span class="muted small">chưa công bố</span>' : '' ?></td>
        <td class="right"><a href="session.php?id=<?= $s['id'] ?>">Điều khiển</a> · <a href="results.php?id=<?= $s['id'] ?>">Kết quả</a></td></tr>
    <?php endforeach; ?></tbody>
  </table>
</section>
<?php endif; ?>

<details class="danger-zone"><summary>Xoá lớp</summary>
  <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="action" value="delete_class">
    <label>Gõ XOA để xác nhận (xoá cả kết quả các buổi) <input name="confirm"></label>
    <button class="danger">Xoá lớp</button></form>
</details>
<?php page_foot();
