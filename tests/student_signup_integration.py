"""python3 tests/student_signup_integration.py /tmp/ars-smoke.json
Học sinh tự đăng ký khi chưa có lớp (migration 008): tạo tài khoản không cần mã lớp, đăng nhập, vào lớp bằng mã ở trang Tổng quan,
đánh dấu "tự tạo, cần kiểm tra" khi giáo viên tự thêm mã đó vào danh sách, "đặt lại MK" thu hồi cả liên kết Google. Chạy sau http_smoke.py.
"""
import os, sys, json, configparser, secrets
import requests, pymysql
from bs4 import BeautifulSoup

BASE = os.environ.get('TEST_BASE', 'http://127.0.0.1:8091/')
d = json.load(open(sys.argv[1]))['fixture']
cfg = configparser.ConfigParser(); cfg.read(os.environ['ARS_CONFIG'])
db = pymysql.connect(host=cfg['db']['host'], port=int(cfg['db'].get('port', 3306)), user=cfg['db']['user'], password=cfg['db']['password'],
                     database=cfg['db']['name'], autocommit=True, cursorclass=pymysql.cursors.DictCursor)
def sql1(q, a=()):
    with db.cursor() as c: c.execute(q, a); r = c.fetchall(); return r[0] if r else None

checks = []
def expect(test, msg):
    assert test, msg
    checks.append(msg)
def csrf(s, url):
    soup = BeautifulSoup(s.get(BASE + url).text, 'html.parser'); t = soup.select_one('input[name=csrf]') or soup.select_one('meta[name=csrf]')
    return t.get('value', t.get('content'))
def post(s, url, data, page=None):
    r = s.post(BASE + url, data={'csrf': csrf(s, page or url), **data})
    expect(all(x not in r.text for x in ['Fatal error', 'Warning:', 'Deprecated:']), 'No PHP errors POST ' + url); return r
def flash(r): return ' '.join(x.get_text(' ', strip=True) for x in BeautifulSoup(r.text, 'html.parser').select('.flash'))

cls = d['class']; pw = 'Matkhau-2026'
code = 'HS' + secrets.token_hex(4).upper()
join = sql1('SELECT join_code FROM classes WHERE id=%s', (cls,))['join_code']

# 1. Đăng ký không cần mã lớp
s = requests.Session()
r = post(s, 'student/register.php', {'code': code, 'name': 'Học Sinh Tự Do', 'join': '', 'p1': pw, 'p2': pw})
expect(r.url.endswith('student/home.php') and 'Vào lớp học' in r.text and 'Học Sinh Tự Do' in r.text, 'Register without class code → home with join box')
u = sql1('SELECT * FROM student_users WHERE student_code=%s', (code,))
expect(u and u['full_name'] == 'Học Sinh Tự Do' and u['unverified'] == 1, 'Classless account stored with name, marked unverified')
expect(sql1('SELECT COUNT(*) n FROM students WHERE student_code=%s', (code,))['n'] == 0, 'Not added to any class')
for page in ['home.php', 'wrong.php', 'practice.php', 'lectures.php', 'homework.php', 'rewards.php', 'lessons.php', 'right.php']:
    r = s.get(BASE + 'student/' + page); expect(r.status_code == 200 and 'Fatal error' not in r.text and 'Warning:' not in r.text, 'Classless page OK ' + page)

# 2. Đăng nhập lại khi chưa có lớp; mật khẩu mặc định = MSSV không dùng được
s2 = requests.Session(); r = post(s2, 'student/index.php', {'code': code, 'password': code})
expect('home.php' not in r.url, 'Default password (= MSSV) rejected for classless account')
r = post(s2, 'student/index.php', {'code': code, 'password': pw}); expect(r.url.endswith('student/home.php'), 'Classless student logs in with own password')
r = post(requests.Session(), 'student/index.php', {'code': 'KHONGCO' + secrets.token_hex(2), 'password': 'x'}); expect('chưa có tài khoản' in flash(r), 'Unknown code: clear message')

# 3. Vào lớp bằng mã ở trang Tổng quan
r = post(s2, 'student/home.php', {'action': 'join', 'join': 'SAIMA1'}); expect('Mã lớp không đúng' in flash(r), 'Wrong class code rejected')
r = post(s2, 'student/home.php', {'action': 'join', 'join': join.lower()})
expect('Bạn đã vào lớp' in flash(r) and sql1('SELECT COUNT(*) n FROM students WHERE student_code=%s AND class_id=%s', (code, cls))['n'] == 1, 'Join class by code from home')
expect(sql1('SELECT unverified FROM student_users WHERE student_code=%s', (code,))['unverified'] == 0, 'Joining by code clears unverified')
r = post(s2, 'student/home.php', {'action': 'join', 'join': join}); expect('đã ở trong lớp' in flash(r), 'Joining twice is harmless')
expect('Vào thêm lớp khác' in s2.get(BASE + 'student/home.php').text, 'Small join form when already in a class')
r = s2.get(BASE + 'student/register.php?code=' + join); expect(r.url.endswith('home.php?join=' + join) and f'value="{join}"' in r.text, '/hs/CODE when logged in prefills join box')

# 4. Tự đăng ký trước, giáo viên tự thêm mã vào danh sách → cảnh báo; "đặt lại MK" thu hồi cả Google
code2 = 'HS' + secrets.token_hex(4).upper()
post(requests.Session(), 'student/register.php', {'code': code2, 'name': 'Ai Đó', 'join': '', 'p1': pw, 'p2': pw})
with db.cursor() as c: c.execute('INSERT INTO oauth_identities (provider, subject, user_type, user_key) VALUES ("google", %s, "student", %s)', ('g-' + code2, code2))
t = requests.Session(); post(t, 'index.php', {'username': d['prefix'] + '_1', 'password': d['password']})
seat = sql1('SELECT COALESCE(MAX(seat_no),0)+1 s FROM students WHERE class_id=%s', (cls,))['s']
post(t, 'class.php?id=%d' % cls, {'action': 'add_student', 'seat_no': seat, 'full_name': 'Học Sinh Thật', 'student_code': code2})
r = t.get(BASE + 'class.php?id=%d' % cls); expect('tự tạo, cần kiểm tra' in r.text, 'Teacher sees warning for pre-registered code')
post(t, 'class.php?id=%d' % cls, {'action': 'reset_pw', 'seat_no': seat})
expect(sql1('SELECT COUNT(*) n FROM student_users WHERE student_code=%s', (code2,))['n'] == 0 and
       sql1('SELECT COUNT(*) n FROM oauth_identities WHERE user_type="student" AND user_key=%s', (code2,))['n'] == 0, 'Reset revokes account and Google link')
print(json.dumps({'passed': len(checks), 'checks': checks}, ensure_ascii=False, indent=2))
