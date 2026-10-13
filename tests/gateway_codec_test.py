"""Firmware gateway (firmware/ARS_gateway_v3_10/gw_codec.cpp) phải cho ra ĐÚNG từng byte như bridge/blecodec.py.

Biên dịch gw_codec.cpp + tests/gateway_codec_harness.cpp bằng g++ (cờ -Wall -Wextra -Werror), rồi so:
  - lệnh STATE / ATTEND / OPENMASK / ACKS → beacon  (blecodec.encode_cmd), gồm lệnh do ingest.state_cmds() tạo,
    các trường hợp biên và hàng nghìn dòng ngẫu nhiên;
  - gói Remote → dòng JSON  (blecodec.decode_uplink);
  - lọc bản lặp 300 ms.
Không cần phần cứng. Chạy: python3 tests/gateway_codec_test.py [smoke.json bỏ qua]
"""
import json
import os
import random
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
FW = ROOT / 'firmware' / 'ARS_gateway_v3_10'
sys.path.insert(0, str(ROOT / 'bridge'))
sys.path.insert(0, str(ROOT / 'common'))
import blecodec  # noqa: E402

checks = []


def check(x, msg):
    assert x, msg
    checks.append(msg)


cxx = shutil.which('g++') or shutil.which('clang++')
if not cxx:
    print(json.dumps({'passed': 0, 'skipped': 'không có g++/clang++'}, ensure_ascii=False))
    sys.exit(0)

tmp = tempfile.mkdtemp(prefix='ars_gw_')
exe = os.path.join(tmp, 'gw_codec_harness')
subprocess.run([cxx, '-std=c++11', '-O1', '-Wall', '-Wextra', '-Werror', f'-I{FW}',
                str(FW / 'gw_codec.cpp'), str(ROOT / 'tests' / 'gateway_codec_harness.cpp'), '-o', exe], check=True)
check(True, 'gw_codec.cpp compiles with -Wall -Wextra -Werror')
proc = subprocess.Popen([exe], stdin=subprocess.PIPE, stdout=subprocess.PIPE, text=True, bufsize=1)


def ask(req):
    proc.stdin.write(req + '\n')
    proc.stdin.flush()
    return proc.stdout.readline().rstrip('\n')


KIND = {blecodec.F_STATE: 0, blecodec.F_ATTEND: 1, blecodec.F_OPENMASK: 2, blecodec.F_ACKS: 3}


def same_cmd(line, room):
    exp = blecodec.encode_cmd(line, room)
    got = ask(f'C {room}\t{line}')
    if exp is None:
        return got == '-1', (line, room, None, got)
    kind, data = exp
    want = f'{KIND[kind]} {data[2] if kind == blecodec.F_STATE else 0} {data.hex()}'
    return got == want, (line, room, want, got)


def all_same(lines, msg):
    bad = []
    for line, room in lines:
        ok, info = same_cmd(line, room)
        if not ok:
            bad.append(info)
    if bad:
        print('MISMATCH', bad[:5], file=sys.stderr)
    check(not bad, f'{msg} ({len(lines)} dòng)')


# ---- 1. lệnh thật do ingest tạo ----
import arscfg  # noqa: E402

sys.modules.setdefault('pymysql', type(sys)('pymysql'))          # ingest import pymysql/paho; state_cmds không dùng
sys.path.insert(0, str(ROOT / 'ingest'))
try:
    from ingest import state_cmds                                   # noqa: E402
except Exception:                                                   # thiếu paho trên máy test: chép lại công thức
    def state_cmds(room, st):
        return [f"STATE {room} {st['qid']} {st['open']} {st['n_options']} {arscfg.bitmap_hex(st['answered'])}",
                f"ATTEND {arscfg.bitmap_hex(st['attend'])}",
                f"OPENMASK {arscfg.mask64_hex(st['open_positions'])}",
                "ACKS " + (','.join(f'{s}:{q}:{sq}:{a}:{x}' for s, q, sq, a, x in st['acks']) or '-')]

rnd = random.Random(310)
real = []
for _ in range(400):
    room = rnd.randint(0, 255)
    st = {'qid': rnd.randint(0, 64), 'open': rnd.randint(0, 1), 'n_options': rnd.randint(0, 10),
          'answered': rnd.sample(range(1, 100), rnd.randint(0, 40)), 'attend': rnd.sample(range(1, 100), rnd.randint(0, 99)),
          'open_positions': rnd.sample(range(1, 65), rnd.randint(0, 10)),
          'acks': [(rnd.randint(1, 99), rnd.randint(1, 64), rnd.randint(0, 255), rnd.randint(1, 10), rnd.choice([0, 0, 0, 1, 7, 14, 255]))
                   for _ in range(rnd.randint(0, 6))]}
    real += [(line, room) for line in state_cmds(room, st)]
all_same(real, 'Commands produced by ingest.state_cmds encode identically to blecodec')

# ---- 2. trường hợp biên ----
edge = ['STATE 1 5 1 4 1E000000000000000000000000', 'STATE 1 5 1 1E000000000000000000000000', 'STATE 1 5 1',
        'STATE 300 -1 2 99 ff', 'STATE 1 5 0 -3 ', 'STATE 1 5 1 4 1e', 'STATE 1 5 1 4 1', 'STATE 1 5 1 4 zz',
        'STATE 1 5 1 4 ' + 'ab' * 20, 'STATE a 5 1 4 00', 'STATE +7 05 -0 4 00', 'STATE 1 5 1 4 00 extra',
        'ATTEND', 'ATTEND -', 'ATTEND ' + 'ff' * 13, 'ATTEND ' + 'ff' * 20, 'ATTEND fff', 'ATTEND ',
        'OPENMASK 0100000000000000', 'OPENMASK 01', 'OPENMASK ' + '11' * 9,
        'ACKS', 'ACKS -', 'ACKS 7:3:12:2:0', 'ACKS 7:3:12:2', 'ACKS 1:1:1:1:0,2:2:2:2:0,3:3:3:3:0,4:4:4:4:0,5:5:5:5:0,6:6:6:6:9',
        'ACKS 7:3:12:2:0,', 'ACKS ,7:3:12:2:0', 'ACKS 7:3:12', 'ACKS 7:3:12:2:0:9', 'ACKS 7:3:x:2:0', 'ACKS 7::12:2:0',
        'ACKS 300:-1:256:2:1000', 'ACKS 1:2:3:4:5 6:7:8:9:0', 'ACKS -,1:1:1:1:1', 'ACKS ,,,', 'ACKS 1:2,3:4:5:6:7',
        '  STATE   1  5 1 4 00  ', 'STATE\t1\t5\t1\t4\t00', 'state 1 5 1 4 00', 'STATEX 1 5 1 4 00', 'ACK 1:1:1:1:0', '', '   ', 'GARBAGE']
all_same([(line, room) for line in edge for room in (0, 1, 77, 255)], 'Edge-case commands (bad hex, legacy STATE, 4-field ACK, >4 records…) match')

# ---- 3. dòng ngẫu nhiên ----
words = ['STATE', 'ATTEND', 'OPENMASK', 'ACKS', '-', '0', '1', '10', '11', '255', '256', '-1', '+3', '00', 'ff', '1e', 'abc',
         'zz', '1:2:3:4:0', '1:2:3:4', '9:9:9:9:9,1:1:1:1:1', 'ffffffffffffffffffffffffffffffff', '', 'x:y']
fuzz = []
for _ in range(4000):
    head = rnd.choice(['STATE', 'ATTEND', 'OPENMASK', 'ACKS'])
    if rnd.random() < 0.5 and head == 'ACKS':
        recs = []
        for _ in range(rnd.randint(0, 7)):
            nf = rnd.choice([3, 4, 5, 5, 5, 6])
            recs.append(':'.join(rnd.choice(['0', '1', '7', '99', '255', '256', '-1', '1000', '', 'a']) if rnd.random() < 0.1
                                 else str(rnd.randint(0, 300)) for _ in range(nf)))
        line = 'ACKS ' + ','.join(recs)
    else:
        line = ' '.join([head] + [rnd.choice(words) for _ in range(rnd.randint(0, 6))])
    fuzz.append((line, rnd.randint(0, 255)))
all_same(fuzz, 'Random command lines match blecodec')

# ---- 4. gói Remote → JSON ----
bad = []
n_ok = 0
for i in range(3000):
    n = rnd.choice([0, 3, 6, 7, 8, 9, 9, 9, 9, 12])
    data = bytearray(rnd.getrandbits(8) for _ in range(n))
    if n >= 2 and rnd.random() < 0.85:
        data[0] = 0xA5
        data[1] = rnd.choice([1, 2, 2, 2, 3, 0, 0x10])
    if n >= 7 and rnd.random() < 0.7:
        data[6] = rnd.choice([0, 1, 5, 10, 11, ord('A'), ord('J'), ord('K'), 255])
    if n >= 9 and rnd.random() < 0.2:
        data[7] = data[8] = 0
    mac = ':'.join(f'{rnd.getrandbits(8):02X}' for _ in range(6))
    rssi = rnd.randint(-110, 5)
    exp = blecodec.decode_uplink(bytes(data), mac, rssi)
    got = ask(f'U {mac.replace(":", "")} {rssi} {bytes(data).hex() or "-"}')
    if exp is None:
        ok = got == 'NONE'
    else:
        ok = got != 'NONE' and json.loads(got) == exp
        n_ok += ok
    if not ok:
        bad.append((bytes(data).hex(), mac, rssi, exp, got))
if bad:
    print('MISMATCH', bad[:5], file=sys.stderr)
check(not bad and n_ok > 500, f'Uplink JSON equals blecodec.decode_uplink (3000 packets, {n_ok} ARS)')
ex = blecodec.encode_uplink(2, 1, 7, 3, 12, 'B', 2950)
check(ask(f'U C41A00112233 -61 {ex.hex()}') ==
      '{"mac":"C4:1A:00:11:22:33","room":1,"type":2,"id":7,"q":3,"seq":12,"ans":"B","batt":2950,"rssi":-61}',
      'README 4.2 example line is produced verbatim')

# ---- 5. lọc bản lặp ----
p = blecodec.encode_uplink(2, 1, 7, 3, 12, 'B', 2950).hex()
p2 = blecodec.encode_uplink(2, 1, 7, 3, 13, 'B', 2950).hex()
m1, m2 = 'C41A00112233', 'C41A00112234'
seq = [(1000, m1, p, '0'), (1025, m1, p, '1'), (1150, m1, p, '1'), (1299, m1, p, '1'), (1030, m2, p, '0'),
       (1040, m1, p2, '0'), (1300, m1, p, '0'), (2400, m1, p, '0'), (2450, m1, p, '1')]
check(all(ask(f'D {t} {m} {d}') == want for t, m, d, want in seq),
      'Dedup: copies within 300 ms dropped; other MAC / new seq / retry after ≥300 ms forwarded')
wrap = 2 ** 32 - 100
check(ask(f'D {wrap} {m1} {p}') == '0' and ask(f'D {(wrap + 150) % 2 ** 32} {m1} {p}') == '1'
      and ask(f'D {(wrap + 400) % 2 ** 32} {m1} {p}') == '0', 'Dedup survives millis() wrap-around')
burst = [ask(f'D {50000 + k} {i:012X} {p}') for i in range(60) for k in (0, 20, 40, 60, 80, 100)]
check(burst.count('0') == 60 and burst.count('1') == 300, '60 Remotes × 6 copies in one burst → exactly 60 lines to USB')

proc.stdin.close()
proc.wait()
shutil.rmtree(tmp, ignore_errors=True)
print(json.dumps({'passed': len(checks), 'checks': checks}, ensure_ascii=False, indent=2))
