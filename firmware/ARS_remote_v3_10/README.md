# ARS Remote V3.11 (nRF52840 SuperMini, Rev J)

Thư mục giữ tên `ARS_remote_v3_10` để không đổi đường dẫn. V3.11 (cùng chân, phím, định dạng gói): vẫn quét trong lúc phát và dừng phát ngay khi nghe thấy kết quả; LOGIN có kết quả chính xác `(ghế, 0, seq, 0, status)`; Serial in độ trễ `dt=…ms`. Chi tiết: [../PROTOCOL_V3_10.md](../PROTOCOL_V3_10.md) mục V3.11.

Firmware Remote cho ARS. Giao thức: [../PROTOCOL_V3_10.md](../PROTOCOL_V3_10.md). Chân GPIO và bản đồ phím **giữ nguyên V3.9** (`board_pins.h`, `keypad.cpp`).

## Build / nạp

- Arduino IDE hoặc `arduino-cli`, core **Adafruit nRF52** (đã thử biên dịch với 1.7.0, Bluefruit52Lib 0.21.0) — dùng đúng board/bootloader đã nạp được V3.8/V3.9.
- Mở `ARS_remote_v3_10.ino` (cả thư mục), Upload.
- Dòng lệnh (ví dụ board Feather nRF52840; thay FQBN bằng board bạn đang dùng, cần `adafruit-nrfutil` để đóng gói DFU):
  ```bash
  arduino-cli compile --fqbn adafruit:nrf52:feather52840 firmware/ARS_remote_v3_10
  arduino-cli upload  --fqbn adafruit:nrf52:feather52840 -p /dev/ttyACM0 firmware/ARS_remote_v3_10
  ```

## Dùng

| Phím | Chế độ thường | Đang nhập số (màn hình `--` hoặc số đang gõ) |
|---|---|---|
| `1/A`…`9/I`, `0/J` | chọn đáp án | gõ chữ số |
| OK | gửi đáp án | lưu; nhập ghế xong → LOGIN |
| MODE | nhập **số ghế** | chuyển ghế ↔ room |
| LEFT / RIGHT | đổi câu | LEFT = huỷ |

**Dấu hiệu từng chế độ (V3.11, `display.cpp`, `status_led.cpp`):**

| Chế độ | Màn hình 2 số | Đèn |
|---|---|---|
| Bình thường | số câu đứng yên `03` | tắt |
| Nhập ghế (MODE) | `Gh` 0,5 s ↔ số ghế / `--` 1 s | xanh nháy 120 ms mỗi 1 s |
| Nhập phòng (MODE lần 2) | `Ph` 0,5 s ↔ số phòng 1 s | đỏ nháy 120 ms mỗi 1 s |
| Đã chọn đáp án, chưa gửi | chữ cái A b C d E F G H I J ở ô trái, nhấp nháy (450/250 ms) | tắt |
| Đang gửi | chữ cái đứng yên | tắt → xanh (nhận) / đỏ (NACK, hết giờ) |

Đèn kết quả luôn ưu tiên hơn đèn nháy của chế độ nhập. Hết giờ (4 lần gửi) → chữ cái nhấp nháy lại, bấm OK để gửi lần mới.

- Lần đầu (flash trống) Remote hiện `Gh` / `--` (đèn xanh nháy) và chờ nhập ghế. Room mặc định 1 (MODE hai lần để đổi).
- MODE → OK (không gõ gì) = LOGIN lại với ghế cũ (thay cho chức năng MODE của V3.9).
- Đèn xanh = server nhận đúng giao dịch (cả LOGIN); đỏ dài = NACK (Serial in lý do, ví dụ `SEAT_TAKEN` khi LOGIN vào ghế đã có máy khác) hoặc hết 4 lần gửi.

**Chẩn đoán qua Serial (Bước 2, xem `../TEST_V3_10.md` mục 2):** gõ `d` = bật/tắt đếm beacon nghe được mỗi giây (`DIAG RX/s STATE= ATTEND= OPENMASK= ACKS= PROBE= phase= rssi=`), `u` = phát 100 gói PROBE (0,7 s/gói) cho `tools/ble_probe.py uplink`, `h` = trợ giúp. Gói PROBE (type 3) bị `ars-ble`/ingest bỏ qua.

Serial 115200 in mọi giao dịch: `TX ANSWER …`, `No ACK yet -> random backoff …`, `RETRY ANSWER …`, `ACK ANSWER EXACT … dt=85ms attempts=1`, `NACK ANSWER EXACT … reason=…`, `ACK LOGIN EXACT …`, `NACK LOGIN EXACT … reason=…`, `ACK TIMEOUT … dt=…`, `ADV START FAIL`. Lưu log này rồi chạy `tools/ble_txn_report.py` để có tỉ lệ và độ trễ.

## Thông số radio

| Hằng số | File | V3.9 | V3.10 |
|---|---|---|---|
| `ADV_INTERVAL_UNITS` | ars_ble.cpp | 40 (25 ms) | 40 (25 ms) |
| TX | ars_ble.cpp | 3 × 100 ms xen RX 120 ms | `TX_BURST_MS` 150 ms một lần; V3.11: vẫn quét, dừng sớm khi có kết quả |
| Nghe ACK | .ino | 3 × 120 ms + 650 ms | `ACK_LISTEN_MS` 1000 ms liên tục |
| Kiểu quảng bá | ars_ble.cpp | connectable | non-connectable non-scannable |
| Scanner | ars_ble.cpp | 160/160 (liên tục) | 160/160 (liên tục) |
| Số lần gửi | .ino | 3 | `MAX_ATTEMPTS` 4 |
| Nghỉ trước gửi lại | .ino | 30–120 ms | `BACKOFF_MS` 50–150 / 150–400 / 300–800 ms |
| ACK sống trên laptop | ingest.py | 1,8 s cả phòng | `ACK_TTL_S` 1,8 s từng ghế |
| Dừng quét cho ACK | ble_scan.py | 120 ms mỗi ACK | 120 ms, cách nhau ≥ `ACK_PAUSE_MIN_GAP_S` 400 ms, gộp `ACK_COALESCE_S` 30 ms |
