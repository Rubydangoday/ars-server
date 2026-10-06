-- ARS v13. Backup DB, run AFTER migration 007. MariaDB; safe to rerun.
-- Học sinh tự tạo tài khoản khi chưa có tên trong lớp nào, vào lớp sau bằng mã tham gia lớp.
USE ars;
-- Họ tên lưu theo tài khoản (học sinh chưa thuộc lớp nào chưa có dòng trong `students`).
ALTER TABLE student_users ADD COLUMN IF NOT EXISTS full_name VARCHAR(100) NULL;
-- 1 = tự đăng ký khi mã học sinh chưa có trong danh sách lớp nào: chưa có gì xác nhận đúng là học sinh đó.
-- Hết cờ khi vào lớp bằng mã tham gia lớp. Giáo viên thấy cảnh báo nếu tự thêm mã này vào danh sách lớp.
ALTER TABLE student_users ADD COLUMN IF NOT EXISTS unverified TINYINT(1) NOT NULL DEFAULT 0;
