#!/usr/bin/env python3
"""sim_clickers.py — giả lập gateway nRF52840 (bridge.py) + N Remote nRF52840 ở tầng MQTT (không cần phần cứng).

Mỗi clicker ảo làm như firmware Remote V3.11 (firmware/ARS_remote_v3_10):
  - điểm danh: gửi LOGIN tới khi thấy kết quả CHÍNH XÁC (ghế, 0, seq LOGIN, 0, status) trong gói ACKS;
  - khi STATE báo câu đang mở: chờ ngẫu nhiên rồi chọn 1 chữ trong số lựa chọn của câu, phát một đợt
    ANSWER (6 sự kiện quảng bá, mỗi sự kiện có thể mất với xác suất --loss), nghe 1 s chờ ACK CHÍNH XÁC
    (ghế, câu, seq, đáp án) trong gói ACKS; chưa có thì nghỉ ngẫu nhiên 50–150 / 150–400 / 300–800 ms rồi
    gửi lại CÙNG seq, tối đa 4 lần. Gói ACKS chỉ chứa 4 bản ghi mới nhất, đúng như trên sóng.
Cuối chạy in: số lần bấm, xác nhận ở lần đầu / sau gửi lại / hết giờ, độ trễ trung vị và P95.

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
sys.path.insert(0, os.environ.get('ARS_BLECODEC_DIR') or os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'bridge'))
import arscfg  # noqa: E402
import blecodec  # noqa: E402   — chỉ "nghe" những bản ghi thật sự nằm trong gói quảng bá ACKS

LETTERS = 'ABCDEFGHIJ'


def bits(hexstr):
    b = bytes.fromhex(hexstr)
    return {i for i in range(len(b) * 8) if b[i // 8] >> (i % 8) & 1}


class Sim:
    def __init__(self, a, cp):
        self.a, self.room = a, a.room
        self.state = {'qid': 0, 'open': 0, 'n_options': 0, 'answered': set(), 'attend': set()}
        self.acks = {}                                  # ghế -> (câu, seq, đáp án, status) đã NGHE được
        self.onair = {}                                 # bản ghi (ghế, câu, seq, đáp án, status) -> lúc bắt đầu lên sóng
        self.first, self.retried, self.timeout, self.nack = 0, 0, 0, 0
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
                if len(p) >= 6:                         # STATE room qid open n_options bitmap
                    self.state.update(qid=int(p[2]), open=int(p[3]), n_options=int(p[4]), answered=bits(p[5]))
                else:
                    self.state.update(qid=int(p[2]), open=int(p[3]), answered=bits(p[4]))
            elif p[0] == 'ATTEND':
                self.state['attend'] = bits(p[1])
            elif p[0] == 'ACKS':
                # Một bản ghi chỉ được "nghe" nếu nằm trên sóng liên tục ≥ --ack-dwell (quảng bá ACK 40–60 ms/lần,
                # đổi nội dung = BlueZ đăng ký lại): gói ACKS đổi liên tục thì Remote thật không kịp bắt.
                enc = blecodec.encode_cmd(msg.payload.decode(), self.room)
                frame = enc[1] if enc else b''
                now = time.time()
                new = {tuple(frame[4 + 5 * k: 9 + 5 * k]) for k in range(frame[3] if len(frame) >= 4 else 0)}
                for rec, since in list(self.onair.items()):
                    if rec not in new:
                        if now - since >= self.a.ack_dwell:
                            self.acks[rec[0]] = rec[1:]
                        del self.onair[rec]
                for rec in new:
                    self.onair.setdefault(rec, now)
            self.cv.notify_all()

    def burst(self, mac, ptype, seat, q, seq, ans):
        for _ in range(6):                                # đợt phát 150 ms, chu kỳ 25 ms = 6 sự kiện quảng bá
            if random.random() >= self.a.loss:            # gói có thể bị va chạm/mất
                pkt = {'mac': mac, 'room': self.room, 'type': ptype, 'id': seat, 'q': q, 'seq': seq,
                       'ans': ans, 'batt': random.randint(2700, 3300), 'rssi': random.randint(-85, -45), 'via': 'ble'}
                self.cli.publish(f'ars/{self.room}/resp', json.dumps(pkt), qos=1)
            time.sleep(random.uniform(0.02, 0.03))

    def wait_ack(self, seat, q, seq, ans_code, timeout):
        """Nghe ACK chính xác như Remote: đúng ghế, câu, seq, đáp án (status 0 = nhận, >0 = NACK)."""
        end = time.time() + timeout
        with self.cv:
            while time.time() < end:
                now = time.time()
                for rec, since in self.onair.items():   # đang trên sóng đủ lâu → nghe được
                    if rec[0] == seat and now - since >= self.a.ack_dwell:
                        self.acks[seat] = rec[1:]
                r = self.acks.get(seat)
                if r and r[:3] == (q, seq, ans_code):
                    return r[3]
                self.cv.wait(min(0.02, max(0.0, end - time.time())))
        return None

    def clicker(self, seat):
        mac = 'C0:DE:%02X:%02X:%02X:%02X' % (self.room, seat, random.randint(0, 255), random.randint(0, 255))
        seq = random.randint(0, 255)
        time.sleep(random.uniform(0, 2))
        for _ in range(3):                                # điểm danh (V3.11: kết quả chính xác, câu 0, đáp án 0)
            seq = (seq + 1) & 0xFF
            self.burst(mac, 1, seat, 0, seq, '')
            if self.wait_ack(seat, 0, seq, 0, 2) == 0:
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
            with self.cv:
                nopt = self.state['n_options'] or self.a.options
            ans = random.choice(LETTERS[:nopt])
            seq = (seq + 1) & 0xFF
            t0 = time.time()
            self.sent += 1
            status = None
            for attempt in range(4):                      # 1 lần đầu + 3 lần gửi lại, cùng seq
                self.burst(mac, 2, seat, q, seq, ans)
                status = self.wait_ack(seat, q, seq, LETTERS.index(ans) + 1, 1.0)
                if status is not None:
                    break
                if attempt < 3:
                    lo, hi = ((0.05, 0.15), (0.15, 0.4), (0.3, 0.8))[attempt]
                    time.sleep(random.uniform(lo, hi))
            if status is None:
                self.timeout += 1
            elif status != 0:
                self.nack += 1                            # server từ chối (NACK chính xác), không phải đèn xanh
            else:
                self.acked += 1
                self.lat.append(time.time() - t0)
                if attempt == 0:
                    self.first += 1
                else:
                    self.retried += 1
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
              f'ACK chính xác {self.acked} lần ({(self.acked / self.sent * 100) if self.sent else 0:.1f} %) — '
              f'lần đầu {self.first}, sau gửi lại {self.retried}; NACK {self.nack}; hết giờ {self.timeout}')
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
    ap.add_argument('--ack-dwell', type=float, default=0.1,
                    help='bản ghi ACK phải nằm trên sóng liên tục bao lâu (giây) thì Remote mới nghe được (mặc định 0,1)')
    a = ap.parse_args()
    Sim(a, arscfg.load(a.config)).run()
