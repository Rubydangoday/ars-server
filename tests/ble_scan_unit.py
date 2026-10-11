"""ble_scan.Advertiser V3.10 với BlueZ/D-Bus giả: gộp ACK, chặn tần suất dừng quét, ưu tiên ACK.
Không cần Bluetooth, bleak hay dbus-fast. Chạy: python3 tests/ble_scan_unit.py [smoke.json bỏ qua]
"""
import asyncio
import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'bridge'))
sys.path.insert(0, str(ROOT / 'common'))
import blecodec  # noqa: E402
import ble_scan  # noqa: E402

checks = []


def check(x, msg):
    assert x, msg
    checks.append(msg)


class FakeMgr:
    def __init__(self):
        self.registered, self.unregistered = [], []

    async def call_register_advertisement(self, path, opts):
        await asyncio.sleep(0.004)                 # BlueZ: một vòng D-Bus
        self.registered.append(path)

    async def call_unregister_advertisement(self, path):
        await asyncio.sleep(0.002)
        self.unregistered.append(path)


class FakeBus:
    def export(self, path, obj):
        pass

    def unexport(self, path):
        pass


class FakeScan:
    def __init__(self):
        self.pauses = []

    async def pause_for(self, seconds):
        self.pauses.append((asyncio.get_running_loop().time(), seconds))


def ack(*recs):
    line = 'ACKS ' + (','.join(':'.join(map(str, r)) for r in recs) or '-')
    return blecodec.encode_cmd(line, 1)


async def main():
    scan = FakeScan()
    adv = ble_scan.Advertiser('hci0', 0xFFFF, scan)
    adv.mgr, adv.bus = FakeMgr(), FakeBus()
    adv._make = lambda kind, data: (f'/ars/adv/k{kind:02x}', object())

    # Nền đang phát; ACK đến phải gỡ nền và phát ACK
    await adv.set(*blecodec.encode_cmd('STATE 1 1 1 4 00', 1))
    check(blecodec.F_STATE in adv.current, 'Background STATE advertised when no ACK is pending')
    await adv.set(*ack((1, 1, 10, 1, 0)))
    await asyncio.sleep(0.1)
    check(blecodec.F_ACKS in adv.current and blecodec.F_STATE not in adv.current,
          'ACK preempts background frames (no rotation with STATE/ATTEND/OPENMASK)')
    check(len(scan.pauses) == 1, 'First new ACK gets one exclusive scan pause')

    # Cùng nội dung (gói lặp) → không đăng ký lại, không dừng quét
    n_reg = len(adv.mgr.registered)
    await adv.set(*ack((1, 1, 10, 1, 0)))
    await asyncio.sleep(0.1)
    check(len(adv.mgr.registered) == n_reg and len(scan.pauses) == 1, 'Same ACK payload: no re-register, no scan pause')

    # Cả lớp cùng bấm: 40 ACK trong 2 s (mỗi 50 ms một gói) → đăng ký ít, dừng quét bị chặn trần
    recs = []
    t0 = asyncio.get_running_loop().time()
    n_reg = len(adv.mgr.registered)
    for seat in range(2, 42):
        recs = (recs + [(seat, 1, seat, 2, 0)])[-4:]
        await adv.set(*ack(*recs))
        await asyncio.sleep(0.05)
    await asyncio.sleep(0.2)
    span = asyncio.get_running_loop().time() - t0
    pauses = [p for p in scan.pauses if p[0] >= t0]
    paused = sum(s for _, s in pauses)
    check(adv.current.get(blecodec.F_ACKS) == ack(*recs)[1], 'After a burst the newest ACK frame is on air')
    check(len(adv.mgr.registered) - n_reg <= 40, 'Coalescing: at most one registration per ACK change')
    check(paused / span <= 0.32, f'Scan paused {paused / span:.0%} of the burst (<= ~30 %, V3.9 would pause on every ACK)')
    gaps = [b[0] - a[0] for a, b in zip(pauses, pauses[1:])]
    check(all(g >= ble_scan.Advertiser.ACK_PAUSE_MIN_GAP_S - 0.01 for g in gaps), 'Scan pauses are >= 400 ms apart')

    # Nhiều thay đổi trong 30 ms → một lần đăng ký
    n_reg = len(adv.mgr.registered)
    for seat in range(50, 54):
        await adv.set(*ack((seat, 2, 1, 1, 0)))
    await asyncio.sleep(0.15)
    check(len(adv.mgr.registered) - n_reg == 1, 'Four ACK changes within 30 ms -> one BlueZ registration')

    # Hết ACK → nền phát lại; "ACKS -" lặp lại không làm gì
    await adv.set(*ack())
    check(blecodec.F_ACKS not in adv.current and blecodec.F_STATE in adv.current, 'ACKS - restores the background burst')
    n_unreg = len(adv.mgr.unregistered)
    await adv.set(*ack())
    check(len(adv.mgr.unregistered) == n_unreg, 'Repeated ACKS - is a no-op')


asyncio.run(main())
print(json.dumps({'passed': len(checks), 'checks': checks}, ensure_ascii=False, indent=2))
