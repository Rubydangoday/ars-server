# Hướng dẫn cho lập trình viên (người tiếp quản code)

[← Mục lục](README.md)

Tài liệu này giúp người mới đọc hiểu và sửa ARS mà không phá phần đang chạy. Chi tiết giao thức và cây thư mục đầy đủ có trong [README.md](../README.md); bản này tập trung vào **cách code được tổ chức** và **cách làm việc với nó**.

## Mục lục
1. [Bức tranh tổng thể](#1-bức-tranh-tổng-thể)
2. [Công nghệ](#2-công-nghệ)
3. [Thư mục quan trọng](#3-thư-mục-quan-trọng)
4. [Luồng dữ liệu Remote → web](#4-luồng-dữ-liệu-remote--web)
5. [Phần web PHP: quy ước](#5-phần-web-php-quy-ước)
6. [Cơ sở dữ liệu và migration](#6-cơ-sở-dữ-liệu-và-migration)
7. [Đa ngôn ngữ](#7-đa-ngôn-ngữ)
8. [Trợ lý AI](#8-trợ-lý-ai)
9. [Bluetooth và firmware Remote](#9-bluetooth-và-firmware-remote)
10. [Chạy thử và kiểm thử](#10-chạy-thử-và-kiểm-thử)
11. [Triển khai và vận hành](#11-triển-khai-và-vận-hành)
12. [Công thức cho các việc hay gặp](#12-công-thức-cho-các-việc-hay-gặp)
13. [Những điều không được làm](#13-những-điều-không-được-làm)

---

## 1. Bức tranh tổng thể

```
Remote nRF52840 ──BLE──▶ Gateway nRF52840 ──USB──▶ bridge/bridge.py ──MQTT──▶ ingest/ingest.py ──SQL──▶ MariaDB ◀──SQL── web/ (PHP, Apache)
               ◀─beacon─  (cắm laptop)     ◀─serial─ (dịch vụ ars-bridge) ◀─lệnh── (dịch vụ ars-ingest)                ▲
                                                                                               └── trình duyệt GV / HS / PH
```

Ba nguyên tắc thiết kế (đừng phá):
1. **Remote và bộ thu không quyết định gì.** Mọi luật (gói hợp lệ, trùng, trễ, sai ghế…) nằm ở `ingest/ingest.py`. Firmware chỉ phát gói, bộ thu chỉ chuyển gói.
2. **Web không nói MQTT.** PHP chỉ ghi DB rồi tăng `sessions.state_rev`. Ingest thấy `state_rev` đổi thì soạn lại trạng thái và phát xuống Remote trong ≤ 0,3 s.
3. **Chạy offline được.** Mọi thứ chạy trên một laptop, không cần Internet. Thư viện JS (KaTeX, mqtt.js, qrcode) nằm sẵn trong `web/assets/vendor/`. Chỉ AI, Google, Zalo/SMS, Cloud và Google Dịch cần mạng.

## 2. Công nghệ

| Phần | Công nghệ |
|---|---|
| Web | PHP 8.1+ (đang chạy 8.3), **không framework**, PDO MariaDB, HTML render phía server + JS thuần |
| CSDL | MariaDB 10.11 (CSDL `ars`) |
| Nhận gói | Python 3 + `paho-mqtt` + `pymysql` |
| Gateway USB | Python `pyserial` (`bridge.py`); tuỳ chọn Bluetooth laptop: `bleak` + `dbus-fast` (BlueZ) |
| Message broker | Mosquitto: 1883 chỉ trong máy, WebSocket 9001 qua Apache `/mqtt` |
| Máy chủ web | Apache 2 + mod_php |
| Hệ điều hành mục tiêu | Ubuntu 22.04 / 24.04 (systemd) |

## 3. Thư mục quan trọng

| Đường dẫn | Vai trò |
|---|---|
| `web/*.php` | Trang giáo viên (mỗi file một trang) |
| `web/student/` | Trang học sinh (`lib/student.php` vẽ khung) |
| `web/parent/` | Trang phụ huynh |
| `web/api/` | Endpoint JSON: `state.php` (bảng điều khiển hỏi mỗi giây), `action.php` (mở/đóng câu…), `ai.php` |
| `web/lib/bootstrap.php` | **Nạp đầu tiên ở mọi trang**: cấu hình, DB, phiên, CSRF, i18n, menu, `page_head()`/`page_foot()` |
| `web/lib/ui.php` | Thành phần giao diện dùng chung: `ui_kpi`, `ui_card_head`, `ui_empty`, `ui_line_chart`, `ui_bar`, `ui_score`… |
| `web/lib/*.php` | Nghiệp vụ theo mảng: `qbank` (câu hỏi), `qparse`/`extract` (tách câu từ Word/Excel/PDF), `lms` (bài tập, phụ huynh), `student`, `stats`, `rewards`, `variants` (đề trộn), `sync` (Cloud), `notify` (Zalo/SMS), `oauth`, `ai`, `tutor`, `i18n` |
| `web/lang/en.php` | Từ điển Việt → Anh (mảng PHP) |
| `web/assets/` | CSS, JS (`control.js` bảng điều khiển, `compose.js` soạn câu, `qeditor.js` thanh công cụ Word/LaTeX, `i18n.js`…) |
| `ingest/ingest.py` | Kiểm tra gói, ghi DB, soạn trạng thái gửi Remote, tự đóng câu hết giờ |
| `bridge/bridge.py` | Gateway nRF52840 qua USB serial ⇄ MQTT |
| `bridge/blecodec.py` | Định dạng byte trên sóng (tham chiếu cho firmware gateway) |
| `bridge/ble_scan.py` | (Tuỳ chọn, khi chưa có gateway) Bluetooth laptop ⇄ MQTT |
| `common/arscfg.py` | Đọc `config.ini`, kết nối DB, mã hoá bitmap, tạo client MQTT |
| `db/schema.sql`, `db/migrate_0XX_*.sql` | Cấu trúc CSDL và các bước nâng cấp |
| `deploy/` | Script cài Ubuntu, dịch vụ systemd, cấu hình Apache/Mosquitto, lệnh `ars` |
| `tools/` | Giả lập Remote (`sim_clickers.py`), công cụ dịch (`i18n_*.py`), chụp màn hình (`shot.sh`) |
| `firmware/` | Firmware Remote V3.10 + giao thức + quy trình test radio |
| `tests/` | Kiểm thử tự động (`run_local.sh` chạy tất cả) |
| `config.ini` | Cấu hình thật (mật khẩu DB, room…). **Không commit**; mẫu ở `config.ini.example` |

## 4. Luồng dữ liệu Remote → web

1. Remote phát gói quảng bá (Manufacturer Data, mã công ty `0xFFFF`, byte đầu `0xA5`).
2. Gateway giải mã (như `blecodec.decode_uplink()`) thành 1 dòng JSON `{mac, room, type, id, q, seq, ans, batt, rssi}` qua USB; `bridge.py` thêm `via: "ble"` và `ts`, rồi publish `ars/<room>/resp`.
3. `ingest.py` → `Ingest.process()` kiểm tra lần lượt: định dạng → ghi `devices` → có buổi đang chạy với room này không → `via` phải là `ble` → LOGIN/ANSWER (ghế có trong lớp, ghép MAC, câu đang mở, hạn giờ, trùng seq…). Gói bị loại ghi vào `rejected` với lý do.
4. Gói hợp lệ ghi `session_logins` / `responses`. VIEW `final_answers` lấy đáp án cuối của mỗi ghế.
5. Phát trạng thái: `Ingest.build_state()` → `state_cmds()` tạo 4 lệnh `STATE / ATTEND / OPENMASK / ACKS` → `ars/<room>/cmd/gw` và bản giữ lại `ars/<room>/state`.
6. `bridge.py` ghi nguyên từng lệnh xuống gateway; gateway đổi mỗi lệnh thành 1 gói beacon (như `blecodec.encode_cmd()`) và phát, ưu tiên ACKS. (Chế độ Bluetooth laptop: `ble_scan.py` làm việc này qua BlueZ.) Remote bật đèn khi thấy bản ghi khớp đúng giao dịch vừa gửi.
7. Web: `session.php` + `assets/control.js` gọi `api/state.php` mỗi giây để vẽ lại bảng điều khiển. Giáo viên bấm nút → `api/action.php` ghi DB và tăng `state_rev`.

Thứ tự kiểm tra và mã lý do loại: README mục 3.3. Định dạng byte: README mục 4.1 và docstring đầu `bridge/blecodec.py`.

## 5. Phần web PHP: quy ước

**Khung một trang giáo viên:**
```php
<?php
// Mô tả ngắn trang làm gì (dòng 2 của mọi file).
require __DIR__ . '/lib/bootstrap.php';
$t = require_login();                         // chuyển về đăng nhập nếu chưa đăng nhập
$c = own_class((int)($_GET['id'] ?? 0));      // 404 nếu lớp không thuộc giáo viên này
if ($_SERVER['REQUEST_METHOD'] === 'POST') {  // CSRF đã được kiểm tra tự động trong bootstrap
    q('UPDATE classes SET name=? WHERE id=?', [trim($_POST['name']), $c['id']]);
    flash(t('Đã lưu.'));
    redirect('class.php?id=' . $c['id']);     // Post/Redirect/Get
}
page_head(t('Lớp học'), 'classes.php');       // tham số 2 = mục menu đang chọn
?>
<form method="post"><?= csrf_field() ?><input name="name" value="<?= h($c['name']) ?>"><button><?= te('Lưu') ?></button></form>
<?php page_foot();
```

**Hàm dùng chung (`lib/bootstrap.php`):**

| Hàm | Dùng để |
|---|---|
| `q($sql, $args)` | Chạy câu SQL có tham số, trả `PDOStatement` |
| `q1()` / `qall()` / `qv()` | Lấy một dòng / mọi dòng / một giá trị |
| `h($s)` | Escape HTML. **Mọi dữ liệu người dùng in ra HTML phải qua `h()`** (hoặc `te()`) |
| `t($vi, $vars)` / `te()` | Dịch chuỗi (xem mục 7); `te` = `h(t(...))` |
| `csrf_field()` | Thẻ ẩn CSRF, bắt buộc trong mọi `<form method="post">` |
| `flash($msg, 'ok'\|'bad')`, `redirect($url)` | Thông báo sau khi chuyển trang |
| `require_login()`, `is_admin()`, `me()` | Giáo viên đăng nhập / quyền quản trị |
| `own_class($id)`, `own_set($id)` | Lấy lớp / bộ câu hỏi **và kiểm tra quyền sở hữu** |
| `json_out($data, $code)` | Trả JSON cho `api/` |
| `cfg()`, `setting($k)`, `setting_set($k, $v)` | Đọc `config.ini` / bảng `settings` |
| `av($file)` | Gắn phiên bản vào URL CSS/JS để trình duyệt không dùng bản cũ |

Phía học sinh dùng `lib/student.php` (`require_student()`, `student_head()`, `student_foot()`); phía phụ huynh dùng `lib/lms.php` (`require_parent()`, `parent_head()`).

**Phong cách:**
- Chú thích, thông báo và tên giao diện viết **tiếng Việt**. Chuỗi hiển thị viết tiếng Việt rồi dịch qua từ điển (mục 7).
- Không thêm framework hay thư viện ngoài khi chưa cần. Giao diện dùng lớp CSS sẵn có (`card`, `btn`, `tag`, `ui-*`) trong `assets/style.css`.
- Thêm CSS/JS mới vào `web/assets/` và luôn nhúng qua `av()`.

## 6. Cơ sở dữ liệu và migration

- `db/schema.sql` là cấu trúc gốc. Mỗi thay đổi về sau là **một file mới** `db/migrate_0XX_ten_ngan.sql` (số tăng dần, hiện tới `012`). **Không sửa migration đã phát hành.**
- Migration nên **chạy lại được an toàn** (dùng `IF NOT EXISTS`, `ADD COLUMN IF NOT EXISTS`…), vì script cài đặt chạy lại tất cả khi cập nhật.
- Khi thêm migration, thêm tên file vào:
  1. `deploy/install_ubuntu.sh` (danh sách migration),
  2. `tests/run_local.sh` (danh sách migration của CSDL test).
- Máy chủ đang chạy: `sudo mysql ars < db/migrate_0XX_….sql`.
- Thời gian nghiệp vụ (mở câu, hạn giờ, nhận gói) **luôn lấy `NOW()` của MariaDB**, không lấy giờ PHP/Python, để không lệch giờ giữa các tiến trình.

Các bảng chính của phần Remote: README mục 3.4. Xem nhanh dữ liệu thật: `ars tables`, `ars db`, `ars watch`.

## 7. Đa ngôn ngữ

- Ngôn ngữ gốc là **tiếng Việt**, bản dịch tiếng Anh ở `web/lang/en.php` (`'chuỗi Việt' => 'English'`).
- PHP: `t('Đã lưu {n} câu', ['n' => 5])`; JS: `T('…')`. Số trong khoá viết `{n}`.
- Khi trang ở tiếng Anh, `i18n_html()` (output buffer trong bootstrap) dịch toàn bộ HTML trước khi gửi; `assets/i18n.js` dịch phần JS chèn sau. Vì vậy **chuỗi Việt viết thẳng trong HTML cũng được dịch** nếu có trong từ điển.
- Tìm chuỗi chưa dịch:
  ```bash
  python3 tools/i18n_static.py > /tmp/missing.jsonl                           # quét mã nguồn
  KEEP=1 bash tests/run_local.sh                                              # máy chủ thử có dữ liệu mẫu
  EN=1 python3 tools/i18n_collect.py /tmp/ars-test.XXXX/smoke.json            # duyệt trang thật ở tiếng Anh
  ```
- Sau khi thêm chuỗi giao diện mới, thêm bản dịch vào cuối `en.php` (nhóm theo đợt, có dòng chú thích).

## 8. Trợ lý AI

- `web/lib/ai.php`: gọi nhà cung cấp (`offline` / `anthropic` / `gemini` / `openai`). Cấu hình (key, mô hình) lưu trong bảng `settings`, nhập ở Cài đặt. **Không bao giờ ghi API key vào code hay commit.**
  - `ai_call()` là điểm gọi chung.
  - `ai_http()` tự thử lại khi gặp lỗi 429/500/503/529.
  - Gemini dùng endpoint tương thích OpenAI, có danh sách mô hình dự phòng `GEMINI_FALLBACK`.
  - Kết quả JSON được ép theo schema (`ai_question_schema`); nếu mô hình trả JSON lỗi (thường do LaTeX), `ai_fix_json()` sửa lại.
- `web/lib/ai_offline.php`: chế độ "Cơ bản" không cần mạng.
- `web/lib/tutor.php` + `student/api_tutor.php`: trợ lý học sinh "Cú Học" (nhiều cuộc trò chuyện, bảng `tutor_chats`); bị khoá khi học sinh đang làm bài kiểm tra.
- `web/api/ai.php`: soạn câu hỏi và hỏi đáp cho giáo viên (trang `compose.php`).

## 9. Bluetooth và firmware Remote

- Phần cứng: Remote nRF52840 SuperMini (thiết kế mạch KiCad Rev J để riêng ngoài repo). Firmware Remote: `firmware/ARS_remote_v3_10/` (Arduino, core Adafruit nRF52). Bộ thu là một nRF52840 thứ hai làm gateway cắm USB; firmware `firmware/ARS_gateway_v3_10/` (SuperMini; `gw_codec.cpp` là bản C++ của `blecodec.py`, `gw_radio.cpp` quét + phát xoay vòng beacon), giao tiếp serial ở README mục 4.2b.
- Hợp đồng giữa firmware và server là định dạng byte trong `bridge/blecodec.py`:
  - Remote → gateway 9 byte: `A5, type, room, ghế, câu, seq, đáp án, pin(2 byte LE)`.
  - Gateway → Remote 4 loại beacon `A5 10..13`; ACK/NACK **chính xác** `(ghế, câu, seq, đáp án, status)`. Chi tiết và lịch radio: `firmware/PROTOCOL_V3_10.md`.
  - **Đổi định dạng thì sửa đồng bộ firmware Remote + firmware gateway + `blecodec.py` + `ble_scan.py` + `ingest.py` + tài liệu giao thức**, và test `tests/ingest_integration.py`, `tests/ble_scan_unit.py`.
- Firmware V3.10: một đợt quảng bá non-connectable 150 ms rồi nghe ACK liên tục 1 s; chưa có thì nghỉ ngẫu nhiên tăng dần và gửi lại CÙNG seq (tối đa 4 lần); địa chỉ BLE random static cố định; số ghế/room nhập bằng MODE, lưu flash. Chân P0.09/P0.10 (hàng phím ROW4/ROW3) là chân NFC, dùng như GPIO.
- Đo độ tin cậy trên máy thật: quy trình và tiêu chí PASS/FAIL ở `firmware/TEST_V3_10.md`; `tools/ble_txn_report.py` phân loại từng giao dịch từ log serial + journalctl.
- Kiểm tra trên máy thật: `sudo ars ble` in mọi gói quảng bá nghe được; dòng `→ ARS {...}` là gói đúng định dạng.
- Phát beacon chỉ chạy trên Linux/BlueZ (đối tượng D-Bus `org.bluez.LEAdvertisement1`, kiểu `broadcast`). Windows/macOS chỉ nghe được.
- Không có Remote: `ars sim 30 1` hoặc `python3 tools/sim_clickers.py --room 1 -n 30` giả lập Remote ở tầng MQTT.

## 10. Chạy thử và kiểm thử

```bash
bash tests/run_local.sh          # dựng MariaDB tạm (cổng 3407) + PHP built-in (8091), chạy mọi bộ test, dọn sạch
KEEP=1 bash tests/run_local.sh   # giữ máy chủ thử chạy để mở http://127.0.0.1:8091/ bằng trình duyệt
```
Cần `mariadb-server`, `php-cli`, `python3-requests`, `python3-bs4`, `python3-pymysql`. Test **không đụng CSDL thật**.

Kết quả mong đợi: mỗi bộ in số kiểm tra đạt, dòng cuối **`Cảnh báo PHP: 0`**.

| Bộ test | Kiểm tra |
|---|---|
| `http_smoke.py` | Mọi trang mở được, đăng nhập, quyền truy cập; tạo dữ liệu mẫu (`smoke.json`) cho các bộ sau |
| `ingest_integration.py` | Luật nhận gói của ingest, ACK/NACK chính xác, nhiều ghế cùng lúc, hạn ACK từng ghế, giải mã gói BLE |
| `gateway_codec_test.py` | Biên dịch `gw_codec.cpp` bằng g++, so từng byte với `blecodec.py` (lệnh ingest, biên, ngẫu nhiên), JSON gói Remote, lọc bản lặp |
| `ble_scan_unit.py` | `ble_scan.Advertiser` với D-Bus giả: gộp ACK, giới hạn dừng quét, ưu tiên ACK |
| `extended_integration.py`, `v11_integration.py` | Bài tập, phụ huynh, trường, Cloud, thi đua, Zalo/SMS (giả lập) |
| `oauth_integration.py`, `student_signup_integration.py` | Đăng nhập Google, học sinh tự đăng ký |
| `i18n_integration.py` | Giao diện tiếng Anh không sót tiếng Việt ở các trang chính |
| `tutor_integration.py` | Trợ lý học sinh |

**Quy tắc:** chạy `bash tests/run_local.sh` trước mỗi commit. Thêm tính năng thì thêm kiểm tra vào bộ test gần nhất.

## 11. Triển khai và vận hành

```bash
sudo bash deploy/install_ubuntu.sh   # cài mới hoặc cập nhật (giữ config.ini và dữ liệu, chạy migration)
```
Cập nhật nhanh khi chỉ đổi code:
```bash
sudo rsync -a --delete --exclude config.ini --exclude .gateway-ble --exclude .gateway-gatt --exclude .git --exclude '__pycache__' ./ /opt/ars-server/
sudo systemctl restart apache2 ars-ingest ars-bridge
```

| Dịch vụ | Chạy | Ghi chú |
|---|---|---|
| `ars-ingest` | `ingest/ingest.py` | Luôn chạy |
| `ars-bridge` | `bridge/bridge.py` | Gateway nRF52840 cắm USB (mặc định) |
| `ars-ble` | `bridge/ble_scan.py` | Bluetooth laptop, tắt sẵn; đổi bằng `sudo ars gateway ble\|usb` |
| `ars-mdns` | `deploy/ars-mdns.sh` | Tên `ars.local` trong mạng LAN |

Lệnh hay dùng: `ars status`, `ars logs`, `ars watch`, `ars mqtt`, `ars ble`, `ars db`, `ars backup`, `sudo ars public on` (link Internet qua Tailscale Funnel). Xem đủ: `ars` không tham số.

Log lỗi web: `sudo tail -50 /var/log/apache2/error.log`.

## 12. Công thức cho các việc hay gặp

**Thêm một trang giáo viên mới**
1. Tạo `web/ten_trang.php` theo khung ở mục 5.
2. Thêm vào menu: mảng `NAV` hoặc `NAV_MORE` trong `lib/bootstrap.php`.
3. Thêm bản dịch tiếng Anh cho chuỗi mới vào `web/lang/en.php`.
4. Thêm kiểm tra mở trang / đăng nhập / quyền vào `tests/http_smoke.py`.

**Thêm cột / bảng**
Tạo `db/migrate_013_….sql` (chạy lại được), thêm vào `install_ubuntu.sh` và `tests/run_local.sh`, rồi chạy test.

**Thêm một luật kiểm tra gói Remote**
Sửa `Ingest.process()` (hoặc `login()` / `answer()`) trong `ingest/ingest.py`, gọi `self.reject(..., 'ma_ly_do', raw)`. Thêm nhãn tiếng Việt của lý do vào `REJECT_REASONS` (`lib/bootstrap.php`) và `REASON` (`assets/control.js`), mã NACK vào `REJECT_STATUS` (`ingest.py`) và `resultStatusName()` (firmware), thêm test vào `tests/ingest_integration.py`, cập nhật bảng ở README mục 3.3.

**Đổi định dạng gói Bluetooth theo firmware**
Sửa `bridge/blecodec.py` (`decode_uplink`, `encode_cmd`), cập nhật test BLE trong `tests/ingest_integration.py` và README mục 4.1.

**Thêm nhà cung cấp AI**
Thêm nhánh trong `ai_call()` (`lib/ai.php`), thêm lựa chọn trong `settings.php`, giữ đầu ra cùng dạng mảng câu hỏi để `compose.js` dùng lại được.

## 13. Những điều không được làm

- Không commit `config.ini`, API key, mật khẩu, dữ liệu học sinh thật.
- Không in dữ liệu người dùng ra HTML mà không qua `h()` / `te()`.
- Không tạo form POST thiếu `csrf_field()`; endpoint không dùng phiên (token header) mới được bỏ CSRF bằng `ARS_TOKEN_API`.
- Không truy vấn lớp / bộ câu hỏi theo `id` từ URL mà không qua `own_class()` / `own_set()` (giáo viên này sẽ xem được dữ liệu giáo viên khác).
- Không ghép chuỗi SQL với dữ liệu người dùng; luôn dùng tham số `?`.
- Không để web gửi MQTT trực tiếp; chỉ ghi DB và tăng `state_rev`.
- Không sửa migration cũ đã phát hành; luôn tạo file mới.
- Không mở cổng MQTT 1883 ra mạng LAN.
