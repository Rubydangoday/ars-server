#include "ars_ble.h"
#include <Arduino.h>
#include <bluefruit.h>
#include <string.h>
namespace ArsBle {
// NUS stream framing: one length byte, then 2..32 payload bytes beginning A5.
// Every packet in single-remote mode fits a default 20-byte ATT payload.
static BLEUart uart;
static uint8_t localRoom=1,localSeat=0,peerRoom=0;
static bool bridgeReady=false;
static volatile bool resetPending=false;
static uint32_t lastHello=0,lastByte=0;
static uint8_t buffer[32],expected=0,used=0;
static bool loginEvent=false,answerEvent=false,stateEvent=false,maskEvent=false;
static uint8_t loginSeq=0,loginStatus=0,answerQ=0,answerSeq=0,answerCode=0,answerStatus=0;
static uint8_t stateQ=0,stateOptions=0;
static bool stateOpen=false;
static uint64_t openMask=0;
static void clearEvents() {loginEvent=answerEvent=stateEvent=maskEvent=false;}
static void disconnected(uint16_t, uint8_t) {resetPending=true;}
static bool sendFrame(const uint8_t* data,uint8_t len) {
    if(!Bluefruit.connected() || !uart.notifyEnabled() || len<2 || len>19) return false;
    uint8_t frame[20];frame[0]=len;memcpy(frame+1,data,len);
    return uart.write(frame,len+1)==(size_t)(len+1);
}
static void receiveFrame(const uint8_t* data,uint8_t len) {
    if(len<2 || data[0]!=0xA5) return;
    if(data[1]==0x22 && len==6) { // PING probe: never authorizes LOGIN.
        uint8_t pong[6];memcpy(pong,data,6);pong[1]=0x23;sendFrame(pong,6);return;
    }
    if(data[1]==0x20 && len==3) { // HELLO after central subscribed to Notify.
        peerRoom=data[2];lastHello=millis();bridgeReady=(peerRoom==localRoom);
        uint8_t reply[]={0xA5,0x21,localRoom,localSeat};sendFrame(reply,4);return;
    }
    if(!ready() || len<3 || data[2]!=localRoom) return;
    if(data[1]==0x14 && len==6 && data[3]==localSeat) {
        loginSeq=data[4];loginStatus=data[5];loginEvent=true;
    } else if(data[1]==0x13 && len==9 && data[3]==1 && data[4]==localSeat) {
        answerQ=data[5];answerSeq=data[6];answerCode=data[7];answerStatus=data[8];answerEvent=true;
    } else if(data[1]==0x10 && len==19) {
        stateQ=data[3];stateOpen=data[4]!=0;stateOptions=data[5]<=10?data[5]:0;stateEvent=true;
    } else if(data[1]==0x12 && len==11) {
        openMask=0;for(uint8_t i=0;i<8;i++) openMask|=(uint64_t)data[3+i]<<(8*i);maskEvent=true;
    }
    // ATTEND is only a state snapshot; never an exact LOGIN acknowledgement.
}
void begin(uint8_t room,uint8_t seat) {
    localRoom=room;localSeat=seat;
    Bluefruit.begin(1,0);
    Bluefruit.autoConnLed(false); // Do not let BSP LED pin conflict with the populated PCB.
    Bluefruit.setName("ARS_REMOTE_01");Bluefruit.setTxPower(0);
    Bluefruit.Periph.setConnInterval(12,24); // request 15..30 ms; central may choose otherwise
    Bluefruit.Periph.setDisconnectCallback(disconnected);
    uart.begin();
    Bluefruit.Advertising.addFlags(BLE_GAP_ADV_FLAGS_LE_ONLY_GENERAL_DISC_MODE);
    Bluefruit.Advertising.addTxPower();
    Bluefruit.Advertising.addService(uart);
    Bluefruit.ScanResponse.addName();
    Bluefruit.Advertising.restartOnDisconnect(true);
    Bluefruit.Advertising.setInterval(32,160);
    Bluefruit.Advertising.setFastTimeout(30);
    Bluefruit.Advertising.start(0);
}
bool ready() {
    return bridgeReady && Bluefruit.connected() && uart.notifyEnabled() &&
           (uint32_t)(millis()-lastHello)<8000;
}
void setIdentity(uint8_t room,uint8_t seat) {
    localRoom=room;localSeat=seat;clearEvents();
    bridgeReady=(peerRoom==localRoom && (uint32_t)(millis()-lastHello)<8000);
}
void update() {
    if(resetPending) {
        resetPending=false;bridgeReady=false;peerRoom=0;expected=used=0;clearEvents();
        while(uart.available()) uart.read();
    }
    if(expected && (uint32_t)(millis()-lastByte)>1000) expected=used=0;
    // Poll in the main loop: no blocking RX callback or UI mutation from a BLE task.
    uint16_t budget=128;
    while(budget-- && uart.available()) {
        int b=uart.read();if(b<0) break;lastByte=millis();
        if(!expected) {if(b>=2 && b<=32) {expected=(uint8_t)b;used=0;}continue;}
        buffer[used++]=(uint8_t)b;
        if(used==expected) {receiveFrame(buffer,used);expected=used=0;}
    }
}
bool sendPacket(uint8_t type,uint8_t room,uint8_t seat,uint8_t q,uint8_t seq,uint8_t ans,uint16_t batt) {
    if(!ready()) return false;
    uint8_t data[]={0xA5,type,room,seat,q,seq,ans,(uint8_t)batt,(uint8_t)(batt>>8)};
    return sendFrame(data,sizeof(data));
}
bool takeLoginAck(uint8_t& seq,uint8_t& status) {
    if(!loginEvent) return false;
    seq=loginSeq;status=loginStatus;loginEvent=false;return true;
}
bool takeAnswerAck(uint8_t& q,uint8_t& seq,uint8_t& ans,uint8_t& status) {
    if(!answerEvent) return false;
    q=answerQ;seq=answerSeq;ans=answerCode;status=answerStatus;answerEvent=false;return true;
}
bool takeStateUpdate(uint8_t& q,bool& open,uint8_t& options) {
    if(!stateEvent) return false;
    q=stateQ;open=stateOpen;options=stateOptions;stateEvent=false;return true;
}
bool takeOpenMask(uint64_t& mask) {if(!maskEvent)return false;mask=openMask;maskEvent=false;return true;}
}
