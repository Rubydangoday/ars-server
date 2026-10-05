<?php
// Thi đua của học sinh: điểm tích luỹ, bảng xếp hạng lớp, đổi avatar / huy hiệu / quà ở lớp.
require __DIR__ . '/../lib/lms.php';
require __DIR__ . '/../lib/rewards.php';
$me = require_student();
$code = $me['code'];
$period = isset(PERIODS[$_GET['period'] ?? '']) ? $_GET['period'] : 'week';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (($_POST['action'] ?? '') === 'redeem') { flash(redeem_reward($code, (int)($_POST['reward_id'] ?? 0))); fx_once('celebrate'); }
        elseif (($_POST['action'] ?? '') === 'avatar') {
            $icon = (string)($_POST['avatar'] ?? '');
            $own = array_column(array_filter(owned_rewards($code), fn($r) => $r['kind'] === 'avatar' && $r['status'] === 'granted'), 'icon');
            if ($icon !== '' && !in_array($icon, $own, true)) throw new RuntimeException('Bạn chưa có avatar này.');
            q('INSERT INTO student_profiles (student_code, avatar) VALUES (?,?) ON DUPLICATE KEY UPDATE avatar=VALUES(avatar)', [$code, $icon ?: null]);
            flash('Đã đổi avatar.');
        }
    } catch (Throwable $e) { flash($e->getMessage(), 'err'); }
    redirect('rewards.php?period=' . $period);
}

$owned = owned_rewards($code);
$avatar = qv('SELECT avatar FROM student_profiles WHERE student_code=?', [$code]);
student_head('Thi đua', 'rewards.php');
echo '<h1>🏆 Thi đua</h1><p class="muted">Mỗi câu bấm Remote đúng trên lớp được ' . POINTS_REMOTE . ' điểm, mỗi câu bài tập về nhà làm tốt được tới ' . POINTS_HW . ' điểm. Dùng điểm để đổi quà nhé!</p>';
$avatars = array_values(array_filter($owned, fn($r) => $r['kind'] === 'avatar'));
$badges = array_values(array_filter($owned, fn($r) => $r['kind'] === 'badge'));
if ($avatars || $badges) {
    echo '<section class="card"><h2>Bộ sưu tập của bạn</h2>';
    if ($badges) echo '<p>' . implode(' ', array_map(fn($b) => '<span class="tag" title="' . h($b['name']) . '">' . h($b['icon'] . ' ' . $b['name']) . '</span>', $badges)) . '</p>';
    if ($avatars) {
        echo '<form method="post" class="row">' . csrf_field() . '<input type="hidden" name="action" value="avatar"><label>Avatar <select name="avatar"><option value="">Chữ cái tên</option>';
        foreach ($avatars as $a) echo '<option value="' . h($a['icon']) . '"' . ($avatar === $a['icon'] ? ' selected' : '') . '>' . h($a['icon'] . ' ' . $a['name']) . '</option>';
        echo '</select></label><button>Dùng avatar</button></form>';
    }
    echo '</section>';
}
foreach (my_classes($code) as $cl) {
    $cid = (int)$cl['class_id'];
    $c = q1('SELECT name, leaderboard_visible FROM classes WHERE id=?', [$cid]);
    $b = points_balance($cid, $code);
    echo '<section class="card"><h2>' . h($c['name']) . '</h2><p>Điểm của bạn: <span class="pts-big">' . $b['balance'] . '</span> <span class="muted small">(tích luỹ ' . $b['earned'] . ', đã đổi ' . $b['spent'] . ')</span></p>';
    if ($c['leaderboard_visible']) {
        echo '<h3>Bảng xếp hạng</h3><p>';
        foreach (PERIODS as $k => $lab) echo '<a class="btn small' . ($k === $period ? ' primary' : '') . '" href="rewards.php?period=' . $k . '">' . $lab . '</a> ';
        echo '</p>' . leaderboard_html(class_points($cid, period_since($period)), $code, 10);
    }
    $shop = qall('SELECT * FROM rewards WHERE class_id=? AND active=1 ORDER BY kind, cost', [$cid]);
    if ($shop) {
        $have = array_column(array_filter($owned, fn($r) => $r['kind'] !== 'gift'), 'id');
        echo '<h3>Đổi quà</h3><div class="shop">';
        foreach ($shop as $r) {
            $got = in_array($r['id'], $have);
            $out = $r['stock'] !== null && (int)$r['stock'] <= 0;
            echo '<div class="item"><div class="icon">' . h($r['icon']) . '</div><b>' . h($r['name']) . '</b><div class="muted small">' . REWARD_KINDS[$r['kind']] . ' · ' . (int)$r['cost'] . ' điểm' . ($r['stock'] !== null ? ' · còn ' . (int)$r['stock'] : '') . '</div>'
               . '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="redeem"><input type="hidden" name="reward_id" value="' . $r['id'] . '">'
               . ($got ? '<span class="tag live">Đã có</span>' : '<button class="primary small"' . ($out || $b['balance'] < (int)$r['cost'] ? ' disabled' : '') . ' onclick="return confirm(\'Đổi ' . (int)$r['cost'] . ' điểm lấy quà này?\')">' . ($out ? 'Hết quà' : 'Đổi') . '</button>')
               . '</form></div>';
        }
        echo '</div>';
    }
    $mine = qall('SELECT rr.*, r.name, r.icon FROM reward_redemptions rr JOIN rewards r ON r.id=rr.reward_id WHERE rr.class_id=? AND rr.student_code=? AND r.kind="gift" ORDER BY rr.id DESC LIMIT 10', [$cid, $code]);
    if ($mine) {
        echo '<h3>Quà ở lớp của bạn</h3>';
        foreach ($mine as $m) echo '<p>' . h($m['icon'] . ' ' . $m['name']) . ' · ' . ['pending' => 'chờ thầy cô trao', 'delivered' => '✅ đã nhận', 'rejected' => 'không được duyệt (đã hoàn điểm)', 'granted' => ''][$m['status']] . '</p>';
    }
    echo '</section>';
}
student_foot();
