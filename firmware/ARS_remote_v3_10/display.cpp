#include "display.h"
#include "board_pins.h"
#include <Arduino.h>
#include <nrf_gpio.h>

using namespace BoardPins;

static const uint32_t segPins[7] = {
    SEG_A, SEG_B, SEG_C, SEG_D, SEG_E, SEG_F, SEG_G
};

// bit0=A ... bit6=G; 1 means segment should be ON
static const uint8_t digitPattern[11] = {
    0b00111111, // 0
    0b00000110, // 1
    0b01011011, // 2
    0b01001111, // 3
    0b01100110, // 4
    0b01101101, // 5
    0b01111101, // 6
    0b00000111, // 7
    0b01111111, // 8
    0b01101111, // 9
    0b01000000  // 10 = "-" (segment G only)
};
static constexpr uint8_t DASH = 10;

static volatile uint8_t currentValue = 0;
static volatile bool showDashes = false;
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

static void writeSegments(uint8_t number) {
    uint8_t p = digitPattern[number <= DASH ? number : 0];
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
    showDashes = false;
}

void displaySetDashes() {
    showDashes = true;
}

void displayUpdate() {
    const uint32_t now = micros();
    if ((uint32_t)(now - lastMuxUs) < 2000) return; // 2 ms per digit
    lastMuxUs = now;

    allDigitsOff();

    uint8_t tens = showDashes ? DASH : currentValue / 10;
    uint8_t ones = showDashes ? DASH : currentValue % 10;

    activeDigit = !activeDigit;

    if (!activeDigit) {
        writeSegments(tens);
        digitOn(DIGIT_1);
    } else {
        writeSegments(ones);
        digitOn(DIGIT_2);
    }
}
