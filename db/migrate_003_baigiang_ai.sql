-- Nâng cấp CSDL: 10 buổi học có bài giảng, bộ câu hỏi kiểm tra/ôn tập, trợ lý AI soạn câu hỏi, cài đặt.
-- Chạy sau schema.sql và migrate_002 (install_ubuntu.sh tự chạy; chạy lại nhiều lần không sao):
--   mysql -u root ars < db/migrate_003_baigiang_ai.sql
USE ars;

-- Bộ câu hỏi dùng để kiểm tra (chiếu lên lớp, clicker) hay để ôn tập (học sinh làm trên web)
ALTER TABLE question_sets
  ADD COLUMN IF NOT EXISTS purpose     ENUM('test','practice') NOT NULL DEFAULT 'test' AFTER title,
  ADD COLUMN IF NOT EXISTS description VARCHAR(500) NULL AFTER purpose;

-- Mức độ và nguồn gốc câu hỏi (soạn tay / Excel / Word / AI)
ALTER TABLE questions
  ADD COLUMN IF NOT EXISTS difficulty ENUM('de','tb','kho') NULL AFTER topic,
  ADD COLUMN IF NOT EXISTS origin     VARCHAR(12) NULL AFTER explanation;

-- Buổi học theo chương trình (mặc định 10 buổi mỗi lớp): file bài giảng, kiến thức trọng tâm, câu hỏi củng cố
CREATE TABLE IF NOT EXISTS lessons (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  class_id      INT NOT NULL,
  no            TINYINT UNSIGNED NOT NULL,          -- Buổi 1, 2, …
  title         VARCHAR(200) NOT NULL DEFAULT '',
  taught_on     DATE NULL,
  file_name     VARCHAR(255) NULL,                  -- tên file gốc giáo viên tải lên
  file_store    VARCHAR(80)  NULL,                  -- tên file lưu trong thư mục uploads
  file_size     INT UNSIGNED NULL,
  lecture_text  MEDIUMTEXT NULL,                    -- chữ đọc được từ file (cho AI tóm tắt / soạn câu hỏi)
  key_points    TEXT NULL,                          -- kiến thức trọng tâm cuối buổi
  quiz_set_id   INT NULL,                           -- bộ "câu hỏi củng cố" của buổi
  published     TINYINT(1) NOT NULL DEFAULT 0,      -- học sinh xem được bài giảng + làm câu hỏi củng cố
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_lesson (class_id, no),
  CONSTRAINT fk_lesson_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
  CONSTRAINT fk_lesson_set   FOREIGN KEY (quiz_set_id) REFERENCES question_sets(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Phiên trả lời bằng clicker gắn với buổi nào (không bắt buộc)
ALTER TABLE sessions ADD COLUMN IF NOT EXISTS lesson_id INT NULL AFTER set_id;

-- Học sinh làm câu hỏi củng cố của buổi học
ALTER TABLE practice_attempts MODIFY source ENUM('assignment','wrong','topic','session','lesson') NOT NULL;
ALTER TABLE practice_attempts ADD COLUMN IF NOT EXISTS lesson_id INT NULL AFTER assignment_id;

-- Cài đặt chung (trợ lý AI, tên đơn vị…)
CREATE TABLE IF NOT EXISTS settings (
  k VARCHAR(50) PRIMARY KEY,
  v TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Nhật ký gọi trợ lý AI (thống kê số lần dùng, token)
CREATE TABLE IF NOT EXISTS ai_log (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  teacher_id  INT NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  provider    VARCHAR(20) NOT NULL,
  model       VARCHAR(80) NULL,
  task        VARCHAR(20) NOT NULL,                 -- questions | summary | test
  prompt      VARCHAR(1000) NULL,
  n_out       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  tokens_in   INT UNSIGNED NULL,
  tokens_out  INT UNSIGNED NULL,
  ms          INT UNSIGNED NULL,
  error       VARCHAR(500) NULL,
  KEY k_teacher (teacher_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mã tham gia lớp: học sinh tự đăng ký tài khoản và vào lớp bằng mã (giống Google Classroom / Azota)
ALTER TABLE classes
  ADD COLUMN IF NOT EXISTS join_code CHAR(6) NULL AFTER room_id,
  ADD COLUMN IF NOT EXISTS join_open TINYINT(1) NOT NULL DEFAULT 1 AFTER join_code;
CREATE UNIQUE INDEX IF NOT EXISTS uq_join_code ON classes (join_code);

-- Trợ lý học tập của học sinh: lịch sử hội thoại và kết quả câu hỏi luyện với trợ lý
CREATE TABLE IF NOT EXISTS tutor_messages (
  id            BIGINT AUTO_INCREMENT PRIMARY KEY,
  student_code  VARCHAR(20) NOT NULL,
  role          ENUM('user','assistant') NOT NULL,
  mode          VARCHAR(16) NOT NULL DEFAULT 'ask',   -- ask | explain | quiz | advanced | summary
  content       MEDIUMTEXT NOT NULL,
  quiz          MEDIUMTEXT NULL,                       -- JSON câu hỏi trắc nghiệm (mode quiz/advanced)
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_student (student_code, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tutor_answers (
  id            BIGINT AUTO_INCREMENT PRIMARY KEY,
  student_code  VARCHAR(20) NOT NULL,
  message_id    BIGINT NOT NULL,
  q_index       TINYINT UNSIGNED NOT NULL,
  answer        CHAR(1) NOT NULL,
  is_correct    TINYINT(1) NOT NULL,
  topic         VARCHAR(100) NULL,
  answered_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ans (message_id, q_index),
  KEY k_student (student_code, answered_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mã khôi phục mật khẩu giáo viên (hiện một lần khi tạo tài khoản / tạo lại trong Cài đặt)
ALTER TABLE teachers ADD COLUMN IF NOT EXISTS recovery_hash VARCHAR(255) NULL AFTER password_hash;
