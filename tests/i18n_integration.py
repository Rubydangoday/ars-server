"""python3 tests/i18n_integration.py /tmp/ars-smoke.json
Giao diện đa ngôn ngữ (migration 009): ?lang=en lưu cookie + theo tài khoản; các trang chính hiện tiếng Anh; đổi lại tiếng Việt.
Chạy sau http_smoke.py.
"""
import os, sys, json, configparser
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
def get(s, url):
    r = s.get(BASE + url); expect(r.status_code == 200 and all(x not in r.text for x in ['Fatal error', 'Warning:', 'Deprecated:']), 'GET ' + url); return r
def csrf(s, url):
    soup = BeautifulSoup(s.get(BASE + url).text, 'html.parser'); t = soup.select_one('input[name=csrf]') or soup.select_one('meta[name=csrf]')
    return t.get('value', t.get('content'))

# 1. Trình duyệt tiếng Anh, chưa chọn: tự hiện tiếng Anh; trình duyệt tiếng Việt: tiếng Việt
r = requests.get(BASE + 'index.php', headers={'Accept-Language': 'en-US,en;q=0.9'}); expect('Teacher sign in' in r.text and 'lang="en"' in r.text, 'English browser → English login')
r = requests.get(BASE + 'index.php', headers={'Accept-Language': 'vi-VN,vi;q=0.9'}); expect('Đăng nhập giáo viên' in r.text, 'Vietnamese browser → Vietnamese login')

# 2. Chọn tiếng Anh bằng ?lang=en (trước khi đăng nhập), đăng nhập → giao diện tiếng Anh, lưu theo tài khoản
s = requests.Session()
r = s.get(BASE + 'index.php?lang=en'); expect(r.url.endswith('index.php') and s.cookies.get('ars_lang') == 'en', '?lang=en sets cookie and drops the parameter')
r = s.post(BASE + 'index.php', data={'csrf': csrf(s, 'index.php'), 'username': d['prefix'] + '_1', 'password': d['password']})
expect(r.url.endswith('dashboard.php') and 'Launch a Remote session' in r.text and '>Classes<' in r.text, 'Dashboard in English')
get(s, 'dashboard.php?lang=en')
expect(sql1('SELECT lang FROM teachers WHERE username=%s', (d['prefix'] + '_1',))['lang'] == 'en', 'Language saved on the teacher account')
r = get(s, 'class.php?id=%d' % d['class']); expect('Start a session' in r.text and 'Class info' in r.text, 'Class page in English')
r = get(s, 'session.php?id=%d' % d['session']); expect('Open question' in r.text and 'window.ARS_I18N' in r.text, 'Control panel in English with JS dictionary')
r = get(s, 'classes.php'); expect('New class' in r.text, 'Classes page in English')
r = get(s, 'results.php?id=%d' % d['session']); expect('Export Excel' in r.text, 'Results page in English')

# 3. Tài khoản đã chọn tiếng Anh: đăng nhập ở trình duyệt mới (cookie tiếng Việt) vẫn ra tiếng Anh
s2 = requests.Session(); s2.get(BASE + 'index.php?lang=vi')
r = s2.post(BASE + 'index.php', data={'csrf': csrf(s2, 'index.php'), 'username': d['prefix'] + '_1', 'password': d['password']})
expect('Launch a Remote session' in r.text, 'Saved account language applied at sign-in')

# 4. Đổi lại tiếng Việt
r = s.get(BASE + 'dashboard.php?lang=vi'); expect('Bắt đầu buổi học bằng Remote' in r.text and s.cookies.get('ars_lang') == 'vi', 'Switch back to Vietnamese')
expect(sql1('SELECT lang FROM teachers WHERE username=%s', (d['prefix'] + '_1',))['lang'] == 'vi', 'Vietnamese saved on the account')

# 5. Cổng phụ huynh tiếng Anh
p = requests.Session(); p.get(BASE + 'parent/index.php?lang=en')
r = p.post(BASE + 'parent/index.php', data={'csrf': csrf(p, 'parent/index.php'), 'action': 'login', 'username': d['prefix'] + '_parent', 'password': d['password']})
expect(r.url.endswith('parent/home.php') and "My child's progress" in r.text and 'Leaderboard points' in r.text, 'Parent portal in English')
# 5. Dịch toàn trang (v16): nội dung trong tab cũng sang tiếng Anh, cổng học sinh / phụ huynh có nút chọn ngôn ngữ
def visible(r):
    so = BeautifulSoup(r.text, 'html.parser')
    for x in so(['script', 'style']): x.decompose()
    return so.get_text(' ')
r = get(s, 'settings.php?lang=en'); v = visible(get(s, 'settings.php'))
expect('AI question-writing assistant' in v and 'Trợ lý AI soạn câu hỏi' not in v and 'Mật khẩu hiện tại' not in v, 'Settings page content in English')
v = visible(get(s, 'homework.php')); expect('New assignment' in v and 'Giao bài mới' not in v, 'Homework page content in English')
get(s, 'dashboard.php?lang=vi')
st = requests.Session(); st.cookies.set('ars_lang', 'en')
st.post(BASE + 'student/index.php', data={'csrf': csrf(st, 'student/index.php'), 'code': d['code'], 'password': d['password']})
r = get(st, 'student/home.php')
expect('lang-switch' in r.text and 'lang="en"' in r.text and ('Parts to review carefully' in r.text or 'Recent lessons' in r.text), 'Student portal in English with language menu')
expect('assets/i18n.js' in r.text, 'Client-side translator loaded for JS-inserted text')
r = st.post(BASE + 'student/api_tutor.php', data={'csrf': csrf(st, 'student/tutor.php'), 'action': 'rename', 'conversation_id': 999999, 'title': 'x'})
expect(r.json().get('error', '').startswith('Conversation not found'), 'JSON error messages translated')
st.get(BASE + 'student/home.php?lang=vi'); r = get(st, 'student/home.php')
expect('Các buổi gần đây' in r.text or 'Phần cần ôn kỹ' in r.text or 'Xin chào' in r.text, 'Student switches back to Vietnamese')
r = requests.get(BASE + 'student/index.php'); expect('stu-lang-float' in r.text, 'Language menu on the student sign-in page')

print(json.dumps({'passed': len(checks), 'checks': checks}, ensure_ascii=False, indent=2))
