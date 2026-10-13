"""bridge.py (gateway USB ⇄ MQTT) với cổng serial giả: tự tìm cổng gateway, không nhận nhầm Remote, xử lý dòng, canh chừng.
Không cần phần cứng, MQTT hay pyserial. Chạy: python3 tests/bridge_unit.py [smoke.json bỏ qua]
"""
import json
import sys
from pathlib import Path
from types import SimpleNamespace

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'bridge'))
sys.path.insert(0, str(ROOT / 'common'))
import bridge  # noqa: E402

checks = []


def check(x, msg):
    assert x, msg
    checks.append(msg)


class FakePort:
    """Cổng serial giả: trả lời lệnh ghi xuống theo hàm reply(bytes) → bytes."""

    def __init__(self, reply):
        self.reply, self.out, self.closed = reply, b'', False

    def write(self, data):
        self.out += self.reply(data)

    def read(self, n):
        chunk, self.out = self.out[:n], self.out[n:]
        return chunk

    def close(self):
        self.closed = True


def gateway_reply(data):
    return b'# ARS-GW 3.11\r\n' if b'v' in data else b''


def remote_reply(data):        # Remote V3.11: in log riêng, không biết lệnh 'v'
    return b'SERVER STATE q=1 open=1 options=4\r\n'


opened = {}


def opener(table):
    def open_port(dev):
        if table[dev] == 'busy':
            raise OSError('Device or resource busy')
        p = FakePort(table[dev])
        opened[dev] = p
        return p
    return open_port


port = lambda dev, vid: SimpleNamespace(device=dev, vid=vid)   # noqa: E731

# ---- tìm cổng ----
ports = [port('/dev/ttyS0', None), port('/dev/ttyUSB0', 0x1A86), port('/dev/ttyACM0', 0x239A), port('/dev/ttyACM1', 0x239A)]
check(bridge.candidate_ports(ports) == ['/dev/ttyACM0', '/dev/ttyACM1', '/dev/ttyUSB0'],
      'Candidate ports: SuperMini VID 0x239A first, other USB ports next, built-in UARTs skipped')
table = {'/dev/ttyACM0': remote_reply, '/dev/ttyACM1': gateway_reply, '/dev/ttyUSB0': remote_reply}
bridge.PROBE_WAIT_S = 0.2
dev, fw = bridge.find_gateway(ports, opener(table))
check(dev == '/dev/ttyACM1' and fw == 'ARS-GW 3.11', 'Auto-detect picks the port answering "# ARS-GW", not the Remote on ttyACM0')
check(all(p.closed for p in opened.values()), 'Every probed port is closed again (Remote / gateway not left busy)')
check(bridge.probe(opener({'/dev/ttyACM0': 'busy'}), '/dev/ttyACM0', 0.1) is None,
      'Busy port (Serial Monitor / Arduino IDE holding it) is skipped without error')
check(bridge.find_gateway(ports[:1] + ports[2:3], opener(table)) == (None, None), 'No gateway plugged in → (None, None)')

boot = [port('/dev/ttyACM0', 0x239A)]
boot[0].pid = 0x00B3                                   # nice!nano bootloader: Arduino IDE đang nạp
opened.clear()
check(bridge.candidate_ports(boot) == [] and bridge.find_gateway(boot, opener({'/dev/ttyACM0': gateway_reply})) == (None, None)
      and not opened, 'Board in bootloader (PID < 0x8000, firmware upload running) is never opened')
seen_not_gw = {}
bridge.find_gateway(ports, opener(table), seen_not_gw, now=1000.0)
opened.clear()
table['/dev/ttyACM1'] = remote_reply                   # gateway rút ra
check(bridge.find_gateway(ports, opener(table), seen_not_gw, now=1010.0) == (None, None) and '/dev/ttyACM0' not in opened,
      'A port that answered "not a gateway" is not probed again for NOT_GW_RETRY_S (Remote / Serial Monitor left alone)')
table['/dev/ttyACM0'] = gateway_reply                  # nạp firmware gateway vào board ở ttyACM0
check(bridge.find_gateway(ports, opener(table), seen_not_gw, now=1000.0 + bridge.NOT_GW_RETRY_S + 1)[0] == '/dev/ttyACM0',
      'After NOT_GW_RETRY_S the port is probed again (board re-flashed as gateway is found)')
swapped = [port('/dev/ttyACM0', 0x239A)]
swapped[0].serial_number = 'D52BED96C6CBC809'          # rút Remote, cắm gateway vào đúng cổng đó
table2 = {'/dev/ttyACM0': gateway_reply}
recent = {('/dev/ttyACM0', '896425C113102C01'): 5000.0}  # Remote vừa được hỏi ở ttyACM0
check(bridge.find_gateway(swapped, opener(table2), recent, now=5001.0)[0] == '/dev/ttyACM0',
      'A different board (new USB serial number) on the same ttyACM path is probed at once, not after 60 s')

# ---- xử lý dòng ----
sent = []
link = bridge.GatewayLink(1, lambda t, p: sent.append((t, json.loads(p))))
line = '{"mac":"C4:1A:00:11:22:33","room":7,"type":2,"id":2,"q":1,"seq":12,"ans":"B","batt":2950,"rssi":-61}'
link.handle(line, 100.0)
check(len(sent) == 1 and sent[0][0] == 'ars/7/resp' and sent[0][1]['via'] == 'ble' and 'ts' in sent[0][1] and link.n == 1,
      'JSON line → MQTT ars/<room of packet>/resp with via=ble and ts')
link.handle('{"mac": broken', 101.0)
link.handle('{"room": "x"}', 101.0)
check(link.bad == 2 and len(sent) == 1, 'Broken JSON / bad room is counted as bad, not published')
link.handle('# STAT rx=12 fwd=2 dup=10 probe=0 qdrop=0 usbdrop=0 cmd=4 bad=0 wait=0 room=1 adv=on upd=40 adverr=0', 102.0)
check(link.stat['rx'] == 12 and link.stat['adv'] == 'on' and link.stat['room'] == 1, '"# STAT" parsed into gateway statistics')
link.handle('# ARS-GW 3.11', 103.0)
check(link.fw == 'ARS-GW 3.11', 'Firmware version captured from the gateway')
link.handle('# server ON', 104.0)
check(len(sent) == 1, 'Gateway log lines are never published as packets')

# ---- canh chừng ----
check(link.alive(104.0 + bridge.SILENCE_S - 1) and not link.alive(104.0 + bridge.SILENCE_S + 1),
      f'Gateway silent > {bridge.SILENCE_S} s (STAT every 5 s missing) → not alive (port reopened, status offline)')

print(json.dumps({'passed': len(checks), 'checks': checks}, ensure_ascii=False, indent=2))
