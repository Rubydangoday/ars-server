-- ARS v12. Backup DB, run AFTER migration 006. MariaDB; safe to rerun.
-- Đăng nhập bằng Google / Facebook: mỗi tài khoản ARS (giáo viên, học sinh, phụ huynh) liên kết tối đa một tài khoản mỗi nhà cung cấp.
USE ars;
CREATE TABLE IF NOT EXISTS oauth_identities (
 provider ENUM('google','facebook') NOT NULL, subject VARCHAR(100) NOT NULL,
 user_type ENUM('teacher','student','parent') NOT NULL, user_key VARCHAR(50) NOT NULL,
 email VARCHAR(255) NULL, name VARCHAR(100) NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, last_login_at DATETIME NULL,
 PRIMARY KEY(provider, subject, user_type),
 UNIQUE KEY uq_oauth_user(user_type, user_key, provider)
);
