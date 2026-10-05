#!/usr/bin/env python3
"""ingest.py — trái tim của server ARS.

1. Nghe MQTT  ars/<room>/resp  (gói thô do bridge.py chuyển từ gateway lên).
2. Kiểm tra hợp lệ TOÀN BỘ ở đây: loại gói trùng, buổi học, ghế ↔ MAC, câu đang mở,
   thời hạn, số lựa chọn, có cho đổi đáp án không. Gói hợp lệ ghi vào `responses`,
   gói bị loại ghi vào `rejected` kèm lý do.
3. Soạn trạng thái cho gateway phát beacon rồi gửi xuống  ars/<room>/cmd/gw :
     STATE <room> <qid> <open 0|1> <bitmap 13 byte hex>   (ai đã có đáp án câu qid)
     ATTEND <bitmap 13 byte hex>                          (ai đã điểm danh)
     OPENMASK <mặt nạ 8 byte hex>                         (câu nào đang mở, cho ◀ ▶)
     ACKS <ghế:câu,...>                                   (6 xác nhận gần nhất)
   và bản sao JSON giữ lại (retain) ở  ars/<room>/state  để bridge khởi động lại vẫn phát đúng.
4. Đẩy số liệu trực tiếp lên  ars/<room>/live  (trang web cũng đọc được qua PHP).

Web PHP không nói chuyện MQTT: nó chỉ ghi DB và tăng sessions.state_rev;
ingest thấy state_rev đổi thì phát lại STATE ngay (≤ 0,3 s).

Chạy:  python3 ingest/ingest.py            (đọc ../config.ini)
"""
import json
import logging
import os
import sys
import threading
import time

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'common'))
import arscfg  # noqa: E402

log = logging.getLogger('ingest')
LETTERS = 'ABCDEFGHIJ'
PKT_LOGIN, PKT_ANSWER = 1, 2


class Ingest:
    def __init__(self, cp):
        self.cp = cp
        self.db = arscfg.db_connect(cp)          # luồng MQTT
        self.db_state = arscfg.db_connect(cp)    # luồng soạn STATE
        self.lock = threading.Lock()
        self.dirty = set()                       # room cần phát lại STATE
        self.acks = {}                           # room -> [(ghế, câu)] gần nhất
        self.refresh = float(cp['ingest'].get('state_refresh_s', 5))
        self.client = arscfg.mqtt_client('ars-ingest', clean_session=False)
        self.client.on_connect = self.on_connect
        self.client.on_message = self.on_message
        self.stats = {'ok': 0, 'dup': 0, 'rej': 0}
        self.recent_rej = {}

    # ------------------------------------------------------------------ MQTT
    def on_connect(self, client, userdata, flags, rc):
        log.info('MQTT đã kết nối (rc=%s)', rc)
        client.subscribe('ars/+/resp', qos=1)
        client.subscribe('ars/+/gw/status', qos=1)

    def on_message(self, client, userdata, msg):
        raw = msg.payload.decode('utf-8', 'replace')[:400]
        if msg.topic.endswith('/gw/status'):
            return self.gw_status(msg.topic, raw)
        try:
            pkt = json.loads(raw)
        except ValueError:
            self.reject(None, None, {}, 'malformed', raw)
            return
        try:
            with self.lock:
                self.process(pkt, raw)
        except Exception:                                   # không để 1 gói lỗi làm dừng server
            log.exception('lỗi xử lý gói %s', raw)
            try:
                self.db.ping(reconnect=True)
            except Exception:
                pass

    def gw_status(self, topic, raw):
        try:
            room = int(topic.split('/')[1])
            st = json.loads(raw)
            with self.lock:
                self.x('INSERT INTO gateways (room_id, online, pkt_per_s, port, updated_at) VALUES (%s,%s,%s,%s,NOW()) '
                       'ON DUPLICATE KEY UPDATE online=VALUES(online), pkt_per_s=VALUES(pkt_per_s), '
                       'port=VALUES(port), updated_at=NOW()',
                       (room, 1 if st.get('online') else 0, st.get('pkt_per_s'), st.get('port')))
        except Exception:
            log.warning('gw/status không đọc được: %s', raw)

    # ------------------------------------------------------------- xử lý gói
    def q(self, sql, args=(), one=False):
        with self.db.cursor() as c:
            c.execute(sql, args)
            return c.fetchone() if one else c.fetchall()

    def x(self, sql, args=()):
        with self.db.cursor() as c:
            return c.execute(sql, args)

    def reject(self, room, session_id, pkt, reason, raw):
        self.stats['rej'] += 1
        # 5 bản lặp của cùng một lần bấm chỉ ghi 1 dòng vào bảng rejected
        key = (pkt.get('mac'), pkt.get('seq'), pkt.get('type'), reason)
        now = time.time()
        if key[0] is not None and now - self.recent_rej.get(key, 0) < 10:
            return
        self.recent_rej[key] = now
        if len(self.recent_rej) > 5000:
            self.recent_rej = {k: v for k, v in self.recent_rej.items() if now - v < 10}
        self.x('INSERT INTO rejected (received_at, room_id, session_id, mac, pkt_type, seat_no, qid, answer, reason, raw) '
               'VALUES (NOW(3),%s,%s,%s,%s,%s,%s,%s,%s,%s)',
               (room, session_id, pkt.get('mac'), pkt.get('type'), pkt.get('id'), pkt.get('q'),
                (pkt.get('ans') or None), reason, raw))
        log.info('LOẠI %-14s %s', reason, raw)

    def process(self, pkt, raw):
        # 1. định dạng
        try:
            mac = str(pkt['mac']).upper()[:17]
            ptype = int(pkt['type'])
            room = int(pkt['room'])
            seat = int(pkt['id'])
            seq = int(pkt['seq']) & 0xFF
            qid = int(pkt.get('q', 0))
            ans = str(pkt.get('ans') or '').upper()[:1]
            batt = int(pkt['batt']) if pkt.get('batt') is not None else None
            rssi = int(pkt['rssi']) if pkt.get('rssi') is not None else None
            via = 'wifi' if str(pkt.get('via', 'ble')).lower() == 'wifi' else 'ble'   # gói không ghi = qua gateway Bluetooth
        except (KeyError, ValueError, TypeError):
            self.reject(pkt.get('room') if isinstance(pkt, dict) else None, None, pkt if isinstance(pkt, dict) else {}, 'malformed', raw)
            return
        if len(mac) != 17 or ptype not in (PKT_LOGIN, PKT_ANSWER) or not 0 <= room <= 255:
            self.reject(room, None, pkt, 'malformed', raw)
            return

        # 2. ghi nhận thiết bị (pin, RSSI, lần cuối thấy) — cả gói trùng
        self.x('INSERT INTO devices (mac, last_seen, last_batt_mv, last_rssi, last_room, last_via, pkt_total) '
               'VALUES (%s,NOW(),%s,%s,%s,%s,1) ON DUPLICATE KEY UPDATE last_seen=NOW(), '
               'last_batt_mv=COALESCE(VALUES(last_batt_mv), last_batt_mv), last_rssi=VALUES(last_rssi), '
               'last_room=VALUES(last_room), last_via=VALUES(last_via), pkt_total=pkt_total+1', (mac, batt, rssi, room, via))

        # 3. buổi học đang chạy của lớp có room này
        ses = self.q('SELECT s.*, c.conn_mode FROM sessions s JOIN classes c ON c.id=s.class_id '
                     'WHERE c.room_id=%s AND s.status="running" ORDER BY s.id DESC LIMIT 1', (room,), one=True)
        if not ses:
            self.reject(room, None, pkt, 'no_session', raw)
            return
        sid = ses['id']
        # 4. lớp chọn Bluetooth thì chỉ nhận gói qua gateway, chọn Wi-Fi thì chỉ nhận gói clicker Wi-Fi
        if via != (ses.get('conn_mode') or 'ble'):
            self.reject(room, sid, pkt, 'wrong_link', raw)
            return

        if ptype == PKT_LOGIN:
            self.login(ses, room, mac, seat, pkt, raw)
        else:
            self.answer(ses, room, mac, seat, qid, seq, ans, rssi, pkt, raw)

    def dup(self, mac):
        self.stats['dup'] += 1
        self.x('UPDATE devices SET pkt_dup=pkt_dup+1 WHERE mac=%s', (mac,))

    def login(self, ses, room, mac, seat, pkt, raw):
        sid = ses['id']
        if not self.q('SELECT 1 FROM students WHERE class_id=%s AND seat_no=%s', (ses['class_id'], seat), one=True):
            return self.reject(room, sid, pkt, 'unknown_seat', raw)
        mapping = self.q('SELECT mac FROM device_mappings WHERE class_id=%s AND seat_no=%s', (ses['class_id'], seat), one=True)
        other = self.q('SELECT seat_no FROM device_mappings WHERE class_id=%s AND mac=%s', (ses['class_id'], mac), one=True)
        if (mapping and mapping['mac'].upper() != mac.upper()) or (other and other['seat_no'] != seat):
            return self.reject(room, sid, pkt, 'mapping_mismatch', raw)
        by_seat = self.q('SELECT mac FROM session_logins WHERE session_id=%s AND seat_no=%s', (sid, seat), one=True)
        if by_seat:
            if by_seat['mac'] == mac:
                return self.dup(mac)                     # đã điểm danh rồi (gói lặp)
            return self.reject(room, sid, pkt, 'seat_taken', raw)   # ghế đã có máy khác
        by_mac = self.q('SELECT seat_no FROM session_logins WHERE session_id=%s AND mac=%s', (sid, mac), one=True)
        if by_mac:
            return self.reject(room, sid, pkt, 'mac_bound', raw)    # máy này đã gắn ghế khác
        self.x('INSERT INTO session_logins (session_id, seat_no, mac, logged_at) VALUES (%s,%s,%s,NOW(3))',
               (sid, seat, mac))
        self.x('UPDATE devices SET last_seat=%s WHERE mac=%s', (seat, mac))
        self.stats['ok'] += 1
        self.dirty.add(room)
        log.info('ĐIỂM DANH ghế %02d  %s', seat, mac)

    def answer(self, ses, room, mac, seat, qid, seq, ans, rssi, pkt, raw):
        sid = ses['id']
        mapping = self.q('SELECT mac FROM device_mappings WHERE class_id=%s AND seat_no=%s', (ses['class_id'], seat), one=True)
        other = self.q('SELECT seat_no FROM device_mappings WHERE class_id=%s AND mac=%s', (ses['class_id'], mac), one=True)
        if (mapping and mapping['mac'].upper() != mac.upper()) or (other and other['seat_no'] != seat):
            return self.reject(room, sid, pkt, 'mapping_mismatch', raw)
        lg = self.q('SELECT seat_no FROM session_logins WHERE session_id=%s AND mac=%s', (sid, mac), one=True)
        if not lg:
            return self.reject(room, sid, pkt, 'not_logged_in', raw)
        if lg['seat_no'] != seat:
            return self.reject(room, sid, pkt, 'id_mismatch', raw)
        sq = self.q('SELECT sq.*, q.n_options, q.position, (sq.deadline IS NOT NULL AND sq.deadline < NOW(3)) AS late '
                    'FROM session_questions sq '
                    'JOIN questions q ON q.id=sq.question_id '
                    'WHERE sq.session_id=%s AND q.set_id=%s AND q.position=%s', (sid, ses['set_id'], qid), one=True)
        if not sq:
            return self.reject(room, sid, pkt, 'q_not_open', raw)
        if self.q('SELECT 1 FROM responses WHERE session_q_id=%s AND mac=%s AND seq=%s', (sq['id'], mac, seq), one=True):
            return self.dup(mac)                         # 1 trong 5 lần phát của cùng 1 lần bấm OK
        if sq['status'] != 'open':
            return self.reject(room, sid, pkt, 'q_closed', raw)
        if sq['late']:
            return self.reject(room, sid, pkt, 'late', raw)
        if len(ans) != 1 or ans not in LETTERS or LETTERS.index(ans) >= sq['n_options']:
            return self.reject(room, sid, pkt, 'bad_option', raw)
        if not ses['allow_change'] and self.q('SELECT 1 FROM responses WHERE session_q_id=%s AND seat_no=%s',
                                              (sq['id'], seat), one=True):
            return self.reject(room, sid, pkt, 'no_change', raw)
        n = self.x('INSERT IGNORE INTO responses (session_q_id, seat_no, mac, seq, answer, rssi, received_at) '
                   'VALUES (%s,%s,%s,%s,%s,%s,NOW(3))', (sq['id'], seat, mac, seq, ans, rssi))
        if n == 0:
            return self.dup(mac)
        self.stats['ok'] += 1
        self.acks.setdefault(room, []).append((seat, sq['position']))
        self.acks[room] = self.acks[room][-6:]
        self.dirty.add(room)
        log.info('NHẬN   ghế %02d câu %d → %s', seat, sq['position'], ans)

    # ------------------------------------------------------- soạn trạng thái
    def s(self, sql, args=(), one=False):
        with self.db_state.cursor() as c:
            c.execute(sql, args)
            return c.fetchone() if one else c.fetchall()

    def build_state(self, room):
        ses = self.s('SELECT s.*, c.conn_mode FROM sessions s JOIN classes c ON c.id=s.class_id '
                     'WHERE c.room_id=%s AND s.status="running" ORDER BY s.id DESC LIMIT 1', (room,), one=True)
        st = {'room': room, 'session_id': None, 'mode': None, 'qid': 0, 'open': 0, 'answered': [], 'attend': [],
              'open_positions': [], 'acks': self.acks.get(room, []), 'ts': time.time()}
        if not ses:
            return st
        st['mode'] = ses.get('conn_mode') or 'ble'
        sid = ses['id']
        st['session_id'] = sid
        st['attend'] = [r['seat_no'] for r in self.s('SELECT seat_no FROM session_logins WHERE session_id=%s', (sid,))]
        st['open_positions'] = [r['position'] for r in self.s(
            'SELECT q.position FROM session_questions sq JOIN questions q ON q.id=sq.question_id '
            'WHERE sq.session_id=%s AND sq.status="open" ORDER BY q.position', (sid,))]
        if ses['current_q']:
            cur = self.s('SELECT sq.id, sq.status, q.position, q.n_options FROM session_questions sq '
                         'JOIN questions q ON q.id=sq.question_id WHERE sq.session_id=%s AND sq.question_id=%s',
                         (sid, ses['current_q']), one=True)
            if cur:
                st['qid'] = cur['position']
                st['open'] = 1 if cur['status'] == 'open' else 0
                st['answered'] = [r['seat_no'] for r in self.s(
                    'SELECT DISTINCT seat_no FROM responses WHERE session_q_id=%s', (cur['id'],))]
                dist = {r['answer']: r['n'] for r in self.s(
                    'SELECT answer, COUNT(*) n FROM final_answers WHERE session_q_id=%s GROUP BY answer', (cur['id'],))}
                st['live'] = {'n_answered': len(st['answered']), 'n_logged': len(st['attend']),
                              'dist': {LETTERS[i]: dist.get(LETTERS[i], 0) for i in range(cur['n_options'])}}
        return st

    def publish_state(self, room):
        st = self.build_state(room)
        cmds = [f"STATE {room} {st['qid']} {st['open']} {arscfg.bitmap_hex(st['answered'])}",
                f"ATTEND {arscfg.bitmap_hex(st['attend'])}",
                f"OPENMASK {arscfg.mask64_hex(st['open_positions'])}",
                "ACKS " + (','.join(f'{a}:{b}' for a, b in st['acks']) or '-')]
        st['cmds'] = cmds
        for c in cmds:
            self.client.publish(f'ars/{room}/cmd/gw', c, qos=1)
        self.client.publish(f'ars/{room}/state', json.dumps(st), qos=1, retain=True)
        if 'live' in st:
            self.client.publish(f'ars/{room}/live', json.dumps(st['live']), qos=0)

    def state_loop(self):
        """Theo dõi thay đổi do web gây ra (state_rev), tự đóng câu hết giờ, phát lại STATE."""
        last_rev, last_sent = {}, {}
        while True:
            try:
                rows = self.s('SELECT c.room_id, s.id, s.state_rev FROM sessions s JOIN classes c ON c.id=s.class_id '
                              'WHERE s.status="running"')
                # câu hết giờ → đóng
                expired = self.s('SELECT sq.id, sq.session_id FROM session_questions sq JOIN sessions s ON s.id=sq.session_id '
                                 'WHERE s.status="running" AND sq.status="open" AND sq.deadline IS NOT NULL AND sq.deadline < NOW(3)')
                for e in expired:
                    with self.db_state.cursor() as c:
                        c.execute('UPDATE session_questions SET status="closed", closed_at=NOW(3) WHERE id=%s', (e['id'],))
                        c.execute('UPDATE sessions SET state_rev=state_rev+1 WHERE id=%s', (e['session_id'],))
                    log.info('Hết giờ: đóng session_question %s', e['id'])
                rooms_running = set()
                for r in rows:
                    room = r['room_id']
                    rooms_running.add(room)
                    key = (r['id'], r['state_rev'])
                    if last_rev.get(room) != key:
                        last_rev[room] = key
                        self.dirty.add(room)
                # lớp vừa kết thúc buổi → phát trạng thái rỗng một lần
                for room in list(last_rev):
                    if room not in rooms_running:
                        del last_rev[room]
                        self.dirty.add(room)
                t = time.time()
                for room in rooms_running:
                    if t - last_sent.get(room, 0) > self.refresh:
                        self.dirty.add(room)
                with self.lock:
                    todo, self.dirty = self.dirty, set()
                for room in todo:
                    self.publish_state(room)
                    last_sent[room] = t
            except Exception:
                log.exception('lỗi vòng trạng thái')
                try:
                    self.db_state.ping(reconnect=True)
                except Exception:
                    time.sleep(2)
            time.sleep(0.3)

    def run(self):
        self.client.connect(self.cp['mqtt']['host'], int(self.cp['mqtt'].get('port', 1883)), keepalive=30)
        threading.Thread(target=self.state_loop, daemon=True).start()

        def report():
            while True:
                time.sleep(30)
                log.info('Thống kê: nhận %(ok)d, trùng %(dup)d, loại %(rej)d', self.stats)
        threading.Thread(target=report, daemon=True).start()
        self.client.loop_forever()


if __name__ == '__main__':
    logging.basicConfig(level=logging.INFO, format='%(asctime)s %(levelname)s %(message)s')
    Ingest(arscfg.load(sys.argv[1] if len(sys.argv) > 1 else None)).run()
