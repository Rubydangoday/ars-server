<?php
// Tài khoản phụ huynh do giáo viên cấp theo SĐT phụ huynh trong danh sách lớp (như cổng phụ huynh của các trường):
//   tên đăng nhập = SĐT, mật khẩu lần đầu do hệ thống tạo (in / gửi phiếu cho phụ huynh), đăng nhập lần đầu bắt buộc đổi.
//   Một SĐT = một tài khoản: anh chị em (cùng hoặc khác lớp) cùng SĐT phụ huynh được gộp vào một tài khoản.
//   Mật khẩu chỉ lưu dạng băm: chỉ có trong file xuất lúc cấp. Mất phiếu → "Cấp lại mật khẩu".
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/notify.php';
require_once __DIR__ . '/school.php';
require_once __DIR__ . '/sheet.php';

/** Mật khẩu lần đầu: 8 ký tự dễ đọc khi in (bỏ 0/O, 1/l/I). */
function parent_pw_new(): string {
    $abc = 'abcdefghjkmnpqrstuvwxyz23456789';
    $s = '';
    for ($i = 0; $i < 8; $i++) $s .= $abc[random_int(0, strlen($abc) - 1)];
    return $s;
}

/** Giáo viên đổi được mật khẩu tài khoản này không: mọi con liên kết đều thuộc lớp giáo viên quản lý
 *  (chủ lớp / GV chủ nhiệm). Tài khoản dùng chung với lớp của giáo viên khác thì không. */
function parent_account_manageable(int $parent_id): bool {
    $mine = array_map('intval', array_column(family_classes(), 'id')) ?: [0];
    return !qv('SELECT 1 FROM parent_links WHERE parent_id=? AND class_id NOT IN (' . implode(',', $mine) . ') LIMIT 1', [$parent_id]);
}

function parent_account_issue(int $parent_id): string {
    $pw = parent_pw_new();
    q('UPDATE parents SET password_hash=?, must_change_pw=1, pw_issued_at=NOW() WHERE id=?', [password_hash($pw, PASSWORD_DEFAULT), $parent_id]);
    return $pw;
}

/** Trạng thái tài khoản phụ huynh của từng học sinh trong lớp: [student_code => ['phone', 'parent' (dòng parents|null)]]. */
function parent_accounts_state(int $class_id): array {
    $out = [];
    foreach (qall('SELECT s.student_code, s.parent_phone, p.id, p.must_change_pw, p.pw_issued_at FROM students s '
                . 'LEFT JOIN parents p ON p.username=s.parent_phone AND p.provisioned=1 WHERE s.class_id=? AND s.student_code IS NOT NULL', [$class_id]) as $r)
        $out[$r['student_code']] = ['phone' => $r['parent_phone'], 'parent' => $r['id'] ? $r : null];
    return $out;
}

/** Cấp tài khoản cho mọi học sinh có SĐT phụ huynh (hoặc chỉ $only_code). Trả về các dòng cho file xuất:
 *  [seat, student, code, phone, password ('' = không đổi), status]. $reissue: cấp lại mật khẩu cho tài khoản chưa đổi mật khẩu
 *  (phụ huynh chưa đăng nhập lần nào); $only_code: cấp lại cho một học sinh kể cả khi phụ huynh đã đổi (quên mật khẩu). */
function parent_accounts_provision(int $class_id, bool $reissue = false, ?string $only_code = null): array {
    $rows = [];
    $issued = [];                                                      // SĐT → [mật khẩu, học sinh] cấp trong lượt này (anh chị em)
    $sql = 'SELECT * FROM students WHERE class_id=?' . ($only_code !== null ? ' AND student_code=?' : '') . ' ORDER BY seat_no';
    foreach (qall($sql, $only_code !== null ? [$class_id, $only_code] : [$class_id]) as $s) {
        $code = (string)$s['student_code'];
        $phone = normalize_phone((string)$s['parent_phone']);
        $row = ['seat' => (int)$s['seat_no'], 'student' => $s['full_name'], 'code' => $code, 'phone' => $phone ?? (string)$s['parent_phone'], 'password' => '', 'status' => ''];
        if ($code === '') { $rows[] = ['status' => 'Thiếu MSSV — chưa cấp được'] + $row; continue; }
        db()->beginTransaction();
        try {
            // SĐT đổi hoặc bị xoá: bỏ liên kết với tài khoản cấp theo SĐT cũ
            q('DELETE l FROM parent_links l JOIN parents p ON p.id=l.parent_id WHERE l.class_id=? AND l.student_code=? AND p.provisioned=1 AND p.username<>?',
              [$class_id, $code, $phone ?? '']);
            if (!$phone) {
                db()->commit();
                $rows[] = ['status' => $s['parent_phone'] ? 'SĐT không hợp lệ (cần 10 số, VD 0912345678)' : 'Chưa có SĐT phụ huynh'] + $row;
                continue;
            }
            $p = q1('SELECT * FROM parents WHERE username=? FOR UPDATE', [$phone]);
            if ($p && isset($issued[$phone])) {
                $pid = (int)$p['id'];
                $row['password'] = $issued[$phone][0]; $row['status'] = 'Cùng tài khoản với em ' . $issued[$phone][1];
            } elseif (!$p) {
                $pw = parent_pw_new();
                q('INSERT INTO parents (username, full_name, password_hash, phone, provisioned, must_change_pw, pw_issued_at) VALUES (?,?,?,?,1,1,NOW())',
                  [$phone, mb_substr('Phụ huynh em ' . $s['full_name'], 0, 100), password_hash($pw, PASSWORD_DEFAULT), $phone]);
                $pid = (int)db()->lastInsertId();
                $row['password'] = $pw; $row['status'] = 'Tài khoản mới — đổi mật khẩu khi đăng nhập lần đầu';
            } else {
                $pid = (int)$p['id'];
                $can = parent_account_manageable($pid);
                if (!$p['provisioned']) $row['status'] = 'SĐT này đã là tên đăng nhập của một tài khoản phụ huynh — đã liên kết, phụ huynh dùng mật khẩu của mình';
                elseif (($only_code !== null || ($reissue && $p['must_change_pw'])) && $can) {
                    $row['password'] = parent_account_issue($pid); $row['status'] = 'Đã cấp lại mật khẩu — đổi mật khẩu khi đăng nhập';
                } elseif ($p['must_change_pw']) $row['status'] = $can ? 'Đã cấp trước, phụ huynh chưa đăng nhập (chọn "Cấp lại mật khẩu" nếu mất phiếu)'
                                                                       : 'Tài khoản dùng chung với lớp khác — mật khẩu đã cấp ở lớp đó';
                else $row['status'] = $only_code !== null ? 'Tài khoản dùng chung với lớp khác — nhờ giáo viên lớp đó cấp lại' : 'Đang dùng — phụ huynh đã đổi mật khẩu';
            }
            if ($row['password'] !== '') $issued[$phone] ??= [$row['password'], $s['full_name']];
            q('INSERT IGNORE INTO parent_links (parent_id, student_code, class_id) VALUES (?,?,?)', [$pid, $code, $class_id]);
            db()->commit();
        } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
        $rows[] = $row;
    }
    return $rows;
}

/** File phát cho phụ huynh: SĐT (tên đăng nhập), học sinh, mật khẩu lần đầu, link đăng nhập. */
function parent_accounts_send(array $class, array $rows, string $format): void {
    $url = share_base() . '/parent/';
    $table = [['STT', 'Họ tên học sinh', 'MSSV', 'Lớp', 'SĐT phụ huynh (tên đăng nhập)', 'Mật khẩu lần đầu', 'Trang đăng nhập', 'Ghi chú']];
    foreach ($rows as $r) $table[] = [$r['seat'], $r['student'], $r['code'], $class['name'], $r['phone'], $r['password'] !== '' ? $r['password'] : '—', $url, $r['status']];
    $fname = 'ARS_tai_khoan_phu_huynh_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $class['name']) ?: 'lop');
    if ($format === 'csv') send_csv($table, $fname . '.csv');
    send_xlsx(['Tai khoan phu huynh' => $table], $fname . '.xlsx');
}
