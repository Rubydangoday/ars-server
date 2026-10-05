"""Uploads, released mistake bank, rubric grading via local stub, immutable submission and variant key."""
import os,json,re,base64,threading,subprocess,sys
from pathlib import Path
from http.server import BaseHTTPRequestHandler,HTTPServer
import requests,pymysql
from bs4 import BeautifulSoup
ROOT=Path(__file__).resolve().parents[1];sys.path.insert(0,str(ROOT/'common'));import arscfg
D=json.load(open(sys.argv[1]))['fixture'];BASE=os.environ.get('TEST_BASE','http://127.0.0.1:8091/');db=arscfg.db_connect(arscfg.load());checks=[]
def sql(s,args=()):
 with db.cursor() as c:c.execute(s,args);return c.fetchall()
def check(x,msg):assert x,msg;checks.append(msg)
def get(s,url):
 r=s.get(BASE+url);check(r.status_code==200,'GET '+url);return r

def post(s,url,data,files=None):
 r=get(s,'dashboard.php' if url.startswith('api/') else url);soup=BeautifulSoup(r.text,'html.parser');e=soup.select_one('[name=csrf]');r=s.post(BASE+url,data={'csrf':e.get('value',e.get('content')),**data},files=files);check(r.status_code==200 and 'Fatal error' not in r.text and 'Warning:' not in r.text,'POST '+url);return r

def teacher(i):
 s=requests.Session();post(s,'index.php',{'username':D['prefix']+'_'+str(i),'password':D['password']});return s
T=teacher(1);other=teacher(2);S=requests.Session();post(S,'student/index.php',{'code':D['code'],'password':D['password']})
# Image upload round-trip and MIME rejection
png=base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jvXcAAAAASUVORK5CYII=');url='media.php?set='+str(D['set'])
post(T,url,{'question':D['question'],'kind':'image'},files={'file':('test.png',png,'image/png')})
mid=sql('SELECT MAX(id) AS id FROM question_media WHERE question_id=%s',(D['question'],))[0]['id'];check(T.get(BASE+'question_file.php?id='+str(mid)).content==png,'Teacher can read uploaded image');check(other.get(BASE+'question_file.php?id='+str(mid)).status_code==404,'Unrelated teacher denied media')
r=post(T,url,{'question':D['question'],'kind':'image'},files={'file':('evil.png',b'<?php echo 1; ?>','image/png')});check('không được hỗ trợ' in r.text,'Reject PHP disguised as PNG')
# Clone key remapping: correct original option content stays correct.
post(T,'variants.php?id='+str(D['set']),{'code':'202','questions':'on','options':'on'})
v=sql('SELECT q.* FROM question_sets s JOIN questions q ON q.set_id=s.id WHERE s.source_set_id=%s AND s.variant_code="202" ORDER BY s.id DESC LIMIT 1',(D['set'],))[0];check(v['opt_'+v['correct'].lower()]=='2','Variant correct key matches original answer content');check(bool(sql('SELECT 1 FROM question_media WHERE question_id=%s',(v['id'],))),'Variant preserves media')
# New MCQ+essay assignment and an incorrect answer, to check student bank after release.
r=post(T,'homework.php',{'action':'create','class_id':D['class'],'title':'Kiểm tra sổ câu sai'});hid=int(re.search(r'id=(\d+)',r.url).group(1));hw='homework.php?id='+str(hid)
for kind in ['mcq','text','drawing']:
 post(T,hw,{'action':'add','kind':kind,'question_id':D['question'],'content':'Một cộng một bằng bao nhiêu?','topic':'Số học','rubric':'Đáp án đúng là 2.','max_score':'1'})
post(T,hw,{'action':'publish'});qs=sql('SELECT * FROM homework_questions WHERE homework_id=%s ORDER BY ord',(hid,));answers={'answer['+str(qs[0]['id'])+']':'A','answer['+str(qs[1]['id'])+']':'Hai','answer['+str(qs[2]['id'])+']':'Hình minh họa'}
post(S,'student/'+hw,{**answers,'action':'save'},files={'attachment['+str(qs[2]['id'])+']':('ve.png',png,'image/png')});post(S,'student/'+hw,{**answers,'action':'submit'})
sub=sql('SELECT * FROM homework_submissions WHERE homework_id=%s',(hid,))[0];aa=sql('SELECT * FROM homework_answers WHERE submission_id=%s ORDER BY hw_question_id',(sub['id'],));grade='homework_grade.php?id='+str(hid)
check(S.get(BASE+'lms_file.php?answer='+str(aa[2]['id'])).content==png,'Student drawing survives draft then submit');check(other.get(BASE+'lms_file.php?answer='+str(aa[2]['id'])).status_code==404,'Other teacher cannot read student attachment')
post(T,grade,{'action':'release','submission':sub['id']});check(not sql('SELECT released FROM homework_submissions WHERE id=%s',(sub['id'],))[0]['released'],'Incomplete grades cannot be released')
# Stub mimics OpenAI-compatible JSON; no external AI call/no student data sent outside test machine.
class Stub(BaseHTTPRequestHandler):
 def do_POST(self):
  body=json.loads(self.rfile.read(int(self.headers['Content-Length'])));prompt=body['messages'][-1]['content'];check('rubric' in prompt and 'max_score' in prompt,'AI receives teacher rubric and score bounds');out=json.dumps({'choices':[{'message':{'content':json.dumps({'score':0.75,'feedback':'Gợi ý thử nghiệm: đúng ý, cần trình bày.'},ensure_ascii=False)}}]});self.send_response(200);self.send_header('Content-Type','application/json');self.end_headers();self.wfile.write(out.encode())
 def log_message(self,*args):pass
server=HTTPServer(('127.0.0.1',8092),Stub);threading.Thread(target=server.serve_forever,daemon=True).start()
settings=['ai_provider','ai_base_openai','ai_model_openai','ai_key_openai'];old={k:sql('SELECT v FROM settings WHERE k=%s',(k,)) for k in settings}
try:
 for k,vv in zip(settings,['openai','http://127.0.0.1:8092/v1','stub','test']):sql('INSERT INTO settings(k,v) VALUES(%s,%s) ON DUPLICATE KEY UPDATE v=VALUES(v)',(k,vv))
 post(T,grade,{'action':'ai','answer_id':aa[1]['id'],'submission':sub['id']});r=sql('SELECT * FROM homework_answers WHERE id=%s',(aa[1]['id'],))[0];check(float(r['ai_score'])==.75 and r['score'] is None,'AI score remains draft until teacher confirms')
finally:
 for k,vv in old.items():
  if vv:sql('UPDATE settings SET v=%s WHERE k=%s',(vv[0]['v'],k))
  else:sql('DELETE FROM settings WHERE k=%s',(k,))
 server.shutdown()
for a in aa[1:]:post(T,grade,{'action':'grade','answer_id':a['id'],'submission':sub['id'],'score':1,'feedback':'Đã duyệt'})
post(T,grade,{'action':'release','submission':sub['id']});check('Một cộng một' in get(S,'student/wrong.php').text,'Released homework error added to wrong bank')
# Submitted answer cannot be overwritten; freeze referenced source question
post(S,'student/'+hw,{'answer['+str(qs[0]['id'])+']':'B','action':'save'});check(sql('SELECT answer FROM homework_answers WHERE id=%s',(aa[0]['id'],))[0]['answer']=='A','Submitted answer immutable')
post(T,'set.php?id='+str(D['set']),{'action':'delete_q','id':D['question']});check(bool(sql('SELECT 1 FROM questions WHERE id=%s',(D['question'],))),'Referenced MCQ cannot be deleted')
# Deadline is checked on the server, not client timer.
sql('UPDATE homework SET due_at=NOW()-INTERVAL 1 SECOND WHERE id=%s',(hid,));post(S,'student/'+hw,{'action':'save'});check(sql('SELECT answer FROM homework_answers WHERE id=%s',(aa[0]['id'],))[0]['answer']=='A','Late edit denied')
print(json.dumps({'passed':len(checks),'checks':checks},ensure_ascii=False,indent=2))
