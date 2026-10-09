-- ARS v19. Backup DB, run AFTER migration 011. MariaDB; safe to rerun.
-- Phần cứng chỉ dùng nRF52840 (Remote Bluetooth → gateway USB): mọi lớp về kiểu kết nối Bluetooth, bỏ thông số Wi-Fi cho clicker.
USE ars;
UPDATE classes SET conn_mode='ble' WHERE conn_mode<>'ble';
DELETE FROM settings WHERE k IN ('wifi_ssid','wifi_pass');
