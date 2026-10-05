-- ARS v10. Backup DB, run AFTER migrations 002–004. MariaDB; safe to rerun.
USE ars;
CREATE TABLE IF NOT EXISTS schools (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(200) NOT NULL);
ALTER TABLE teachers ADD COLUMN IF NOT EXISTS school_id INT NULL;
ALTER TABLE classes ADD COLUMN IF NOT EXISTS grade_level TINYINT UNSIGNED NOT NULL DEFAULT 6;
ALTER TABLE question_sets ADD COLUMN IF NOT EXISTS visibility ENUM('private','school','public') NOT NULL DEFAULT 'private';
ALTER TABLE question_sets ADD COLUMN IF NOT EXISTS variant_code VARCHAR(30) NULL;
ALTER TABLE question_sets ADD COLUMN IF NOT EXISTS source_set_id INT NULL;
CREATE TABLE IF NOT EXISTS question_media (
 id INT AUTO_INCREMENT PRIMARY KEY, question_id INT NOT NULL, kind ENUM('image','audio','video') NOT NULL,
 store VARCHAR(80) NOT NULL, name VARCHAR(255) NOT NULL, mime VARCHAR(80) NOT NULL, size INT UNSIGNED NOT NULL,
 FOREIGN KEY(question_id) REFERENCES questions(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS device_mappings (
 class_id INT NOT NULL, seat_no TINYINT UNSIGNED NOT NULL, mac CHAR(17) NOT NULL,
 PRIMARY KEY(class_id,seat_no), UNIQUE KEY uq_class_mac(class_id,mac),
 FOREIGN KEY(class_id,seat_no) REFERENCES students(class_id,seat_no) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS homework (
 id INT AUTO_INCREMENT PRIMARY KEY, class_id INT NOT NULL, title VARCHAR(200) NOT NULL,
 instructions TEXT NULL, due_at DATETIME NULL, published TINYINT(1) NOT NULL DEFAULT 0,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(class_id) REFERENCES classes(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS homework_questions (
 id INT AUTO_INCREMENT PRIMARY KEY, homework_id INT NOT NULL, ord SMALLINT UNSIGNED NOT NULL,
 question_id INT NULL, kind ENUM('mcq','text','drawing','audio') NOT NULL,
 content TEXT NOT NULL, topic VARCHAR(100) NULL, rubric TEXT NULL, max_score DECIMAL(5,2) NOT NULL DEFAULT 1,
 UNIQUE KEY uq_hw_ord(homework_id,ord), FOREIGN KEY(homework_id) REFERENCES homework(id) ON DELETE CASCADE,
 FOREIGN KEY(question_id) REFERENCES questions(id) ON DELETE RESTRICT
);
CREATE TABLE IF NOT EXISTS homework_submissions (
 id INT AUTO_INCREMENT PRIMARY KEY, homework_id INT NOT NULL, student_code VARCHAR(20) NOT NULL,
 submitted_at DATETIME NULL, released TINYINT(1) NOT NULL DEFAULT 0,
 UNIQUE KEY uq_hw_student(homework_id,student_code), FOREIGN KEY(homework_id) REFERENCES homework(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS homework_answers (
 id INT AUTO_INCREMENT PRIMARY KEY, submission_id INT NOT NULL, hw_question_id INT NOT NULL,
 answer MEDIUMTEXT NULL, file_store VARCHAR(80) NULL, file_name VARCHAR(255) NULL, mime VARCHAR(80) NULL,
 score DECIMAL(5,2) NULL, feedback TEXT NULL, ai_score DECIMAL(5,2) NULL, ai_feedback TEXT NULL,
 UNIQUE KEY uq_hw_answer(submission_id,hw_question_id),
 FOREIGN KEY(submission_id) REFERENCES homework_submissions(id) ON DELETE CASCADE,
 FOREIGN KEY(hw_question_id) REFERENCES homework_questions(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS parents (
 id INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(50) NOT NULL UNIQUE, full_name VARCHAR(100) NOT NULL,
 password_hash VARCHAR(255) NOT NULL
);
CREATE TABLE IF NOT EXISTS parent_links (
 parent_id INT NOT NULL, student_code VARCHAR(20) NOT NULL, class_id INT NOT NULL,
 PRIMARY KEY(parent_id,student_code,class_id), FOREIGN KEY(parent_id) REFERENCES parents(id) ON DELETE CASCADE,
 FOREIGN KEY(class_id) REFERENCES classes(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS parent_invites (
 id INT AUTO_INCREMENT PRIMARY KEY, class_id INT NOT NULL, student_code VARCHAR(20) NOT NULL,
 token_hash CHAR(64) NOT NULL UNIQUE, expires_at DATETIME NOT NULL, used_at DATETIME NULL,
 FOREIGN KEY(class_id) REFERENCES classes(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS teacher_comments (
 id INT AUTO_INCREMENT PRIMARY KEY, class_id INT NOT NULL, student_code VARCHAR(20) NOT NULL,
 teacher_id INT NOT NULL, comment TEXT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(class_id) REFERENCES classes(id) ON DELETE CASCADE,
 FOREIGN KEY(teacher_id) REFERENCES teachers(id) ON DELETE CASCADE
);
ALTER TABLE sessions ADD COLUMN IF NOT EXISTS live_distribution TINYINT(1) NOT NULL DEFAULT 0;
