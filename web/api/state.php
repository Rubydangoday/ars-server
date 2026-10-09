<?php
// JSON trạng thái một buổi học — bảng điều khiển và màn chiếu gọi mỗi giây.
//   ?id=<session>&view=control    đầy đủ (lưới ghế, cảnh báo, đáp án đúng)
//   ?id=<session>&view=projector  chỉ những gì cả lớp được thấy
require __DIR__ . '/../lib/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_login_json();
session_write_close();          // gọi mỗi giây: không giữ khoá phiên, nhiều tab/nhiều GV không phải chờ nhau
$ses = own_session((int)($_GET['id'] ?? 0));
$sid = (int)$ses['id'];
$control = ($_GET['view'] ?? 'control') === 'control';
$low_mv = (int)(cfg()['ingest']['low_batt_mv'] ?? 2600);

$qs = qall('SELECT q.id, q.position, q.content, q.n_options, q.correct, q.time_limit, '
         . 'q.opt_a,q.opt_b,q.opt_c,q.opt_d,q.opt_e,q.opt_f,q.opt_g,q.opt_h,q.opt_i,q.opt_j, '
         . 'sq.id AS sq_id, sq.status, sq.show_result, sq.opened_at, sq.deadline, '
         . 'TIMESTAMPDIFF(MICROSECOND, NOW(3), sq.deadline)/1e6 AS deadline_left, '
         . '(SELECT COUNT(DISTINCT seat_no) FROM responses r WHERE r.session_q_id=sq.id) AS n_answered '
         . 'FROM questions q LEFT JOIN session_questions sq ON sq.question_id=q.id AND sq.session_id=? '
         . 'WHERE q.set_id=? ORDER BY q.position', [$sid, $ses['set_id']]);
$n_logged = (int)qv('SELECT COUNT(*) FROM session_logins WHERE session_id=?', [$sid]);
$now = microtime(true);

$out = [
    'session' => ['id' => $sid, 'status' => $ses['status'], 'allow_change' => (bool)$ses['allow_change'],
                  'class_name' => $ses['class_name'], 'room_id' => (int)$ses['room_id'], 'set_title' => $ses['set_title']],
    'n_questions' => count($qs),
    'n_logged' => $n_logged,
    'n_students' => (int)qv('SELECT COUNT(*) FROM students WHERE class_id=?', [$ses['class_id']]),
    'server_time' => $now,
];

$gw = q1('SELECT online, pkt_per_s, port, TIMESTAMPDIFF(SECOND, updated_at, NOW()) AS age FROM gateways WHERE room_id=?', [$ses['room_id']]);
$out['gateway'] = $gw ? ['online' => (bool)$gw['online'] && (int)$gw['age'] < 15, 'pkt_per_s' => (float)$gw['pkt_per_s'], 'age_s' => (int)$gw['age'],
                         'port' => $gw['port'] ?? null]
                      : ['online' => false, 'pkt_per_s' => null, 'age_s' => null, 'port' => null];

$cur = null;
foreach ($qs as $q) if ((int)$q['id'] === (int)$ses['current_q']) $cur = $q;

if ($control) {
    $out['questions'] = array_map(fn($q) => ['id' => (int)$q['id'], 'position' => (int)$q['position'],
        'content' => mb_strimwidth($q['content'], 0, 90, '…'), 'status' => $q['status'] ?? 'new',
        'n_answered' => (int)$q['n_answered'], 'correct' => $q['correct']], $qs);
}

if ($cur) {
    $deadline_left = $cur['deadline_left'] !== null ? max(0.0, (float)$cur['deadline_left']) : null;
    $c = ['id' => (int)$cur['id'], 'position' => (int)$cur['position'], 'content' => $cur['content'],
          'options' => options_of($cur), 'n_options' => (int)$cur['n_options'], 'status' => $cur['status'] ?? 'new',
          'show_result' => (bool)$cur['show_result'], 'n_answered' => (int)$cur['n_answered'],
          'time_limit' => $cur['time_limit'] ? (int)$cur['time_limit'] : null,
          'deadline_left' => $deadline_left !== null ? round($deadline_left, 1) : null];
    $reveal = $control || $cur['show_result'];
    if(!$reveal && (bool)$ses['live_distribution'] && $cur['sq_id']) {
      $d=array_fill_keys(array_keys(options_of($cur)),0);foreach(qall('SELECT answer,COUNT(*) n FROM final_answers WHERE session_q_id=? GROUP BY answer',[$cur['sq_id']]) as $r)$d[$r['answer']]=(int)$r['n'];$c['dist']=$d;
    }
    if ($reveal && $cur['sq_id']) {
        $d = array_fill_keys(array_keys(options_of($cur)), 0);
        foreach (qall('SELECT answer, COUNT(*) n FROM final_answers WHERE session_q_id=? GROUP BY answer', [$cur['sq_id']]) as $r) $d[$r['answer']] = (int)$r['n'];
        $c['dist'] = $d;
        $c['correct'] = $cur['correct'];
    }
    $c['media']=qall('SELECT id,kind,name FROM question_media WHERE question_id=? ORDER BY id',[$cur['id']]);
    $out['current'] = $c;
}

if ($control) {
    $answered = [];
    if ($cur && $cur['sq_id']) foreach (qall('SELECT seat_no, answer FROM final_answers WHERE session_q_id=?', [$cur['sq_id']]) as $r) $answered[(int)$r['seat_no']] = $r['answer'];
    $logins = [];
    foreach (qall('SELECT l.seat_no, l.mac, d.last_batt_mv, d.last_rssi, TIMESTAMPDIFF(SECOND, d.last_seen, NOW()) AS seen_s '
                . 'FROM session_logins l LEFT JOIN devices d ON d.mac=l.mac WHERE l.session_id=?', [$sid]) as $r) $logins[(int)$r['seat_no']] = $r;
    $seats = [];
    foreach (qall('SELECT seat_no, full_name FROM students WHERE class_id=? ORDER BY seat_no', [$ses['class_id']]) as $s) {
        $n = (int)$s['seat_no']; $l = $logins[$n] ?? null;
        $seats[] = ['seat' => $n, 'name' => $s['full_name'], 'present' => (bool)$l, 'answer' => $answered[$n] ?? null,
                    'mac' => $l['mac'] ?? null, 'batt_mv' => $l ? (int)$l['last_batt_mv'] : null,
                    'low_batt' => $l && $l['last_batt_mv'] && (int)$l['last_batt_mv'] < $low_mv, 'rssi' => $l ? (int)$l['last_rssi'] : null];
    }
    $out['seats'] = $seats;
    // cảnh báo: gói bị loại trong 10 phút gần nhất (trừ lỗi thường gặp như câu đã đóng)
    $out['warnings'] = qall('SELECT DATE_FORMAT(received_at, "%H:%i:%s") t, reason, seat_no, mac, qid, answer, COUNT(*) n '
        . 'FROM rejected WHERE session_id=? AND received_at > NOW() - INTERVAL 10 MINUTE '
        . 'GROUP BY reason, seat_no, mac, qid, answer ORDER BY MAX(id) DESC LIMIT 12', [$sid]);
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);
