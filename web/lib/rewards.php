<?php
// Thi đua & đổi quà theo từng lớp. Điểm thi đua khác XP luyện tập (stu_stats): chỉ tính kết quả giáo viên đã công bố.
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

const POINTS_REMOTE = 10;   // mỗi câu bấm Remote đúng (buổi đã kết thúc, đã công bố)
const POINTS_HW = 10;       // mỗi câu bài tập về nhà đạt điểm tối đa (tính theo tỉ lệ điểm)
const REWARD_KINDS = ['avatar' => 'Avatar', 'badge' => 'Huy hiệu', 'gift' => 'Quà ở lớp'];
const PERIODS = ['week' => 'Tuần này', 'month' => 'Tháng này', 'all' => 'Từ đầu năm'];
const SAMPLE_REWARDS = [
    ['avatar', '🦁', 'Sư tử dũng cảm', 50], ['avatar', '🐼', 'Gấu trúc chăm chỉ', 50], ['avatar', '🦊', 'Cáo thông thái', 80], ['avatar', '🚀', 'Tên lửa', 120],
    ['badge', '⭐', 'Ngôi sao tích cực', 100], ['badge', '🏅', 'Nhà vô địch Remote', 300],
    ['gift', '✏️', 'Bút chì đẹp', 200], ['gift', '🪑', 'Được chọn chỗ ngồi 1 tuần', 150], ['gift', '📒', 'Sổ tay', 250],
];

function period_since(string $p): ?string {
    return match ($p) {
        'week' => (new DateTimeImmutable('monday this week'))->format('Y-m-d 00:00:00'),
        'month' => (new DateTimeImmutable('first day of this month'))->format('Y-m-d 00:00:00'),
        default => null,
    };
}

/** Điểm thi đua của từng học sinh trong lớp kể từ $since (NULL = toàn bộ). key = seat_no; sắp xếp giảm dần, có hạng. */
function class_points(int $class_id, ?string $since = null): array {
    $out = [];
    foreach (qall('SELECT s.seat_no, s.student_code, s.full_name, p.avatar FROM students s LEFT JOIN student_profiles p ON p.student_code=s.student_code WHERE s.class_id=?', [$class_id]) as $s)
        $out[(int)$s['seat_no']] = ['seat' => (int)$s['seat_no'], 'code' => $s['student_code'], 'name' => $s['full_name'], 'avatar' => $s['avatar'], 'remote' => 0, 'hw' => 0.0];
    $cond = $since ? ' AND s.started_at>=?' : '';
    foreach (qall('SELECT f.seat_no, COUNT(*) n FROM sessions s JOIN session_questions sq ON sq.session_id=s.id JOIN questions q ON q.id=sq.question_id '
        . 'JOIN final_answers f ON f.session_q_id=sq.id AND f.answer=q.correct WHERE s.class_id=? AND s.status="ended" AND s.released=1' . $cond . ' GROUP BY f.seat_no',
        $since ? [$class_id, $since] : [$class_id]) as $r)
        if (isset($out[(int)$r['seat_no']])) $out[(int)$r['seat_no']]['remote'] = (int)$r['n'];
    $codes = [];
    foreach ($out as $k => $r) if ($r['code'] !== null && $r['code'] !== '') $codes[$r['code']] = $k;
    $cond = $since ? ' AND s.submitted_at>=?' : '';
    foreach (qall('SELECT s.student_code, SUM(a.score/hq.max_score) r FROM homework_submissions s JOIN homework h ON h.id=s.homework_id '
        . 'JOIN homework_answers a ON a.submission_id=s.id JOIN homework_questions hq ON hq.id=a.hw_question_id '
        . 'WHERE h.class_id=? AND s.released=1 AND s.submitted_at IS NOT NULL AND a.score IS NOT NULL' . $cond . ' GROUP BY s.student_code',
        $since ? [$class_id, $since] : [$class_id]) as $r)
        if (isset($codes[$r['student_code']])) $out[$codes[$r['student_code']]]['hw'] = (float)$r['r'];
    foreach ($out as &$r) $r['points'] = $r['remote'] * POINTS_REMOTE + (int)round($r['hw'] * POINTS_HW);
    unset($r);
    uasort($out, fn($a, $b) => $b['points'] <=> $a['points'] ?: strcmp($a['name'], $b['name']));
    $rank = 0; $prev = null; $i = 0;
    foreach ($out as &$r) { $i++; if ($r['points'] !== $prev) { $rank = $i; $prev = $r['points']; } $r['rank'] = $rank; }
    return $out;
}

/** Điểm còn lại để đổi quà = tổng điểm từ trước tới nay − điểm đã đổi (trừ quà bị từ chối). */
function points_balance(int $class_id, string $code): array {
    $earned = 0;
    foreach (class_points($class_id) as $r) if ($r['code'] === $code) $earned = $r['points'];
    $spent = (int)qv('SELECT COALESCE(SUM(cost),0) FROM reward_redemptions WHERE class_id=? AND student_code=? AND status<>"rejected"', [$class_id, $code]);
    return ['earned' => $earned, 'spent' => $spent, 'balance' => $earned - $spent];
}

function owned_rewards(string $code): array {
    return qall('SELECT r.*, rr.status, rr.created_at AS got_at FROM reward_redemptions rr JOIN rewards r ON r.id=rr.reward_id '
              . 'WHERE rr.student_code=? AND rr.status IN ("granted","delivered","pending") ORDER BY rr.id DESC', [$code]);
}

/** Đổi quà. Khoá dòng học sinh để hai lần bấm cùng lúc không tiêu quá số điểm. */
function redeem_reward(string $code, int $reward_id): string {
    db()->beginTransaction();
    try {
        $r = q1('SELECT * FROM rewards WHERE id=? AND active=1 FOR UPDATE', [$reward_id]);
        if (!$r) throw new RuntimeException('Quà không còn.');
        if (!q1('SELECT 1 FROM students WHERE class_id=? AND student_code=? FOR UPDATE', [$r['class_id'], $code])) throw new RuntimeException('Bạn không thuộc lớp này.');
        if ($r['kind'] !== 'gift' && qv('SELECT 1 FROM reward_redemptions WHERE reward_id=? AND student_code=? AND status<>"rejected"', [$r['id'], $code]))
            throw new RuntimeException('Bạn đã có ' . $r['name'] . ' rồi.');
        if ($r['stock'] !== null && (int)$r['stock'] <= 0) throw new RuntimeException('Quà đã hết.');
        $b = points_balance((int)$r['class_id'], $code);
        if ($b['balance'] < (int)$r['cost']) throw new RuntimeException('Chưa đủ điểm: cần ' . $r['cost'] . ', bạn có ' . $b['balance'] . '.');
        q('INSERT INTO reward_redemptions (reward_id, class_id, student_code, cost, status) VALUES (?,?,?,?,?)',
          [$r['id'], $r['class_id'], $code, $r['cost'], $r['kind'] === 'gift' ? 'pending' : 'granted']);
        if ($r['stock'] !== null) q('UPDATE rewards SET stock=stock-1 WHERE id=?', [$r['id']]);
        db()->commit();
    } catch (Throwable $e) { db()->rollBack(); throw $e; }
    return $r['kind'] === 'gift' ? 'Đã gửi yêu cầu đổi “' . $r['name'] . '”. Thầy cô sẽ trao quà ở lớp.' : 'Đã nhận ' . $r['icon'] . ' ' . $r['name'] . '!';
}

/** Bảng xếp hạng HTML. $me = mã học sinh đang xem (tô sáng). */
function leaderboard_html(array $rows, ?string $me = null, int $limit = 0): string {
    $html = '<table class="lb"><thead><tr><th class="rank">#</th><th class="ava"></th><th>Học sinh</th><th class="right">Remote đúng</th><th class="right">Bài tập</th><th class="right">Điểm</th></tr></thead><tbody>';
    $n = 0;
    foreach ($rows as $r) {
        if ($limit && ++$n > $limit && $r['code'] !== $me) continue;
        $medal = [1 => '🥇', 2 => '🥈', 3 => '🥉'][$r['rank']] ?? $r['rank'];
        $html .= '<tr' . ($me !== null && $r['code'] === $me ? ' class="me"' : '') . '><td class="rank">' . $medal . '</td><td class="ava">' . h($r['avatar'] ?? '') . '</td><td>' . h($r['name'])
               . '</td><td class="right">' . $r['remote'] . '</td><td class="right">' . round($r['hw'], 1) . '</td><td class="right"><b>' . $r['points'] . '</b></td></tr>';
    }
    return $html . '</tbody></table>';
}
