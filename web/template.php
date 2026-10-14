<?php
// File Excel mẫu: sheet "Cau hoi" (bộ câu hỏi) và "Danh sach lop" (học sinh).
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/sheet.php';
require_login();

send_xlsx([
    'Cau hoi' => [
        ['STT', 'Noi dung cau hoi', 'So lua chon (2-10)', 'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'Dap an dung (A-J, de trong neu khao sat)', 'Thoi gian (giay, de trong = khong gioi han)', 'Chu de', 'Giai thich'],
        [1, 'Giao thức nào dùng mô hình publish/subscribe?', 4, 'HTTP', 'MQTT', 'FTP', 'SMTP', '', '', '', '', '', '', 'B', 60, 'MQTT', 'MQTT: client publish lên topic, broker chuyển cho mọi client đã subscribe topic đó.'],
        [2, 'BLE hoạt động ở băng tần nào?', 3, '868 MHz', '2,4 GHz', '5 GHz', '', '', '', '', '', '', '', 'B', 45, 'BLE', 'BLE dùng băng ISM 2,4 GHz, 40 kênh 2 MHz; kênh quảng bá là 37, 38, 39.'],
        [3, 'Bạn thấy tốc độ bài giảng hôm nay thế nào?', 3, 'Chậm', 'Vừa', 'Nhanh', '', '', '', '', '', '', '', '', '', '', ''],
    ],
    'Danh sach lop' => [
        ['So thu tu (ID tren clicker, 1-99)', 'MSSV', 'Ho ten', 'SDT phu huynh'],
        [1, '23134001', 'Nguyễn Văn An', '0912345678'],
        [2, '23134002', 'Trần Thị Bình', '0987654321'],
        [3, '23134003', 'Lê Minh Châu', ''],
    ],
], 'ARS_mau_nhap_lieu.xlsx');
