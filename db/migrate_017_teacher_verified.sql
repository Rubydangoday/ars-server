-- Giáo viên đăng ký khi server ở chế độ "cần mã mời" vào web ngay, nhập mã mời sau (thanh nhắc đầu trang / trang Giáo viên).
--   verified = 0: chưa nhập mã mời — dùng web bình thường nhưng AI chạy chế độ Cơ bản (offline), không tải tệp lên server.
--   Tài khoản đã có trước đây: verified = 1.
USE ars;
ALTER TABLE teachers ADD COLUMN IF NOT EXISTS verified TINYINT(1) NOT NULL DEFAULT 1;
