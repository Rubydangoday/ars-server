#pragma once
#include "Arduino.h"
#define BLE_GAP_ADV_FLAGS_LE_ONLY_GENERAL_DISC_MODE 2
inline bool fakeConnected=false,fakeNotify=false,fakeWriteOk=true;
inline std::deque<uint8_t> fakeRx;
inline std::vector<std::vector<uint8_t>> fakeTx;
struct BLEUart {
 void begin(){}
 bool notifyEnabled(){return fakeNotify;}
 int available(){return (int)fakeRx.size();}
 int read(){if(fakeRx.empty())return -1;int b=fakeRx.front();fakeRx.pop_front();return b;}
 size_t write(const uint8_t* p,size_t n){if(!fakeWriteOk)return 0;fakeTx.emplace_back(p,p+n);return n;}
};
struct AdvertisingMock {
 void addFlags(int){}void addTxPower(){}void addService(BLEUart&){}void addName(){}
 void restartOnDisconnect(bool){}void setInterval(int,int){}void setFastTimeout(int){}void start(int){}
};
struct PeriphMock {
 void(*disconnect)(uint16_t,uint8_t)=nullptr;
 void setConnInterval(int,int){}
 void setDisconnectCallback(void(*cb)(uint16_t,uint8_t)){disconnect=cb;}
};
struct BluefruitMock {
 AdvertisingMock Advertising,ScanResponse;
 PeriphMock Periph;
 void begin(int,int){}void autoConnLed(bool){}void setName(const char*){}void setTxPower(int){}
 bool connected(){return fakeConnected;}
};
inline BluefruitMock Bluefruit;
