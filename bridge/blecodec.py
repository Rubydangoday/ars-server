"""blecodec.py — định dạng byte trên sóng BLE giữa Remote nRF52840 và laptop (ble_scan.py). Không phụ thuộc thư viện ngoài.

Mọi gói nằm trong Manufacturer Specific Data (AD type 0xFF) của gói quảng bá, mã công ty COMPANY_ID (mặc định 0xFFFF = mã
dành cho thử nghiệm của Bluetooth SIG), byte đầu là MAGIC để không lẫn với thiết bị khác.

Remote → laptop (9 byte):
    [0] 0xA5  [1] type 1=LOGIN 2=ANSWER  [2] room  [3] ghế (id)  [4] câu q (LOGIN: 0)  [5] seq
    [6] đáp án 0 = không có, 1..10 = A..J (hoặc mã ASCII 'A'..'J')   [7..8] pin mV, uint16 little-endian (0 = không đo)

Laptop → Remote (beacon, 4 gói quảng bá phát song song, mỗi gói một loại):
    STATE    [0xA5, 0x10, room, qid, open 0/1] + bitmap ghế đã trả lời câu qid (13 byte)
    ATTEND   [0xA5, 0x11, room] + bitmap ghế đã điểm danh (13 byte)
    OPENMASK [0xA5, 0x12, room] + mặt nạ câu đang mở (8 byte, câu p ở bit p-1)
    ACKS     [0xA5, 0x13, room, n] + n cặp (ghế, câu), n ≤ 6
Bitmap: ghế n ở byte n // 8, bit n % 8, bit thấp trước (giống common/arscfg.py → bitmap_hex).
"""
MAGIC = 0xA5
COMPANY_ID = 0xFFFF
PKT_LOGIN, PKT_ANSWER = 1, 2
F_STATE, F_ATTEND, F_OPENMASK, F_ACKS = 0x10, 0x11, 0x12, 0x13
FRAME_NAMES = {'STATE': F_STATE, 'ATTEND': F_ATTEND, 'OPENMASK': F_OPENMASK, 'ACKS': F_ACKS}
LETTERS = 'ABCDEFGHIJ'


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
            return F_STATE, bytes([MAGIC, F_STATE, r & 0xFF, qid & 0xFF, 1 if op else 0]) + bytes.fromhex(p[4])[:13].ljust(13, b'\0')
        if p[0] == 'ATTEND':
            return F_ATTEND, bytes([MAGIC, F_ATTEND, room & 0xFF]) + bytes.fromhex(p[1])[:13].ljust(13, b'\0')
        if p[0] == 'OPENMASK':
            return F_OPENMASK, bytes([MAGIC, F_OPENMASK, room & 0xFF]) + bytes.fromhex(p[1])[:8].ljust(8, b'\0')
        pairs = [] if len(p) < 2 or p[1] == '-' else [tuple(int(x) & 0xFF for x in it.split(':')) for it in p[1].split(',') if ':' in it]
        pairs = pairs[:6]
        return F_ACKS, bytes([MAGIC, F_ACKS, room & 0xFF, len(pairs)]) + b''.join(bytes(pr) for pr in pairs)
    except (IndexError, ValueError):
        return None
