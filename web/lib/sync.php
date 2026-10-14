<?php
// Đồng bộ Hybrid: server lớp học (offline, nhận Remote) → server Cloud (cùng mã nguồn ARS, mở Internet cho HS/PH ở nhà).
// Một chiều, idempotent: gửi lại nhiều lần không nhân đôi dữ liệu. Cloud ánh xạ id local → id cloud trong sync_map.
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/notify.php';

const SYNC_VERSION = 1;
const SYNC_BATCH_SESSIONS = 20;     // số buổi mỗi lần gửi, giữ gói JSON nhỏ
const SYNC_TIME_RE = '/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d(\.\d{1,6})?$/';

/** Mã nhận diện server này (ổn định), để Cloud phân biệt dữ liệu từ nhiều server lớp học. */
function server_uuid(): string {
    $u = setting('server_uuid');
    if (!$u || !preg_match('/^[a-f0-9]{32}$/', $u)) { $u = bin2hex(random_bytes(16)); setting_set('server_uuid', $u); }
    return $u;
}
function sync_log(int $teacher_id, string $dir, bool $ok, string $msg): void {
    q('INSERT INTO sync_log (teacher_id, direction, ok, message) VALUES (?,?,?,?)', [$teacher_id, $dir, (int)$ok, mb_substr($msg, 0, 1000)]);
}
function normalize_cloud_url(string $u): string {
    $u = rtrim(trim($u), '/');
    if (!preg_match('~^https?://[^\s/?#]+(/[^\s?#]*)?$~i', $u)) throw new RuntimeException('Địa chỉ Cloud dạng https://ars.truong.edu.vn (thư mục web của ARS).');
    return $u;
}

// ------------------------------------------------------------------ phía server lớp học (gửi)
/** Gói dữ liệu một lượt: mọi lớp + học sinh của giáo viên, và tối đa SYNC_BATCH_SESSIONS buổi đã kết thúc (id > $after) chưa đồng bộ. */
function sync_payload(int $teacher_id, bool $all, int $after = 0): array {
    $classes = qall('SELECT id, name, room_id, grade_level, subject, cohort FROM classes WHERE teacher_id=? ORDER BY id', [$teacher_id]);
    $cids = array_map('intval', array_column($classes, 'id')) ?: [0];
    $in = implode(',', $cids);
    $sessions = qall("SELECT id, class_id, set_id, kind, released, started_at, ended_at FROM sessions WHERE class_id IN ($in) AND status='ended' AND id>?"
        . ($all ? '' : ' AND cloud_synced_at IS NULL') . ' ORDER BY id LIMIT ' . SYNC_BATCH_SESSIONS, [$after]);
    $sids = array_map('intval', array_column($sessions, 'id')) ?: [0];
    $sin = implode(',', $sids);
    $setids = array_values(array_unique(array_map('intval', array_column($sessions, 'set_id')))) ?: [0];
    $setin = implode(',', $setids);
    return [
        'version' => SYNC_VERSION, 'source' => server_uuid(),
        'classes' => $classes,
        'students' => qall("SELECT class_id, seat_no, student_code, full_name, parent_phone FROM students WHERE class_id IN ($in)"),
        'sets' => qall("SELECT id, title, purpose, description FROM question_sets WHERE id IN ($setin)"),
        'questions' => qall("SELECT id, set_id, position, content, topic, difficulty, n_options, opt_a,opt_b,opt_c,opt_d,opt_e,opt_f,opt_g,opt_h,opt_i,opt_j, correct, explanation, time_limit FROM questions WHERE set_id IN ($setin)"),
        'sessions' => $sessions,
        'session_questions' => qall("SELECT id, session_id, question_id, opened_at, closed_at, show_result FROM session_questions WHERE session_id IN ($sin)"),
        'logins' => qall("SELECT session_id, seat_no, mac, logged_at FROM session_logins WHERE session_id IN ($sin)"),
        'attendance' => qall("SELECT session_id, seat_no, status, note FROM session_attendance WHERE session_id IN ($sin)"),   // giáo viên sửa tay
        'answers' => qall("SELECT f.session_q_id, f.seat_no, f.mac, f.answer, f.received_at FROM final_answers f JOIN session_questions sq ON sq.id=f.session_q_id WHERE sq.session_id IN ($sin)"),
    ];
}

function sync_http(string $url, string $token, array $body): array {
    if (!function_exists('curl_init')) throw new RuntimeException('Thiếu PHP cURL (sudo apt install php-curl).');
    $ch = curl_init($url . '/api/sync.php');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 120,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-ARS-Sync-Token: ' . $token],
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE)]);
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($res === false) throw new RuntimeException('Không kết nối được Cloud: ' . $err . '. Kiểm tra Internet rồi thử lại; dữ liệu vẫn an toàn trên máy này.');
    $j = json_decode((string)$res, true);
    if (!is_array($j)) throw new RuntimeException('Cloud trả lời không hợp lệ (HTTP ' . $code . '). Kiểm tra địa chỉ Cloud.');
    if ($code !== 200 || empty($j['ok'])) throw new RuntimeException('Cloud từ chối: ' . ($j['error'] ?? 'HTTP ' . $code));
    return $j;
}

/** Đẩy toàn bộ buổi chưa đồng bộ lên Cloud theo từng lô. Trả về tóm tắt. */
function sync_push(int $teacher_id, bool $all = false, string $via = 'manual'): string {
    $tag = $via === 'auto' ? '[Tự động] ' : '';
    $t = q1('SELECT cloud_url, cloud_token FROM teachers WHERE id=?', [$teacher_id]);
    if (!$t['cloud_url'] || !$t['cloud_token']) throw new RuntimeException('Chưa cấu hình địa chỉ Cloud và mã đồng bộ.');
    $n_sessions = 0; $n_answers = 0; $notified = 0; $after = 0;
    try {
        do {    // lô đầu luôn gửi (cập nhật lớp, học sinh) kể cả khi không có buổi mới
            $p = sync_payload($teacher_id, $all, $after);
            $j = sync_http($t['cloud_url'], $t['cloud_token'], $p);
            $ids = array_map('intval', array_column($p['sessions'], 'id'));
            if ($ids) { q('UPDATE sessions SET cloud_synced_at=NOW() WHERE id IN (' . implode(',', $ids) . ')'); $after = max($ids); }
            $n_sessions += count($ids); $n_answers += count($p['answers']); $notified += (int)($j['notified'] ?? 0);
        } while (count($ids) === SYNC_BATCH_SESSIONS);
    } catch (Throwable $e) {
        if (!$tag || !setting('sync_auto_fail_' . $teacher_id))           // tự động mất mạng liên tục: chỉ ghi lỗi đầu tiên
            sync_log($teacher_id, 'push', false, $tag . $e->getMessage() . ($n_sessions ? " (đã gửi $n_sessions buổi trước khi lỗi)" : ''));
        throw $e;
    }
    $msg = "Đã đồng bộ lớp, học sinh và $n_sessions buổi ($n_answers câu trả lời) lên Cloud." . ($notified ? " Cloud đã gửi $notified thông báo cho phụ huynh." : '');
    sync_log($teacher_id, 'push', true, $tag . $msg);
    return $msg;
}

/** Tự đồng bộ (deploy/ars-autosync.php, systemd timer mỗi 3 phút khi máy chủ bật): mọi giáo viên đã nhập Cloud và
 *  không tắt "Tự động". Chỉ gửi khi có buổi chưa đồng bộ (kết thúc buổi, công bố, sửa điểm danh) hoặc đã quá 6 giờ
 *  (cập nhật lớp / danh sách học sinh). Mất Internet: chờ lâu dần 3 → 6 → … → 30 phút, chỉ ghi nhật ký lỗi đầu tiên.
 *  Trả về [teacher_id => thông báo] cho các giáo viên đã thử. */
function sync_auto_run(bool $force = false): array {
    $out = [];
    foreach (qall('SELECT id FROM teachers WHERE active=1 AND cloud_auto=1 AND cloud_url IS NOT NULL AND cloud_token IS NOT NULL') as $r) {
        $tid = (int)$r['id'];
        $fail = json_decode(setting('sync_auto_fail_' . $tid) ?? '', true) ?: ['n' => 0, 'at' => 0];
        if (!$force && $fail['n'] && time() - $fail['at'] < 60 * min(30, 3 * 2 ** ($fail['n'] - 1))) continue;   // đang chờ thử lại
        $pending = (int)qv('SELECT COUNT(*) FROM sessions s JOIN classes c ON c.id=s.class_id WHERE c.teacher_id=? AND s.status="ended" AND s.cloud_synced_at IS NULL', [$tid]);
        $last = qv('SELECT MAX(created_at) FROM sync_log WHERE teacher_id=? AND direction="push" AND ok=1', [$tid]);
        if (!$force && !$pending && $last && strtotime($last) > time() - 6 * 3600) continue;           // không có gì mới
        try {
            $out[$tid] = sync_push($tid, false, 'auto');
            setting_set('sync_auto_fail_' . $tid, '');
        } catch (Throwable $e) {
            $out[$tid] = 'Lỗi: ' . $e->getMessage();
            setting_set('sync_auto_fail_' . $tid, json_encode(['n' => $fail['n'] + 1, 'at' => time()]));
        }
    }
    return $out;
}

// ------------------------------------------------------------------ phía Cloud (nhận)
function sync_token_auth(string $token): ?array {
    if (!preg_match('/^[A-Za-z0-9_-]{20,80}$/', $token)) return null;
    $r = q1('SELECT s.*, t.active FROM sync_tokens s JOIN teachers t ON t.id=s.teacher_id WHERE s.token_hash=? AND s.revoked=0', [hash('sha256', $token)]);
    return $r && (int)$r['active'] ? $r : null;
}
function new_sync_token(int $teacher_id, string $label): string {
    $tok = 'ars_' . rtrim(strtr(base64_encode(random_bytes(30)), '+/', '-_'), '=');
    q('INSERT INTO sync_tokens (teacher_id, label, token_hash) VALUES (?,?,?)', [$teacher_id, mb_substr($label, 0, 100) ?: 'Server lớp học', hash('sha256', $tok)]);
    return $tok;
}

/** Nhập gói dữ liệu vào tài khoản giáo viên của mã đồng bộ. Trả về [số liệu, id buổi cloud vừa được công bố]. */
function sync_receive(int $tid, array $p): array {
    $src = (string)($p['source'] ?? '');
    if ((int)($p['version'] ?? 0) !== SYNC_VERSION) throw new RuntimeException('Phiên bản đồng bộ không khớp: cập nhật ARS ở cả hai server.');
    if (!preg_match('/^[a-f0-9]{32}$/', $src)) throw new RuntimeException('Thiếu mã server nguồn.');
    $str = fn($v, int $n) => $v === null ? null : mb_substr((string)$v, 0, $n);
    $time = function ($v, bool $null = false) { if ($v === null && $null) return null; if (!is_string($v) || !preg_match(SYNC_TIME_RE, $v)) throw new RuntimeException('Thời gian không hợp lệ.'); return $v; };
    $map = fn(string $e, $local) => ($v = qv('SELECT cloud_id FROM sync_map WHERE teacher_id=? AND source=? AND entity=? AND local_id=?', [$tid, $src, $e, (int)$local])) === null ? null : (int)$v;
    $setmap = fn(string $e, $local, int $cloud) => q('REPLACE INTO sync_map (teacher_id, source, entity, local_id, cloud_id) VALUES (?,?,?,?,?)', [$tid, $src, $e, (int)$local, $cloud]);
    $count = ['classes' => 0, 'students' => 0, 'sessions' => 0, 'answers' => 0];
    $released = [];

    db()->beginTransaction();
    try {
        $class_ids = [];
        foreach ($p['classes'] ?? [] as $c) {
            $name = trim((string)($c['name'] ?? ''));
            if ($name === '') throw new RuntimeException('Lớp thiếu tên.');
            $cid = $map('class', $c['id'] ?? 0);
            if ($cid && !qv('SELECT 1 FROM classes WHERE id=? AND teacher_id=?', [$cid, $tid])) $cid = null;   // lớp đã bị xoá trên Cloud → tạo lại
            $vals = [mb_substr($name, 0, 100), max(1, min(12, (int)($c['grade_level'] ?? 6))), $str($c['subject'] ?? null, 60), $str($c['cohort'] ?? null, 30)];
            if ($cid) q('UPDATE classes SET name=?, grade_level=?, subject=?, cohort=? WHERE id=?', array_merge($vals, [$cid]));
            else {
                // room chỉ có nghĩa ở server lớp học; trên Cloud chọn số chưa ai dùng để không đụng ràng buộc
                $used = array_map('intval', array_column(qall('SELECT room_id FROM classes'), 'room_id'));
                $mine = array_map('intval', array_column(qall('SELECT room_id FROM classes WHERE teacher_id=?', [$tid]), 'room_id'));
                $room = null;
                foreach (array_merge([(int)($c['room_id'] ?? 0)], range(1, 255)) as $r) if ($r >= 1 && $r <= 255 && !in_array($r, $used, true)) { $room = $r; break; }
                if ($room === null) foreach (range(1, 255) as $r) if (!in_array($r, $mine, true)) { $room = $r; break; }
                if ($room === null) throw new RuntimeException('Tài khoản Cloud đã đủ 255 lớp.');
                q('INSERT INTO classes (teacher_id, name, room_id, grade_level, subject, cohort) VALUES (?,?,?,?,?,?)', array_merge([$tid, $vals[0], $room], array_slice($vals, 1)));
                $cid = (int)db()->lastInsertId();
                $setmap('class', $c['id'] ?? 0, $cid);
            }
            $class_ids[(int)$c['id']] = $cid;
            $count['classes']++;
        }
        // Học sinh: danh sách local là chuẩn (thêm, sửa, bỏ ghế không còn).
        $seats = [];
        foreach ($p['students'] ?? [] as $s) {
            $cid = $class_ids[(int)($s['class_id'] ?? 0)] ?? null;
            $seat = (int)($s['seat_no'] ?? 0);
            if (!$cid || $seat < 1 || $seat > 99) throw new RuntimeException('Học sinh không hợp lệ.');
            // parent_phone: để giáo viên cấp tài khoản phụ huynh trên Cloud (server lớp học cũ không gửi → giữ nguyên)
            q('INSERT INTO students (class_id, seat_no, student_code, full_name, parent_phone) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE student_code=VALUES(student_code), full_name=VALUES(full_name)'
              . (array_key_exists('parent_phone', $s) ? ', parent_phone=VALUES(parent_phone)' : ''),
              [$cid, $seat, $str($s['student_code'] ?? null, 20) ?: null, mb_substr((string)($s['full_name'] ?? ''), 0, 100), normalize_phone((string)($s['parent_phone'] ?? ''))]);
            $seats[$cid][] = $seat;
            $count['students']++;
        }
        foreach ($class_ids as $cid) {
            $keep = $seats[$cid] ?? [];
            q('DELETE FROM students WHERE class_id=?' . ($keep ? ' AND seat_no NOT IN (' . implode(',', array_map('intval', $keep)) . ')' : ''), [$cid]);
        }
        $set_ids = [];
        foreach ($p['sets'] ?? [] as $s) {
            $id = $map('set', $s['id'] ?? 0);
            if ($id && !qv('SELECT 1 FROM question_sets WHERE id=? AND teacher_id=?', [$id, $tid])) $id = null;
            $purpose = in_array($s['purpose'] ?? '', ['test', 'practice'], true) ? $s['purpose'] : 'test';
            if ($id) q('UPDATE question_sets SET title=?, purpose=?, description=? WHERE id=?', [mb_substr((string)$s['title'], 0, 200), $purpose, $str($s['description'] ?? null, 500), $id]);
            else {
                q('INSERT INTO question_sets (teacher_id, title, purpose, description) VALUES (?,?,?,?)', [$tid, mb_substr((string)$s['title'], 0, 200), $purpose, $str($s['description'] ?? null, 500)]);
                $id = (int)db()->lastInsertId(); $setmap('set', $s['id'] ?? 0, $id);
            }
            $set_ids[(int)$s['id']] = $id;
        }
        $q_ids = [];
        foreach ($p['questions'] ?? [] as $q) {
            $set = $set_ids[(int)($q['set_id'] ?? 0)] ?? null;
            $n = (int)($q['n_options'] ?? 0);
            if (!$set || $n < 2 || $n > 10) throw new RuntimeException('Câu hỏi không hợp lệ.');
            $correct = in_array($q['correct'] ?? null, str_split(substr(LETTERS, 0, $n)), true) ? $q['correct'] : null;
            $opts = [];
            foreach (str_split(LETTERS) as $L) $opts[] = $str($q['opt_' . strtolower($L)] ?? null, 500);
            $vals = array_merge([(int)$q['position'], (string)$q['content'], $str($q['topic'] ?? null, 100), in_array($q['difficulty'] ?? null, ['de', 'tb', 'kho'], true) ? $q['difficulty'] : null, $n],
                $opts, [$correct, $str($q['explanation'] ?? null, 65000), isset($q['time_limit']) ? (int)$q['time_limit'] : null]);
            $id = $map('question', $q['id'] ?? 0);
            if ($id && !qv('SELECT 1 FROM questions WHERE id=? AND set_id=?', [$id, $set])) $id = null;
            $cols = 'position=?, content=?, topic=?, difficulty=?, n_options=?, opt_a=?,opt_b=?,opt_c=?,opt_d=?,opt_e=?,opt_f=?,opt_g=?,opt_h=?,opt_i=?,opt_j=?, correct=?, explanation=?, time_limit=?';
            if ($id) q("UPDATE questions SET $cols WHERE id=?", array_merge($vals, [$id]));
            else {
                q("INSERT INTO questions SET set_id=?, origin='cloud', $cols", array_merge([$set], $vals));
                $id = (int)db()->lastInsertId(); $setmap('question', $q['id'] ?? 0, $id);
            }
            $q_ids[(int)$q['id']] = $id;
        }
        $ses_ids = [];
        foreach ($p['sessions'] ?? [] as $s) {
            $cid = $class_ids[(int)($s['class_id'] ?? 0)] ?? null; $set = $set_ids[(int)($s['set_id'] ?? 0)] ?? null;
            if (!$cid || !$set) throw new RuntimeException('Buổi học thiếu lớp hoặc bộ câu hỏi.');
            $rel = (int)!empty($s['released']);
            $kind = ($s['kind'] ?? '') === 'test' ? 'test' : 'class';
            $id = $map('session', $s['id'] ?? 0);
            $old = $id ? q1('SELECT s.released FROM sessions s JOIN classes c ON c.id=s.class_id WHERE s.id=? AND c.teacher_id=?', [$id, $tid]) : null;
            if ($old) q('UPDATE sessions SET kind=?, released=?, started_at=?, ended_at=? WHERE id=?', [$kind, $rel, $time($s['started_at'] ?? null), $time($s['ended_at'] ?? null, true), $id]);
            else {
                q('INSERT INTO sessions (class_id, set_id, kind, status, released, started_at, ended_at, cloud_synced_at) VALUES (?,?,?,"ended",?,?,?,NOW())',
                  [$cid, $set, $kind, $rel, $time($s['started_at'] ?? null), $time($s['ended_at'] ?? null, true)]);
                $id = (int)db()->lastInsertId(); $setmap('session', $s['id'] ?? 0, $id);
            }
            if ($rel && (!$old || !(int)$old['released'])) $released[] = $id;
            $ses_ids[(int)$s['id']] = $id;
            $count['sessions']++;
        }
        $sq_ids = [];
        foreach ($p['session_questions'] ?? [] as $sq) {
            $ses = $ses_ids[(int)($sq['session_id'] ?? 0)] ?? null; $qid = $q_ids[(int)($sq['question_id'] ?? 0)] ?? null;
            if (!$ses || !$qid) throw new RuntimeException('Câu của buổi học không hợp lệ.');
            // LAST_INSERT_ID(id) trả về id dòng sẵn có khi trùng khoá (session, câu)
            q('INSERT INTO session_questions (session_id, question_id, status, opened_at, closed_at, show_result) VALUES (?,?,"closed",?,?,?) '
            . 'ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id), opened_at=VALUES(opened_at), closed_at=VALUES(closed_at), show_result=VALUES(show_result)',
              [$ses, $qid, $time($sq['opened_at'] ?? null), $time($sq['closed_at'] ?? null, true), (int)!empty($sq['show_result'])]);
            $sq_ids[(int)$sq['id']] = (int)db()->lastInsertId();
        }
        foreach ($p['logins'] ?? [] as $l) {
            $ses = $ses_ids[(int)($l['session_id'] ?? 0)] ?? null;
            if (!$ses) throw new RuntimeException('Điểm danh không hợp lệ.');
            q('INSERT IGNORE INTO session_logins (session_id, seat_no, mac, logged_at) VALUES (?,?,?,?)', [$ses, (int)$l['seat_no'], mb_substr((string)$l['mac'], 0, 17), $time($l['logged_at'] ?? null)]);
        }
        if (isset($p['attendance'])) {                   // điểm danh giáo viên sửa tay: Cloud giống hệt server lớp học
            foreach ($ses_ids as $ses) q('DELETE FROM session_attendance WHERE session_id=?', [$ses]);
            foreach ($p['attendance'] as $a) {
                $ses = $ses_ids[(int)($a['session_id'] ?? 0)] ?? null;
                if (!$ses || !in_array($a['status'] ?? '', ['present', 'absent', 'excused'], true)) throw new RuntimeException('Điểm danh không hợp lệ.');
                q('INSERT INTO session_attendance (session_id, seat_no, status, note) VALUES (?,?,?,?)',
                  [$ses, (int)$a['seat_no'], $a['status'], isset($a['note']) ? mb_substr((string)$a['note'], 0, 200) : null]);
            }
        }
        foreach ($p['answers'] ?? [] as $a) {
            $sq = $sq_ids[(int)($a['session_q_id'] ?? 0)] ?? null;
            if (!$sq || !preg_match('/^[A-J]$/', (string)($a['answer'] ?? ''))) throw new RuntimeException('Câu trả lời không hợp lệ.');
            q('INSERT INTO responses (session_q_id, seat_no, mac, seq, answer, received_at) VALUES (?,?,?,0,?,?) ON DUPLICATE KEY UPDATE answer=VALUES(answer), received_at=VALUES(received_at)',
              [$sq, (int)$a['seat_no'], mb_substr((string)$a['mac'], 0, 17), $a['answer'], $time($a['received_at'] ?? null)]);
            $count['answers']++;
        }
        db()->commit();
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
    return [$count, $released, array_values($ses_ids)];
}
