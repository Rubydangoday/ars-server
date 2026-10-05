-- v9: nhiều giáo viên dùng chung một server, chọn kiểu kết nối clicker (Bluetooth / Wi-Fi) cho từng lớp.
-- Chạy lại nhiều lần không lỗi.

-- Giáo viên: quyền quản trị, khoá tài khoản, lần đăng nhập cuối
ALTER TABLE teachers ADD COLUMN IF NOT EXISTS is_admin   TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE teachers ADD COLUMN IF NOT EXISTS active     TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE teachers ADD COLUMN IF NOT EXISTS last_login DATETIME NULL;
-- tài khoản tạo đầu tiên là quản trị (nếu chưa có ai là quản trị)
UPDATE teachers SET is_admin = 1
 WHERE id = (SELECT id FROM (SELECT MIN(id) AS id FROM teachers) t)
   AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM teachers WHERE is_admin = 1) a);

-- Lớp: kiểu kết nối clicker
--   ble  = clicker nRF52840 → Bluetooth → gateway USB (cắm vào máy chủ hoặc máy GV qua Chrome)
--   wifi = clicker ESP32 → Wi-Fi → MQTT của máy chủ
ALTER TABLE classes ADD COLUMN IF NOT EXISTS conn_mode ENUM('ble','wifi') NOT NULL DEFAULT 'ble';

-- Thiết bị: gói cuối cùng đến qua đường nào
ALTER TABLE devices ADD COLUMN IF NOT EXISTS last_via VARCHAR(8) NULL;

-- Chế độ đăng ký giáo viên: invite (cần mã mời, mặc định) | open | closed
INSERT IGNORE INTO settings (k, v) VALUES ('teacher_signup', 'invite');
