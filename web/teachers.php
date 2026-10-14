<?php
// Quản trị giáo viên (chỉ quản trị viên): mã mời, chế độ đăng ký, khoá/mở, cấp quyền, đặt lại mật khẩu.
require __DIR__ . '/lib/bootstrap.php';
$me = require_admin();
$act = $_POST['action'] ?? '';
$tid = (int)($_POST['tid'] ?? 0);

function new_invite(): string {
    $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; $c = 'GV-';
    for ($i = 0; $i < 8; $i++) $c .= ($i === 4 ? '-' : '') . $abc[random_int(0, strlen($abc) - 1)];
    setting_set('teacher_invite_code', $c);
    return $c;
}

if ($act === 'signup') {
    $m = $_POST['mode'] ?? 'invite';
    setting_set('teacher_signup', in_array($m, ['invite', 'open', 'closed'], true) ? $m : 'invite');
    if ($m === 'invite' && !setting('teacher_invite_code')) new_invite();
    flash('Đã lưu chế độ đăng ký.'); redirect('teachers.php');
}
if ($act === 'new_invite') { new_invite(); flash('Đã tạo mã mời mới. Mã cũ hết hiệu lực.'); redirect('teachers.php'); }

if ($tid && $act !== '') {
    $row = q1('SELECT * FROM teachers WHERE id=?', [$tid]);
    if (!$row) redirect('teachers.php');
    $self = $tid === (int)$me['id'];
    $n_admin = (int)qv('SELECT COUNT(*) FROM teachers WHERE is_admin=1 AND active=1');
    if ($act === 'toggle_active') {
        if ($self) flash('Không thể tự khoá tài khoản của mình.', 'err');
        else { q('UPDATE teachers SET active=1-active WHERE id=?', [$tid]); flash(($row['active'] ? 'Đã khoá ' : 'Đã mở khoá ') . $row['username'] . '.'); }
    } elseif ($act === 'toggle_admin') {
        if ($self) flash('Không thể tự bỏ quyền quản trị của mình.', 'err');
        elseif ((int)$row['is_admin'] && $n_admin <= 1) flash('Phải còn ít nhất một quản trị viên.', 'err');
        else { q('UPDATE teachers SET is_admin=1-is_admin WHERE id=?', [$tid]); flash('Đã đổi quyền của ' . $row['username'] . '.'); }
    } elseif ($act === 'verify') {                  // duyệt giáo viên đăng ký chưa có mã mời
        q('UPDATE teachers SET verified=1 WHERE id=?', [$tid]); flash('Đã xác nhận ' . $row['username'] . '.');
    } elseif ($act === 'reset_pw') {
        $abc = 'abcdefghjkmnpqrstuvwxyz23456789'; $pw = '';
        for ($i = 0; $i < 10; $i++) $pw .= $abc[random_int(0, strlen($abc) - 1)];
        q('UPDATE teachers SET password_hash=? WHERE id=?', [password_hash($pw, PASSWORD_DEFAULT), $tid]);
        $_SESSION['temp_pw'] = [$row['username'], $pw];
    }
    redirect('teachers.php');
}

$mode = setting('teacher_signup', 'invite');
$invite = setting('teacher_invite_code', '') ?: ($mode === 'invite' ? new_invite() : '');
$n_unverified = (int)qv('SELECT COUNT(*) FROM teachers WHERE verified=0');
$rows = qall('SELECT t.*, (SELECT COUNT(*) FROM classes c WHERE c.teacher_id=t.id) AS n_class, '
           . '(SELECT COUNT(*) FROM sessions s JOIN classes c ON c.id=s.class_id WHERE c.teacher_id=t.id AND s.status="running") AS n_run '
           . 'FROM teachers t ORDER BY t.id');
page_head('Giáo viên', 'teachers.php');
?>
<div class="page-head"><div><h1>👥 Giáo viên dùng server</h1><p class="muted">Mời giáo viên khác, cấp quyền quản trị, khoá tài khoản hoặc đặt lại mật khẩu.</p></div></div>
<?php if (!empty($_SESSION['temp_pw'])): [$u, $pw] = $_SESSION['temp_pw']; unset($_SESSION['temp_pw']); ?>
  <div class="flash warn">Mật khẩu tạm của <b><?= h($u) ?></b>: <code style="font-size:16px"><?= h($pw) ?></code> — gửi cho giáo viên, nhắc đổi ở <i>Cài đặt → Tài khoản</i> sau khi đăng nhập. Mật khẩu chỉ hiện một lần.</div>
<?php endif; ?>

<section class="card">
  <h2>Mời giáo viên khác</h2>
  <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="action" value="signup">
    <label>Chế độ đăng ký
      <select name="mode" onchange="this.form.submit()">
        <option value="invite" <?= $mode === 'invite' ? 'selected' : '' ?>>Cần mã mời (khuyên dùng)</option>
        <option value="open" <?= $mode === 'open' ? 'selected' : '' ?>>Ai có link cũng đăng ký được</option>
        <option value="closed" <?= $mode === 'closed' ? 'selected' : '' ?>>Đóng đăng ký</option>
      </select></label>
  </form>
  <?php if ($mode === 'invite'): ?>
    <?= link_box($invite, 'Mã mời giáo viên', false) ?>
    <?= link_box(share_base() . '/ars/register.php?code=' . rawurlencode($invite), 'Link đăng ký (đã điền mã)') ?>
    <p class="muted small">Giáo viên chưa có mã vẫn tạo được tài khoản và vào web ngay, nhưng ở trạng thái <b>chưa xác nhận</b>: trợ lý AI chỉ chạy chế độ Cơ bản, chưa tải tệp lên được. Họ nhập mã ở thanh nhắc đầu trang, hoặc bạn bấm <b>Duyệt</b> trong danh sách dưới đây.</p>
    <form method="post" onsubmit="return confirm('Tạo mã mời mới? Mã cũ sẽ không dùng được nữa.')"><?= csrf_field() ?><input type="hidden" name="action" value="new_invite"><button>Tạo mã mời mới</button></form>
  <?php elseif ($mode === 'open'): ?>
    <p class="flash warn small">Khi server có link Internet công khai, chế độ này cho phép bất kỳ ai tạo tài khoản giáo viên. Chỉ nên dùng trong mạng nội bộ.</p>
    <?= link_box(share_base() . '/ars/register.php', 'Link đăng ký') ?>
  <?php endif; ?>
  <p class="muted small">Mỗi giáo viên chỉ thấy lớp, bộ câu hỏi, bài giảng và kết quả của mình. Mã lớp (room) không được trùng giữa các giáo viên để clicker không nhầm lớp.</p>
</section>

<section class="card">
  <h2>Danh sách (<?= count($rows) ?>)<?= $n_unverified ? ' <span class="tag warn">' . $n_unverified . ' chưa xác nhận</span>' : '' ?></h2>
  <table class="grid">
    <thead><tr><th>Họ tên</th><th>Tên đăng nhập</th><th>Vai trò</th><th>Trạng thái</th><th class="c">Lớp</th><th>Đăng nhập gần nhất</th><th>Ngày tạo</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $self = (int)$r['id'] === (int)$me['id']; ?>
      <tr>
        <td><?= h($r['full_name']) ?><?= $self ? ' <span class="tag">bạn</span>' : '' ?></td>
        <td><code><?= h($r['username']) ?></code></td>
        <td><?= (int)$r['is_admin'] ? '<span class="tag gold">quản trị</span>' : 'giáo viên' ?></td>
        <td><?= (int)$r['active'] ? '<span class="tag live">hoạt động</span>' : '<span class="tag off">đã khoá</span>' ?><?= (int)$r['n_run'] ? ' <span class="tag live">đang dạy</span>' : '' ?><?= (int)$r['verified'] ? '' : ' <span class="tag warn" title="Đăng ký chưa có mã mời: AI chế độ Cơ bản, chưa tải tệp lên">chưa xác nhận</span>' ?></td>
        <td class="c"><?= (int)$r['n_class'] ?></td>
        <td class="small"><?= h($r['last_login'] ?? '—') ?></td>
        <td class="small"><?= h(substr((string)$r['created_at'], 0, 10)) ?></td>
        <td class="right" style="white-space:nowrap">
          <?php if (!$self): ?>
            <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="tid" value="<?= (int)$r['id'] ?>">
              <?php if (!(int)$r['verified']): ?><button name="action" value="verify" class="primary">Duyệt</button><?php endif; ?>
              <button name="action" value="toggle_active"><?= (int)$r['active'] ? 'Khoá' : 'Mở khoá' ?></button>
              <button name="action" value="toggle_admin"><?= (int)$r['is_admin'] ? 'Bỏ quản trị' : 'Cấp quản trị' ?></button>
              <button name="action" value="reset_pw" onclick="return confirm('Đặt mật khẩu tạm mới cho <?= h($r['username']) ?>?')">Đặt lại MK</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php page_foot();
