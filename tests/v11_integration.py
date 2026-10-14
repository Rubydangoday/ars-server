"""python3 tests/v11_integration.py /tmp/ars-smoke.json
Kiểm thử v11 (phân quyền trường, đồng bộ Cloud, thi đua/đổi quà, thông báo Zalo/SMS) trên CSDL thử nghiệm.
Chạy SAU http_smoke.py (dùng lại fixture). PHP server phải có nhiều worker vì Sync to Cloud gọi lại chính server:
  PHP_CLI_SERVER_WORKERS=4 ARS_CONFIG=... php -S 127.0.0.1:8091 -t web
Cloud = cùng server thử nghiệm (giáo viên 2 nhận dữ liệu của giáo viên 1). Zalo/SMS dùng stub HTTP localhost:8093, không gửi ra ngoài.
"""
import subprocess, os, sys, json, re, threading, configparser
from http.server import BaseHTTPRequestHandler, HTTPServer
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
def page(s, url, code=200):
    r = s.get(BASE + url); expect(r.status_code == code, f'GET {url} → {code}')
    expect('Fatal error' not in r.text and 'Warning:' not in r.text and 'Deprecated:' not in r.text, 'No PHP errors ' + url)
    return r
def post(s, url, data):
    r = s.get(BASE + url); soup = BeautifulSoup(r.text, 'html.parser')
    tag = soup.select_one('input[name=csrf]') or soup.select_one('meta[name=csrf]')
    r = s.post(BASE + url, data={'csrf': tag.get('value', tag.get('content')), **data})
    expect(r.status_code == 200, 'POST ' + url)
    expect('Fatal error' not in r.text and 'Warning:' not in r.text and 'Deprecated:' not in r.text, 'No PHP errors POST ' + url)
    return r
def login(user, pw=d['password'], url='index.php', field='username'):
    s = requests.Session(); post(s, url, {field: user, 'password': pw}); return s
def flash(r): return ' '.join(x.get_text(' ', strip=True) for x in BeautifulSoup(r.text, 'html.parser').select('.flash'))

# ---- stub Zalo/SMS
inbox = []
class Stub(BaseHTTPRequestHandler):
    def do_POST(self):
        body = json.loads(self.rfile.read(int(self.headers['Content-Length'])))
        inbox.append({'path': self.path, 'headers': dict(self.headers), 'body': body})
        out = json.dumps({'error': 0, 'message': 'Success'} if self.path.startswith('/zalo') else {'ok': True}).encode()
        self.send_response(200); self.send_header('Content-Type', 'application/json'); self.send_header('Content-Length', str(len(out))); self.end_headers(); self.wfile.write(out)
    def log_message(self, *a): pass
stub = HTTPServer(('127.0.0.1', 8093), Stub); threading.Thread(target=stub.serve_forever, daemon=True).start()

p = d['prefix']; cls = d['class']; t1id, t2id, t3id = d['teachers']
t1 = login(f'{p}_1'); t2 = login(f'{p}_2'); t3 = login(f'{p}_3')
saved = {r['k']: r['v'] for r in sql('SELECT k, v FROM settings')}
try:
    # ================= 1. Phân quyền tổ chức
    school = sql1('SELECT school_id FROM teachers WHERE id=%s', (t1id,))['school_id']
    post(t1, 'schools.php', {'action': 'assign', 'teacher_id': t2id, 'school_id': school, 'school_role': 'school_admin'})
    expect(sql1('SELECT school_role FROM teachers WHERE id=%s', (t2id,))['school_role'] == 'school_admin', 'Server admin grants school admin')
    t2 = login(f'{p}_2')
    r = page(t2, 'school.php'); expect('Lớp kiểm thử' in r.text and 'Trường của tôi' in r.text, 'School admin dashboard lists school classes')
    page(t2, 'analytics.php?class=%d' % cls); page(t3, 'school.php', 403); page(t3, 'analytics.php?class=%d' % cls, 404)
    expect('Trường của tôi' not in page(t3, 'dashboard.php').text, 'Normal teacher has no school menu')
    page(t2, 'class.php?id=%d' % cls, 404)   # xem báo cáo, không sửa lớp
    room = sql1('SELECT room_id FROM classes WHERE id=%s', (cls,))['room_id']
    post(t1, 'class.php?id=%d' % cls, {'action': 'update', 'name': 'Lớp kiểm thử', 'room_id': room, 'subject': 'Toán', 'cohort': '6A1'})
    expect(sql1('SELECT cohort FROM classes WHERE id=%s', (cls,))['cohort'] == '6A1', 'Owner sets cohort and subject')
    r = post(t2, 'school.php', {'action': 'create_teacher', 'full_name': 'GV Chủ nhiệm', 'username': p + '_hr', 'school_role': 'teacher'})
    pw = BeautifulSoup(r.text, 'html.parser').select_one('.flash.warn code').text
    hrid = sql1('SELECT id FROM teachers WHERE username=%s', (p + '_hr',))['id']
    expect(sql1('SELECT school_id FROM teachers WHERE id=%s', (hrid,))['school_id'] == school, 'School admin creates teacher in own school')
    hr = login(p + '_hr', pw)
    page(hr, 'analytics.php?class=%d' % cls, 404)
    post(t2, 'school.php', {'action': 'cohort', 'name': '6A1', 'homeroom': hrid})
    page(hr, 'analytics.php?class=%d' % cls); r = page(hr, 'cohort.php?name=6A1'); expect('Học sinh kiểm thử' in r.text, 'Homeroom teacher sees cohort summary')
    page(hr, 'family.php?class=%d' % cls); page(hr, 'class.php?id=%d' % cls, 404); page(t3, 'cohort.php?name=6A1', 404)
    r = post(t2, 'school.php', {'action': 'toggle_active', 'tid': t1id})
    expect('quản trị server' in flash(r) and sql1('SELECT active FROM teachers WHERE id=%s', (t1id,))['active'] == 1, 'School admin cannot lock server admin')

    # ================= 3. Thi đua: buổi đã kết thúc, đã công bố, học sinh ghế 5 bấm đúng
    def ended_session(correct=True, released=1):
        sql('INSERT INTO sessions (class_id, set_id, status, released, started_at, ended_at) VALUES (%s,%s,"ended",%s,NOW(),NOW())', (cls, d['set'], released))
        sid = sql1('SELECT MAX(id) id FROM sessions')['id']
        sql('INSERT INTO session_questions (session_id, question_id, status, opened_at, closed_at) VALUES (%s,%s,"closed",NOW(3),NOW(3))', (sid, d['question']))
        sq = sql1('SELECT id FROM session_questions WHERE session_id=%s', (sid,))['id']
        sql('INSERT INTO session_logins (session_id, seat_no, mac, logged_at) VALUES (%s,5,"AA:BB:CC:DD:EE:05",NOW(3))', (sid,))
        sql('INSERT INTO responses (session_q_id, seat_no, mac, seq, answer, received_at) VALUES (%s,5,"AA:BB:CC:DD:EE:05",1,%s,NOW(3))', (sq, 'B' if correct else 'A'))
        return sid
    s1 = ended_session()
    r = page(t1, 'rewards.php?class=%d&period=week' % cls); expect('Học sinh kiểm thử' in r.text, 'Leaderboard lists student')
    rows = BeautifulSoup(r.text, 'html.parser').select('table.lb tbody tr')
    hw = sql1('SELECT SUM(a.score/hq.max_score) r FROM homework_answers a JOIN homework_questions hq ON hq.id=a.hw_question_id JOIN homework_submissions s ON s.id=a.submission_id '
              'JOIN homework h ON h.id=s.homework_id WHERE h.class_id=%s AND s.released=1 AND a.score IS NOT NULL', (cls,))['r'] or 0
    pts = 10 + round(float(hw) * 10)   # 1 câu Remote đúng + bài tập đã công bố
    expect(rows and rows[0].select('td')[-1].text.strip() == str(pts), f'Points = 10 Remote + homework ({pts})')
    post(t1, 'rewards.php?class=%d' % cls, {'class_id': cls, 'action': 'samples'})
    expect(sql1('SELECT COUNT(*) n FROM rewards WHERE class_id=%s', (cls,))['n'] == 9, 'Sample rewards created')
    post(t1, 'rewards.php?class=%d' % cls, {'class_id': cls, 'action': 'samples'})
    expect(sql1('SELECT COUNT(*) n FROM rewards WHERE class_id=%s', (cls,))['n'] == 9, 'Samples not duplicated')
    st = login(d['code'], url='student/index.php', field='code')
    r = page(st, 'student/rewards.php'); expect(f'pts-big">{pts}<' in r.text, 'Student sees balance')
    lion = sql1('SELECT id FROM rewards WHERE class_id=%s AND name="Sư tử dũng cảm"', (cls,))['id']
    r = post(st, 'student/rewards.php', {'action': 'redeem', 'reward_id': lion}); expect('Đã nhận' in flash(r), 'Redeem avatar')
    r = post(st, 'student/rewards.php', {'action': 'redeem', 'reward_id': lion}); expect('đã có' in flash(r), 'Avatar not bought twice')
    rocket = sql1('SELECT id FROM rewards WHERE class_id=%s AND name="Tên lửa"', (cls,))['id']
    r = post(st, 'student/rewards.php', {'action': 'redeem', 'reward_id': rocket}); expect('Chưa đủ điểm' in flash(r), 'Cannot overspend')
    rocket_av = sql1('SELECT icon FROM rewards WHERE id=%s', (rocket,))['icon']
    r = post(st, 'student/rewards.php', {'action': 'avatar', 'avatar': rocket_av}); expect('chưa có' in flash(r), 'Cannot equip unowned avatar')
    r = post(st, 'student/rewards.php', {'action': 'avatar', 'avatar': '🦁'}); expect('<span class="avatar">🦁</span>' in r.text, 'Avatar shown in header')
    ended_session()   # +10
    post(t1, 'rewards.php?class=%d' % cls, {'class_id': cls, 'action': 'add', 'kind': 'gift', 'icon': '🎁', 'name': 'Quà thử', 'cost': 10, 'stock': 1})
    gift = sql1('SELECT id FROM rewards WHERE class_id=%s AND name="Quà thử"', (cls,))['id']
    r = post(st, 'student/rewards.php', {'action': 'redeem', 'reward_id': gift}); expect('Thầy cô sẽ trao' in flash(r), 'Gift request pending')
    expect(sql1('SELECT stock FROM rewards WHERE id=%s', (gift,))['stock'] == 0, 'Stock decremented')
    red = sql1('SELECT id FROM reward_redemptions WHERE reward_id=%s', (gift,))['id']
    page(t3, 'rewards.php?class=%d' % cls, 404)
    post(t1, 'rewards.php?class=%d' % cls, {'class_id': cls, 'action': 'reject', 'redemption_id': red})
    expect(sql1('SELECT stock FROM rewards WHERE id=%s', (gift,))['stock'] == 1 and f'pts-big">{pts - 50 + 10}<' in page(st, 'student/rewards.php').text, 'Reject refunds points and stock')
    post(t1, 'rewards.php?class=%d' % cls, {'class_id': cls, 'action': 'visible'})
    expect('Bảng xếp hạng' not in page(st, 'student/rewards.php').text, 'Teacher can hide leaderboard')
    post(t1, 'rewards.php?class=%d' % cls, {'class_id': cls, 'action': 'visible'})

    # ================= 4. Thông báo: phụ huynh tự đăng ký, SMS + Zalo qua stub
    sql('REPLACE INTO settings (k, v) VALUES ("sms_url","http://127.0.0.1:8093/sms"),("sms_token","sms-secret"),("notify_test_mode","0"),'
        '("zalo_access_token","zalo-secret"),("zalo_template_id","T123"),("zalo_endpoint","http://127.0.0.1:8093/zalo"),'
        '("zalo_template_params","student_name=hs,correct=dung,total=tong")')
    par = login(p + '_parent', url='parent/index.php'); post(par, 'parent/index.php', {'action': 'login', 'username': p + '_parent', 'password': d['password']})
    r = page(par, 'parent/home.php'); expect('Điểm thi đua' in r.text and 'Nhận kết quả buổi học' in page(par, 'parent/home.php?tab=account').text, 'Parent sees points and notify opt-in (account tab)')
    r = post(par, 'parent/home.php', {'action': 'notify', 'channel': 'sms', 'phone': '123'}); expect('10 số' in flash(r), 'Invalid phone rejected')
    post(par, 'parent/home.php', {'action': 'notify', 'channel': 'sms', 'phone': '+84 912 345 678'})
    expect(sql1('SELECT phone FROM parents WHERE username=%s', (p + '_parent',))['phone'] == '0912345678', 'Phone normalized')
    r = post(t1, 'results.php?id=%d' % s1, {'action': 'notify'}); expect('gửi được 1' in flash(r), 'Teacher sends SMS report')
    m = inbox[-1]; expect(m['path'] == '/sms' and m['headers'].get('Authorization') == 'Bearer sms-secret' and 'dung 1/1' in m['body']['message'], 'SMS body ASCII with score')
    n = len(inbox); post(t1, 'results.php?id=%d' % s1, {'action': 'notify'}); expect(len(inbox) == n, 'Same session not sent twice')
    post(par, 'parent/home.php', {'action': 'notify', 'channel': 'zalo', 'phone': '0912345678'})
    s3 = ended_session(correct=False)
    post(t1, 'results.php?id=%d' % s3, {'action': 'notify'}); m = inbox[-1]
    expect(m['path'] == '/zalo' and m['headers'].get('access_token') == 'zalo-secret' and m['body']['phone'] == '84912345678'
           and m['body']['template_data'] == {'student_name': 'Học sinh kiểm thử', 'correct': '0', 'total': '1'}, 'Zalo ZNS payload')
    s4 = ended_session(released=0); r = post(t1, 'results.php?id=%d' % s4, {'action': 'notify'}); expect('công bố' in flash(r), 'Unreleased session not notified')
    page(t1, 'notify.php'); expect('Cài đặt nhà cung cấp' not in page(t2, 'notify.php').text, 'Only server admin edits providers')
    r = post(t1, 'notify.php', {'action': 'config', 'sms_url': 'http://insecure', 'zalo_template_id': 'T123'}); expect('https://' in flash(r), 'Provider URL must be https')

    # ================= 2. Đồng bộ Hybrid: giáo viên 1 (server lớp học) → giáo viên 2 (Cloud, cùng server thử)
    r = post(t2, 'sync.php', {'action': 'new_token', 'label': 'Phòng 201'}); token = BeautifulSoup(r.text, 'html.parser').select_one('.flash.warn code').text
    r = post(t1, 'sync.php', {'action': 'config', 'cloud_url': BASE, 'cloud_token': 'ars_wrongwrongwrongwrongwrong'}); expect('Mã đồng bộ sai' in flash(r), 'Wrong token rejected')
    r = post(t1, 'sync.php', {'action': 'config', 'cloud_url': BASE, 'cloud_token': token}); expect('Kết nối Cloud thành công' in flash(r) and 'Giáo viên 2' in flash(r), 'Cloud ping ok')
    expect(requests.post(BASE + 'api/sync.php', json={'ping': True}).status_code == 403, 'Sync API requires token')
    n_ended = sql1('SELECT COUNT(*) n FROM sessions WHERE class_id=%s AND status="ended"', (cls,))['n']
    r = post(t1, 'sync.php', {'action': 'push'}); expect('Đã đồng bộ' in flash(r), 'Sync to Cloud: ' + flash(r))
    cc = sql1('SELECT * FROM classes WHERE teacher_id=%s AND name="Lớp kiểm thử"', (t2id,))
    expect(cc and cc['cohort'] == '6A1' and cc['subject'] == 'Toán', 'Cloud class created with cohort/subject')
    expect(sql1('SELECT COUNT(*) n FROM students WHERE class_id=%s', (cc['id'],))['n'] == sql1('SELECT COUNT(*) n FROM students WHERE class_id=%s', (cls,))['n'], 'Students mirrored')
    expect(sql1('SELECT COUNT(*) n FROM sessions WHERE class_id=%s', (cc['id'],))['n'] == n_ended, 'Ended sessions mirrored')
    def cloud_answers(): return sql1('SELECT COUNT(*) n FROM final_answers f JOIN session_questions sq ON sq.id=f.session_q_id JOIN sessions s ON s.id=sq.session_id WHERE s.class_id=%s', (cc['id'],))['n']
    na = cloud_answers(); expect(na >= 4, 'Final answers mirrored')
    post(t1, 'sync.php', {'action': 'push', 'all': '1'})
    expect(sql1('SELECT COUNT(*) n FROM sessions WHERE class_id=%s', (cc['id'],))['n'] == n_ended and cloud_answers() == na, 'Re-sync is idempotent')
    expect(sql1('SELECT COUNT(*) n FROM classes WHERE teacher_id=%s AND name="Lớp kiểm thử"', (t2id,))['n'] == 1, 'Class not duplicated')
    # Học sinh ở nhà thấy điểm trên Cloud: XP thi đua tính cả buổi trên lớp đồng bộ
    r = page(t2, 'rewards.php?class=%d' % cc['id']); expect('Học sinh kiểm thử' in r.text, 'Cloud leaderboard from synced data')
    # Công bố muộn → đồng bộ lại + tự báo phụ huynh của lớp trên Cloud
    r = post(t2, 'family.php?class=%d' % cc['id'], {'action': 'invite', 'student_code': d['code'], 'class_id': cc['id']})
    tok = BeautifulSoup(r.text, 'html.parser').select_one('.invite-once .io-code').text
    post(par, 'parent/home.php', {'invite': tok}); post(par, 'parent/home.php', {'action': 'notify', 'channel': 'sms', 'phone': '0912345678'})
    sql('REPLACE INTO settings (k, v) VALUES ("notify_auto","1")')
    post(t1, 'results.php?id=%d' % s4, {'action': 'release', 'on': 1})
    expect(sql1('SELECT cloud_synced_at FROM sessions WHERE id=%s', (s4,))['cloud_synced_at'] is None, 'Release toggle marks session for re-sync')
    n = len(inbox); r = post(t1, 'sync.php', {'action': 'push'})
    cs4 = sql1('SELECT cloud_id FROM sync_map WHERE teacher_id=%s AND entity="session" AND local_id=%s', (t2id, s4))['cloud_id']
    expect(sql1('SELECT released FROM sessions WHERE id=%s', (cs4,))['released'] == 1, 'Release state synced')
    expect(len(inbox) == n + 1 and 'thông báo' in flash(r), 'Cloud auto-notifies parents on synced release')
    # Tự đồng bộ (ars-autosync.timer): buổi mới lên Cloud không cần bấm nút; không có gì mới thì không gửi
    autosync = lambda *a: subprocess.run(['php', os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'deploy', 'ars-autosync.php'), *a],
                                         capture_output=True, text=True, env=os.environ)
    expect('Tự động: BẬT' in page(t1, 'sync.php').text, 'Auto-sync on by default')
    s5 = ended_session(); a = autosync()
    expect(a.returncode == 0 and 'Đã đồng bộ' in a.stdout and sql1('SELECT cloud_synced_at FROM sessions WHERE id=%s', (s5,))['cloud_synced_at'] is not None, 'Auto-sync pushes a new session: ' + a.stdout + a.stderr)
    expect(sql1('SELECT message FROM sync_log WHERE teacher_id=%s ORDER BY id DESC LIMIT 1', (t1id,))['message'].startswith('[Tự động]'), 'Auto-sync logged as automatic')
    expect(autosync().stdout == '', 'Auto-sync idle when nothing new')
    r = post(t1, 'sync.php', {'action': 'auto', 'on': 0}); expect('Đã tắt tự đồng bộ' in flash(r), 'Teacher can turn auto-sync off')
    s6 = ended_session(); autosync()
    expect(sql1('SELECT cloud_synced_at FROM sessions WHERE id=%s', (s6,))['cloud_synced_at'] is None, 'Auto-sync off: session left for manual sync')
    post(t1, 'sync.php', {'action': 'auto', 'on': 1})
    sql('UPDATE sync_tokens SET revoked=1 WHERE teacher_id=%s', (t2id,))
    n_log = sql1('SELECT COUNT(*) n FROM sync_log WHERE teacher_id=%s', (t1id,))['n']
    a = autosync(); expect('Lỗi' in a.stdout and 'thu hồi' in a.stdout, 'Auto-sync reports Cloud error')
    a = autosync(); expect(a.stdout == '', 'Auto-sync backs off after an error (retry later)')
    a = autosync('--now'); expect(a.returncode == 1, '"ars sync" (--now) retries immediately, exit 1 on error')
    expect(sql1('SELECT COUNT(*) n FROM sync_log WHERE teacher_id=%s', (t1id,))['n'] == n_log + 1, 'Repeated auto-sync errors logged once')
    expect('tự thử lại' in page(t1, 'sync.php').text, 'Sync page shows auto-sync retry state')
    r = post(t1, 'sync.php', {'action': 'push'}); expect('thu hồi' in flash(r), 'Revoked token blocked')
    page(t1, 'sync.php')
finally:
    for k in ['sms_url', 'sms_token', 'notify_test_mode', 'zalo_access_token', 'zalo_template_id', 'zalo_endpoint', 'zalo_template_params', 'notify_auto']:
        if k in saved: sql('REPLACE INTO settings (k, v) VALUES (%s,%s)', (k, saved[k]))
        else: sql('DELETE FROM settings WHERE k=%s', (k,))
    stub.shutdown()
print(json.dumps({'passed': len(checks), 'checks': checks}, ensure_ascii=False, indent=2))
