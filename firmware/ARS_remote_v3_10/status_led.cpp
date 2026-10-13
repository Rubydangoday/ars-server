#include "status_led.h"
#include "board_pins.h"
#include <Arduino.h>
#include <nrf_gpio.h>

using namespace BoardPins;

static uint32_t offAtMs = 0;
static bool timed = false;

// Kiểu nháy nền (chế độ nhập ghế / phòng); periodMs = 0 = không có.
static StatusColor patColor = STATUS_OFF;
static uint16_t patOnMs = 0;
static uint16_t patPeriodMs = 0;
static uint32_t patStartMs = 0;
static int8_t applied = -1;                // màu đang xuất ra chân LED (tránh cấu hình lại chân mỗi vòng)

static void apply(StatusColor color) {
    if (applied == color) return;
    applied = color;
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

void statusLedBegin() {
    nrf_gpio_cfg_output(EXT_VCC);
    nrf_gpio_pin_set(EXT_VCC);

    statusLedSet(STATUS_OFF);
}

void statusLedSet(StatusColor color) {
    timed = false;
    patPeriodMs = 0;
    apply(color);
}

void statusLedFlash(StatusColor color, uint32_t durationMs) {
    apply(color);
    timed = true;
    offAtMs = millis() + durationMs;
}

void statusLedPattern(StatusColor color, uint16_t onMs, uint16_t periodMs) {
    if (patPeriodMs == periodMs && patColor == color && patOnMs == onMs) return;   // đang chạy đúng kiểu này
    patColor = color;
    patOnMs = onMs;
    patPeriodMs = periodMs;
    patStartMs = millis();
}

void statusLedPatternOff() {
    patPeriodMs = 0;
    if (!timed) apply(STATUS_OFF);
}

void statusLedUpdate() {
    const uint32_t now = millis();
    if (timed) {
        if ((int32_t)(now - offAtMs) < 0) return;  // đèn kết quả còn sáng
        timed = false;
        apply(STATUS_OFF);
    }
    if (patPeriodMs) {
        apply(((now - patStartMs) % patPeriodMs) < patOnMs ? patColor : STATUS_OFF);
    }
}
