#pragma once
// Radio của gateway: quét gói Remote (đẩy vào hàng đợi cho loop()) và phát xoay vòng 4 beacon trên 1 bộ quảng bá.

#include <stdint.h>
#include "gw_codec.h"

namespace GwRadio {

struct Uplink {
    uint8_t mac[6];          // byte thấp trước (thứ tự SoftDevice)
    int8_t rssi;
    uint8_t len;
    uint8_t data[GwCodec::UPLINK_LEN];
};

// Bật SoftDevice, quét liên tục, khởi tạo bộ quảng bá (chưa phát cho tới khi có beacon).
void begin();

// Lấy một gói Remote từ hàng đợi (BLE task → loop()). false = hàng đợi rỗng.
bool takeUplink(Uplink& u);

// Đặt nội dung beacon cho một khe (K_STATE..K_ACKS). len = 0 → bỏ khe đó (ACKS - , mất server).
void setFrame(GwCodec::Kind kind, const uint8_t* data, uint8_t len);
void clearFrames();

struct Stats {
    uint32_t heard;          // gói ARS uplink nghe được (mọi bản lặp)
    uint32_t queueDrop;      // hàng đợi đầy (loop() không kịp gửi USB)
    uint32_t advUpdates;     // số lần đổi nội dung quảng bá
    uint32_t advErrors;      // lỗi SoftDevice khi cấu hình / bắt đầu quảng bá
    bool advertising;
};
void stats(Stats& s, bool reset);

} // namespace GwRadio
