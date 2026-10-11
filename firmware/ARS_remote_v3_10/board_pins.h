#pragma once

#include <stdint.h>
#include <nrf_gpio.h>

namespace BoardPins {

// ===== Power control on SuperMini / nice!nano style board =====
static constexpr uint32_t EXT_VCC = NRF_GPIO_PIN_MAP(0, 13); // P0.13

// ===== 7-segment DS1: common anode =====
static constexpr uint32_t SEG_A = NRF_GPIO_PIN_MAP(0, 6);   // P0.06
static constexpr uint32_t SEG_B = NRF_GPIO_PIN_MAP(0, 8);   // P0.08
static constexpr uint32_t SEG_C = NRF_GPIO_PIN_MAP(0, 17);  // P0.17
static constexpr uint32_t SEG_D = NRF_GPIO_PIN_MAP(0, 20);  // P0.20
static constexpr uint32_t SEG_E = NRF_GPIO_PIN_MAP(0, 22);  // P0.22
static constexpr uint32_t SEG_F = NRF_GPIO_PIN_MAP(0, 24);  // P0.24
static constexpr uint32_t SEG_G = NRF_GPIO_PIN_MAP(1, 0);   // P1.00

// Digit P-MOS gate control: LOW = digit ON
static constexpr uint32_t DIGIT_1 = NRF_GPIO_PIN_MAP(0, 11); // P0.11
static constexpr uint32_t DIGIT_2 = NRF_GPIO_PIN_MAP(1, 4);  // P1.04

// ===== Red/Green status LED =====
// RevJ assumption: LOW = RED, HIGH = GREEN, Hi-Z = OFF
static constexpr uint32_t STATUS_LED = NRF_GPIO_PIN_MAP(1, 6); // P1.06

// ===== Key matrix rows =====
static constexpr uint32_t ROW_1 = NRF_GPIO_PIN_MAP(1, 13); // P1.13
static constexpr uint32_t ROW_2 = NRF_GPIO_PIN_MAP(1, 11); // P1.11
static constexpr uint32_t ROW_3 = NRF_GPIO_PIN_MAP(0, 10); // P0.10 / NFC2
static constexpr uint32_t ROW_4 = NRF_GPIO_PIN_MAP(0, 9);  // P0.09 / NFC1

// ===== Key matrix columns =====
static constexpr uint32_t COL_1 = NRF_GPIO_PIN_MAP(0, 31); // P0.31
static constexpr uint32_t COL_2 = NRF_GPIO_PIN_MAP(0, 29); // P0.29
static constexpr uint32_t COL_3 = NRF_GPIO_PIN_MAP(0, 2);  // P0.02
static constexpr uint32_t COL_4 = NRF_GPIO_PIN_MAP(1, 15); // P1.15

}
