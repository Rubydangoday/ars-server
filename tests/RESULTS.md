# Kết quả kiểm thử ARS v10

Ngày: 30/09/2026. Môi trường: PHP 8.3.6, MariaDB 10.11.14, Chromium headless, dữ liệu giả trên instance riêng.

| Nhóm | Kết quả |
|---|---|
| Migration schema + 002–005 | Cài mới thành công; migration 005 chạy lại thành công |
| Cú pháp PHP | 81 file, không lỗi |
| Cú pháp JavaScript ứng dụng | 9 file, không lỗi |
| HTTP giáo viên/học sinh/phụ huynh | 171 assertions qua |
| Ingest với MariaDB thật | 7 assertions qua |
| Upload, bài đã nộp, mã đề, AI giả lập | 57 assertions qua |
| Chromium | 5 kiểm tra qua |
| Hàm mã đề | 1.000 lần đảo giữ đúng nội dung đáp án và đủ phương án |
| Điểm và tiến độ | Chặn điểm âm/quá tối đa/không số/vô hạn; tổng hợp tuần loại buổi vắng khỏi trung bình điểm |

Các số assertions gồm cả kiểm tra HTTP/status và không có lỗi PHP, không phải số tính năng độc lập.

## Trường hợp quan trọng

- Giáo viên khác lớp bị chặn xem bài, chấm và đọc tệp học sinh. Kho trường không xuất hiện cho giáo viên ngoài trường.
- CSRF bắt buộc trên POST. Mã mời phụ huynh chỉ dùng một lần; quyền liên kết được giới hạn theo học sinh và lớp.
- Nộp bài khóa thay đổi, kiểm tra hạn nộp ở server. Không công bố nếu còn câu chưa chấm.
- Chấm AI bằng stub OpenAI-compatible localhost, không gửi dữ liệu ra cloud; điểm AI lưu riêng và chờ giáo viên xác nhận.
- PNG được lưu và đọc đúng bytes; PHP giả dạng PNG bị từ chối. Hình vẽ được giữ qua lưu nháp và nộp bài.
- Mã đề giữ media, đổi đáp án đúng theo vị trí phương án mới. Đề tham chiếu bởi homework không xóa được.
- Câu trắc nghiệm homework sai được đưa vào sổ câu sai sau công bố. Excel và Word tải về là ZIP Office hợp lệ.
- Ingest loại MAC không đúng ghép, câu đóng, câu hết giờ và gói lặp. Kiểm tra đúng/sai ghép ở cả điểm danh và đáp án.
- Máy chiếu giữ kín đáp án đúng khi bật biểu đồ lựa chọn trực tiếp.
- Chromium render KaTeX, vẽ và gắn ảnh canvas, lưu và tải lại. Màn học sinh 390px không tràn ngang; không có lỗi JavaScript pageerror.

## Chưa nghiệm thu

Remote/gateway BLE thật, đường truyền MQTT thực, microphone/âm thanh trên các điện thoại khác nhau, chất lượng chấm AI bằng model thực, tải đồng thời nhiều lớp và mẫu sổ điểm chính thức của trường. Test hiện tại không thay nghiệm thu triển khai.

Các screenshot dùng dữ liệu giả trong `tests/previews/`. Test scripts ở `tests/`, hướng dẫn chạy tại `tests/README.md`. Không đóng gói config thử nghiệm, mật khẩu thật hoặc output fixture.
