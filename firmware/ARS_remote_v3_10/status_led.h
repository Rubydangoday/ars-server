#pragma once
#include <stdint.h>

enum StatusColor {
    STATUS_OFF = 0,
    STATUS_RED,
    STATUS_GREEN
};

void statusLedBegin();
void statusLedSet(StatusColor color);
void statusLedFlash(StatusColor color, uint32_t durationMs);
void statusLedUpdate();
