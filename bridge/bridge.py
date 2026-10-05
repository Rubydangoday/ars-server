#!/usr/bin/env python3
"""bridge.py — nối gateway (USB serial) với MQTT. Không có logic nghiệp vụ.

Chiều lên:  mỗi dòng JSON gateway in ra  →  MQTT  ars/<room>/resp   (QoS 1)
            dòng JSON phải có: mac, room, type, id, q, seq, ans, batt, rssi
Chiều xuống: MQTT  ars/<ROOM>/cmd/gw  (STATE / ATTEND / OPENMASK / ACKS)  →  ghi nguyên dòng xuống serial
Khi khởi động: đọc bản giữ lại  ars/<ROOM>/state  và gửi lại các lệnh trong đó cho gateway.
Trạng thái:  ars/<ROOM>/gw/status  = {"online": true, "pkt_per_s": ...}  (Last Will = offline)

Chạy:  python3 bridge/bridge.py                  (cổng serial lấy trong config.ini)
       python3 bridge/bridge.py --port COM7      (ghi đè cổng)
       python3 bridge/bridge.py --list           (liệt kê cổng serial)
"""
import argparse
import json
import logging
import os
import sys
import threading
import time

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'common'))
import arscfg  # noqa: E402

log = logging.getLogger('bridge')


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--config')
    ap.add_argument('--port')
    ap.add_argument('--list', action='store_true')
    a = ap.parse_args()

    import serial
    import serial.tools.list_ports
    if a.list:
        for p in serial.tools.list_ports.comports():
            print(p.device, '-', p.description)
        return

    cp = arscfg.load(a.config)
    room = int(cp['gateway']['room'])
    port = a.port or cp['gateway']['serial']
    baud = int(cp['gateway'].get('baud', 115200))
    status_topic = f'ars/{room}/gw/status'

    ser_lock = threading.Lock()
    ser = {'h': None}
    counters = {'n': 0, 'bad': 0}

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
        # chỉ báo khi đang mở cổng, và một lần khi vừa mất cổng — để không đè trạng thái của
        # gateway cắm vào máy giáo viên (trang gateway.php) dùng cùng room
        was_open = None
        while True:
            time.sleep(5)
            n, counters['n'] = counters['n'], 0
            is_open = ser['h'] is not None
            if is_open or was_open is not False:
                cli.publish(status_topic, json.dumps({'online': is_open, 'port': port,
                                                      'pkt_per_s': round(n / 5, 1), 'bad_lines': counters['bad'],
                                                      'ts': time.time()}), qos=1, retain=True)
            was_open = is_open
    threading.Thread(target=report, daemon=True).start()

    while True:                                           # tự nối lại khi rút/cắm USB
        try:
            h = serial.Serial(port, baud, timeout=1)
            with ser_lock:
                ser['h'] = h
            log.info('Đã mở %s', port)
            cli.unsubscribe(f'ars/{room}/state'); cli.subscribe(f'ars/{room}/state', qos=1)  # phát lại trạng thái
            buf = b''
            while True:
                chunk = h.read(512)
                if not chunk:
                    continue
                buf += chunk
                while b'\n' in buf:
                    line, buf = buf.split(b'\n', 1)
                    line = line.strip().decode('utf-8', 'replace')
                    if not line.startswith('{'):
                        if line:
                            log.debug('gateway: %s', line)   # dòng log của firmware
                        continue
                    try:
                        pkt = json.loads(line)
                        r = int(pkt.get('room', room))
                    except ValueError:
                        counters['bad'] += 1
                        continue
                    pkt['ts'] = time.time()
                    pkt['via'] = 'ble'
                    cli.publish(f'ars/{r}/resp', json.dumps(pkt), qos=1)
                    counters['n'] += 1
        except Exception as e:
            with ser_lock:
                ser['h'] = None
            log.warning('Serial %s: %s — thử lại sau 2 s', port, e)
            time.sleep(2)


if __name__ == '__main__':
    logging.basicConfig(level=logging.INFO, format='%(asctime)s %(levelname)s %(message)s')
    main()
