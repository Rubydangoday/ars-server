# ARS server — web cho giáo viên

**Bản cập nhật v10:** xem [UPGRADE_V10.md](UPGRADE_V10.md) cho migration 005, LMS, kho đề và cổng phụ huynh. Giữ config.ini và sao lưu CSDL/uploads trước nâng cấp.


Phần mềm chạy trên laptop giảng viên. Clicker và gateway chỉ truyền/nhận; mọi kiểm tra hợp lệ, lưu trữ, thống kê đều nằm ở đây.

```
Clicker ─BLE─▶ Gateway ─USB (JSON)─▶ bridge.py ─MQTT─▶ ingest.py ─▶ MariaDB ◀─ web PHP ◀─ trình duyệt GV / máy chiếu
                  ▲                       │                 │
                  └──── STATE/ATTEND ◀────┴──── MQTT ◀──────┘
```

| Thư mục | Nội dung |
|---|---|
| `db/schema.sql` | Tạo CSDL `ars` (bảng, VIEW `final_answers`) |
| `ingest/ingest.py` | Kiểm tra gói, ghi DB, soạn STATE/ATTEND cho gateway, tự đóng câu hết giờ |
| `bridge/bridge.py` | Nối cổng USB của gateway với MQTT (không có logic) |
| `web/` | Web PHP cho giáo viên: lớp, câu hỏi, điều khiển buổi, màn chiếu, kết quả, thiết bị, giao ôn tập |
| `web/student/` | Cổng học sinh: tổng quan, câu sai, câu đúng, ôn tập, rút kinh nghiệm |
| `db/migrate_002_hocsinh.sql` | Bổ sung CSDL cho cổng học sinh (chạy sau `schema.sql`) |
| `db/migrate_003_baigiang_ai.sql` | Buổi học/bài giảng, loại bộ câu hỏi, cài đặt, nhật ký AI |
| `tools/sim_clickers.py` | Giả lập gateway + N clicker để chạy thử khi chưa có phần cứng |
| `common/arscfg.py` | Đọc `config.ini`, mã hoá bitmap |

## Cài đặt trên Ubuntu 22.04 / 24.04 (một lệnh)

```
cd ars-server
sudo bash deploy/install_ubuntu.sh
```
Script cài Apache + PHP, MariaDB, Mosquitto, thư viện Python; chép mã vào `/opt/ars-server`; tạo CSDL với mật khẩu ngẫu nhiên
(ghi trong `/opt/ars-server/config.ini`); bật dịch vụ `ars-ingest` và `ars-bridge`; mở cổng 80 nếu ufw đang bật.
Chạy lại script để cập nhật bản mới — dữ liệu và config.ini được giữ nguyên.

- Link cố định: giáo viên `http://ars.local/gv`, học sinh `http://ars.local/hs` (LAN); `sudo ars public on` để có link https dùng mọi nơi (Tailscale Funnel). Nhiều giáo viên dùng chung server (mã mời, menu **Giáo viên**).
- Mỗi lớp chọn kết nối clicker **Bluetooth** (gateway USB ở máy chủ hoặc máy GV qua Chrome) hoặc **Wi-Fi** (ESP32 gửi thẳng MQTT, cổng 1884 / WebSocket `/mqtt`). Chi tiết: HUONG_DAN_CAI_DAT.md mục 1b–1d.
- `ars status` · `ars logs` (xem gói vào, gói bị loại) · `ars watch` (xem CSDL trực tiếp) · `ars db` (mở MariaDB) · `ars sim 30` (30 clicker giả lập) · `ars ports` (tìm cổng gateway) · `ars mqtt` · `ars backup`
- Gateway cắm USB thường là `/dev/ttyACM0`; khác thì sửa `[gateway] serial` rồi `sudo systemctl restart ars-bridge`.

## Cài đặt trên Windows (laptop GV)

1. **XAMPP** (Apache + PHP 8 + MariaDB): cài xong mở XAMPP Control Panel, Start *Apache* và *MySQL*.
   Trong `php.ini` bật `extension=zip` (thường đã bật sẵn).
2. **Mosquitto**: cài bản Windows từ mosquitto.org, chạy service *Mosquitto Broker* (cổng 1883).
3. **Python 3.10+**: `pip install paho-mqtt pymysql pyserial`
4. Tạo CSDL (trong thư mục này):
   ```
   C:\xampp\mysql\bin\mysql -u root < db\schema.sql
   C:\xampp\mysql\bin\mysql -u root ars < db\migrate_002_hocsinh.sql
   C:\xampp\mysql\bin\mysql -u root ars < db\migrate_003_baigiang_ai.sql
   C:\xampp\mysql\bin\mysql -u root ars < db\migrate_004_link_nhieu_gv_ketnoi.sql
   C:\xampp\mysql\bin\mysql -u root ars < db\migrate_005_lms.sql
   C:\xampp\mysql\bin\mysql -u root -e "CREATE USER 'ars'@'localhost' IDENTIFIED BY 'matkhau'; GRANT ALL ON ars.* TO 'ars'@'localhost';"
   ```
5. Chép `config.ini.example` thành `config.ini`, sửa mật khẩu DB, cổng COM của gateway và `room`.
6. Web: chép cả thư mục `ars-server` vào `C:\xampp\htdocs\` rồi mở `http://localhost/ars-server/web/`
   (hoặc không cần Apache: `php -S 0.0.0.0:8080 -t web` rồi mở `http://localhost:8080`).
   Lần đầu mở web sẽ yêu cầu tạo tài khoản giáo viên.
7. Mỗi buổi học mở 2 cửa sổ lệnh:
   ```
   python ingest\ingest.py
   python bridge\bridge.py          (python bridge\bridge.py --list để xem cổng COM)
   ```

Linux/macOS giống hệt, dùng `apt install mariadb-server mosquitto php-cli php-mysql php-zip` hoặc Homebrew.

## Dùng trong lớp

1. **Lớp học → Tạo lớp**, đặt *mã lớp (room)* trùng `room` trong `config.ini`.
2. Nhập danh sách: **Tải file mẫu**, điền sheet *Danh sach lop* (số thứ tự 1–99 = ID học sinh gõ trên clicker), **Nhập từ Excel/CSV** → xem trước (dòng lỗi tô đỏ) → **Xác nhận**.
3. **Câu hỏi → Tạo bộ**, soạn trên web hoặc nhập sheet *Cau hoi* (2–10 lựa chọn, đáp án đúng, thời gian).
4. Vào lớp → **Bắt đầu** buổi → bảng điều khiển. Học sinh bấm MODE, gõ số, OK để điểm danh (ô chuyển đỏ nhạt).
5. **Mở** câu → học sinh chọn chữ, bấm OK → ô chuyển xanh (đèn xanh trên clicker bật cùng lúc).
   **Mở màn chiếu** trên máy chiếu; biểu đồ chỉ hiện khi bấm **Hiện kết quả trên màn chiếu**.
6. **Kết quả**: độ khó p, độ phân biệt D, phương án nhiễu, thời gian trả lời, bảng điểm từng học sinh, **Xuất Excel**.

## Buổi học, soạn câu hỏi và trợ lý AI

- **Tổng quan** (trang đầu sau khi đăng nhập): phiên đang chạy, số liệu 30 ngày, việc cần làm.
- **Buổi học**: mỗi lớp có sẵn 10 buổi (thêm được). Mỗi buổi gồm file bài giảng (PDF/PPTX/DOCX, tối đa 50 MB, PDF xem ngay trên web), **kiến thức trọng tâm** (AI tóm tắt được từ bài giảng) và **câu hỏi củng cố**. Bấm *Công bố* thì học sinh thấy ở tab *Bài giảng* và làm câu hỏi củng cố trên điện thoại. File lưu ở `/var/lib/ars/uploads`.
- **Câu hỏi → Soạn câu hỏi**: bên trái gõ/dán văn bản hoặc tải file Word/Excel, hệ thống tự tách câu (nhận `Câu 1:`/`1.`, phương án `A.`…, đáp án đánh dấu `*`, gạch chân/tô đỏ trong Word, dòng `Đáp án: B` hoặc bảng đáp án cuối đề). Bên phải là trợ lý AI: nhập yêu cầu, chọn số câu, mức độ, nguồn (bài giảng của một buổi). Mỗi câu có nút **✓ Thêm vào bài kiểm tra / bài ôn tập** — chỉ câu được xác nhận mới lưu.
- Bộ câu hỏi có hai loại: **Kiểm tra** (chiếu lên lớp, clicker, tối đa 64 câu) và **Ôn tập** (giao cho học sinh trên web).
- **Cài đặt → Trợ lý AI**: *Cơ bản* (không cần Internet, tạo câu từ câu định nghĩa trong bài giảng), *Claude* (API key Anthropic) hoặc *OpenAI / Ollama / LM Studio* (Ollama chạy AI ngay trên laptop: `ollama pull qwen2.5:7b`, địa chỉ `http://localhost:11434/v1`).

## Cổng học sinh (`/student/`)

Giao diện nhiều màu, dùng tốt trên điện thoại (hợp học sinh tiểu học đến THPT). Có ⭐ điểm thưởng, 🔥 chuỗi ngày học, cấp độ.
**Đăng ký**: học sinh tự tạo tài khoản bằng MSSV; học sinh mới nhập *mã tham gia lớp* (trang lớp của GV) để vào lớp.
**🦉 Trợ lý AI (Cú Học)**: hỏi đáp, giải thích chi tiết phần yếu, câu hỏi ôn tập chấm ngay, bài nâng cao, tóm tắt, lộ trình ôn tập — dùng bài giảng và ngân hàng câu hỏi của lớp.

Học sinh mở `http://<IP laptop GV>/ars-server/web/student/` bằng điện thoại (cùng mạng Wi-Fi), đăng nhập bằng **MSSV**; lần đầu mật khẩu là chính MSSV và bắt buộc đổi. Giáo viên đặt lại mật khẩu ở trang lớp.

| Tab | Nội dung | Làm theo |
|---|---|---|
| Tổng quan | tỉ lệ đúng, câu sai chưa thuộc, bài ôn tập cần làm, phần cần ôn kỹ, các buổi gần đây | — |
| Câu sai | từng câu: đáp án đã chọn, đáp án đúng, giải thích, % lớp làm đúng, mức đã ôn; ghi **vì sao sai** + ghi chú | xem lại bài của Moodle, lọc câu sai của Azota |
| Câu đúng | câu đúng, đánh dấu "câu khó" (dưới 50 % lớp đúng), "trả lời lâu", "trước đó sai" | — |
| Ôn tập | bài GV giao (ôn trước kiểm tra / bài tập), ôn câu sai, ôn theo chủ đề, làm lại một buổi, lịch sử | chế độ luyện tập của Azota |
| Rút kinh nghiệm | sau mỗi bài kiểm tra: sai ở phần nào, vì sao sai, bài học rút ra, kế hoạch ôn kỹ từng phần | sổ tay lỗi sai |

- **Luyện tập**: báo đúng/sai + giải thích ngay; câu sai được hỏi lại cuối lượt tới khi đúng (kiểu Learn của Quizlet). Câu đúng 2 lần liên tiếp tính là **đã thuộc**.
- **Như kiểm tra**: không báo đúng/sai, có thể giới hạn thời gian, chấm khi nộp.
- Học sinh chỉ xem lại được buổi **đã kết thúc**. Với **bài kiểm tra**, phải chờ GV bấm *Công bố cho học sinh xem lại* ở trang kết quả (giống tuỳ chọn review của Moodle).
- Ôn theo chủ đề chỉ lấy câu từ các bộ đã dùng ở buổi đã công bố hoặc bộ GV giao ôn tập, không lộ đề kiểm tra sắp tới.
- File Excel câu hỏi có thêm cột P *Chu de* và Q *Giai thich*.

## Chạy thử không cần phần cứng

```
python ingest\ingest.py
python tools\sim_clickers.py --room 1 -n 40            # 40 clicker, mất 20 % gói
python tools\sim_clickers.py --room 1 -n 200 --loss 0.3 --think 1 8   # thử tải cho báo cáo
```
Mở bảng điều khiển, bấm Mở câu: các ô chuyển xanh dần. Cuối chạy, bộ giả lập in tỉ lệ được xác nhận và độ trễ bấm OK → đèn xanh.
Bộ giả lập chọn ngẫu nhiên trong 4 chữ đầu nên câu có 3 lựa chọn sẽ có gói bị loại "Chữ vượt số lựa chọn" — đó là server kiểm tra đúng.

## Giao tiếp với firmware gateway (USB serial, 115200, mỗi dòng một bản tin)

**Gateway → máy tính**: mỗi gói BLE nghe được in thành 1 dòng JSON (không lọc, không loại trùng):
```json
{"mac":"C4:1A:00:11:22:33","room":1,"type":2,"id":7,"q":3,"seq":12,"ans":"B","batt":2950,"rssi":-61}
```
`type` 1 = LOGIN (ans rỗng, q = 0), 2 = ANSWER. `batt` tính bằng mV. Dòng không bắt đầu bằng `{` được coi là log và bỏ qua.

**Máy tính → gateway** (phát lại nguyên vào beacon):

| Lệnh | Ý nghĩa |
|---|---|
| `STATE <room> <qid> <open 0/1> <13 byte hex>` | câu đang chiếu và ai đã có đáp án câu đó |
| `ATTEND <13 byte hex>` | ai đã điểm danh |
| `OPENMASK <8 byte hex>` | câu nào đang mở (câu p ở bit p−1) — cho ◀ ▶ |
| `ACKS <ghế:câu,...>` hoặc `ACKS -` | 6 xác nhận gần nhất |

Bitmap: ghế n ở byte n÷8, bit n mod 8 (bit thấp trước). Ví dụ ghế 1–4 → `1E000000000000000000000000`.

## Kiểm tra hợp lệ ở server

Loại gói trùng theo (câu, MAC, seq) · lớp phải đang có buổi học · ID phải có trong danh sách · một ID một máy, một máy một ID trong buổi (GV "giải phóng ID" khi cần) · câu phải đang mở và chưa hết giờ · chữ phải nằm trong số lựa chọn · có cho đổi đáp án hay không. Gói bị loại vào bảng `rejected` kèm lý do, hiện ở bảng điều khiển và trang kết quả.
