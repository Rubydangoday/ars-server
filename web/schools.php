<?php
// Quản trị server: tạo trường/trung tâm, gán giáo viên vào trường và cấp quyền Admin trường (Ban giám hiệu / chủ trung tâm).
require __DIR__ . '/lib/bootstrap.php';
require_admin();
if ($_SERVER['REQUEST_METHOD'] === 'POST') try {
    $act = $_POST['action'] ?? '';
    if ($act === 'create') {
        $name = trim($_POST['name'] ?? '');
        if (!$name) throw new RuntimeException('Tên trường trống.');
        q('INSERT INTO schools(name) VALUES(?)', [mb_substr($name, 0, 200)]);
    } elseif ($act === 'assign') {
        $school = (int)$_POST['school_id'];
        if ($school && !qv('SELECT 1 FROM schools WHERE id=?', [$school])) throw new RuntimeException('Trường không tồn tại.');
        $role = $school && ($_POST['school_role'] ?? '') === 'school_admin' ? 'school_admin' : 'teacher';
        q('UPDATE teachers SET school_id=?, school_role=? WHERE id=?', [$school ?: null, $role, (int)$_POST['teacher_id']]);
    } else throw new RuntimeException('Lệnh sai.');
    redirect('schools.php');
} catch (Throwable $e) { flash($e->getMessage(), 'bad'); }

page_head('Quản lý trường', 'schools.php');
echo '<h1>Trường / Trung tâm</h1><form method="post" class="row">' . csrf_field() . '<input name="name" placeholder="Tên trường hoặc trung tâm" required><button name="action" value="create">Tạo trường</button></form>'
   . '<p class="muted small">Admin trường quản lý giáo viên của trường mình, gán GV chủ nhiệm và xem báo cáo mọi lớp trong trường (menu <b>Trường của tôi</b>). Quyền quản trị server là riêng.</p>';
$schools = qall('SELECT * FROM schools ORDER BY name');
foreach (qall('SELECT id, full_name, username, school_id, school_role FROM teachers ORDER BY full_name') as $r) {
    echo '<form method="post" class="card row">' . csrf_field() . '<input type="hidden" name="teacher_id" value="' . $r['id'] . '"><b>' . h($r['full_name']) . '</b> <code>' . h($r['username']) . '</code>'
       . '<select name="school_id"><option value="0">Chưa gán</option>';
    foreach ($schools as $sc) echo '<option value="' . $sc['id'] . '" ' . ((int)$r['school_id'] === (int)$sc['id'] ? 'selected' : '') . '>' . h($sc['name']) . '</option>';
    echo '</select><select name="school_role"><option value="teacher">Giáo viên</option><option value="school_admin" ' . ($r['school_role'] === 'school_admin' ? 'selected' : '') . '>Admin trường</option></select>'
       . '<button name="action" value="assign">Lưu</button></form>';
}
page_foot();
