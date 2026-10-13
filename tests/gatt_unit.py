#!/usr/bin/env python3
"""Chế độ 1 nRF (BLE GATT, bridge/ble_gatt.py) — test của bản V3.11 GATT (nhóm), chạy trên ingest của repo.
Chạy: python3 tests/gatt_unit.py [smoke.json bỏ qua]  — in JSON {"passed": n} như các test khác."""
import asyncio
import json
import sys
import unittest
from pathlib import Path
from types import SimpleNamespace
ROOT = Path(__file__).resolve().parents[1]
sys.path[:0] = [str(ROOT/'bridge'), str(ROOT/'common'), str(ROOT/'ingest')]
import blecodec
import gatt_protocol as gp
import ble_gatt
from ingest import Ingest


def packet(ptype=2, seq=7):
    return blecodec.encode_uplink(ptype,1,2,1 if ptype==2 else 0,seq,'C' if ptype==2 else '')


def result_for(p, status=0):
    return {**{k:p[k] for k in ('room','mac','type','id','q','seq','ans','gatt_request')}, 'status':status}


class ProtocolTests(unittest.TestCase):
    def test_fragmented_and_coalesced_stream(self):
        d=gp.Decoder(); encoded=gp.frame(packet())+gp.frame(bytes([0xA5,0x20,1]))
        self.assertEqual(d.feed(encoded[:3]),[])
        self.assertEqual(d.feed(encoded[3:]),[packet(),bytes([0xA5,0x20,1])])

    def test_bad_frames_fail_closed(self):
        for raw in (b'\x00', b'\xff', b'\x02\x00\x01'):
            with self.assertRaises(ValueError):gp.Decoder().feed(raw)

    def test_retry_uses_same_request_token(self):
        t=gp.Transactions(1,'aa:bb:cc:dd:ee:ff')
        p=t.request(packet(),0);retry=t.request(packet(),1)
        self.assertEqual(p['gatt_request'],retry['gatt_request'])
        self.assertNotEqual(p['gatt_request'],t.request(packet(seq=8),2)['gatt_request'])

    def test_wrong_identity_and_stale_results_rejected(self):
        t=gp.Transactions(1,'AA:BB:CC:DD:EE:FF');p=t.request(packet(),0)
        good=result_for(p)
        for key,value in [('seq',8),('id',3),('q',2),('ans','A'),('room',2),('mac','OTHER'),('type',1),('gatt_request','old')]:
            self.assertIsNone(t.result({**good,key:value},1),key)
        self.assertIsNone(t.result(good,10))
        self.assertIsNone(gp.Transactions(1,p['mac']).result(good,1))

    def test_exact_login_answer_and_nack(self):
        t=gp.Transactions(1,'AA:BB:CC:DD:EE:FF')
        p=t.request(packet(),0)
        self.assertEqual(t.result(result_for(p),1),bytes([0xA5,0x13,1,1,2,1,7,3,0]))
        login=t.request(packet(1),0)
        self.assertEqual(t.result(result_for(login,8),1),bytes([0xA5,0x14,1,2,7,8]))

    def test_malformed_uplink_not_forwarded(self):
        t=gp.Transactions(1,'AA:BB:CC:DD:EE:FF')
        for data in [packet()[:-1],blecodec.encode_uplink(2,2,2,1,7,'A'),blecodec.encode_uplink(2,1,0,1,7,'A')]:
            with self.assertRaises(ValueError):t.request(data)

    def test_all_single_remote_frames_fit_default_mtu(self):
        payloads=[packet(),bytes([0xA5,0x14,1,2,7,0])]
        for cmd in ['STATE 1 1 1 4 00','OPENMASK 01','ACKS 2:1:7:3:0']:
            payloads.append(blecodec.encode_cmd(cmd,1)[1])
        for payload in payloads:self.assertLessEqual(len(gp.frame(payload)),20)


class FakeMQTT:
    def __init__(self):self.sent=[]
    def publish(self,topic,payload,**kw):self.sent.append((topic,payload,kw));return SimpleNamespace(rc=0)


class BridgeTests(unittest.IsolatedAsyncioTestCase):
    async def test_old_state_cannot_ack_login_or_answer(self):
        link=ble_gatt.Link({},1);link.ready=True
        link.on_mqtt('ars/1/state',json.dumps({'cmds':['ATTEND 04','ACKS 2:1:7:3:0','STATE 1 1 1 4 00']}).encode(),True)
        self.assertEqual(link.queue.qsize(),1)
        self.assertEqual((await link.queue.get())[2][1],0x10)

    async def test_retained_and_mismatching_result_ignored(self):
        link=ble_gatt.Link({},1);link.ready=True;link.txns=gp.Transactions(1,'AA:BB:CC:DD:EE:FF')
        pkt=link.txns.request(packet());good=json.dumps(result_for(pkt)).encode()
        link.on_mqtt('ars/1/result/gatt',good,True)
        link.on_mqtt('ars/1/result/gatt',json.dumps({**result_for(pkt),'seq':99}).encode(),False)
        self.assertEqual(link.queue.qsize(),0)
        link.on_mqtt('ars/1/result/gatt',good,False)
        self.assertEqual((await link.queue.get())[2],bytes([0xA5,0x13,1,1,2,1,7,3,0]))

    async def test_notify_forwards_only_after_hello(self):
        link=ble_gatt.Link({},1);link.mqtt=FakeMQTT();link.mqtt_ready.set()
        link.txns=gp.Transactions(1,'AA:BB:CC:DD:EE:FF')
        link.on_notify(None,gp.frame(packet()))
        self.assertEqual(link.mqtt.sent,[])
        link.on_notify(None,gp.frame(bytes([0xA5,0x21,1,2]))+gp.frame(packet()))
        self.assertEqual(len(link.mqtt.sent),1)
        self.assertEqual(json.loads(link.mqtt.sent[0][1])['transport'],'gatt')

    async def test_wrong_room_fails_connection(self):
        link=ble_gatt.Link({},1)
        link.on_notify(None,gp.frame(bytes([0xA5,0x21,2,2])))
        self.assertTrue(link.lost.is_set());self.assertFalse(link.hello.is_set())

    async def test_write_requires_att_response_and_serializes(self):
        link=ble_gatt.Link({},1)
        calls=[]
        class Client:
            async def write_gatt_char(self,uuid,data,response):calls.append((uuid,data,response))
        link.client=Client();await link.write(packet())
        self.assertEqual(calls,[(gp.RX_UUID,gp.frame(packet()),True)])

    async def test_offline_status_when_ble_not_ready(self):
        link=ble_gatt.Link({},1);link.mqtt=FakeMQTT();link.mqtt_ready.set()
        link.client=SimpleNamespace(is_connected=True)
        link.status();self.assertFalse(json.loads(link.mqtt.sent[-1][1])['online'])
        link.ready=True;link.status();self.assertTrue(json.loads(link.mqtt.sent[-1][1])['online'])


class BackendTests(unittest.TestCase):
    def make_ingest(self):
        i=Ingest.__new__(Ingest);i.client=FakeMQTT();i.stats={'ok':0,'dup':0,'rej':0};i.dirty=set();i.recent_rej={}
        i.remember_ack=lambda *a:None;i.drop_ack=lambda *a:None;i.dup=lambda *a:None
        i.remember_login=lambda *a:None   # repo: LOGIN cũng có bản ghi ACKS cho gateway USB (không liên quan GATT)
        i.remember_reject_result=lambda *a:None;i.x=lambda *a:None
        return i

    def test_legacy_packets_do_not_create_gatt_results(self):
        i=self.make_ingest();i.publish_gatt_result({'type':2});self.assertEqual(i.client.sent,[])

    def test_exact_result_not_retained(self):
        i=self.make_ingest();p=gp.Transactions(1,'AA:BB:CC:DD:EE:FF').request(packet(1))
        i.publish_gatt_result(p);topic,payload,kw=i.client.sent[0]
        self.assertEqual(topic,'ars/1/result/gatt');self.assertFalse(kw['retain'])
        self.assertEqual(json.loads(payload)['gatt_request'],p['gatt_request'])

    def test_login_success_only_after_db_writes(self):
        i=self.make_ingest();p=gp.Transactions(1,'AA:BB:CC:DD:EE:FF').request(packet(1))
        answers=iter([{'seat_no':2},None,None,None,None]);i.q=lambda *a,**kw:next(answers)
        writes=[]
        def write(sql,args):
            self.assertEqual(i.client.sent,[]);writes.append(sql)
        i.x=write;i.login({'id':1,'class_id':1},1,p['mac'],2,p,'{}')
        self.assertTrue(any('INSERT INTO session_logins' in s for s in writes))
        self.assertEqual(json.loads(i.client.sent[-1][1])['status'],0)

    def test_db_failure_cannot_emit_success(self):
        i=self.make_ingest();p=gp.Transactions(1,'AA:BB:CC:DD:EE:FF').request(packet())
        answers=iter([None,None,{'seat_no':2},{'id':1,'position':1,'status':'open','late':False,'n_options':4},None])
        i.q=lambda *a,**kw:next(answers)
        def fail(*a):raise RuntimeError('DB unavailable')
        i.x=fail
        with self.assertRaises(RuntimeError):i.answer({'id':1,'class_id':1,'set_id':1,'allow_change':1},1,p['mac'],2,1,7,'C',None,p,'{}')
        self.assertEqual(i.client.sent,[])

    def test_answer_success_and_duplicate_emit_result(self):
        for duplicate in (False,True):
            i=self.make_ingest();p=gp.Transactions(1,'AA:BB:CC:DD:EE:FF').request(packet())
            answers=iter([None,None,{'seat_no':2},{'id':1,'position':1,'status':'open','late':False,'n_options':4},{'answer':'C'} if duplicate else None])
            i.q=lambda *a,**kw:next(answers)
            writes=[];i.x=lambda *a:writes.append(a[0])
            i.answer({'id':1,'class_id':1,'set_id':1,'allow_change':1},1,p['mac'],2,1,7,'C',None,p,'{}')
            self.assertEqual(sum('INSERT INTO responses' in s for s in writes),0 if duplicate else 1)
            self.assertEqual(json.loads(i.client.sent[-1][1])['status'],0)

    def test_login_rejection_is_exact_nack(self):
        i=self.make_ingest();p=gp.Transactions(1,'AA:BB:CC:DD:EE:FF').request(packet(1))
        i.reject(1,1,p,'unknown_seat','{}')
        self.assertEqual(json.loads(i.client.sent[-1][1])['status'],8)


if __name__=='__main__':
    res=unittest.TextTestRunner(stream=sys.stderr,verbosity=1).run(unittest.defaultTestLoader.loadTestsFromModule(sys.modules[__name__]))
    if not res.wasSuccessful():
        sys.exit(1)
    print(json.dumps({'passed': res.testsRun}))
