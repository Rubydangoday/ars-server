<?php
// Xuất kết quả một buổi ra Excel: sheet theo câu, theo học sinh, và toàn bộ lượt gửi.
//   export.php?id=…&format=csv → CSV bảng điểm theo học sinh (một bảng: điểm thang 10 + đáp án từng câu).
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/stats.php';
require __DIR__ . '/lib/sheet.php';
require_login();
$ses = own_session((int)($_GET['id'] ?? 0));
$S = session_stats($ses);
$r2 = fn($v) => $v === null ? '' : round($v, 3);

$byq = [['Câu', 'Nội dung', 'Đáp án đúng', 'Số trả lời', 'Tỉ lệ tham gia', 'Độ khó p', 'Độ phân biệt D', 'Thời gian trung vị (s)', 'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J']];
foreach ($S['questions'] as $r) {
    $row = [(int)$r['sq']['position'], $r['sq']['content'], $r['sq']['correct'] ?? '', $r['n'], $r2($r['participation']), $r2($r['p']), $r2($r['D']), $r2($r['median_t'])];
    foreach (str_split(LETTERS) as $L) $row[] = array_key_exists($L, $r['dist']) ? $r['dist'][$L] : '';
    $byq[] = $row;
}
$head = ['Số', 'MSSV', 'Họ tên', 'Có mặt', 'Đã trả lời', 'Đúng', 'Điểm (thang 10)'];
foreach ($S['questions'] as $r) $head[] = 'C' . $r['sq']['position'];
$bys = [$head];
foreach ($S['students'] as $s) {
    $row = [(int)$s['seat_no'], $s['student_code'] ?? '', $s['full_name'], $s['present'] ? 'có' : 'vắng', $s['answered'], $s['correct'],
            $s['n_graded'] ? round($s['correct'] * 10 / $s['n_graded'], 1) : ''];
    foreach ($s['answers'] as $a) $row[] = $a;
    $bys[] = $row;
}
$all = [['Thời điểm', 'Câu', 'Số', 'MAC', 'Seq', 'Chữ', 'RSSI (dBm)']];
foreach (qall('SELECT r.received_at, q.position, r.seat_no, r.mac, r.seq, r.answer, r.rssi FROM responses r '
            . 'JOIN session_questions sq ON sq.id=r.session_q_id JOIN questions q ON q.id=sq.question_id '
            . 'WHERE sq.session_id=? ORDER BY r.id', [$ses['id']]) as $x) {
    $all[] = [$x['received_at'], (int)$x['position'], (int)$x['seat_no'], $x['mac'], (int)$x['seq'], $x['answer'], $x['rssi'] === null ? '' : (int)$x['rssi']];
}
$fname = 'ARS_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $ses['class_name']) ?: 'lop') . '_' . date('Ymd_Hi', strtotime($ses['started_at'])) . '.xlsx';
if (($_GET['format'] ?? '') === 'csv') send_csv($bys, substr($fname, 0, -5) . '_diem.csv');
send_xlsx(['Theo cau' => $byq, 'Theo hoc sinh' => $bys, 'Tat ca luot gui' => $all], $fname);
