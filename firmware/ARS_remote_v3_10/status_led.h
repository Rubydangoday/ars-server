#pragma once
#include <stdint.h>

enum StatusColor {
    STATUS_OFF = 0,
    STATUS_RED,
    STATUS_GREEN
};

void statusLedBegin();
void statusLedSet(StatusColor color);                  // đặt màu cố định (huỷ kiểu nháy)
void statusLedFlash(StatusColor color, uint32_t durationMs);   // báo kết quả; xong thì trở lại kiểu nháy nếu có
// Kiểu nháy nền cho một chế độ (V3.11): nhập GHẾ = xanh, nhập PHÒNG = đỏ — sáng onMs mỗi periodMs.
void statusLedPattern(StatusColor color, uint16_t onMs, uint16_t periodMs);
void statusLedPatternOff();
void statusLedUpdate();
