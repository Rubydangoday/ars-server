#pragma once
#include <stdint.h>

void displayBegin();
void displaySetNumber(uint8_t value);
void displaySetDashes();          // "--": waiting for the student to type a seat / room number
void displayUpdate();
