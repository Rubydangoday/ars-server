-- Tự đồng bộ Cloud: khi máy chủ lớp học bật, dịch vụ ars-autosync (systemd timer, mỗi 3 phút) tự đẩy buổi mới lên Cloud
-- cho mọi giáo viên đã nhập địa chỉ Cloud + mã đồng bộ. Mặc định BẬT; giáo viên tắt được ở trang Đồng bộ Cloud.
USE ars;
ALTER TABLE teachers ADD COLUMN IF NOT EXISTS cloud_auto TINYINT(1) NOT NULL DEFAULT 1;
