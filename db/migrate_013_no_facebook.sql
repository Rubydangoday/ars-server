-- Bỏ đăng nhập Facebook (chỉ còn Google): xoá liên kết Facebook cũ và cấu hình App ID / secret đã lưu.
-- Người từng đăng nhập bằng Facebook vẫn vào được bằng mật khẩu ARS (hoặc liên kết Google).
USE ars;
DELETE FROM oauth_identities WHERE provider = 'facebook';
DELETE FROM settings WHERE k IN ('oauth_facebook_id', 'oauth_facebook_secret');
