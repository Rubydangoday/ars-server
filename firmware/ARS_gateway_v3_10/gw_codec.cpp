#include "gw_codec.h"

#include <stdio.h>
#include <string.h>

namespace GwCodec {

namespace {

constexpr uint8_t MAX_TOKENS = 8;

struct Tok { const char* s; size_t n; };

bool isSpace(char c) { return c == ' ' || c == '\t' || c == '\r' || c == '\n' || c == '\v' || c == '\f'; }

// Tách theo khoảng trắng như str.split(). Trả về số token (tối đa MAX_TOKENS, phần dư bị bỏ — không lệnh nào cần).
uint8_t split(const char* line, Tok t[MAX_TOKENS]) {
    uint8_t n = 0;
    const char* p = line;
    while (*p && n < MAX_TOKENS) {
        while (*p && isSpace(*p)) ++p;
        if (!*p) break;
        const char* s = p;
        while (*p && !isSpace(*p)) ++p;
        t[n++] = {s, static_cast<size_t>(p - s)};
    }
    return n;
}

bool eq(const Tok& t, const char* w) { return strlen(w) == t.n && memcmp(t.s, w, t.n) == 0; }

// Số nguyên kiểu int() của Python (dấu tuỳ chọn + chữ số), không giới hạn độ dài:
// giữ phần dư mod 256 (cho `& 0xFF`) và trị tuyệt đối đã bão hoà (cho so sánh / khác 0).
struct Num { bool neg; uint32_t sat; uint8_t mod; };

bool parseNum(const char* s, size_t n, Num& o) {
    o = {false, 0, 0};
    size_t i = 0;
    if (i < n && (s[i] == '+' || s[i] == '-')) { o.neg = s[i] == '-'; ++i; }
    if (i == n) return false;
    for (; i < n; ++i) {
        if (s[i] < '0' || s[i] > '9') return false;
        const uint8_t d = static_cast<uint8_t>(s[i] - '0');
        o.mod = static_cast<uint8_t>(o.mod * 10 + d);
        if (o.sat < 100000) o.sat = o.sat * 10 + d;
    }
    return true;
}

uint8_t low8(const Num& v) { return v.neg ? static_cast<uint8_t>(0u - v.mod) : v.mod; }   // v & 0xFF
bool nonzero(const Num& v) { return v.sat != 0; }

bool parseTokNum(const Tok& t, Num& o) { return parseNum(t.s, t.n, o); }

int hexVal(char c) {
    if (c >= '0' && c <= '9') return c - '0';
    if (c >= 'a' && c <= 'f') return c - 'a' + 10;
    if (c >= 'A' && c <= 'F') return c - 'A' + 10;
    return -1;
}

// bytes.fromhex(tok)[:maxLen].ljust(maxLen, b'\0') — chuỗi hex lẻ hoặc ký tự lạ → false (như ValueError).
bool hexField(const Tok& t, uint8_t* out, uint8_t maxLen) {
    if (t.n % 2) return false;
    memset(out, 0, maxLen);
    for (size_t i = 0; i < t.n; i += 2) {
        const int hi = hexVal(t.s[i]), lo = hexVal(t.s[i + 1]);
        if (hi < 0 || lo < 0) return false;
        if (i / 2 < maxLen) out[i / 2] = static_cast<uint8_t>(hi << 4 | lo);
    }
    return true;
}

} // namespace

Kind encodeCmd(const char* line, uint8_t room, uint8_t out[MAX_FRAME], uint8_t& outLen, uint8_t& stateRoom) {
    Tok p[MAX_TOKENS];
    const uint8_t n = split(line, p);
    outLen = 0;
    if (n == 0) return K_NONE;

    if (eq(p[0], "STATE")) {
        // STATE room qid open n_options bitmap   (V3.7 cũ: không có n_options)
        if (n < 5) return K_NONE;
        Num r, qid, op;
        if (!parseTokNum(p[1], r) || !parseTokNum(p[2], qid) || !parseTokNum(p[3], op)) return K_NONE;
        uint8_t nOptions = 0;
        const Tok* bitmap = &p[4];
        if (n >= 6) {
            Num no;
            if (!parseTokNum(p[4], no)) return K_NONE;
            nOptions = no.neg ? 0 : static_cast<uint8_t>(no.sat > 10 ? 10 : no.sat);
            bitmap = &p[5];
        }
        out[0] = MAGIC; out[1] = F_STATE; out[2] = low8(r); out[3] = low8(qid);
        out[4] = nonzero(op) ? 1 : 0; out[5] = nOptions;
        if (!hexField(*bitmap, &out[6], 13)) return K_NONE;
        outLen = 19;
        stateRoom = out[2];
        return K_STATE;
    }
    if (eq(p[0], "ATTEND")) {
        if (n < 2) return K_NONE;
        out[0] = MAGIC; out[1] = F_ATTEND; out[2] = room;
        if (!hexField(p[1], &out[3], 13)) return K_NONE;
        outLen = 16;
        return K_ATTEND;
    }
    if (eq(p[0], "OPENMASK")) {
        if (n < 2) return K_NONE;
        out[0] = MAGIC; out[1] = F_OPENMASK; out[2] = room;
        if (!hexField(p[1], &out[3], 8)) return K_NONE;
        outLen = 11;
        return K_OPENMASK;
    }
    if (eq(p[0], "ACKS")) {
        // ACKS ghế:câu:seq:đáp_án:status,...  hoặc  ACKS -   (bản ghi 4 trường cũ = status 0).
        // Giữ MAX_ACK_RECORDS bản ghi MỚI nhất (cuối danh sách); một số sai trong bản ghi đủ trường → bỏ cả lệnh.
        uint8_t rec[MAX_ACK_RECORDS][5];
        uint8_t count = 0;                                // tổng số bản ghi hợp lệ (vòng tròn, giữ 4 cuối)
        if (n >= 2 && !eq(p[1], "-")) {
            const char* s = p[1].s;
            const char* end = p[1].s + p[1].n;
            while (s <= end) {
                const char* comma = s;
                while (comma < end && *comma != ',') ++comma;
                // tách item [s, comma) theo ':'
                Tok f[6];
                uint8_t nf = 0;
                const char* a = s;
                for (const char* c = s; c <= comma; ++c) {
                    if (c == comma || *c == ':') {
                        if (nf < 6) f[nf] = {a, static_cast<size_t>(c - a)};
                        ++nf;
                        a = c + 1;
                    }
                }
                if (nf == 4 || nf == 5) {
                    uint8_t r5[5] = {0, 0, 0, 0, 0};
                    for (uint8_t k = 0; k < nf; ++k) {
                        Num v;
                        if (!parseTokNum(f[k], v)) return K_NONE;
                        r5[k] = low8(v);
                    }
                    memcpy(rec[count % MAX_ACK_RECORDS], r5, 5);
                    ++count;
                }
                s = comma + 1;
            }
        }
        const uint8_t keep = count < MAX_ACK_RECORDS ? count : MAX_ACK_RECORDS;
        out[0] = MAGIC; out[1] = F_ACKS; out[2] = room; out[3] = keep;
        for (uint8_t i = 0; i < keep; ++i) {
            const uint8_t idx = static_cast<uint8_t>((count - keep + i) % MAX_ACK_RECORDS);
            memcpy(&out[4 + i * 5], rec[idx], 5);
        }
        outLen = static_cast<uint8_t>(4 + keep * 5);
        return K_ACKS;
    }
    return K_NONE;
}

size_t uplinkJson(const uint8_t* d, size_t len, const uint8_t mac[6], int8_t rssi, char* out, size_t outSize) {
    if (len < 7 || d[0] != MAGIC || (d[1] != PKT_LOGIN && d[1] != PKT_ANSWER)) return 0;
    char ans[2] = {0, 0};
    if (d[1] == PKT_ANSWER) {
        if (d[6] >= 1 && d[6] <= 10) ans[0] = static_cast<char>('A' + d[6] - 1);
        else if (d[6] >= 'A' && d[6] <= 'J') ans[0] = static_cast<char>(d[6]);
    }
    char batt[8] = "null";
    const unsigned b = len >= 9 ? static_cast<unsigned>(d[7] | d[8] << 8) : 0;
    if (b) snprintf(batt, sizeof batt, "%u", b);
    const int w = snprintf(out, outSize,
        "{\"mac\":\"%02X:%02X:%02X:%02X:%02X:%02X\",\"room\":%u,\"type\":%u,\"id\":%u,\"q\":%u,\"seq\":%u,"
        "\"ans\":\"%s\",\"batt\":%s,\"rssi\":%d}",
        mac[5], mac[4], mac[3], mac[2], mac[1], mac[0], d[2], d[1], d[3], d[4], d[5], ans, batt, rssi);
    return (w > 0 && static_cast<size_t>(w) < outSize) ? static_cast<size_t>(w) : 0;
}

bool Dedup::seen(const uint8_t mac[6], const uint8_t* data, size_t len, uint32_t nowMs) {
    uint8_t d[UPLINK_LEN] = {0};
    memcpy(d, data, len < UPLINK_LEN ? len : UPLINK_LEN);
    for (uint8_t i = 0; i < N; ++i) {
        const Entry& e = e_[i];
        if (e.used && static_cast<uint32_t>(nowMs - e.t) < DEDUP_MS &&
            memcmp(e.mac, mac, 6) == 0 && memcmp(e.data, d, UPLINK_LEN) == 0) {
            return true;                                // không làm mới thời điểm: cửa sổ tính từ bản đầu tiên
        }
    }
    Entry& e = e_[next_];
    next_ = static_cast<uint8_t>((next_ + 1) % N);
    e.t = nowMs; e.used = 1;
    memcpy(e.mac, mac, 6);
    memcpy(e.data, d, UPLINK_LEN);
    return false;
}

} // namespace GwCodec
