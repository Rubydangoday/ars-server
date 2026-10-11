#include "status_led.h"
#include "board_pins.h"
#include <Arduino.h>
#include <nrf_gpio.h>

using namespace BoardPins;

static uint32_t offAtMs = 0;
static bool timed = false;

void statusLedBegin() {
    nrf_gpio_cfg_output(EXT_VCC);
    nrf_gpio_pin_set(EXT_VCC);

    statusLedSet(STATUS_OFF);
}

void statusLedSet(StatusColor color) {
    timed = false;

    if (color == STATUS_OFF) {
        nrf_gpio_cfg_input(STATUS_LED, NRF_GPIO_PIN_NOPULL); // Hi-Z
    } else {
        nrf_gpio_cfg_output(STATUS_LED);

        if (color == STATUS_RED) {
            nrf_gpio_pin_clear(STATUS_LED); // LOW = red
        } else {
            nrf_gpio_pin_set(STATUS_LED);   // HIGH = green
        }
    }
}

void statusLedFlash(StatusColor color, uint32_t durationMs) {
    statusLedSet(color);
    timed = true;
    offAtMs = millis() + durationMs;
}

void statusLedUpdate() {
    if (timed && (int32_t)(millis() - offAtMs) >= 0) {
        timed = false;
        statusLedSet(STATUS_OFF);
    }
}
