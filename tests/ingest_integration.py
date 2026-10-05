"""Actual MariaDB test of ingest mapping, timeout, receive lock and duplicate rejection.
Run after http_smoke.py, passing that JSON path. MQTT transport/hardware not tested here.
"""
import sys,json
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1];sys.path.insert(0,str(ROOT/'ingest'))
from ingest import Ingest,arscfg
cp=arscfg.load();i=Ingest.__new__(Ingest);i.db=arscfg.db_connect(cp);i.stats={'ok':0,'dup':0,'rej':0};i.recent_rej={};i.dirty=set();i.acks={}
d=json.load(open(sys.argv[1]))['fixture'];cid=d['class'];sid=d['session'];qid=d['question']
ses=i.q('SELECT * FROM sessions WHERE id=%s',(sid,),True);room=i.q('SELECT room_id FROM classes WHERE id=%s',(cid,),True)['room_id'];mac='AA:BB:CC:DD:EE:05';other='AA:BB:CC:DD:EE:06'
checks=[]
def check(x,msg):assert x,msg;checks.append(msg)
def packet(m,type,seq,ans='B'):
 p={'mac':m,'type':type,'seq':seq,'room':room,'id':5,'q':1,'ans':ans,'via':'ble'};i.process(p,json.dumps(p));return p
packet(other,1,1);check(i.q('SELECT reason FROM rejected WHERE session_id=%s ORDER BY id DESC LIMIT 1',(sid,),True)['reason']=='mapping_mismatch','Reject wrong mapped login')
packet(mac,1,2);check(bool(i.q('SELECT 1 FROM session_logins WHERE session_id=%s AND mac=%s',(sid,mac),True)),'Accept mapped login')
packet(mac,2,3);check(i.q('SELECT reason FROM rejected WHERE session_id=%s ORDER BY id DESC LIMIT 1',(sid,),True)['reason']=='q_closed','Reject while receiving locked')
i.x('UPDATE session_questions SET status="open",deadline=NOW(3)+INTERVAL 60 SECOND WHERE session_id=%s AND question_id=%s',(sid,qid))
packet(mac,2,4);check(i.q('SELECT COUNT(*) AS n FROM responses r JOIN session_questions sq ON sq.id=r.session_q_id WHERE sq.session_id=%s',(sid,),True)['n']==1,'Accept open question')
packet(mac,2,4);check(i.stats['dup']==1,'Duplicate answer counted once')
i.x('UPDATE session_questions SET deadline=NOW(3)-INTERVAL 1 SECOND WHERE session_id=%s',(sid,));packet(mac,2,5);check(i.q('SELECT reason FROM rejected WHERE session_id=%s ORDER BY id DESC LIMIT 1',(sid,),True)['reason']=='late','Reject late answer')
i.x('UPDATE device_mappings SET mac=%s WHERE class_id=%s AND seat_no=5',(other,cid));packet(mac,2,6);check(i.q('SELECT reason FROM rejected WHERE session_id=%s ORDER BY id DESC LIMIT 1',(sid,),True)['reason']=='mapping_mismatch','Reject stale mapping after change')
i.x('UPDATE device_mappings SET mac=%s WHERE class_id=%s AND seat_no=5',(mac,cid))
print(json.dumps({'passed':len(checks),'checks':checks},ensure_ascii=False,indent=2))
