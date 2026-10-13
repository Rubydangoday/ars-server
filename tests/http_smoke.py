"""python3 tests/http_smoke.py; needs requests, beautifulsoup4, ARS_CONFIG and TEST_BASE.
Only run against disposable DB/server. Exercises actual PHP + MariaDB, not mocks.
"""
import os, subprocess, json, re, io, zipfile
from pathlib import Path
import requests
from bs4 import BeautifulSoup
ROOT=Path(__file__).resolve().parents[1]
BASE=os.environ.get('TEST_BASE','http://127.0.0.1:8091/')
d=json.loads(subprocess.check_output(['php',str(ROOT/'tests/seed.php')],env=os.environ))
checks=[]
def expect(test,msg):
    assert test,msg
    checks.append(msg)
def page(s,url):
    r=s.get(BASE+url);expect(r.status_code==200,'GET '+url)
    expect('Fatal error' not in r.text and 'Warning:' not in r.text,'No PHP errors '+url)
    return r
def post(s,url,data,files=None):
    r=page(s,'dashboard.php' if url.startswith('api/') else url);soup=BeautifulSoup(r.text,'html.parser');csrf=soup.select_one('input[name=csrf]') or soup.select_one('meta[name=csrf]')
    data={'csrf':csrf.get('value',csrf.get('content')) if csrf else '',**data}
    r=s.post(BASE+url,data=data,files=files);expect(r.status_code==200,'POST '+url);expect('Fatal error' not in r.text and 'Warning:' not in r.text,'No PHP errors POST '+url);return r
def login(i):
    s=requests.Session();post(s,'index.php',{'username':d['prefix']+'_'+str(i),'password':d['password']});expect(s.get(BASE+'dashboard.php').url.endswith('dashboard.php'),'Teacher login '+str(i));return s
t=login(1);other=login(2);outsider=login(3)
for url in ['set_print.php?id='+str(d['set']), 'homework.php','family.php','mapping.php','library.php','schools.php','analytics.php?class='+str(d['class']),'media.php?set='+str(d['set']),'variants.php?id='+str(d['set'])]:page(t,url)
expect(other.get(BASE+'analytics.php?class='+str(d['class'])).status_code==404,'Other teacher cannot access class')
expect(t.post(BASE+'homework.php',data={'action':'create'}).status_code==400,'CSRF required')
# School library membership, independent clone and variant answer remapping
post(t,'library.php',{'action':'share','set_id':d['set'],'visibility':'school'})
expect('Đề kiểm thử' in page(other,'library.php').text,'Same-school library visible')
expect('Đề kiểm thử' not in page(outsider,'library.php').text,'Other school cannot see set')
r=post(t,'variants.php?id='+str(d['set']),{'code':'101','questions':'on','options':'on'});expect('Tạo mã đề' in r.text,'Variant created')
word=t.get(BASE+'set_docx.php?id='+str(d['set'])+'&key=1');expect(zipfile.is_zipfile(io.BytesIO(word.content)),'Question DOCX export valid');export=t.get(BASE+'set_export.php?id='+str(d['set']));expect(zipfile.is_zipfile(io.BytesIO(export.content)),'Question XLSX export valid')
# Persistent device mapping bind and unbind
post(t,'mapping.php?class='+str(d['class']),{'seat':5,'mac':'AA:BB:CC:DD:EE:05','action':'bind'})
expect('AA:BB:CC:DD:EE:05' in page(t,'mapping.php?class='+str(d['class'])).text,'Mapping saved')
# Create 4 question types, publish
r=post(t,'homework.php',{'action':'create','class_id':d['class'],'title':'Bài tập bốn loại','instructions':'Làm bài ngắn.'});hid=int(re.search(r'id=(\d+)',r.url).group(1));url='homework.php?id='+str(hid)
for kind in ['mcq','text','drawing','audio']:
    post(t,url,{'action':'add','kind':kind,'question_id':d['question'],'content':'Trình bày '+kind,'topic':'Số học','rubric':'Nêu đúng một cộng một bằng hai.','max_score':'1'})
post(t,url,{'action':'publish'})
s=requests.Session();post(s,'student/index.php',{'code':d['code'],'password':d['password']})
# Verify student login rather than relying on a redirect alone.
expect('student/index.php' not in s.get(BASE+'student/home.php').url,'Student login')
r=page(s,'student/'+url);soup=BeautifulSoup(r.text,'html.parser');names=sorted(set(e.get('name') for e in soup.select('[name]') if (e.get('name') or '').startswith('answer[')),key=lambda name:int(re.search(r'\d+',name).group()));expect(len(names)==4,'All four homework question types rendered')
answers={name:('B' if i==0 else 'Một cộng một bằng hai') for i,name in enumerate(names)}
r=post(s,'student/'+url,{**answers,'action':'save'});expect('Một cộng một bằng hai' in r.text,'Homework draft saved')
post(s,'student/'+url,{**answers,'action':'submit'});r=page(s,'student/'+url);expect('Đã nộp' in r.text,'Homework submitted')
# Student cannot forge another class; teacher cannot modify peer submissions
expect(other.get(BASE+'homework_grade.php?id='+str(hid)).status_code==404,'Other teacher cannot grade homework')
grade='homework_grade.php?id='+str(hid);r=page(t,grade);soup=BeautifulSoup(r.text,'html.parser');forms=[f for f in soup.select('form') if f.select_one('[name=answer_id]')];expect(len(forms)==4,'Four answers for teacher review')
sub=None
for f in forms:
    aid=f.select_one('[name=answer_id]')['value'];sub=f.select_one('[name=submission]')['value'];post(t,grade,{'action':'grade','answer_id':aid,'submission':sub,'score':1,'feedback':'Làm tốt.'})
post(t,grade,{'action':'release','submission':sub});expect('Làm tốt.' in page(s,'student/'+url).text,'Official score visible after release')
# Bảng điểm bài tập về nhà: Excel + CSV (BOM UTF-8, ";"), giáo viên khác không xuất được
r=t.get(BASE+'homework_export.php?id='+str(hid));expect(zipfile.is_zipfile(io.BytesIO(r.content)) and 'Học sinh kiểm thử' in zipfile.ZipFile(io.BytesIO(r.content)).read('xl/worksheets/sheet1.xml').decode(),'Homework score XLSX lists the class')
import csv as _csv
r=t.get(BASE+'homework_export.php?id='+str(hid)+'&format=csv');rows=list(_csv.reader(io.StringIO(r.content.decode('utf-8-sig')),delimiter=';'))
row=next((x for x in rows if 'Học sinh kiểm thử' in x),None)
expect(r.content.startswith(b'\xef\xbb\xbf') and 'Thang 10' in rows[0] and row and row[3]=='Đã công bố' and row[rows[0].index('Thang 10')]=='10','Homework score CSV: BOM, header, released student row scored 10/10')
expect(other.get(BASE+'homework_export.php?id='+str(hid)).status_code==404,'Other teacher cannot export homework scores')
# Invalid AI offline must not publish a score
r=post(t,grade,{'action':'ai','answer_id':forms[1].select_one('[name=answer_id]')['value'],'submission':sub});expect('Ollama' in r.text,'Offline AI grading blocked clearly')
# Parent token one-use and class-scoped access
r=post(t,'family.php?class='+str(d['class']),{'action':'invite','student_code':d['code'],'class_id':d['class']});so=BeautifulSoup(r.text,'html.parser');token=so.select_one('.invite-once .io-code').text;expect(re.fullmatch(r'[A-Z2-9]{4}-[A-Z2-9]{4}',token) and so.select_one('.invite-once code.lb-url').text.endswith('/parent/index.php?invite='+token) and so.select_one('.invite-once [data-qr-inline]'),'Invite shows short code, prefilled link and QR')
p=requests.Session();post(p,'parent/index.php',{'action':'register','username':d['prefix']+'_parent','password':d['password'],'full_name':'Phụ huynh kiểm thử','invite':token});post(p,'parent/index.php',{'action':'login','username':d['prefix']+'_parent','password':d['password']});expect('Học sinh kiểm thử' in page(p,'parent/home.php').text,'Linked parent sees child progress')
p2=requests.Session();r=post(p2,'parent/index.php',{'action':'register','username':d['prefix']+'_parent2','password':d['password'],'full_name':'Khác','invite':token});expect('đã dùng' in r.text,'Parent invite cannot be reused')
# Grade workbook and projector state; correct hidden until teacher reveals
r=t.get(BASE+'analytics.php?class='+str(d['class'])+'&format=xlsx');expect(zipfile.is_zipfile(io.BytesIO(r.content)),'Analytics XLSX valid')
r=t.get(BASE+'analytics.php?class='+str(d['class'])+'&format=csv');expect(r.content.startswith(b'\xef\xbb\xbf') and 'Điểm' in next(_csv.reader(io.StringIO(r.content.decode('utf-8-sig')),delimiter=';')),'Analytics score CSV valid')
r=t.get(BASE+'export.php?id='+str(d['session'])+'&format=csv');expect(r.content.startswith(b'\xef\xbb\xbf') and 'Điểm (thang 10)' in next(_csv.reader(io.StringIO(r.content.decode('utf-8-sig')),delimiter=';')),'Session score CSV valid')
post(t,'api/action.php',{'session':d['session'],'question':d['question'],'action':'open'})
r=t.get(BASE+'api/state.php?id='+str(d['session'])+'&view=projector').json();expect('correct' not in r['current'],'Projector hides correct answer')
post(t,'projector.php?id='+str(d['session']),{'live':'on'});r=t.get(BASE+'api/state.php?id='+str(d['session'])+'&view=projector').json();expect('dist' in r['current'] and 'correct' not in r['current'],'Live bars do not expose answer key')
post(t,'api/action.php',{'session':d['session'],'action':'close'});r=t.get(BASE+'api/state.php?id='+str(d['session'])+'&view=projector').json();expect(r['current']['status']=='closed','Teacher can lock receiving answers')
print(json.dumps({'passed':len(checks),'checks':checks,'fixture':d},ensure_ascii=False,indent=2))
