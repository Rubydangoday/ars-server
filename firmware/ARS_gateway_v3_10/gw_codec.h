#pragma once
// Định dạng byte trên sóng và trên USB của gateway — bản C++ của bridge/blecodec.py.
// Không phụ thuộc Arduino: tests/gateway_codec_test.py biên dịch file này bằng g++ và so từng byte với blecodec.py.

#include <stddef.h>
#include <stdint.h>

namespace GwCodec {

static constexpr uint16_t COMPANY_ID = 0xFFFF;
static constexpr uint8_t MAGIC = 0xA5;
static constexpr uint8_t PKT_LOGIN = 1;
static constexpr uint8_t PKT_ANSWER = 2;
static constexpr uint8_t F_STATE = 0x10;
static constexpr uint8_t F_ATTEND = 0x11;
static constexpr uint8_t F_OPENMASK = 0x12;
static constexpr uint8_t F_ACKS = 0x13;
static constexpr uint8_t MAX_ACK_RECORDS = 4;   // 4 + 4×5 = 24 byte: vừa một gói quảng bá legacy (31 byte)
static constexpr uint8_t MAX_FRAME = 24;        // gói beacon dài nhất (ACKS đủ 4 bản ghi)
static constexpr uint8_t UPLINK_LEN = 9;        // gói Remote: A5 type room ghế câu seq đáp_án pin(2)

// Thứ tự cũng là chỉ số khe beacon trong gateway.
enum Kind : int8_t { K_NONE = -1, K_STATE = 0, K_ATTEND = 1, K_OPENMASK = 2, K_ACKS = 3, K_COUNT = 4 };

// Một dòng lệnh của ingest (STATE / ATTEND / OPENMASK / ACKS) → bytes beacon (không gồm mã công ty), giống
// blecodec.encode_cmd(line, room). STATE mang room của nó (ghi vào *stateRoom); 3 loại còn lại dùng `room`.
// Lệnh lạ hoặc sai cú pháp → K_NONE.
Kind encodeCmd(const char* line, uint8_t room, uint8_t out[MAX_FRAME], uint8_t& outLen, uint8_t& stateRoom);

// Manufacturer data của Remote (sau mã công ty) → 1 dòng JSON giống blecodec.decode_uplink() (README mục 4.2),
// không có '\n'. mac[0] là byte thấp (thứ tự của SoftDevice). Không phải gói LOGIN/ANSWER → 0.
size_t uplinkJson(const uint8_t* data, size_t len, const uint8_t mac[6], int8_t rssi, char* out, size_t outSize);

// Lọc bản lặp: Remote phát một đợt 150 ms (~6 bản cùng nội dung). Bản đầu tiên được chuyển lên; bản giống hệt
// (cùng MAC + cùng 9 byte) trong DEDUP_MS sau đó bị bỏ. Lần gửi lại cùng seq của Remote cách ≥ 1 s nên vẫn
// được chuyển lên (server gia hạn ACK cho lần đó).
static constexpr uint32_t DEDUP_MS = 300;
class Dedup {
public:
    // true = bản lặp, bỏ qua. nowMs: đồng hồ tăng dần (millis()).
    bool seen(const uint8_t mac[6], const uint8_t* data, size_t len, uint32_t nowMs);
private:
    static constexpr uint8_t N = 128;            // > số gói khác nhau trong 300 ms của 50 Remote
    struct Entry { uint32_t t; uint8_t used; uint8_t mac[6]; uint8_t data[UPLINK_LEN]; };
    Entry e_[N] = {};
    uint8_t next_ = 0;
};

} // namespace GwCodec
