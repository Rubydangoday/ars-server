# ARS Remote V3.11 GATT — chế độ 1 nRF (Remote kết nối BLE với laptop)

Bản V3.11 GATT của nhóm (`ARS_project_single_remote_current-1`), đưa vào repo làm chế độ `sudo ars gateway gatt`.
Hướng dẫn chạy và đo: [../../HUONG_DAN_1NRF_GATT.md](../../HUONG_DAN_1NRF_GATT.md). Giao thức: [../GATT_PROTOCOL_V3_11.md](../GATT_PROTOCOL_V3_11.md).

Sửa so với bản gốc (repo): tự kiểm tra ngay khi bật nguồn, chỉ chờ Serial khi cắm USB · seq ban đầu từ bộ tạo số ngẫu
nhiên phần cứng (bản gốc: mỗi lần reset ra cùng seq) · log Serial cùng định dạng với Remote V3.11 quảng bá
(`TX ANSWER …`, `ACK … dt=…ms attempts=…`) để `tools/ble_txn_report.py` dùng chung · mã lý do 15 `SEQ_REUSED`.

Open `ARS_remote_gatt.ino` with every file in this folder present. Use the same
board/bootloader that worked on the physical SuperMini with V3.10.
Do not copy an advertising-only `ars_ble.cpp` into this folder.

- Existing physical GPIO, keypad mapping, flash room/seat identity are preserved.
- NUS service; peripheral TX Notify, peripheral RX Write. All single-remote
  framed packets are at most 20 bytes, so no MTU tuning is required.
- Requested connection interval 15–30 ms; final interval is central-dependent.
- HELLO readiness heartbeat; exact LOGIN and ANSWER ACK/NACK from backend.
- Up to four sends with same sequence within a connection; disconnect cancels
  pending answer with an unknown outcome, and requires fresh LOGIN on reconnect.
- UI labels: r / Id / n / An / bt / LG / Er, plus moving dashes while waiting.
- Battery value is 0/unknown until actual ADC measurement is implemented.
- Seven-segment refresh is polled without intentional BLE burst delays.
- No pairing/encryption policy is configured in this demo; deployment security is
  a separate task. Only one central can connect; close phone BLE applications.
