<?php
// Xuất bảng điểm một bài tập về nhà: mọi học sinh trong lớp (cả chưa nộp), trạng thái, điểm từng câu, tổng, thang 10.
//   homework_export.php?id=…               → Excel (.xlsx): sheet "Diem" + sheet "Nhan xet"
//   homework_export.php?id=…&format=csv    → CSV (chỉ bảng điểm)
require __DIR__ . '/lib/lms.php';
require __DIR__ . '/lib/sheet.php';
require_login();
$h = own_homework((int)($_GET['id'] ?? 0));
$id = (int)$h['id'];

$qs = qall('SELECT id, ord, kind, max_score FROM homework_questions WHERE homework_id=? ORDER BY ord', [$id]);
$maxTotal = array_sum(array_map(fn($q) => (float)$q['max_score'], $qs));
$subs = [];
foreach (qall('SELECT * FROM homework_submissions WHERE homework_id=?', [$id]) as $s) $subs[$s['student_code']] = $s;
$ans = [];                                           // submission_id → hw_question_id → hàng homework_answers
foreach (qall('SELECT a.submission_id, a.hw_question_id, a.score, a.feedback FROM homework_answers a '
            . 'JOIN homework_submissions s ON s.id=a.submission_id WHERE s.homework_id=?', [$id]) as $a) {
    $ans[(int)$a['submission_id']][(int)$a['hw_question_id']] = $a;
}
$num = fn($v) => $v === null ? '' : (float)$v;       // DECIMAL → số (Excel tính được), trống = chưa chấm

$head = ['Số', 'MSSV', 'Họ tên', 'Trạng thái', 'Nộp lúc', 'Điểm', 'Tối đa', 'Thang 10'];
foreach ($qs as $q) $head[] = 'C' . (int)$q['ord'] . ' (/' . (float)$q['max_score'] . ')';
$grades = [$head];
$notes = [['Số', 'MSSV', 'Họ tên', 'Câu', 'Điểm', 'Nhận xét']];
foreach (qall('SELECT seat_no, student_code, full_name FROM students WHERE class_id=? ORDER BY seat_no', [$h['class_id']]) as $st) {
    $s = $st['student_code'] !== null ? ($subs[$st['student_code']] ?? null) : null;
    $row = [(int)$st['seat_no'], $st['student_code'] ?? '', $st['full_name']];
    if (!$s) {
        $grades[] = array_merge($row, [$st['student_code'] ? 'Chưa nộp' : 'Chưa có tài khoản học sinh', '', '', $maxTotal, '']);
        continue;
    }
    $mine = $ans[(int)$s['id']] ?? [];
    $scores = array_map(fn($q) => isset($mine[(int)$q['id']]) ? $mine[(int)$q['id']]['score'] : null, $qs);
    $open = count(array_filter($scores, fn($v) => $v === null));
    $got = array_sum(array_map(fn($v) => (float)$v, array_filter($scores, fn($v) => $v !== null)));
    if (!$s['submitted_at']) $status = 'Đang làm nháp';
    elseif ($open) $status = "Chưa chấm xong ($open câu)";
    else $status = $s['released'] ? 'Đã công bố' : 'Đã chấm, chưa công bố';
    $done = $s['submitted_at'] && !$open;
    $grades[] = array_merge($row, [$status, $s['submitted_at'] ?? '', $s['submitted_at'] ? round($got, 2) : '', $maxTotal,
                                   $done && $maxTotal > 0 ? round($got * 10 / $maxTotal, 2) : ''], array_map($num, $scores));
    foreach ($qs as $q) {
        $a = $mine[(int)$q['id']] ?? null;
        if ($a && trim((string)$a['feedback']) !== '') $notes[] = array_merge($row, [(int)$q['ord'], $num($a['score']), $a['feedback']]);
    }
}

$name = 'ARS_bai_tap_' . $id . '_' . preg_replace('/[^A-Za-z0-9_-]/', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $h['title']) ?: 'diem');
if (($_GET['format'] ?? '') === 'csv') send_csv($grades, $name . '.csv');
send_xlsx(['Diem' => $grades, 'Nhan xet' => $notes], $name . '.xlsx');
