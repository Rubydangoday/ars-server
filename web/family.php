<?php
// Phụ huynh & nhận xét: mời phụ huynh bằng mã ngắn + QR (từng em hoặc in phiếu cả lớp), quản lý liên kết, gửi nhận xét.
// Tài khoản phụ huynh theo SĐT (lib/parent_accounts.php): cấp cả lớp + tải file SĐT / học sinh / mật khẩu lần đầu, cấp lại từng em.
require __DIR__ . '/lib/lms.php';
require __DIR__ . '/lib/school.php';
require __DIR__ . '/lib/parent_accounts.php';
$t = require_login();
$classes = family_classes();
$id = (int)($_GET['class'] ?? $_POST['class_id'] ?? ($classes[0]['id'] ?? 0));
$c = $id ? family_class($id) : null;                 // chủ lớp hoặc GV chủ nhiệm
$back = 'family.php?class=' . $id;

if ($_SERVER['REQUEST_METHOD'] === 'POST') try {
    if (!$c) throw new RuntimeException('Chọn lớp.');
    $act = $_POST['action'] ?? '';
    if ($act === 'grade_level') {
        $grade = (int)($_POST['grade_level'] ?? 0);
        if ($grade < 1 || $grade > 12) throw new RuntimeException('Khối lớp từ 1 đến 12.');
        q('UPDATE classes SET grade_level=? WHERE id=?', [$grade, $id]);
        flash('Đã lưu khối lớp.'); redirect($back);
    }
    if ($act === 'invite_all') {                     // phiếu mời cho mọi em chưa có phụ huynh liên kết
        $out = [];
        foreach (qall('SELECT s.* FROM students s WHERE s.class_id=? AND s.student_code IS NOT NULL AND s.student_code<>"" '
                    . 'AND NOT EXISTS (SELECT 1 FROM parent_links l WHERE l.class_id=s.class_id AND l.student_code=s.student_code) ORDER BY s.seat_no', [$id]) as $s)
            $out[] = ['code' => parent_invite_new($id, $s['student_code']), 'student' => $s['full_name'], 'sc' => $s['student_code'], 'seat' => (int)$s['seat_no']];
        if (!$out) throw new RuntimeException('Mọi học sinh trong lớp đã có phụ huynh liên kết.');
        $_SESSION['parent_invite_sheet'] = ['class' => $c['name'], 'items' => $out];
        redirect($back . '&sheet=1');
    }
    if ($act === 'accounts') {                       // cấp tài khoản theo SĐT phụ huynh + tải file phát cho phụ huynh
        $rows = parent_accounts_provision($id, !empty($_POST['reissue']));
        if (!$rows) throw new RuntimeException('Lớp chưa có học sinh.');
        parent_accounts_send($c, $rows, ($_POST['format'] ?? '') === 'csv' ? 'csv' : 'xlsx');
    }
    $code = trim($_POST['student_code'] ?? '');
    $st = $code !== '' ? q1('SELECT * FROM students WHERE class_id=? AND student_code=?', [$id, $code]) : null;
    if (!$st) throw new RuntimeException('Học sinh phải có mã và thuộc lớp.');
    if ($act === 'invite') {
        $_SESSION['parent_invite_once'] = ['code' => parent_invite_new($id, $code), 'student' => $st['full_name'], 'sc' => $code];
        redirect($back . '#invite');
    } elseif ($act === 'account_reset') {            // phụ huynh quên / mất phiếu: mật khẩu mới, hiện một lần
        $r = parent_accounts_provision($id, false, $code)[0];
        if ($r['password'] === '') throw new RuntimeException($r['status']);
        $_SESSION['parent_pw_once'] = $r;
        redirect($back . '#pacc');
    } elseif ($act === 'comment') {
        q('INSERT INTO teacher_comments(class_id,student_code,teacher_id,comment) VALUES(?,?,?,?)', [$id, $code, $t['id'], required_text('comment', 10000)]);
        flash('Đã gửi nhận xét cho phụ huynh.');
    } elseif ($act === 'revoke') {
        q('DELETE FROM parent_links WHERE class_id=? AND student_code=? AND parent_id=?', [$id, $code, (int)$_POST['parent_id']]);
        flash('Đã thu hồi liên kết.');
    } elseif ($act === 'cancel_invites') {
        q('UPDATE parent_invites SET expires_at=NOW() WHERE class_id=? AND student_code=? AND used_at IS NULL AND expires_at>NOW()', [$id, $code]);
        flash('Đã huỷ các mã mời đang chờ.');
    } else throw new RuntimeException('Lệnh không hợp lệ.');
    redirect($back);
} catch (Throwable $e) { flash($e->getMessage(), 'bad'); }

$invite_link = fn(string $code) => share_base() . '/parent/index.php?invite=' . rawurlencode($code);

// ---- phiếu mời cả lớp (in ra, cắt phát cho học sinh mang về)
if (isset($_GET['sheet'], $_SESSION['parent_invite_sheet'])) {
    $sh = $_SESSION['parent_invite_sheet']; unset($_SESSION['parent_invite_sheet']);
    page_head('Phiếu mời phụ huynh', 'family.php');
    echo '<div class="page-head no-print"><div><h1>🖨 Phiếu mời phụ huynh · ' . h($sh['class']) . '</h1><p class="muted">' . count($sh['items'])
       . ' phiếu. Mã chỉ hiện một lần: in ngay hoặc lưu PDF rồi phát cho từng học sinh.</p></div>'
       . '<div class="row"><button class="primary" onclick="window.print()">🖨 In / lưu PDF</button><a class="btn" href="' . h($back) . '">← Quay lại</a></div></div><div class="invite-sheet">';
    foreach ($sh['items'] as $it) {
        echo '<div class="invite-slip"><div class="is-qr" data-qr-inline="' . h($invite_link($it['code'])) . '"></div><div class="is-body">'
           . '<small>' . h(setting('org_name', 'ARS')) . ' · ' . h($sh['class']) . '</small><b>' . h($it['student']) . '</b>'
           . '<span class="muted small">' . te('Mã học sinh') . ' ' . h($it['sc']) . '</span>'
           . '<div class="is-code" translate="no">' . h($it['code']) . '</div>'
           . '<p class="small">' . te('Kính gửi phụ huynh: quét mã QR bằng camera điện thoại (hoặc mở trang Phụ huynh → Đăng ký và nhập mã) để theo dõi kết quả học tập của con.') . '</p>'
           . '<span class="muted small">' . te('Hiệu lực 7 ngày · dùng một lần') . '</span></div></div>';
    }
    echo '</div>';
    page_foot();
    exit;
}

$students = $c ? qall('SELECT * FROM students WHERE class_id=? ORDER BY seat_no', [$id]) : [];
$links = []; $pending = []; $last = [];
if ($c) {
    foreach (qall('SELECT l.*, p.full_name, p.username FROM parent_links l JOIN parents p ON p.id=l.parent_id WHERE l.class_id=?', [$id]) as $r) $links[$r['student_code']][] = $r;
    foreach (qall('SELECT student_code, COUNT(*) n, MAX(expires_at) exp FROM parent_invites WHERE class_id=? AND used_at IS NULL AND expires_at>NOW() GROUP BY student_code', [$id]) as $r) $pending[$r['student_code']] = $r;
    foreach (qall('SELECT tc.* FROM teacher_comments tc JOIN (SELECT student_code, MAX(id) m FROM teacher_comments WHERE class_id=? GROUP BY student_code) x ON x.m=tc.id', [$id]) as $r) $last[$r['student_code']] = $r;
}
$with_code = array_filter($students, fn($s) => (string)$s['student_code'] !== '');
$acc = $c ? parent_accounts_state($id) : [];
$n_phone = count(array_filter($with_code, fn($s) => normalize_phone((string)$s['parent_phone'])));
$n_wait = count(array_filter($acc, fn($a) => $a['parent'] && $a['parent']['must_change_pw']));
$n_used = count(array_filter($acc, fn($a) => $a['parent'] && !$a['parent']['must_change_pw']));
$n_linked = count(array_filter($with_code, fn($s) => !empty($links[$s['student_code']])));

page_head('Phụ huynh và nhận xét', 'family.php');
?>
<div class="page-head">
  <div><h1>👪 Phụ huynh &amp; nhận xét</h1>
    <p class="muted">Mời phụ huynh bằng mã 8 ký tự hoặc mã QR, quản lý liên kết và gửi nhận xét — phụ huynh xem ngay trên cổng Phụ huynh.</p></div>
  <?php if ($c && $with_code): ?>
  <form method="post" class="row" style="margin:0"><?= csrf_field() ?><input type="hidden" name="class_id" value="<?= $id ?>">
    <button class="primary" name="action" value="invite_all">🖨 In phiếu mời cả lớp</button></form>
  <?php endif; ?>
</div>

<?php if (!$classes): ?>
  <div class="empty-state card"><b>Chưa có lớp nào</b><p class="muted">Tạo lớp và thêm học sinh ở tab Lớp học trước.</p><a class="btn primary" href="classes.php">Đến Lớp học</a></div>
<?php page_foot(); exit; endif; ?>

<nav class="pill-tabs" aria-label="Chọn lớp">
  <?php foreach ($classes as $r): ?><a href="family.php?class=<?= (int)$r['id'] ?>" class="<?= (int)$r['id'] === $id ? 'on' : '' ?>"><?= h($r['name']) ?></a><?php endforeach; ?>
</nav>

<?php if (isset($_SESSION['parent_invite_once'])): $inv = $_SESSION['parent_invite_once']; unset($_SESSION['parent_invite_once']); if (!isset($inv['code'])) $inv = ['code' => $inv['token'] ?? '', 'student' => $inv['student'] ?? '', 'sc' => '']; ?>
<section class="card invite-once" id="invite">
  <div class="io-qr" data-qr-inline="<?= h($invite_link($inv['code'])) ?>"></div>
  <div class="io-body">
    <span class="eyebrow">Mã mời phụ huynh · <?= h($inv['student']) ?></span>
    <div class="io-code" translate="no"><?= h($inv['code']) ?></div>
    <p>Phụ huynh <b>quét mã QR</b> bằng camera điện thoại — mở thẳng trang đăng ký, mã đã điền sẵn. Hoặc mở trang Phụ huynh → <b>Đăng ký</b> và nhập mã 8 ký tự ở trên.</p>
    <?= link_box($invite_link($inv['code']), t('Link đăng ký phụ huynh')) ?>
    <div class="row" style="margin:8px 0 0"><button type="button" data-copy="<?= h($inv['code']) ?>">📋 Chép mã</button><button type="button" onclick="window.print()">🖨 In phiếu</button></div>
    <p class="muted small">⚠️ Mã chỉ hiện một lần, dùng được một lần, hết hạn sau 7 ngày. Gửi riêng cho phụ huynh đúng học sinh (Zalo / tin nhắn), không đăng lên nhóm chung.</p>
  </div>
</section>
<?php endif; ?>

<div class="stat-row">
  <div class="stat"><b><?= count($students) ?></b><span>Học sinh</span></div>
  <div class="stat"><b><?= $n_linked ?>/<?= count($with_code) ?></b><span>Đã có phụ huynh liên kết</span>
    <progress max="<?= max(1, count($with_code)) ?>" value="<?= $n_linked ?>" data-animate></progress></div>
  <div class="stat"><b><?= array_sum(array_column($pending, 'n')) ?></b><span>Mã mời đang chờ dùng</span></div>
  <form method="post" class="stat stat-form"><?= csrf_field() ?><input type="hidden" name="class_id" value="<?= $id ?>">
    <span>Khối lớp (cho gợi ý AI)</span>
    <div class="row" style="margin:0"><input name="grade_level" type="number" min="1" max="12" value="<?= (int)$c['grade_level'] ?>" style="width:80px"><button name="action" value="grade_level">Lưu</button></div></form>
</div>

<section class="card" id="pacc">
  <h2>📱 Tài khoản phụ huynh bằng số điện thoại</h2>
  <p class="muted">Phụ huynh đăng nhập bằng <b>SĐT</b> (cột "SĐT phụ huynh" trong danh sách lớp) và <b>mật khẩu lần đầu</b> do hệ thống tạo. Đăng nhập lần đầu bắt buộc đổi mật khẩu. Anh chị em cùng SĐT phụ huynh dùng chung một tài khoản.</p>
  <?php if (isset($_SESSION['parent_pw_once'])): $po = $_SESSION['parent_pw_once']; unset($_SESSION['parent_pw_once']); ?>
    <div class="flash warn">Mật khẩu mới cho phụ huynh em <b><?= h($po['student']) ?></b> — chỉ hiện một lần:<br>
      Tên đăng nhập: <code><?= h($po['phone']) ?></code> · Mật khẩu: <code translate="no"><?= h($po['password']) ?></code> · Trang đăng nhập: <code><?= h(share_base()) ?>/parent/</code><br>
      <span class="small">Phụ huynh phải đổi mật khẩu khi đăng nhập. Gửi riêng cho phụ huynh (Zalo / tin nhắn), không đăng lên nhóm chung.</span></div>
  <?php endif; ?>
  <div class="stat-row">
    <div class="stat"><b><?= $n_phone ?>/<?= count($with_code) ?></b><span>Học sinh có SĐT phụ huynh</span></div>
    <div class="stat"><b><?= $n_wait ?></b><span>Đã cấp, phụ huynh chưa đăng nhập</span></div>
    <div class="stat"><b><?= $n_used ?></b><span>Phụ huynh đã đổi mật khẩu</span></div>
  </div>
  <?php if (!$n_phone): ?><p class="muted">Chưa có SĐT phụ huynh: thêm cột <b>SĐT phụ huynh</b> vào file danh sách rồi nhập lại, hoặc bấm <b>Sửa</b> từng học sinh ở <a href="class.php?id=<?= $id ?>">trang lớp</a>.</p>
  <?php else: ?>
  <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="class_id" value="<?= $id ?>"><input type="hidden" name="action" value="accounts">
    <button class="primary">⬇ Cấp tài khoản &amp; tải file mật khẩu</button>
    <select name="format"><option value="xlsx">Excel (.xlsx)</option><option value="csv">CSV</option></select>
    <label class="check"><input type="checkbox" name="reissue" value="1"> Cấp lại mật khẩu cho phụ huynh chưa đăng nhập (phiếu cũ hết hiệu lực)</label></form>
  <p class="muted small">File gồm: STT, họ tên học sinh, MSSV, lớp, SĐT phụ huynh (tên đăng nhập), mật khẩu lần đầu, trang đăng nhập. Mật khẩu chỉ có trong file này (máy chủ chỉ lưu dạng mã hoá): cấp lần đầu thì tải file rồi in / gửi riêng cho từng phụ huynh. Bấm lại lần sau chỉ tạo mật khẩu cho học sinh mới; phụ huynh đã đổi mật khẩu không bị ảnh hưởng.</p>
  <?php endif; ?>
</section>

<section class="card flush">
  <div class="card-head"><h2>Học sinh trong lớp</h2><input type="search" class="tbl-filter" placeholder="🔎 Tìm học sinh…" data-filter="#fam-table"></div>
  <?php if (!$students): ?>
    <div class="empty-state"><b>Lớp chưa có học sinh</b><p class="muted">Thêm danh sách ở trang lớp.</p><a class="btn" href="class.php?id=<?= $id ?>">Mở trang lớp</a></div>
  <?php else: ?>
  <table class="data fam" id="fam-table">
    <thead><tr><th>#</th><th>Học sinh</th><th>Phụ huynh</th><th>Tài khoản SĐT</th><th>Nhận xét gần nhất</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($students as $s): $sc = (string)$s['student_code']; $ls = $links[$sc] ?? []; $pd = $pending[$sc] ?? null; $lc = $last[$sc] ?? null; ?>
      <tr>
        <td class="muted"><?= (int)$s['seat_no'] ?></td>
        <td><div class="who"><span class="ava"><?= h(mb_strtoupper(mb_substr(trim(strrchr(' ' . $s['full_name'], ' ')), 0, 1))) ?></span>
          <div><b><?= h($s['full_name']) ?></b><small class="muted"><?= $sc !== '' ? h($sc) : 'chưa có mã học sinh' ?></small></div></div></td>
        <td>
          <?php foreach ($ls as $l): ?>
            <form method="post" class="chip-form" onsubmit="return confirm('Thu hồi liên kết của phụ huynh này?')"><?= csrf_field() ?><input type="hidden" name="class_id" value="<?= $id ?>"><input type="hidden" name="student_code" value="<?= h($sc) ?>"><input type="hidden" name="parent_id" value="<?= (int)$l['parent_id'] ?>">
              <span class="tag ok">✓ <?= h($l['full_name']) ?></span><button class="x" name="action" value="revoke" title="Thu hồi liên kết">✕</button></form>
          <?php endforeach; ?>
          <?php if ($pd): ?><span class="tag warn" title="Hết hạn <?= h($pd['exp']) ?>">⏳ <?= (int)$pd['n'] ?> mã đang chờ</span><?php endif; ?>
          <?php if (!$ls && !$pd): ?><span class="tag">Chưa liên kết</span><?php endif; ?>
        </td>
        <td class="small"><?php $a = $acc[$sc] ?? null; $ph = (string)($s['parent_phone'] ?? ''); ?>
          <?php if ($ph === ''): ?><span class="muted">chưa có SĐT</span>
          <?php else: ?><span translate="no"><?= h($ph) ?></span><br>
            <?php if (!$a || !$a['parent']): ?><span class="tag">chưa cấp</span>
            <?php elseif ($a['parent']['must_change_pw']): ?><span class="tag warn" title="Cấp lúc <?= h((string)$a['parent']['pw_issued_at']) ?>">chờ đăng nhập lần đầu</span>
            <?php else: ?><span class="tag ok">đang dùng</span><?php endif; ?>
            <?php if ($a && $a['parent'] && $sc !== ''): ?>
            <form method="post" class="inline" onsubmit="return confirm(<?= h(json_encode('Tạo mật khẩu mới cho phụ huynh em ' . $s['full_name'] . '? Mật khẩu cũ hết hiệu lực.', JSON_UNESCAPED_UNICODE)) ?>)"><?= csrf_field() ?><input type="hidden" name="class_id" value="<?= $id ?>"><input type="hidden" name="student_code" value="<?= h($sc) ?>"><button class="link" name="action" value="account_reset">cấp lại MK</button></form>
            <?php endif; ?>
          <?php endif; ?></td>
        <td class="small"><?= $lc ? '<span class="clamp">' . h($lc['comment']) . '</span><small class="muted">' . h(substr($lc['created_at'], 0, 16)) . '</small>' : '<span class="muted">—</span>' ?></td>
        <td class="actions">
          <?php if ($sc !== ''): ?>
          <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="class_id" value="<?= $id ?>"><input type="hidden" name="student_code" value="<?= h($sc) ?>">
            <button class="small primary" name="action" value="invite">🔗 Mời phụ huynh</button>
            <?php if ($pd): ?><button class="small ghost" name="action" value="cancel_invites" title="Huỷ mã đang chờ">Huỷ mã</button><?php endif; ?></form>
          <details class="pop"><summary class="btn small">💬 Nhận xét</summary>
            <form method="post" class="pop-body stack"><?= csrf_field() ?><input type="hidden" name="class_id" value="<?= $id ?>"><input type="hidden" name="student_code" value="<?= h($sc) ?>">
              <b>Nhận xét cho phụ huynh em <?= h($s['full_name']) ?></b>
              <textarea name="comment" rows="4" required placeholder="VD: Em tiến bộ rõ ở phần phân số, cần luyện thêm bài toán có lời văn…"></textarea>
              <div class="chips"><?php foreach (['Tiến bộ rõ rệt 👏', 'Tích cực phát biểu', 'Cần chú ý làm bài tập về nhà', 'Nên ôn lại các câu sai'] as $q): ?><button type="button" class="chip" data-append="<?= h($q) ?>"><?= h($q) ?></button><?php endforeach; ?></div>
              <button class="primary" name="action" value="comment">Gửi nhận xét</button></form></details>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>
<script>
document.querySelectorAll('[data-append]').forEach((b) => b.addEventListener('click', () => {
  const t = b.closest('form').querySelector('textarea'); t.value = (t.value.trim() ? t.value.trim() + ' ' : '') + b.dataset.append + '. '; t.focus();
}));
</script>
<?php page_foot();
