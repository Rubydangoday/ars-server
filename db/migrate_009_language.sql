-- ARS v14. Backup DB, run AFTER migration 008. MariaDB; safe to rerun.
-- Ngôn ngữ giao diện theo tài khoản (vi | en). NULL = theo trình duyệt / mặc định tiếng Việt.
USE ars;
ALTER TABLE teachers ADD COLUMN IF NOT EXISTS lang VARCHAR(5) NULL;
ALTER TABLE parents ADD COLUMN IF NOT EXISTS lang VARCHAR(5) NULL;
