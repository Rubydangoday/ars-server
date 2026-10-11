#include "keypad.h"
#include "board_pins.h"
#include <Arduino.h>
#include <nrf_gpio.h>

using namespace BoardPins;

static const uint32_t rows[4] = { ROW_1, ROW_2, ROW_3, ROW_4 };
static const uint32_t cols[4] = { COL_1, COL_2, COL_3, COL_4 };

// RevJ electrical matrix:
// row1: SW1=1/A, SW2=2/B, SW3=3/C, SW11=LEFT
// row2: SW4=4/D, SW5=5/E, SW6=6/F, SW13=RIGHT
// row3: SW7=7/G, SW8=8/H, SW9=9/I, SW12=MODE
// row4: unused, SW10=0/J, SW14=OK, unused
static const KeyId keyMap[4][4] = {
    { KEY_1_A, KEY_2_B, KEY_3_C, KEY_LEFT  },
    { KEY_4_D, KEY_5_E, KEY_6_F, KEY_RIGHT },
    { KEY_7_G, KEY_8_H, KEY_9_I, KEY_MODE  },
    { KEY_NONE, KEY_0_J, KEY_OK,  KEY_NONE  }
};

static KeyId stableKey = KEY_NONE;
static KeyId lastRawKey = KEY_NONE;
static uint32_t changedAtMs = 0;
static bool pressReported = false;

static void allRowsHiZ() {
    for (uint8_t r = 0; r < 4; ++r) {
        nrf_gpio_cfg_input(rows[r], NRF_GPIO_PIN_NOPULL);
    }
}

static KeyId scanRaw() {
    KeyId found = KEY_NONE;
    uint8_t count = 0;

    for (uint8_t r = 0; r < 4; ++r) {
        allRowsHiZ();

        nrf_gpio_cfg_output(rows[r]);
        nrf_gpio_pin_clear(rows[r]); // selected row LOW

        delayMicroseconds(5);

        for (uint8_t c = 0; c < 4; ++c) {
            if (nrf_gpio_pin_read(cols[c]) == 0) {
                KeyId k = keyMap[r][c];
                if (k != KEY_NONE) {
                    found = k;
                    ++count;
                }
            }
        }
    }

    allRowsHiZ();

    if (count == 0) return KEY_NONE;
    if (count > 1)  return KEY_MULTI;
    return found;
}

void keypadBegin() {
    allRowsHiZ();

    for (uint8_t c = 0; c < 4; ++c) {
        nrf_gpio_cfg_input(cols[c], NRF_GPIO_PIN_PULLUP);
    }
}

KeyId keypadGetPressedEvent() {
    const KeyId raw = scanRaw();
    const uint32_t now = millis();

    if (raw != lastRawKey) {
        lastRawKey = raw;
        changedAtMs = now;
    }

    if ((uint32_t)(now - changedAtMs) < 25) {
        return KEY_NONE;
    }

    if (raw != stableKey) {
        stableKey = raw;

        if (stableKey == KEY_NONE) {
            pressReported = false;
            return KEY_NONE;
        }

        if (!pressReported) {
            pressReported = true;
            return stableKey;
        }
    }

    return KEY_NONE;
}
