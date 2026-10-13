# Thay đổi V3.11 (13/10/2026)

Tóm tắt hai đợt cập nhật gần nhất: `fcaccab` (2 nRF V3.11) và `8037ab0` (chế độ 1 nRF GATT, tăng tốc MQTT, sửa lỗi).
Chi tiết cách chạy: [HUONG_DAN_2NRF.md](HUONG_DAN_2NRF.md) (2 nRF) · [HUONG_DAN_1NRF_GATT.md](HUONG_DAN_1NRF_GATT.md) (1 nRF).

## 1. Ba chế độ thu sóng

| Chế độ | Lệnh | Firmware Remote | Dịch vụ | Ghi chú |
|---|---|---|---|---|
| **2 nRF — gateway USB** (mặc định) | `sudo ars gateway usb` | `firmware/ARS_remote_v3_10/` | `ars-bridge` | Gateway SuperMini tự lịch quét/phát, ổn định nhất |
| **1 nRF — BLE GATT** (mới) | `sudo ars gateway gatt` | `firmware/ARS_remote_gatt/` | `ars-gatt` | Remote kết nối thẳng laptop, không cần gateway |
| 1 nRF — quảng bá (cũ) | `sudo ars gateway ble` | `firmware/ARS_remote_v3_10/` | `ars-ble` | Phụ thuộc BlueZ, kém ổn định |

Chế độ đã chọn được giữ qua mỗi lần chạy lại `deploy/install_ubuntu.sh`. Đổi chế độ thì nạp lại đúng firmware Remote.

## 2. Tốc độ

| Thay đổi | Trước | Sau | File |
|---|---|---|---|
| Bật `TCP_NODELAY` cho client MQTT và Mosquitto — ingest gửi 2 bản tin nhỏ liền nhau mỗi gói, Nagle + ACK trễ giữ bản thứ hai ~40 ms mỗi chiều | 82 ms / giao dịch | **3 ms** (trung vị, P95 4,1 ms; 1000 lần bấm) | `common/arscfg.py`, `deploy/mosquitto-ars.conf` |
| Remote quét **trong lúc** phát, dừng phát ngay khi nghe kết quả (2 nRF) | chỉ nghe ACK sau đợt phát 150 ms | ACK nghe được trong đợt phát | `firmware/ARS_remote_v3_10/ars_ble.cpp` |
| Gateway: nhịp 10 ms, ACK mới lên sóng ngay, 250 ms đầu chỉ phát ACK | đổi khe 30 ms, ACK xen kẽ | ACK ≤ 10 ms | `firmware/ARS_gateway_v3_10/gw_radio.cpp` |

Số đo phía server: MariaDB + Mosquitto + ingest thật trên máy chủ, gói giống hệt `ble_gatt.py` gửi — từ lúc bridge gửi
gói tới lúc nhận kết quả chính xác. Thời gian trên sóng Bluetooth đo trên phần cứng: `ars gatt-probe 100` (GATT),
`tools/ble_txn_report.py` (cột `dt ms`).

## 3. Độ chính xác

| Lỗi | Hậu quả trước đây | Sửa |
|---|---|---|
| LOGIN "thành công" khi thấy bit ghế trong ATTEND — bit đó bật cả khi **máy khác** giữ ghế | Remote báo đã điểm danh sai | LOGIN có kết quả chính xác `(ghế, 0, seq, 0, status)`, cả khi bị từ chối (`SEAT_TAKEN`, `NO_SESSION`…) |
| Seq dùng lại cho cùng câu sau cửa sổ trùng 10 s (seq 8 bit quay vòng, Remote khởi động lại) | Lỗi khoá `uq_resp` → ingest không gửi kết quả → Remote hết giờ, **mất lượt** (45/300 lượt trong phép đo) | NACK chính xác mã 15 `SEQ_REUSED`; bấm lại là được (300/300 lượt có kết quả) |
| Seq ban đầu lấy từ `micros()` lúc khởi động — giống nhau mỗi lần reset | Sau mỗi reset Remote gửi lại đúng seq cũ (đo được: LOGIN seq=104 lặp lại) | Hạt giống từ bộ tạo số ngẫu nhiên phần cứng nRF52840 (cả 2 firmware Remote) |
| `ingest` cắt mọi bản tin MQTT ở 400 ký tự | JSON trạng thái gateway kèm thống kê bị hỏng | Chỉ cắt gói Remote |

Chế độ GATT: kết quả ghép theo mã yêu cầu ngẫu nhiên + mọi trường (ghế, câu, seq, đáp án); kết quả giữ lại / lạc không
bao giờ thành ACK; mất kết nối khi đang chờ → Remote báo `Er`, không báo xanh giả, không tự gửi lại đáp án cũ.

## 4. Remote (giao diện)

**2 nRF / quảng bá (`ARS_remote_v3_10`):**

| Chế độ | Màn hình | Đèn |
|---|---|---|
| Bình thường | số câu đứng yên `03` | tắt |
| Nhập ghế (MODE) | `Gh` ↔ số ghế | xanh nháy chậm |
| Nhập phòng (MODE lần 2) | `Ph` ↔ số phòng | đỏ nháy chậm |
| Đã chọn đáp án, chưa gửi | chữ cái A b C d E F G H I J nhấp nháy | tắt |
| Đang gửi | chữ cái đứng yên | tắt → xanh / đỏ |

- Tự kiểm tra `88` + đỏ + xanh **ngay khi bật nguồn** (trước: chờ cổng USB 3 s, chạy pin thì tối 3 s).
- Serial in `dt=…ms attempts=…` cho mỗi giao dịch, `ADV START FAIL` khi không phát được.

**1 nRF GATT (`ARS_remote_gatt`):** nhãn `r` / `Id` / `n` / `An` / `bt` / `LG` / `Er` (xem `HUONG_DAN_1NRF_GATT.md`),
cùng định dạng log Serial với Remote 2 nRF.

Phím giữ nguyên (S11 = MODE, S14 = OK, S3 / S7 = LEFT / RIGHT); chân GPIO không đổi.

## 5. Gateway và bridge (2 nRF)

- Firmware gateway 3.11: quét Remote → JSON qua USB, lọc bản lặp 300 ms, đếm gói PROBE, thống kê `# STAT` mỗi 5 s.
- `bridge.py`: `[gateway] serial = auto` — tự tìm cổng trả lời `# ARS-GW` (không nhận nhầm Remote cắm cùng lúc);
  **không mở board đang ở bootloader** (trước: chen vào lúc Arduino IDE đang nạp → `Timed out waiting for acknowledgement`);
  board khác số serial cắm vào cùng cổng được hỏi ngay; gateway im lặng 15 s → mở lại cổng.
- `ars gw` (tình trạng gateway), `ars ports` (đánh dấu cổng có gateway), quy tắc udev để ModemManager không chiếm cổng.

## 6. Công cụ đo

```bash
python3 tools/ble_txn_report.py serial.log server.log --seat 2          # có log Serial: tỉ lệ, ACK lần đầu, trung vị / P95 ms
python3 tools/ble_txn_report.py --server-only server.log --presses 50 --seat 2   # Remote chạy pin
python3 tools/ble_txn_report.py serial.log server.log --probe           # tỉ lệ gói lên (Remote gõ u)
ars gatt-probe 100                                                       # PING/PONG Bluetooth chế độ GATT
```

## 7. Kiểm thử

`bash tests/run_local.sh` (CSDL + web tạm, không đụng dữ liệu thật):

| Bộ test | Số kiểm tra | Nội dung mới |
|---|---|---|
| `ingest_integration` | 27 | ACK LOGIN chính xác, NACK `seq_reused` |
| `gateway_codec_test` | 9 | firmware gateway so từng byte với `blecodec.py` |
| `bridge_unit` | 15 | tìm cổng tự động, bỏ qua bootloader, canh chừng gateway |
| `gatt_unit` | 19 | khung GATT, ghép kết quả theo mã, không ACK giả |
| `firmware_host_gatt` | OK | firmware GATT chạy trên máy tính với GPIO/BLE giả |
| Các bộ web còn lại | 172 + 57 + 180 + 127 + 33 + 31 + 57 | không đổi; 0 cảnh báo PHP |

Cả 3 firmware (Remote 2 nRF, Remote GATT, gateway) biên dịch không lỗi, không cảnh báo với core Adafruit nRF52 1.7.0.

## 8. Cập nhật máy chủ đang chạy

```bash
cd ~/Downloads/ars-server
git pull
sudo bash deploy/install_ubuntu.sh      # giữ config.ini, dữ liệu và chế độ đang dùng
```
Sau đó nạp lại firmware Remote (và gateway nếu dùng 2 nRF) — Remote V3.11 cần server mới để nhận xác nhận LOGIN.

## 9. Còn phải đo trên phần cứng

- Chế độ GATT: `ars gatt-probe 100` và 50 lần bấm có log Serial (độ trễ Bluetooth thật, tỉ lệ ACK lần đầu).
- Chế độ 2 nRF: lượt dài 50 lần bấm (lượt đo gần nhất: 9/9 giao dịch tới server, 0 lần gửi lại).
- Thời lượng pin Remote theo từng chế độ.
