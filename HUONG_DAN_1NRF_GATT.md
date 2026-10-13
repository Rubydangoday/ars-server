# Chạy ARS với 1 nRF52840 (Remote kết nối BLE GATT với laptop)

```
Remote nRF52840 ══ kết nối BLE GATT (NUS) ══ Bluetooth laptop ── ars-gatt (bridge/ble_gatt.py) ──MQTT── ars-ingest ── MariaDB ── Web
```

Không cần gateway. Remote **kết nối** với laptop (không phải phát quảng bá như 2 nRF): tầng liên kết Bluetooth tự xác nhận và
gửi lại từng gói, laptop không phải vừa quét vừa phát nên tránh được các lỗi BlueZ của chế độ quảng bá (`ars gateway ble`).
Bản này là V3.11 GATT của nhóm, đưa vào repo thành chế độ thứ ba; chế độ 2 nRF (`HUONG_DAN_2NRF.md`) giữ nguyên.

| Chế độ | Lệnh | Firmware Remote | Dịch vụ |
|---|---|---|---|
| 2 nRF — gateway USB (mặc định) | `sudo ars gateway usb` | `firmware/ARS_remote_v3_10/` | `ars-bridge` |
| **1 nRF — GATT** | `sudo ars gateway gatt` | **`firmware/ARS_remote_gatt/`** | `ars-gatt` |
| 1 nRF — quảng bá (cũ) | `sudo ars gateway ble` | `firmware/ARS_remote_v3_10/` | `ars-ble` |

Đổi chế độ thì **nạp lại đúng firmware Remote** — Remote GATT không nói chuyện được với gateway/quảng bá và ngược lại.

## 1. Cài / chuyển sang 1 nRF

```bash
cd ~/Downloads/ars-server
sudo bash deploy/install_ubuntu.sh      # chép code mới: ingest có kết quả GATT, MQTT không trễ Nagle, dịch vụ ars-gatt
sudo ars gateway gatt                   # tắt ars-bridge / ars-ble, bật ars-gatt (giữ qua các lần cài lại)
```
Nạp **`firmware/ARS_remote_gatt/ARS_remote_gatt.ino`** vào Remote (Arduino IDE như mục A2–A3 của `HUONG_DAN_2NRF.md`;
tiêu đề cửa sổ phải là `ARS_remote_gatt`). Rút gateway USB nếu đang cắm (không cần nữa).

Ghi MAC Remote vào cấu hình để laptop chỉ kết nối đúng Remote này:
```bash
sudo systemctl stop ars-gatt
python3 bridge/ble_gatt.py --scan                      # dòng ARS_REMOTE_01: <MAC>, service 6e400001-…
sudo nano /opt/ars-server/config.ini                    # mục [ble]:  remote_address = <MAC>
sudo systemctl start ars-gatt
```
Đóng ứng dụng Bluetooth trên điện thoại (nRF Connect…): Remote chỉ cho **một** máy kết nối.

## 2. Kiểm tra

```bash
ars status          # ars-ingest, ars-gatt: active  (ars-bridge, ars-ble: inactive là đúng)
journalctl -u ars-gatt -u ars-ingest -f
```
| Log | Nghĩa |
|---|---|
| `GATT READY <MAC> room=1` | đã kết nối + đăng ký Notify + HELLO đúng room |
| `GATT RX type=2 seat=… seq=…` | laptop nhận gói từ Remote |
| `ĐIỂM DANH` / `NHẬN` / `TRÙNG` / `LOẠI …` | ingest đã xử lý |
| `BACKEND RESULT type=… seq=… status=…` | kết quả chính xác đã gửi xuống Remote |

Web: bảng điều khiển hiện **Gateway: đang nhận** khi Remote đã kết nối.

**Màn hình Remote GATT** (khác Remote 2 nRF): `r` ↔ số = nhập phòng · `Id` ↔ số = nhập ghế · `n` ↔ số = câu ·
`An` ↔ 01–10 = đáp án A–J · `bt` nhấp nháy = chưa kết nối laptop · hai gạch chạy = chờ server · `LG` = đã kết nối nhưng
chưa điểm danh (mở buổi học rồi MODE → OK) · `Er` + đèn đỏ = bị từ chối / chưa rõ kết quả. Phím như cũ (S11 = MODE).

## 3. Đo tốc độ và độ chính xác

**(a) Riêng đường Bluetooth** — 100 lần PING/PONG laptop ⇄ Remote, không đụng CSDL:
```bash
ars gatt-probe 100        # ping_pong_ok: 100, median_ms / p95_ms / max_ms
```
**(b) Toàn tuyến, Remote cắm USB để có log Serial** (`dt=…ms` = từ lúc bấm OK tới khi Remote nhận kết quả):
```bash
journalctl -u ars-ingest -u ars-gatt -f --since now | tee ~/server.log
python3 -m serial.tools.miniterm /dev/serial/by-id/usb-Adafruit_Feather_nRF52840_Express_<SỐ>-if00 115200 | tee ~/serial.log
# mở câu 1, bấm A/B/C/D + OK 50 lần (chờ đèn), rồi:
python3 tools/ble_txn_report.py ~/serial.log ~/server.log --seat <ghế>
```
**(c) Remote chạy pin:** `python3 tools/ble_txn_report.py --server-only ~/server.log --presses 50 --seat <ghế>`.

Mục tiêu 1 Remote: có kết quả ≥ 99 %, ACK lần đầu ≥ 95 %, P95 < 200 ms, 0 đèn xanh nhầm.

Đã đo phía server trên máy này (MariaDB + Mosquitto + ingest thật, gói giống hệt `ble_gatt.py` gửi, 1000 lần bấm):
trung vị **3,0 ms**, P95 **4,1 ms** từ lúc bridge gửi gói tới lúc nhận kết quả — sau khi bật `TCP_NODELAY`
(trước đó **82 ms** mỗi giao dịch do Nagle + ACK trễ của TCP, ở cả 3 chế độ). Phần còn lại của độ trễ là Bluetooth:
đo bằng (a).

## 4. Sự cố

| Hiện tượng | Kiểm tra / sửa |
|---|---|
| Remote luôn `bt` | `ars status` có `ars-gatt` active? Remote đã nạp `ARS_remote_gatt`? MAC trong `[ble] remote_address` đúng? Điện thoại đang kết nối Remote? |
| Log `Room mismatch` | Room trên Remote (MODE hai lần) ≠ `[gateway] room` trong config.ini |
| Kết nối được nhưng không thấy dịch vụ | `bluetoothctl remove <MAC>` rồi `sudo systemctl restart ars-gatt` (xoá bộ nhớ đệm GATT cũ) |
| `LG` / `NACK LOGIN` | Serial nói lý do: `NO_SESSION` (chưa Bắt đầu buổi), `UNKNOWN_SEAT`, `SEAT_TAKEN`… |
| Có `GATT RX`, không có `BACKEND RESULT` | `ars-ingest` có chạy bản mới không (`sudo bash deploy/install_ubuntu.sh`) |
| `Er` sau khi mất kết nối | Kết quả chưa rõ — xem web trước khi bấm lại (Remote không tự gửi lại đáp án cũ sau khi nối lại) |
| `NACK … SEQ_REUSED` | Remote dùng lại số thứ tự cũ cho cùng câu — bấm OK lần nữa (seq mới) |

Quay lại 2 nRF: `sudo ars gateway usb` + nạp lại `firmware/ARS_remote_v3_10`.
