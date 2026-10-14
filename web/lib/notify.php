<?php
// Báo kết quả buổi học cho phụ huynh qua Zalo OA (ZNS) hoặc SMS. Chỉ gửi cho phụ huynh đã tự đăng ký số điện thoại.
// Hàng đợi `notifications`: mỗi (buổi, phụ huynh, học sinh) một tin, gửi lại tối đa 3 lần khi lỗi.
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

const NOTIFY_CHANNELS = ['zalo' => 'Zalo', 'sms' => 'SMS'];
const NOTIFY_DEFAULT_TEMPLATE = 'Hôm nay {ngay}, cháu {hs} đã trả lời đúng {dung}/{tong} câu hỏi bằng Remote trên lớp {mon} ({lop}).';
const NOTIFY_ABSENT_TEMPLATE = 'Hôm nay {ngay}, cháu {hs} vắng buổi học {mon} ({lop}).';
const NOTIFY_MAX_ATTEMPTS = 3;

/** Kênh đang dùng được: zalo/sms đã cấu hình; chế độ thử ghi nhật ký thay cho gửi thật. */
function notify_channels(): array {
    $c = [];
    if (setting('zalo_access_token') && setting('zalo_template_id')) $c['zalo'] = 'Zalo';
    if (setting('sms_url')) $c['sms'] = 'SMS';
    if (setting('notify_test_mode') === '1') $c += NOTIFY_CHANNELS;
    return $c;
}
function normalize_phone(string $p): ?string {
    $d = preg_replace('/\D+/', '', $p);
    if (str_starts_with($d, '84') && strlen($d) === 11) $d = '0' . substr($d, 2);
    if (strlen($d) === 9 && preg_match('/^[35789]/', $d)) $d = '0' . $d;      // ô Excel dạng số làm mất số 0 đầu
    return preg_match('/^0[35789]\d{8}$/', $d) ? $d : null;
}
/** Bỏ dấu tiếng Việt (SMS không dấu rẻ hơn và không bị cắt). */
function vn_ascii(string $s): string {
    $map = ['a' => 'àáạảãâầấậẩẫăằắặẳẵ', 'e' => 'èéẹẻẽêềếệểễ', 'i' => 'ìíịỉĩ', 'o' => 'òóọỏõôồốộổỗơờớợởỡ', 'u' => 'ùúụủũưừứựửữ', 'y' => 'ỳýỵỷỹ', 'd' => 'đ',
            'A' => 'ÀÁẠẢÃÂẦẤẬẨẪĂẰẮẶẲẴ', 'E' => 'ÈÉẸẺẼÊỀẾỆỂỄ', 'I' => 'ÌÍỊỈĨ', 'O' => 'ÒÓỌỎÕÔỒỐỘỔỖƠỜỚỢỞỠ', 'U' => 'ÙÚỤỦŨƯỪỨỰỬỮ', 'Y' => 'ỲÝỴỶỸ', 'D' => 'Đ'];
    $tr = [];
    foreach ($map as $to => $from) foreach (mb_str_split($from) as $ch) $tr[$ch] = $to;
    return strtr($s, $tr);
}

/** Kết quả từng học sinh của một buổi (đã kết thúc + công bố). key = seat_no */
function session_student_results(int $session_id): array {
    $out = [];
    foreach (qall('SELECT st.seat_no, st.student_code, st.full_name, '
        . '(SELECT COUNT(*) FROM attendance_final af WHERE af.session_id=s.id AND af.seat_no=st.seat_no AND af.status="present") AS present, '
        . '(SELECT COUNT(*) FROM session_questions sq JOIN questions q ON q.id=sq.question_id WHERE sq.session_id=s.id AND q.correct IS NOT NULL) AS n, '
        . '(SELECT COUNT(*) FROM final_answers f JOIN session_questions sq ON sq.id=f.session_q_id JOIN questions q ON q.id=sq.question_id WHERE sq.session_id=s.id AND f.seat_no=st.seat_no AND f.answer=q.correct) AS ok '
        . 'FROM sessions s JOIN students st ON st.class_id=s.class_id WHERE s.id=?', [$session_id]) as $r) $out[(int)$r['seat_no']] = $r;
    return $out;
}

/** Tạo tin trong hàng đợi cho mọi phụ huynh đã đăng ký nhận tin của lớp. Trả về số tin mới. */
function notify_enqueue_session(int $session_id): int {
    $s = q1('SELECT s.*, c.name AS class_name, c.subject FROM sessions s JOIN classes c ON c.id=s.class_id WHERE s.id=? AND s.status="ended" AND s.released=1', [$session_id]);
    if (!$s) return 0;
    $channels = notify_channels();
    $res = [];
    foreach (session_student_results($session_id) as $r) if ($r['student_code']) $res[$r['student_code']] = $r;
    $n = 0;
    foreach (qall('SELECT p.id, p.phone, p.notify_channel, l.student_code FROM parent_links l JOIN parents p ON p.id=l.parent_id WHERE l.class_id=? AND p.notify_channel<>"none" AND p.phone IS NOT NULL', [$s['class_id']]) as $p) {
        $r = $res[$p['student_code']] ?? null;
        if (!$r || !isset($channels[$p['notify_channel']]) || !(int)$r['n']) continue;
        $data = ['hs' => $r['full_name'], 'lop' => $s['class_name'], 'mon' => $s['subject'] ?: 'học', 'dung' => (string)(int)$r['ok'], 'tong' => (string)(int)$r['n'],
                 'ngay' => date('d/m/Y', strtotime($s['started_at'])), 'vang' => $r['present'] ? '0' : '1'];
        $tpl = $r['present'] ? (setting('notify_template') ?: NOTIFY_DEFAULT_TEMPLATE) : NOTIFY_ABSENT_TEMPLATE;
        $msg = mb_substr(strtr($tpl, array_combine(array_map(fn($k) => '{' . $k . '}', array_keys($data)), array_values($data))), 0, 500);
        $st = q('INSERT IGNORE INTO notifications (session_id, parent_id, student_code, channel, phone, message, data) VALUES (?,?,?,?,?,?,?)',
            [$session_id, $p['id'], $p['student_code'], setting('notify_test_mode') === '1' ? 'log' : $p['notify_channel'], $p['phone'], $msg, json_encode($data, JSON_UNESCAPED_UNICODE)]);
        $n += $st->rowCount();
    }
    return $n;
}

function notify_http(string $url, array $headers, array $body): array {
    if (!function_exists('curl_init')) throw new RuntimeException('Thiếu PHP cURL.');
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers), CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE)]);
    $res = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    if ($res === false) throw new RuntimeException('Không kết nối được: ' . $err);
    return [$code, json_decode((string)$res, true), (string)$res];
}

/** Gửi một tin. Ném lỗi kèm lý do khi nhà cung cấp từ chối. */
function notify_deliver(array $n): void {
    if ($n['channel'] === 'log') return;          // chế độ thử: chỉ lưu nhật ký
    if ($n['channel'] === 'zalo') {
        // Zalo Notification Service: template do OA đăng ký và Zalo duyệt; tham số khớp tên trong template.
        $data = json_decode((string)$n['data'], true) ?: [];
        $keys = array_filter(array_map('trim', explode(',', setting('zalo_template_params') ?: 'hs,lop,mon,dung,tong,ngay')));
        $tpl = []; foreach ($keys as $k) { [$name, $src] = array_pad(explode('=', $k, 2), 2, null); $tpl[$name] = (string)($data[$src ?? $name] ?? ''); }
        [$code, $j, $raw] = notify_http(setting('zalo_endpoint') ?: 'https://business.openapi.zalo.me/message/template', ['access_token: ' . setting('zalo_access_token')],
            ['phone' => '84' . substr($n['phone'], 1), 'template_id' => setting('zalo_template_id'), 'template_data' => $tpl, 'tracking_id' => 'ars-' . $n['id']]);
        if ($code !== 200 || !is_array($j) || (int)($j['error'] ?? -1) !== 0) throw new RuntimeException('Zalo: ' . mb_substr((string)($j['message'] ?? $raw), 0, 300));
        return;
    }
    // SMS qua cổng HTTP của nhà mạng / đại lý (eSMS, SpeedSMS…): POST JSON {phone, message}; 2xx = thành công.
    $msg = setting('sms_ascii', '1') === '1' ? vn_ascii($n['message']) : $n['message'];
    [$code, , $raw] = notify_http((string)setting('sms_url'), setting('sms_token') ? ['Authorization: Bearer ' . setting('sms_token')] : [], ['phone' => $n['phone'], 'message' => $msg]);
    if ($code < 200 || $code >= 300) throw new RuntimeException('SMS HTTP ' . $code . ': ' . mb_substr($raw, 0, 300));
}

/** Gửi các tin đang chờ hoặc lỗi chưa quá số lần thử. $session_ids giới hạn theo buổi (NULL = mọi buổi). */
function notify_send_pending(int $limit = 50, ?array $session_ids = null): array {
    $where = $session_ids !== null ? ' AND session_id IN (' . (implode(',', array_map('intval', $session_ids)) ?: '0') . ')' : '';
    $sent = 0; $failed = 0;
    foreach (qall('SELECT * FROM notifications WHERE (status="pending" OR (status="failed" AND attempts<?))' . $where . ' ORDER BY id LIMIT ' . max(1, $limit), [NOTIFY_MAX_ATTEMPTS]) as $n) {
        // giữ chỗ trước khi gửi: hai yêu cầu cùng lúc không gửi trùng một tin
        if (!q('UPDATE notifications SET attempts=attempts+1, status="sending" WHERE id=? AND status IN ("pending","failed") AND attempts=?', [$n['id'], $n['attempts']])->rowCount()) continue;
        try {
            notify_deliver($n);
            q('UPDATE notifications SET status="sent", sent_at=NOW(), error=NULL WHERE id=?', [$n['id']]); $sent++;
        } catch (Throwable $e) {
            q('UPDATE notifications SET status="failed", error=? WHERE id=?', [mb_substr($e->getMessage(), 0, 500), $n['id']]); $failed++;
        }
    }
    return ['sent' => $sent, 'failed' => $failed];
}
