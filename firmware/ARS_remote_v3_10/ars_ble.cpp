#include "ars_ble.h"
#include "display.h"
#include "status_led.h"

#include <Arduino.h>
#include <bluefruit.h>
#include <string.h>

namespace ArsBle {

static constexpr uint16_t COMPANY_ID = 0xFFFF;
static constexpr uint8_t MAGIC = 0xA5;

// Server downlink frame types from bridge/blecodec.py.
static constexpr uint8_t FRAME_STATE    = 0x10;
static constexpr uint8_t FRAME_ATTEND   = 0x11;
static constexpr uint8_t FRAME_OPENMASK = 0x12;
static constexpr uint8_t FRAME_ACKS     = 0x13;

// V3.10 radio schedule: ONE short TX burst, then one long uninterrupted RX window (in the .ino).
// V3.9 switched TX/RX six times per attempt and listened only 3 x 120 ms, partly while the laptop
// was still stopping its scan / registering the ACK (~40-50 ms DB+MQTT latency). A single burst
// followed by ~1 s of continuous scanning covers the whole ACK window with fewer mode switches.
// 150 ms at a 25 ms interval = ~6 advertising events x 3 channels.
static constexpr uint16_t ADV_INTERVAL_UNITS = 40; // 25 ms
static constexpr uint32_t TX_BURST_MS = 150;

// Scan continuously when the SoftDevice radio scheduler allows it.
// 160 * 0.625 ms = 100 ms interval/window. This reduces downlink ACK latency.
static constexpr uint16_t SCAN_INTERVAL_UNITS = 160;
static constexpr uint16_t SCAN_WINDOW_UNITS   = 160;

static volatile uint8_t localRoom = 0;
static volatile uint8_t localSeat = 0;

// Events are written by the BLE task (scanCallback) and read by loop(): copy multi-field events
// inside a critical section so loop() never sees a half-updated ACK (V3.9 read them unguarded).
#define ARS_LOCK()   taskENTER_CRITICAL()
#define ARS_UNLOCK() taskEXIT_CRITICAL()

// Events written by BLE callback and consumed in loop().
static volatile bool loginAckPending = false;
static volatile bool answerAckPending = false;
static volatile uint8_t answerAckQuestion = 0;
static volatile uint8_t answerAckSequence = 0;
static volatile uint8_t answerAckCode = 0;
static volatile uint8_t answerAckStatus = 0;

static volatile bool statePending = false;
static volatile uint8_t stateQuestion = 0;
static volatile bool stateOpen = false;
static volatile uint8_t stateNOptions = 0;

static volatile bool openMaskPending = false;
static volatile uint64_t latestOpenMask = 0;

static bool seatBitIsSet(const uint8_t* bitmap, size_t len, uint8_t seat) {
    const uint8_t byteIndex = static_cast<uint8_t>(seat / 8);
    const uint8_t bitIndex  = static_cast<uint8_t>(seat % 8);
    if (byteIndex >= len) return false;
    return (bitmap[byteIndex] & static_cast<uint8_t>(1u << bitIndex)) != 0;
}

static void scanCallback(ble_gap_evt_adv_report_t* report) {
    uint8_t msd[32] = {0};
    const uint8_t len = Bluefruit.Scanner.parseReportByType(
        report,
        BLE_GAP_AD_TYPE_MANUFACTURER_SPECIFIC_DATA,
        msd,
        sizeof(msd)
    );

    if (len < 2) {
        Bluefruit.Scanner.resume();
        return;
    }

    // Bluefruit returns Manufacturer Specific Data including the 2-byte company ID.
    const uint16_t company = static_cast<uint16_t>(msd[0]) |
                             (static_cast<uint16_t>(msd[1]) << 8);
    if (company != COMPANY_ID) {
        Bluefruit.Scanner.resume();
        return;
    }

    const uint8_t* data = &msd[2];
    const uint8_t dlen = static_cast<uint8_t>(len - 2);
    if (dlen < 3 || data[0] != MAGIC || data[2] != localRoom) {
        Bluefruit.Scanner.resume();
        return;
    }

    const uint8_t frame = data[1];

    if (frame == FRAME_ATTEND) {
        // [A5, 11, room] + 13-byte attendance bitmap
        if (dlen >= 16 && seatBitIsSet(&data[3], 13, localSeat)) {
            loginAckPending = true;
        }
    }
    else if (frame == FRAME_STATE) {
        // V3.8: [A5,10,room,qid,open,n_options] + 13-byte answered bitmap.
        // V3.7 frames had no n_options; accept them with n_options=0 so local
        // validation is disabled rather than rejecting a valid answer.
        if (dlen >= 18) {
            const uint8_t qid = data[3];
            const bool op = data[4] != 0;
            const uint8_t nOptions = (dlen >= 19) ? data[5] : 0;

            ARS_LOCK();
            stateQuestion = qid;
            stateOpen = op;
            stateNOptions = (nOptions <= 10) ? nOptions : 0;
            statePending = true;
            ARS_UNLOCK();
        }
    }
    else if (frame == FRAME_OPENMASK) {
        // [A5, 12, room] + 8-byte little-endian mask, question q => bit q-1.
        if (dlen >= 11) {
            uint64_t mask = 0;
            for (uint8_t i = 0; i < 8; ++i) {
                mask |= static_cast<uint64_t>(data[3 + i]) << (8 * i);
            }
            ARS_LOCK();
            latestOpenMask = mask;
            openMaskPending = true;
            ARS_UNLOCK();
        }
    }
    else if (frame == FRAME_ACKS) {
        // V3.7 exact result format:
        // [A5, 13, room, n] + n records
        //   (seat, question, seq, answerCode, status), n <= 4.
        // status=0 is accepted; non-zero is a server NACK. STATE is deliberately
        // NOT treated as an ANSWER result.
        if (dlen >= 4) {
            uint8_t n = data[3];
            if (n > 4) n = 4;

            // V3.7 uses 5-byte records. A legacy V3.5 retained frame may still
            // contain 4-byte records; accept it as status=0 during transition.
            uint8_t recordSize = 5;
            if (n > 0 && dlen < static_cast<uint8_t>(4 + n * 5) &&
                dlen >= static_cast<uint8_t>(4 + n * 4)) {
                recordSize = 4;
            }
            const uint8_t maxRecords = static_cast<uint8_t>((dlen - 4) / recordSize);
            if (n > maxRecords) n = maxRecords;

            // Server keeps records oldest -> newest. Search from the end so this
            // Remote consumes the newest exact result for its seat.
            for (int i = static_cast<int>(n) - 1; i >= 0; --i) {
                const uint8_t base = static_cast<uint8_t>(4 + i * recordSize);
                const uint8_t seat = data[base + 0];
                const uint8_t qid  = data[base + 1];
                const uint8_t seq  = data[base + 2];
                const uint8_t ans  = data[base + 3];
                const uint8_t status = (recordSize == 5) ? data[base + 4] : 0;
                if (seat == localSeat) {
                    ARS_LOCK();
                    answerAckQuestion = qid;
                    answerAckSequence = seq;
                    answerAckCode = ans;
                    answerAckStatus = status;
                    answerAckPending = true;
                    ARS_UNLOCK();
                    break;
                }
            }
        }
    }

    // SoftDevice v6 pauses scanning after each report; resume explicitly.
    Bluefruit.Scanner.resume();
}

void begin(uint8_t room, uint8_t seat) {
    localRoom = room;
    localSeat = seat;

    Bluefruit.begin();
    Bluefruit.setName("ARS_REMOTE_01");
    Bluefruit.setTxPower(0);

    Bluefruit.Scanner.setRxCallback(scanCallback);
    Bluefruit.Scanner.setInterval(SCAN_INTERVAL_UNITS, SCAN_WINDOW_UNITS);
    Bluefruit.Scanner.useActiveScan(false);
    Bluefruit.Scanner.start(0); // scan forever
}

void setIdentity(uint8_t room, uint8_t seat) {
    ARS_LOCK();
    localRoom = room;
    localSeat = seat;
    loginAckPending = false;
    answerAckPending = false;
    statePending = false;
    openMaskPending = false;
    ARS_UNLOCK();
}

static void keepUiAlive(uint32_t durationMs) {
    // Display/LED keep running while the radio is busy (keys are read again in loop()).
    const uint32_t start = millis();
    while ((uint32_t)(millis() - start) < durationMs) {
        displayUpdate();
        statusLedUpdate();
        delay(1);
    }
}

static void startUplinkAdvertising(const uint8_t* manufacturerData, size_t len) {
    Bluefruit.Advertising.stop();
    Bluefruit.Advertising.clearData();
    // V3.10: non-connectable, non-scannable. The default (connectable/scannable) type made the
    // laptop's ACTIVE scan send a SCAN_REQ and this Remote answer a SCAN_RSP for every event:
    // wasted airtime on both radios exactly while the laptop needs to transmit the ACK.
    Bluefruit.Advertising.setType(BLE_GAP_ADV_TYPE_NONCONNECTABLE_NONSCANNABLE_UNDIRECTED);
    Bluefruit.Advertising.addFlags(BLE_GAP_ADV_FLAGS_LE_ONLY_GENERAL_DISC_MODE);
    Bluefruit.Advertising.addManufacturerData(manufacturerData, len);
    Bluefruit.Advertising.setInterval(ADV_INTERVAL_UNITS, ADV_INTERVAL_UNITS);
    Bluefruit.Advertising.start(0);
}

void sendPacket(uint8_t type,
                uint8_t room,
                uint8_t seat,
                uint8_t question,
                uint8_t sequence,
                uint8_t answerCode,
                uint16_t batteryMv) {
    uint8_t payload[9] = {
        MAGIC,
        type,
        room,
        seat,
        question,
        sequence,
        answerCode,
        static_cast<uint8_t>(batteryMv & 0xFF),
        static_cast<uint8_t>((batteryMv >> 8) & 0xFF)
    };

    // addManufacturerData() expects company ID in the buffer.
    uint8_t manufacturerData[11];
    manufacturerData[0] = static_cast<uint8_t>(COMPANY_ID & 0xFF);
    manufacturerData[1] = static_cast<uint8_t>((COMPANY_ID >> 8) & 0xFF);
    memcpy(&manufacturerData[2], payload, sizeof(payload));

    // V3.10: TX burst with the scanner off (denser advertising), then scan continuously.
    // The exact ACK cannot be on air before the laptop has received this burst (+ ~40-50 ms
    // DB/MQTT latency), so listening only after the burst loses nothing.
    Bluefruit.Scanner.stop();
    startUplinkAdvertising(manufacturerData, sizeof(manufacturerData));
    keepUiAlive(TX_BURST_MS);
    Bluefruit.Advertising.stop();
    Bluefruit.Scanner.start(0);
}

bool takeLoginAck() {
    if (!loginAckPending) return false;
    loginAckPending = false;
    return true;
}

bool takeAnswerAck(uint8_t& question, uint8_t& sequence, uint8_t& answerCode, uint8_t& status) {
    if (!answerAckPending) return false;
    ARS_LOCK();
    question = answerAckQuestion;
    sequence = answerAckSequence;
    answerCode = answerAckCode;
    status = answerAckStatus;
    answerAckPending = false;
    ARS_UNLOCK();
    return true;
}

bool takeStateUpdate(uint8_t& question, bool& open, uint8_t& nOptions) {
    if (!statePending) return false;
    ARS_LOCK();
    question = stateQuestion;
    open = stateOpen;
    nOptions = stateNOptions;
    statePending = false;
    ARS_UNLOCK();
    return true;
}

bool takeOpenMask(uint64_t& mask) {
    if (!openMaskPending) return false;
    ARS_LOCK();
    mask = latestOpenMask;
    openMaskPending = false;
    ARS_UNLOCK();
    return true;
}

} // namespace ArsBle
