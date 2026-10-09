"""python3 tests/tutor_integration.py /tmp/ars-smoke.json
Trợ lý Cú Học dạng nhiều cuộc trò chuyện (migration 010) + trang Câu đúng / Rút kinh nghiệm gộp mọi nguồn (Remote, luyện tập, bài về nhà).
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
def sql(q, a=()):
    with db.cursor() as c: c.execute(q, a); return c.fetchall(), c.lastrowid
def sql1(q, a=()): r = sql(q, a)[0]; return r[0] if r else None

checks = []
def expect(test, msg):
    assert test, msg
    checks.append(msg)
def csrf(s, url):
    soup = BeautifulSoup(s.get(BASE + url).text, 'html.parser'); t = soup.select_one('input[name=csrf]') or soup.select_one('meta[name=csrf]')
    return t.get('value', t.get('content'))
def clean(r, what): expect(r.status_code == 200 and all(x not in r.text for x in ['Fatal error', 'Warning:', 'Deprecated:']), 'No PHP errors ' + what)

s = requests.Session()
s.post(BASE + 'student/index.php', data={'csrf': csrf(s, 'student/index.php'), 'code': d['code'], 'password': d['password']})
tok = csrf(s, 'student/tutor.php')
def api(**f):
    r = s.post(BASE + 'student/api_tutor.php', data={'csrf': tok, **f}); clean(r, 'api ' + f['action']); return r.json()

# ---- Câu đúng từ bài luyện tập (không chỉ Remote)
q = sql1('SELECT id FROM questions WHERE correct IS NOT NULL AND correct<>"" LIMIT 1')
_, att = sql('INSERT INTO practice_attempts(student_code,class_id,source,title,finished_at,n_questions,n_first_ok) VALUES(%s,%s,"topic","Ôn thử",NOW(),1,1)', (d['code'], d['class']))
corr = sql1('SELECT correct FROM questions WHERE id=%s', (q['id'],))['correct']
sql('INSERT INTO practice_items(attempt_id,question_id,ord,round,answer,is_correct,answered_at) VALUES(%s,%s,1,1,%s,1,NOW())', (att, q['id'], corr[0]))
r = s.get(BASE + 'student/right.php'); clean(r, 'right.php')
expect('Luyện tập' in r.text, 'Right answers include practice items')
r = s.get(BASE + 'student/right.php?src=practice'); clean(r, 'right.php?src=practice')
r = s.get(BASE + 'student/lessons.php?bai=p%d' % att); clean(r, 'lessons.php practice attempt')
expect('Ôn thử' in r.text and 'review.php?a=%d' % att in r.text, 'Lessons page shows practice attempt with review link')
r = s.get(BASE + 'student/lessons.php'); clean(r, 'lessons.php default')

# ---- Trợ lý: nhiều cuộc trò chuyện
r = s.get(BASE + 'student/tutor.php'); clean(r, 'tutor.php')
soup = BeautifulSoup(r.text, 'html.parser')
expect(soup.select_one('#ct-convs') is not None and soup.select('.ct-tool[data-mode]') and soup.select('.ct-card'), 'Tutor renders chat-app layout')
j = api(action='send', mode='ask', text='Phân số là gì?', conversation_id=0)
c1 = j['conversation']['id']
expect(c1 and 'Phân số' in j['conversation']['title'] and j['message']['role'] == 'assistant', 'First message creates titled conversation')
api(action='send', mode='ask', text='Cho ví dụ', conversation_id=c1)
j = api(action='send', mode='ask', text='Câu hỏi khác hẳn', conversation_id=0); c2 = j['conversation']['id']
expect(c2 != c1, 'New chat creates second conversation')
h = api(action='history', conversation_id=c1)
expect(len(h['messages']) == 4 and h['conversation']['id'] == c1, 'History scoped to conversation')
mid = h['messages'][1]['id']
api(action='feedback', message_id=mid, value=1)
expect(api(action='history', conversation_id=c1)['messages'][1]['feedback'] == 1, 'Feedback stored')
cs = api(action='rename', conversation_id=c1, title='Ôn phân số')['conversations']
expect(any(c['id'] == c1 and c['title'] == 'Ôn phân số' for c in cs) and cs[0]['id'] == c2, 'Rename + list newest first')
# không xem được cuộc trò chuyện của người khác
other = sql1('SELECT student_code FROM student_users WHERE student_code<>%s LIMIT 1', (d['code'],))
if other:
    _, foreign = sql('INSERT INTO tutor_conversations(student_code,title) VALUES(%s,"Bí mật")', (other['student_code'],))
    expect(not api(action='history', conversation_id=foreign)['messages'], 'Cannot read another student\'s conversation')
cs = api(action='delete', conversation_id=c2)['conversations']
expect(all(c['id'] != c2 for c in cs) and not sql1('SELECT 1 x FROM tutor_messages WHERE conversation_id=%s', (c2,)), 'Delete removes conversation and messages')
api(action='clear')
expect(not api(action='conversations')['conversations'], 'Clear removes all conversations')

# ---- chế độ cơ bản: hiểu ý câu hỏi, câu nối tiếp bám chủ đề trước và không lặp lại
cls = sql1('SELECT class_id FROM students WHERE student_code=%s LIMIT 1', (d['code'],))['class_id']
sql("INSERT IGNORE INTO lessons(class_id,no,title,key_points,lecture_text,published) VALUES(%s,98,'MQTT','',%s,1)", (cls,
    "MQTT là giao thức truyền thông điệp theo mô hình publish/subscribe, chạy trên TCP/IP.\nBroker là máy chủ trung gian nhận thông điệp và chuyển tới subscriber.\n"
    "Topic là chuỗi phân cấp dùng để phân loại thông điệp, ví dụ nha/phongkhach/nhietdo.\nPublisher gửi thông điệp lên broker kèm topic.\n"
    "Retained message giúp subscriber mới nhận ngay giá trị cuối cùng của topic.\nLast Will cho phép broker thông báo khi thiết bị mất kết nối.\nMQTT mặc định dùng cổng 1883."))
again = 'Bạn giải thích lại theo cách khác, đơn giản hơn và có ví dụ gần gũi giúp mình nhé.'
j = api(action='send', mode='ask', text='MQTT là gì?', conversation_id=0); cc = j['conversation']['id']
a1 = j['message']['content']
expect('publish/subscribe' in a1, 'Basic mode answers "what is" from the lecture')
a2 = api(action='send', mode='ask', text=again, conversation_id=cc)['message']['content']
a3 = api(action='send', mode='ask', text=again, conversation_id=cc)['message']['content']
body = lambda x: x.split('\n\n_(')[0]
expect(body(a2) != body(a1) and body(a3) != body(a2) and ('Broker' in a2 or 'Topic' in a2), 'Follow-up "explain another way" stays on topic and does not repeat')
g = api(action='send', mode='ask', text='hello', conversation_id=0)['message']['content']
expect('nội dung về' not in g and any(x in g for x in ['👋', '✨', '🦉']), 'Greeting gets a friendly reply, not "not found"')

# ---- mã mời phụ huynh ngắn (8 ký tự + QR), phiếu mời cả lớp, chống dò mã
import re
t = requests.Session()
t.post(BASE + 'index.php', data={'csrf': csrf(t, 'index.php'), 'username': d['prefix'] + '_1', 'password': d['password']})
fam = 'family.php?class=%d' % d['class']
r = t.post(BASE + fam, data={'csrf': csrf(t, fam), 'action': 'invite', 'class_id': d['class'], 'student_code': d['code']}); clean(r, 'family invite')
code = BeautifulSoup(r.text, 'html.parser').select_one('.io-code').text
expect(re.fullmatch(r'[A-Z2-9]{4}-[A-Z2-9]{4}', code), 'Invite code is 8 characters like ABCD-EFGH')
p2 = requests.Session(); typed = code.replace('-', ' ').lower()
r = p2.post(BASE + 'parent/index.php', data={'csrf': csrf(p2, 'parent/index.php?tab=register'), 'action': 'register', 'username': d['prefix'] + '_pshort',
            'password': 'Matkhau-2026-x', 'full_name': 'PH mã ngắn', 'invite': typed})
expect(r.url.endswith('parent/home.php') or 'Học sinh kiểm thử' in r.text or sql1('SELECT 1 x FROM parents WHERE username=%s', (d['prefix'] + '_pshort',)), 'Parent signs up with code typed in lowercase with a space')
r = t.post(BASE + fam, data={'csrf': csrf(t, fam), 'action': 'invite_all', 'class_id': d['class']}); clean(r, 'invite_all')
expect('invite-slip' in r.text or 'đã có phụ huynh liên kết' in r.text, 'Print invite slips for the whole class')
p3 = requests.Session()
for i in range(11):
    r = p3.post(BASE + 'parent/index.php', data={'csrf': csrf(p3, 'parent/index.php?tab=register'), 'action': 'register', 'username': d['prefix'] + '_guess',
                'password': 'Matkhau-2026-x', 'full_name': 'Dò', 'invite': 'AAAA-AAA%d' % (i % 8 + 2)})
expect('15' in r.text and not sql1('SELECT 1 x FROM parents WHERE username=%s', (d['prefix'] + '_guess',)), 'Guessing invite codes gets locked out')

# ---- trang Soạn câu hỏi: trình soạn kiểu Word + trợ lý AI giáo viên (hỏi đáp, ngữ cảnh)
r = t.get(BASE + 'compose.php'); clean(r, 'compose.php')
so = BeautifulSoup(r.text, 'html.parser')
expect(so.select_one('#qe .ribbon [data-cmd=eq]') and so.select_one('dialog#qe-eq') and 'qeditor.js' in r.text and 'mhchem' in r.text, 'Compose has Word-like toolbar, equation editor and chemistry support')
expect(len(so.select('.aw-card')) >= 6 and so.select_one('.ai-mode [data-mode=chat]'), 'AI panel has suggestion cards and Q&A mode')
tk = so.select_one('meta[name=csrf]')['content']
j = t.post(BASE + 'api/ai.php', data={'csrf': tk, 'task': 'chat', 'prompt': 'Lập giáo án 45 phút', 'turns': '[]'}).json()
expect('Gemini' in j.get('text', ''), 'Teacher Q&A in basic mode explains how to enable full AI')
j = t.post(BASE + 'api/ai.php', data={'csrf': tk, 'task': 'chat', 'prompt': '', 'turns': '[]'}).json()
expect('error' in j, 'Empty teacher question rejected')

# ---- lưu API key AI: ô trống giữ key cũ; mật khẩu do trình duyệt tự điền / key sai dạng không ghi đè
def save_ai(**f):
    return t.post(BASE + 'settings.php', data={'csrf': csrf(t, 'settings.php'), 'action': 'ai', 'provider': 'offline', **f})
gkey = 'AIza' + 'Sy' + 'x' * 33
save_ai(key_gemini=gkey, model_gemini='gemini-3-flash-preview')
cur = lambda: sql1("SELECT v FROM settings WHERE k='ai_key_gemini'")['v']
expect(cur() == gkey, 'Gemini key saved')
save_ai(key_gemini='', model_gemini='gemini-3-flash-preview')
expect(cur() == gkey, 'Empty key field keeps the saved key')
save_ai(key_gemini=d['password'], model_gemini='gemini-3-flash-preview')
expect(cur() == gkey, 'Browser-autofilled login password does not overwrite the key')
r = save_ai(key_gemini='not-a-key', model_gemini='gemini-3-flash-preview')
expect(cur() == gkey and 'không đúng dạng' in r.text, 'Malformed key rejected with a message, old key kept')
r = t.get(BASE + 'settings.php'); expect('Đã lưu key' in r.text and gkey[-4:] in r.text and gkey not in r.text, 'Settings show saved key hint without revealing it')
sql("DELETE FROM settings WHERE k IN ('ai_key_gemini','ai_model_gemini')")

# ---- xác nhận hoàn tất bộ câu hỏi: kiểm tra đủ câu, sửa câu thì về "đang soạn"
sp = 'set.php?id=%d' % d['set']
fin = lambda: sql1('SELECT finalized_at FROM question_sets WHERE id=%s', (d['set'],))['finalized_at']
r = t.get(BASE + sp); clean(r, 'set.php'); expect('id="finalize"' in r.text and 'Xác nhận hoàn tất bộ câu hỏi' in r.text, 'Set page shows finalize checklist')
r = t.post(BASE + sp, data={'csrf': csrf(t, sp), 'action': 'finalize', 'target_n': 50}); expect(fin() is None and 'chưa đủ' in r.text, 'Cannot finalize with fewer questions than expected')
r = t.post(BASE + sp, data={'csrf': csrf(t, sp), 'action': 'finalize', 'target_n': ''}); expect(fin() is not None and 'Đã hoàn tất bộ câu hỏi' in r.text, 'Finalize set')
expect('✓ Hoàn tất' in t.get(BASE + 'sets.php').text, 'Sets list shows finalized status')
tk = BeautifulSoup(t.get(BASE + 'compose.php').text, 'html.parser').select_one('meta[name=csrf]')['content']
j = t.post(BASE + 'api/qadd.php', data={'csrf': tk, 'set_id': d['set'], 'questions': json.dumps([{'content': 'Câu thêm sau khi chốt?', 'options': ['Có', 'Không'], 'correct': 'A'}])}).json()
expect(fin() is None, 'Adding a question puts the set back to drafting')
t.post(BASE + sp, data={'csrf': csrf(t, sp), 'action': 'finalize', 'target_n': ''}); t.post(BASE + sp, data={'csrf': csrf(t, sp), 'action': 'reopen'})
expect(fin() is None, 'Reopen set for editing')

print(json.dumps({'passed': len(checks), 'checks': checks}, ensure_ascii=False))
