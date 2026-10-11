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


class ScanController:
    """Dieu phoi cua so scan tren cung adapter hci0.

    Khi server can phat Exact ACK, ta tam dung scan trong mot cua so ngan
    de adapter uu tien radio cho advertising downlink. Sau do scan duoc bat lai
    trong khi ACK van co the tiep tuc advertise den khi ingest gui ACKS -.
    """

    def __init__(self):
        self.scanner = None
        self.running = False
        self.lock = asyncio.Lock()
        self.pause_gen = 0
        self.paused_s = 0.0          # tổng thời gian dừng quét (đo trong mỗi chu kỳ báo trạng thái 5 s)
        self.paused_at = None
        self.pauses = 0

    async def attach_and_start(self, scanner):
        async with self.lock:
            self.scanner = scanner
            await scanner.start()
            self.running = True

    async def pause_for(self, seconds):
        async with self.lock:
            self.pause_gen += 1
            gen = self.pause_gen
            if self.scanner and self.running:
                try:
                    await self.scanner.stop()
                    self.running = False
                    self.paused_at = time.monotonic()
                    self.pauses += 1
                    log.info('DIAG SCAN PAUSE ack_window_ms=%d', round(seconds * 1000))
                except Exception as e:
                    log.warning('Khong tam dung duoc BLE scan: %s', e)
            asyncio.create_task(self._resume_after(seconds, gen))

    async def _resume_after(self, seconds, gen):
        await asyncio.sleep(seconds)
        async with self.lock:
            if gen != self.pause_gen or not self.scanner or self.running:
                return
            try:
                await self.scanner.start()
                self.running = True
                if self.paused_at is not None:
                    self.paused_s += time.monotonic() - self.paused_at
                    self.paused_at = None
                log.info('DIAG SCAN RESUME after_ack_window')
            except Exception as e:
                log.warning('Khong bat lai duoc BLE scan: %s', e)

    def take_duty(self):
        """(số lần dừng, tổng giây dừng) từ lần gọi trước — để log tỉ lệ thời gian không quét."""
        paused = self.paused_s + (time.monotonic() - self.paused_at if self.paused_at is not None else 0.0)
        if self.paused_at is not None:
            self.paused_at = time.monotonic()
        n, self.pauses, self.paused_s = self.pauses, 0, 0.0
        return n, paused

    async def stop(self):
        async with self.lock:
            self.pause_gen += 1
            if self.scanner and self.running:
                try:
                    await self.scanner.stop()
                except Exception:
                    pass
            self.running = False
            self.scanner = None


class Advertiser:
    """Phát beacon qua BlueZ theo kiểu ưu tiên ACK + background burst.

    Mục tiêu cho một adapter laptop vừa scan uplink vừa advertise downlink:
      * ACKS: ưu tiên tuyệt đối. Khi có ACK, tạm dừng STATE/ATTEND/OPENMASK.
      * ATTEND/STATE/OPENMASK: chỉ phát thành burst ngắn rồi tự unregister.
        Ingest sẽ gửi heartbeat định kỳ (state_refresh_s, mặc định 5 s), nên
        Remote vẫn có cơ hội đồng bộ lại nếu bỏ lỡ một burst.
      * Khi ACKS hết TTL và ingest gửi ACKS -, các background frame mới nhất
        được khôi phục thành một burst ngắn.

    Cách này tránh việc 3 background advertisements chiếm radio liên tục và
    cạnh tranh với exact ACK.
    """

    BACKGROUND = (blecodec.F_STATE, blecodec.F_ATTEND, blecodec.F_OPENMASK)
    BURST_TTL = {
        blecodec.F_STATE: 0.90,
        blecodec.F_ATTEND: 1.20,
        blecodec.F_OPENMASK: 0.90,
    }
    ACK_EXCLUSIVE_S = 0.12        # dừng quét để card chỉ phát ACK (đã chứng minh tốt ở V3.8.4)
    ACK_COALESCE_S = 0.03         # V3.10: gộp các ACK đến trong 30 ms thành MỘT lần đăng ký quảng bá
    ACK_PAUSE_MIN_GAP_S = 0.40    # V3.10: hai lần dừng quét cách nhau ≥ 400 ms → quét ≥ 70 % thời gian dù cả lớp cùng bấm

    def __init__(self, adapter, company_id, scan_controller=None):
        self.adapter, self.company = adapter, company_id
        self.bus = self.mgr = None
        self.current = {}       # kind -> bytes đang thực sự advertise
        self.desired = {}       # kind -> bytes mới nhất cần giữ để restore
        self.objs = {}          # kind -> D-Bus advertisement object
        self.expire_gen = {}    # kind -> generation; chống timer cũ gỡ burst mới
        self.lock = asyncio.Lock()
        self.ack_active = False
        self.scan_controller = scan_controller
        self.ack_flush = None         # task gộp ACK đang chờ
        self.last_pause = -1e9        # monotonic lúc dừng quét gần nhất

    async def start(self):
        from dbus_fast import Variant
        self.Variant = Variant
        self.bus, obj = await power_on(self.adapter)
        self.mgr = obj.get_interface('org.bluez.LEAdvertisingManager1')
        # Ghi khả năng quảng bá của card vào log (Bước 2: có quảng bá mở rộng thì nhiều bộ chạy song song
        # trong controller; không có thì BlueZ xoay vòng bằng phần mềm).
        try:
            props = obj.get_interface('org.freedesktop.DBus.Properties')
            inst = (await props.call_get('org.bluez.LEAdvertisingManager1', 'SupportedInstances')).value
            try:
                sec = (await props.call_get('org.bluez.LEAdvertisingManager1', 'SupportedSecondaryChannels')).value
            except Exception:
                sec = []
            log.info('DIAG ADAPTER %s instances=%s secondary=%s ext_adv=%s ack_exclusive_ms=%d pause_gap_ms=%d coalesce_ms=%d',
                     self.adapter, inst, ','.join(sec) or '-', 'yes' if sec else 'no',
                     round(self.ACK_EXCLUSIVE_S * 1000), round(self.ACK_PAUSE_MIN_GAP_S * 1000), round(self.ACK_COALESCE_S * 1000))
        except Exception as e:
            log.warning('Không đọc được khả năng quảng bá: %s', e)

    def _make(self, kind, data):
        from dbus_fast.service import PropertyAccess, ServiceInterface, dbus_property, method
        company, Variant = self.company, self.Variant

        # Background chỉ tồn tại trong burst ngắn nên có thể phát nhanh trong
        # burst. Exact ACK vẫn nhanh nhất.
        if kind == blecodec.F_ACKS:
            min_interval, max_interval = 40, 60
        elif kind == blecodec.F_ATTEND:
            min_interval, max_interval = 150, 220
        else:  # STATE / OPENMASK
            min_interval, max_interval = 220, 320

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
                return min_interval

            @dbus_property(access=PropertyAccess.READ)
            def MaxInterval(self) -> 'u':  # noqa: F821
                return max_interval
        return f'/ars/adv/k{kind:02x}', Adv()

    async def _deactivate_locked(self, kind):
        """Dừng advertisement nhưng giữ self.desired để có thể restore."""
        path = f'/ars/adv/k{kind:02x}'
        was_active = kind in self.objs or kind in self.current
        if kind in self.objs:
            try:
                await self.mgr.call_unregister_advertisement(path)
            except Exception:
                pass
            try:
                self.bus.unexport(path)
            except Exception:
                pass
            self.objs.pop(kind, None)
        self.current.pop(kind, None)
        if was_active and kind == blecodec.F_ACKS:
            log.info('DIAG ACK ADV STOP')

    async def _activate_locked(self, kind, data):
        if self.current.get(kind) == data:
            return True
        await self._deactivate_locked(kind)
        path, obj = self._make(kind, data)
        self.bus.export(path, obj)
        self.objs[kind] = obj
        try:
            await self.mgr.call_register_advertisement(path, {})
            self.current[kind] = data
            if kind == blecodec.F_ACKS:
                n = data[3] if len(data) >= 4 else 0
                recs = []
                off = 4
                for _ in range(n):
                    if off + 5 > len(data):
                        break
                    seat, qid, seq, ans, status = data[off:off+5]
                    recs.append(f'{seat}:{qid}:{seq}:{ans}:{status}')
                    off += 5
                log.info('DIAG ACK ADV START room=%s n=%d records=%s interval_ms=40-60',
                         data[2] if len(data) >= 3 else '?', n, ','.join(recs) if recs else '-')
            else:
                log.debug('beacon %02X: %s', kind, data.hex())
            return True
        except Exception as e:
            self.current.pop(kind, None)
            self.objs.pop(kind, None)
            try:
                self.bus.unexport(path)
            except Exception:
                pass
            log.warning('Không phát được beacon %02X: %s', kind, e)
            return False

    def _schedule_expiry_locked(self, kind, data):
        ttl = self.BURST_TTL.get(kind)
        if ttl is None:
            return
        gen = self.expire_gen.get(kind, 0) + 1
        self.expire_gen[kind] = gen
        asyncio.create_task(self._expire_after(kind, data, gen, ttl))

    async def _expire_after(self, kind, data, gen, ttl):
        await asyncio.sleep(ttl)
        async with self.lock:
            # Timer cũ không được gỡ một burst mới hơn.
            if self.expire_gen.get(kind) != gen:
                return
            if self.ack_active:
                return
            if self.current.get(kind) == data:
                await self._deactivate_locked(kind)
                log.debug('background beacon %02X hết burst', kind)

    async def _restore_background_locked(self):
        """Sau ACK, phát lại snapshot background mới nhất thành burst."""
        for kind in self.BACKGROUND:
            data = self.desired.get(kind)
            if not data:
                continue
            if await self._activate_locked(kind, data):
                self._schedule_expiry_locked(kind, data)

    @staticmethod
    def _records(data):
        """Tập bản ghi (ghế, câu, seq, đáp án, status) trong một gói ACKS."""
        if not data or len(data) < 4:
            return set()
        out, off = set(), 4
        for _ in range(data[3]):
            if off + 5 > len(data):
                break
            out.add(tuple(data[off:off + 5]))
            off += 5
        return out

    async def _flush_ack_later(self):
        await asyncio.sleep(self.ACK_COALESCE_S)
        async with self.lock:
            data = self.desired.get(blecodec.F_ACKS)
            if not data or not self.ack_active or self.current.get(blecodec.F_ACKS) == data:
                return
            new = self._records(data) - self._records(self.current.get(blecodec.F_ACKS))
            for rec in sorted(new):
                seat, qid, seq, ans, status = rec
                log.info('DIAG ACK QUEUED seat=%d q=%d seq=%03d ans_code=%d status=%d', seat, qid, seq, ans, status)
            # ACK ưu tiên tuyệt đối: gỡ STATE/ATTEND/OPENMASK (vẫn giữ trong desired để phát lại sau).
            for bg in self.BACKGROUND:
                await self._deactivate_locked(bg)
            # Dừng quét ngắn để card chỉ phát ACK — chỉ khi có bản ghi MỚI và lần dừng trước đủ xa,
            # để tổng thời gian "điếc" bị chặn trên (≤ ACK_EXCLUSIVE_S / ACK_PAUSE_MIN_GAP_S ≈ 30 %).
            mono = time.monotonic()
            if new and self.scan_controller and mono - self.last_pause >= self.ACK_PAUSE_MIN_GAP_S:
                self.last_pause = mono
                await self.scan_controller.pause_for(self.ACK_EXCLUSIVE_S)
            await self._activate_locked(blecodec.F_ACKS, data)
            if self.ack_active and self.desired.get(blecodec.F_ACKS) not in (None, self.current.get(blecodec.F_ACKS)):
                self.ack_flush = asyncio.create_task(self._flush_ack_later())   # đổi trong lúc đang đăng ký

    async def set(self, kind, data):
        async with self.lock:
            if not self.mgr:
                return

            if kind == blecodec.F_ACKS:
                # [A5,13,room,n]. n=0 = không còn ACK pending.
                empty = len(data) >= 4 and data[3] == 0
                if empty:
                    if not self.ack_active and kind not in self.current:
                        return                       # đã trống (bản lặp từ trạng thái giữ lại)
                    self.desired.pop(kind, None)
                    self.ack_active = False
                    log.info('DIAG ACK CLEAR received_empty_acks')
                    await self._deactivate_locked(kind)
                    # ACK đã xong: cho Remote một burst STATE/ATTEND/OPENMASK
                    # mới nhất để đồng bộ UI/câu hỏi, rồi các beacon này tự tắt.
                    await self._restore_background_locked()
                    return

                # Gói ACKS mới: chỉ ghi nhận, rồi gộp các thay đổi trong ACK_COALESCE_S thành một lần
                # đăng ký (cả lớp cùng bấm → vài lần đăng ký thay vì mỗi gói một lần).
                self.desired[kind] = data
                self.ack_active = True
                if self.current.get(kind) != data and (self.ack_flush is None or self.ack_flush.done()):
                    self.ack_flush = asyncio.create_task(self._flush_ack_later())
                return

            # STATE / ATTEND / OPENMASK: luôn lưu snapshot mới nhất.
            self.desired[kind] = data

            # Trong lúc exact ACK đang phát, không cạnh tranh airtime.
            if self.ack_active:
                return

            # Không đổi payload và burst vẫn còn chạy -> không cần đăng ký lại.
            if self.current.get(kind) == data:
                return

            if await self._activate_locked(kind, data):
                self._schedule_expiry_locked(kind, data)


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

    scan_ctl = ScanController()
    adv = Advertiser(adapter, company, scan_ctl) if advertise else None
    if adv:                                   # chỉnh thông số ACK không cần sửa code (Bước 2: thử nghiệm)
        adv.ACK_EXCLUSIVE_S = int(bc.get('ack_exclusive_ms', round(Advertiser.ACK_EXCLUSIVE_S * 1000))) / 1000
        adv.ACK_PAUSE_MIN_GAP_S = int(bc.get('ack_pause_gap_ms', round(Advertiser.ACK_PAUSE_MIN_GAP_S * 1000))) / 1000
        adv.ACK_COALESCE_S = int(bc.get('ack_coalesce_ms', round(Advertiser.ACK_COALESCE_S * 1000))) / 1000
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
            if pkt.get('type') == blecodec.PKT_ANSWER:
                log.info('DIAG RF RX ANSWER room=%d seat=%d q=%d seq=%03d ans=%s rssi=%s',
                         pkt.get('room', 0), pkt.get('id', 0), pkt.get('q', 0), pkt.get('seq', 0),
                         pkt.get('ans', '-'), pkt.get('rssi'))
            elif pkt.get('type') == blecodec.PKT_LOGIN:
                log.info('DIAG RF RX LOGIN room=%d seat=%d seq=%03d rssi=%s',
                         pkt.get('room', 0), pkt.get('id', 0), pkt.get('seq', 0), pkt.get('rssi'))
            cli.publish(f"ars/{pkt['room']}/resp", json.dumps(pkt), qos=1)
            counters['n'] += 1

    kw = {'detection_callback': on_adv, 'scanning_mode': 'active'}
    if sys.platform.startswith('linux'):
        kw['adapter'] = adapter
        kw['bluez'] = {'filters': {'Transport': 'le', 'DuplicateData': True}}   # báo cả gói lặp (gói lặp cùng seq = gửi lại)
    while True:                                           # Bluetooth tat / adapter mat -> thu lai
        scanner = BleakScanner(**kw)
        try:
            await scan_ctl.attach_and_start(scanner)
            log.info('Dang nghe Remote qua Bluetooth %s (room %d, company 0x%04X)%s', adapter, room, company,
                     ', phat beacon + ACK exclusive window' if adv else '')
            while True:
                await asyncio.sleep(5)
                n, counters['n'] = counters['n'], 0
                np, ps = scan_ctl.take_duty()
                if np:
                    log.info('DIAG SCAN DUTY pauses=%d paused_ms=%d of 5000 (%.0f%% scanning)', np, round(ps * 1000), 100 - ps * 20)
                if cli:
                    cli.publish(status_topic, json.dumps({'online': True, 'port': f'bluetooth {adapter}',
                                                          'pkt_per_s': round(n / 5, 1), 'ts': time.time()}), qos=1, retain=True)
        except Exception as e:
            log.warning('Bluetooth %s: %s - thu lai sau 3 s', adapter, e)
            if cli:
                cli.publish(status_topic, json.dumps({'online': False, 'port': f'bluetooth {adapter}', 'ts': time.time()}), qos=1, retain=True)
            await asyncio.sleep(3)
        finally:
            await scan_ctl.stop()


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
