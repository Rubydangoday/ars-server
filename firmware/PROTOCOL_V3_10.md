# ARS BLE Protocol V3.10 / V3.11

> **V3.11** (mục cuối): giữ nguyên định dạng byte; thêm kết quả chính xác cho LOGIN và rút ngắn độ trễ với gateway USB.

Định dạng byte **giữ nguyên V3.9** (Remote V3.9 vẫn nói chuyện được với server V3.10 và ngược lại). V3.10 chỉ đổi **lịch radio** (firmware) và **cách server chọn / phát ACK**. Mã tham chiếu: `bridge/blecodec.py`.

> Bộ thu mặc định là **gateway nRF52840 cắm USB** (`bridge.py`, README mục 4.2b): gateway đóng vai "laptop" trong tài liệu này — quét gói Remote và phát beacon STATE/ATTEND/OPENMASK/ACKS với cùng định dạng byte. Các mục về BlueZ / `ble_scan.py` (dừng quét, xoay vòng quảng bá) chỉ áp dụng khi dùng Bluetooth laptop (`sudo ars gateway ble`).

## Remote → laptop (ADV_NONCONN_IND, Manufacturer Data, company `0xFFFF`)

```text
[0] 0xA5 magic
[1] type: 1 LOGIN, 2 ANSWER
[2] room
[3] seat
[4] question (LOGIN: 0)
[5] seq            — giữ nguyên khi gửi lại
[6] answer: 0 none, 1=A ... 10=J
[7] battery mV low byte
[8] battery mV high byte
```

V3.10: gói quảng bá kiểu **non-connectable, non-scannable** (V3.9: connectable → laptop quét active phải gửi SCAN_REQ, Remote trả SCAN_RSP cho mọi sự kiện).

## Laptop → Remote

```text
STATE:    [A5,10,room,qid,open,n_options] + 13-byte answered bitmap      (19 byte)
ATTEND:   [A5,11,room] + 13-byte attendance bitmap                         (16 byte) — thông tin (V3.11: không còn là xác nhận LOGIN)
OPENMASK: [A5,12,room] + 8-byte question-open mask                         (11 byte)
ACKS:     [A5,13,room,n] + n records, n ≤ 4                                (4 + 5n byte)
          record = (seat, question, seq, answer_code, status)
          V3.11: kết quả LOGIN = (seat, 0, seq, 0, status)
```

`status = 0`: chấp nhận. Khác 0: NACK chính xác (1 BAD_OPTION, 2 QUESTION_CLOSED, 3 NOT_LOGGED_IN, 4 NO_SESSION, 5 QUESTION_NOT_OPEN, 6 LATE, 7 NO_CHANGE, 8 UNKNOWN_SEAT, 9 MAPPING_MISMATCH, 10 ID_MISMATCH, 11 SEAT_TAKEN, 12 MAC_BOUND, 13 WRONG_LINK, 14 MALFORMED, 255 khác). Remote chỉ nhận kết quả khi **đúng ghế + câu + seq + đáp án**; STATE không bao giờ được dùng làm ACK.

## Giao dịch và độ tin cậy

```text
Remote:  bấm OK → seq mới
         TX burst 150 ms (25 ms/sự kiện ≈ 6 sự kiện × 3 kênh; V3.10 tắt quét, V3.11 vẫn quét và dừng sớm khi có kết quả)
         RX liên tục 1000 ms chờ ACK/NACK chính xác
         chưa có → nghỉ ngẫu nhiên: lần 1 50–150 ms, lần 2 150–400 ms, lần 3 300–800 ms
         gửi lại CÙNG seq — tối đa 4 lần (≈ 6 s trường hợp xấu nhất)
Server:  nhận → kiểm tra → ghi DB → bản ghi ACK/NACK của ghế đó (mỗi ghế 1 bản ghi)
         gói lặp/gửi lại cùng giao dịch → chỉ gia hạn bản ghi 1,8 s, không publish lại
         gói ACKS chứa 4 bản ghi MỚI nhất; bản ghi đã rơi ra mà Remote gửi lại → được đưa lên lại
         mỗi ghế hết hạn riêng sau 1,8 s; hết sạch → "ACKS -" → phát lại STATE/ATTEND/OPENMASK
ble_scan: thay đổi ACK trong 30 ms gộp thành 1 lần đăng ký BlueZ
         dừng quét 120 ms chỉ khi có bản ghi MỚI và lần dừng trước cách ≥ 400 ms (quét ≥ 70 % thời gian)
```

## Thay đổi so với V3.9

| Vấn đề V3.9 | File / hàm | V3.10 |
|---|---|---|
| `records[:4]` giữ 4 bản ghi CŨ nhất → ghế thứ 5 trở đi không bao giờ thấy ACK của mình | `blecodec.encode_cmd` | `records[-4:]` |
| Mỗi gói lặp đổi thứ tự danh sách ACK → đăng ký lại BlueZ + dừng quét 120 ms | `ingest.remember_ack` | gói lặp chỉ gia hạn; publish khi nội dung đổi |
| Hạn ACK chung cả phòng, gói mới gia hạn tất cả | `ingest.state_loop` | hạn riêng từng ghế (`expire_acks`) |
| Dừng quét 120 ms cho MỖI ACK (40 em bấm trong 2 s → laptop "điếc" 100 %) | `ble_scan.Advertiser.set` | gộp 30 ms, dừng quét ≥ 400 ms một lần (≤ 30 %) |
| 3× TX 100 ms / RX 120 ms, 6 lần đổi chế độ, nghe ngắt quãng | `ars_ble.cpp::sendPacket` | TX 150 ms → RX liên tục 1000 ms |
| Quảng bá connectable → SCAN_REQ/SCAN_RSP thừa | `startUplinkAdvertising` | non-connectable non-scannable |
| Nghỉ 30–120 ms cố định | `.ino::processRetry` | 50–150 / 150–400 / 300–800 ms, 4 lần |
| Ghế/room là hằng số → không thể thử nhiều Remote | `.ino` | nhập bằng phím MODE, lưu flash |
| Biến ACK đọc/ghi giữa 2 task không khoá | `ars_ble.cpp` | `taskENTER_CRITICAL` khi chép |

## Kết quả mô phỏng (server thật + 40 Remote giả lập ở tầng MQTT, chưa có radio)

`tools/sim_clickers.py`, mất 30 % gói uplink, một bản ghi ACK chỉ tính là "nghe được" nếu nằm trên sóng liên tục ≥ 100 ms (`--ack-dwell 0.1`, ≈ 2 lần phát ACK). 40 Remote × 3 câu = 120 giao dịch mỗi lượt.

| Học sinh bấm rải trong | Bản | ACK chính xác | Ngay lần đầu | Hết giờ | Trung vị / P95 |
|---|---|---|---|---|---|
| 1–10 s (lớp bình thường) | V3.9 | 99,2 % | 115 | 1 | 306 / 879 ms |
| | **V3.10** | **100 %** | **120** | **0** | **156 / 190 ms** |
| 0,5–4 s (lớp nhanh) | V3.9 | 97,5 % | 114 | 3 | 285 / 500 ms |
| | **V3.10** | **100 %** | **116** | **0** | **158 / 286 ms** |
| 0,2–1 s (cả lớp bấm cùng lúc) | V3.9 | 63 % | 22 | 44 | — |
| | V3.10 | 67 % | 13 | 40 | — |

Mô phỏng `ble_scan` (tests/ble_scan_unit.py, 40 ACK trong 2 s): V3.9 dừng quét 39 lần = 100 % thời gian; V3.10 ≈ 26 %.

**Giới hạn còn lại (Bước 3):** khi 40 em bấm trong ~1 s, một gói ACKS chỉ chứa 4 bản ghi nên không đủ chỗ — cần nhiều gói ACKS song song (định dạng giữ nguyên, firmware đã đọc mọi gói ACKS) hoặc bảng ACK theo ghế; phải đo trước `btmgmt advinfo` / `btmon` xem card laptop phát nhiều bộ quảng bá cùng lúc được không (BlueZ xoay vòng phần mềm mỗi ~2 s nếu không). Trường hợp đó cũng là lý do để cân nhắc gateway nRF52840 USB.

## V3.11 — tốc độ và độ chính xác cho 1 Remote (gateway USB)

| Vấn đề V3.10 | File / hàm | V3.11 |
|---|---|---|
| LOGIN "thành công" khi thấy bit ghế trong ATTEND — bit đó cũng bật khi **máy khác** đã chiếm ghế, hoặc từ lần LOGIN trước → báo điểm danh **sai** | `ars_ble.cpp` ATTEND, `ingest.login` | LOGIN có bản ghi chính xác `(ghế, 0, seq, 0, status)` trong ACKS, cả khi bị từ chối (`SEAT_TAKEN`, `UNKNOWN_SEAT`, `MAPPING_MISMATCH`…). ATTEND chỉ còn là thông tin |
| Remote tắt quét suốt đợt phát 150 ms → ACK (gateway đưa lên sóng sau ~40–80 ms) chỉ nghe được sau 150 ms | `ars_ble.cpp::sendPacket` | quét **trong** đợt phát; nghe thấy kết quả đúng giao dịch → dừng phát ngay |
| Gateway đổi khe mỗi 30 ms và xen kẽ ACKS 1:1 với beacon nền | `gw_radio.cpp::slotTick` | nhịp 10 ms; ACKS mới lên sóng ở nhịp kế tiếp, 250 ms đầu chỉ phát ACKS |
| Không đo được độ trễ / lỗi phát của Remote | `.ino` | in `dt=…ms attempts=…`, `ADV START FAIL` |
| `ingest` cắt mọi bản tin MQTT ở 400 ký tự → trạng thái gateway kèm thống kê thành JSON hỏng | `ingest.on_message` | chỉ cắt gói Remote |

Tương thích: Remote V3.10 gặp bản ghi LOGIN `(ghế, 0, seq, 0, …)` chỉ in "Ignore old/other RESULT"; Remote V3.11 với server V3.10 sẽ không nhận được xác nhận LOGIN → **nạp firmware và cập nhật server cùng lúc**.

Ước lượng độ trễ OK → đèn xanh với gateway (chưa đo trên phần cứng): gói đầu tới gateway ~0–25 ms, USB + bridge + MQTT + ingest (3–4 truy vấn DB) ~20–50 ms, gateway đổi beacon ≤ 10 ms, sự kiện quảng bá kế tiếp ≤ 30 ms → **khoảng 50–120 ms** (V3.10: ≥ 150 ms vì Remote chỉ nghe sau đợt phát). Đo bằng `tools/ble_txn_report.py` (cột `dt ms`, trung vị / P95).

