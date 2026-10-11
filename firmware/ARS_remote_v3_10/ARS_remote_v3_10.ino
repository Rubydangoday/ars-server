#include "board_pins.h"
#include "keypad.h"
#include "display.h"
#include "status_led.h"
#include "ars_ble.h"

#include <Arduino.h>
#include <nrf_gpio.h>
#include <nrf.h>
#include <Adafruit_LittleFS.h>
#include <InternalFileSystem.h>

using namespace Adafruit_LittleFS_Namespace;

// ============================================================
// ARS REMOTE - BIDIRECTIONAL BLE V3.10
//
// Uplink:   Remote -> laptop/server with BLE Manufacturer Data (non-connectable).
// Downlink: laptop/server -> Remote with STATE/ATTEND/OPENMASK/ACKS (exact ACK/NACK).
//
// Hardware GPIO continues to use proven physical P0.xx/P1.xx pins
// from board_pins.h via nrf_gpio. Arduino D10..D17 mapping is not used.
//
// V3.10 vs V3.9:
//  * one TX burst (150 ms) then ONE continuous ACK listen window (1000 ms) instead of 3x TX/RX slices
//  * exponential randomized backoff 50-150 / 150-400 / 300-800 ms, 4 attempts, SAME seq on retry
//  * room/seat entered on the keypad (MODE) and stored in flash -> many Remotes can be tested together
//  * random initial seq per boot (fewer seq collisions after reboot; LOGIN still clears old results)
// ============================================================

static constexpr uint8_t DEFAULT_ROOM = 1;
static constexpr uint16_t TEST_BATTERY_MV = 3000;   // TODO: ADC battery measurement (step 2)

// After the TX burst, keep scanning this long for the exact ACK/NACK before backing off.
// Server path is ~40-60 ms (scan -> MQTT -> DB -> MQTT -> BlueZ); ACK lives 1.8 s on the laptop.
static constexpr uint32_t ACK_LISTEN_MS = 1000;
static constexpr uint8_t MAX_ATTEMPTS = 4;          // first send + up to 3 retries (reliability > latency)

// Randomized exponential backoff before retry k (k = 1..3). Wider windows on later retries spread
// a classroom of 30-50 Remotes that collided at the same instant instead of re-colliding.
static constexpr uint16_t BACKOFF_MS[3][2] = { {50, 150}, {150, 400}, {300, 800} };

// Room/seat are typed by the student (MODE) and kept in flash.
static uint8_t gRoom = DEFAULT_ROOM;
static uint8_t gSeat = 0;                          // 0 = not set yet

static uint8_t seqNo = 0;
static uint8_t currentQuestion = 1;
static uint8_t selectedAnswer = 0; // 0 none, 1..10 A..J

// Latest current-question metadata received in STATE V3.8.
// We validate locally only when currentQuestion matches this server question.
// If metadata is missing/stale, the server remains authoritative and may NACK.
static uint8_t serverStateQuestion = 0;
static uint8_t serverOptionCount = 0;
static bool serverQuestionOpen = false;

// ----------------------- pending TX / retry state -----------------------
enum PendingKind : uint8_t {
    PENDING_NONE = 0,
    PENDING_LOGIN,
    PENDING_ANSWER
};

struct PendingTx {
    PendingKind kind = PENDING_NONE;
    uint8_t question = 0;
    uint8_t seq = 0;
    uint8_t answer = 0;
    uint8_t attempts = 0;
    uint32_t deadlineMs = 0;
};

static PendingTx pending;
static bool retryBackoffArmed = false;
static uint32_t retryBackoffUntilMs = 0;

static uint8_t nextSeq() {
    seqNo = static_cast<uint8_t>(seqNo + 1);
    return seqNo;
}

static char answerLetter(uint8_t code) {
    if (code < 1 || code > 10) return '?';
    return static_cast<char>('A' + code - 1);
}

static bool optionKnownInvalid(uint8_t answerCode) {
    if (serverStateQuestion == 0 || serverOptionCount == 0) return false;
    if (currentQuestion != serverStateQuestion) return false;
    return answerCode < 1 || answerCode > serverOptionCount;
}

static void reportLocalInvalidOption(uint8_t answerCode) {
    Serial.print("LOCAL REJECT: answer ");
    Serial.print(answerLetter(answerCode));
    Serial.print(" invalid for q=");
    Serial.print(currentQuestion);
    Serial.print("; allowed=A..");
    Serial.println(answerLetter(serverOptionCount));
    statusLedFlash(STATUS_RED, 500);
}

static const char* resultStatusName(uint8_t status) {
    switch (status) {
        case 0:  return "OK";
        case 1:  return "BAD_OPTION";
        case 2:  return "QUESTION_CLOSED";
        case 3:  return "NOT_LOGGED_IN";
        case 4:  return "NO_SESSION";
        case 5:  return "QUESTION_NOT_OPEN";
        case 6:  return "LATE";
        case 7:  return "NO_CHANGE";
        case 8:  return "UNKNOWN_SEAT";
        case 9:  return "MAPPING_MISMATCH";
        case 10: return "ID_MISMATCH";
        case 11: return "SEAT_TAKEN";
        case 12: return "MAC_BOUND";
        case 13: return "WRONG_LINK";
        case 14: return "MALFORMED";
        default: return "SERVER_REJECT";
    }
}

// ----------------------- identity (room / seat) in flash -----------------------
static const char* ID_FILE = "/ars_id.bin";

static void loadIdentity() {
    InternalFS.begin();
    File f(InternalFS);
    if (f.open(ID_FILE, FILE_O_READ)) {
        uint8_t b[4] = {0};
        if (f.read(b, sizeof(b)) == sizeof(b) && b[0] == 0xA5 && b[3] == (uint8_t)(b[0] ^ b[1] ^ b[2])) {
            gRoom = b[1] ? b[1] : DEFAULT_ROOM;
            gSeat = (b[2] >= 1 && b[2] <= 99) ? b[2] : 0;
        }
        f.close();
    }
}

static void saveIdentity() {
    const uint8_t b[4] = { 0xA5, gRoom, gSeat, (uint8_t)(0xA5 ^ gRoom ^ gSeat) };
    InternalFS.remove(ID_FILE);
    File f(InternalFS);
    if (f.open(ID_FILE, FILE_O_WRITE)) {
        f.write(b, sizeof(b));
        f.close();
    }
}

// ----------------------- keypad entry of seat / room -----------------------
enum UiMode : uint8_t { UI_NORMAL = 0, UI_ENTER_SEAT, UI_ENTER_ROOM };
static UiMode uiMode = UI_NORMAL;
static uint8_t entryValue = 0;
static bool entryTyped = false;

static void showEntry() {
    if (!entryTyped && entryValue == 0) displaySetDashes();
    else displaySetNumber(entryValue);
}

// uint8_t parameter: the Arduino builder puts auto-prototypes above the enum declaration.
static void startEntry(uint8_t mode) {
    uiMode = static_cast<UiMode>(mode);
    entryValue = (mode == UI_ENTER_SEAT) ? gSeat : gRoom;
    entryTyped = false;
    showEntry();
    Serial.println(mode == UI_ENTER_SEAT
        ? "ENTER SEAT: type 1..99, OK = save + LOGIN, MODE = room, LEFT = cancel"
        : "ENTER ROOM: type 1..99, OK = save, MODE = seat, LEFT = cancel");
}

static void printPacket(const char* label,
                        uint8_t type,
                        uint8_t q,
                        uint8_t seq,
                        uint8_t ans) {
    Serial.print(label);
    Serial.print(" room=");
    Serial.print(gRoom);
    Serial.print(" seat=");
    Serial.print(gSeat);
    Serial.print(" q=");
    Serial.print(q);
    Serial.print(" seq=");
    Serial.print(seq);
    Serial.print(" ans=");
    if (ans == 0) Serial.print('-');
    else Serial.print(answerLetter(ans));
    Serial.println();
}

static void transmitPending(bool retry) {
    if (pending.kind == PENDING_NONE) return;

    pending.attempts++;

    if (pending.kind == PENDING_LOGIN) {
        printPacket(retry ? "RETRY LOGIN" : "TX LOGIN",
                    ArsBle::TYPE_LOGIN, 0, pending.seq, 0);
        ArsBle::sendPacket(
            ArsBle::TYPE_LOGIN,
            gRoom,
            gSeat,
            0,
            pending.seq,
            0,
            TEST_BATTERY_MV
        );
    } else {
        printPacket(retry ? "RETRY ANSWER" : "TX ANSWER",
                    ArsBle::TYPE_ANSWER,
                    pending.question,
                    pending.seq,
                    pending.answer);
        ArsBle::sendPacket(
            ArsBle::TYPE_ANSWER,
            gRoom,
            gSeat,
            pending.question,
            pending.seq,
            pending.answer,
            TEST_BATTERY_MV
        );
    }

    // sendPacket() returns after the TX burst with the scanner running: listen for the exact result.
    pending.deadlineMs = millis() + ACK_LISTEN_MS;
}

static void beginLogin() {
    if (gSeat == 0) {
        Serial.println("LOGIN skipped: seat not set (press MODE, type seat, OK)");
        startEntry(UI_ENTER_SEAT);
        return;
    }
    pending.kind = PENDING_LOGIN;
    pending.question = 0;
    pending.seq = nextSeq();
    pending.answer = 0;
    pending.attempts = 0;
    retryBackoffArmed = false;

    statusLedSet(STATUS_OFF);
    transmitPending(false);
}

static void beginAnswer() {
    if (selectedAnswer == 0) {
        Serial.println("OK ignored: no answer selected");
        statusLedFlash(STATUS_RED, 400);
        return;
    }

    if (pending.kind != PENDING_NONE) {
        Serial.println("OK ignored: waiting for server ACK");
        statusLedFlash(STATUS_RED, 180);
        return;
    }

    // V3.8 local guard: if the latest STATE describes this question and says
    // only A..D are valid, do not waste radio airtime sending E..J.
    if (optionKnownInvalid(selectedAnswer)) {
        reportLocalInvalidOption(selectedAnswer);
        selectedAnswer = 0;
        displaySetNumber(currentQuestion);
        return;
    }

    pending.kind = PENDING_ANSWER;
    pending.question = currentQuestion;
    pending.seq = nextSeq();
    pending.answer = selectedAnswer;
    pending.attempts = 0;
    retryBackoffArmed = false;

    statusLedSet(STATUS_OFF);
    transmitPending(false);
}

static void finishLoginAck() {
    if (pending.kind != PENDING_LOGIN) return;

    Serial.println("ACK LOGIN: server confirmed attendance");
    pending.kind = PENDING_NONE;
    retryBackoffArmed = false;
    statusLedFlash(STATUS_GREEN, 350);
}

static void finishAnswerAck(uint8_t q, uint8_t seq, uint8_t ans, uint8_t status) {
    if (pending.kind != PENDING_ANSWER) return;
    if (q != pending.question || seq != pending.seq || ans != pending.answer) {
        Serial.print("Ignore old/other RESULT q=");
        Serial.print(q);
        Serial.print(" seq=");
        Serial.print(seq);
        Serial.print(" ans=");
        Serial.print(answerLetter(ans));
        Serial.print(" status=");
        Serial.println(resultStatusName(status));
        return;
    }

    if (status == 0) {
        Serial.print("ACK ANSWER EXACT: server confirmed q=");
        Serial.print(q);
        Serial.print(" seq=");
        Serial.print(seq);
        Serial.print(" ans=");
        Serial.println(answerLetter(ans));
        statusLedFlash(STATUS_GREEN, 450);
    } else {
        Serial.print("NACK ANSWER EXACT: server rejected q=");
        Serial.print(q);
        Serial.print(" seq=");
        Serial.print(seq);
        Serial.print(" ans=");
        Serial.print(answerLetter(ans));
        Serial.print(" reason=");
        Serial.println(resultStatusName(status));
        statusLedFlash(STATUS_RED, 700);
    }

    // Accepted or rejected, the server has definitively processed this exact
    // transaction. Do not retry it. Force a fresh choice for the next OK press.
    pending.kind = PENDING_NONE;
    retryBackoffArmed = false;
    selectedAnswer = 0;
    displaySetNumber(currentQuestion);
}

static void processDownlink() {
    if (ArsBle::takeLoginAck()) {
        finishLoginAck();
    }

    uint8_t ackQuestion = 0;
    uint8_t ackSeq = 0;
    uint8_t ackAnswer = 0;
    uint8_t ackStatus = 0;
    if (ArsBle::takeAnswerAck(ackQuestion, ackSeq, ackAnswer, ackStatus)) {
        finishAnswerAck(ackQuestion, ackSeq, ackAnswer, ackStatus);
    }

    uint8_t serverQ = 0;
    uint8_t serverOptions = 0;
    bool serverOpen = false;
    if (ArsBle::takeStateUpdate(serverQ, serverOpen, serverOptions)) {
        serverStateQuestion = serverQ;
        serverQuestionOpen = serverOpen;
        serverOptionCount = serverOptions;
        Serial.print("SERVER STATE q=");
        Serial.print(serverQ);
        Serial.print(" open=");
        Serial.print(serverOpen ? 1 : 0);
        Serial.print(" options=");
        Serial.println(serverOptions);
    }

    uint64_t mask = 0;
    if (ArsBle::takeOpenMask(mask)) {
        Serial.print("SERVER OPENMASK 0x");
        const uint32_t hi = static_cast<uint32_t>(mask >> 32);
        const uint32_t lo = static_cast<uint32_t>(mask & 0xFFFFFFFFu);
        if (hi) {
            Serial.print(hi, HEX);
            Serial.print("_");
            Serial.println(lo, HEX);
        } else {
            Serial.println(lo, HEX);
        }
    }
}

static void processRetry() {
    if (pending.kind == PENDING_NONE) return;
    const uint32_t now = millis();
    if ((int32_t)(now - pending.deadlineMs) < 0) return;

    if (pending.attempts >= MAX_ATTEMPTS) {
        Serial.println("ACK TIMEOUT: server did not confirm");
        pending.kind = PENDING_NONE;
        retryBackoffArmed = false;
        statusLedFlash(STATUS_RED, 900);
        return;
    }

    // Do not let a classroom full of remotes retry at exactly the same instant.
    // Arm one random non-blocking backoff (window grows with each retry), then resend the SAME seq.
    if (!retryBackoffArmed) {
        const uint8_t k = (pending.attempts >= 1 && pending.attempts <= 3) ? pending.attempts - 1 : 2;
        const uint32_t span = BACKOFF_MS[k][1] - BACKOFF_MS[k][0] + 1;
        const uint16_t backoff = BACKOFF_MS[k][0] + static_cast<uint16_t>(random(span));
        retryBackoffUntilMs = now + backoff;
        retryBackoffArmed = true;
        Serial.print("No ACK yet -> random backoff ");
        Serial.print(backoff);
        Serial.println(" ms before retry same seq");
        return;
    }

    if ((int32_t)(now - retryBackoffUntilMs) < 0) return;

    retryBackoffArmed = false;
    transmitPending(true); // IMPORTANT: same seq, so server can de-duplicate
}

static bool isAnswerKey(KeyId key) {
    return key >= KEY_1_A && key <= KEY_0_J;
}

static uint8_t answerCodeFromKey(KeyId key) {
    return static_cast<uint8_t>(key);
}

static void handleEntryKey(KeyId key) {
    if (isAnswerKey(key)) {                          // 1/A..9/I = 1..9, 0/J = 0
        const uint8_t d = (key == KEY_0_J) ? 0 : static_cast<uint8_t>(key);
        entryValue = entryTyped ? static_cast<uint8_t>((entryValue * 10 + d) % 100) : d;
        entryTyped = true;
        showEntry();
        return;
    }
    switch (key) {
        case KEY_MODE:
            startEntry(uiMode == UI_ENTER_SEAT ? UI_ENTER_ROOM : UI_ENTER_SEAT);
            break;
        case KEY_LEFT:                               // cancel
            uiMode = UI_NORMAL;
            Serial.println("Entry cancelled");
            displaySetNumber(currentQuestion);
            if (gSeat == 0) startEntry(UI_ENTER_SEAT);
            break;
        case KEY_OK:
            if (entryValue < 1 || entryValue > 99) {
                Serial.println("Entry rejected: use 1..99");
                statusLedFlash(STATUS_RED, 500);
                break;
            }
            if (uiMode == UI_ENTER_ROOM) {
                gRoom = entryValue;
                saveIdentity();
                ArsBle::setIdentity(gRoom, gSeat);
                Serial.print("Room saved: ");
                Serial.println(gRoom);
                statusLedFlash(STATUS_GREEN, 300);
                startEntry(UI_ENTER_SEAT);           // continue with the seat
            } else {
                gSeat = entryValue;
                saveIdentity();
                ArsBle::setIdentity(gRoom, gSeat);
                Serial.print("Seat saved: ");
                Serial.println(gSeat);
                uiMode = UI_NORMAL;
                displaySetNumber(currentQuestion);
                beginLogin();                        // OK without typing = LOGIN again (old MODE behaviour)
            }
            break;
        default:
            break;
    }
}

static void handleKey(KeyId key) {
    if (uiMode != UI_NORMAL && key != KEY_MULTI) {
        handleEntryKey(key);
        return;
    }
    if (key == KEY_MULTI) {
        Serial.println("KEY_MULTI: more than one key detected");
        statusLedFlash(STATUS_RED, 600);
        return;
    }

    if (isAnswerKey(key)) {
        if (pending.kind != PENDING_NONE) {
            Serial.println("Answer key ignored: waiting for server ACK");
            return;
        }

        const uint8_t candidate = answerCodeFromKey(key);
        if (optionKnownInvalid(candidate)) {
            reportLocalInvalidOption(candidate);
            return;
        }

        selectedAnswer = candidate;
        Serial.print("Selected answer: ");
        Serial.println(answerLetter(selectedAnswer));
        displaySetNumber(selectedAnswer); // temporary A..J code 01..10
        return;
    }

    switch (key) {
        case KEY_LEFT:
            if (pending.kind != PENDING_NONE) {
                Serial.println("LEFT ignored: waiting for server ACK");
                break;
            }
            if (currentQuestion > 1) currentQuestion--;
            selectedAnswer = 0;
            displaySetNumber(currentQuestion);
            Serial.print("Question: ");
            Serial.println(currentQuestion);
            break;

        case KEY_RIGHT:
            if (pending.kind != PENDING_NONE) {
                Serial.println("RIGHT ignored: waiting for server ACK");
                break;
            }
            if (currentQuestion < 99) currentQuestion++;
            selectedAnswer = 0;
            displaySetNumber(currentQuestion);
            Serial.print("Question: ");
            Serial.println(currentQuestion);
            break;

        case KEY_MODE:
            if (pending.kind != PENDING_NONE) {
                Serial.println("MODE ignored: waiting for server ACK");
                break;
            }
            startEntry(UI_ENTER_SEAT);               // MODE -> type seat (MODE again -> room), OK -> LOGIN
            break;

        case KEY_OK:
            beginAnswer();
            break;

        default:
            break;
    }
}

void setup() {
    nrf_gpio_cfg_output(BoardPins::EXT_VCC);
    nrf_gpio_pin_set(BoardPins::EXT_VCC);

    Serial.begin(115200);
    const uint32_t serialWaitStart = millis();
    while (!Serial && (uint32_t)(millis() - serialWaitStart) < 3000) {
        delay(10);
    }

    displayBegin();
    statusLedBegin();
    keypadBegin();

    // Proven hardware self-test.
    displaySetNumber(88);
    statusLedSet(STATUS_RED);
    uint32_t t0 = millis();
    while ((uint32_t)(millis() - t0) < 500) displayUpdate();

    statusLedSet(STATUS_GREEN);
    t0 = millis();
    while ((uint32_t)(millis() - t0) < 500) displayUpdate();

    statusLedSet(STATUS_OFF);
    displaySetNumber(currentQuestion);

    Serial.println();
    Serial.println("ARS Remote bidirectional V3.10");
    Serial.println("BLE: TX burst 150 ms + ACK listen 1000 ms + exact ACK/NACK + exponential backoff");
    Serial.println("GPIO: physical nRF52840 pins via nrf_gpio");

    // Per-device pseudo-random seed for retry backoff. Different remotes have
    // different DEVICEID values, so simultaneous retries spread out naturally.
    randomSeed(static_cast<uint32_t>(micros()) ^ NRF_FICR->DEVICEID[0] ^ NRF_FICR->DEVICEID[1]);
    seqNo = static_cast<uint8_t>(random(256));      // random start: a reboot rarely reuses a recent seq

    loadIdentity();
    Serial.print("Identity from flash: room=");
    Serial.print(gRoom);
    Serial.print(" seat=");
    Serial.println(gSeat);

    ArsBle::begin(gRoom, gSeat);
    beginLogin();                                    // seat 0 -> asks for the seat first

    Serial.println("Ready: A..J select, OK sends, MODE = set seat/room then LOGIN.");
    Serial.println("Green = accepted exact result. Red = server NACK or ACK timeout.");
}

void loop() {
    displayUpdate();
    statusLedUpdate();

    // Consume BLE beacons produced by server and handle ACKs.
    processDownlink();

    // Retry pending LOGIN/ANSWER if server has not acknowledged it.
    processRetry();

    const KeyId key = keypadGetPressedEvent();
    if (key != KEY_NONE) {
        handleKey(key);
    }
}
