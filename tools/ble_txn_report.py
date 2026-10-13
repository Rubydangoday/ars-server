#!/usr/bin/env python3
"""Phân loại từng giao dịch (ANSWER và LOGIN) của một Remote từ log Serial + log server; đo tốc độ.

  journalctl -u ars-ingest -u ars-bridge --since "10 min ago" > server.log     (chế độ Bluetooth laptop: -u ars-ble)
  python3 tools/ble_txn_report.py serial.log server.log [--seat 2]

Mỗi giao dịch (loại + seq) trong log Serial được xếp vào một loại:
  ACK_FIRST   xác nhận ở lần gửi đầu          ACK_RETRY   xác nhận sau khi gửi lại
  NACK        server từ chối có lý do           UPLINK_MISS hết giờ, server không hề thấy seq này
  ACK_MISS    hết giờ dù server đã thấy seq này (Remote bỏ lỡ ACK)        ADV_FAIL  Remote không phát được
Độ trễ: Remote V3.11 in "dt=…ms" (lần gửi đầu → kết quả). In trung vị, P95, lớn nhất.

Đo gói lên (Remote Serial 'u' = 100 gói PROBE; gateway in "PROBE seq=…" vào journal ars-bridge):
  python3 tools/ble_txn_report.py serial.log server.log --probe

Remote chạy pin, không có log Serial — chỉ dùng log server, tự đếm số lần bấm OK:
  python3 tools/ble_txn_report.py --server-only server.log --presses 50 --seat 2
  Gateway đã bỏ bản lặp trong một đợt phát, nên mỗi dòng TRÙNG của một seq ≈ một lần Remote PHẢI GỬI LẠI
  (không nghe được ACK lần đầu). Không đo được độ trễ và lần gửi đầu bị mất hẳn (server chỉ thấy lần gửi lại).
"""
import argparse
import re
from collections import OrderedDict

ap = argparse.ArgumentParser()
ap.add_argument('logs', nargs='+', help='serial.log server.log  (--server-only: chỉ server.log)')
ap.add_argument('--seat', type=int, default=None, help='chỉ xét ghế này trong log server')
ap.add_argument('--probe', action='store_true', help='đo tỉ lệ gói PROBE gateway nghe được')
ap.add_argument('--server-only', action='store_true', help='không có log Serial của Remote (Remote chạy pin)')
ap.add_argument('--presses', type=int, default=None, help='--server-only: số lần bấm OK (ANSWER) đã làm')
a = ap.parse_args()
if a.server_only:
    a.serial, a.server = None, a.logs[0]
elif len(a.logs) == 2:
    a.serial, a.server = a.logs
else:
    ap.error('cần 2 file: serial.log server.log (hoặc --server-only server.log)')


def pct(n, d):
    return f'{n}/{d} = {n / d:.1%}' if d else '0/0'


def quantile(v, q):
    v = sorted(v)
    return v[min(len(v) - 1, int(round(q * (len(v) - 1))))] if v else None


if a.server_only:
    acc, nack, extra, order = set(), {}, {}, []
    logins = []
    for line in open(a.server, errors='replace'):
        m = re.search(r'(NHẬN|TRÙNG)\s+ghế\s*0?(\d+).*?seq=\s*0*(\d+)', line)
        if m and (a.seat is None or int(m.group(2)) == a.seat):
            seq = int(m.group(3))
            if m.group(1) == 'NHẬN':
                if seq not in acc and seq not in nack:
                    order.append(seq)
                acc.add(seq)
            else:
                extra[seq] = extra.get(seq, 0) + 1
            continue
        m = re.search(r'LOẠI\s+(\S+)\s+ghế\s*0?(\d+)\s+câu\s+(\S+)\s+seq=\s*0*(\d+)', line)
        if m and m.group(3) != '0' and (a.seat is None or int(m.group(2)) == a.seat):
            seq = int(m.group(4))
            if seq not in acc and seq not in nack:
                order.append(seq)
            nack[seq] = m.group(1)
            continue
        m = re.search(r'ĐIỂM DANH( lại)? ghế\s*0?(\d+)\s+seq=\s*0*(\d+)', line)
        if m and (a.seat is None or int(m.group(2)) == a.seat):
            logins.append(int(m.group(3)))
    n = len(order)
    if not n:
        raise SystemExit('Không có dòng NHẬN / LOẠI nào trong log server (đúng ghế? đã mở câu?).')
    retried = [s for s in order if extra.get(s)]
    print(f'LOGIN server thấy: {len(logins)} lần (seq {", ".join(map(str, logins[-5:])) or "-"})')
    print(f'ANSWER server nhận: {n} giao dịch — chấp nhận {len(acc)}, NACK {len(nack)} '
          f'({", ".join(f"seq {k}: {v}" for k, v in list(nack.items())[:8])}{"…" if len(nack) > 8 else ""})')
    print(f'Remote phải gửi lại (server thấy cùng seq ≥ 2 lần): {pct(len(retried), n)}   seq: {retried[:20]}')
    first = (n - len(retried)) / n
    print(f'ACK lần đầu (ước lượng, chỉ tính giao dịch server thấy): {first:.1%}')
    ok = first >= 0.95
    if a.presses:
        print(f'Server nhận / số lần bấm: {pct(n, a.presses)}   (thiếu {max(0, a.presses - n)} lần: đèn đỏ hết giờ hoặc đếm nhầm)')
        ok = ok and n / a.presses >= 0.99
    else:
        print('(thêm --presses <số lần bấm OK> để tính tỉ lệ server nhận)')
    print('PASS' if ok else 'FAIL', '(server nhận ≥ 99 % số lần bấm, ACK lần đầu ≥ 95 %; độ trễ cần log Serial)')
    raise SystemExit(0)

if a.probe:
    sent = [int(m.group(1)) for m in re.finditer(r'DIAG TX PROBE seq=(\d+)', open(a.serial, errors='replace').read())]
    heard = {int(m.group(1)) & 0xFF for m in re.finditer(r'gateway PROBE seq=(\d+)', open(a.server, errors='replace').read())}
    if not sent:
        raise SystemExit('Không có dòng "DIAG TX PROBE" trong log Serial (gõ u trên Remote).')
    got = [s for s in sent if (s & 0xFF) in heard]
    miss = [s for s in sent if (s & 0xFF) not in heard]
    print(f'Gói PROBE gateway nghe được: {pct(len(got), len(sent))}   thiếu: {miss[:20]}{"…" if len(miss) > 20 else ""}')
    print('PASS' if len(got) / len(sent) >= 0.99 else 'FAIL', '(tiêu chí ≥ 99 %)')
    raise SystemExit(0)

# ---- log Serial của Remote ----
tx = OrderedDict()                       # (kind, seq) -> {'retries', 'result', 'dt', 'adv_fail'}
cur = None
for line in open(a.serial, errors='replace'):
    m = re.search(r'(TX|RETRY) (ANSWER|LOGIN) .*?seq=(\d+)', line)
    if m:
        cur = (m.group(2), int(m.group(3)))
        t = tx.setdefault(cur, {'retries': 0, 'result': None, 'dt': None, 'adv_fail': 0})
        if m.group(1) == 'RETRY':
            t['retries'] += 1
        continue
    if cur is None:
        continue
    if 'ADV START FAIL' in line:
        tx[cur]['adv_fail'] += 1
        continue
    kind = cur[0]
    res = None
    if re.search(rf'(?<!N)ACK {kind} EXACT', line) or (kind == 'LOGIN' and 'ACK LOGIN:' in line):
        res = 'ACK'
    elif f'NACK {kind} EXACT' in line:
        res = 'NACK'
    elif 'ACK TIMEOUT' in line:
        res = 'TIMEOUT'
    if res:
        tx[cur]['result'] = res
        d = re.search(r'dt=(\d+)ms', line)
        tx[cur]['dt'] = int(d.group(1)) if d else None

# ---- log server: ingest (NHẬN / TRÙNG / LOẠI / ĐIỂM DANH) hoặc ars-ble (DIAG RF RX) ----
seen = {'ANSWER': set(), 'LOGIN': set()}
for line in open(a.server, errors='replace'):
    m = re.search(r'(NHẬN|TRÙNG)\s+ghế\s*0?(\d+).*?seq=\s*0*(\d+)', line)
    if m and (a.seat is None or int(m.group(2)) == a.seat):
        seen['ANSWER'].add(int(m.group(3)))
        continue
    m = re.search(r'LOẠI\s+\S+\s+ghế\s*0?(\d+)\s+câu\s+(\S+)\s+seq=\s*0*(\d+)', line)
    if m and (a.seat is None or int(m.group(1)) == a.seat):
        seen['LOGIN' if m.group(2) == '0' else 'ANSWER'].add(int(m.group(3)))
        continue
    m = re.search(r'RF RX (ANSWER|LOGIN) room=\d+ seat=(\d+).*?seq=0*(\d+)', line)
    if m and (a.seat is None or int(m.group(2)) == a.seat):
        seen[m.group(1)].add(int(m.group(3)))
        continue
    m = re.search(r'(ĐIỂM DANH)(?: lại)? ghế\s*0?(\d+)\s+seq=\s*0*(\d+)', line)
    if m and (a.seat is None or int(m.group(2)) == a.seat):
        seen['LOGIN'].add(int(m.group(3)))

rows, cnt = [], {}
for (kind, seq), t in tx.items():
    r = t['result']
    server = seq in seen[kind]
    if r == 'ACK':
        cls = 'ACK_FIRST' if t['retries'] == 0 else 'ACK_RETRY'
    elif r == 'NACK':
        cls = 'NACK'
    elif r == 'TIMEOUT':
        cls = 'ADV_FAIL' if t['adv_fail'] and not server else ('ACK_MISS' if server else 'UPLINK_MISS')
    else:
        cls = 'UNFINISHED'
    if r in ('ACK', 'NACK'):
        server = True                      # có kết quả chính xác ⇒ server chắc chắn đã nhận
    cnt[cls] = cnt.get(cls, 0) + 1
    rows.append((kind, seq, t['retries'], 'yes' if server else 'NO', t['dt'], cls))

print(f'{"loại":<6} {"seq":>4} {"retries":>7} {"server":>6} {"dt ms":>6}  result')
for r in rows:
    print(f'{r[0]:<6} {r[1]:>4} {r[2]:>7} {r[3]:>6} {r[4] if r[4] is not None else "-":>6}  {r[5]}')
done_rows = [r for r in rows if r[5] != 'UNFINISHED']
n = len(done_rows)
if not n:
    raise SystemExit('Không có giao dịch nào kết thúc trong log Serial.')
srv = sum(1 for r in done_rows if r[3] == 'yes')
ok = cnt.get('ACK_FIRST', 0) + cnt.get('ACK_RETRY', 0) + cnt.get('NACK', 0)
dts = [r[4] for r in done_rows if r[4] is not None and r[5] in ('ACK_FIRST', 'ACK_RETRY', 'NACK')]
print('\n' + ', '.join(f'{k} {v}' for k, v in sorted(cnt.items())))
print(f'Server nhận: {pct(srv, n)}   Có kết quả chính xác: {pct(ok, n)}   '
      f'ACK lần đầu: {pct(cnt.get("ACK_FIRST", 0), n)}   Timeout: {n - ok}')
if dts:
    print(f'Độ trễ OK → kết quả: trung vị {quantile(dts, .5)} ms, P95 {quantile(dts, .95)} ms, lớn nhất {max(dts)} ms ({len(dts)} giao dịch)')
passed = srv / n >= 0.99 and ok / n >= 0.99 and cnt.get('ACK_FIRST', 0) / n >= 0.95 and (not dts or quantile(dts, .95) < 300)
print('PASS' if passed else 'FAIL',
      '(1 Remote: server nhận ≥ 99 %, có kết quả ≥ 99 %, ACK lần đầu ≥ 95 %, P95 < 300 ms)')
