"""Đọc config.ini dùng chung cho các chương trình Python của server."""
import configparser
import os

HERE = os.path.dirname(os.path.abspath(__file__))
DEFAULT_PATH = os.path.join(HERE, '..', 'config.ini')


def load(path=None):
    path = path or os.environ.get('ARS_CONFIG') or DEFAULT_PATH
    cp = configparser.ConfigParser(inline_comment_prefixes=(';', '#'))
    if not cp.read(path, encoding='utf-8'):
        raise SystemExit(f'Không đọc được {path} — chép config.ini.example thành config.ini rồi sửa.')
    return cp


def db_connect(cp):
    import pymysql
    return pymysql.connect(host=cp['db']['host'], port=int(cp['db'].get('port', 3306)),
                           user=cp['db']['user'], password=cp['db']['password'],
                           database=cp['db']['name'], charset='utf8mb4', autocommit=True,
                           cursorclass=pymysql.cursors.DictCursor)


def bitmap_hex(seats, nbytes=13):
    """Bit n (n = số ghế 1–99) ở byte n//8, bit n%8 (LSB trước). 13 byte = 104 bit."""
    b = bytearray(nbytes)
    for s in seats:
        s = int(s)
        if 0 <= s < nbytes * 8:
            b[s // 8] |= 1 << (s % 8)
    return b.hex().upper()


def mask64_hex(positions):
    """Mặt nạ câu đang mở: câu thứ p (1–64) ở bit p-1, 8 byte, LSB trước."""
    b = bytearray(8)
    for p in positions:
        p = int(p) - 1
        if 0 <= p < 64:
            b[p // 8] |= 1 << (p % 8)
    return b.hex().upper()


def mqtt_client(client_id, clean_session=True):
    """Tạo client paho-mqtt chạy được cả bản 1.x và 2.x.

    Bật TCP_NODELAY: ingest gửi 2 bản tin nhỏ liền nhau cho mỗi gói (ACKS + kết quả GATT); không có NODELAY thì
    thuật toán Nagle giữ bản tin thứ hai tới khi bản đầu được ACK (ACK trễ ~40 ms) → đo được ~40 ms chờ thừa
    mỗi giao dịch. (Mosquitto: set_tcp_nodelay true trong deploy/mosquitto-ars.conf, cho chiều broker → client.)"""
    import socket
    import paho.mqtt.client as mqtt
    try:
        c = mqtt.Client(mqtt.CallbackAPIVersion.VERSION1, client_id=client_id, clean_session=clean_session)
    except AttributeError:
        c = mqtt.Client(client_id=client_id, clean_session=clean_session)

    def nodelay(client, userdata, sock):
        try:
            sock.setsockopt(socket.IPPROTO_TCP, socket.TCP_NODELAY, 1)
        except (OSError, AttributeError):
            pass                                  # WebSocket / socket không phải TCP: bỏ qua
    c.on_socket_open = nodelay
    return c
