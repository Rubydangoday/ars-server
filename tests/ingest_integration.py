"""Actual MariaDB test of ingest mapping, timeout, receive lock and duplicate rejection.
Run after http_smoke.py, passing that JSON path. MQTT transport/hardware not tested here.
"""
import sys,json
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1];sys.path.insert(0,str(ROOT/'ingest'));sys.path.insert(0,str(ROOT/'bridge'))
import blecodec
from ingest import Ingest,arscfg,state_cmds
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
p={'mac':mac,'type':1,'seq':9,'room':room,'id':5,'q':1,'ans':'B','via':'wifi'};i.process(p,json.dumps(p))
check(i.q('SELECT reason FROM rejected WHERE session_id=%s ORDER BY id DESC LIMIT 1',(sid,),True)['reason']=='wrong_link','Only nRF52840 Bluetooth packets accepted (via wifi rejected)')
i.db_state=i.db;check(i.build_state(room)['mode']=='ble','State mode is always Bluetooth')
# gói BLE thu bằng Bluetooth laptop (bridge/ble_scan.py): byte → JSON → ingest; lệnh trạng thái → beacon
raw=blecodec.encode_uplink(1,room,5,0,10,'',2950);bp=blecodec.decode_uplink(raw,mac.lower(),-60);bp['via']='ble'
check(bp=={'mac':mac,'room':room,'type':1,'id':5,'q':0,'seq':10,'ans':'','batt':2950,'rssi':-60,'via':'ble'},'BLE uplink bytes decode to gateway JSON')
check(blecodec.decode_uplink(blecodec.encode_uplink(2,room,5,3,11,'J'),mac)['ans']=='J' and blecodec.decode_uplink(b'\x00\x01',mac) is None,'BLE answer letter decoded, foreign data ignored')
n=i.stats['dup'];i.process(bp,json.dumps(bp));check(i.stats['dup']==n+1,'BLE login packet reaches ingest (already logged in = duplicate)')
st=i.build_state(room);fr=[blecodec.encode_cmd(c,room) for c in state_cmds(room,st)]
check([f[0] for f in fr]==[0x10,0x11,0x12,0x13] and len(fr[0][1])==18 and len(fr[1][1])==16 and len(fr[2][1])==11 and all(len(f[1])<=24 for f in fr),'State commands encode to BLE beacon frames (fit legacy advertising)')
check(blecodec.encode_cmd('ACKS 5:1,7:2',room)[1][3:]==bytes([2,5,1,7,2]),'ACKS beacon carries seat:question pairs')
print(json.dumps({'passed':len(checks),'checks':checks},ensure_ascii=False,indent=2))
