"""Actual MariaDB test of ingest mapping, timeout, receive lock and duplicate rejection.
Run after http_smoke.py, passing that JSON path. MQTT transport/hardware not tested here.
"""
import sys,json
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1];sys.path.insert(0,str(ROOT/'ingest'));sys.path.insert(0,str(ROOT/'bridge'))
import blecodec
from ingest import Ingest,arscfg,state_cmds
import threading,ingest as ING
class FakeMQTT:
 def __init__(s):s.sent=[]
 def publish(s,topic,payload,qos=0,retain=False):s.sent.append((topic,payload))
cp=arscfg.load();i=Ingest.__new__(Ingest);i.db=arscfg.db_connect(cp);i.stats={'ok':0,'dup':0,'rej':0};i.recent_rej={};i.dirty=set();i.acks={};i.ack_exp={};i.lock=threading.Lock();i.client=FakeMQTT()
def acklines():return [p for t,p in i.client.sent if p.startswith('ACKS')]
d=json.load(open(sys.argv[1]))['fixture'];cid=d['class'];sid=d['session'];qid=d['question']
ses=i.q('SELECT * FROM sessions WHERE id=%s',(sid,),True);room=i.q('SELECT room_id FROM classes WHERE id=%s',(cid,),True)['room_id'];mac='AA:BB:CC:DD:EE:05';other='AA:BB:CC:DD:EE:06'
checks=[]
def check(x,msg):assert x,msg;checks.append(msg)
def packet(m,type,seq,ans='B'):
 p={'mac':m,'type':type,'seq':seq,'room':room,'id':5,'q':1,'ans':ans,'via':'ble'};i.process(p,json.dumps(p));return p
packet(other,1,1);check(i.q('SELECT reason FROM rejected WHERE session_id=%s ORDER BY id DESC LIMIT 1',(sid,),True)['reason']=='mapping_mismatch','Reject wrong mapped login')
check(i.acks[room]==[(5,0,1,0,ING.REJECT_STATUS['mapping_mismatch'])] and acklines()[-1]==f"ACKS 5:0:1:0:{ING.REJECT_STATUS['mapping_mismatch']}",'V3.11: rejected LOGIN gets an exact NACK record (seat, 0, seq, 0, status)')
packet(mac,1,2);check(bool(i.q('SELECT 1 FROM session_logins WHERE session_id=%s AND mac=%s',(sid,mac),True)),'Accept mapped login')
check(i.acks[room]==[(5,0,2,0,0)] and acklines()[-1]=='ACKS 5:0:2:0:0','V3.11: accepted LOGIN gets an exact ACK record for its own seq (ATTEND bit is not a confirmation)')
packet(mac,2,3);check(i.q('SELECT reason FROM rejected WHERE session_id=%s ORDER BY id DESC LIMIT 1',(sid,),True)['reason']=='q_closed','Reject while receiving locked')
i.x('UPDATE session_questions SET status="open",deadline=NOW(3)+INTERVAL 60 SECOND WHERE session_id=%s AND question_id=%s',(sid,qid))
packet(mac,2,4);check(i.q('SELECT COUNT(*) AS n FROM responses r JOIN session_questions sq ON sq.id=r.session_q_id WHERE sq.session_id=%s',(sid,),True)['n']==1,'Accept open question')
check(i.acks[room]==[(5,1,4,2,0)] and acklines()[-1]=='ACKS 5:1:4:2:0','Exact ACK (seat, question, seq, answer, status OK) published at once')
n_pub=len(acklines())
packet(mac,2,4);check(i.stats['dup']==1,'Duplicate answer counted once')
check(len(acklines())==n_pub and i.acks[room]==[(5,1,4,2,0)],'V3.10: duplicate re-arms the ACK without republishing (no BlueZ re-register / scan pause)')
i.x('UPDATE session_questions SET deadline=NOW(3)-INTERVAL 1 SECOND WHERE session_id=%s',(sid,));packet(mac,2,5);check(i.q('SELECT reason FROM rejected WHERE session_id=%s ORDER BY id DESC LIMIT 1',(sid,),True)['reason']=='late','Reject late answer')
check(i.acks[room]==[(5,1,5,2,ING.REJECT_STATUS['late'])],'Exact NACK replaces the seat\'s old ACK (status = late)')
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
check(i.acks[room]==[(5,0,10,0,0)],'V3.11: LOGIN again (retry / Remote reboot) is confirmed with its new seq and replaces the seat\'s old answer result')
i.x('UPDATE session_questions SET status="open",deadline=NOW(3)+INTERVAL 60 SECOND WHERE session_id=%s AND question_id=%s',(sid,qid))
i.x('UPDATE responses SET received_at=NOW(3)-INTERVAL 1 MINUTE WHERE mac=%s AND seq=4',(mac,))   # seq 4 dùng từ 1 phút trước
n_rows=i.q('SELECT COUNT(*) AS n FROM responses WHERE mac=%s',(mac,),True)['n'];packet(mac,2,4,'C')
check(i.q('SELECT reason FROM rejected WHERE session_id=%s ORDER BY id DESC LIMIT 1',(sid,),True)['reason']=='seq_reused'
      and i.acks[room][-1]==(5,1,4,3,ING.REJECT_STATUS['seq_reused']) and i.q('SELECT COUNT(*) AS n FROM responses WHERE mac=%s',(mac,),True)['n']==n_rows,
      'Seq reused after the dup window (8-bit wrap / reboot): exact NACK seq_reused instead of an unhandled uq_resp error and a silent timeout')
st=i.build_state(room);fr=[blecodec.encode_cmd(c,room) for c in state_cmds(room,st)]
check([f[0] for f in fr]==[0x10,0x11,0x12,0x13] and len(fr[0][1])==19 and len(fr[1][1])==16 and len(fr[2][1])==11 and all(len(f[1])<=24 for f in fr),'State commands encode to BLE beacon frames (STATE carries n_options, all fit legacy advertising)')
check(blecodec.encode_cmd('ACKS 5:1:7:2:0',room)[1][3:]==bytes([1,5,1,7,2,0]),'ACKS beacon carries exact records (seat, question, seq, answer, status)')
# V3.10: nhiều Remote cùng lúc (gọi thẳng remember_ack, không cần ghế thật trong CSDL)
R=200;i.acks[R]=[];i.ack_exp[R]={};i.client.sent=[]
for seat in range(1,7):i.remember_ack(R,seat,1,seat+10,'A')
line=acklines()[-1];frame=blecodec.encode_cmd(line,R)[1]
check(frame[3]==4 and frame[4+3*5]==6,'Six seats answering: the beacon keeps the 4 NEWEST records (V3.9 dropped the answer just made)')
n_pub=len(acklines());i.remember_ack(R,5,1,15,'A');i.remember_ack(R,6,1,16,'A')
check(len(acklines())==n_pub,'Retries of records still on air do not republish')
i.remember_ack(R,1,1,11,'A');check(i.acks[R][-1][0]==1 and len(acklines())==n_pub+1,'Retry of a record that fell out of the 4-record frame is put back on air')
import time as _t;now=_t.monotonic();i.ack_exp[R][4]=now-1;i.expire_acks(now)
check(all(x[0]!=4 for x in i.acks[R]) and 4 not in i.ack_exp[R] and acklines()[-1].count(':')==3*4,'Each seat\'s ACK expires on its own TTL')
for s_ in list(i.ack_exp[R]):i.ack_exp[R][s_]=now-1
i.expire_acks(now);check(i.acks[R]==[] and acklines()[-1]=='ACKS -','All results expired -> "ACKS -" returns airtime to STATE/ATTEND/OPENMASK')
i.remember_ack(R,9,1,3,'B');i.drop_ack(R,9);check(i.acks[R]==[] and acklines()[-1]=='ACKS -','LOGIN (Remote reboot) drops the seat\'s old result so a recycled seq cannot be confirmed')
print(json.dumps({'passed':len(checks),'checks':checks},ensure_ascii=False,indent=2))
