<?php
// Điểm danh buổi học (bằng Remote, tự động) + thông báo trong web cho phụ huynh.
//   Tự động: học sinh đã LOGIN bằng Remote hoặc có câu trả lời được nhận trong buổi = "có mặt" (VIEW attendance_final).
//   Giáo viên sửa: Có mặt / Vắng / Vắng có phép (bảng session_attendance) — ví dụ Remote hỏng, học sinh làm bài giấy.
//   Phụ huynh: khi buổi KẾT THÚC → mỗi buổi một tin (điểm danh, kèm điểm nếu kết quả đã công bố). Bài kiểm tra công bố
//              sau, hoặc giáo viên sửa điểm danh → CÙNG tin đó được cập nhật và hiện lại là chưa đọc.
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

const ATTEND_STATUS = ['present' => 'Có mặt', 'absent' => 'Vắng', 'excused' => 'Vắng có phép'];

/** Điểm danh của một buổi: [seat_no => [seat_no, student_code, full_name, status, source, note]] theo số ghế. */
function attendance_session(int $session_id): array {
    $out = [];
    foreach (qall('SELECT seat_no, student_code, full_name, status, source, note FROM attendance_final WHERE session_id=? ORDER BY seat_no', [$session_id]) as $r)
        $out[(int)$r['seat_no']] = $r;
    return $out;
}

/** Giáo viên đặt điểm danh một ghế; $status = null → trở về tự động (theo Remote). */
function attendance_set(int $session_id, int $seat, ?string $status, int $teacher_id, string $note = ''): void {
    if ($status === null || $status === '' || $status === 'auto') {
        q('DELETE FROM session_attendance WHERE session_id=? AND seat_no=?', [$session_id, $seat]);
        return;
    }
    if (!isset(ATTEND_STATUS[$status])) throw new RuntimeException('Trạng thái điểm danh không hợp lệ.');
    q('INSERT INTO session_attendance (session_id, seat_no, status, note, teacher_id) VALUES (?,?,?,?,?) '
      . 'ON DUPLICATE KEY UPDATE status=VALUES(status), note=VALUES(note), teacher_id=VALUES(teacher_id)',
      [$session_id, $seat, $status, mb_substr(trim($note), 0, 200) ?: null, $teacher_id]);
}

/** Số câu có đáp án đúng của buổi, và số câu đúng của từng ghế (đáp án cuối). */
function attendance_scores(int $session_id): array {
    $n = (int)qv('SELECT COUNT(*) FROM session_questions sq JOIN questions q ON q.id=sq.question_id WHERE sq.session_id=? AND q.correct IS NOT NULL', [$session_id]);
    $ok = [];
    foreach (qall('SELECT f.seat_no, SUM(f.answer=q.correct) AS ok FROM final_answers f JOIN session_questions sq ON sq.id=f.session_q_id '
                . 'JOIN questions q ON q.id=sq.question_id WHERE sq.session_id=? AND q.correct IS NOT NULL GROUP BY f.seat_no', [$session_id]) as $r)
        $ok[(int)$r['seat_no']] = (int)$r['ok'];
    return [$n, $ok];
}

function attendance_score_text(int $ok, int $n): string {
    return 'đúng ' . $ok . '/' . $n . ' câu, điểm ' . str_replace('.', ',', number_format($ok * 10 / $n, 1)) . '/10';
}

/** Tạo / cập nhật tin cho phụ huynh của một buổi đã kết thúc. Gọi được nhiều lần (kết thúc, công bố, sửa điểm danh,
 *  nhận đồng bộ trên Cloud). Trả về số tin mới hoặc thay đổi. */
function parent_notify_session(int $session_id): int {
    $s = q1('SELECT s.*, c.name AS class_name, qs.title AS set_title FROM sessions s JOIN classes c ON c.id=s.class_id '
          . 'JOIN question_sets qs ON qs.id=s.set_id WHERE s.id=? AND s.status="ended"', [$session_id]);
    if (!$s) return 0;
    $parents = qall('SELECT parent_id, student_code FROM parent_links WHERE class_id=?', [$s['class_id']]);
    if (!$parents) return 0;
    $att = [];
    foreach (attendance_session($session_id) as $r) if ($r['student_code'] !== null) $att[$r['student_code']] = $r;
    [$n, $ok] = attendance_scores($session_id);
    $day = date('d/m/Y', strtotime($s['started_at']));
    $what = ($s['kind'] === 'test' ? 'bài kiểm tra' : 'buổi học') . ' "' . $s['set_title'] . '" (lớp ' . $s['class_name'] . ')';
    $changed = 0;
    $upsert = function (array $p, string $title, string $body) use ($s, &$changed) {
        // read_at gán TRƯỚC body: nội dung đổi (sửa điểm danh) thì tin hiện lại là chưa đọc
        $changed += q('INSERT INTO parent_messages (parent_id, student_code, class_id, session_id, kind, title, body) VALUES (?,?,?,?,?,?,?) '
                    . 'ON DUPLICATE KEY UPDATE read_at=IF(body<=>VALUES(body), read_at, NULL), title=VALUES(title), body=VALUES(body)',
                      [(int)$p['parent_id'], $p['student_code'], (int)$s['class_id'], (int)$s['id'], 'session', mb_substr($title, 0, 200), mb_substr($body, 0, 1000)])
                    ->rowCount() > 0 ? 1 : 0;                  // 1 = thêm, 2 = sửa, 0 = không đổi
    };
    foreach ($parents as $p) {
        $a = $att[$p['student_code']] ?? null;
        if (!$a) continue;                                        // học sinh không còn trong lớp
        $seat = (int)$a['seat_no'];
        $present = $a['status'] === 'present';
        $who = 'Ngày ' . $day . ', em ' . $a['full_name'] . ' ';
        $body = $who . mb_strtolower(ATTEND_STATUS[$a['status']]) . ' ' . $what . '.';
        if ($present && $n && $s['released']) $body .= ' Kết quả: ' . attendance_score_text($ok[$seat] ?? 0, $n) . '.';
        elseif ($present && $n && !$s['released']) $body .= ' Điểm sẽ được báo khi thầy cô công bố kết quả.';
        $upsert($p, ($present ? '✅ ' : '⚠️ ') . 'Điểm danh: ' . ATTEND_STATUS[$a['status']] . ' — ' . $s['set_title'], $body);
    }
    return $changed;
}

/** Tin của một phụ huynh (mới nhất trước). */
function parent_messages_for(int $parent_id, int $limit = 30): array {
    return qall('SELECT m.*, c.name AS class_name FROM parent_messages m JOIN classes c ON c.id=m.class_id WHERE m.parent_id=? '
              . 'ORDER BY m.read_at IS NULL DESC, m.created_at DESC, m.id DESC LIMIT ' . max(1, $limit), [$parent_id]);
}
