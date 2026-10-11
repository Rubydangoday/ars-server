#!/usr/bin/env python3
"""ble_probe.py — đo radio của card Bluetooth laptop cho ARS (Bước 2). Chạy thay cho ars-ble trong lúc đo.

  sudo systemctl stop ars-ble
  python3 tools/ble_probe.py downlink --room 1      # Remote: Serial 'd' (đếm beacon nghe được mỗi giây)
  python3 tools/ble_probe.py uplink   --room 1      # Remote: Serial 'u' (100 gói PROBE, 0,7 s/gói)
  sudo systemctl start ars-ble

downlink — laptop phát gói PROBE [A5,1F,room,phase] mỗi 40–60 ms (như ACK) qua các pha, mỗi pha --phase-s giây:
  phase 1  chỉ phát, KHÔNG quét                    → tốc độ trần Remote nghe được
  phase 2  phát + quét liên tục (như ars-ble)        → card có còn phát đều khi đang quét không
  phase 3  phát + quét + 3 bộ quảng bá nền khác      → card có phát song song nhiều bộ không (hay BlueZ xoay vòng)
  phase 4  phát + quét, dừng quét 120 ms mỗi 400 ms   → đúng chính sách ACK V3.10
  So sánh "PROBE=x/s phase=n" in trên Serial của Remote: pha 2/3/4 tụt mạnh so với pha 1 = card bỏ phát khi quét.

uplink — laptop quét (giống ars-ble) và đếm gói PROBE (type 3) Remote phát; nửa đầu không phát gì, nửa sau phát
  thêm một gói ACK-giả 40–60 ms (như khi đang có ACK). In tỉ lệ seq bắt được mỗi pha = độ tin cậy uplink.

Kết quả in ra màn hình; gửi kèm log Serial của Remote khi báo kết quả.
"""
import argparse
import asyncio
import os
import sys
import time

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(HERE, '..', 'common'))
sys.path.insert(0, os.path.join(HERE, '..', 'bridge'))
import arscfg  # noqa: E402
import blecodec  # noqa: E402

F_PROBE = 0x1F
PKT_PROBE = 3


class Adv:
    """Một bộ quảng bá BlueZ (broadcast, Manufacturer Data) với chu kỳ tuỳ chọn."""

    def __init__(self, bus, mgr, company, path, data, min_ms, max_ms):
        from dbus_fast import Variant
        from dbus_fast.service import PropertyAccess, ServiceInterface, dbus_property, method

        class A(ServiceInterface):
            def __init__(self):
                super().__init__('org.bluez.LEAdvertisement1')

            @method()
            def Release(self):
                pass

            @dbus_property(access=PropertyAccess.READ)
            def Type(self) -> 's':  # noqa: F821
                return 'broadcast'

            @dbus_property(access=PropertyAccess.READ)
            def ManufacturerData(self) -> 'a{qv}':  # noqa: F821
                return {company: Variant('ay', data)}

            @dbus_property(access=PropertyAccess.READ)
            def MinInterval(self) -> 'u':  # noqa: F821
                return min_ms

            @dbus_property(access=PropertyAccess.READ)
            def MaxInterval(self) -> 'u':  # noqa: F821
                return max_ms
        self.bus, self.mgr, self.path, self.obj, self.on = bus, mgr, path, A(), False

    async def start(self):
        self.bus.export(self.path, self.obj)
        await self.mgr.call_register_advertisement(self.path, {})
        self.on = True

    async def stop(self):
        if self.on:
            try:
                await self.mgr.call_unregister_advertisement(self.path)
            except Exception:
                pass
            self.bus.unexport(self.path)
            self.on = False


async def adapter_info(adapter):
    from ble_scan import power_on
    bus, obj = await power_on(adapter)
    props = obj.get_interface('org.freedesktop.DBus.Properties')
    info = {}
    for k in ('SupportedInstances', 'ActiveInstances', 'SupportedSecondaryChannels'):
        try:
            info[k] = (await props.call_get('org.bluez.LEAdvertisingManager1', k)).value
        except Exception:
            info[k] = '?'
    ext = bool(info.get('SupportedSecondaryChannels')) and info['SupportedSecondaryChannels'] != '?'
    print(f'Adapter {adapter}: SupportedInstances={info["SupportedInstances"]} '
          f'SecondaryChannels={info["SupportedSecondaryChannels"]} → '
          f'{"có quảng bá mở rộng (BLE 5): nhiều bộ quảng bá chạy song song trong controller" if ext else "KHÔNG có quảng bá mở rộng: BlueZ xoay vòng nhiều bộ bằng phần mềm (MultiAdvertisementRotationInterval)"}')
    return bus, obj.get_interface('org.bluez.LEAdvertisingManager1')


def scanner(adapter, cb):
    from bleak import BleakScanner
    return BleakScanner(detection_callback=cb, scanning_mode='active', adapter=adapter,
                        bluez={'filters': {'Transport': 'le', 'DuplicateData': True}})


async def downlink(a):
    bus, mgr = await adapter_info(a.adapter)
    sc = scanner(a.adapter, lambda d, ad: None)
    bg = []
    print(f'\nRemote: mở Serial 115200, gõ  d  (room của Remote phải = {a.room}). Mỗi pha {a.phase_s:.0f} s.\n')
    for phase in (1, 2, 3, 4):
        probe = Adv(bus, mgr, a.company, f'/ars/probe/p{phase}', bytes([0xA5, F_PROBE, a.room, phase]), 40, 60)
        await probe.start()
        if phase >= 2:
            await sc.start()
        if phase == 3:
            for k, kind in enumerate((blecodec.F_STATE, blecodec.F_ATTEND, blecodec.F_OPENMASK)):
                ad = Adv(bus, mgr, a.company, f'/ars/probe/bg{k}', bytes([0xA5, kind, (a.room + 1) & 0xFF]) + bytes(13), 220, 320)
                await ad.start()
                bg.append(ad)
        desc = {1: 'chỉ phát, không quét', 2: 'phát + quét liên tục', 3: 'phát + quét + 3 bộ nền',
                4: 'phát + quét, dừng quét 120 ms / 400 ms'}[phase]
        print(f'{time.strftime("%H:%M:%S")}  phase {phase}: {desc}')
        end = time.monotonic() + a.phase_s
        while time.monotonic() < end:
            if phase == 4:
                await sc.stop()
                await asyncio.sleep(0.12)
                await sc.start()
                await asyncio.sleep(0.28)
            else:
                await asyncio.sleep(0.2)
        for ad in bg:
            await ad.stop()
        bg.clear()
        if phase >= 2:
            await sc.stop()
        await probe.stop()
    print('\nXong. So sánh PROBE=x/s theo từng phase trên Serial của Remote.')


async def uplink(a):
    bus, mgr = await adapter_info(a.adapter)
    seen = {}                                         # seq -> [số bản, pha]
    phase = {'n': 1}

    def cb(device, ad):
        for cid, data in (ad.manufacturer_data or {}).items():
            data = bytes(data)
            if cid == a.company and len(data) >= 6 and data[0] == 0xA5 and data[1] == PKT_PROBE and data[2] == a.room:
                rec = seen.setdefault(data[5], [0, phase['n']])
                rec[0] += 1
    sc = scanner(a.adapter, cb)
    await sc.start()
    fake_ack = Adv(bus, mgr, a.company, '/ars/probe/ack', blecodec.encode_cmd('ACKS 99:1:1:1:0', (a.room + 1) & 0xFF)[1], 40, 60)
    half = a.duration / 2
    print(f'\nRemote: Serial  u  ngay bây giờ (100 gói, 0,7 s/gói ≈ 70 s). Pha 1 {half:.0f} s chỉ quét, pha 2 quét + phát ACK giả.\n')
    t0 = time.monotonic()
    while time.monotonic() - t0 < a.duration:
        if phase['n'] == 1 and time.monotonic() - t0 >= half:
            phase['n'] = 2
            await fake_ack.start()
            print(f'{time.strftime("%H:%M:%S")}  phase 2: quét + phát ACK giả 40–60 ms')
        await asyncio.sleep(0.2)
    await fake_ack.stop()
    await sc.stop()
    if not seen:
        print('Không bắt được gói PROBE nào: kiểm tra room, Remote đã gõ u chưa, ars-ble đã dừng chưa.')
        return
    hi = max(seen)
    for ph in (1, 2):
        seqs = sorted(s for s, (n, p) in seen.items() if p == ph)
        if not seqs:
            continue
        span = range(seqs[0], seqs[-1] + 1)
        got = len(seqs)
        copies = sum(seen[s][0] for s in seqs)
        missing = [s for s in span if s not in seen]
        print(f'phase {ph}: bắt {got}/{len(span)} seq ({got / len(span):.1%}), trung bình {copies / got:.1f} bản/seq, '
              f'thiếu {missing[:15]}{"…" if len(missing) > 15 else ""}')
    print(f'(seq cao nhất {hi}; tiêu chí: mỗi pha ≥ 99 %)')


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('mode', choices=['downlink', 'uplink'])
    ap.add_argument('--config')
    ap.add_argument('--room', type=int, default=None, help='mặc định [gateway] room trong config.ini')
    ap.add_argument('--adapter', default=None)
    ap.add_argument('--phase-s', type=float, default=15)
    ap.add_argument('--duration', type=float, default=80, help='uplink: tổng thời gian đo (giây)')
    a = ap.parse_args()
    try:
        cp = arscfg.load(a.config)
    except SystemExit:
        cp = None
    bc = cp['ble'] if cp is not None and cp.has_section('ble') else {}
    a.room = a.room if a.room is not None else int(cp['gateway']['room']) if cp is not None else 1
    a.adapter = a.adapter or bc.get('adapter', 'hci0')
    a.company = int(str(bc.get('company_id', '0xFFFF')), 0)
    asyncio.run(downlink(a) if a.mode == 'downlink' else uplink(a))


if __name__ == '__main__':
    main()
