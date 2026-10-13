#pragma once
#include <stdint.h>
namespace ArsBle {
static constexpr uint8_t TYPE_LOGIN=1, TYPE_ANSWER=2;
void begin(uint8_t room,uint8_t seat);
void update();
bool ready();
void setIdentity(uint8_t room,uint8_t seat);
bool sendPacket(uint8_t type,uint8_t room,uint8_t seat,uint8_t question,
                uint8_t sequence,uint8_t answerCode,uint16_t batteryMv);
bool takeLoginAck(uint8_t& sequence,uint8_t& status);
bool takeAnswerAck(uint8_t& question,uint8_t& sequence,uint8_t& answerCode,uint8_t& status);
bool takeStateUpdate(uint8_t& question,bool& open,uint8_t& nOptions);
bool takeOpenMask(uint64_t& mask);
}
