<?php
// Lệnh từ bảng điều khiển (POST, JSON trả về). Mỗi lệnh ghi DB rồi tăng state_rev
// để ingest.py phát lại STATE cho gateway trong ≤ 0,3 s.
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/attendance.php';
header('Content-Type: application/json; charset=utf-8');
require_login_json();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

$ses = own_session((int)($_POST['session'] ?? 0));
$sid = (int)$ses['id'];
$act = $_POST['action'] ?? '';
$qid = (int)($_POST['question'] ?? 0);

function reply($ok, $msg = '') { echo json_encode(['ok' => $ok, 'msg' => $msg], JSON_UNESCAPED_UNICODE); exit; }
function question_of(array $ses, int $qid): array {
    $q = q1('SELECT * FROM questions WHERE id=? AND set_id=?', [$qid, $ses['set_id']]);
    if (!$q) reply(false, t('Câu hỏi không thuộc buổi này.'));
    return $q;
}
function open_q(array $ses, array $q): void {
    // mọi mốc thời gian lấy từ đồng hồ MariaDB (NOW(3)) để ingest.py và web dùng chung một đồng hồ
    $tl = $q['time_limit'] ? (int)$q['time_limit'] : null;
    q('INSERT INTO session_questions (session_id, question_id, status, opened_at, deadline) '
    . 'VALUES (?,?,"open",NOW(3),IF(? IS NULL, NULL, NOW(3) + INTERVAL ? SECOND)) '
    . 'ON DUPLICATE KEY UPDATE status="open", closed_at=NULL, deadline=VALUES(deadline)', [$ses['id'], $q['id'], $tl, $tl]);
    q('UPDATE sessions SET current_q=? WHERE id=?', [$q['id'], $ses['id']]);
}
function close_q(int $sid, int $qid): void {
    q('UPDATE session_questions SET status="closed", closed_at=NOW(3) WHERE session_id=? AND question_id=? AND status="open"', [$sid, $qid]);
}

if ($ses['status'] !== 'running' && $act !== 'show_result') reply(false, t('Buổi học đã kết thúc.'));

switch ($act) {
    case 'open':                       // mở (hoặc mở lại) một câu và chiếu nó
        open_q($ses, question_of($ses, $qid));
        break;
    case 'close':
        close_q($sid, $qid ?: (int)$ses['current_q']);
        break;
    case 'current':                    // chỉ đổi câu đang chiếu, không mở/đóng
        question_of($ses, $qid);
        q('UPDATE sessions SET current_q=? WHERE id=?', [$qid, $sid]);
        break;
    case 'next':                       // đóng câu đang chiếu, mở câu kế tiếp
        $pos = (int)qv('SELECT position FROM questions WHERE id=?', [(int)$ses['current_q']]);
        if ($ses['current_q']) close_q($sid, (int)$ses['current_q']);
        $nq = q1('SELECT * FROM questions WHERE set_id=? AND position>? ORDER BY position LIMIT 1', [$ses['set_id'], $pos]);
        if (!$nq) { bump_state($sid); reply(true, t('Đã hết câu hỏi.')); }
        open_q($ses, $nq);
        break;
    case 'show_result':
        q('UPDATE session_questions SET show_result=? WHERE session_id=? AND question_id=?', [(int)($_POST['on'] ?? 1), $sid, $qid ?: (int)$ses['current_q']]);
        break;
    case 'allow_change':
        q('UPDATE sessions SET allow_change=? WHERE id=?', [(int)($_POST['on'] ?? 1), $sid]);
        break;
    case 'release':                    // giải phóng ghế: học sinh đăng nhập lại bằng máy khác
        q('DELETE FROM session_logins WHERE session_id=? AND seat_no=?', [$sid, (int)($_POST['seat'] ?? 0)]);
        break;
    case 'end':
        q('UPDATE session_questions SET status="closed", closed_at=NOW(3) WHERE session_id=? AND status="open"', [$sid]);
        q('UPDATE sessions SET status="ended", ended_at=NOW() WHERE id=?', [$sid]);
        // điểm danh tự động (LOGIN / có câu trả lời) → tin cho phụ huynh trong trang phụ huynh; lỗi ở đây không chặn việc kết thúc buổi
        try { parent_notify_session($sid); } catch (Throwable $e) { error_log('parent_notify_session: ' . $e->getMessage()); }
        break;
    default:
        reply(false, t('Lệnh không hợp lệ.'));
}
bump_state($sid);
reply(true);
