#pragma once
#include <cstdint>
#include <cstddef>
#include <vector>
#include <deque>
inline uint32_t fakeMillis=0;
inline uint32_t millis(){return fakeMillis;}
inline uint32_t micros(){return fakeMillis*1000;}
inline void delay(uint32_t n){fakeMillis+=n;}
inline void randomSeed(uint32_t){}
inline long random(long n){return n?1:0;}
#define HEX 16
struct SerialMock {
 void begin(int){}
 operator bool()const{return true;}
 template<class...T>void print(T...){}
 template<class...T>void println(T...){}
};
inline SerialMock Serial;
