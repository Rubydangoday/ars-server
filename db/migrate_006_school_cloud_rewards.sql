-- ARS v11. Backup DB, run AFTER migrations 002–005. MariaDB; safe to rerun.
-- Phân quyền trường, đồng bộ Cloud, thi đua/đổi quà, thông báo Zalo/SMS.
USE ars;

-- 1. Phân quyền tổ chức: Admin trường (BGH / chủ trung tâm) → GV chủ nhiệm / bộ môn → HS / PH
ALTER TABLE teachers ADD COLUMN IF NOT EXISTS school_role ENUM('teacher','school_admin') NOT NULL DEFAULT 'teacher';
-- Mỗi GV bộ môn có bản ghi lớp riêng; cohort = lớp hành chính (VD "10A1") gom các môn của cùng một lớp.
ALTER TABLE classes ADD COLUMN IF NOT EXISTS subject VARCHAR(60) NULL;
ALTER TABLE classes ADD COLUMN IF NOT EXISTS cohort VARCHAR(30) NULL;
CREATE TABLE IF NOT EXISTS school_cohorts (
 school_id INT NOT NULL, name VARCHAR(30) NOT NULL, homeroom_teacher_id INT NULL,
 PRIMARY KEY(school_id, name),
 FOREIGN KEY(school_id) REFERENCES schools(id) ON DELETE CASCADE,
 FOREIGN KEY(homeroom_teacher_id) REFERENCES teachers(id) ON DELETE SET NULL
);

-- 2. Đồng bộ Hybrid (server lớp học → server Cloud)
-- Phía local: địa chỉ Cloud + mã đồng bộ của từng giáo viên; buổi đã đẩy lên.
ALTER TABLE teachers ADD COLUMN IF NOT EXISTS cloud_url VARCHAR(255) NULL;
ALTER TABLE teachers ADD COLUMN IF NOT EXISTS cloud_token VARCHAR(80) NULL;
ALTER TABLE sessions ADD COLUMN IF NOT EXISTS cloud_synced_at DATETIME NULL;
CREATE TABLE IF NOT EXISTS sync_log (
 id INT AUTO_INCREMENT PRIMARY KEY, teacher_id INT NOT NULL, direction ENUM('push','receive') NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, ok TINYINT(1) NOT NULL, message VARCHAR(1000) NULL,
 KEY k_sync_teacher (teacher_id, id)
);
-- Phía Cloud: mã cho phép một server lớp học đẩy dữ liệu vào tài khoản giáo viên; bảng ánh xạ id local → id cloud.
CREATE TABLE IF NOT EXISTS sync_tokens (
 id INT AUTO_INCREMENT PRIMARY KEY, teacher_id INT NOT NULL, label VARCHAR(100) NOT NULL,
 token_hash CHAR(64) NOT NULL UNIQUE, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 last_used_at DATETIME NULL, revoked TINYINT(1) NOT NULL DEFAULT 0,
 FOREIGN KEY(teacher_id) REFERENCES teachers(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS sync_map (
 teacher_id INT NOT NULL, source CHAR(32) NOT NULL, entity VARCHAR(20) NOT NULL, local_id BIGINT NOT NULL, cloud_id BIGINT NOT NULL,
 PRIMARY KEY(teacher_id, source, entity, local_id),
 FOREIGN KEY(teacher_id) REFERENCES teachers(id) ON DELETE CASCADE
);

-- 3. Thi đua & đổi quà
ALTER TABLE classes ADD COLUMN IF NOT EXISTS leaderboard_visible TINYINT(1) NOT NULL DEFAULT 1;
CREATE TABLE IF NOT EXISTS rewards (
 id INT AUTO_INCREMENT PRIMARY KEY, class_id INT NOT NULL, name VARCHAR(100) NOT NULL,
 kind ENUM('avatar','badge','gift') NOT NULL, icon VARCHAR(16) NOT NULL, cost INT UNSIGNED NOT NULL,
 stock INT UNSIGNED NULL, active TINYINT(1) NOT NULL DEFAULT 1,
 FOREIGN KEY(class_id) REFERENCES classes(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS reward_redemptions (
 id INT AUTO_INCREMENT PRIMARY KEY, reward_id INT NOT NULL, class_id INT NOT NULL, student_code VARCHAR(20) NOT NULL,
 cost INT UNSIGNED NOT NULL, status ENUM('granted','pending','delivered','rejected') NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, handled_at DATETIME NULL,
 KEY k_red_student (class_id, student_code),
 FOREIGN KEY(reward_id) REFERENCES rewards(id) ON DELETE CASCADE,
 FOREIGN KEY(class_id) REFERENCES classes(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS student_profiles (
 student_code VARCHAR(20) PRIMARY KEY, avatar VARCHAR(16) NULL
);

-- 4. Thông báo Zalo / SMS cho phụ huynh (phụ huynh tự đăng ký nhận)
ALTER TABLE parents ADD COLUMN IF NOT EXISTS phone VARCHAR(15) NULL;
ALTER TABLE parents ADD COLUMN IF NOT EXISTS notify_channel ENUM('none','zalo','sms') NOT NULL DEFAULT 'none';
CREATE TABLE IF NOT EXISTS notifications (
 id INT AUTO_INCREMENT PRIMARY KEY, session_id INT NOT NULL, parent_id INT NOT NULL, student_code VARCHAR(20) NOT NULL,
 channel ENUM('zalo','sms','log') NOT NULL, phone VARCHAR(15) NOT NULL, message VARCHAR(500) NOT NULL, data TEXT NULL,
 status ENUM('pending','sending','sent','failed') NOT NULL DEFAULT 'pending', error VARCHAR(500) NULL, attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, sent_at DATETIME NULL,
 UNIQUE KEY uq_notify (session_id, parent_id, student_code),
 FOREIGN KEY(session_id) REFERENCES sessions(id) ON DELETE CASCADE,
 FOREIGN KEY(parent_id) REFERENCES parents(id) ON DELETE CASCADE
);
