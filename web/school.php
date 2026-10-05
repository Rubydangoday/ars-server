<?php
// Bảng điều khiển Admin trường (Ban giám hiệu / chủ trung tâm): giáo viên, lớp hành chính + GV chủ nhiệm, lớp bộ môn.
require __DIR__ . '/lib/school.php';
$me = require_login();
$school = require_school_admin();
$sid = (int)$school['id'];

function school_teacher(int $sid, int $tid): array {
    $r = q1('SELECT * FROM teachers WHERE id=? AND school_id=?', [$tid, $sid]);
    if (!$r) throw new RuntimeException('Giáo viên không thuộc trường.');
    return $r;
}
function temp_password(): string {
    $abc = 'abcdefghjkmnpqrstuvwxyz23456789'; $pw = '';
    for ($i = 0; $i < 10; $i++) $pw .= $abc[random_int(0, strlen($abc) - 1)];
    return $pw;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') try {
    $act = $_POST['action'] ?? '';
    if ($act === 'create_teacher') {
        $u = trim($_POST['username'] ?? ''); $name = trim($_POST['full_name'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9_.]{3,50}$/', $u)) throw new RuntimeException('Tên đăng nhập 3–50 ký tự: chữ không dấu, số, dấu chấm, gạch dưới.');
        if ($name === '') throw new RuntimeException('Nhập họ tên giáo viên.');
        if (qv('SELECT 1 FROM teachers WHERE username=?', [$u])) throw new RuntimeException('Tên đăng nhập đã có.');
        $pw = temp_password();
        q('INSERT INTO teachers (username, full_name, password_hash, school_id, school_role) VALUES (?,?,?,?,?)',
          [$u, mb_substr($name, 0, 100), password_hash($pw, PASSWORD_DEFAULT), $sid, ($_POST['school_role'] ?? '') === 'school_admin' ? 'school_admin' : 'teacher']);
        $_SESSION['temp_pw'] = [$u, $pw];
    } elseif (in_array($act, ['toggle_active', 'toggle_role', 'reset_pw'], true)) {
        $t = school_teacher($sid, (int)($_POST['tid'] ?? 0));
        if ((int)$t['id'] === (int)$me['id']) throw new RuntimeException('Không tự đổi tài khoản của mình ở đây.');
        if ((int)$t['is_admin'] && !is_admin()) throw new RuntimeException('Tài khoản quản trị server chỉ quản trị server đổi được.');
        if ($act === 'toggle_active') q('UPDATE teachers SET active=1-active WHERE id=?', [$t['id']]);
        elseif ($act === 'toggle_role') q('UPDATE teachers SET school_role=IF(school_role="school_admin","teacher","school_admin") WHERE id=?', [$t['id']]);
        else { $pw = temp_password(); q('UPDATE teachers SET password_hash=? WHERE id=?', [password_hash($pw, PASSWORD_DEFAULT), $t['id']]); $_SESSION['temp_pw'] = [$t['username'], $pw]; }
    } elseif ($act === 'cohort') {
        $name = mb_substr(trim($_POST['name'] ?? ''), 0, 30);
        if ($name === '') throw new RuntimeException('Nhập tên lớp hành chính, VD 10A1.');
        $hr = (int)($_POST['homeroom'] ?? 0);
        if ($hr) school_teacher($sid, $hr);
        q('INSERT INTO school_cohorts (school_id, name, homeroom_teacher_id) VALUES (?,?,?) ON DUPLICATE KEY UPDATE homeroom_teacher_id=VALUES(homeroom_teacher_id)',
          [$sid, $name, $hr ?: null]);
    } elseif ($act === 'class_meta') {
        $c = q1('SELECT c.id FROM classes c JOIN teachers t ON t.id=c.teacher_id WHERE c.id=? AND t.school_id=?', [(int)($_POST['class_id'] ?? 0), $sid]);
        if (!$c) throw new RuntimeException('Lớp không thuộc trường.');
        q('UPDATE classes SET cohort=?, subject=? WHERE id=?', [mb_substr(trim($_POST['cohort'] ?? ''), 0, 30) ?: null, mb_substr(trim($_POST['subject'] ?? ''), 0, 60) ?: null, $c['id']]);
    } else throw new RuntimeException('Lệnh không hợp lệ.');
    flash('Đã lưu.');
    redirect('school.php');
} catch (Throwable $e) { flash($e->getMessage(), 'err'); redirect('school.php'); }

$teachers = qall('SELECT t.*, (SELECT COUNT(*) FROM classes c WHERE c.teacher_id=t.id) AS n_class FROM teachers t WHERE t.school_id=? ORDER BY t.full_name', [$sid]);
$classes = qall('SELECT c.*, t.full_name AS teacher_name, (SELECT COUNT(*) FROM students s WHERE s.class_id=c.id) AS n_students, '
    . '(SELECT COUNT(*) FROM sessions x WHERE x.class_id=c.id AND x.status="ended") AS n_sessions, '
    . '(SELECT MAX(x.started_at) FROM sessions x WHERE x.class_id=c.id) AS last_at '
    . 'FROM classes c JOIN teachers t ON t.id=c.teacher_id WHERE t.school_id=? ORDER BY c.cohort IS NULL, c.cohort, c.subject, c.name', [$sid]);
$cohorts = [];
foreach (qall('SELECT sc.*, t.full_name AS homeroom_name FROM school_cohorts sc LEFT JOIN teachers t ON t.id=sc.homeroom_teacher_id WHERE sc.school_id=? ORDER BY sc.name', [$sid]) as $r) $cohorts[$r['name']] = $r;
foreach ($classes as $c) if ($c['cohort'] !== null && !isset($cohorts[$c['cohort']])) $cohorts[$c['cohort']] = ['name' => $c['cohort'], 'homeroom_teacher_id' => null, 'homeroom_name' => null];
ksort($cohorts, SORT_NATURAL);
$n_students = (int)qv('SELECT COUNT(DISTINCT COALESCE(s.student_code, CONCAT(s.class_id,"#",s.seat_no))) FROM students s JOIN classes c ON c.id=s.class_id JOIN teachers t ON t.id=c.teacher_id WHERE t.school_id=?', [$sid]);
$n_recent = (int)qv('SELECT COUNT(*) FROM sessions x JOIN classes c ON c.id=x.class_id JOIN teachers t ON t.id=c.teacher_id WHERE t.school_id=? AND x.started_at > NOW() - INTERVAL 30 DAY', [$sid]);

page_head('Trường của tôi', 'school.php');
?>
<div class="page-head"><div><h1><?= h($school['name']) ?></h1><p class="muted">Admin trường: quản lý giáo viên, gán GV chủ nhiệm cho lớp hành chính, xem báo cáo mọi lớp trong trường.</p></div></div>
<?php if (!empty($_SESSION['temp_pw'])): [$u, $pw] = $_SESSION['temp_pw']; unset($_SESSION['temp_pw']); ?>
  <div class="flash warn">Mật khẩu tạm của <b><?= h($u) ?></b>: <code style="font-size:16px"><?= h($pw) ?></code> — gửi riêng cho giáo viên, nhắc đổi ở <i>Cài đặt → Tài khoản</i>. Chỉ hiện một lần.</div>
<?php endif; ?>
<div class="kpis">
  <div><b><?= count($teachers) ?></b><span>giáo viên</span></div>
  <div><b><?= count($cohorts) ?></b><span>lớp hành chính</span></div>
  <div><b><?= count($classes) ?></b><span>lớp bộ môn</span></div>
  <div><b><?= $n_students ?></b><span>học sinh</span></div>
  <div><b><?= $n_recent ?></b><span>buổi Remote 30 ngày qua</span></div>
</div>

<section class="card">
  <h2>Lớp hành chính &amp; GV chủ nhiệm</h2>
  <table class="grid"><thead><tr><th>Lớp</th><th>GV chủ nhiệm</th><th>Lớp bộ môn</th><th></th></tr></thead><tbody>
  <?php foreach ($cohorts as $name => $co): $n = count(array_filter($classes, fn($c) => $c['cohort'] === (string)$name)); ?>
    <tr><td><b><?= h($name) ?></b></td>
      <td><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="cohort"><input type="hidden" name="name" value="<?= h($name) ?>">
        <select name="homeroom" onchange="this.form.submit()"><option value="0">— chưa gán —</option>
        <?php foreach ($teachers as $t): ?><option value="<?= (int)$t['id'] ?>" <?= (int)$co['homeroom_teacher_id'] === (int)$t['id'] ? 'selected' : '' ?>><?= h($t['full_name']) ?></option><?php endforeach; ?>
        </select><noscript><button>Lưu</button></noscript></form></td>
      <td><?= $n ?></td><td class="right"><a class="btn small" href="cohort.php?name=<?= rawurlencode((string)$name) ?>">Tổng hợp lớp →</a></td></tr>
  <?php endforeach; ?>
  </tbody></table>
  <form method="post" class="row" style="margin-top:10px"><?= csrf_field() ?><input type="hidden" name="action" value="cohort">
    <label>Thêm lớp hành chính <input name="name" maxlength="30" placeholder="10A1" required></label>
    <label>GV chủ nhiệm <select name="homeroom"><option value="0">— chưa gán —</option><?php foreach ($teachers as $t): ?><option value="<?= (int)$t['id'] ?>"><?= h($t['full_name']) ?></option><?php endforeach; ?></select></label>
    <button>Thêm</button></form>
  <p class="muted small">GV chủ nhiệm xem được báo cáo (chỉ đọc) và liên lạc phụ huynh của mọi lớp bộ môn gắn cùng lớp hành chính. Giáo viên bộ môn tự điền “Lớp hành chính” trong trang lớp, hoặc Admin trường điền ở bảng dưới.</p>
</section>

<section class="card">
  <h2>Lớp bộ môn (<?= count($classes) ?>)</h2>
  <table class="grid compact"><thead><tr><th>Lớp hành chính</th><th>Môn</th><th>Tên lớp</th><th>Giáo viên</th><th class="c">HS</th><th class="c">Buổi</th><th>Dạy gần nhất</th><th></th></tr></thead><tbody>
  <?php foreach ($classes as $c): $f = 'cm' . (int)$c['id']; ?>
    <tr><td><input form="<?= $f ?>" name="cohort" value="<?= h($c['cohort'] ?? '') ?>" maxlength="30" class="w5"></td>
      <td><input form="<?= $f ?>" name="subject" value="<?= h($c['subject'] ?? '') ?>" maxlength="60" class="w5"></td>
      <td><?= h($c['name']) ?></td><td><?= h($c['teacher_name']) ?></td><td class="c"><?= (int)$c['n_students'] ?></td><td class="c"><?= (int)$c['n_sessions'] ?></td>
      <td class="small"><?= h($c['last_at'] ?? '—') ?></td>
      <td class="right" style="white-space:nowrap"><form id="<?= $f ?>" method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="class_meta"><input type="hidden" name="class_id" value="<?= (int)$c['id'] ?>"><button class="small">Lưu</button></form>
        <a class="btn small" href="analytics.php?class=<?= (int)$c['id'] ?>">Báo cáo</a></td></tr>
  <?php endforeach; ?>
  </tbody></table>
</section>

<section class="card">
  <h2>Giáo viên (<?= count($teachers) ?>)</h2>
  <table class="grid"><thead><tr><th>Họ tên</th><th>Tên đăng nhập</th><th>Vai trò</th><th>Trạng thái</th><th class="c">Lớp</th><th>Đăng nhập gần nhất</th><th></th></tr></thead><tbody>
  <?php foreach ($teachers as $t): $self = (int)$t['id'] === (int)$me['id']; ?>
    <tr><td><?= h($t['full_name']) ?><?= $self ? ' <span class="tag">bạn</span>' : '' ?></td><td><code><?= h($t['username']) ?></code></td>
      <td><?= $t['school_role'] === 'school_admin' ? '<span class="tag gold">Admin trường</span>' : 'Giáo viên' ?></td>
      <td><?= (int)$t['active'] ? '<span class="tag live">hoạt động</span>' : '<span class="tag off">đã khoá</span>' ?></td>
      <td class="c"><?= (int)$t['n_class'] ?></td><td class="small"><?= h($t['last_login'] ?? '—') ?></td>
      <td class="right" style="white-space:nowrap"><?php if (!$self && (!(int)$t['is_admin'] || is_admin())): ?>
        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="tid" value="<?= (int)$t['id'] ?>">
          <button class="small" name="action" value="toggle_active"><?= (int)$t['active'] ? 'Khoá' : 'Mở khoá' ?></button>
          <button class="small" name="action" value="toggle_role"><?= $t['school_role'] === 'school_admin' ? 'Bỏ Admin trường' : 'Cấp Admin trường' ?></button>
          <button class="small" name="action" value="reset_pw" onclick="return confirm('Đặt mật khẩu tạm mới?')">Đặt lại MK</button></form>
      <?php endif; ?></td></tr>
  <?php endforeach; ?>
  </tbody></table>
  <form method="post" class="row" style="margin-top:10px"><?= csrf_field() ?><input type="hidden" name="action" value="create_teacher">
    <label>Họ tên <input name="full_name" required maxlength="100"></label>
    <label>Tên đăng nhập <input name="username" required pattern="[a-zA-Z0-9_.]{3,50}"></label>
    <label>Vai trò <select name="school_role"><option value="teacher">Giáo viên</option><option value="school_admin">Admin trường</option></select></label>
    <button class="primary">Tạo tài khoản</button></form>
</section>
<?php page_foot();
