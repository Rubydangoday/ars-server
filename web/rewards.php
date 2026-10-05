<?php
// Thi đua: bảng xếp hạng tuần/tháng (Remote đúng + bài tập về nhà), cửa hàng quà của lớp, duyệt quà thực tế.
require __DIR__ . '/lib/rewards.php';
$t = require_login();
$id = (int)($_GET['class'] ?? $_POST['class_id'] ?? 0);
$c = $id ? own_class($id) : null;
$period = isset(PERIODS[$_GET['period'] ?? '']) ? $_GET['period'] : 'week';

if ($_SERVER['REQUEST_METHOD'] === 'POST') try {
    if (!$c) throw new RuntimeException('Chọn lớp.');
    $act = $_POST['action'] ?? '';
    if ($act === 'add') {
        $kind = $_POST['kind'] ?? '';
        if (!isset(REWARD_KINDS[$kind])) throw new RuntimeException('Loại quà không hợp lệ.');
        $name = mb_substr(trim($_POST['name'] ?? ''), 0, 100); $icon = mb_substr(trim($_POST['icon'] ?? ''), 0, 8);
        $cost = (int)($_POST['cost'] ?? 0); $stock = trim($_POST['stock'] ?? '');
        if ($name === '' || $icon === '') throw new RuntimeException('Nhập tên và biểu tượng (emoji) của quà.');
        if ($cost < 1 || $cost > 100000) throw new RuntimeException('Giá đổi từ 1 đến 100000 điểm.');
        q('INSERT INTO rewards (class_id, name, kind, icon, cost, stock) VALUES (?,?,?,?,?,?)', [$id, $name, $kind, $icon, $cost, $stock === '' ? null : max(0, (int)$stock)]);
    } elseif ($act === 'samples') {
        foreach (SAMPLE_REWARDS as [$kind, $icon, $name, $cost])
            if (!qv('SELECT 1 FROM rewards WHERE class_id=? AND name=?', [$id, $name])) q('INSERT INTO rewards (class_id, name, kind, icon, cost) VALUES (?,?,?,?,?)', [$id, $name, $kind, $icon, $cost]);
    } elseif ($act === 'toggle') {
        q('UPDATE rewards SET active=1-active WHERE id=? AND class_id=?', [(int)$_POST['reward_id'], $id]);
    } elseif ($act === 'visible') {
        q('UPDATE classes SET leaderboard_visible=1-leaderboard_visible WHERE id=?', [$id]);
    } elseif ($act === 'deliver' || $act === 'reject') {
        db()->beginTransaction();
        $rr = q1('SELECT * FROM reward_redemptions WHERE id=? AND class_id=? AND status="pending" FOR UPDATE', [(int)$_POST['redemption_id'], $id]);
        if (!$rr) { db()->rollBack(); throw new RuntimeException('Yêu cầu đã được xử lý.'); }
        q('UPDATE reward_redemptions SET status=?, handled_at=NOW() WHERE id=?', [$act === 'deliver' ? 'delivered' : 'rejected', $rr['id']]);
        if ($act === 'reject') q('UPDATE rewards SET stock=stock+1 WHERE id=? AND stock IS NOT NULL', [$rr['reward_id']]);   // trả lại điểm và số lượng
        db()->commit();
    } else throw new RuntimeException('Lệnh không hợp lệ.');
    flash('Đã lưu.');
    redirect('rewards.php?class=' . $id . '&period=' . $period);
} catch (Throwable $e) { flash($e->getMessage(), 'err'); redirect('rewards.php?class=' . $id); }

page_head('Thi đua & đổi quà', 'rewards.php');
echo '<h1>Thi đua &amp; đổi quà</h1><form method="get" class="row"><select name="class">';
foreach (qall('SELECT * FROM classes WHERE teacher_id=? ORDER BY name', [$t['id']]) as $r) echo '<option value="' . $r['id'] . '"' . ((int)$r['id'] === $id ? ' selected' : '') . '>' . h($r['name']) . '</option>';
echo '</select><button>Chọn lớp</button></form><p class="muted small">Điểm thi đua: ' . POINTS_REMOTE . ' điểm/câu bấm Remote đúng (buổi đã công bố) + tối đa ' . POINTS_HW
   . ' điểm/câu bài tập về nhà (theo điểm giáo viên đã công bố). Học sinh dùng điểm tích luỹ để đổi avatar, huy hiệu hoặc quà ở lớp.</p>';
if ($c) {
    $rows = class_points($id, period_since($period));
    echo '<section class="card"><div class="row"><h2 style="margin:0">Bảng xếp hạng — ' . h($c['name']) . '</h2><span class="spacer"></span>';
    foreach (PERIODS as $k => $lab) echo '<a class="btn small' . ($k === $period ? ' primary' : '') . '" href="rewards.php?class=' . $id . '&period=' . $k . '">' . $lab . '</a> ';
    echo '<button class="small" onclick="window.print()">In / chiếu</button></div>' . leaderboard_html($rows)
       . '<form method="post" class="row" style="margin-top:8px">' . csrf_field() . '<input type="hidden" name="class_id" value="' . $id . '"><input type="hidden" name="action" value="visible">'
       . '<span class="muted small">Học sinh ' . ($c['leaderboard_visible'] ? '<b>đang thấy</b>' : '<b>không thấy</b>') . ' bảng xếp hạng của lớp.</span><button class="small">' . ($c['leaderboard_visible'] ? 'Ẩn với học sinh' : 'Cho học sinh xem') . '</button></form></section>';

    $pending = qall('SELECT rr.*, r.name, r.icon, s.full_name FROM reward_redemptions rr JOIN rewards r ON r.id=rr.reward_id LEFT JOIN students s ON s.class_id=rr.class_id AND s.student_code=rr.student_code WHERE rr.class_id=? AND rr.status="pending" ORDER BY rr.id', [$id]);
    echo '<section class="card"><h2>Quà chờ trao (' . count($pending) . ')</h2>';
    if (!$pending) echo '<p class="muted">Không có yêu cầu nào.</p>';
    foreach ($pending as $p) echo '<form method="post" class="row">' . csrf_field() . '<input type="hidden" name="class_id" value="' . $id . '"><input type="hidden" name="redemption_id" value="' . $p['id'] . '">'
        . '<span>' . h($p['icon'] . ' ' . $p['name']) . ' — <b>' . h($p['full_name'] ?? $p['student_code']) . '</b> <span class="muted small">' . h($p['created_at']) . ' · ' . (int)$p['cost'] . ' điểm</span></span>'
        . '<button name="action" value="deliver" class="primary small">Đã trao</button><button name="action" value="reject" class="small" onclick="return confirm(\'Từ chối và hoàn điểm?\')">Từ chối, hoàn điểm</button></form>';
    echo '</section><section class="card"><h2>Cửa hàng quà của lớp</h2><table class="grid"><thead><tr><th></th><th>Tên</th><th>Loại</th><th class="right">Giá</th><th class="right">Còn</th><th class="right">Đã đổi</th><th></th></tr></thead><tbody>';
    foreach (qall('SELECT r.*, (SELECT COUNT(*) FROM reward_redemptions x WHERE x.reward_id=r.id AND x.status<>"rejected") AS n FROM rewards r WHERE r.class_id=? ORDER BY r.kind, r.cost', [$id]) as $r)
        echo '<tr' . ($r['active'] ? '' : ' class="muted"') . '><td style="font-size:24px">' . h($r['icon']) . '</td><td>' . h($r['name']) . '</td><td>' . REWARD_KINDS[$r['kind']] . '</td><td class="right">' . (int)$r['cost']
           . '</td><td class="right">' . ($r['stock'] === null ? '∞' : (int)$r['stock']) . '</td><td class="right">' . (int)$r['n'] . '</td><td class="right"><form method="post" class="inline">' . csrf_field()
           . '<input type="hidden" name="class_id" value="' . $id . '"><input type="hidden" name="reward_id" value="' . $r['id'] . '"><button class="small" name="action" value="toggle">' . ($r['active'] ? 'Ngừng đổi' : 'Mở lại') . '</button></form></td></tr>';
    echo '</tbody></table><form method="post" class="row" style="margin-top:10px">' . csrf_field() . '<input type="hidden" name="class_id" value="' . $id . '"><input type="hidden" name="action" value="add">'
       . '<label>Loại <select name="kind">' . implode('', array_map(fn($k, $v) => '<option value="' . $k . '">' . $v . '</option>', array_keys(REWARD_KINDS), REWARD_KINDS)) . '</select></label>'
       . '<label>Emoji <input name="icon" maxlength="8" class="w5" placeholder="🎁" required></label><label>Tên <input name="name" maxlength="100" required></label>'
       . '<label>Giá (điểm) <input name="cost" type="number" min="1" max="100000" value="100" class="w5" required></label><label>Số lượng <input name="stock" type="number" min="0" class="w5" placeholder="∞"></label><button>Thêm quà</button></form>'
       . '<form method="post" style="margin-top:8px">' . csrf_field() . '<input type="hidden" name="class_id" value="' . $id . '"><button name="action" value="samples">Tạo bộ quà mẫu (avatar, huy hiệu, quà lớp)</button></form></section>';
}
echo '<style>@media print{header.top,nav,form,.btn,button,.card:not(:first-of-type){display:none!important}main{max-width:none}.lb{font-size:20px}}</style>';
page_foot();
