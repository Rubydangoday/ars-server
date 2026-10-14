"""python3 tests/parent_accounts_integration.py /tmp/ars-smoke.json   (chạy sau http_smoke.py, CSDL tạm của run_local.sh)
Tài khoản phụ huynh theo SĐT do giáo viên cấp:
  - cột "SĐT phụ huynh" khi nhập danh sách (Excel mất số 0 đầu, có dấu cách, sai → cảnh báo, không chặn dòng); sửa tay từng em
  - Cấp tài khoản & tải file (CSV / Excel): SĐT, học sinh, mật khẩu lần đầu; anh chị em cùng SĐT = một tài khoản, cùng mật khẩu
  - bấm lại: không đổi mật khẩu đã cấp, trừ khi chọn "Cấp lại"; phụ huynh đã đổi mật khẩu không bị ảnh hưởng
  - phụ huynh đăng nhập bằng SĐT (+84 / dấu cách) → bắt buộc đổi mật khẩu trước khi xem gì; mật khẩu yếu / trùng bị từ chối
  - cấp lại mật khẩu từng em (quên mật khẩu); đổi SĐT → bỏ liên kết tài khoản cũ
  - giáo viên khác không cấp lại được tài khoản dùng chung với lớp của mình
"""
import csv, io, json, os, secrets, sys, zipfile, configparser, random
import requests, pymysql
from bs4 import BeautifulSoup

BASE = os.environ.get('TEST_BASE', 'http://127.0.0.1:8091/')
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
def ok(r, what):
    expect(r.status_code in (200, 302, 303) and 'Fatal error' not in r.text and 'Warning:' not in r.text, what)
    return r
def csrf(s, url):
    soup = BeautifulSoup(s.get(BASE + url).text, 'html.parser')
    t = soup.select_one('input[name=csrf]') or soup.select_one('meta[name=csrf]')
    return t.get('value', t.get('content'))
def post(s, url, data, files=None, follow=True, csrf_from=None):
    return ok(s.post(BASE + url, data={'csrf': csrf(s, csrf_from or url), **data}, files=files, allow_redirects=follow), 'POST ' + url.split('?')[0])
def flash(r):
    return ' '.join(x.get_text(' ', strip=True) for x in BeautifulSoup(r.text, 'html.parser').select('.flash'))
def login(i):
    s = requests.Session(); post(s, 'index.php', {'username': d['prefix'] + '_' + str(i), 'password': d['password']}); return s

t1, t2 = login(1), login(2)
t1id, t2id = d['teachers'][0], d['teachers'][1]
tag = secrets.token_hex(3).upper()
ph = ['09' + ''.join(random.choice('0123456789') for _ in range(8)) for _ in range(4)]   # 4 SĐT riêng cho lượt chạy này
cls = ins('INSERT INTO classes (teacher_id, name, room_id, join_code) VALUES (%s,%s,%s,%s)', (t1id, 'Lớp PH ' + tag, 210, 'P' + tag[:5]))
fam = f'family.php?class={cls}'

# ---- nhập danh sách có SĐT phụ huynh
csv_in = ('STT;Họ và tên;MSSV;Họ tên phụ huynh;SĐT phụ huynh\n'
          f'1;Nguyễn An;PA{tag};Nguyễn Bố;{ph[0][1:]}\n'                   # Excel mất số 0 đầu
          f'2;Trần Bình;PB{tag};Trần Mẹ;{ph[1][:4]} {ph[1][4:7]} {ph[1][7:]}\n'
          f'3;Lê Cường;PC{tag};;\n'
          f'4;Phạm Dũng;PD{tag};Phạm Cha;12345\n'                          # sai → cảnh báo, vẫn nhập học sinh
          f'5;Nguyễn Em;PE{tag};Nguyễn Bố;0{ph[0][1:]}\n')                  # em của An: cùng SĐT
r = post(t1, f'class.php?id={cls}', {'action': 'import_upload'}, files={'file': ('ds.csv', ('﻿' + csv_in).encode('utf-8'), 'text/csv')})
expect(ph[0] in r.text and ph[1] in r.text and 'SĐT phụ huynh không hợp lệ' in r.text and 'Nguyễn Bố' not in r.text,
       'Import preview: phone column normalised, parent-name column ignored, bad phone warned')
post(t1, f'class.php?id={cls}', {'action': 'import_confirm'})
got = {x['seat_no']: x['parent_phone'] for x in sql('SELECT seat_no, parent_phone FROM students WHERE class_id=%s', (cls,))}
expect(got == {1: ph[0], 2: ph[1], 3: None, 4: None, 5: ph[0]}, 'Students imported with parent phones (bad phone dropped)')
# nhập lại file không có cột SĐT: giữ SĐT cũ
post(t1, f'class.php?id={cls}', {'action': 'import_upload'}, files={'file': ('ds.csv', f'STT;Họ và tên;MSSV\n1;Nguyễn An;PA{tag}\n'.encode(), 'text/csv')})
post(t1, f'class.php?id={cls}', {'action': 'import_confirm'})
expect(sql('SELECT parent_phone FROM students WHERE class_id=%s AND seat_no=1', (cls,))[0]['parent_phone'] == ph[0], 'Re-import without phone column keeps phones')
r = post(t1, f'class.php?id={cls}', {'action': 'edit_student', 'seat_no': 3, 'student_code': f'PC{tag}', 'full_name': 'Lê Cường', 'parent_phone': '123'})
expect('không hợp lệ' in flash(r), 'Manual edit rejects bad phone')
r = post(t1, f'class.php?id={cls}', {'action': 'edit_student', 'seat_no': 3, 'student_code': f'PC{tag}', 'full_name': 'Lê Cường', 'parent_phone': '+84 ' + ph[2][1:]})
expect(sql('SELECT parent_phone FROM students WHERE class_id=%s AND seat_no=3', (cls,))[0]['parent_phone'] == ph[2], 'Manual edit stores normalised phone')
expect(ph[2] in t1.get(BASE + f'class.php?id={cls}').text, 'Class page lists parent phones')

# ---- cấp tài khoản + tải file
def export(s=t1, url=fam, **extra):
    r = s.post(BASE + url, data={'csrf': csrf(s, url), 'class_id': url.split('=')[1], 'action': 'accounts', 'format': 'csv', **extra})
    expect(r.status_code == 200 and r.content.startswith(b'\xef\xbb\xbf'), 'Account file downloaded (CSV)')
    rows = list(csv.reader(io.StringIO(r.content.decode('utf-8-sig')), delimiter=';'))
    return rows[0], {x[1]: x for x in rows[1:]}
head, rows = export()
expect(head[:6] == ['STT', 'Họ tên học sinh', 'MSSV', 'Lớp', 'SĐT phụ huynh (tên đăng nhập)', 'Mật khẩu lần đầu'], 'File columns: STT, student, code, class, phone, password')
pw_an = rows['Nguyễn An'][5]
expect(len(pw_an) == 8 and rows['Nguyễn An'][4] == ph[0] and rows['Nguyễn Em'][5] == pw_an and 'Cùng tài khoản' in rows['Nguyễn Em'][7],
       'New account: 8-char password; sibling with same phone shares account + password')
expect(rows['Phạm Dũng'][5] == '—' and 'Chưa có SĐT' in rows['Phạm Dũng'][7] and len(rows['Lê Cường'][5]) == 8, 'No phone → no account; other new accounts get passwords')
pa = sql('SELECT * FROM parents WHERE username=%s', (ph[0],))[0]
expect(pa['provisioned'] == 1 and pa['must_change_pw'] == 1 and pa['phone'] == ph[0], 'Parent account created: username = phone, must change password')
expect({x['student_code'] for x in sql('SELECT student_code FROM parent_links WHERE parent_id=%s', (pa['id'],))} == {f'PA{tag}', f'PE{tag}'}, 'Both siblings linked to one account')
r = t1.post(BASE + fam, data={'csrf': csrf(t1, fam), 'class_id': cls, 'action': 'accounts', 'format': 'xlsx'})
expect(zipfile.is_zipfile(io.BytesIO(r.content)), 'Account file downloaded (Excel)')
_, rows = export()   # lượt Excel ở trên không cấp lại (không chọn "Cấp lại") → mật khẩu cũ vẫn dùng được
expect(rows['Nguyễn An'][5] == '—' and 'chưa đăng nhập' in rows['Nguyễn An'][7], 'Second export keeps issued passwords')
page = t1.get(BASE + fam).text
expect('chờ đăng nhập lần đầu' in page and ph[0] in page, 'Family page shows account state per student')

# ---- phụ huynh đăng nhập lần đầu → bắt buộc đổi mật khẩu
def plogin(user, pw):
    s = requests.Session()
    r = s.post(BASE + 'parent/index.php', data={'csrf': csrf(s, 'parent/index.php'), 'action': 'login', 'username': user, 'password': pw}, allow_redirects=False)
    return s, r
p, r = plogin('+84 ' + ph[0][1:4] + ' ' + ph[0][4:], pw_an)
expect(r.status_code in (302, 303) and r.headers['Location'].endswith('password.php'), 'Phone login (+84, spaces) → forced to change password')
r = p.get(BASE + 'parent/home.php', allow_redirects=False)
expect(r.status_code in (302, 303) and 'password.php' in r.headers['Location'], 'Nothing visible before the password is changed')
for bad, why in [('abc', 'tối thiểu'), ('chiconchu', 'tối thiểu'), (pw_an, 'khác mật khẩu cũ')]:
    r = post(p, 'parent/password.php', {'new': bad, 'confirm': bad}); expect(why in flash(r), f'Weak/old password rejected ({why})')
r = post(p, 'parent/password.php', {'new': 'MatKhau2026', 'confirm': 'MatKhau2027'}); expect('không khớp' in flash(r), 'Confirmation mismatch rejected')
r = post(p, 'parent/password.php', {'new': 'MatKhau2026', 'confirm': 'MatKhau2026'})
expect('Đã đổi mật khẩu' in flash(r) and 'Nguyễn An' in r.text and 'Nguyễn Em' in r.text, 'Password changed → parent home with both children')
expect(sql('SELECT must_change_pw FROM parents WHERE id=%s', (pa['id'],))[0]['must_change_pw'] == 0, 'must_change cleared')
_, r = plogin(ph[0], pw_an); expect(r.status_code == 200 and 'không đúng' in flash(r), 'Teacher-issued password no longer works')
p, r = plogin(ph[0], 'MatKhau2026'); expect(r.headers.get('Location', '').endswith('home.php'), 'Login with own password goes straight home')
r = post(p, 'parent/password.php', {'current': 'sai-mat-khau', 'new': 'MatKhau2030', 'confirm': 'MatKhau2030'})
expect('hiện tại không đúng' in flash(r), 'Voluntary change needs the current password')
expect('Đổi mật khẩu' in p.get(BASE + 'parent/home.php?tab=account').text, 'Account tab links to password change')

# ---- giáo viên: bấm lại không ảnh hưởng phụ huynh đã đổi; cấp lại có chọn; cấp lại từng em
_, rows = export(reissue='1')
expect(rows['Nguyễn An'][5] == '—' and 'Đang dùng' in rows['Nguyễn An'][7], 'Reissue skips parents who changed their password')
pw_c2 = rows['Lê Cường'][5]
expect(len(pw_c2) == 8, 'Reissue gives a new password to parents who never logged in')
r = post(t1, fam, {'class_id': cls, 'student_code': f'PA{tag}', 'action': 'account_reset'})
new_pw = BeautifulSoup(r.text, 'html.parser').select_one('#pacc .flash.warn code[translate=no]').text
_, r = plogin(ph[0], new_pw)
expect(r.headers.get('Location', '').endswith('password.php') and sql('SELECT must_change_pw FROM parents WHERE id=%s', (pa['id'],))[0]['must_change_pw'] == 1,
       'Per-student reset (forgotten password): new one-time password, must change again')

# ---- đổi SĐT → bỏ liên kết tài khoản cũ, tạo tài khoản mới
pb = sql('SELECT id FROM parents WHERE username=%s', (ph[1],))[0]['id']
post(t1, f'class.php?id={cls}', {'action': 'edit_student', 'seat_no': 2, 'student_code': f'PB{tag}', 'full_name': 'Trần Bình', 'parent_phone': ph[3]})
_, rows = export()
expect(rows['Trần Bình'][4] == ph[3] and len(rows['Trần Bình'][5]) == 8 and not sql('SELECT 1 FROM parent_links WHERE parent_id=%s', (pb,)),
       'Changed phone: old account unlinked, new account issued')

# ---- giáo viên khác: liên kết được nhưng không cấp lại mật khẩu tài khoản dùng chung
cls2 = ins('INSERT INTO classes (teacher_id, name, room_id, join_code) VALUES (%s,%s,%s,%s)', (t2id, 'Lớp PH2 ' + tag, 211, 'Q' + tag[:5]))
ins('INSERT INTO students (class_id, seat_no, student_code, full_name, parent_phone) VALUES (%s,1,%s,%s,%s)', (cls2, f'PX{tag}', 'Nguyễn Út', ph[0]))
expect(t2.get(BASE + fam).status_code == 404, 'Other teacher cannot open this class family page')
_, rows = export(t2, f'family.php?class={cls2}', reissue='1')
expect(rows['Nguyễn Út'][5] == '—' and 'dùng chung' in rows['Nguyễn Út'][7], 'Shared account: other teacher links child but cannot reset password')
r = post(t2, f'family.php?class={cls2}', {'class_id': cls2, 'student_code': f'PX{tag}', 'action': 'account_reset'})
expect('dùng chung' in flash(r), 'Shared account: per-student reset refused for other teacher')
expect(sql('SELECT COUNT(*) n FROM parent_links WHERE parent_id=%s', (pa['id'],))[0]['n'] == 3, 'Third child (other class) linked to the same parent')

# ---- file mẫu của ARS (Tải file mẫu): nhập lại đúng số thứ tự + SĐT phụ huynh
tpl = t1.get(BASE + 'template.php').content
cls3 = ins('INSERT INTO classes (teacher_id, name, room_id, join_code) VALUES (%s,%s,%s,%s)', (t1id, 'Lớp mẫu ' + tag, 212, 'R' + tag[:5]))
post(t1, f'class.php?id={cls3}', {'action': 'import_upload'}, files={'file': ('ARS_mau_nhap_lieu.xlsx', tpl, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')})
post(t1, f'class.php?id={cls3}', {'action': 'import_confirm'})
expect([(x['seat_no'], x['parent_phone']) for x in sql('SELECT seat_no, parent_phone FROM students WHERE class_id=%s ORDER BY seat_no', (cls3,))]
       == [(1, '0912345678'), (2, '0987654321'), (3, None)], 'ARS template (So thu tu / SDT phu huynh) imports seats and phones')
sql('DELETE FROM students WHERE class_id=%s', (cls3,)); sql('DELETE FROM classes WHERE id=%s', (cls3,))

# dọn dữ liệu của bài test
sql('DELETE FROM parents WHERE username IN (%s,%s,%s,%s)', tuple(ph))
sql('DELETE FROM students WHERE class_id IN (%s,%s)', (cls, cls2)); sql('DELETE FROM classes WHERE id IN (%s,%s)', (cls, cls2))
print(json.dumps({'passed': len(checks), 'checks': checks}, ensure_ascii=False, indent=2))
