#pragma once
#include <stdint.h>

// Màn hình 2 số 7 đoạn — mỗi chế độ một kiểu hiển thị để học sinh nhận ra ngay (V3.11):
//   số câu (bình thường)      2 chữ số đứng yên             "03"
//   nhập GHẾ (MODE)           "Gh" 0,5 s ↔ số ghế 1 s        "Gh" "07"   ("--" khi chưa gõ)
//   nhập PHÒNG (MODE 2 lần)   "Ph" 0,5 s ↔ số phòng 1 s      "Ph" "01"
//   đáp án đã chọn, chưa gửi  chữ cái ở ô trái, nhấp nháy    "A "  "b "  "C " … "J "
//   đáp án đang gửi           chữ cái đứng yên
void displayBegin();
void displaySetNumber(uint8_t value);
void displaySetDashes();          // "--"
// kind: 0 = ghế ("Gh"), 1 = phòng ("Ph"). hasValue = false → "--". Gọi lại sau mỗi phím: hiện số ngay.
void displaySetEntry(uint8_t kind, bool hasValue, uint8_t value);
// code 1..10 = A..J; blinking = chưa gửi (chờ OK)
void displaySetAnswer(uint8_t code, bool blinking);
void displayUpdate();
