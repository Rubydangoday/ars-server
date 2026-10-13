# ARS Gateway V3.10 (nRF52840 SuperMini cắm USB)

Con nRF52840 thứ hai của hệ thống: cắm USB vào laptop chạy server, thu gói của mọi Remote trong phòng và phát trạng thái / ACK ngược lại. **Không có logic nghiệp vụ**: mọi kiểm tra nằm ở `ingest/ingest.py`. Không sửa gì ở Remote V3.10 (`../ARS_remote_v3_10`).

```
Remote ──BLE 9 byte──▶ Gateway ──1 dòng JSON/USB──▶ bridge.py (ars-bridge) ──MQTT──▶ ingest
Remote ◀──beacon────── Gateway ◀──1 dòng lệnh/USB── bridge.py               ◀──MQTT── ingest
```

## Build / nạp

Giống Remote: Arduino IDE hoặc `arduino-cli`, core **Adafruit nRF52** (đã thử biên dịch với 1.7.0, Bluefruit52Lib 0.21.0), cùng board/bootloader đã dùng để nạp Remote SuperMini.

```bash
arduino-cli compile --fqbn adafruit:nrf52:feather52840 firmware/ARS_gateway_v3_10
arduino-cli upload  --fqbn adafruit:nrf52:feather52840 -p /dev/ttyACM0 firmware/ARS_gateway_v3_10
```

Cắm gateway vào máy chủ → dịch vụ `ars-bridge` tự tìm cổng (`[gateway] serial = auto`: hỏi `v`, chờ `# ARS-GW 3.11`; Remote cắm cùng lúc không bị nhận nhầm) và mở lại khi gateway im lặng > 15 s. Kiểm tra: `ars gw`, `ars ports`. Từng bước: [../../HUONG_DAN_2NRF.md](../../HUONG_DAN_2NRF.md). Hoặc cắm máy giáo viên → bảng điều khiển → **🔌 Gateway USB máy này** (Chrome/Edge).

Chỉ cần board SuperMini trần; không dùng chân nào của mạch Remote Rev J. Đèn: xanh dương có sẵn **P0.15** (`GW_LED_PIN` trong `.ino`).

| Đèn | Nghĩa |
|---|---|
| Tắt | Chưa nhận lệnh nào từ server (bridge chưa chạy, sai cổng) hoặc mất server > 15 s |
| Sáng | Đang phát beacon trạng thái cho Remote |
| Chớp tắt ngắn | Vừa chuyển một gói Remote lên server |

## Giao tiếp USB (README mục 4.2b)

**Lên** — mỗi lần bấm của Remote đúng 1 dòng (đã lọc bản lặp):
```
{"mac":"C4:1A:00:11:22:33","room":1,"type":2,"id":7,"q":3,"seq":12,"ans":"B","batt":2950,"rssi":-61}
```
**Xuống** — `bridge.py` ghi nguyên lệnh của ingest, mỗi lệnh 1 dòng:
```
STATE <room> <qid> <open> <n_options> <13 byte hex>
ATTEND <13 byte hex>
OPENMASK <8 byte hex>
ACKS <ghế:câu:seq:đáp_án:status,...> | ACKS -
```
**Log** — dòng bắt đầu bằng `# ` (bridge bỏ qua). Mỗi 5 s:
```
# STAT rx=… fwd=… dup=… probe=… qdrop=… usbdrop=… cmd=… bad=… wait=… room=… adv=on|off upd=… adverr=…
```
`rx` = bản gói nghe được (kể cả bản lặp), `fwd` = dòng gửi lên, `dup` = bản lặp đã bỏ, `probe` = gói đo PROBE khác nhau (Remote gõ `u`; mỗi gói còn in 1 dòng `# PROBE seq=… rssi=…` để `tools/ble_txn_report.py --probe` tính %), `qdrop`/`usbdrop` = mất do hàng đợi đầy / không ai mở cổng, `cmd`/`bad` = lệnh hợp lệ / sai, `wait` = lệnh đến trước STATE đầu tiên, `adverr` = lỗi SoftDevice khi đổi quảng bá (phải = 0).

Lệnh tay (dừng `ars-bridge`, mở Serial monitor, gõ rồi Enter): `h` trợ giúp · `v` phiên bản · `s` thống kê · `d` bật/tắt in mọi bản gói nghe được (kể cả bản lặp và gói PROBE của Remote).

## Hoạt động

| Chức năng | Cách làm |
|---|---|
| Quét | Liên tục (cửa sổ = chu kỳ 100 ms), thụ động; chỉ giữ Manufacturer Data `0xFFFF` + `A5` + type < `0x10` (gói Remote) |
| Lọc bản lặp | Remote phát ~6 bản giống nhau trong 150 ms → chỉ bản đầu được gửi lên; bản giống hệt (MAC + 9 byte) trong **300 ms** bị bỏ. Lần gửi lại của Remote (cách ≥ 1 s) vẫn lên server để gia hạn ACK. Hệ quả: cột "gói trùng" ở trang Thiết bị gần như bằng 0 |
| Mã hoá beacon | `gw_codec.cpp` — bản C++ của `bridge/blecodec.py` (`encode_cmd`, `decode_uplink`), kiểm tra từng byte bằng `tests/gateway_codec_test.py` |
| Room | Lấy từ lệnh STATE (server gửi đầu tiên khi bridge mở cổng và mỗi 5 s). Chưa có STATE → chưa phát. Room đổi → bỏ beacon cũ |
| Phát | 1 bộ quảng bá non-connectable 20 ms, +8 dBm; nhịp 10 ms, đổi khe mỗi **30 ms**. **ACK mới lên sóng ở nhịp 10 ms kế tiếp** và 250 ms đầu chỉ phát ACKS (Remote V3.11 đang quét ngay trong đợt phát của nó); sau đó ACKS ↔ (STATE → ATTEND → OPENMASK) xen kẽ, ~60 ms/lần. Không có ACK (`ACKS -`): xoay 3 beacon nền |
| Mất server | 15 s không có lệnh → ngừng phát (Remote không thấy câu "đang mở" đã cũ); có lệnh lại → phát tiếp |

Beacon dài nhất (ACKS 4 bản ghi) = 3 byte flags + 4 byte header + 24 byte = 31 byte, vừa quảng bá legacy.

## File

| File | Nội dung |
|---|---|
| `ARS_gateway_v3_10.ino` | Đọc lệnh USB, gửi JSON lên, thống kê, mất server, đèn |
| `gw_radio.cpp/.h` | Quét (hàng đợi BLE task → loop) và lịch phát xoay vòng (SoftwareTimer + SoftDevice `sd_ble_gap_adv_*`) |
| `gw_codec.cpp/.h` | Mã hoá lệnh → beacon, gói Remote → JSON, lọc bản lặp. Không phụ thuộc Arduino |

**Đổi định dạng gói** thì sửa đồng bộ: firmware Remote + `gw_codec.cpp` + `bridge/blecodec.py` + `ingest.py` + `firmware/PROTOCOL_V3_10.md`, rồi chạy `bash tests/run_local.sh`.
