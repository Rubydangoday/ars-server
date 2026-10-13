// Chạy firmware/ARS_gateway_v3_10/gw_codec.cpp trên máy tính cho tests/gateway_codec_test.py.
// Mỗi dòng stdin một yêu cầu, mỗi yêu cầu đúng một dòng stdout:
//   C <room>\t<dòng lệnh>          → "<kind> <stateRoom> <hex>"  hoặc  "-1"
//   U <mac 12 hex, byte cao trước> <rssi> <data hex>   → dòng JSON  hoặc  "NONE"
//   D <ms> <mac 12 hex> <data hex>  → "1" (bản lặp) / "0"
#include "gw_codec.h"

#include <cstdio>
#include <cstdlib>
#include <cstring>
#include <iostream>
#include <string>

using namespace GwCodec;

static size_t unhex(const std::string& s, uint8_t* out, size_t max) {
    size_t n = 0;
    for (size_t i = 0; i + 1 < s.size() && n < max; i += 2) out[n++] = static_cast<uint8_t>(std::strtoul(s.substr(i, 2).c_str(), nullptr, 16));
    return n;
}

static void macLsbFirst(const std::string& hex, uint8_t mac[6]) {
    uint8_t m[6] = {0};
    unhex(hex, m, 6);
    for (int i = 0; i < 6; ++i) mac[i] = m[5 - i];
}

int main() {
    Dedup dedup;
    std::string line;
    while (std::getline(std::cin, line)) {
        if (line.size() < 2) { std::cout << "?\n" << std::flush; continue; }
        if (line[0] == 'C') {
            const size_t tab = line.find('\t');
            const uint8_t room = static_cast<uint8_t>(std::atoi(line.substr(2, tab - 2).c_str()));
            uint8_t out[MAX_FRAME];
            uint8_t len = 0, stRoom = 0;
            const Kind k = encodeCmd(line.substr(tab + 1).c_str(), room, out, len, stRoom);
            if (k == K_NONE) { std::cout << "-1\n" << std::flush; continue; }
            std::printf("%d %u ", static_cast<int>(k), stRoom);
            for (uint8_t i = 0; i < len; ++i) std::printf("%02x", out[i]);
            std::printf("\n");
            std::fflush(stdout);
        } else if (line[0] == 'U') {
            char mac[32], data[128];
            int rssi;
            if (std::sscanf(line.c_str() + 2, "%31s %d %127s", mac, &rssi, data) != 3) data[0] = 0;
            uint8_t m[6], d[64];
            macLsbFirst(mac, m);
            const size_t n = unhex(data, d, sizeof d);
            char json[200];
            const size_t w = uplinkJson(d, n, m, static_cast<int8_t>(rssi), json, sizeof json);
            std::printf("%s\n", w ? json : "NONE");
            std::fflush(stdout);
        } else if (line[0] == 'D') {
            unsigned long ms;
            char mac[32], data[128];
            std::sscanf(line.c_str() + 2, "%lu %31s %127s", &ms, mac, data);
            uint8_t m[6], d[64];
            macLsbFirst(mac, m);
            const size_t n = unhex(data, d, sizeof d);
            std::printf("%d\n", dedup.seen(m, d, n, static_cast<uint32_t>(ms)) ? 1 : 0);
            std::fflush(stdout);
        } else {
            std::cout << "?\n" << std::flush;
        }
    }
    return 0;
}
