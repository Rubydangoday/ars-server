# Hướng dẫn sử dụng ARS

ARS (Audience Response System) là hệ thống hỏi–đáp trên lớp bằng **Remote nRF52840**. Học sinh bấm Remote, **gateway nRF52840 cắm USB vào laptop giáo viên** thu đáp án, kết quả hiện ngay trên web. Ngoài giờ học, ARS là một trang học tập: bài tập về nhà, bài giảng, ôn câu sai, trợ lý AI, thi đua, và trang theo dõi cho phụ huynh.

Chọn tài liệu theo vai trò của bạn:

| Bạn là | Đọc | Nội dung chính |
|---|---|---|
| 🧑‍🏫 **Giáo viên** | [giao-vien.md](giao-vien.md) | Tạo lớp, soạn câu hỏi, dạy bằng Remote, giao bài tập, xem kết quả, mời phụ huynh, cài đặt AI |
| 🎒 **Học sinh** | [hoc-sinh.md](hoc-sinh.md) | Dùng Remote trên lớp, đăng nhập web, vào lớp, làm bài tập, ôn tập, hỏi trợ lý AI |
| 👪 **Phụ huynh** | [phu-huynh.md](phu-huynh.md) | Tạo tài khoản bằng mã mời, xem điểm, chuyên cần, bài tập, nhận xét của thầy cô |
| 💻 **Lập trình viên** (người tiếp quản code) | [lap-trinh-vien.md](lap-trinh-vien.md) | Kiến trúc, thư mục, luồng dữ liệu, giao thức Bluetooth, cách thêm tính năng, test, triển khai |

Tài liệu kỹ thuật khác trong repo:
- [HUONG_DAN_2NRF.md](../HUONG_DAN_2NRF.md): cài, nạp firmware, chạy mỗi buổi, xử lý sự cố (Remote + gateway USB).
- [README.md](../README.md): kiến trúc, cây thư mục, giao thức Remote ⇄ gateway ⇄ server.
- [HUONG_DAN_CAI_DAT.md](../HUONG_DAN_CAI_DAT.md): cài đặt máy chủ trên Ubuntu/Windows, link cố định, lệnh `ars`.
- [tests/README.md](../tests/README.md): cách chạy kiểm thử.

## Ba đường dẫn cần nhớ

| Ai | Địa chỉ trong mạng trường | Ghi chú |
|---|---|---|
| Giáo viên | `http://ars.local/gv` | Hoặc `http://<IP máy chủ>/gv` |
| Học sinh | `http://ars.local/hs` | Giáo viên chiếu mã QR trong trang lớp để học sinh quét |
| Phụ huynh | `http://ars.local/ars/parent/` | Hoặc bấm "Phụ huynh" ở trang đăng nhập học sinh |

Nếu nhà trường đã bật link Internet (`sudo ars public on`), thay `http://ars.local` bằng link `https://…ts.net` mà giáo viên được cung cấp. Link này dùng được ở nhà.

Mọi trang có nút **VI / EN** để đổi tiếng Việt / tiếng Anh.
