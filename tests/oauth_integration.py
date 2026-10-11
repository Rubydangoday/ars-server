"""python3 tests/oauth_integration.py /tmp/ars-smoke.json
Kiểm thử đăng nhập Google (v12; Facebook đã bỏ) với nhà cung cấp giả lập localhost:8094 — không gọi Google thật.
Chạy SAU http_smoke.py, CSDL thử nghiệm đã có migration 007. Config thử nghiệm cần:
  [oauth_endpoints]
  google_auth = http://127.0.0.1:8094/google/auth
  google_token = http://127.0.0.1:8094/google/token
  google_userinfo = http://127.0.0.1:8094/google/userinfo
  facebook_auth = http://127.0.0.1:8094/facebook/auth
  facebook_token = http://127.0.0.1:8094/facebook/token
  facebook_userinfo = http://127.0.0.1:8094/facebook/me
Stub kiểm tra client secret, PKCE S256 (Google), appsecret_proof (Facebook), code dùng một lần.
"""
import os, sys, json, re, threading, hashlib, hmac, base64, secrets, configparser
from http.server import BaseHTTPRequestHandler, HTTPServer
from urllib.parse import urlparse, parse_qs, urlencode
import requests, pymysql
from bs4 import BeautifulSoup

BASE = os.environ.get('TEST_BASE', 'http://127.0.0.1:8091/')
d = json.load(open(sys.argv[1]))['fixture']
cfg = configparser.ConfigParser(); cfg.read(os.environ['ARS_CONFIG'])
db = pymysql.connect(host=cfg['db']['host'], port=int(cfg['db'].get('port', 3306)), user=cfg['db']['user'], password=cfg['db']['password'],
                     database=cfg['db']['name'], autocommit=True, cursorclass=pymysql.cursors.DictCursor)
def sql(q, a=()):
    with db.cursor() as c: c.execute(q, a); return c.fetchall()
def sql1(q, a=()):
    r = sql(q, a); return r[0] if r else None

checks = []
def expect(test, msg):
    assert test, msg
    checks.append(msg)
def noerr(r, what): expect(all(x not in r.text for x in ['Fatal error', 'Warning:', 'Deprecated:']), 'No PHP errors ' + what)
def csrf(s, url):
    r = s.get(BASE + url); tag = BeautifulSoup(r.text, 'html.parser').select_one('input[name=csrf]') or BeautifulSoup(r.text, 'html.parser').select_one('meta[name=csrf]')
    return tag.get('value', tag.get('content'))
def post(s, url, data, page=None):
    r = s.post(BASE + url, data={'csrf': csrf(s, page or url), **data}); noerr(r, 'POST ' + url); return r
def flash(r): return ' '.join(x.get_text(' ', strip=True) for x in BeautifulSoup(r.text, 'html.parser').select('.flash'))
def login(user):
    s = requests.Session(); post(s, 'index.php', {'username': user, 'password': d['password']}); return s

# ---- nhà cung cấp giả lập
SECRETS = {'google': ('gid.apps.googleusercontent.com', 'g-secret'), 'facebook': ('1234567890', 'fb-secret')}
NEXT = {'google': None, 'facebook': None, 'deny': False}    # hồ sơ người dùng "đăng nhập" ở lượt kế tiếp
codes = {}; tokens = {}
class Stub(BaseHTTPRequestHandler):
    def reply(self, code, obj=None, location=None):
        body = json.dumps(obj).encode() if obj is not None else b''
        self.send_response(code)
        if location: self.send_header('Location', location)
        self.send_header('Content-Type', 'application/json'); self.send_header('Content-Length', str(len(body))); self.end_headers(); self.wfile.write(body)
    def do_GET(self):
        u = urlparse(self.path); q = {k: v[0] for k, v in parse_qs(u.query).items()}; prov = u.path.split('/')[1]
        if u.path.endswith('/auth'):
            assert q['client_id'] == SECRETS[prov][0] and q['response_type'] == 'code'
            if NEXT['deny']: return self.reply(302, location=q['redirect_uri'] + '?' + urlencode({'error': 'access_denied', 'state': q['state']}))
            code = secrets.token_urlsafe(16)
            codes[code] = {'prov': prov, 'profile': NEXT[prov], 'redirect_uri': q['redirect_uri'], 'challenge': q.get('code_challenge')}
            return self.reply(302, location=q['redirect_uri'] + '?' + urlencode({'code': code, 'state': q['state']}))
        if u.path == '/facebook/token':
            return self.token(prov, q)
        if u.path == '/facebook/me':
            t = tokens.get(q.get('access_token'))
            proof = hmac.new(SECRETS['facebook'][1].encode(), q.get('access_token', '').encode(), hashlib.sha256).hexdigest()
            if not t or q.get('appsecret_proof') != proof: return self.reply(400, {'error': 'bad proof'})
            return self.reply(200, t)
        if u.path == '/google/userinfo':
            t = tokens.get(self.headers.get('Authorization', '').removeprefix('Bearer '))
            return self.reply(200, t) if t else self.reply(401, {'error': 'invalid_token'})
        self.reply(404, {})
    def do_POST(self):
        q = {k: v[0] for k, v in parse_qs(self.rfile.read(int(self.headers['Content-Length'])).decode()).items()}
        self.token(urlparse(self.path).path.split('/')[1], q)
    def token(self, prov, q):
        c = codes.pop(q.get('code'), None)                       # code dùng một lần
        if not c or c['prov'] != prov or (q.get('client_id'), q.get('client_secret')) != SECRETS[prov] or q.get('redirect_uri') != c['redirect_uri']:
            return self.reply(400, {'error': 'invalid_grant'})
        if prov == 'google':
            ch = base64.urlsafe_b64encode(hashlib.sha256(q.get('code_verifier', '').encode()).digest()).decode().rstrip('=')
            if ch != c['challenge']: return self.reply(400, {'error': 'invalid_grant', 'error_description': 'PKCE'})
        at = secrets.token_urlsafe(16); tokens[at] = c['profile']
        self.reply(200, {'access_token': at, 'token_type': 'Bearer'})
    def log_message(self, *a): pass
stub = HTTPServer(('127.0.0.1', 8094), Stub); threading.Thread(target=stub.serve_forever, daemon=True).start()

def g(sub, email='gv@example.com', name='Google User'): return {'sub': sub, 'email': email, 'email_verified': True, 'name': name}
def fb(i, name='FB User'): return {'id': i, 'name': name, 'email': None}
def oauth(s, page, provider, data):
    """Bấm nút trên trang `page`: POST tới oauth.php, theo chuyển hướng qua nhà cung cấp giả lập rồi về ARS."""
    r = s.get(BASE + page); soup = BeautifulSoup(r.text, 'html.parser')
    cands = []   # (form, URL gửi tới): form riêng có action=start, hoặc nút formaction trong form đăng ký phụ huynh
    for f in soup.select('form'):
        if (f.get('action') or '').endswith('oauth.php') and f.select_one('input[name=action][value=start]') and f.select_one(f'input[name=provider][value={provider}]'): cands.append((f, f['action']))
        b = f.select_one(f'button[name=provider][value={provider}][formaction]')
        if b: cands.append((f, b['formaction']))
    expect(cands, f'{provider} button on {page}')
    f, action = cands[0]; fields = {i['name']: i.get('value', '') for i in f.select('input[name]')}
    fields.update(data); fields['provider'] = provider
    url = requests.compat.urljoin(BASE + page, action)
    r = s.post(url, data=fields); noerr(r, 'oauth ' + provider); return r

p = d['prefix']; cls = d['class']; t1id, t2id, t3id = d['teachers']
saved = {r['k']: r['v'] for r in sql('SELECT k, v FROM settings')}
try:
    sql('DELETE FROM settings WHERE k LIKE "oauth\\_%%"')
    soup = BeautifulSoup(requests.get(BASE + 'index.php').text, 'html.parser'); btn = soup.select_one('button.oauth-google')
    expect(btn is not None and btn.has_attr('disabled') and 'chưa bật' in soup.select_one('.oauth-note').text, 'Buttons visible but disabled until configured')
    t1 = login(f'{p}_1')
    post(t1, 'settings.php', {'action': 'oauth', 'google_id': SECRETS['google'][0], 'google_secret': SECRETS['google'][1],
                              'facebook_id': SECRETS['facebook'][0], 'facebook_secret': SECRETS['facebook'][1]})
    expect(sql1('SELECT COUNT(*) n FROM settings WHERE k LIKE "oauth_facebook%%"')['n'] == 0, 'Facebook removed: settings no longer stores Facebook App ID / secret')
    r = t1.get(BASE + 'settings.php'); noerr(r, 'settings')
    expect('/oauth.php' in r.text and 'g-secret' not in r.text and 'fb-secret' not in r.text, 'Settings show redirect URI, hide secrets')
    t2 = login(f'{p}_2')
    expect(post(t2, 'settings.php', {'action': 'oauth', 'google_id': 'evil'}) and sql1('SELECT v FROM settings WHERE k="oauth_google_id"')['v'] == SECRETS['google'][0], 'Only admin configures OAuth')
    post(t1, 'settings.php', {'action': 'oauth', 'google_id': SECRETS['google'][0], 'google_secret': '••••••••',
                              'facebook_id': SECRETS['facebook'][0], 'facebook_secret': '••••••••'})
    expect(sql1('SELECT v FROM settings WHERE k="oauth_google_secret"')['v'] == 'g-secret', 'Masked secret field keeps old secret')
    for page in ['index.php', 'student/index.php', 'parent/index.php', 'parent/index.php?tab=register']:
        soup_ = BeautifulSoup(requests.get(BASE + page).text, 'html.parser'); b = soup_.select_one('button.oauth-google')
        expect(b is not None and not b.has_attr('disabled'), 'Enabled button on ' + page)
        expect(soup_.select_one('button.oauth-facebook') is None and 'Facebook' not in soup_.get_text(), 'No Facebook button on ' + page)

    # ---- giáo viên
    anon = requests.Session(); NEXT['google'] = g('g-teacher-1')
    r = oauth(anon, 'index.php', 'google', {})
    expect(r.url.endswith('register.php') and 'gv@example.com' in r.text and 'dashboard' not in r.url, 'Unlinked Google goes to sign-up step (no email auto-match)')
    expect(sql1('SELECT COUNT(*) n FROM oauth_identities WHERE subject="g-teacher-1"')['n'] == 0, 'Nothing linked before sign-up completes')
    anon.post(BASE + 'oauth.php', data={'csrf': csrf(anon, 'register.php'), 'action': 'cancel', 'provider': 'google', 'for': 'teacher'})
    expect('Đang tạo tài khoản bằng' not in anon.get(BASE + 'register.php').text, 'Cancel clears pending sign-up')
    r = oauth(t1, 'settings.php', 'google', {}); expect('Đã liên kết Google' in flash(r), 'Teacher links Google')
    expect(sql1('SELECT user_key FROM oauth_identities WHERE provider="google" AND subject="g-teacher-1" AND user_type="teacher"')['user_key'] == str(t1id), 'Identity stored')
    s = requests.Session(); r = oauth(s, 'index.php', 'google', {}); expect(r.url.endswith('dashboard.php') and 'Giáo viên 1' in r.text, 'Teacher logs in with Google')
    r = oauth(t2, 'settings.php', 'google', {}); expect('tài khoản ARS khác' in flash(r), 'Same Google cannot link two teachers')
    r = t1.get(BASE + 'settings.php'); expect('Liên kết Google' not in r.text and 'Gỡ liên kết' in r.text, 'Linked account shows unlink only')
    NEXT['google'] = g('g-teacher-1b')     # gửi thẳng yêu cầu liên kết Google thứ hai: server phải chặn
    r = t1.post(BASE + 'oauth.php', data={'csrf': csrf(t1, 'settings.php'), 'action': 'start', 'provider': 'google', 'for': 'teacher', 'mode': 'link'})
    expect('Gỡ liên kết cũ' in flash(r), 'One Google per account')
    sql('UPDATE teachers SET active=0 WHERE id=%s', (t1id,)); NEXT['google'] = g('g-teacher-1')
    r = oauth(requests.Session(), 'index.php', 'google', {}); expect('bị khoá' in flash(r), 'Locked teacher blocked')
    sql('UPDATE teachers SET active=1 WHERE id=%s', (t1id,))

    # ---- bảo mật luồng: state giả, gọi lại lần hai, huỷ, thiếu CSRF
    s = requests.Session(); s.get(BASE + 'index.php')
    r = s.post(BASE + 'oauth.php', data={'csrf': csrf(s, 'index.php'), 'action': 'start', 'provider': 'google', 'for': 'teacher', 'mode': 'login'}, allow_redirects=False)
    auth = r.headers['Location']; expect('code_challenge=' in auth and 'code_challenge_method=S256' in auth and 'state=' in auth, 'Google request uses state + PKCE')
    cb = requests.get(auth, allow_redirects=False).headers['Location']
    bad = re.sub(r'state=[^&]+', 'state=forged', cb)
    r = s.get(bad); expect('không hợp lệ' in flash(r), 'Forged state rejected')
    r = s.get(cb); expect('không hợp lệ' in flash(r), 'State is single-use')
    other = requests.Session(); r = other.get(cb); expect('không hợp lệ' in flash(r) and 'dashboard' not in r.url, 'Callback from another browser rejected')
    NEXT['deny'] = True; r = oauth(requests.Session(), 'index.php', 'google', {}); expect('huỷ' in flash(r), 'User cancel handled'); NEXT['deny'] = False
    expect(requests.post(BASE + 'oauth.php', data={'action': 'start', 'provider': 'google', 'for': 'teacher', 'mode': 'login'}).status_code == 400, 'CSRF required to start')

    # ---- Facebook đã bỏ: không bắt đầu được luồng Facebook
    s = requests.Session(); s.get(BASE + 'index.php')
    expect(s.post(BASE + 'oauth.php', data={'csrf': csrf(s, 'index.php'), 'action': 'start', 'provider': 'facebook', 'for': 'teacher', 'mode': 'login'}).status_code == 400,
           'Facebook login flow is rejected (provider removed)')

    # ---- học sinh: Google
    st = requests.Session(); st.post(BASE + 'student/index.php', data={'csrf': csrf(st, 'student/index.php'), 'code': d['code'], 'password': d['password']})
    NEXT['google'] = g('g-student-1', 'hs@example.com', 'HS Google')
    r = oauth(st, 'student/account.php', 'google', {}); expect('Đã liên kết Google' in flash(r), 'Student links Google')
    s = requests.Session(); r = oauth(s, 'student/index.php', 'google', {}); expect(r.url.endswith('student/home.php') and 'Học sinh kiểm thử' in r.text, 'Student logs in with Google')
    r = post(st, 'oauth.php', {'action': 'unlink', 'provider': 'google', 'for': 'student'}, page='student/account.php'); expect('Đã gỡ' in flash(r), 'Student unlinks')
    r = oauth(requests.Session(), 'student/index.php', 'google', {}); expect(r.url.endswith('student/register.php') and 'home.php' not in r.url, 'Unlinked Google no longer logs in (goes to sign-up)')
    expect(sql1('SELECT COUNT(*) n FROM oauth_identities WHERE user_type="student"')['n'] == 0, 'Unlink removed identity')

    # ---- phụ huynh: đăng ký bằng Google + mã mời
    def invite():
        r = post(t1, 'family.php?class=%d' % cls, {'action': 'invite', 'student_code': d['code'], 'class_id': cls})
        return BeautifulSoup(r.text, 'html.parser').select_one('.invite-once .io-code').text
    n_parents = sql1('SELECT COUNT(*) n FROM parents')['n']
    r = oauth(requests.Session(), 'parent/index.php?tab=register', 'google', {'invite': 'abc'}); expect('Mã mời không đúng' in flash(r), 'Register requires invite format')
    tok = invite(); NEXT['google'] = g('g-parent-1', 'ph@example.com', 'Phụ huynh Google')
    par = requests.Session(); r = oauth(par, 'parent/index.php?tab=register', 'google', {'invite': tok})
    expect(r.url.endswith('parent/home.php') and 'Học sinh kiểm thử' in r.text, 'Parent registers with Google + invite')
    expect(sql1('SELECT COUNT(*) n FROM parents')['n'] == n_parents + 1 and sql1('SELECT full_name FROM parents ORDER BY id DESC LIMIT 1')['full_name'] == 'Phụ huynh Google', 'Parent account created from profile')
    r = oauth(requests.Session(), 'parent/index.php?tab=register', 'google', {'invite': tok}); expect('hết hạn hoặc đã dùng' in flash(r), 'Invite single-use')
    expect(sql1('SELECT COUNT(*) n FROM parents')['n'] == n_parents + 1, 'No orphan parent on failed register')
    r = oauth(requests.Session(), 'parent/index.php', 'google', {}); expect(r.url.endswith('parent/home.php'), 'Parent logs in with Google')
    expect('Gỡ liên kết' in par.get(BASE + 'parent/home.php?tab=account').text, 'Parent manages linked account')
    tok2 = invite(); r = oauth(requests.Session(), 'parent/index.php?tab=register', 'google', {'invite': tok2})
    expect(sql1('SELECT COUNT(*) n FROM parents')['n'] == n_parents + 1 and r.url.endswith('parent/home.php'), 'Existing Google parent reuses account')

    # ---- đăng ký nhanh bằng Google (không liên kết trước)
    # Giáo viên, chế độ cần mã mời: Google → bước cuối nhập mã mời → vào thẳng, không cần mật khẩu
    code = sql1('SELECT v FROM settings WHERE k="teacher_invite_code"')
    if not code: post(t1, 'teachers.php', {'action': 'new_invite'}); code = sql1('SELECT v FROM settings WHERE k="teacher_invite_code"')
    NEXT['google'] = g('g-new-teacher', 'co.lan@example.com', 'Cô Lan')
    s = requests.Session(); r = oauth(s, 'register.php', 'google', {})
    soup = BeautifulSoup(r.text, 'html.parser')
    expect(r.url.endswith('register.php') and soup.select_one('input[name=password]') is None and soup.select_one('input[name=full_name]')['value'] == 'Cô Lan', 'Teacher sign-up step prefilled, no password')
    expect(soup.select_one('input[name=username]')['value'].startswith('co.lan'), 'Username suggested from email')
    r = post(s, 'register.php', {'invite': code['v'], 'full_name': 'Cô Lan', 'username': soup.select_one('input[name=username]')['value']})
    expect(r.url.endswith('dashboard.php') and 'Đăng nhập với Google' in flash(r), 'Teacher account created via Google')
    r = oauth(requests.Session(), 'index.php', 'google', {}); expect(r.url.endswith('dashboard.php'), 'New teacher logs in with Google')
    NEXT['google'] = g('g-wrong-invite', 'x@example.com', 'X'); s = requests.Session(); oauth(s, 'index.php', 'google', {})
    r = post(s, 'register.php', {'invite': 'GV-SAI0-SAI0', 'full_name': 'X', 'username': 'xxguess'}); expect('Mã mời' in r.text and 'dashboard' not in r.url, 'Invite still required')
    # Chế độ đăng ký tự do: bấm Google là có tài khoản
    sql('REPLACE INTO settings (k, v) VALUES ("teacher_signup","open")')
    NEXT['google'] = g('g-open-teacher', 'thay.minh@example.com', 'Thầy Minh'); r = oauth(requests.Session(), 'index.php', 'google', {})
    expect(r.url.endswith('dashboard.php') and sql1('SELECT COUNT(*) n FROM teachers WHERE full_name="Thầy Minh"')['n'] == 1, 'Open signup: Google creates account directly')
    sql('REPLACE INTO settings (k, v) VALUES ("teacher_signup",%s)', (saved.get('teacher_signup', 'invite'),))
    # Đã có tài khoản: bấm Google → đăng nhập mật khẩu → ARS hỏi rồi mới liên kết
    NEXT['google'] = g('g-teacher-2', 'gv2@example.com', 'GV 2'); s = requests.Session(); oauth(s, 'index.php', 'google', {})
    r = post(s, 'index.php', {'username': f'{p}_2', 'password': d['password']})
    expect('Liên kết Google gv2@example.com với tài khoản này?' in r.text, 'Password login asks before linking pending Google')
    expect(sql1('SELECT COUNT(*) n FROM oauth_identities WHERE subject="g-teacher-2"')['n'] == 0, 'Not linked without confirmation')
    r = post(s, 'oauth.php', {'action': 'confirm_link', 'provider': 'google', 'for': 'teacher'}, page='dashboard.php')
    expect(sql1('SELECT user_key FROM oauth_identities WHERE subject="g-teacher-2"')['user_key'] == str(t2id) and 'Đã liên kết Google' in flash(r), 'Confirmed link')
    NEXT['google'] = g('g-skip', 'skip@example.com', 'Skip'); s = requests.Session(); oauth(s, 'index.php', 'google', {})
    post(s, 'index.php', {'username': f'{p}_3', 'password': d['password']}); post(s, 'oauth.php', {'action': 'skip_link', 'provider': 'google', 'for': 'teacher'}, page='dashboard.php')
    expect(sql1('SELECT COUNT(*) n FROM oauth_identities WHERE subject="g-skip"')['n'] == 0 and 'với tài khoản này?' not in s.get(BASE + 'dashboard.php').text, 'Skip does not link')
    # Học sinh mới: Google → mã lớp + mã học sinh → vào thẳng; mã đã có mật khẩu thì không chiếm được
    join = sql1('SELECT join_code FROM classes WHERE id=%s', (cls,))['join_code']
    NEXT['google'] = g('g-student-new', 'hs.moi@example.com', 'Học Sinh Mới'); s = requests.Session(); r = oauth(s, 'student/index.php', 'google', {})
    expect(r.url.endswith('student/register.php') and 'input name="p1"' not in r.text, 'Student sign-up step without password')
    r = post(s, 'student/register.php', {'code': p[-6:] + 'G1', 'name': 'Học Sinh Mới', 'join': join})
    expect(r.url.endswith('student/home.php') and 'Học Sinh Mới' in r.text, 'Student created via Google with class code')
    r = oauth(requests.Session(), 'student/index.php', 'google', {}); expect(r.url.endswith('student/home.php'), 'New student logs in with Google')
    NEXT['google'] = g('g-intruder', 'ke.gia@example.com', 'Kẻ giả'); s = requests.Session(); oauth(s, 'student/index.php', 'google', {})
    r = post(s, 'student/register.php', {'code': p[-6:] + 'G1', 'name': 'Kẻ giả', 'join': join})
    expect('đã có tài khoản' in r.text and 'home.php' not in r.url, 'Another Google cannot take over an existing student code')
    # Phụ huynh: Google → chỉ cần mã mời
    NEXT['google'] = g('g-parent-new', 'ph.moi@example.com', 'Phụ Huynh Mới'); s = requests.Session(); r = oauth(s, 'parent/index.php', 'google', {})
    soup = BeautifulSoup(r.text, 'html.parser')
    expect('tab=register' in r.url and soup.select_one('input[name=password]') is None and 'Đang tạo tài khoản bằng Google' in r.text, 'Parent sign-up step asks only invite')
    r = post(s, 'parent/index.php?tab=register', {'action': 'register', 'invite': invite(), 'full_name': 'Phụ Huynh Mới'})
    expect(r.url.endswith('parent/home.php') and 'Học sinh kiểm thử' in r.text, 'Parent created via Google + invite')
finally:
    for k in ['oauth_google_id', 'oauth_google_secret', 'oauth_facebook_id', 'oauth_facebook_secret', 'teacher_signup']:
        if k in saved: sql('REPLACE INTO settings (k, v) VALUES (%s,%s)', (k, saved[k]))
        else: sql('DELETE FROM settings WHERE k=%s', (k,))
    stub.shutdown()
print(json.dumps({'passed': len(checks), 'checks': checks}, ensure_ascii=False, indent=2))
