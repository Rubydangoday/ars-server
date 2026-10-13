# Chạy hệ thống ARS với 2 nRF52840 (Remote + gateway USB)

```
Remote nRF52840 ──BLE──▶ Gateway nRF52840 ──USB──▶ ars-bridge ──MQTT──▶ ars-ingest ──▶ MariaDB ──▶ Web
 (học sinh)      ◀─ACK─── (SuperMini cắm laptop)
```

| Thành phần | Phiên bản cần khớp |
|---|---|
| Remote | firmware `firmware/ARS_remote_v3_10/` — Serial in `ARS Remote bidirectional V3.11` |
| Gateway | firmware `firmware/ARS_gateway_v3_10/` — `ars gw` in `firmware ARS-GW 3.11` |
| Server | repo này, cài bằng `deploy/install_ubuntu.sh` |

Ba phần phải cùng đợt cập nhật: Remote V3.11 chỉ báo điểm danh khi thấy kết quả LOGIN chính xác mà server cũ không gửi.

---

## A. Lần đầu (làm một lần)

### A1. Cài server (Ubuntu 22.04 / 24.04)
```bash
cd ~/Downloads/ars-server
sudo bash deploy/install_ubuntu.sh
```
Bộ cài: Apache + PHP, MariaDB, Mosquitto, Python; bật `ars-ingest`, **`ars-bridge`** (gateway), `ars-mdns`; đặt `[gateway] serial = auto` (tự tìm cổng gateway); cài quy tắc udev để ModemManager không chiếm cổng SuperMini.

### A2. Arduino IDE (máy dùng để nạp firmware)
1. File → Preferences → *Additional boards manager URLs*: `https://adafruit.github.io/arduino-board-index/package_adafruit_index.json`
2. Tools → Board → Boards Manager → **Adafruit nRF52** bản **1.7.0**.
3. Ubuntu: `pip3 install --user adafruit-nrfutil` (thiếu → lỗi `adafruit-nrfutil: executable file not found`), `sudo usermod -aG dialout $USER` rồi đăng xuất/đăng nhập.
4. Board: **Adafruit Feather nRF52840 Express** (firmware dùng chân vật lý P0.xx/P1.xx nên đúng với SuperMini).
5. Kiểm tra bootloader (một lần mỗi board): chạm RST–GND 2 lần nhanh → ổ `NICENANO` → `INFO_UF2.TXT` phải ghi SoftDevice **S140 6.1.1**.

### A3. Nạp firmware (mỗi lần chỉ cắm 1 board)
| | Gateway | Remote |
|---|---|---|
| Trước khi nạp | `sudo systemctl stop ars-bridge` (bridge giữ / hỏi cổng SuperMini) | nên `sudo systemctl stop ars-bridge` như gateway |
| Mở | `firmware/ARS_gateway_v3_10/ARS_gateway_v3_10.ino` | `firmware/ARS_remote_v3_10/ARS_remote_v3_10.ino` |
| Upload, rồi Serial Monitor 115200 | `# ARS-GW 3.11`, `# ready…` | `ARS Remote bidirectional V3.11` |
| Sau đó | đóng Serial Monitor, `sudo systemctl start ars-bridge` | đóng Serial Monitor (hoặc giữ để xem log) |

Upload báo lỗi cổng → chạm RST–GND 2 lần (vào bootloader), chọn lại cổng, Upload lại.

Nạp kiểu kéo thả (UF2), khi Upload vẫn lỗi: Sketch → **Export Compiled Binary** (tạo `build/…/*.ino.hex` trong thư mục sketch), rồi
```bash
python3 ~/.arduino15/packages/adafruit/hardware/nrf52/1.7.0/tools/uf2conv/uf2conv.py -c -f 0xADA52840 \
    -o gateway.uf2 firmware/ARS_gateway_v3_10/build/*/ARS_gateway_v3_10.ino.hex
```
chạm RST–GND 2 lần → chép `gateway.uf2` vào ổ `NICENANO` (board tự khởi động lại khi chép xong).

### A4. Tạo lớp trên web
`http://localhost/gv` → tạo tài khoản → **Lớp học** → tạo lớp với **mã lớp (room)** = `[gateway] room` trong `/opt/ars-server/config.ini` (mặc định 1) → thêm học sinh (số ghế 1–99) → bộ câu hỏi.

### A5. Đặt ghế cho Remote
Remote mới hiện `Gh` ↔ `--` (đèn xanh nháy chậm = đang nhập ghế): gõ số ghế → **OK** (Remote LOGIN ngay). `Ph` + đèn đỏ nháy = đang nhập phòng. Chữ cái nhấp nháy = đã chọn đáp án, chưa gửi. Bảng đầy đủ: `firmware/ARS_remote_v3_10/README.md`. Room khác 1: MODE hai lần → gõ room → OK → gõ ghế → OK. Ghế/room lưu trong flash.

---

## B. Mỗi buổi học (chạy lại)

1. Bật laptop, **cắm gateway** vào USB (đặt ở chỗ thoáng, giữa lớp; cáp nối dài nếu cần).
2. Kiểm tra:
   ```bash
   ars status      # apache2 mariadb mosquitto ars-ingest ars-bridge: active  (ars-ble: inactive là đúng)
   ars gw          # online: CÓ, firmware ARS-GW 3.11, lỗi: không
   ```
   Đèn xanh dương trên gateway sáng khi server đã gửi trạng thái.
3. Web giáo viên → lớp → **Bắt đầu** buổi học → bảng điều khiển hiện **Gateway: đang nhận**.
4. Học sinh bật Remote (tự LOGIN lại với ghế đã lưu) → đèn xanh = đã điểm danh; ô ghế trên web chuyển màu.
5. Giáo viên **Mở** câu → học sinh chọn chữ, **OK** → đèn xanh = server đã nhận đúng đáp án đó; đỏ = bị từ chối (Serial Remote in lý do).
6. Hết buổi: **Kết thúc buổi**. Rút gateway được (bridge tự chờ cắm lại).

Laptop khởi động lại: các dịch vụ tự chạy, chỉ cần làm lại bước 1–3.

---

## C. Cập nhật khi có code mới

```bash
cd ~/Downloads/ars-server
git pull                                   # hoặc giải nén bản mới
sudo bash deploy/install_ubuntu.sh         # chạy migration, chép code, khởi động lại dịch vụ; giữ config.ini và dữ liệu
```
Chỉ đổi code (không có migration mới):
```bash
sudo rsync -a --delete --exclude config.ini --exclude .gateway-ble --exclude .gateway-gatt --exclude .git --exclude '__pycache__' ./ /opt/ars-server/
sudo systemctl restart apache2 ars-ingest ars-bridge
```
Có thay đổi trong `firmware/` → nạp lại gateway và/hoặc Remote (mục A3).

---

## D. Đo tốc độ và độ chính xác (1 Remote)

Chi tiết: `firmware/TEST_V3_10.md` mục 1b và 3. Tóm tắt:
```bash
# Terminal 1 — log server
journalctl -u ars-ingest -u ars-bridge -f --since now | tee server.log
# Terminal 2 — Serial Monitor của Remote, lưu thành serial.log; bấm 50 lần A/B/C/D + OK (chờ đèn rồi bấm tiếp)
python3 tools/ble_txn_report.py serial.log server.log --seat <ghế>
```
Đạt: server nhận ≥ 99 %, có kết quả ≥ 99 %, ACK lần đầu ≥ 95 %, độ trễ P95 < 300 ms, 0 đèn xanh nhầm.
**Remote chạy pin (không cắm máy tính)** — chỉ cần log server, tự đếm số lần bấm OK:
```bash
journalctl -u ars-ingest -u ars-bridge -f --since now | tee server.log      # bấm 50 lần, rồi Ctrl+C
python3 tools/ble_txn_report.py --server-only server.log --presses 50 --seat <ghế>
```
Cho biết: server nhận bao nhiêu / 50, bao nhiêu lần Remote phải gửi lại (≈ không nghe được ACK lần đầu), NACK và lý do. Không đo được độ trễ — muốn số `dt` thì cắm Remote vào USB (cổng thứ 2 hoặc hub) cho một lượt đo.

Đo riêng gói lên: Remote Serial gõ `u` (100 gói PROBE) → `python3 tools/ble_txn_report.py serial.log server.log --probe` (≥ 99 %).

---

## E. Xử lý sự cố

| Hiện tượng | Kiểm tra | Cách sửa |
|---|---|---|
| Web: **Gateway: chưa kết nối**; `ars gw`: online KHÔNG | `journalctl -u ars-bridge -n 30` | "chưa thấy gateway" → cắm lại USB; đóng Serial Monitor / Arduino IDE đang giữ cổng; `ars ports` phải có dòng `← gateway ARS-GW 3.11` (khi ars-bridge đang dừng) |
| `ars ports` không đánh dấu cổng nào | Serial Monitor 115200 vào cổng gateway, gõ `v` | Không trả lời `# ARS-GW` → nạp lại firmware gateway (A3) |
| Log: "gateway im lặng 15 s" lặp lại | đèn gateway | Gateway treo / cáp lỏng: rút–cắm lại; đổi cáp/cổng USB |
| Đèn gateway tắt dù bridge chạy | `ars gw`: `cmd=0` | Server chưa gửi trạng thái: có thể chưa có buổi học nào chạy cho room này, hoặc `[gateway] room` ≠ mã lớp → sửa config.ini, `sudo systemctl restart ars-bridge` |
| Remote đỏ, Serial `ACK TIMEOUT`, server không có dòng NHẬN/LOẠI seq đó | `ars gw`: `rx` có tăng khi bấm? | `rx` không tăng → Remote khác room (MODE kiểm tra) hoặc quá xa / bị che; đo `--probe` |
| Remote đỏ `NACK … reason=…` | lý do trên Serial Remote | `NOT_LOGGED_IN`: MODE → OK để LOGIN lại · `QUESTION_NOT_OPEN`/`QUESTION_CLOSED`: GV chưa mở / đã đóng câu · `SEAT_TAKEN`: GV bấm ô ghế để giải phóng · `NO_SESSION`: chưa bấm Bắt đầu |
| Remote báo `LOCAL REJECT` | — | Chọn chữ vượt số lựa chọn của câu (ví dụ E khi câu có A–D) — đúng thiết kế |
| Serial Remote `ADV START FAIL` | — | Nạp lại firmware Remote; còn lỗi → báo kèm log |
| Arduino IDE nạp lỗi giữa chừng (`Timed out waiting for acknowledgement`, `No data received on serial port`) | `systemctl is-active ars-bridge` | `sudo systemctl stop ars-bridge` rồi nạp lại (bridge cũ hỏi cổng mỗi 2 s, chen vào lúc nạp; bản mới bỏ qua board đang ở bootloader). Vẫn lỗi: RST–GND 2 lần rồi nạp, hoặc nạp UF2 (mục A3) |
| Web không vào được | `ars status` | `sudo systemctl restart apache2`; xem `ars links` |

Tạm thời không có gateway: `sudo ars gateway ble` (laptop thu bằng Bluetooth của nó — chậm và kém ổn định hơn), quay lại: `sudo ars gateway usb`.
