-- ARS v15. Backup DB, run AFTER migration 009. MariaDB; safe to rerun.
-- Trợ lý học tập "Cú Học": nhiều cuộc trò chuyện (như các app chat AI), đánh giá 👍/👎 từng câu trả lời.
USE ars;
CREATE TABLE IF NOT EXISTS tutor_conversations (
 id INT AUTO_INCREMENT PRIMARY KEY, student_code VARCHAR(20) NOT NULL, title VARCHAR(120) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY k_conv_student (student_code, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE tutor_messages ADD COLUMN IF NOT EXISTS conversation_id INT NULL;
ALTER TABLE tutor_messages ADD COLUMN IF NOT EXISTS feedback TINYINT NULL;          -- 1 = 👍, -1 = 👎
CREATE INDEX IF NOT EXISTS k_msg_conv ON tutor_messages (conversation_id, id);
-- Tin nhắn cũ (trước khi có cuộc trò chuyện): gom vào một cuộc "Trò chuyện trước đây" của từng học sinh
INSERT INTO tutor_conversations (student_code, title, created_at, updated_at)
  SELECT student_code, 'Trò chuyện trước đây', MIN(created_at), MAX(created_at) FROM tutor_messages WHERE conversation_id IS NULL GROUP BY student_code;
UPDATE tutor_messages m JOIN tutor_conversations c ON c.student_code=m.student_code AND c.title='Trò chuyện trước đây'
  SET m.conversation_id=c.id WHERE m.conversation_id IS NULL;
