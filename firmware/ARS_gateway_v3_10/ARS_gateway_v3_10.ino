// ARS Gateway V3.10 — nRF52840 SuperMini cắm USB vào laptop chạy server ARS.
//
// Không có logic nghiệp vụ (mọi kiểm tra ở ingest.py):
//   Lên:   gói Remote nghe được → lọc bản lặp → 1 dòng JSON qua USB (bridge/bridge.py → MQTT ars/<room>/resp)
//   Xuống: 1 dòng lệnh từ USB (STATE / ATTEND / OPENMASK / ACKS) → beacon cho Remote (định dạng bridge/blecodec.py)
// Dòng bắt đầu bằng '#' là log — bridge.py bỏ qua. Giao thức: README mục 4.1–4.2b, firmware/PROTOCOL_V3_10.md.
//
// Lệnh tay qua Serial monitor (dừng ars-bridge trước): h = trợ giúp, v = phiên bản, s = thống kê ngay,
// d = bật/tắt in mọi bản gói nghe được (kể cả bản lặp, gói PROBE).

#include <Arduino.h>
#include <nrf_gpio.h>
#include "gw_codec.h"
#include "gw_radio.h"

using namespace GwCodec;

static const char* FW_VERSION = "ARS-GW 3.11";

// Đèn xanh dương có sẵn trên SuperMini (P0.15, sáng khi HIGH). Board khác: đổi chân này.
static constexpr uint32_t GW_LED_PIN = NRF_GPIO_PIN_MAP(0, 15);

// Server phát lại toàn bộ trạng thái mỗi 5 s ([ingest] state_refresh_s). Không nhận lệnh nào trong
// STALE_MS → coi như mất server (bridge dừng, rút cáp, laptop treo): ngừng phát beacon để Remote không
// thấy một câu "đang mở" đã cũ.
static constexpr uint32_t STALE_MS = 15000;
static constexpr uint32_t STAT_MS = 5000;

static char lineBuf[256];
static uint16_t lineLen = 0;
static bool lineOverflow = false;

static bool roomKnown = false;
static uint8_t room = 0;
static uint32_t lastCmdMs = 0;
static bool serverAlive = false;
static bool diagAll = false;

static Dedup dedup;
static uint32_t cFwd = 0, cDup = 0, cCmd = 0, cBad = 0, cWait = 0, cUsbDrop = 0, cProbe = 0;
static constexpr uint8_t PKT_PROBE = 3;                   // gói đo của Remote (Serial 'u'): chỉ đếm, không gửi server
static uint32_t lastStatMs = 0;
static uint32_t ledBlinkUntil = 0;

static void logLine(const char* s) {
    if (!Serial) return;                           // không có ai mở cổng: bỏ, không chặn loop()
    Serial.print("# ");
    Serial.println(s);
}

static void printStats(bool reset) {
    GwRadio::Stats r;
    GwRadio::stats(r, reset);
    char b[200];
    snprintf(b, sizeof b,
             "STAT rx=%lu fwd=%lu dup=%lu probe=%lu qdrop=%lu usbdrop=%lu cmd=%lu bad=%lu wait=%lu room=%d adv=%s upd=%lu adverr=%lu",
             (unsigned long)r.heard, (unsigned long)cFwd, (unsigned long)cDup, (unsigned long)cProbe, (unsigned long)r.queueDrop,
             (unsigned long)cUsbDrop, (unsigned long)cCmd, (unsigned long)cBad, (unsigned long)cWait,
             roomKnown ? room : -1, r.advertising ? "on" : "off", (unsigned long)r.advUpdates, (unsigned long)r.advErrors);
    logLine(b);
    if (reset) { cFwd = cDup = cCmd = cBad = cWait = cUsbDrop = cProbe = 0; }
}

static void handleLine(char* line) {
    // Lệnh tay một chữ (server không bao giờ gửi các dòng này).
    if (line[0] && !line[1]) {
        switch (line[0]) {
            case 'h': logLine("h=tro giup  v=phien ban  s=thong ke  d=bat/tat in moi goi nghe duoc"); return;
            case 'v': logLine(FW_VERSION); return;
            case 's': printStats(false); return;
            case 'd': diagAll = !diagAll; logLine(diagAll ? "diag ON" : "diag OFF"); return;
        }
    }
    uint8_t frame[MAX_FRAME];
    uint8_t len = 0;
    uint8_t stRoom = 0;
    const Kind k = encodeCmd(line, room, frame, len, stRoom);
    if (k == K_NONE) {
        if (line[0]) {
            cBad++;
            char b[96];
            snprintf(b, sizeof b, "BAD %.80s", line);
            logLine(b);
        }
        return;
    }
    if (k == K_STATE) {
        if (roomKnown && stRoom != room) {
            // Đổi room: các beacon khác đã mã hoá với room cũ → bỏ, chờ server gửi lại (ngay sau STATE).
            GwRadio::clearFrames();
            char b[48];
            snprintf(b, sizeof b, "ROOM %u -> %u", room, stRoom);
            logLine(b);
        }
        room = stRoom;
        roomKnown = true;
    } else if (!roomKnown) {
        cWait++;                                   // ATTEND/OPENMASK/ACKS không mang room: chờ STATE đầu tiên
        return;
    }
    GwRadio::setFrame(k, frame, len);
    cCmd++;
    lastCmdMs = millis();
    if (!serverAlive) { serverAlive = true; logLine("server ON"); }
}

static void readSerial() {
    while (Serial.available() > 0) {
        const int c = Serial.read();
        if (c < 0) break;
        if (c == '\n') {
            lineBuf[lineLen] = 0;
            if (lineLen && lineBuf[lineLen - 1] == '\r') lineBuf[--lineLen] = 0;
            if (!lineOverflow) handleLine(lineBuf);
            lineLen = 0;
            lineOverflow = false;
        } else if (lineLen < sizeof lineBuf - 1) {
            lineBuf[lineLen++] = static_cast<char>(c);
        } else {
            lineOverflow = true;                   // dòng quá dài: bỏ cả dòng
        }
    }
}

static void forwardUplinks() {
    GwRadio::Uplink u;
    char json[160];
    const uint32_t now = millis();
    while (GwRadio::takeUplink(u)) {
        const bool known = u.len >= 7 && (u.data[1] == PKT_LOGIN || u.data[1] == PKT_ANSWER);
        const bool probe = u.len >= 6 && u.data[1] == PKT_PROBE;
        const bool dup = (known || probe) && dedup.seen(u.mac, u.data, u.len, now);
        if (diagAll) {
            char b[96];
            snprintf(b, sizeof b, "RX %02X:%02X:%02X:%02X:%02X:%02X type=%u room=%u id=%u q=%u seq=%u ans=%u rssi=%d%s",
                     u.mac[5], u.mac[4], u.mac[3], u.mac[2], u.mac[1], u.mac[0], u.data[1],
                     u.len > 2 ? u.data[2] : 0, u.len > 3 ? u.data[3] : 0, u.len > 4 ? u.data[4] : 0,
                     u.len > 5 ? u.data[5] : 0, u.len > 6 ? u.data[6] : 0, u.rssi, dup ? " dup" : "");
            logLine(b);
        }
        if (probe && !dup) {
            // Đo gói lên (tools/gw_probe_report.py): mỗi seq PROBE khác nhau in đúng 1 dòng.
            cProbe++;
            char b[64];
            snprintf(b, sizeof b, "PROBE seq=%u rssi=%d", u.data[5], u.rssi);
            logLine(b);
        }
        if (!known) continue;                      // PROBE và gói lạ: không gửi server
        if (dup) { cDup++; continue; }
        const size_t n = uplinkJson(u.data, u.len, u.mac, u.rssi, json, sizeof json - 1);
        if (!n) continue;
        if (!Serial) { cUsbDrop++; continue; }
        json[n] = '\n';
        Serial.write(reinterpret_cast<const uint8_t*>(json), n + 1);
        cFwd++;
        ledBlinkUntil = now + 30;
    }
}

static void updateLed(uint32_t now) {
    // Sáng = đang phát beacon (có server); chớp tắt ngắn = vừa chuyển một gói lên; tắt = chưa có server.
    const bool on = serverAlive && (int32_t)(now - ledBlinkUntil) >= 0;
    if (on) nrf_gpio_pin_set(GW_LED_PIN); else nrf_gpio_pin_clear(GW_LED_PIN);
}

void setup() {
    nrf_gpio_cfg_output(GW_LED_PIN);
    nrf_gpio_pin_clear(GW_LED_PIN);
    Serial.begin(115200);                          // USB CDC: tốc độ thật không phụ thuộc số này
    GwRadio::begin();
    logLine(FW_VERSION);
    logLine("ready: cho lenh STATE tu server (h = tro giup)");
}

void loop() {
    readSerial();
    forwardUplinks();

    const uint32_t now = millis();
    if (serverAlive && (uint32_t)(now - lastCmdMs) > STALE_MS) {
        serverAlive = false;
        GwRadio::clearFrames();
        logLine("server OFF: khong co lenh 15 s, ngung phat beacon");
    }
    if ((uint32_t)(now - lastStatMs) >= STAT_MS) {
        lastStatMs = now;
        printStats(true);
    }
    updateLed(now);
    delay(1);
}
