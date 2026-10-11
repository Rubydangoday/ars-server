# Test V3.10 — quy trình và tiêu chí PASS/FAIL

## 0. Cài server

```bash
cd ~/Downloads/ars-server                       # repo đã có V3.10
sudo bash deploy/install_ubuntu.sh              # hoặc chỉ chép code:
sudo rsync -a --delete --exclude config.ini --exclude .gateway-usb --exclude .git --exclude '__pycache__' ./ /opt/ars-server/
sudo systemctl restart ars-ingest ars-ble
ars status                                      # ars-ingest, ars-ble: active
```

Kiểm tra không cần radio (CSDL tạm, không đụng dữ liệu thật):

```bash
bash tests/run_local.sh       # ingest_integration 23, ble_scan_unit 11, … Cảnh báo PHP: 0
```

## 1. Nạp firmware, đặt ghế

Nạp `firmware/ARS_remote_v3_10` (xem README cùng thư mục). Mỗi Remote: MODE → gõ ghế → OK (MODE hai lần nếu cần đổi room). Ghế phải có trong lớp, room = `[gateway] room` của laptop = mã lớp.

## 2. Thu số liệu card Bluetooth của laptop (một lần, để quyết định bước 2)

```bash
sudo btmgmt info | head -5
sudo btmgmt advinfo            # Instances / supported flags: có hỗ trợ nhiều bộ quảng bá trong controller không
sudo btmon -w ~/ars_btmon.log  # chạy song song với test 3, Ctrl+C khi xong
```

Gửi kèm `btmon` log khi báo kết quả: nó cho biết card có thật sự phát ACK khi đang quét không (nguyên nhân nghi ngờ của ACK_MISS seq 30/31 ở V3.9).

## 3. Test 1 Remote (50 giao dịch)

Terminal 1: `journalctl -u ars-ingest -u ars-ble -f --since now | tee server.log`
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
| ACK ở lần gửi đầu | ≥ 90 % (1 Remote), ≥ 80 % (cả lớp cùng bấm) | `ble_txn_report.py` |
| Độ trễ OK → đèn xanh (lần đầu) | thường < 300 ms | Serial (thời điểm TX → ACK) |
| Tỉ lệ thời gian quét khi cả lớp bấm | ≥ 70 % | `DIAG SCAN DUTY` |
| ACK sai (đèn xanh cho giao dịch khác) | 0 | Serial: mọi `ACK ANSWER EXACT` khớp seq vừa gửi |
| Đáp án không hợp lệ (E khi 4 lựa chọn) | bị chặn tại Remote, không gửi | Serial `LOCAL REJECT` |

FAIL ở uplink → giữ giao thức, thử tăng `TX_BURST_MS` (150 → 200) hoặc giảm `ACK_EXCLUSIVE_S`. FAIL ở ACK_MISS khi đã có `btmon` → bước 2/3 (giảm tỉ lệ quét BlueZ bằng `ScanWindowDiscovery`, hoặc gateway nRF52840 USB: `sudo ars gateway usb`).
