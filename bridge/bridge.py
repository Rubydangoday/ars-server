#!/usr/bin/env python3
"""bridge.py — nối gateway nRF52840 (USB serial) với MQTT. Không có logic nghiệp vụ.

Chiều lên:  mỗi dòng JSON gateway in ra  →  MQTT  ars/<room>/resp   (QoS 1)
            dòng JSON phải có: mac, room, type, id, q, seq, ans, batt, rssi
Chiều xuống: MQTT  ars/<ROOM>/cmd/gw  (STATE / ATTEND / OPENMASK / ACKS)  →  ghi nguyên dòng xuống serial
Khi khởi động: đọc bản giữ lại  ars/<ROOM>/state  và gửi lại các lệnh trong đó cho gateway.
Trạng thái:  ars/<ROOM>/gw/status  = {"online", "port", "fw", "pkt_per_s", "gw": {thống kê "# STAT" mới nhất}}  (Last Will = offline)
Log:        dòng "# …" của firmware gateway (STAT, server ON/OFF, PROBE, BAD…) ghi vào journal (ars logs).

Cổng:  [gateway] serial = auto (mặc định khi cài) → tìm cổng USB có firmware gateway: hỏi "v", chờ "# ARS-GW …".
       Remote cắm cùng lúc (cũng là SuperMini, cùng mã USB 0x239A) không trả lời như vậy nên không bị nhận nhầm.
Canh chừng: gateway in "# STAT" mỗi 5 s; im lặng SILENCE_S giây (treo, mất USB) → đóng cổng, báo offline, tìm/mở lại.

Chạy:  python3 bridge/bridge.py                  (cổng serial lấy trong config.ini)
       python3 bridge/bridge.py --port COM7      (ghi đè cổng)
       python3 bridge/bridge.py --list           (liệt kê cổng serial, đánh dấu cổng có gateway)
"""
import argparse
import json
import logging
import os
import re
import sys
import threading
import time

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'common'))
import arscfg  # noqa: E402

log = logging.getLogger('bridge')

GW_VID = 0x239A          # Adafruit / nice!nano bootloader + core Adafruit nRF52 (cả Remote lẫn gateway SuperMini)
# Theo quy ước của Adafruit, PID < 0x8000 là BOOTLOADER (nice!nano 0x00B3, Feather 0x0029): board đang được nạp firmware.
# Không bao giờ mở cổng đó — ghi "v" vào giữa lúc Arduino IDE / adafruit-nrfutil đang nạp làm hỏng việc nạp.
NOT_GW_RETRY_S = 60      # cổng đã hỏi mà không phải gateway (Remote, thiết bị khác): 60 s sau mới hỏi lại
SILENCE_S = 15           # gateway in "# STAT" mỗi 5 s → 15 s không có dòng nào = treo / mất USB
PROBE_WAIT_S = 1.5


def parse_stat(text):
    """'rx=12 fwd=2 room=1 adv=on' → {'rx': 12, 'fwd': 2, 'room': 1, 'adv': 'on'}."""
    out = {}
    for item in text.split():
        k, _, v = item.partition('=')
        if k:
            try:
                out[k] = int(v)
            except ValueError:
                out[k] = v
    return out


def in_bootloader(p):
    return getattr(p, 'vid', None) == GW_VID and (getattr(p, 'pid', None) or 0x8000) < 0x8000


def candidate_ports(ports):
    """Danh sách cổng (serial.tools.list_ports) → tên cổng nên thử: VID 0x239A trước, rồi các cổng USB khác.
    Bỏ board đang ở bootloader (đang nạp firmware)."""
    ports = [p for p in ports if not in_bootloader(p)]
    first = [p.device for p in ports if getattr(p, 'vid', None) == GW_VID]
    rest = [p.device for p in ports if p.device not in first and getattr(p, 'vid', None) is not None]
    return first + rest


def probe(open_port, device, wait=PROBE_WAIT_S):
    """Mở cổng, gửi lệnh 'v', chờ dòng '# ARS-GW …'. Trả về chuỗi phiên bản, hoặc None (không phải gateway / bận)."""
    try:
        h = open_port(device)
    except Exception:
        return None
    try:
        h.write(b'\nv\n')                                 # '\n' đầu: bỏ nửa dòng còn sót trong bộ đệm gateway
        end, buf = time.monotonic() + wait, b''
        while time.monotonic() < end:
            buf += h.read(256) or b''
            m = re.search(rb'# (ARS-GW[^\r\n]*)', buf)
            if m:
                return m.group(1).decode('ascii', 'replace').strip()
        return None
    except Exception:
        return None
    finally:
        try:
            h.close()
        except Exception:
            pass


def find_gateway(ports, open_port, not_gw=None, now=None):
    """(cổng, phiên bản) của gateway đầu tiên trả lời, hoặc (None, None).
    not_gw: {(cổng, số serial): lúc hỏi} các cổng vừa hỏi mà không phải gateway — bỏ qua NOT_GW_RETRY_S giây (không làm phiền
    Remote / Serial Monitor / Arduino IDE mỗi 2 s).
    Khoá theo (cổng, số serial USB): cắm board KHÁC vào cùng cổng (ttyACM0) thì hỏi ngay, không chờ."""
    now = time.monotonic() if now is None else now
    not_gw = {} if not_gw is None else not_gw
    serials = {p.device: getattr(p, 'serial_number', None) for p in ports}
    for dev in candidate_ports(ports):
        key = (dev, serials.get(dev))
        if now - not_gw.get(key, -1e9) < NOT_GW_RETRY_S:
            continue
        fw = probe(open_port, dev)
        if fw:
            not_gw.pop(key, None)
            return dev, fw
        not_gw[key] = now
    return None, None


class GatewayLink:
    """Xử lý từng dòng gateway gửi lên. publish(topic, payload) = MQTT publish (QoS 1)."""

    def __init__(self, room, publish):
        self.room, self.publish = room, publish
        self.n = 0                  # gói chuyển lên server (đếm cho pkt_per_s)
        self.bad = 0                # dòng JSON hỏng
        self.stat = {}              # "# STAT" mới nhất
        self.fw = None              # "ARS-GW x.y"
        self.last_line = 0.0        # monotonic lúc nhận dòng gần nhất (canh chừng)

    def alive(self, now):
        return now - self.last_line < SILENCE_S

    def handle(self, line, now):
        self.last_line = now
        if not line.startswith('{'):
            if line.startswith('# '):                    # log của firmware gateway (README firmware)
                msg = line[2:]
                if msg.startswith('STAT '):
                    self.stat = parse_stat(msg[5:])
                    if self.stat.get('rx') or self.stat.get('adverr') or self.stat.get('qdrop') or self.stat.get('usbdrop'):
                        log.info('gateway %s', msg)      # chỉ ghi khi có hoạt động, tránh đầy journal
                    return
                if msg.startswith('ARS-GW'):
                    if msg != self.fw:
                        log.info('gateway firmware %s', msg)
                    self.fw = msg
                    return
                log.info('gateway %s', msg)
            elif line:
                log.debug('gateway: %s', line)
            return
        try:
            pkt = json.loads(line)
            r = int(pkt.get('room', self.room))
        except (ValueError, TypeError, AttributeError):
            self.bad += 1
            return
        pkt['ts'] = time.time()
        pkt['via'] = 'ble'
        self.publish(f'ars/{r}/resp', json.dumps(pkt))
        self.n += 1


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--config')
    ap.add_argument('--port')
    ap.add_argument('--list', action='store_true')
    a = ap.parse_args()

    import serial
    import serial.tools.list_ports

    def open_port(dev, baud=115200):
        return serial.Serial(dev, baud, timeout=0.2)

    if a.list:
        ports = serial.tools.list_ports.comports()
        for p in ports:
            fw = probe(open_port, p.device) if p.device in candidate_ports(ports) else None
            print(p.device, '-', p.description, f'  ← gateway {fw}' if fw else '')
        return

    cp = arscfg.load(a.config)
    room = int(cp['gateway']['room'])
    cfg_port = (a.port or cp['gateway'].get('serial', 'auto')).strip()
    auto = cfg_port.lower() in ('', 'auto')
    baud = int(cp['gateway'].get('baud', 115200))
    status_topic = f'ars/{room}/gw/status'

    ser_lock = threading.Lock()
    ser = {'h': None, 'port': None if auto else cfg_port}

    def write_line(line):
        with ser_lock:
            if ser['h']:
                try:
                    ser['h'].write((line.strip() + '\n').encode('ascii', 'replace'))
                    log.debug('→ gateway: %s', line)
                except Exception as e:
                    log.warning('ghi serial lỗi: %s', e)

    cli = arscfg.mqtt_client(f'ars-bridge-{room}', clean_session=True)
    cli.will_set(status_topic, json.dumps({'online': False}), qos=1, retain=True)
    link = GatewayLink(room, lambda topic, payload: cli.publish(topic, payload, qos=1))

    def on_connect(c, u, f, rc):
        log.info('MQTT đã kết nối (rc=%s)', rc)
        c.subscribe(f'ars/{room}/cmd/gw', qos=1)
        c.subscribe(f'ars/{room}/state', qos=1)        # bản giữ lại → gửi lại cho gateway

    def on_message(c, u, msg):
        if msg.topic.endswith('/cmd/gw'):
            write_line(msg.payload.decode('ascii', 'replace'))
        elif msg.topic.endswith('/state'):
            try:
                for line in json.loads(msg.payload).get('cmds', []):
                    write_line(line)
            except ValueError:
                pass

    cli.on_connect, cli.on_message = on_connect, on_message
    cli.connect(cp['mqtt']['host'], int(cp['mqtt'].get('port', 1883)), keepalive=30)
    cli.loop_start()

    def report():
        # chỉ báo khi gateway đang chạy, và một lần khi vừa mất — để không đè trạng thái của
        # gateway cắm vào máy giáo viên (trang gateway.php) dùng cùng room
        was_on = None
        while True:
            time.sleep(5)
            n, link.n = link.n, 0
            on = ser['h'] is not None and link.alive(time.monotonic())
            if on or was_on is not False:
                cli.publish(status_topic, json.dumps({'online': on, 'port': ser['port'] or 'auto', 'fw': link.fw,
                                                      'pkt_per_s': round(n / 5, 1), 'bad_lines': link.bad,
                                                      'gw': link.stat, 'ts': time.time()}), qos=1, retain=True)
            was_on = on
    threading.Thread(target=report, daemon=True).start()

    last_warn = 0.0
    not_gw = {}
    while True:                                           # tự tìm / nối lại khi rút-cắm USB, gateway treo
        port = cfg_port
        try:
            if auto:
                port, fw = find_gateway(serial.tools.list_ports.comports(), open_port, not_gw)
                if not port:
                    raise OSError('chưa thấy gateway (cổng USB trả lời "# ARS-GW") — cắm gateway, hoặc đóng Serial Monitor đang giữ cổng')
                log.info('Tìm thấy gateway %s ở %s', fw, port)
            h = serial.Serial(port, baud, timeout=1)
            link.last_line = time.monotonic()            # tính thời gian im lặng từ lúc mở cổng
            with ser_lock:
                ser['h'], ser['port'] = h, port
            log.info('Đã mở %s', port)
            h.write(b'\nv\n')                             # hỏi phiên bản firmware
            cli.unsubscribe(f'ars/{room}/state'); cli.subscribe(f'ars/{room}/state', qos=1)  # phát lại trạng thái
            buf = b''
            while True:
                chunk = h.read(512)
                now = time.monotonic()
                if not chunk:
                    if not link.alive(now):
                        raise TimeoutError(f'gateway im lặng {SILENCE_S} s (treo hoặc mất USB) — mở lại cổng')
                    continue
                buf += chunk
                while b'\n' in buf:
                    line, buf = buf.split(b'\n', 1)
                    link.handle(line.strip().decode('utf-8', 'replace'), now)
        except Exception as e:
            with ser_lock:
                if ser['h']:
                    try:
                        ser['h'].close()
                    except Exception:
                        pass
                ser['h'] = None
            if time.monotonic() - last_warn > 60 or isinstance(e, TimeoutError):
                log.warning('Serial %s: %s — thử lại sau 2 s', port or 'auto', e)
                last_warn = time.monotonic()
            time.sleep(2)


if __name__ == '__main__':
    logging.basicConfig(level=logging.INFO, format='%(asctime)s %(levelname)s %(message)s')
    main()
