<?php
// Thi đua: bảng xếp hạng tuần/tháng (Remote đúng + bài tập về nhà), cửa hàng quà của lớp, duyệt quà thực tế.
require __DIR__ . '/lib/rewards.php';
$t = require_login();
$myc = qall('SELECT * FROM classes WHERE teacher_id=? ORDER BY name', [$t['id']]);
$id = (int)($_GET['class'] ?? $_POST['class_id'] ?? ($myc[0]['id'] ?? 0));
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
echo '<div class="page-head"><div><h1>🏆 Thi đua &amp; đổi quà</h1><p class="muted">' . te('Điểm thi đua: {a} điểm/câu bấm Remote đúng (buổi đã công bố) + tối đa {b} điểm/câu bài tập về nhà. Học sinh dùng điểm tích luỹ để đổi avatar, huy hiệu hoặc quà ở lớp.', ['a' => POINTS_REMOTE, 'b' => POINTS_HW]) . '</p></div>'
   . ($c ? '<button type="button" onclick="window.print()">🖥 ' . te('In / chiếu bảng xếp hạng') . '</button>' : '') . '</div>';
if (!$myc) { echo '<div class="card">' . ui_empty('🏫', t('Chưa có lớp nào'), t('Tạo lớp và thêm học sinh ở tab Lớp học trước.'), '<a class="btn primary" href="classes.php">' . te('Đến Lớp học') . '</a>') . '</div>'; page_foot(); exit; }
echo '<nav class="pill-tabs">'; foreach ($myc as $r) echo '<a href="rewards.php?class=' . $r['id'] . '&period=' . $period . '" class="' . ((int)$r['id'] === $id ? 'on' : '') . '">' . h($r['name']) . '</a>'; echo '</nav>';
if ($c) {
    $rows = class_points($id, period_since($period));
    $ranked = array_values(array_filter($rows, fn($r) => $r['points'] > 0));
    $pending = qall('SELECT rr.*, r.name, r.icon, s.full_name FROM reward_redemptions rr JOIN rewards r ON r.id=rr.reward_id LEFT JOIN students s ON s.class_id=rr.class_id AND s.student_code=rr.student_code WHERE rr.class_id=? AND rr.status="pending" ORDER BY rr.id', [$id]);
    $shop = qall('SELECT r.*, (SELECT COUNT(*) FROM reward_redemptions x WHERE x.reward_id=r.id AND x.status<>"rejected") AS n FROM rewards r WHERE r.class_id=? ORDER BY r.kind, r.cost', [$id]);
    echo '<div class="ui-kpis">' . ui_kpi('⭐', (string)array_sum(array_column($rows, 'points')), t('Tổng điểm của lớp'), h(PERIODS[$period]), 'orange')
       . ui_kpi('🙋', count($ranked) . '/' . count($rows), t('Học sinh có điểm'), '', 'blue')
       . ui_kpi('🎁', (string)count($pending), t('Quà chờ trao'), '', 'pink')
       . ui_kpi('🛍', (string)count(array_filter($shop, fn($r) => $r['active'])), t('Món trong cửa hàng'), te('{n} lượt đã đổi', ['n' => array_sum(array_column($shop, 'n'))]), 'violet') . '</div>';
    echo '<div class="rw-grid"><section class="card rw-board">'
       . ui_card_head('🏅 ' . te('Bảng xếp hạng'), $c['name'], '<nav class="seg-mini">' . implode('', array_map(fn($k, $lab) => '<a href="rewards.php?class=' . $id . '&period=' . $k . '" class="' . ($k === $period ? 'on' : '') . '">' . te($lab) . '</a>', array_keys(PERIODS), PERIODS)) . '</nav>');
    if (!$ranked) echo ui_empty('🏁', t('Chưa có điểm trong khoảng này'), t('Điểm cộng khi buổi học được công bố kết quả hoặc bài tập về nhà được chấm.'));
    else {
        echo '<div class="podium">';
        foreach ([1, 0, 2] as $i) if (isset($ranked[$i])) { $r = $ranked[$i];
            echo '<div class="pd pd-' . ($i + 1) . '"><span class="pd-ava">' . h($r['avatar'] ?: mb_strtoupper(mb_substr(trim(strrchr(' ' . $r['name'], ' ')), 0, 1))) . '</span><b>' . h($r['name']) . '</b><span class="pd-pts">' . (int)$r['points'] . ' ' . te('điểm') . '</span><div class="pd-bar">' . ['🥇', '🥈', '🥉'][$i] . '</div></div>'; }
        echo '</div>' . leaderboard_html($rows);
    }
    echo '<form method="post" class="rw-vis">' . csrf_field() . '<input type="hidden" name="class_id" value="' . $id . '"><input type="hidden" name="action" value="visible">'
       . '<span>' . ($c['leaderboard_visible'] ? '👁 ' . te('Học sinh đang thấy bảng xếp hạng của lớp') : '🙈 ' . te('Học sinh không thấy bảng xếp hạng của lớp')) . '</span><button class="small">' . ($c['leaderboard_visible'] ? te('Ẩn với học sinh') : te('Cho học sinh xem')) . '</button></form></section>';
    echo '<aside class="rw-side"><section class="card">' . ui_card_head('🎁 ' . te('Quà chờ trao') . ' <span class="tag info">' . count($pending) . '</span>', t('Học sinh đã đổi điểm lấy quà ở lớp — trao quà rồi bấm “Đã trao”.'));
    if (!$pending) echo ui_empty('📦', t('Không có yêu cầu nào.'));
    foreach ($pending as $p) echo '<form method="post" class="rw-req">' . csrf_field() . '<input type="hidden" name="class_id" value="' . $id . '"><input type="hidden" name="redemption_id" value="' . $p['id'] . '">'
        . '<span class="rw-ico">' . h($p['icon']) . '</span><div><b>' . h($p['name']) . '</b><small>' . h($p['full_name'] ?? $p['student_code']) . ' · ' . (int)$p['cost'] . ' ' . te('điểm') . '</small></div>'
        . '<div class="rw-act"><button name="action" value="deliver" class="primary small">' . te('Đã trao') . '</button><button name="action" value="reject" class="small ghost" onclick="return confirm(\'' . te('Từ chối và hoàn điểm?') . '\')">' . te('Từ chối') . '</button></div></form>';
    echo '</section></aside></div>';
    echo '<section class="card">' . ui_card_head('🛍 ' . te('Cửa hàng quà của lớp'), t('Avatar và huy hiệu học sinh tự đổi; quà ở lớp cần giáo viên trao tận tay.'),
        '<form method="post">' . csrf_field() . '<input type="hidden" name="class_id" value="' . $id . '"><button class="small" name="action" value="samples">✨ ' . te('Tạo bộ quà mẫu') . '</button></form>');
    echo '<div class="shop">';
    foreach ($shop as $r) echo '<div class="shop-item' . ($r['active'] ? '' : ' off') . '"><span class="shop-ico">' . h($r['icon']) . '</span><b>' . h($r['name']) . '</b><span class="tag">' . te(REWARD_KINDS[$r['kind']]) . '</span>'
        . '<div class="shop-meta"><span>⭐ ' . (int)$r['cost'] . '</span><span>' . te('Còn') . ' ' . ($r['stock'] === null ? '∞' : (int)$r['stock']) . '</span><span>' . te('Đã đổi') . ' ' . (int)$r['n'] . '</span></div>'
        . '<form method="post">' . csrf_field() . '<input type="hidden" name="class_id" value="' . $id . '"><input type="hidden" name="reward_id" value="' . $r['id'] . '"><button class="small ghost" name="action" value="toggle">' . ($r['active'] ? te('Ngừng đổi') : te('Mở lại')) . '</button></form></div>';
    echo '<details class="shop-item shop-new"><summary><span class="shop-ico">＋</span><b>' . te('Thêm quà') . '</b></summary><form method="post" class="stack">' . csrf_field() . '<input type="hidden" name="class_id" value="' . $id . '"><input type="hidden" name="action" value="add">'
       . '<label>' . te('Loại') . ' <select name="kind">' . implode('', array_map(fn($k, $v) => '<option value="' . $k . '">' . te($v) . '</option>', array_keys(REWARD_KINDS), REWARD_KINDS)) . '</select></label>'
       . '<div class="row" style="margin:0"><label style="width:80px">Emoji <input name="icon" maxlength="8" placeholder="🎁" required></label><label style="flex:1">' . te('Tên') . ' <input name="name" maxlength="100" required></label></div>'
       . '<div class="row" style="margin:0"><label style="flex:1">' . te('Giá (điểm)') . ' <input name="cost" type="number" min="1" max="100000" value="100" required></label><label style="flex:1">' . te('Số lượng') . ' <input name="stock" type="number" min="0" placeholder="∞"></label></div>'
       . '<button class="primary">' . te('Thêm quà') . '</button></form></details></div></section>';
}
echo '<style>@media print{header.top,nav,form,.btn,button,.ui-kpis,.rw-side,.card:not(.rw-board),.page-head p{display:none!important}main{max-width:none}.lb{font-size:20px}.rw-grid{display:block}}</style>';
page_foot();
