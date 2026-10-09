-- ARS v19. Backup DB, run AFTER migration 010. MariaDB; safe to rerun.
-- Bộ câu hỏi có trạng thái "Đang soạn" / "Đã hoàn tất": giáo viên xác nhận đã đủ câu trước khi dùng.
USE ars;
ALTER TABLE question_sets ADD COLUMN IF NOT EXISTS finalized_at DATETIME NULL;      -- NULL = đang soạn
ALTER TABLE question_sets ADD COLUMN IF NOT EXISTS target_n SMALLINT UNSIGNED NULL; -- số câu dự kiến (để kiểm tra đủ câu)
