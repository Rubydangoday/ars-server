#include "display.h"
#include "board_pins.h"
#include <Arduino.h>
#include <nrf_gpio.h>

using namespace BoardPins;

static const uint32_t segPins[7] = {
    SEG_A, SEG_B, SEG_C, SEG_D, SEG_E, SEG_F, SEG_G
};

// bit0=A ... bit6=G; 1 means segment should be ON
static const uint8_t digitPattern[10] = {
    0b00111111, // 0
    0b00000110, // 1
    0b01011011, // 2
    0b01001111, // 3
    0b01100110, // 4
    0b01101101, // 5
    0b01111101, // 6
    0b00000111, // 7
    0b01111111, // 8
    0b01101111  // 9
};
// Đáp án A..J: A b C d E F G H I J (I dùng 2 đoạn TRÁI để khác số 1)
static const uint8_t letterPattern[10] = {
    0b01110111, // A
    0b01111100, // b
    0b00111001, // C
    0b01011110, // d
    0b01111001, // E
    0b01110001, // F
    0b00111101, // G
    0b01110110, // H
    0b00110000, // I
    0b00011110  // J
};
static constexpr uint8_t SEG_DASH = 0b01000000;   // "-"
static constexpr uint8_t SEG_BLANK = 0;
static constexpr uint8_t GLYPH_P = 0b01110011;
static constexpr uint8_t GLYPH_h = 0b01110100;
static constexpr uint8_t GLYPH_G = 0b00111101;

enum Mode : uint8_t { M_NUMBER, M_DASHES, M_ENTRY, M_ANSWER };

static constexpr uint32_t LABEL_MS = 500;         // nhãn "Gh"/"Ph"
static constexpr uint32_t ENTRY_CYCLE_MS = 1500;  // nhãn 0,5 s + số 1 s
static constexpr uint32_t BLINK_ON_MS = 450;      // đáp án chưa gửi: sáng 450 ms, tắt 250 ms
static constexpr uint32_t BLINK_PERIOD_MS = 700;

static volatile uint8_t mode = M_NUMBER;
static volatile uint8_t currentValue = 0;
static volatile uint8_t entryKind = 0;
static volatile bool entryHasValue = false;
static volatile uint8_t answerCode = 1;
static volatile bool answerBlink = false;
static volatile uint32_t phaseStartMs = 0;
static bool activeDigit = false;
static uint32_t lastMuxUs = 0;

static inline void segmentOff(uint32_t pin) {
    nrf_gpio_pin_set(pin);     // common-anode: HIGH = OFF
}

static inline void segmentOn(uint32_t pin) {
    nrf_gpio_pin_clear(pin);   // common-anode: LOW = ON
}

static inline void digitOff(uint32_t pin) {
    nrf_gpio_pin_set(pin);     // PMOS gate HIGH = OFF
}

static inline void digitOn(uint32_t pin) {
    nrf_gpio_pin_clear(pin);   // PMOS gate LOW = ON
}

static void allDigitsOff() {
    digitOff(DIGIT_1);
    digitOff(DIGIT_2);
}

static void writePattern(uint8_t p) {
    for (uint8_t i = 0; i < 7; ++i) {
        if (p & (1u << i)) segmentOn(segPins[i]);
        else               segmentOff(segPins[i]);
    }
}

void displayBegin() {
    for (uint8_t i = 0; i < 7; ++i) {
        nrf_gpio_cfg_output(segPins[i]);
        segmentOff(segPins[i]);
    }

    nrf_gpio_cfg_output(DIGIT_1);
    nrf_gpio_cfg_output(DIGIT_2);
    allDigitsOff();

    displaySetNumber(0);
}

void displaySetNumber(uint8_t value) {
    if (value > 99) value = 99;
    currentValue = value;
    mode = M_NUMBER;
}

void displaySetDashes() {
    mode = M_DASHES;
}

void displaySetEntry(uint8_t kind, bool hasValue, uint8_t value) {
    const bool sameKind = mode == M_ENTRY && entryKind == kind;
    entryKind = kind;
    entryHasValue = hasValue;
    currentValue = value > 99 ? 99 : value;
    // Vào chế độ: bắt đầu bằng nhãn. Đang gõ (cùng chế độ): hiện số ngay, nhãn quay lại sau 1 s.
    phaseStartMs = sameKind ? millis() - LABEL_MS : millis();
    mode = M_ENTRY;
}

void displaySetAnswer(uint8_t code, bool blinking) {
    answerCode = (code >= 1 && code <= 10) ? code : 1;
    answerBlink = blinking;
    phaseStartMs = millis();
    mode = M_ANSWER;
}

// Hai mẫu đoạn (ô trái, ô phải) cho khung hình hiện tại.
static void currentGlyphs(uint8_t& left, uint8_t& right) {
    const uint32_t t = millis() - phaseStartMs;
    switch (mode) {
        case M_DASHES:
            left = right = SEG_DASH;
            return;
        case M_ENTRY:
            if (t % ENTRY_CYCLE_MS < LABEL_MS) {
                left = entryKind ? GLYPH_P : GLYPH_G;
                right = GLYPH_h;
            } else if (!entryHasValue) {
                left = right = SEG_DASH;
            } else {
                left = digitPattern[currentValue / 10];
                right = digitPattern[currentValue % 10];
            }
            return;
        case M_ANSWER:
            left = (!answerBlink || t % BLINK_PERIOD_MS < BLINK_ON_MS) ? letterPattern[answerCode - 1] : SEG_BLANK;
            right = SEG_BLANK;
            return;
        default:
            left = digitPattern[currentValue / 10];
            right = digitPattern[currentValue % 10];
    }
}

void displayUpdate() {
    const uint32_t now = micros();
    if ((uint32_t)(now - lastMuxUs) < 2000) return; // 2 ms per digit
    lastMuxUs = now;

    allDigitsOff();

    uint8_t left, right;
    currentGlyphs(left, right);

    activeDigit = !activeDigit;

    if (!activeDigit) {
        writePattern(left);
        digitOn(DIGIT_1);
    } else {
        writePattern(right);
        digitOn(DIGIT_2);
    }
}
