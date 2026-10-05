# ARS — Hướng dẫn cài đặt và chạy (Ubuntu 22.04 / 24.04)

**Bản cập nhật v10:** xem [UPGRADE_V10.md](UPGRADE_V10.md) cho migration 005, LMS, kho đề và cổng phụ huynh. Giữ config.ini và sao lưu CSDL/uploads trước nâng cấp.


File `ars-server.zip` đã gồm **toàn bộ mã mới nhất** (web giáo viên, cổng học sinh, buổi học, trợ lý AI, lệnh `ars`).
Không cần áp các file `.patch` nữa.

## 1. Cài lần đầu hoặc cập nhật (chạy một lần)

```bash
cd ~/Downloads
rm -rf ars-server                      # xoá thư mục cũ đã giải nén (dữ liệu nằm trong CSDL, không mất)
unzip ars-server.zip
cd ars-server
sudo bash deploy/install_ubuntu.sh
```

Script tự cài Apache + PHP, MariaDB, Mosquitto, Avahi, Python, php-curl, poppler-utils; chép mã vào `/opt/ars-server`;
tạo/cập nhật CSDL; tạo thư mục lưu bài giảng `/var/lib/ars/uploads`; bật dịch vụ `ars-ingest`, `ars-bridge`, `ars-mdns`;
mở MQTT cho clicker Wi-Fi (cổng 1884 và WebSocket `/mqtt`, có mật khẩu).
Chạy lại nhiều lần không mất dữ liệu, không đổi mật khẩu CSDL.

Nếu bước 1/7 báo lỗi kho Spotify (`NO_PUBKEY … repository.spotify.com`):
```bash
sudo mv /etc/apt/sources.list.d/spotify.list /etc/apt/sources.list.d/spotify.list.off
sudo bash deploy/install_ubuntu.sh
```

## 1b. Link cố định cho giáo viên và học sinh

Chỉ cần nhớ **/gv** (giáo viên) và **/hs** (học sinh). Nhiều giáo viên và cả lớp học sinh dùng cùng lúc.

| Dùng ở đâu | Giáo viên | Học sinh | Ghi chú |
|---|---|---|---|
| Ngay trên máy chủ | `http://localhost/gv` | — | |
| Cùng mạng Wi-Fi/LAN — tên cố định | `http://ars.local/gv` | `http://ars.local/hs` | Máy tính, iPhone, Android 12+. Đổi tên: `sudo ars lan-name lop-iot.local` |
| Cùng mạng — theo IP | `http://<IP>/gv` | `http://<IP>/hs` | Nên đặt *DHCP reservation* cho máy chủ trong router để IP không đổi |
| **Mọi nơi, qua Internet (https)** | `https://<tên-máy>.<tailnet>.ts.net/gv` | `…/hs` | Bật một lần bằng Tailscale Funnel (bên dưới) |

- `ars links` in toàn bộ link. Trên web: **Tổng quan** có ô link + mã QR; **Cài đặt → Đường dẫn** có đủ các link.
- Link vào thẳng một lớp: `…/hs/<MÃ-THAM-GIA>` (ở trang lớp, bấm **QR** để chiếu cho cả lớp quét).

**Bật link Internet cố định (miễn phí, không cần mở cổng router):**
```bash
curl -fsSL https://tailscale.com/install.sh | sh     # bỏ qua nếu đã có Tailscale
sudo tailscale up                                     # đăng nhập (một lần)
sudo ars public on                                    # lần đầu: bấm link Tailscale in ra để cho phép HTTPS + Funnel
```
Lệnh in ra link dạng `https://ars-server.tail1234.ts.net/gv` — link giữ nguyên dù đổi mạng Wi-Fi. Tắt: `sudo ars public off`.
Tên máy trong link đổi được ở trang quản trị Tailscale (Machines → Edit machine name).

## 1c. Nhiều giáo viên

- Tài khoản tạo đầu tiên là **quản trị viên**. Menu **Giáo viên** → chép **mã mời** hoặc **link đăng ký** gửi cho giáo viên khác.
- Giáo viên mới: `…/gv` → **Tạo tài khoản giáo viên mới** → nhập mã mời. Mỗi giáo viên có lớp, câu hỏi, bài giảng, kết quả riêng.
- Quản trị viên: khoá/mở khoá, cấp quyền quản trị, đặt lại mật khẩu (mật khẩu tạm), đổi chế độ đăng ký (cần mã / mở / đóng).
- Trợ lý AI, trợ lý học sinh, tên trường do quản trị viên cài; giáo viên khác chỉ sửa tài khoản của mình.
- **Mã lớp (room) không trùng giữa các giáo viên** (web tự gợi ý số trống) để clicker không gửi nhầm lớp.

## 1d. Kết nối clicker: Bluetooth hoặc Wi-Fi

Chọn cho từng lớp ở **trang lớp** (hoặc khi bấm **Bắt đầu**), đổi nhanh trên **bảng điều khiển** (nút 🔵 Bluetooth / 📶 Wi-Fi).
Server chỉ nhận gói đúng kiểu lớp đang chọn; gói sai kiểu hiện ở bảng “Gói bị từ chối” với lý do *Sai kiểu kết nối*.

- **🔵 Bluetooth** (clicker nRF52840): cần gateway USB. Hai cách cắm:
  1. Cắm **máy chủ** → dịch vụ `ars-bridge` (room trong `config.ini`, mục `[gateway]`).
  2. Cắm **máy của giáo viên** (phòng khác, không cài gì): bảng điều khiển → **🔌 Gateway USB máy này** → **Kết nối gateway** → chọn cổng.
     Cần Chrome/Edge và trang mở bằng link **https** (link Internet) hoặc `localhost`. Giữ tab mở trong buổi học.
- **📶 Wi-Fi** (clicker ESP32): clicker gửi thẳng lên MQTT của máy chủ, không cần gateway. Thông số ở **Cài đặt → Kết nối clicker**:
  - Trong LAN: `mqtt://ars.local:1884`; qua Internet: `wss://<link-công-khai>/mqtt`; tài khoản `clicker` (mật khẩu trong `config.ini`, mục `[mqtt_public]`).
  - Gửi: `ars/<room>/resp` — JSON như gateway (`mac, room, type, id, q, seq, ans, batt, rssi`) + `"via":"wifi"`, QoS 1.
  - Nhận: `ars/<room>/state` (giữ lại): `qid, open, answered[], attend[], acks, mode`.
- Thử không cần phần cứng: `ars sim 30 1 wifi` (30 clicker Wi-Fi, room 1) hoặc `ars sim 30 1 ble`.

## 2. Mỗi lần dùng

Các dịch vụ tự chạy khi bật máy. Chỉ cần:

| Việc | Terminal | Trình duyệt |
|---|---|---|
| Kiểm tra | `ars status` (5 dòng `active`) | — |
| Giáo viên | `ars links` | `http://ars.local/gv` (hoặc link Internet) |
| Học sinh | — | `http://ars.local/hs` · quét QR ở trang Tổng quan |
| Chạy thử không cần mạch | `ars sim 30` | Mở bảng điều khiển, bấm **Mở** câu |
| Có gateway thật | `ars ports` → nếu không phải `/dev/ttyACM0` thì sửa `/opt/ars-server/config.ini` rồi `sudo systemctl restart ars-bridge` | — |

## 3. Lệnh tiện ích

| Lệnh | Tác dụng |
|---|---|
| `ars links` | in các link cố định (LAN, IP, Internet) |
| `sudo ars public on` / `off` | bật/tắt link Internet cố định (Tailscale Funnel) |
| `ars status` | trạng thái 6 dịch vụ |
| `ars logs` | gói tin vào / bị loại (Ctrl+C thoát) |
| `ars watch` | xem CSDL trực tiếp: buổi đang chạy, đáp án, điểm danh, gói bị loại, bài học sinh làm |
| `ars db` | mở MariaDB (gõ SQL, `exit` để thoát) |
| `ars sql "SELECT * FROM students"` | chạy nhanh một câu SQL |
| `ars tables` | liệt kê bảng và số dòng |
| `ars sim 30 1 wifi` | 30 clicker giả lập (room 1, Wi-Fi; bỏ `wifi` = Bluetooth) |
| `ars backup` | sao lưu CSDL ra file .sql |
| `sudo systemctl restart ars-ingest ars-bridge` | khởi động lại |

## 4. Thử nhanh trên web (dùng file trong thư mục `mau/`)

1. `http://localhost/ars/` → tạo tài khoản giáo viên (lần đầu) → **Tổng quan**.
2. **Lớp học** → Tạo lớp (mã lớp room = 1) → mở lớp → nhập `mau/ARS_du_lieu_thu.xlsx` (sheet *Danh sach lop*).
3. **Câu hỏi → Soạn câu hỏi**:
   - bên trái tải `mau/ARS_de_mau.docx` hoặc `mau/ARS_du_lieu_thu.xlsx` → tự tách câu;
   - bên phải nhập yêu cầu cho trợ lý AI;
   - chọn bộ đích ở thanh trên (có "＋ Tạo bộ mới"), bấm **✓ Thêm vào bài kiểm tra / bài ôn tập** dưới từng câu.
4. **Buổi học** → Buổi 1 → tải `mau/Bai_giang_MQTT_BLE.pdf` → **✨ Tóm tắt bằng AI** → Lưu →
   **✨ Soạn bằng AI từ bài giảng** → thêm câu → quay lại tick **Công bố cho học sinh**.
5. Vào lớp → **Bắt đầu** buổi → terminal khác chạy `ars sim 30` → bấm **Mở** câu 1.
6. Học sinh: `http://localhost/ars/student/` → MSSV `23134001`, mật khẩu lần đầu `23134001` → tab **Bài giảng**.

## 5. Học sinh đăng ký tài khoản

- Cổng học sinh → **✨ Tạo tài khoản mới**: nhập MSSV, họ tên, mật khẩu.
- Học sinh **đã có trong danh sách lớp**: không cần mã lớp.
- Học sinh **mới**: nhập **mã tham gia lớp** (6 ký tự, xem ở trang lớp của giáo viên, mục *Mã tham gia lớp*). Hệ thống tự thêm vào lớp và cấp số thứ tự trống tiếp theo làm ID clicker.
- Quên mật khẩu / muốn tạo lại: giáo viên bấm **đặt lại MK** ở dòng học sinh → học sinh đăng ký lại (hoặc đăng nhập bằng mật khẩu = MSSV).
- Giáo viên có thể **Khoá đăng ký** hoặc **Đổi mã** bất cứ lúc nào.

## 6. Trợ lý học tập cho học sinh (tab 🦉 Trợ lý AI)

Hỏi đáp · Giải thích chi tiết phần còn yếu · Câu hỏi ôn tập (chấm ngay, cộng ⭐) · Bài tập nâng cao · Tóm tắt bài học · Lộ trình ôn tập 7 ngày.
Trợ lý dùng bài giảng và câu hỏi của lớp, biết học sinh đang yếu phần nào; tự khoá khi học sinh đang làm bài kiểm tra.
Giáo viên bật/tắt và đặt số lượt hỏi mỗi ngày ở **Cài đặt → Trợ lý học sinh**.

## 7. Trợ lý AI (Cài đặt → Trợ lý AI)

- **Cơ bản**: không cần Internet; tạo câu từ các câu định nghĩa trong bài giảng.
- **Claude**: dán API key (console.anthropic.com) → Lưu → **Kiểm tra kết nối**.
- **Ollama chạy trên laptop** (không cần Internet sau khi tải model):
  ```bash
  curl -fsSL https://ollama.com/install.sh | sh
  ollama pull qwen2.5:7b
  ```
  Chọn *OpenAI / Ollama / LM Studio*, địa chỉ `http://localhost:11434/v1`, model `qwen2.5:7b`.

## 8. Giáo viên quên mật khẩu

- **Ngồi tại laptop chạy server**: mở `http://localhost/ars/` → **Quên mật khẩu?** → chọn tài khoản, đặt mật khẩu mới (không cần mã).
- **Từ máy khác**: cần **mã khôi phục** (hiện khi tạo tài khoản lần đầu; tạo lại ở *Cài đặt → Tài khoản*).
- **Trên terminal**: `ars gv` (xem tên đăng nhập) · `ars reset-gv ten_dang_nhap` (đặt mật khẩu mới).

## 9. Lịch sử luyện tập của học sinh

Tab **Lịch sử → Luyện tập của học sinh**: mọi lượt học sinh làm trên web (bài giao, ôn câu sai, ôn chủ đề, câu hỏi củng cố),
số câu đúng/sai, bấm **Chi tiết** để xem từng câu học sinh chọn gì; lọc theo lớp, học sinh, thời gian; **Xuất Excel**.
Ở trang lớp, mỗi học sinh có liên kết **Luyện tập** để xem nhanh.

## 10. Khi gặp lỗi

```bash
ars status
ars logs
sudo tail -30 /var/log/apache2/error.log
```
Gửi phần in ra để được hỗ trợ.
