"""python3 tests/attendance_integration.py /tmp/ars-smoke.json   (chạy sau http_smoke.py, CSDL tạm của run_local.sh)
Điểm danh + thông báo phụ huynh + nhập danh sách học sinh:
  - tự động có mặt khi LOGIN bằng Remote HOẶC có câu trả lời; còn lại vắng; giáo viên sửa Có mặt / Vắng / Vắng có phép
  - Kết thúc buổi → mỗi phụ huynh một tin (kèm điểm khi đã công bố); bài kiểm tra công bố sau → cùng tin cập nhật điểm, hiện lại chưa đọc
  - phụ huynh thấy tin trong trang phụ huynh rồi tin được đánh dấu đã đọc; giáo viên khác không xem / sửa được điểm danh
  - trang điểm danh cả lớp + xuất CSV/Excel
  - nhập danh sách từ Word (.docx bảng), CSV có "Họ đệm" + "Tên" không có STT (tự đánh số), .txt mỗi dòng một học sinh
"""
import csv, io, json, os, secrets, subprocess, sys, zipfile, configparser
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
def page(s, url):
    r = s.get(BASE + url); expect(r.status_code == 200 and 'Fatal error' not in r.text and 'Warning:' not in r.text, 'GET ' + url.split('?')[0])
    return r
def post(s, url, data, files=None, csrf_from=None):
    soup = BeautifulSoup(s.get(BASE + (csrf_from or url)).text, 'html.parser')
    t = soup.select_one('input[name=csrf]') or soup.select_one('meta[name=csrf]')
    r = s.post(BASE + url, data={'csrf': t.get('value', t.get('content')), **data}, files=files)
    expect(r.status_code == 200 and 'Fatal error' not in r.text and 'Warning:' not in r.text, 'POST ' + url.split('?')[0]); return r
def login(i):
    s = requests.Session(); post(s, 'index.php', {'username': d['prefix'] + '_' + str(i), 'password': d['password']}); return s

t, other = login(1), login(2)
cls, setid, qid = d['class'], d['set'], d['question']
tag = secrets.token_hex(3).upper()
codes = {5: d['code'], 6: 'AT6' + tag, 7: 'AT7' + tag}
for seat in (6, 7):
    ins('INSERT INTO students (class_id, seat_no, student_code, full_name) VALUES (%s,%s,%s,%s)', (cls, seat, codes[seat], f'Học sinh ghế {seat}'))
phash = subprocess.check_output(['php', '-r', 'echo password_hash("Phu-huynh-2026", PASSWORD_DEFAULT);'], text=True)
parents = {}
for seat in (5, 6, 7):
    parents[seat] = ins('INSERT INTO parents (username, full_name, password_hash) VALUES (%s,%s,%s)', (f'ph{seat}_{tag}'.lower(), f'Phụ huynh {seat}', phash))
    ins('INSERT INTO parent_links (parent_id, student_code, class_id) VALUES (%s,%s,%s)', (parents[seat], codes[seat], cls))

def new_session(kind):
    sid = ins('INSERT INTO sessions (class_id, set_id, kind, released) VALUES (%s,%s,%s,%s)', (cls, setid, kind, 0 if kind == 'test' else 1))
    sq = ins('INSERT INTO session_questions (session_id, question_id, status, opened_at) VALUES (%s,%s,"open",NOW(3))', (sid, qid))
    return sid, sq
def msg(seat, sid):
    r = sql('SELECT * FROM parent_messages WHERE parent_id=%s AND session_id=%s', (parents[seat], sid)); return r[0] if r else None

# ---- buổi học: ghế 5 LOGIN + trả lời đúng (B), ghế 7 chỉ có câu trả lời (Remote đã giải phóng ghế), ghế 6 không có gì
sid, sq = new_session('class')
ins('INSERT INTO session_logins (session_id, seat_no, mac, logged_at) VALUES (%s,5,%s,NOW(3))', (sid, 'AA:BB:CC:00:00:05'))
ins('INSERT INTO responses (session_q_id, seat_no, mac, seq, answer, received_at) VALUES (%s,5,%s,1,"B",NOW(3))', (sq, 'AA:BB:CC:00:00:05'))
ins('INSERT INTO responses (session_q_id, seat_no, mac, seq, answer, received_at) VALUES (%s,7,%s,1,"A",NOW(3))', (sq, 'AA:BB:CC:00:00:07'))
att = {r['seat_no']: r for r in sql('SELECT * FROM attendance_final WHERE session_id=%s', (sid,))}
expect(att[5]['status'] == 'present' and att[7]['status'] == 'present' and att[6]['status'] == 'absent' and att[5]['source'] == 'auto',
       'Auto attendance: Remote LOGIN or an accepted answer = present, nothing = absent')
expect(msg(5, sid) is None, 'No parent message while the session is still running')
post(t, 'api/action.php', {'session': sid, 'action': 'end'}, csrf_from='dashboard.php')
m5, m6, m7 = msg(5, sid), msg(6, sid), msg(7, sid)
expect(m5 and 'có mặt' in m5['body'] and 'đúng 1/1 câu, điểm 10,0/10' in m5['body'] and m5['read_at'] is None,
       'Ending the session tells the parent: present + score (released class session)')
expect(m6 and 'vắng' in m6['body'] and 'Kết quả' not in m6['body'] and m7 and 'đúng 0/1' in m7['body'], 'Absent / wrong-answer students get the matching message')
page(other, 'index.php')
expect(other.get(BASE + f'attendance.php?session={sid}').status_code == 404, 'Another teacher cannot open this attendance')

# ---- giáo viên sửa: ghế 6 vắng có phép → tin cập nhật, hiện lại chưa đọc
sql('UPDATE parent_messages SET read_at=NOW() WHERE parent_id=%s AND session_id=%s', (parents[6], sid))
r = post(t, f'attendance.php?session={sid}', {'action': 'save', 'st[5]': 'auto', 'st[6]': 'excused', 'st[7]': 'auto', 'note[6]': 'Ốm, có giấy phép'})
m6 = msg(6, sid)
expect('vắng có phép' in m6['body'] and m6['read_at'] is None and 'Đã lưu điểm danh' in r.text,
       'Teacher sets "Vắng có phép": parent message updated and unread again')
expect(sql('SELECT status, source FROM attendance_final WHERE session_id=%s AND seat_no=6', (sid,))[0] == {'status': 'excused', 'source': 'teacher'},
       'Teacher override stored (source = teacher)')
r = page(t, f'attendance.php?session={sid}')
expect('Ốm, có giấy phép' in r.text and 'Vắng có phép' in r.text, 'Session attendance page shows statuses and notes')

# ---- bài kiểm tra chưa công bố: tin chưa có điểm; công bố → cùng tin có điểm
sid2, sq2 = new_session('test')
ins('INSERT INTO session_logins (session_id, seat_no, mac, logged_at) VALUES (%s,5,%s,NOW(3))', (sid2, 'AA:BB:CC:00:00:05'))
ins('INSERT INTO responses (session_q_id, seat_no, mac, seq, answer, received_at) VALUES (%s,5,%s,2,"B",NOW(3))', (sq2, 'AA:BB:CC:00:00:05'))
post(t, 'api/action.php', {'session': sid2, 'action': 'end'}, csrf_from='dashboard.php')
expect('Điểm sẽ được báo' in msg(5, sid2)['body'] and 'Kết quả' not in msg(5, sid2)['body'], 'Unreleased test: parent told present, score withheld')
sql('UPDATE parent_messages SET read_at=NOW() WHERE parent_id=%s', (parents[5],))
post(t, f'results.php?id={sid2}', {'action': 'release', 'on': 1})
m = msg(5, sid2)
expect('đúng 1/1 câu' in m['body'] and m['read_at'] is None and len(sql('SELECT id FROM parent_messages WHERE parent_id=%s AND session_id=%s', (parents[5], sid2))) == 1,
       'Releasing the test updates the SAME message with the score, unread again')

# ---- phụ huynh đọc tin
p = requests.Session()
tok = BeautifulSoup(p.get(BASE + 'parent/index.php').text, 'html.parser').select_one('input[name=csrf]')['value']
r = p.post(BASE + 'parent/index.php', data={'csrf': tok, 'action': 'login', 'username': f'ph5_{tag}'.lower(), 'password': 'Phu-huynh-2026'}, allow_redirects=False)
expect(r.status_code in (302, 303) and 'home.php' in r.headers.get('Location', ''), 'Parent logs in')
r = page(p, 'parent/home.php')
expect('Thông báo' in r.text and 'đúng 1/1 câu' in r.text and 'p-msg unread' in r.text, 'Parent home shows the new message highlighted')
expect(all(x['read_at'] is not None for x in sql('SELECT read_at FROM parent_messages WHERE parent_id=%s', (parents[5],))), 'Messages marked read after the parent viewed them')

# ---- điểm danh cả lớp + xuất
r = page(t, f'attendance.php?class={cls}')
expect('att-bad' in r.text and 'att-ok' in r.text, 'Class attendance grid renders')
r = t.get(BASE + f'attendance.php?class={cls}&format=csv')
rows = list(csv.reader(io.StringIO(r.content.decode('utf-8-sig')), delimiter=';'))
row6 = next(x for x in rows if x and x[0] == '6')
expect(r.content.startswith(b'\xef\xbb\xbf') and 'Tỉ lệ có mặt (%)' in rows[0] and 'P' in row6, 'Attendance CSV export (✓ / ✗ / P, attendance rate)')
expect(zipfile.is_zipfile(io.BytesIO(t.get(BASE + f'attendance.php?class={cls}&format=xlsx').content)), 'Attendance XLSX export')

# ---- nhập danh sách: Word (bảng), CSV "Họ đệm"+"Tên" không có STT, TXT
cls2 = ins('INSERT INTO classes (teacher_id, name, room_id, join_code) VALUES (%s,%s,%s,%s)', (d['teachers'][0], 'Lớp nhập ' + tag, 200, 'N' + tag[:5]))
def docx_table(rows):
    cell = lambda v: f'<w:tc><w:p><w:r><w:t xml:space="preserve">{v}</w:t></w:r></w:p></w:tc>'
    body = '<w:tbl>' + ''.join('<w:tr>' + ''.join(cell(v) for v in r) + '</w:tr>' for r in rows) + '</w:tbl>'
    b = io.BytesIO()
    with zipfile.ZipFile(b, 'w') as z:
        z.writestr('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>')
        z.writestr('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
                   + '<w:p><w:r><w:t>DANH SÁCH LỚP</w:t></w:r></w:p>' + body + '</w:body></w:document>')
    return b.getvalue()
def import_file(name, content, mime):
    r = post(t, f'class.php?id={cls2}', {'action': 'import_upload'}, files={'file': (name, content, mime)})
    return r
r = import_file('ds.docx', docx_table([['STT', 'Mã HS', 'Họ và tên'], ['1', '23134101', 'Nguyễn Văn An'], ['2', '23134102', 'Trần Thị Bình']]),
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document')
expect('Nguyễn Văn An' in r.text and 'Trần Thị Bình' in r.text and '23134102' in r.text, 'Word table import preview (STT / Mã HS / Họ và tên)')
post(t, f'class.php?id={cls2}', {'action': 'import_confirm'})
expect([(x['seat_no'], x['student_code'], x['full_name']) for x in sql('SELECT * FROM students WHERE class_id=%s ORDER BY seat_no', (cls2,))]
       == [(1, '23134101', 'Nguyễn Văn An'), (2, '23134102', 'Trần Thị Bình')], 'Word list imported')
r = import_file('ds.csv', '﻿Họ đệm;Tên;MSSV\nLê Hoàng;Cường;23134103\nPhạm Thu;Dung;23134104\n'.encode('utf-8'), 'text/csv')
expect('tự đánh số' in r.text and 'Lê Hoàng Cường' in r.text, 'CSV with "Họ đệm" + "Tên" and no STT: names joined, seats auto-numbered')
post(t, f'class.php?id={cls2}', {'action': 'import_confirm'})
got = {x['student_code']: x['seat_no'] for x in sql('SELECT * FROM students WHERE class_id=%s', (cls2,))}
expect(got.get('23134103') == 1 and got.get('23134104') == 2, 'Auto-numbered seats 1, 2 (file order)')
r = import_file('ds.txt', '1. 23134105 Võ Minh Em\n2) 23134106 - Đỗ Gia Phúc\n'.encode('utf-8'), 'text/plain')
expect('Võ Minh Em' in r.text and 'Đỗ Gia Phúc' in r.text and '23134106' in r.text, 'TXT list "1. MSSV Họ tên" parsed')
r = import_file('ds.doc', b'binary', 'application/msword')
expect('.docx' in BeautifulSoup(r.text, 'html.parser').get_text(), 'Old .doc gives a clear "save as .docx" message')

# dọn dữ liệu riêng của bài test này: các bộ test sau dùng chung lớp kiểm thử (bảng thi đua, điểm…)
sql('DELETE FROM sessions WHERE id IN (%s,%s)', (sid, sid2))
sql('DELETE FROM students WHERE class_id=%s AND seat_no IN (6,7)', (cls,))
sql('DELETE FROM parent_links WHERE parent_id IN (%s,%s,%s)', tuple(parents.values()))
sql('DELETE FROM parents WHERE id IN (%s,%s,%s)', tuple(parents.values()))
sql('DELETE FROM students WHERE class_id=%s', (cls2,)); sql('DELETE FROM classes WHERE id=%s', (cls2,))

print(json.dumps({'passed': len(checks), 'checks': checks}, ensure_ascii=False, indent=2))
