"""V3.11 single-remote NUS framing and exact backend-result correlation."""
import secrets
import time
import blecodec
SERVICE_UUID = '6e400001-b5a3-f393-e0a9-e50e24dcca9e'
RX_UUID = '6e400002-b5a3-f393-e0a9-e50e24dcca9e'  # central writes
TX_UUID = '6e400003-b5a3-f393-e0a9-e50e24dcca9e'  # peripheral notifies
HELLO, HELLO_REPLY, PING, PONG = 0x20, 0x21, 0x22, 0x23
LOGIN_RESULT = 0x14


def frame(payload):
    payload = bytes(payload)
    if not 2 <= len(payload) <= 32 or payload[0] != 0xA5:
        raise ValueError('Invalid ARS frame')
    return bytes([len(payload)]) + payload


class Decoder:
    def __init__(self):
        self.buffer = bytearray()

    def feed(self, data):
        self.buffer.extend(data)
        out = []
        while self.buffer:
            n = self.buffer[0]
            if not 2 <= n <= 32:
                self.buffer.clear()
                raise ValueError('Invalid frame length; reconnect to resynchronize')
            if len(self.buffer) < n + 1:
                break
            p = bytes(self.buffer[1:n + 1])
            del self.buffer[:n + 1]
            if p[0] != 0xA5:
                self.buffer.clear()
                raise ValueError('Invalid frame magic')
            out.append(p)
        return out


class Transactions:
    """Results must match this connection's request token AND all packet fields.

    Tokens are not persisted/replayed across reconnect. DB keeps existing dedup.
    MQTT retained ACKS/ATTEND can never complete a transaction here.
    """
    def __init__(self, room, mac):
        self.room, self.mac = room, mac.upper()
        self.pending = {}

    def request(self, payload, now=None):
        now = time.monotonic() if now is None else now
        if len(payload) != 9:
            raise ValueError('Uplink must have exactly 9 bytes')
        pkt = blecodec.decode_uplink(payload, self.mac)
        if not pkt or pkt['room'] != self.room or not 1 <= pkt['id'] <= 99:
            raise ValueError('Wrong room, seat, or uplink type')
        if pkt['type'] == 2 and (not pkt['ans'] or not 1 <= pkt['q'] <= 99):
            raise ValueError('Invalid answer')
        self.pending = {k: v for k, v in self.pending.items() if now - v['created'] < 10}
        key = bytes(payload[:7])
        if key not in self.pending:
            self.pending[key] = {'created': now, 'request_id': secrets.token_hex(16), 'pkt': pkt.copy()}
        pkt.update(via='ble', transport='gatt', ts=time.time(),
                   gatt_request=self.pending[key]['request_id'])
        return pkt

    def result(self, result, now=None):
        now = time.monotonic() if now is None else now
        for item in self.pending.values():
            p = item['pkt']
            if now - item['created'] >= 10 or result.get('gatt_request') != item['request_id']:
                continue
            if any(result.get(k) != p[k] for k in ('room', 'mac', 'type', 'id', 'q', 'seq', 'ans')):
                return None
            status = result.get('status')
            if type(status) is not int or not 0 <= status <= 255:
                return None
            if p['type'] == 1:
                return bytes([0xA5, LOGIN_RESULT, self.room, p['id'], p['seq'], status])
            code = blecodec.LETTERS.index(p['ans']) + 1
            return bytes([0xA5, 0x13, self.room, 1, p['id'], p['q'], p['seq'], code, status])
        return None
