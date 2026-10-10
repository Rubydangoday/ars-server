#!/usr/bin/env python3
"""ble_scan.py — dùng Bluetooth của laptop thay cho gateway USB: Remote nRF52840 ⇄ laptop ⇄ MQTT. Không có logic nghiệp vụ.

Chiều lên:  quét gói quảng bá BLE, lọc Manufacturer Data mã công ty [ble] company_id + byte 0xA5 (định dạng: bridge/blecodec.py)
            → JSON giống gateway USB → MQTT  ars/<room>/resp   (QoS 1, "via":"ble")
Chiều xuống: MQTT  ars/<ROOM>/cmd/gw  và bản giữ lại  ars/<ROOM>/state  → 4 gói beacon (STATE / ATTEND / OPENMASK / ACKS)
            phát song song bằng BlueZ (chỉ Linux; Windows/macOS: chỉ nghe, Remote không nhận được trạng thái)
Trạng thái:  ars/<ROOM>/gw/status  = {"online": true, "port": "bluetooth hci0", "pkt_per_s": ...}  (Last Will = offline)

Cần: python3-bleak (Ubuntu: sudo apt install python3-bleak; Windows: pip install bleak), Bluetooth của laptop đang bật.
Chạy:  python3 bridge/ble_scan.py            (room, adapter lấy trong config.ini)
       python3 bridge/ble_scan.py --dump     (in mọi gói quảng bá có Manufacturer Data — kiểm tra firmware Remote)
"""
import argparse
import asyncio
import json
import logging
import os
import sys
import time

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(HERE, '..', 'common'))
sys.path.insert(0, HERE)
import arscfg  # noqa: E402
import blecodec  # noqa: E402

log = logging.getLogger('ble')


async def power_on(adapter):
    """Bật adapter Bluetooth (BlueZ) nếu đang tắt. Trả về (bus D-Bus, đối tượng adapter)."""
    from dbus_fast import BusType, Variant
    from dbus_fast.aio import MessageBus
    bus = await MessageBus(bus_type=BusType.SYSTEM).connect()
    path = f'/org/bluez/{adapter}'
    obj = bus.get_proxy_object('org.bluez', path, await bus.introspect('org.bluez', path))
    props = obj.get_interface('org.freedesktop.DBus.Properties')
    if not (await props.call_get('org.bluez.Adapter1', 'Powered')).value:
        await props.call_set('org.bluez.Adapter1', 'Powered', Variant('b', True))
        log.info('Đã bật Bluetooth %s', adapter)
    return bus, obj


class Advertiser:
    """Phát beacon qua BlueZ (D-Bus LEAdvertisingManager1). Mỗi loại gói một bản quảng bá; nội dung đổi → đăng ký lại bản đó."""

    def __init__(self, adapter, company_id):
        self.adapter, self.company = adapter, company_id
        self.bus = self.mgr = None
        self.current = {}       # loại gói → bytes đang phát
        self.objs = {}          # loại gói → đối tượng D-Bus đã đăng ký
        self.lock = asyncio.Lock()

    async def start(self):
        from dbus_fast import Variant
        self.Variant = Variant
        self.bus, obj = await power_on(self.adapter)
        self.mgr = obj.get_interface('org.bluez.LEAdvertisingManager1')

    def _make(self, kind, data):
        from dbus_fast.service import PropertyAccess, ServiceInterface, dbus_property, method
        company, Variant = self.company, self.Variant

        class Adv(ServiceInterface):
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
                return 100

            @dbus_property(access=PropertyAccess.READ)
            def MaxInterval(self) -> 'u':  # noqa: F821
                return 150
        return f'/ars/adv/k{kind:02x}', Adv()

    async def set(self, kind, data):
        async with self.lock:
            if not self.mgr or self.current.get(kind) == data:
                return
            path = f'/ars/adv/k{kind:02x}'
            if kind in self.objs:
                try:
                    await self.mgr.call_unregister_advertisement(path)
                except Exception:
                    pass
                self.bus.unexport(path)
            path, obj = self._make(kind, data)
            self.bus.export(path, obj)
            self.objs[kind] = obj
            try:
                await self.mgr.call_register_advertisement(path, {})
                self.current[kind] = data
                log.debug('beacon %02X: %s', kind, data.hex())
            except Exception as e:
                self.current.pop(kind, None)
                log.warning('Không phát được beacon %02X: %s', kind, e)


async def run(a, cp):
    from bleak import BleakScanner
    room = int(cp['gateway']['room'])
    bc = cp['ble'] if cp.has_section('ble') else {}
    adapter = a.adapter or bc.get('adapter', 'hci0')
    company = int(str(bc.get('company_id', '0xFFFF')), 0)
    advertise = str(bc.get('advertise', '1')) not in ('0', 'no', 'false') and sys.platform.startswith('linux') and not a.dump
    loop = asyncio.get_running_loop()
    status_topic = f'ars/{room}/gw/status'
    counters = {'n': 0}

    adv = Advertiser(adapter, company) if advertise else None
    if not adv and sys.platform.startswith('linux'):
        try:
            await power_on(adapter)
        except Exception as e:
            log.warning('Không bật được Bluetooth %s: %s', adapter, e)
    if adv:
        try:
            await adv.start()
        except Exception as e:
            log.warning('Không phát được beacon (%s) — chỉ nghe Remote. Kiểm tra: rfkill unblock bluetooth, quyền nhóm bluetooth.', e)
            adv = None

    def apply(lines):
        for line in lines:
            enc = blecodec.encode_cmd(line, room)
            if enc and adv:
                asyncio.run_coroutine_threadsafe(adv.set(*enc), loop)

    cli = None
    if not a.dump:
        cli = arscfg.mqtt_client(f'ars-ble-{room}', clean_session=True)
        cli.will_set(status_topic, json.dumps({'online': False}), qos=1, retain=True)

        def on_connect(c, u, f, rc):
            log.info('MQTT đã kết nối (rc=%s)', rc)
            c.subscribe(f'ars/{room}/cmd/gw', qos=1)
            c.subscribe(f'ars/{room}/state', qos=1)

        def on_message(c, u, msg):
            if msg.topic.endswith('/cmd/gw'):
                apply([msg.payload.decode('ascii', 'replace')])
            else:
                try:
                    apply(json.loads(msg.payload).get('cmds', []))
                except ValueError:
                    pass
        cli.on_connect, cli.on_message = on_connect, on_message
        cli.connect(cp['mqtt']['host'], int(cp['mqtt'].get('port', 1883)), keepalive=30)
        cli.loop_start()

    def on_adv(device, ad):
        for cid, data in (ad.manufacturer_data or {}).items():
            if a.dump:
                print(f'{device.address}  rssi={ad.rssi}  company=0x{cid:04X}  data={bytes(data).hex()}', flush=True)
            if cid != company:
                continue
            pkt = blecodec.decode_uplink(data, device.address, ad.rssi)
            if not pkt:
                continue
            if a.dump:
                print('   → ARS', json.dumps(pkt), flush=True)
                continue
            pkt['ts'] = time.time()
            pkt['via'] = 'ble'
            cli.publish(f"ars/{pkt['room']}/resp", json.dumps(pkt), qos=1)
            counters['n'] += 1

    kw = {'detection_callback': on_adv, 'scanning_mode': 'active'}
    if sys.platform.startswith('linux'):
        kw['adapter'] = adapter
        kw['bluez'] = {'filters': {'Transport': 'le', 'DuplicateData': True}}   # báo cả gói lặp (mỗi lần bấm phát 5 lần)
    while True:                                           # Bluetooth tắt / adapter mất → thử lại
        try:
            async with BleakScanner(**kw):
                log.info('Đang nghe Remote qua Bluetooth %s (room %d, company 0x%04X)%s', adapter, room, company,
                         ', phát beacon' if adv else '')
                while True:
                    await asyncio.sleep(5)
                    n, counters['n'] = counters['n'], 0
                    if cli:
                        cli.publish(status_topic, json.dumps({'online': True, 'port': f'bluetooth {adapter}',
                                                              'pkt_per_s': round(n / 5, 1), 'ts': time.time()}), qos=1, retain=True)
        except Exception as e:
            log.warning('Bluetooth %s: %s — thử lại sau 3 s', adapter, e)
            if cli:
                cli.publish(status_topic, json.dumps({'online': False, 'port': f'bluetooth {adapter}', 'ts': time.time()}), qos=1, retain=True)
            await asyncio.sleep(3)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--config')
    ap.add_argument('--adapter', help='hci0, hci1… (Linux)')
    ap.add_argument('--dump', action='store_true', help='in mọi gói quảng bá có Manufacturer Data, không gửi MQTT')
    a = ap.parse_args()
    cp = arscfg.load(a.config)
    try:
        asyncio.run(run(a, cp))
    except KeyboardInterrupt:
        pass


if __name__ == '__main__':
    logging.basicConfig(level=logging.INFO, format='%(asctime)s %(levelname)s %(message)s')
    main()
