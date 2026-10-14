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

| Ai | **Ở nhà / mọi mạng (Internet)** | Trong lớp, cùng Wi-Fi với máy chủ |
|---|---|---|
| Giáo viên | **https://ngoc-legion-5-15arp8.tail4be8bf.ts.net/gv** | `http://ars.local/gv` hoặc `http://<IP máy chủ>/gv` |
| Học sinh | **https://ngoc-legion-5-15arp8.tail4be8bf.ts.net/hs** | `http://ars.local/hs` — giáo viên chiếu mã QR trong trang lớp để quét |
| Phụ huynh | **https://ngoc-legion-5-15arp8.tail4be8bf.ts.net/ars/parent/** | `http://ars.local/ars/parent/` — hoặc bấm "Phụ huynh" ở trang đăng nhập học sinh |

Link Internet là của **máy chủ hiện tại (laptop giáo viên)**, bật bằng Tailscale Funnel (`sudo ars public on`): dùng được bằng
Wi-Fi hay 4G bất kỳ, **khi laptop đang bật và có mạng**. Khi chuyển sang máy chủ Cloud 24/24
([HUONG_DAN_CLOUD.md](../HUONG_DAN_CLOUD.md)), giáo viên sẽ gửi link mới dạng `https://ars-cloud.….ts.net/hs`.
Lệnh `ars links` trên máy chủ luôn in đúng các link đang dùng.

Mọi trang có nút **VI / EN** để đổi tiếng Việt / tiếng Anh.
