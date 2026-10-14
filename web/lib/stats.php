<?php
// Thống kê một buổi học: theo câu (độ khó p, độ phân biệt D, phương án nhiễu, thời gian) và theo học sinh.
declare(strict_types=1);

function session_stats(array $ses): array {
    $sid = (int)$ses['id'];
    $students = qall('SELECT seat_no, student_code, full_name FROM students WHERE class_id=? ORDER BY seat_no', [$ses['class_id']]);
    // có mặt = điểm danh cuối (Remote / có câu trả lời / giáo viên sửa tay), lib/attendance.php
    $logged = array_column(qall('SELECT seat_no FROM attendance_final WHERE session_id=? AND status="present"', [$sid]), 'seat_no');
    $sqs = qall('SELECT sq.*, q.position, q.content, q.topic, q.explanation, q.n_options, q.correct, '
              . 'q.opt_a,q.opt_b,q.opt_c,q.opt_d,q.opt_e,q.opt_f,q.opt_g,q.opt_h,q.opt_i,q.opt_j '
              . 'FROM session_questions sq JOIN questions q ON q.id=sq.question_id WHERE sq.session_id=? ORDER BY q.position', [$sid]);
    $finals = qall('SELECT f.session_q_id, f.seat_no, f.answer, TIMESTAMPDIFF(MICROSECOND, sq.opened_at, f.received_at)/1e6 AS t '
                 . 'FROM final_answers f JOIN session_questions sq ON sq.id=f.session_q_id WHERE sq.session_id=?', [$sid]);
    $by_q = [];
    foreach ($finals as $f) $by_q[$f['session_q_id']][(int)$f['seat_no']] = $f;

    // điểm từng học sinh trên các câu có đáp án đúng
    $graded = array_filter($sqs, fn($q) => $q['correct'] !== null && $q['correct'] !== '');
    $score = [];
    foreach ($students as $s) $score[(int)$s['seat_no']] = 0;
    foreach ($graded as $q) foreach ($by_q[$q['id']] ?? [] as $seat => $f) if ($f['answer'] === $q['correct']) $score[$seat] = ($score[$seat] ?? 0) + 1;

    // nhóm 27 % cao / thấp (chỉ tính học sinh đã điểm danh)
    $pool = array_intersect_key($score, array_flip($logged));
    arsort($pool);
    $k = (int)max(1, round(count($pool) * 0.27));
    $top = array_slice(array_keys($pool), 0, $k);
    $bottom = array_slice(array_keys($pool), -$k);

    $qs = [];
    foreach ($sqs as $q) {
        $ans = $by_q[$q['id']] ?? [];
        $dist = [];
        for ($i = 0; $i < (int)$q['n_options']; $i++) $dist[LETTERS[$i]] = 0;
        $times = [];
        foreach ($ans as $f) { $dist[$f['answer']] = ($dist[$f['answer']] ?? 0) + 1; $times[] = (float)$f['t']; }
        $n = count($ans);
        $row = ['sq' => $q, 'n' => $n, 'dist' => $dist, 'participation' => count($logged) ? $n / count($logged) : null,
                'median_t' => $times ? median($times) : null, 'p' => null, 'D' => null, 'distractors' => []];
        if ($q['correct'] !== null && $q['correct'] !== '' && $n) {
            $row['p'] = $dist[$q['correct']] / $n;
            $pg = function ($grp) use ($ans, $q) {
                $a = array_filter($grp, fn($s) => isset($ans[$s]));
                if (!$a) return null;
                return count(array_filter($a, fn($s) => $ans[$s]['answer'] === $q['correct'])) / count($a);
            };
            $pt = $pg($top); $pb = $pg($bottom);
            $row['D'] = ($pt !== null && $pb !== null) ? $pt - $pb : null;
            foreach ($dist as $L => $v) if ($L !== $q['correct']) $row['distractors'][$L] = $v / $n;
            arsort($row['distractors']);
        }
        $qs[] = $row;
    }

    $st = [];
    foreach ($students as $s) {
        $seat = (int)$s['seat_no'];
        $answered = 0; $correct = 0;
        foreach ($sqs as $q) if (isset($by_q[$q['id']][$seat])) {
            $answered++;
            if ($q['correct'] && $by_q[$q['id']][$seat]['answer'] === $q['correct']) $correct++;
        }
        $st[] = $s + ['present' => in_array($seat, array_map('intval', $logged), true), 'answered' => $answered,
                      'correct' => $correct, 'n_graded' => count($graded), 'n_q' => count($sqs),
                      'answers' => array_map(fn($q) => $by_q[$q['id']][$seat]['answer'] ?? '', $sqs)];
    }
    return ['questions' => $qs, 'students' => $st, 'logged' => count($logged), 'n_students' => count($students)];
}

function median(array $a): float {
    sort($a);
    $n = count($a);
    return $n % 2 ? $a[intdiv($n, 2)] : ($a[$n / 2 - 1] + $a[$n / 2]) / 2;
}

function pct(?float $v, int $d = 0): string { return $v === null ? '—' : number_format($v * 100, $d, ',', '.') . ' %'; }
function num(?float $v, int $d = 2): string { return $v === null ? '—' : number_format($v, $d, ',', '.'); }

// Nhận xét nhanh theo ngưỡng thường dùng trong phân tích câu hỏi
function judge_p(?float $p): string {
    if ($p === null) return '';
    return $p >= 0.85 ? 'dễ' : ($p >= 0.3 ? 'vừa' : 'khó');
}
function judge_D(?float $d): string {
    if ($d === null) return '';
    return $d >= 0.4 ? 'tốt' : ($d >= 0.2 ? 'được' : 'kém — xem lại câu');
}
