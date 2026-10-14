-- Điểm danh buổi học + thông báo trong web cho phụ huynh.
--   session_attendance : giáo viên sửa tay (Có mặt / Vắng / Vắng có phép); không có dòng = tự động.
--   attendance_final   : kết quả cuối mỗi (buổi, ghế): sửa tay nếu có, ngược lại "có mặt" khi đã LOGIN bằng Remote
--                        hoặc có câu trả lời được nhận trong buổi, còn lại "vắng".
--   parent_messages    : hộp thông báo trong trang phụ huynh (miễn phí, không cần Zalo/SMS).
USE ars;

CREATE TABLE IF NOT EXISTS session_attendance (
  session_id  INT NOT NULL,
  seat_no     TINYINT UNSIGNED NOT NULL,
  status      ENUM('present','absent','excused') NOT NULL,
  note        VARCHAR(200) NULL,
  teacher_id  INT NULL,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (session_id, seat_no),
  FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE OR REPLACE VIEW attendance_final AS
SELECT s.id AS session_id, s.class_id, st.seat_no, st.student_code, st.full_name,
       COALESCE(a.status,
                CASE WHEN EXISTS (SELECT 1 FROM session_logins l WHERE l.session_id = s.id AND l.seat_no = st.seat_no)
                       OR EXISTS (SELECT 1 FROM responses r JOIN session_questions sq ON sq.id = r.session_q_id
                                  WHERE sq.session_id = s.id AND r.seat_no = st.seat_no)
                     THEN 'present' ELSE 'absent' END) AS status,
       IF(a.status IS NULL, 'auto', 'teacher') AS source,
       a.note
FROM sessions s
JOIN students st ON st.class_id = s.class_id
LEFT JOIN session_attendance a ON a.session_id = s.id AND a.seat_no = st.seat_no;

CREATE TABLE IF NOT EXISTS parent_messages (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  parent_id    INT NOT NULL,
  student_code VARCHAR(20) NOT NULL,
  class_id     INT NOT NULL,
  session_id   INT NULL,
  kind         VARCHAR(20) NOT NULL,          -- session: điểm danh (+ điểm khi kết quả đã công bố; cập nhật khi công bố sau)
  title        VARCHAR(200) NOT NULL,
  body         VARCHAR(1000) NOT NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  read_at      DATETIME NULL,
  UNIQUE KEY uq_pm (parent_id, student_code, session_id, kind),
  KEY k_unread (parent_id, read_at),
  FOREIGN KEY (parent_id) REFERENCES parents(id) ON DELETE CASCADE,
  FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB;
