# Test V3.10 — quy trình và tiêu chí PASS/FAIL

> Mặc định server thu Remote qua **gateway nRF52840 cắm USB** (`ars-bridge`). Mục 2 (đo card Bluetooth laptop) và các chỉ số `DIAG SCAN DUTY` / `ble-tune` chỉ dùng khi chạy chế độ Bluetooth laptop (`sudo ars gateway ble`). Với gateway: bỏ qua mục 2; ở mục 3–4 thay `-u ars-ble` bằng `-u ars-bridge`.

## 0. Cài server

```bash
cd ~/Downloads/ars-server                       # repo đã có V3.10
sudo bash deploy/install_ubuntu.sh              # hoặc chỉ chép code:
sudo rsync -a --delete --exclude config.ini --exclude .gateway-ble --exclude .gateway-gatt --exclude .git --exclude '__pycache__' ./ /opt/ars-server/
sudo systemctl restart ars-ingest ars-bridge     # (chế độ Bluetooth laptop: ars-ble)
ars status                                      # ars-ingest, ars-bridge: active
```

Kiểm tra không cần radio (CSDL tạm, không đụng dữ liệu thật):

```bash
bash tests/run_local.sh       # ingest_integration 23, ble_scan_unit 11, … Cảnh báo PHP: 0
```

## 1. Nạp firmware, đặt ghế

Nạp `firmware/ARS_remote_v3_10` (xem README cùng thư mục). Mỗi Remote: MODE → gõ ghế → OK (MODE hai lần nếu cần đổi room). Ghế phải có trong lớp, room = `[gateway] room` của laptop = mã lớp.

## 1b. Kiểm tra gateway nRF52840 (mặc định)

Nạp `firmware/ARS_gateway_v3_10` vào một SuperMini (xem README cùng thư mục), cắm USB máy chủ.

1. **Gateway một mình** (~3 phút). `sudo systemctl stop ars-bridge`, mở Serial monitor 115200 cổng gateway (`arduino-cli monitor -p /dev/ttyACM0` hoặc Arduino IDE):
   - thấy `# ARS-GW 3.11`; gõ `d` rồi bấm OK trên một Remote → nhiều dòng `# RX … seq=…`, bản thứ 2 trở đi có `dup`, và **đúng 1** dòng JSON `{"mac":…}` cho lần bấm đó;
   - gõ tay `STATE 1 1 1 4 00` (room 1, câu 1 mở, 4 lựa chọn) rồi `ACKS 7:0:<seq LOGIN Remote vừa in>:0:0` (kết quả LOGIN chính xác cho ghế 7 — đổi theo ghế của Remote) → đèn gateway sáng, Remote in `ACK LOGIN EXACT`; Remote room 1 hiện câu 1, bấm E bị chặn tại chỗ (`LOCAL REJECT` trên Serial Remote);
   - gõ `ACKS <ghế>:1:<seq vừa gửi>:<đáp án 1–4>:0` trong vòng 1 s sau khi bấm (hoặc để Remote gửi lại) → Remote đèn xanh; status khác 0 → đèn đỏ;
   - không gõ gì 15 s → `# server OFF`, đèn tắt; `s` → dòng `# STAT … adverr=0`.
2. **Với server.** Đóng Serial monitor, `sudo systemctl start ars-bridge`. Web → bảng điều khiển buổi học: **Gateway: đang nhận (… gói/s)**; `ars mqtt` thấy `ars/<room>/resp` khi bấm Remote, mỗi lần bấm 1 bản tin.
3. Làm mục 3–4 bên dưới với `journalctl -u ars-ingest -u ars-bridge`. Bỏ qua mục 2 (chỉ dành cho Bluetooth laptop).

Tiêu chí riêng của gateway: `adverr` = 0, `qdrop` = 0, `usbdrop` = 0 trong suốt bài test; mỗi lần bấm đúng 1 dòng JSON (lần gửi lại cách ≥ 1 s được tính là dòng mới — đúng thiết kế).

4. **Đo gói lên qua gateway** (~80 s). `ars-bridge` đang chạy; Terminal 1: `journalctl -u ars-bridge -f --since now | tee probe_server.log`; Serial Remote gõ `u` (100 gói PROBE, lưu `probe_serial.log`). Xong:
   ```bash
   python3 tools/ble_txn_report.py probe_serial.log probe_server.log --probe     # tiêu chí ≥ 99 %
   ```
   Thấp → gói lên mất do sóng (vị trí gateway, cáp USB nối dài, Wi-Fi 2.4 GHz gần), chưa phải do server.

## 2. Đo radio của card Bluetooth laptop (Bước 2)

Mục tiêu: biết card laptop **có phát ACK đều khi đang quét không**, **bắt được bao nhiêu % gói Remote khi đang phát ACK**, và **có phát nhiều bộ quảng bá song song được không** — ba con số quyết định Bước 3. Cần 1 Remote V3.10 nối Serial 115200, đặt cách laptop 1–2 m rồi 5–8 m (đo cả hai khoảng cách).

### 2.1 Thông tin card (1 phút)

```bash
sudo bash tools/ble_diag_collect.sh                 # → ~/ars_ble_diag_<giờ>.tar.gz
```
Log `ars-ble` khi khởi động cũng in `DIAG ADAPTER hci0 instances=… secondary=… ext_adv=yes|no`.
`ext_adv=no` (không có quảng bá mở rộng) = BlueZ xoay vòng các bộ quảng bá bằng phần mềm, mặc định **2 s mỗi bộ** → nhiều gói ACKS song song (Bước 3) sẽ không chạy tốt trên card này.

### 2.2 Downlink: card có phát ACK khi đang quét không (~1 phút)

```bash
sudo systemctl stop ars-ble
python3 tools/ble_probe.py downlink                 # 4 pha × 15 s
```
Remote: Serial gõ `d`. Ghi lại `PROBE=x/s` trung bình của từng `phase`.

### 2.3 Uplink: laptop bắt được bao nhiêu gói khi đang phát ACK (~80 s)

```bash
python3 tools/ble_probe.py uplink                   # in % seq bắt được ở pha 1 (chỉ quét) và pha 2 (quét + phát ACK)
```
Remote: Serial gõ `u` ngay khi lệnh in hướng dẫn. Xong: `sudo systemctl start ars-ble`.

### 2.4 Thử tỉ lệ quét thấp hơn (nếu 2.2 pha 2 tụt mạnh)

```bash
ars ble-tune scan 30 20        # quét 20 ms mỗi 30 ms (≈ 67 %), để card có khe phát ACK
ars ble-tune show
# lặp lại 2.2 + 2.3, rồi so sánh; quay về mặc định:
ars ble-tune reset
```
Thông số ACK của `ars-ble` cũng chỉnh được không cần sửa code: `[ble] ack_exclusive_ms / ack_pause_gap_ms / ack_coalesce_ms` trong `/opt/ars-server/config.ini`, rồi `sudo systemctl restart ars-ble`.

### 2.5 Đọc kết quả → quyết định Bước 3

| Quan sát | Nghĩa | Bước tiếp |
|---|---|---|
| 2.2: pha 2 ≥ 80 % pha 1 | Card phát ACK tốt khi đang quét | ACK_MISS (nếu còn) do lịch nghe của Remote → chỉnh `ACK_LISTEN_MS` |
| 2.2: pha 2 < 50 % pha 1, pha 4 khá hơn rõ | Quét 100 % lấn át phát | Giữ dừng quét ngắn; thử `ble-tune scan 30 20` |
| 2.2: pha 2 < 50 % kể cả sau `ble-tune` | Card không chia sẻ radio tốt | **Gateway nRF52840 USB** (`sudo ars gateway usb`) |
| 2.2: pha 3 ≪ pha 2 hoặc `ext_adv=no` | Không phát song song nhiều bộ | Bước 3 không dùng nhiều gói ACKS song song; dùng bảng ACK nén hoặc gateway |
| 2.2: pha 3 ≈ pha 2 và `ext_adv=yes` | Phát song song được | Bước 3: 2–3 gói ACKS song song (8–12 bản ghi), định dạng không đổi |
| 2.3: pha 1 < 99 % | Uplink yếu ngay cả khi chỉ quét | Tăng `TX_BURST_MS` 150 → 200, kiểm tra anten/vị trí laptop |
| 2.3: pha 2 thấp hơn pha 1 > 5 điểm % | Phát ACK làm laptop bỏ lỡ gói Remote | Giảm `ack_exclusive_ms`, rút thời gian ACK trên sóng |

## 3. Test 1 Remote (50 giao dịch)

Terminal 1: `journalctl -u ars-ingest -u ars-bridge -f --since now | tee server.log` (chế độ Bluetooth laptop: `-u ars-ble`)
Terminal 2: Serial monitor 115200 của Remote, lưu thành `serial.log`.

Trên web mở câu 1 (4 lựa chọn), bấm 50 lần xen kẽ A/B/C/D + OK (chờ đèn trước khi bấm tiếp). Không chạy `sudo ars ble` trong lúc test (chế độ dump không phát ACK).

```bash
python3 tools/ble_txn_report.py serial.log server.log --seat <ghế>
```

## 4. Test nhiều Remote (≥ 5 máy, mục tiêu 30–50)

Như bước 3 nhưng cả nhóm bấm **cùng lúc** khi giáo viên mở câu (đếm 3-2-1), 10 câu liên tiếp. Chạy `ble_txn_report.py` cho từng Remote (`--seat`). Trong `server.log` xem dòng `DIAG SCAN DUTY … (xx% scanning)` — phải ≥ 70 %.

Không đủ Remote thật: chạy thêm Remote giả lập ở tầng MQTT để tạo tải cho server (ACK của chúng chiếm chỗ trong gói ACKS như máy thật):

```bash
ars sim 30 <room>             # tools/sim_clickers.py — in tỉ lệ ACK, NACK, hết giờ, độ trễ P95 (--ack-dwell 0.1 mặc định)
```

## 5. Tiêu chí

| Chỉ số | PASS | Nguồn |
|---|---|---|
| Server nhận (uplink) | ≥ 99 % giao dịch | `ble_txn_report.py` cột server |
| Giao dịch có kết quả chính xác (ACK hoặc NACK) | ≥ 99 % | `ble_txn_report.py` |
| Timeout (UPLINK_MISS + ACK_MISS) | ≤ 1 / 100 | `ble_txn_report.py` |
| ACK ở lần gửi đầu | ≥ 95 % (1 Remote, gateway), ≥ 80 % (cả lớp cùng bấm) | `ble_txn_report.py` |
| Độ trễ OK → đèn xanh | P95 < 300 ms (1 Remote, gateway; ước lượng 50–120 ms) | `ble_txn_report.py` (cột `dt ms`, Remote V3.11) |
| LOGIN | `ACK LOGIN EXACT` đúng seq; ghế đã có máy khác → `NACK LOGIN EXACT reason=SEAT_TAKEN` | Serial Remote |
| Gói lên qua gateway | ≥ 99 % | `ble_txn_report.py --probe` (mục 1b.4) |
| Tỉ lệ thời gian quét khi cả lớp bấm (chỉ chế độ Bluetooth laptop) | ≥ 70 % | `DIAG SCAN DUTY` |
| ACK sai (đèn xanh cho giao dịch khác) | 0 | Serial: mọi `ACK ANSWER EXACT` khớp seq vừa gửi |
| Đáp án không hợp lệ (E khi 4 lựa chọn) | bị chặn tại Remote, không gửi | Serial `LOCAL REJECT` |

FAIL ở uplink (gateway) → đo mục 1b.4 trước: PROBE < 99 % là do sóng (vị trí gateway, nhiễu Wi-Fi), mới thử tăng `TX_BURST_MS`. Chế độ Bluetooth laptop: FAIL ở uplink → giữ giao thức, thử tăng `TX_BURST_MS` (150 → 200) hoặc giảm `ACK_EXCLUSIVE_S`. FAIL ở ACK_MISS khi đã có `btmon` → bước 2/3 (giảm tỉ lệ quét BlueZ bằng `ScanWindowDiscovery`, hoặc gateway nRF52840 USB: `sudo ars gateway usb`).
