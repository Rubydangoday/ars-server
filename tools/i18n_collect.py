#!/usr/bin/env python3
"""Gom chữ tiếng Việt hiển thị trên web mà từ điển lang/en.php chưa có (để dịch).

  KEEP=1 bash tests/run_local.sh                     # máy chủ tạm có dữ liệu mẫu
  python3 tools/i18n_collect.py /tmp/ars-test.XXXX/smoke.json > /tmp/missing.txt
  EN=1 python3 tools/i18n_collect.py …               # duyệt bằng giao diện tiếng Anh: liệt kê chữ Việt còn sót trên trang

Duyệt mọi trang (giáo viên quản trị, học sinh, phụ huynh) bằng cách đi theo liên kết, tách chữ giống hệt bộ lọc
i18n_html() trong web/lib/i18n.php, cộng thêm chuỗi tĩnh trong flash(...) / RuntimeException(...) / 'error' => ...
Mỗi dòng ra: khoá (số đã thay bằng {n}).
"""
import os, re, sys, json, glob, html, subprocess
from urllib.parse import urljoin, urlparse
import requests
from bs4 import BeautifulSoup

BASE = os.environ.get('TEST_BASE', 'http://127.0.0.1:8091/')
ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'web')
VI = re.compile(r'[À-ỹĐđ]')
SKIP = re.compile(r'logout|lang=|export|download|\.xlsx|\.docx|\.pdf|file\.php|delete|xoa|reset|_docx|sheet|print|api/|go\.php|oauth\.php\?p=')

d = json.load(open(sys.argv[1]))['fixture']
dict_keys = set(json.loads(subprocess.run(['php', '-r', 'echo json_encode(array_keys(require "%s/lang/en.php"), JSON_UNESCAPED_UNICODE);' % ROOT],
                                       capture_output=True, text=True).stdout))


def norm(s):
    s = re.sub(r'\s+', ' ', html.unescape(s)).strip()
    if not VI.search(s):
        return None
    m = re.match(r'^([^\w]*)(.*?)([\s:：.…!?·,;)]*)$', s, re.S)
    core = m.group(2) if m and m.group(2) else s
    if core in dict_keys or s in dict_keys:
        return None
    key = re.sub(r'\d+(?:[.,]\d+)*', '{n}', core)
    if key in dict_keys:
        return None
    return key


found = {}
EN = os.environ.get('EN') == '1'


def add(s, where):
    k = norm(s)
    if k and len(k) <= 600:
        found.setdefault(k, where)


def scan(text, where):
    text = re.sub(r'<(script|style|textarea|pre)\b.*?</\1\s*>', ' ', text, flags=re.S | re.I)
    for m in re.finditer(r'>([^<>]+)<', text):
        add(m.group(1), where)
    for m in re.finditer(r'\b(?:placeholder|title|aria-label|alt|data-tip)="([^"]*)"', text):
        add(m.group(1), where)
    for m in re.finditer(r'<(?:input|button)\b[^>]*\btype="(?:submit|button)"[^>]*\bvalue="([^"]*)"', text):
        add(m.group(1), where)
    for m in re.finditer(r"confirm\((?:&#039;|')(.*?)(?:&#039;|')\)", text):
        add(m.group(1), where)


def csrf(s, url):
    so = BeautifulSoup(s.get(BASE + url).text, 'html.parser')
    t = so.select_one('input[name=csrf]') or so.select_one('meta[name=csrf]')
    return t.get('value', t.get('content'))


def crawl(s, starts, limit=500):
    if EN:                                    # đổi ngôn ngữ như người dùng (lưu cả theo tài khoản)
        for c in [c for c in s.cookies if c.name == 'ars_lang']: s.cookies.clear(c.domain, c.path, c.name)
        s.get(BASE + starts[0] + ('&' if '?' in starts[0] else '?') + 'lang=en')
    seen, queue = set(), list(starts)
    while queue and len(seen) < limit:
        u = queue.pop(0)
        key = re.sub(r'=\d+', '=N', u)
        if key in seen or SKIP.search(u):
            continue
        seen.add(key)
        try:
            r = s.get(BASE + u, timeout=30, allow_redirects=True)
        except Exception:
            continue
        if 'text/html' not in r.headers.get('content-type', ''):
            continue
        scan(r.text, u)
        cur = urlparse(r.url).path
        for a in BeautifulSoup(r.text, 'html.parser').select('a[href]'):
            h = urljoin(BASE + cur.lstrip('/'), a['href'])
            p = urlparse(h)
            if p.netloc != urlparse(BASE).netloc or not p.path.endswith('.php'):
                continue
            queue.append(p.path.lstrip('/') + ('?' + p.query if p.query else ''))


# không đăng nhập
anon = requests.Session()
crawl(anon, ['portal.php?home=1', 'index.php', 'register.php', 'student/index.php', 'student/register.php', 'parent/index.php',
             'parent/index.php?tab=register', 'privacy.php', 'forgot.php'], 60)
# giáo viên quản trị (teacher 1)
t = requests.Session()
t.post(BASE + 'index.php', data={'csrf': csrf(t, 'index.php'), 'username': d['prefix'] + '_1', 'password': d['password']})
crawl(t, ['dashboard.php', 'settings.php', 'classes.php', 'sets.php', 'results.php', 'homework.php', 'lessons.php', 'rewards.php',
          'family.php', 'devices.php', 'sync.php', 'teachers.php', 'schools.php', 'school.php', 'notify.php', 'library.php', 'reports.php'])
# học sinh
st = requests.Session()
st.post(BASE + 'student/index.php', data={'csrf': csrf(st, 'student/index.php'), 'code': d['code'], 'password': d['password']})
crawl(st, ['student/home.php', 'student/tutor.php', 'student/lessons.php', 'student/right.php', 'student/wrong.php', 'student/practice.php',
           'student/homework.php', 'student/lectures.php', 'student/rewards.php'], 200)
# phụ huynh
p = requests.Session()
p.post(BASE + 'parent/index.php', data={'csrf': csrf(p, 'parent/index.php'), 'action': 'login', 'username': d['prefix'] + '_parent', 'password': d['password']})
crawl(p, ['parent/home.php'], 40)

# chuỗi tĩnh: thông báo flash / lỗi
for f in [] if EN else  glob.glob(ROOT + '/**/*.php', recursive=True):
    if '/lang/' in f:
        continue
    src = open(f, encoding='utf-8').read()
    for m in re.finditer(r"(?:flash|RuntimeException|InvalidArgumentException|json_out\(\['error' =>|'error' =>|exit)\(?\s*'((?:[^'\\]|\\.)*)'", src):
        s = m.group(1).replace("\\'", "'")
        if '{$' not in s and '$' not in s:
            add(s, os.path.relpath(f, ROOT))

for k, w in sorted(found.items(), key=lambda x: x[1]):
    print(json.dumps([k, w], ensure_ascii=False))
print(len(found), 'chuỗi', file=sys.stderr)
