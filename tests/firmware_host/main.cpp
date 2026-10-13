// Test the real application/radio/display code using host-only hardware mocks.
#include <cassert>
#include <iostream>
#include <bluefruit.h>
#include "../../firmware/ARS_remote_gatt/ARS_remote_gatt.ino"
void keypadBegin(){}
KeyId keypadGetPressedEvent(){return KEY_NONE;}
static void rx(std::initializer_list<uint8_t> data) {
 fakeRx.push_back((uint8_t)data.size());for(auto b:data)fakeRx.push_back(b);ArsBle::update();
}
static std::pair<uint8_t,uint8_t> segments() {
 uint8_t left=0,right=0;
 const uint32_t pins[]={BoardPins::SEG_A,BoardPins::SEG_B,BoardPins::SEG_C,BoardPins::SEG_D,BoardPins::SEG_E,BoardPins::SEG_F,BoardPins::SEG_G};
 for(int digit=0;digit<2;digit++) {
  fakeMillis+=2;displayUpdate();uint8_t bits=0;
  for(int j=0;j<7;j++)if(!gpioHigh[pins[j]])bits|=1u<<j;
  if(!gpioHigh[BoardPins::DIGIT_1])left=bits;else right=bits;
 }
 return {left,right};
}
int main() {
 fakeMillis=100;
 displayBegin();statusLedBegin();gRoom=1;gSeat=2;
 ArsBle::begin(gRoom,gSeat);beginLogin();
 assert(pending.kind==PENDING_NONE && loginWanted && fakeTx.empty());
 fakeConnected=fakeNotify=true;
 rx({0xA5,0x22,1,2,3,4}); // probe must NOT authorize real LOGIN
 assert(fakeTx.back()==std::vector<uint8_t>({6,0xA5,0x23,1,2,3,4}));
 assert(!ArsBle::ready());
 rx({0xA5,0x20,1});processConnection();
 assert(ArsBle::ready() && pending.kind==PENDING_LOGIN);
 uint8_t loginSequence=pending.seq;
 rx({0xA5,0x11,1,0xff,0xff,0xff,0xff,0xff,0xff,0xff,0xff,0xff,0xff,0xff,0xff,0xff});
 processDownlink();assert(!loggedIn); // stale attendance bitmap is not a login ACK
 rx({0xA5,0x14,1,2,(uint8_t)(loginSequence+1),0});processDownlink();assert(!loggedIn);
 rx({0xA5,0x14,1,2,loginSequence,0});processDownlink();assert(loggedIn && pending.kind==PENDING_NONE);
 selectedAnswer=3;beginAnswer();uint8_t answerSequence=pending.seq;
 assert(pending.kind==PENDING_ANSWER);
 rx({0xA5,0x13,1,1,2,1,answerSequence,2,0});processDownlink();assert(pending.kind==PENDING_ANSWER);
 // Backend not responding: retry exactly the same sequence and answer.
 fakeMillis=pending.deadlineMs;processRetry();fakeMillis=retryBackoffUntilMs;processRetry();
 assert(pending.seq==answerSequence && pending.answer==3 && pending.attempts==2);
 rx({0xA5,0x13,1,1,2,1,answerSequence,3,0});processDownlink();assert(pending.kind==PENDING_NONE && selectedAnswer==0);
 // Loss during answer must never automatically replay it after reconnection.
 selectedAnswer=2;beginAnswer();fakeConnected=false;Bluefruit.Periph.disconnect(0,0);ArsBle::update();processConnection();
 assert(pending.kind==PENDING_NONE && !loggedIn && selectedAnswer==0);
 fakeConnected=true;rx({0xA5,0x20,1});processConnection();assert(pending.kind==PENDING_LOGIN);
 // Modes have physically different segment patterns, even for the same value.
 displaySetMode(DISP_ROOM,2);auto room=segments();
 displaySetMode(DISP_SEAT,2);auto seat=segments();
 displaySetMode(DISP_QUESTION,2);auto question=segments();
 displaySetMode(DISP_ANSWER,2);auto answer=segments();
 assert(room!=seat && room!=question && room!=answer && seat!=question && seat!=answer && question!=answer);
 fakeMillis+=450;auto numeric=segments();assert(numeric.first==0x3f && numeric.second==0x5b);
 // Editing remains visible while Bluetooth is disconnected.
 fakeConnected=false;uiMode=UI_ENTER_ROOM;entryValue=1;renderUi();auto edit=segments();assert(edit.first==0x50);
 // HELLO heartbeat expires; a connected radio alone is not application-ready.
 fakeConnected=true;fakeMillis+=9000;assert(!ArsBle::ready());
 std::cout<<"Firmware host scenarios passed: framing, HELLO, exact ACK/NACK matching, retry, disconnect, mode segments, heartbeat.\n";
}
