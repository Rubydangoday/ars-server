#pragma once

#include <stdint.h>

namespace ArsBle {

static constexpr uint8_t TYPE_LOGIN  = 1;
static constexpr uint8_t TYPE_ANSWER = 2;

// Start BLE scanning (advertising only during a TX burst).
// room/seat are used to filter server beacons for this Remote.
void begin(uint8_t room, uint8_t seat);

// Change room/seat after the student enters a new ID (filters beacons; clears stale events).
void setIdentity(uint8_t room, uint8_t seat);

// Remote -> laptop. V3.10: one short non-connectable advertising burst (TX_BURST_MS, scanner off),
// then the scanner is restarted and stays on; the caller waits for the exact ACK (ACK_LISTEN window).
// answerCode: 0 for none, 1..10 for A..J.
void sendPacket(uint8_t type,
                uint8_t room,
                uint8_t seat,
                uint8_t question,
                uint8_t sequence,
                uint8_t answerCode,
                uint16_t batteryMv);

// Server -> Remote events. These functions consume the event once.
bool takeLoginAck();
bool takeAnswerAck(uint8_t& question, uint8_t& sequence, uint8_t& answerCode, uint8_t& status);

// Latest STATE beacon from server. Returns true only when a new STATE arrived.
bool takeStateUpdate(uint8_t& question, bool& open, uint8_t& nOptions);

// Latest OPENMASK beacon. Bit (q-1) tells whether question q is open.
bool takeOpenMask(uint64_t& mask);

} // namespace ArsBle
