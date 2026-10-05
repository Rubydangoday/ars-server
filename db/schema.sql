-- ARS — Audience Response System: cơ sở dữ liệu MariaDB
-- Chạy: mysql -u root -p < db/schema.sql
-- Mọi kiểm tra hợp lệ nằm ở server (ingest.py); clicker/gateway chỉ truyền nhận.

CREATE DATABASE IF NOT EXISTS ars CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE ars;

CREATE TABLE IF NOT EXISTS teachers (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(50)  NOT NULL UNIQUE,
  full_name     VARCHAR(100) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Lớp học; room_id là mã lớp phát trong beacon (1–255)
CREATE TABLE IF NOT EXISTS classes (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  teacher_id  INT NOT NULL,
  name        VARCHAR(100) NOT NULL,
  room_id     TINYINT UNSIGNED NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_class_room (teacher_id, room_id),
  FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Học sinh; seat_no = ID gõ trên clicker (1–99)
CREATE TABLE IF NOT EXISTS students (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  class_id     INT NOT NULL,
  seat_no      TINYINT UNSIGNED NOT NULL,
  student_code VARCHAR(20)  NULL,
  full_name    VARCHAR(100) NOT NULL,
  UNIQUE KEY uq_student_seat (class_id, seat_no),
  FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
  CHECK (seat_no BETWEEN 1 AND 99)
) ENGINE=InnoDB;

-- Clicker: nhận diện bằng địa chỉ BLE (MAC), cập nhật mỗi lần server nghe thấy
CREATE TABLE IF NOT EXISTS devices (
  mac          CHAR(17) PRIMARY KEY,
  label        VARCHAR(20) NULL,          -- số in trên vỏ
  last_seen    DATETIME NULL,
  last_batt_mv SMALLINT UNSIGNED NULL,
  last_rssi    SMALLINT NULL,
  last_room    TINYINT UNSIGNED NULL,
  last_seat    TINYINT UNSIGNED NULL,
  pkt_total    INT UNSIGNED NOT NULL DEFAULT 0,
  pkt_dup      INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS question_sets (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  teacher_id  INT NOT NULL,
  title       VARCHAR(200) NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Câu hỏi; position = số câu hiện trên LED (1–255)
CREATE TABLE IF NOT EXISTS questions (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  set_id      INT NOT NULL,
  position    TINYINT UNSIGNED NOT NULL,
  content     TEXT NOT NULL,
  n_options   TINYINT UNSIGNED NOT NULL,
  opt_a VARCHAR(500) NULL, opt_b VARCHAR(500) NULL, opt_c VARCHAR(500) NULL, opt_d VARCHAR(500) NULL,
  opt_e VARCHAR(500) NULL, opt_f VARCHAR(500) NULL, opt_g VARCHAR(500) NULL, opt_h VARCHAR(500) NULL,
  opt_i VARCHAR(500) NULL, opt_j VARCHAR(500) NULL,
  correct     CHAR(1) NULL,                 -- 'A'..'J', NULL = câu khảo sát không có đáp án đúng
  time_limit  SMALLINT UNSIGNED NULL,       -- giây, NULL = không giới hạn
  UNIQUE KEY uq_q_pos (set_id, position),
  FOREIGN KEY (set_id) REFERENCES question_sets(id) ON DELETE CASCADE,
  CHECK (n_options BETWEEN 2 AND 10)
) ENGINE=InnoDB;

-- Buổi học: một lớp + một bộ câu hỏi
CREATE TABLE IF NOT EXISTS sessions (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  class_id      INT NOT NULL,
  set_id        INT NOT NULL,
  status        ENUM('running','ended') NOT NULL DEFAULT 'running',
  allow_change  TINYINT(1) NOT NULL DEFAULT 1,   -- cho đổi đáp án khi câu còn mở
  current_q     INT NULL,                        -- questions.id đang chiếu
  state_rev     INT UNSIGNED NOT NULL DEFAULT 0, -- web tăng mỗi khi đổi trạng thái → ingest phát lại STATE
  started_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ended_at      DATETIME NULL,
  FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
  FOREIGN KEY (set_id)   REFERENCES question_sets(id)
) ENGINE=InnoDB;

-- Trạng thái từng câu trong buổi (mở/đóng nhiều lần được)
CREATE TABLE IF NOT EXISTS session_questions (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  session_id   INT NOT NULL,
  question_id  INT NOT NULL,
  status       ENUM('open','closed') NOT NULL DEFAULT 'open',
  opened_at    DATETIME(3) NOT NULL,
  closed_at    DATETIME(3) NULL,
  deadline     DATETIME(3) NULL,
  show_result  TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_sq (session_id, question_id),
  FOREIGN KEY (session_id)  REFERENCES sessions(id) ON DELETE CASCADE,
  FOREIGN KEY (question_id) REFERENCES questions(id)
) ENGINE=InnoDB;

-- Điểm danh / ghép ghế ↔ clicker: một ghế một máy, một máy một ghế trong buổi
CREATE TABLE IF NOT EXISTS session_logins (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  session_id  INT NOT NULL,
  seat_no     TINYINT UNSIGNED NOT NULL,
  mac         CHAR(17) NOT NULL,
  logged_at   DATETIME(3) NOT NULL,
  UNIQUE KEY uq_login_seat (session_id, seat_no),
  UNIQUE KEY uq_login_mac  (session_id, mac),
  FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Câu trả lời đã nhận (sau khi loại gói trùng)
CREATE TABLE IF NOT EXISTS responses (
  id             BIGINT AUTO_INCREMENT PRIMARY KEY,
  session_q_id   INT NOT NULL,
  seat_no        TINYINT UNSIGNED NOT NULL,
  mac            CHAR(17) NOT NULL,
  seq            TINYINT UNSIGNED NOT NULL,
  answer         CHAR(1) NOT NULL,
  rssi           SMALLINT NULL,
  received_at    DATETIME(3) NOT NULL,
  UNIQUE KEY uq_resp (session_q_id, mac, seq),
  KEY k_resp_seat (session_q_id, seat_no),
  FOREIGN KEY (session_q_id) REFERENCES session_questions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Gói bị loại, kèm lý do (không tính gói trùng — xem devices.pkt_dup)
CREATE TABLE IF NOT EXISTS rejected (
  id           BIGINT AUTO_INCREMENT PRIMARY KEY,
  received_at  DATETIME(3) NOT NULL,
  room_id      TINYINT UNSIGNED NULL,
  session_id   INT NULL,
  mac          CHAR(17) NULL,
  pkt_type     TINYINT UNSIGNED NULL,
  seat_no      TINYINT UNSIGNED NULL,
  qid          TINYINT UNSIGNED NULL,
  answer       CHAR(1) NULL,
  reason       VARCHAR(40) NOT NULL,
  raw          VARCHAR(400) NULL,
  KEY k_rej_session (session_id, received_at)
) ENGINE=InnoDB;

-- Đáp án cuối của mỗi ghế cho mỗi câu = lần gửi được nhận sau cùng
-- (không dùng seq lớn nhất vì seq quay vòng 0–255)
CREATE OR REPLACE VIEW final_answers AS
SELECT r.*
FROM responses r
JOIN (SELECT session_q_id, seat_no, MAX(id) AS max_id
      FROM responses GROUP BY session_q_id, seat_no) last
  ON last.max_id = r.id;

-- Trạng thái gateway (ingest.py ghi từ MQTT ars/<room>/gw/status để web đọc)
CREATE TABLE IF NOT EXISTS gateways (
  room_id     TINYINT UNSIGNED PRIMARY KEY,
  online      TINYINT(1) NOT NULL DEFAULT 0,
  pkt_per_s   DECIMAL(6,1) NULL,
  port        VARCHAR(60) NULL,
  updated_at  DATETIME NOT NULL
) ENGINE=InnoDB;
