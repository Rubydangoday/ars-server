"""blecodec.py — định dạng byte trên sóng BLE giữa Remote nRF52840 và laptop (ble_scan.py). Không phụ thuộc thư viện ngoài.

Mọi gói nằm trong Manufacturer Specific Data (AD type 0xFF) của gói quảng bá, mã công ty COMPANY_ID (mặc định 0xFFFF = mã
dành cho thử nghiệm của Bluetooth SIG), byte đầu là MAGIC để không lẫn với thiết bị khác.

Remote → laptop (9 byte):
    [0] 0xA5  [1] type 1=LOGIN 2=ANSWER  [2] room  [3] ghế (id)  [4] câu q (LOGIN: 0)  [5] seq
    [6] đáp án 0 = không có, 1..10 = A..J (hoặc mã ASCII 'A'..'J')   [7..8] pin mV, uint16 little-endian (0 = không đo)

Laptop → Remote (beacon; ACKS ưu tiên, STATE/ATTEND/OPENMASK phát thành burst ngắn — xem ble_scan.py):
    STATE    [0xA5, 0x10, room, qid, open 0/1, n_options] + bitmap ghế đã trả lời câu qid (13 byte)
    ATTEND   [0xA5, 0x11, room] + bitmap ghế đã điểm danh (13 byte)
    OPENMASK [0xA5, 0x12, room] + mặt nạ câu đang mở (8 byte, câu p ở bit p-1)
    ACKS     [0xA5, 0x13, room, n] + n bản ghi (ghế, câu, seq, đáp_án, status), n ≤ 4 (giữ 4 bản ghi MỚI nhất)
             đáp_án: 1..10 = A..J. status 0=OK, >0=NACK. Kết quả khớp đúng một lần bấm OK.
Bitmap: ghế n ở byte n // 8, bit n % 8, bit thấp trước (giống common/arscfg.py → bitmap_hex).
"""
MAGIC = 0xA5
COMPANY_ID = 0xFFFF
PKT_LOGIN, PKT_ANSWER = 1, 2
F_STATE, F_ATTEND, F_OPENMASK, F_ACKS = 0x10, 0x11, 0x12, 0x13
FRAME_NAMES = {'STATE': F_STATE, 'ATTEND': F_ATTEND, 'OPENMASK': F_OPENMASK, 'ACKS': F_ACKS}
LETTERS = 'ABCDEFGHIJ'
MAX_ACK_RECORDS = 4          # 4 + 4×5 = 24 byte: vừa một gói quảng bá legacy (31 byte)


def decode_uplink(data, mac, rssi=None):
    """Bytes manufacturer data của Remote → dict giống dòng JSON của gateway USB (README mục 4.2). Không phải gói ARS → None."""
    data = bytes(data)
    if len(data) < 7 or data[0] != MAGIC or data[1] not in (PKT_LOGIN, PKT_ANSWER):
        return None
    a = data[6]
    if 1 <= a <= 10:
        ans = LETTERS[a - 1]
    elif ord('A') <= a <= ord('J'):
        ans = chr(a)
    else:
        ans = ''
    batt = int.from_bytes(data[7:9], 'little') if len(data) >= 9 else 0
    return {'mac': str(mac).upper(), 'room': data[2], 'type': data[1], 'id': data[3], 'q': data[4], 'seq': data[5],
            'ans': ans if data[1] == PKT_ANSWER else '', 'batt': batt or None, 'rssi': rssi}


def encode_uplink(ptype, room, seat, q, seq, ans='', batt=0):
    """Ngược lại của decode_uplink — dùng cho thử nghiệm và làm mẫu cho firmware Remote."""
    a = LETTERS.index(ans.upper()) + 1 if ans else 0
    return bytes([MAGIC, ptype, room & 0xFF, seat & 0xFF, q & 0xFF, seq & 0xFF, a]) + int(batt).to_bytes(2, 'little')


def encode_cmd(line, room):
    """Một dòng lệnh của ingest (STATE / ATTEND / OPENMASK / ACKS) → (loại gói, bytes beacon). Lệnh lạ → None."""
    p = line.split()
    if not p or p[0] not in FRAME_NAMES:
        return None
    try:
        if p[0] == 'STATE':
            r, qid, op = int(p[1]), int(p[2]), int(p[3])
            # V3.8 adds n_options after open. Accept the V3.7 command too so a
            # retained/older producer cannot break the bridge during upgrade.
            if len(p) >= 6:
                n_options = max(0, min(10, int(p[4])))
                bitmap_hex = p[5]
            else:
                n_options = 0
                bitmap_hex = p[4]
            return F_STATE, bytes([MAGIC, F_STATE, r & 0xFF, qid & 0xFF, 1 if op else 0, n_options & 0xFF]) + bytes.fromhex(bitmap_hex)[:13].ljust(13, b'\0')
        if p[0] == 'ATTEND':
            return F_ATTEND, bytes([MAGIC, F_ATTEND, room & 0xFF]) + bytes.fromhex(p[1])[:13].ljust(13, b'\0')
        if p[0] == 'OPENMASK':
            return F_OPENMASK, bytes([MAGIC, F_OPENMASK, room & 0xFF]) + bytes.fromhex(p[1])[:8].ljust(8, b'\0')
        # ACKS exact result: seat:question:seq:answer_code:status (status 0=OK, >0=NACK).
        # A legacy 4-field record is accepted as status=0. One legacy advertisement
        # holds at most MAX_ACK_RECORDS 5-byte records; ingest lists oldest -> newest,
        # so keep the NEWEST ones (V3.9 kept the oldest and dropped the answer just made).
        records = []
        if len(p) >= 2 and p[1] != '-':
            for item in p[1].split(','):
                fields = item.split(':')
                if len(fields) == 4:
                    fields.append('0')
                if len(fields) != 5:
                    continue
                records.append(tuple(int(x) & 0xFF for x in fields))
        records = records[-MAX_ACK_RECORDS:]
        return F_ACKS, bytes([MAGIC, F_ACKS, room & 0xFF, len(records)]) + b''.join(bytes(rec) for rec in records)
    except (IndexError, ValueError):
        return None
