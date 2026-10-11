#!/usr/bin/env python3
"""Phân loại từng giao dịch ANSWER của một Remote từ log serial + log ingest (dùng cho test V3.10).

  journalctl -u ars-ingest -u ars-ble --since "10 min ago" > server.log
  python3 tools/ble_txn_report.py serial.log server.log [--seat 1]

Mỗi seq trong log serial được xếp vào một loại:
  ACK_FIRST   xác nhận ở lần gửi đầu          ACK_RETRY   xác nhận sau khi gửi lại
  NACK        server từ chối có lý do           UPLINK_MISS hết giờ, server không hề thấy seq này
  ACK_MISS    hết giờ dù server đã NHẬN/TRÙNG seq này (Remote bỏ lỡ ACK)
Cuối cùng in tỉ lệ và PASS/FAIL theo tiêu chí ≥ 99 % (server nhận, giao dịch có kết quả), timeout ≈ 0.
"""
import argparse
import re
from collections import OrderedDict

ap = argparse.ArgumentParser()
ap.add_argument('serial')
ap.add_argument('ingest')
ap.add_argument('--seat', type=int, default=None, help='chỉ xét ghế này trong log ingest')
a = ap.parse_args()

tx = OrderedDict()                       # seq -> {'retries': n, 'result': ...}
cur = None
for line in open(a.serial, errors='replace'):
    m = re.search(r'(TX|RETRY) ANSWER .*?seq=(\d+)', line)
    if m:
        cur = int(m.group(2))
        t = tx.setdefault(cur, {'retries': 0, 'result': None})
        if m.group(1) == 'RETRY':
            t['retries'] += 1
        continue
    if cur is None:
        continue
    if 'ACK ANSWER EXACT' in line and not line.lstrip().startswith('NACK'):
        tx[cur]['result'] = 'ACK'
    elif 'NACK ANSWER EXACT' in line:
        tx[cur]['result'] = 'NACK'
    elif 'ACK TIMEOUT' in line:
        tx[cur]['result'] = 'TIMEOUT'

seen = set()
for line in open(a.ingest, errors='replace'):
    m = (re.search(r'(NHẬN|TRÙNG|LOẠI)\s+.*?ghế\s*0?(\d+).*?seq=\s*0*(\d+)', line)
         or re.search(r'(RF RX ANSWER) room=\d+ seat=(\d+) q=\d+ seq=0*(\d+)', line))   # log ars-ble cũng dùng được
    if m and (a.seat is None or int(m.group(2)) == a.seat):
        seen.add(int(m.group(3)))

rows, cnt = [], {}
for seq, t in tx.items():
    r = t['result']
    if r == 'ACK':
        cls = 'ACK_FIRST' if t['retries'] == 0 else 'ACK_RETRY'
    elif r == 'NACK':
        cls = 'NACK'
    elif r == 'TIMEOUT':
        cls = 'ACK_MISS' if seq in seen else 'UPLINK_MISS'
    else:
        cls = 'UNFINISHED'
    cnt[cls] = cnt.get(cls, 0) + 1
    rows.append((seq, t['retries'], 'yes' if seq in seen else 'NO', cls))

print(f'{"seq":>4} {"retries":>7} {"server":>6}  result')
for r in rows:
    print(f'{r[0]:>4} {r[1]:>7} {r[2]:>6}  {r[3]}')
n = sum(v for k, v in cnt.items() if k != 'UNFINISHED')
if not n:
    raise SystemExit('Không có giao dịch nào trong log serial.')
srv = sum(1 for r in rows if r[2] == 'yes' and r[3] != 'UNFINISHED')
done = cnt.get('ACK_FIRST', 0) + cnt.get('ACK_RETRY', 0) + cnt.get('NACK', 0)
print('\n' + ', '.join(f'{k} {v}' for k, v in sorted(cnt.items())))
print(f'Server nhận: {srv}/{n} = {srv / n:.1%}   Giao dịch có kết quả: {done}/{n} = {done / n:.1%}   '
      f'Lần đầu: {cnt.get("ACK_FIRST", 0) / n:.1%}   Timeout: {n - done}')
ok = srv / n >= 0.99 and done / n >= 0.99
print('PASS' if ok else 'FAIL', '(tiêu chí: server nhận ≥ 99 %, giao dịch có kết quả ≥ 99 %)')
