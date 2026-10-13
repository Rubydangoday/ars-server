#pragma once

#include <stdint.h>

namespace ArsBle {

static constexpr uint8_t TYPE_LOGIN  = 1;
static constexpr uint8_t TYPE_ANSWER = 2;
static constexpr uint8_t TYPE_PROBE  = 3;   // diagnostics only (tools/ble_probe.py uplink); ignored by ars-ble/ingest

// Start BLE scanning (advertising only during a TX burst).
// room/seat are used to filter server beacons for this Remote.
void begin(uint8_t room, uint8_t seat);

// Change room/seat after the student enters a new ID (filters beacons; clears stale events).
void setIdentity(uint8_t room, uint8_t seat);

// Remote -> gateway. One short non-connectable advertising burst (TX_BURST_MS). V3.11: the scanner stays on
// during the burst and the burst ends early once the exact result of THIS transaction is heard; the caller
// then reads it (takeLoginResult / takeAnswerAck) or keeps waiting for it (ACK_LISTEN window).
// answerCode: 0 for none, 1..10 for A..J. Returns false if the SoftDevice refused to start advertising.
bool sendPacket(uint8_t type,
                uint8_t room,
                uint8_t seat,
                uint8_t question,
                uint8_t sequence,
                uint8_t answerCode,
                uint16_t batteryMv);

// Server -> Remote events. These functions consume the event once.
// V3.11 exact LOGIN result: ACKS record (seat, 0, seq, 0, status). status 0 = logged in.
bool takeLoginResult(uint8_t& sequence, uint8_t& status);
bool takeAnswerAck(uint8_t& question, uint8_t& sequence, uint8_t& answerCode, uint8_t& status);

// Latest STATE beacon from server. Returns true only when a new STATE arrived.
bool takeStateUpdate(uint8_t& question, bool& open, uint8_t& nOptions);

// Latest OPENMASK beacon. Bit (q-1) tells whether question q is open.
bool takeOpenMask(uint64_t& mask);

// ---- Diagnostics (Serial 'd' / 'u', tools/ble_probe.py) ----
// Frames heard for this room since the last call, by type: [0]=STATE [1]=ATTEND [2]=OPENMASK [3]=ACKS [4]=PROBE.
// lastProbePhase = phase byte of the last PROBE frame; avgRssi = mean RSSI of counted frames (0 if none).
void diagSnapshot(uint32_t counts[5], uint8_t& lastProbePhase, int16_t& avgRssi);

} // namespace ArsBle
