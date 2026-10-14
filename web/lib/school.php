<?php
// Phân quyền tổ chức: Admin trường → GV chủ nhiệm (theo lớp hành chính) / GV bộ môn (chủ lớp) → HS / PH.
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

/** Trường của giáo viên đang đăng nhập (NULL nếu chưa được gán). */
function my_school_id(): ?int {
    $v = qv('SELECT school_id FROM teachers WHERE id=?', [me()['id']]);
    return $v === null ? null : (int)$v;
}

function require_school_admin(): array {
    require_login();
    $sid = my_school_id();
    if (!is_school_admin() || !$sid) {
        http_response_code(403); page_head('Không có quyền');
        echo '<div class="card"><h1>Chỉ Admin trường</h1><p>Trang này dành cho Ban giám hiệu / chủ trung tâm. Quản trị server gán quyền ở mục <b>Trường</b>.</p></div>';
        page_foot(); exit;
    }
    return q1('SELECT * FROM schools WHERE id=?', [$sid]);
}

/**
 * Lớp giáo viên được XEM báo cáo: lớp mình dạy; lớp bộ môn thuộc lớp hành chính mình chủ nhiệm;
 * mọi lớp của giáo viên cùng trường nếu là Admin trường. Sửa dữ liệu lớp vẫn chỉ chủ lớp (own_class).
 */
function viewable_classes(): array {
    $me = (int)me()['id'];
    $school = is_school_admin() ? my_school_id() : null;
    return qall('SELECT c.*, t.full_name AS teacher_name, c.teacher_id=? AS is_owner FROM classes c JOIN teachers t ON t.id=c.teacher_id '
        . 'LEFT JOIN school_cohorts sc ON sc.school_id=t.school_id AND sc.name=c.cohort '
        . 'WHERE c.teacher_id=? OR sc.homeroom_teacher_id=? OR (? IS NOT NULL AND t.school_id=?) '
        . 'ORDER BY c.cohort IS NULL, c.cohort, c.name, c.subject', [$me, $me, $me, $school, $school]);
}
function viewable_class(int $id): array {
    foreach (viewable_classes() as $c) if ((int)$c['id'] === $id) return $c;
    http_response_code(404); exit('Không tìm thấy lớp.');
}

/** Lớp giáo viên được quản lý liên lạc phụ huynh: chủ lớp hoặc GV chủ nhiệm lớp hành chính. */
function family_classes(): array {
    $me = (int)me()['id'];
    return qall('SELECT c.* FROM classes c JOIN teachers t ON t.id=c.teacher_id LEFT JOIN school_cohorts sc ON sc.school_id=t.school_id AND sc.name=c.cohort '
        . 'WHERE c.teacher_id=? OR sc.homeroom_teacher_id=? ORDER BY c.name', [$me, $me]);
}
function family_class(int $id): array {
    foreach (family_classes() as $c) if ((int)$c['id'] === $id) return $c;
    http_response_code(404); exit('Không tìm thấy lớp.');
}

/** Lớp hành chính GV được xem tổng hợp: lớp mình chủ nhiệm, hoặc mọi lớp hành chính nếu là Admin trường. */
function cohort_access(int $school_id, string $name): bool {
    if (is_school_admin() && my_school_id() === $school_id) return true;
    return (bool)qv('SELECT 1 FROM school_cohorts WHERE school_id=? AND name=? AND homeroom_teacher_id=?', [$school_id, $name, me()['id']]);
}
function my_homerooms(): array {
    return qall('SELECT * FROM school_cohorts WHERE homeroom_teacher_id=? ORDER BY name', [me()['id']]);
}

/** Tỉ lệ đúng Remote và chuyên cần của từng học sinh trong một lớp (buổi đã kết thúc, đã công bố). key = seat_no */
function class_remote_summary(int $class_id): array {
    $out = [];
    foreach (qall('SELECT st.seat_no, st.student_code, st.full_name, s.id AS sid, '
        . '(SELECT COUNT(*) FROM attendance_final af WHERE af.session_id=s.id AND af.seat_no=st.seat_no AND af.status="present") AS present, '
        . '(SELECT COUNT(*) FROM session_questions sq JOIN questions q ON q.id=sq.question_id WHERE sq.session_id=s.id AND q.correct IS NOT NULL) AS n, '
        . '(SELECT COUNT(*) FROM final_answers f JOIN session_questions sq ON sq.id=f.session_q_id JOIN questions q ON q.id=sq.question_id '
        . '  WHERE sq.session_id=s.id AND f.seat_no=st.seat_no AND f.answer=q.correct) AS ok '
        . 'FROM students st JOIN sessions s ON s.class_id=st.class_id AND s.status="ended" AND s.released=1 WHERE st.class_id=?', [$class_id]) as $r) {
        $k = (int)$r['seat_no'];
        $out[$k] ??= ['code' => $r['student_code'], 'name' => $r['full_name'], 'sessions' => 0, 'present' => 0, 'n' => 0, 'ok' => 0];
        $out[$k]['sessions']++;
        if ($r['present']) { $out[$k]['present']++; $out[$k]['n'] += (int)$r['n']; $out[$k]['ok'] += (int)$r['ok']; }
    }
    return $out;
}
