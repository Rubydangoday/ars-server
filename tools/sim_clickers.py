#!/usr/bin/env python3
"""sim_clickers.py — giả lập gateway nRF52840 + N Remote nRF52840 ở tầng MQTT (không cần phần cứng).

Mỗi clicker ảo làm đúng như firmware thật:
  - điểm danh: gửi LOGIN (5 lần, cùng seq) tới khi thấy bit của mình trong ATTEND;
  - khi STATE báo câu đang mở: chờ ngẫu nhiên rồi chọn 1 chữ, gửi ANSWER 5 lần (mỗi lần có thể
    bị mất gói với xác suất --loss), chờ tối đa 2 s xem bit của mình trong STATE,
    chưa thấy thì gửi thêm 1 đợt (seq giữ nguyên).
Cuối chạy in: số lần bấm, số được xác nhận, độ trễ trung vị/phân vị 95 từ lúc bấm OK tới đèn xanh.

Ví dụ:
  python3 tools/sim_clickers.py --room 1 -n 40
  python3 tools/sim_clickers.py --room 1 -n 200 --loss 0.3 --think 1 8       (thử tải)
  python3 tools/sim_clickers.py --room 1 -n 5 --bad                          (thêm vài gói sai để xem bảng "bị loại")
"""
import argparse
import json
import os
import random
import statistics
import sys
import threading
import time

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'common'))
import arscfg  # noqa: E402

LETTERS = 'ABCDEFGHIJ'


def bits(hexstr):
    b = bytes.fromhex(hexstr)
    return {i for i in range(len(b) * 8) if b[i // 8] >> (i % 8) & 1}


class Sim:
    def __init__(self, a, cp):
        self.a, self.room = a, a.room
        self.state = {'qid': 0, 'open': 0, 'answered': set(), 'attend': set()}
        self.cv = threading.Condition()
        self.lat, self.sent, self.acked = [], 0, 0
        self.cli = arscfg.mqtt_client(f'ars-sim-{a.room}-{random.randint(0, 9999)}')
        self.cli.on_message = self.on_msg
        self.cli.connect(cp['mqtt']['host'], int(cp['mqtt'].get('port', 1883)))
        self.cli.subscribe(f'ars/{a.room}/cmd/gw', qos=1)  # như gateway: đọc lệnh STATE/ATTEND phát vào beacon
        self.cli.loop_start()

    def on_msg(self, c, u, msg):
        p = msg.payload.decode().split()
        with self.cv:
            if p[0] == 'STATE':
                self.state.update(qid=int(p[2]), open=int(p[3]), answered=bits(p[4]))
            elif p[0] == 'ATTEND':
                self.state['attend'] = bits(p[1])
            self.cv.notify_all()

    def burst(self, mac, ptype, seat, q, seq, ans):
        for _ in range(5):                                # 5 sự kiện quảng bá BLE mỗi lần bấm
            if random.random() >= self.a.loss:            # gói có thể bị va chạm/mất
                pkt = {'mac': mac, 'room': self.room, 'type': ptype, 'id': seat, 'q': q, 'seq': seq,
                       'ans': ans, 'batt': random.randint(2700, 3300), 'rssi': random.randint(-85, -45), 'via': 'ble'}
                self.cli.publish(f'ars/{self.room}/resp', json.dumps(pkt), qos=1)
            time.sleep(random.uniform(0.02, 0.03))

    def wait_bit(self, key, seat, timeout, q=None):
        end = time.time() + timeout
        with self.cv:
            while time.time() < end:
                if seat in self.state[key] and (q is None or self.state['qid'] == q):
                    return True
                self.cv.wait(end - time.time())
        return False

    def clicker(self, seat):
        mac = 'C0:DE:%02X:%02X:%02X:%02X' % (self.room, seat, random.randint(0, 255), random.randint(0, 255))
        seq = random.randint(0, 255)
        time.sleep(random.uniform(0, 2))
        for _ in range(3):                                # điểm danh
            seq = (seq + 1) & 0xFF
            self.burst(mac, 1, seat, 0, seq, '')
            if self.wait_bit('attend', seat, 2):
                break
        done_q = set()
        end = time.time() + self.a.duration
        while time.time() < end:
            with self.cv:
                q, op = self.state['qid'], self.state['open']
            if not op or q in done_q:
                with self.cv:
                    self.cv.wait(0.5)
                continue
            time.sleep(random.uniform(*self.a.think))
            with self.cv:
                if self.state['qid'] != q or not self.state['open']:
                    continue
            ans = random.choice(LETTERS[:self.a.options])
            seq = (seq + 1) & 0xFF
            t0 = time.time()
            self.sent += 1
            ok = False
            for _ in range(2):                            # 1 đợt + 1 đợt gửi lại
                self.burst(mac, 2, seat, q, seq, ans)
                if self.wait_bit('answered', seat, 2, q):
                    ok = True
                    break
            if ok:
                self.acked += 1
                self.lat.append(time.time() - t0)
            done_q.add(q)

    def bad_packets(self):
        """Vài gói sai cố ý để kiểm tra bảng 'bị loại'."""
        time.sleep(3)
        samples = [
            {'mac': 'BA:D0:00:00:00:01', 'type': 1, 'id': 98, 'q': 0, 'seq': 1, 'ans': ''},        # ghế không có trong lớp
            {'mac': 'BA:D0:00:00:00:02', 'type': 2, 'id': 3, 'q': 1, 'seq': 1, 'ans': 'A'},        # chưa điểm danh
            {'mac': 'BA:D0:00:00:00:03', 'type': 1, 'id': 1, 'q': 0, 'seq': 1, 'ans': ''},         # ghế 1 đã có máy khác
        ]
        for s in samples:
            s.update(room=self.room, batt=2500, rssi=-90)
            self.cli.publish(f'ars/{self.room}/resp', json.dumps(s), qos=1)
        self.cli.publish(f'ars/{self.room}/resp', 'khong phai json', qos=1)

    def gw_status(self):
        """Giả làm bridge báo gateway đang chạy (để web hiện 'Gateway: đang nhận')."""
        while True:
            self.cli.publish(f'ars/{self.room}/gw/status', json.dumps({'online': True, 'port': 'SIM', 'pkt_per_s': 0,
                                                                       'ts': time.time()}), qos=1, retain=True)
            time.sleep(5)

    def run(self):
        threading.Thread(target=self.gw_status, daemon=True).start()
        th = [threading.Thread(target=self.clicker, args=(s,), daemon=True) for s in range(1, self.a.n + 1)]
        for t in th:
            t.start()
        if self.a.bad:
            threading.Thread(target=self.bad_packets, daemon=True).start()
        for t in th:
            t.join()
        self.cli.publish(f'ars/{self.room}/gw/status', json.dumps({'online': False, 'port': 'SIM'}), qos=1, retain=True)
        print(f'\n{self.a.n} clicker, mất gói {self.a.loss:.0%}: bấm OK {self.sent} lần, '
              f'đèn xanh {self.acked} lần ({(self.acked / self.sent * 100) if self.sent else 0:.1f} %)')
        if self.lat:
            s = sorted(self.lat)
            print(f'Độ trễ bấm OK → đèn xanh: trung vị {statistics.median(s) * 1000:.0f} ms, '
                  f'P95 {s[int(len(s) * 0.95) - 1] * 1000:.0f} ms, lớn nhất {s[-1] * 1000:.0f} ms')


if __name__ == '__main__':
    ap = argparse.ArgumentParser()
    ap.add_argument('--config')
    ap.add_argument('--room', type=int, default=1)
    ap.add_argument('-n', type=int, default=30, help='số clicker (ghế 1..n)')
    ap.add_argument('--loss', type=float, default=0.2, help='xác suất mất mỗi gói (0–1)')
    ap.add_argument('--options', type=int, default=4, help='chọn ngẫu nhiên trong bao nhiêu chữ đầu')
    ap.add_argument('--think', type=float, nargs=2, default=[1, 6], help='thời gian suy nghĩ (giây) min max')
    ap.add_argument('--duration', type=float, default=120, help='chạy bao lâu (giây)')
    ap.add_argument('--bad', action='store_true', help='gửi thêm vài gói sai')
    a = ap.parse_args()
    Sim(a, arscfg.load(a.config)).run()
