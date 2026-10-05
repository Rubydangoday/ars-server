-- Nâng cấp CSDL cho cổng học sinh (chạy 1 lần trên CSDL đã tạo bằng schema.sql bản đầu):
--   mysql -u root ars < db/migrate_002_hocsinh.sql
USE ars;

-- Câu hỏi: chủ đề (để gom câu sai theo phần kiến thức) và lời giải thích (hiện khi xem lại / ôn tập)
ALTER TABLE questions
  ADD COLUMN IF NOT EXISTS topic       VARCHAR(100) NULL AFTER content,
  ADD COLUMN IF NOT EXISTS explanation TEXT NULL AFTER correct;

-- Buổi học hay bài kiểm tra; học sinh chỉ xem lại được khi buổi đã kết thúc và đã công bố
ALTER TABLE sessions
  ADD COLUMN IF NOT EXISTS kind     ENUM('class','test') NOT NULL DEFAULT 'class' AFTER set_id,
  ADD COLUMN IF NOT EXISTS released TINYINT(1) NOT NULL DEFAULT 1 AFTER allow_change;

-- Tài khoản học sinh: đăng nhập bằng MSSV (students.student_code). Tạo tự động lần đăng nhập đầu,
-- mật khẩu mặc định = MSSV, bắt buộc đổi.
CREATE TABLE IF NOT EXISTS student_users (
  student_code  VARCHAR(20) PRIMARY KEY,
  password_hash VARCHAR(255) NULL,
  must_change   TINYINT(1) NOT NULL DEFAULT 1,
  last_login    DATETIME NULL
) ENGINE=InnoDB;

-- Giáo viên giao bộ câu hỏi cho lớp để ôn tập (trước kiểm tra hoặc bài tập về nhà)
CREATE TABLE IF NOT EXISTS practice_assignments (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  class_id    INT NOT NULL,
  set_id      INT NOT NULL,
  title       VARCHAR(200) NOT NULL,
  kind        ENUM('pre_test','homework') NOT NULL DEFAULT 'pre_test',
  mode        ENUM('practice','exam') NOT NULL DEFAULT 'practice',   -- practice: báo đúng/sai ngay; exam: chấm cuối bài
  time_limit  SMALLINT UNSIGNED NULL,                                -- phút, chỉ dùng cho mode exam
  due_at      DATETIME NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
  FOREIGN KEY (set_id)   REFERENCES question_sets(id)
) ENGINE=InnoDB;

-- Một lượt ôn tập của học sinh
CREATE TABLE IF NOT EXISTS practice_attempts (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  student_code  VARCHAR(20) NOT NULL,
  class_id      INT NOT NULL,
  assignment_id INT NULL,
  source        ENUM('assignment','wrong','topic','session') NOT NULL,
  title         VARCHAR(200) NOT NULL,
  mode          ENUM('practice','exam') NOT NULL DEFAULT 'practice',
  deadline      DATETIME NULL,                 -- hết giờ (mode exam có time_limit)
  started_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at   DATETIME NULL,
  n_questions   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  n_first_ok    SMALLINT UNSIGNED NOT NULL DEFAULT 0,   -- đúng ngay lần đầu
  KEY k_att_student (student_code, started_at),
  FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
  FOREIGN KEY (assignment_id) REFERENCES practice_assignments(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Từng câu trong lượt ôn; câu trả lời sai ở chế độ practice được xếp lại cuối lượt (round > 1) tới khi đúng
CREATE TABLE IF NOT EXISTS practice_items (
  id           BIGINT AUTO_INCREMENT PRIMARY KEY,
  attempt_id   INT NOT NULL,
  question_id  INT NOT NULL,
  ord          SMALLINT UNSIGNED NOT NULL,
  round        TINYINT UNSIGNED NOT NULL DEFAULT 1,
  answer       CHAR(1) NULL,
  is_correct   TINYINT(1) NULL,
  answered_at  DATETIME NULL,
  KEY k_item_q (question_id),
  FOREIGN KEY (attempt_id) REFERENCES practice_attempts(id) ON DELETE CASCADE,
  FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Sổ tay lỗi sai: học sinh ghi vì sao sai từng câu (rút kinh nghiệm)
CREATE TABLE IF NOT EXISTS wrong_notes (
  student_code VARCHAR(20) NOT NULL,
  question_id  INT NOT NULL,
  reason       ENUM('chua_hoc','nham_khai_niem','doc_sai_de','tinh_sai','bam_nham','doan','khac') NULL,
  note         VARCHAR(1000) NULL,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (student_code, question_id),
  FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE
) ENGINE=InnoDB;
