-- Tài khoản phụ huynh theo số điện thoại (giáo viên cấp):
--   students.parent_phone : SĐT phụ huynh trong danh sách lớp (nhập tay hoặc cột "SĐT phụ huynh" trong file danh sách)
--   parents.provisioned   : 1 = tài khoản do giáo viên cấp, tên đăng nhập = SĐT
--   parents.must_change_pw: 1 = đang dùng mật khẩu giáo viên cấp → lần đăng nhập đầu bắt buộc đổi mật khẩu
USE ars;
ALTER TABLE students ADD COLUMN IF NOT EXISTS parent_phone VARCHAR(15) NULL;
ALTER TABLE parents ADD COLUMN IF NOT EXISTS provisioned TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE parents ADD COLUMN IF NOT EXISTS must_change_pw TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE parents ADD COLUMN IF NOT EXISTS pw_issued_at DATETIME NULL;
