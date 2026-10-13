#pragma once
#include <stdint.h>

enum KeyId : uint8_t {
    KEY_NONE = 0,
    KEY_1_A = 1,
    KEY_2_B = 2,
    KEY_3_C = 3,
    KEY_4_D = 4,
    KEY_5_E = 5,
    KEY_6_F = 6,
    KEY_7_G = 7,
    KEY_8_H = 8,
    KEY_9_I = 9,
    KEY_0_J = 10,
    KEY_LEFT = 11,
    KEY_RIGHT = 12,
    KEY_MODE = 13,
    KEY_OK = 14,
    KEY_MULTI = 255
};

void keypadBegin();
KeyId keypadGetPressedEvent();
