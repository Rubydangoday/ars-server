"""python3 tests/teacher_verify_integration.py /tmp/ars-smoke.json   (chạy sau http_smoke.py, CSDL tạm của run_local.sh)
Giáo viên đăng ký ở chế độ "cần mã mời" mà chưa có mã: vào web ngay, nhập mã sau.
  - để trống mã → tạo tài khoản, vào thẳng trang tổng quan, thấy mã khôi phục + thanh nhắc nhập mã
  - nhập SAI mã lúc đăng ký → báo lỗi, không tạo tài khoản (giữ chống dò mã)
  - chưa xác nhận: AI chạy chế độ Cơ bản (offline) dù server đã cấu hình nhà cung cấp trả phí; không tải bài giảng lên được;
    học sinh chỉ học lớp của giáo viên này cũng dùng AI Cơ bản
  - nhập mã sai ở thanh nhắc → báo lỗi; đúng → xác nhận, hết giới hạn
  - quản trị viên bấm Duyệt; nhập đúng mã lúc đăng ký → xác nhận ngay; tài khoản cũ vẫn xác nhận
"""
import json, os, secrets, subprocess, sys, configparser
import requests, pymysql
from bs4 import BeautifulSoup

BASE = os.environ.get('TEST_BASE', 'http://127.0.0.1:8091/')
ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..')
d = json.load(open(sys.argv[1]))['fixture']
cfg = configparser.ConfigParser(); cfg.read(os.environ['ARS_CONFIG'])
db = pymysql.connect(host=cfg['db']['host'], port=int(cfg['db'].get('port', 3306)), user=cfg['db']['user'], password=cfg['db']['password'],
                     database=cfg['db']['name'], autocommit=True, cursorclass=pymysql.cursors.DictCursor)
def sql(q, a=()):
    with db.cursor() as c: c.execute(q, a); return c.fetchall()
def ins(q, a=()):
    with db.cursor() as c: c.execute(q, a); return c.lastrowid

checks = []
def expect(test, msg):
    assert test, msg
    checks.append(msg)
def csrf(s, url):
    soup = BeautifulSoup(s.get(BASE + url).text, 'html.parser')
    t = soup.select_one('input[name=csrf]') or soup.select_one('meta[name=csrf]')
    return t.get('value', t.get('content'))
def post(s, url, data, files=None, csrf_from=None):
    r = s.post(BASE + url, data={'csrf': csrf(s, csrf_from or url), **data}, files=files)
    expect(r.status_code == 200 and 'Fatal error' not in r.text and 'Warning:' not in r.text, 'POST ' + url.split('?')[0])
    return r
def flash(r):
    return ' '.join(x.get_text(' ', strip=True) for x in BeautifulSoup(r.text, 'html.parser').select('.flash'))
def php_limited(teacher_id=None, student_code=None):
    """ai_conf() / account_limited() trong ngữ cảnh một phiên giáo viên hoặc học sinh."""
    sess = f"$_SESSION['teacher']=['id'=>{int(teacher_id)}];" if teacher_id else f"$_SESSION['student']=['code'=>'{student_code}'];"
    code = (f"$_SERVER['REQUEST_METHOD']='GET'; require '{ROOT}/web/lib/bootstrap.php'; require '{ROOT}/web/lib/ai.php'; {sess} echo json_encode(['limited'=>account_limited(),'provider'=>ai_conf()['provider']]);")
    return json.loads(subprocess.check_output(['php', '-r', code], text=True, env=os.environ))

saved = {r['k']: r['v'] for r in sql('SELECT k, v FROM settings WHERE k IN ("teacher_signup","teacher_invite_code","ai_provider")')}
invite = 'GV-' + secrets.token_hex(2).upper() + '-' + secrets.token_hex(2).upper()
sql('REPLACE INTO settings (k, v) VALUES ("teacher_signup","invite"), ("teacher_invite_code",%s), ("ai_provider","anthropic")', (invite,))
tag = secrets.token_hex(3)
try:
    # ---- nhập sai mã lúc đăng ký: báo lỗi, không tạo tài khoản
    s = requests.Session()
    r = post(s, 'register.php', {'invite': 'GV-SAI0-SAI0', 'full_name': 'Thầy Sai', 'username': 'gvsai' + tag, 'password': 'matkhau1', 'password2': 'matkhau1'})
    expect('Mã mời giáo viên không đúng' in r.text and not sql('SELECT 1 FROM teachers WHERE username=%s', ('gvsai' + tag,)), 'Wrong invite code at sign-up: error, no account')

    # ---- để trống mã: vào thẳng web
    r = post(s, 'register.php', {'invite': '', 'full_name': 'Cô Mới', 'username': 'gvmoi' + tag, 'password': 'matkhau1', 'password2': 'matkhau1'})
    t = sql('SELECT * FROM teachers WHERE username=%s', ('gvmoi' + tag,))[0]
    expect(r.url.endswith('dashboard.php') and t['verified'] == 0, 'Empty invite: account created unverified, straight into dashboard')
    soup = BeautifulSoup(r.text, 'html.parser')
    expect(soup.select_one('.recovery code') is not None and soup.select_one('form.verify-bar input[name=invite]') is not None,
           'Dashboard shows the one-time recovery code and the invite prompt')
    expect(s.get(BASE + 'dashboard.php').text.count('class="recovery"') == 0, 'Recovery code shown only once')
    expect('verify-bar' in s.get(BASE + 'classes.php').text, 'Invite prompt on every teacher page')

    # ---- giới hạn khi chưa xác nhận
    expect(php_limited(t['id']) == {'limited': True, 'provider': 'offline'}, 'Unverified teacher: AI forced to offline (Basic) mode')
    expect(php_limited(d['teachers'][0]) == {'limited': False, 'provider': 'anthropic'}, 'Existing (verified) teacher keeps the configured AI provider')
    cls = ins('INSERT INTO classes (teacher_id, name, room_id, join_code) VALUES (%s,%s,%s,%s)', (t['id'], 'Lớp GV mới ' + tag, 230, 'V' + tag[:5].upper()))
    sc = 'VS' + tag.upper()
    ins('INSERT INTO students (class_id, seat_no, student_code, full_name) VALUES (%s,1,%s,%s)', (cls, sc, 'Học sinh lớp mới'))
    expect(php_limited(student_code=sc)['provider'] == 'offline', "Student only in an unverified teacher's class: AI offline")
    expect(php_limited(student_code=d['code'])['provider'] == 'anthropic', 'Student of a verified teacher keeps the AI provider')
    lid = ins('INSERT INTO lessons (class_id, no, title) VALUES (%s,1,%s)', (cls, 'Buổi 1'))
    r = post(s, f'lesson.php?id={lid}', {'action': 'upload'}, files={'file': ('bai.txt', b'Noi dung bai giang', 'text/plain')})
    expect('chưa xác nhận' in flash(r) and sql('SELECT file_store FROM lessons WHERE id=%s', (lid,))[0]['file_store'] is None, 'Unverified: lecture upload refused')

    # ---- nhập mã ở thanh nhắc
    r = post(s, 'verify.php', {'invite': 'GV-SAI1-SAI1', 'back': 'classes.php'}, csrf_from='dashboard.php')
    expect('không đúng' in flash(r) and sql('SELECT verified FROM teachers WHERE id=%s', (t['id'],))[0]['verified'] == 0, 'Wrong code in the prompt: error, still unverified')
    r = post(s, 'verify.php', {'invite': invite.lower().replace('-', ' '), 'back': 'classes.php'}, csrf_from='dashboard.php')
    expect('Đã xác nhận' in flash(r) and r.url.endswith('classes.php') and 'verify-bar' not in r.text, 'Right code (any case/spacing): verified, back to the same page, prompt gone')
    expect(php_limited(t['id'])['provider'] == 'anthropic', 'Verified: AI provider restored')
    r = post(s, f'lesson.php?id={lid}', {'action': 'upload'}, files={'file': ('bai.txt', b'Noi dung bai giang', 'text/plain')})
    expect(sql('SELECT file_store FROM lessons WHERE id=%s', (lid,))[0]['file_store'] is not None, 'Verified: lecture upload works')
    r = post(s, 'verify.php', {'invite': invite, 'back': 'https://evil.example/'}, csrf_from='dashboard.php')
    expect(r.url.endswith('dashboard.php'), 'verify.php only redirects inside ARS')

    # ---- quản trị viên duyệt; nhập đúng mã lúc đăng ký
    s2 = requests.Session()
    post(s2, 'register.php', {'full_name': 'Thầy Hai', 'username': 'gvhai' + tag, 'password': 'matkhau2', 'password2': 'matkhau2'})
    t2 = sql('SELECT * FROM teachers WHERE username=%s', ('gvhai' + tag,))[0]
    admin = requests.Session(); post(admin, 'index.php', {'username': d['prefix'] + '_1', 'password': d['password']})
    page = admin.get(BASE + 'teachers.php').text
    expect('chưa xác nhận' in page and 'value="verify"' in page, 'Admin sees unverified teachers with an Approve button')
    post(admin, 'teachers.php', {'tid': t2['id'], 'action': 'verify'})
    expect(sql('SELECT verified FROM teachers WHERE id=%s', (t2['id'],))[0]['verified'] == 1, 'Admin approval verifies the account')
    s3 = requests.Session()
    post(s3, 'register.php', {'invite': invite, 'full_name': 'Cô Ba', 'username': 'gvba' + tag, 'password': 'matkhau3', 'password2': 'matkhau3'})
    expect(sql('SELECT verified FROM teachers WHERE username=%s', ('gvba' + tag,))[0]['verified'] == 1 and 'verify-bar' not in s3.get(BASE + 'dashboard.php').text,
           'Right invite at sign-up: verified immediately, no prompt')
finally:
    for k in ('teacher_signup', 'teacher_invite_code', 'ai_provider'):
        if k in saved: sql('REPLACE INTO settings (k, v) VALUES (%s,%s)', (k, saved[k]))
        else: sql('DELETE FROM settings WHERE k=%s', (k,))
    for u in ('gvmoi', 'gvhai', 'gvba'):
        for row in sql('SELECT id FROM teachers WHERE username=%s', (u + tag,)):
            sql('DELETE FROM classes WHERE teacher_id=%s', (row['id'],)); sql('DELETE FROM teachers WHERE id=%s', (row['id'],))

print(json.dumps({'passed': len(checks), 'checks': checks}, ensure_ascii=False, indent=2))
