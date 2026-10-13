#include "gw_radio.h"

#include <Arduino.h>
#include <bluefruit.h>
#include <string.h>

using namespace GwCodec;

namespace GwRadio {

// ---- Lịch radio ----
// Quét 100 % (cửa sổ = chu kỳ 100 ms), thụ động. SoftDevice tự chèn các sự kiện quảng bá vào giữa.
static constexpr uint16_t SCAN_INTERVAL_UNITS = 160;      // 100 ms
static constexpr uint16_t SCAN_WINDOW_UNITS = 160;
// Quảng bá non-connectable 20 ms (nhỏ nhất cho phép); SoftDevice cộng thêm 0–10 ms ngẫu nhiên → mỗi sự kiện
// cách nhau ≤ 30 ms. Đổi nội dung mỗi SLOT_MS = 30 ms nên khe nào cũng có ít nhất 1 sự kiện (× 3 kênh).
static constexpr uint32_t ADV_INTERVAL_UNITS = 32;        // 20 ms
static constexpr uint32_t TICK_MS = 10;
static constexpr uint8_t TICKS_PER_SLOT = 3;              // SLOT = 30 ms
static constexpr int8_t ADV_TX_POWER_DBM = 8;             // nRF52840 tối đa +8 dBm: Remote xa vẫn nghe được ACK
// ACK mới (nội dung ACKS đổi): lên sóng ngay ở nhịp 10 ms kế tiếp, và trong ACK_FOCUS_MS chỉ phát ACKS — Remote V3.11
// đang quét ngay trong đợt phát 150 ms của nó và dừng phát khi nghe thấy kết quả. Sau đó ACKS xen kẽ với
// STATE/ATTEND/OPENMASK (~60 ms một lần) tới khi ingest gửi "ACKS -".
static constexpr uint32_t ACK_FOCUS_MS = 250;

#define GW_LOCK()   taskENTER_CRITICAL()
#define GW_UNLOCK() taskEXIT_CRITICAL()

// ---- Hàng đợi gói Remote (scanCallback → loop) ----
static constexpr uint8_t QN = 64;
static Uplink queue[QN];
static volatile uint8_t qHead = 0, qTail = 0;

// ---- Khe beacon (loop ghi, timer đọc) ----
static uint8_t frameData[K_COUNT][MAX_FRAME];
static uint8_t frameLen[K_COUNT] = {0, 0, 0, 0};

static volatile uint32_t stHeard = 0, stQueueDrop = 0, stAdvUpdates = 0, stAdvErrors = 0;

// ---- Bộ quảng bá (chỉ timer task đụng tới sau begin()) ----
static uint8_t advHandle = BLE_GAP_ADV_SET_HANDLE_NOT_SET;
static uint8_t advBuf[2][BLE_GAP_ADV_SET_DATA_SIZE_MAX];  // SoftDevice cần bộ đệm MỚI khi đổi dữ liệu lúc đang phát
static uint8_t advBufIdx = 0;
static uint8_t curLen = 0;
static uint8_t curData[MAX_FRAME];
static volatile bool advOn = false;
static uint8_t phase = 0;
static uint8_t otherIdx = 0;
static uint8_t tickN = 0;
static volatile bool ackKick = false;                     // ACKS vừa đổi: phát ngay, không chờ hết khe
static volatile uint32_t ackFocusUntil = 0;
static SoftwareTimer slotTimer;

static void scanCallback(ble_gap_evt_adv_report_t* report) {
    uint8_t msd[32];
    const uint8_t len = Bluefruit.Scanner.parseReportByType(report, BLE_GAP_AD_TYPE_MANUFACTURER_SPECIFIC_DATA, msd, sizeof msd);
    // [company LE16][A5][type < 0x10 = gói Remote]; beacon (0x10..) của gateway khác bị bỏ qua.
    if (len >= 2 + 2 && (msd[0] | msd[1] << 8) == COMPANY_ID && msd[2] == MAGIC && msd[3] < F_STATE) {
        stHeard++;
        GW_LOCK();
        const uint8_t next = static_cast<uint8_t>((qHead + 1) % QN);
        if (next == qTail) {
            stQueueDrop++;
        } else {
            Uplink& u = queue[qHead];
            memcpy(u.mac, report->peer_addr.addr, 6);
            u.rssi = report->rssi;
            u.len = static_cast<uint8_t>(len - 2 < UPLINK_LEN ? len - 2 : UPLINK_LEN);
            memcpy(u.data, &msd[2], u.len);
            qHead = next;
        }
        GW_UNLOCK();
    }
    Bluefruit.Scanner.resume();                    // SoftDevice v6 dừng quét sau mỗi báo cáo
}

bool takeUplink(Uplink& u) {
    bool ok = false;
    GW_LOCK();
    if (qTail != qHead) {
        u = queue[qTail];
        qTail = static_cast<uint8_t>((qTail + 1) % QN);
        ok = true;
    }
    GW_UNLOCK();
    return ok;
}

void setFrame(Kind kind, const uint8_t* data, uint8_t len) {
    if (kind < 0 || kind >= K_COUNT) return;
    if (len > MAX_FRAME) len = MAX_FRAME;
    GW_LOCK();
    const bool changed = len != frameLen[kind] || memcmp(frameData[kind], data, len) != 0;
    memcpy(frameData[kind], data, len);
    frameLen[kind] = len;
    if (kind == K_ACKS && changed && len > 4) {
        ackKick = true;
        ackFocusUntil = millis() + ACK_FOCUS_MS;
    }
    GW_UNLOCK();
}

void clearFrames() {
    GW_LOCK();
    for (uint8_t k = 0; k < K_COUNT; ++k) frameLen[k] = 0;
    GW_UNLOCK();
}

static void advStop() {
    if (advOn) {
        sd_ble_gap_adv_stop(advHandle);
        advOn = false;
    }
}

// Ghi frame vào bộ đệm quảng bá kế tiếp: flags + Manufacturer Data (mã công ty + frame) — tối đa 3+4+24 = 31 byte.
static bool advPublish(const uint8_t* frame, uint8_t len) {
    uint8_t* b = advBuf[advBufIdx];
    advBufIdx ^= 1;
    uint8_t n = 0;
    b[n++] = 2; b[n++] = BLE_GAP_AD_TYPE_FLAGS; b[n++] = BLE_GAP_ADV_FLAG_BR_EDR_NOT_SUPPORTED;
    b[n++] = static_cast<uint8_t>(1 + 2 + len); b[n++] = BLE_GAP_AD_TYPE_MANUFACTURER_SPECIFIC_DATA;
    b[n++] = COMPANY_ID & 0xFF; b[n++] = COMPANY_ID >> 8;
    memcpy(&b[n], frame, len);
    n = static_cast<uint8_t>(n + len);

    ble_gap_adv_data_t data;
    memset(&data, 0, sizeof data);
    data.adv_data.p_data = b;
    data.adv_data.len = n;

    uint32_t err;
    if (advOn) {
        err = sd_ble_gap_adv_set_configure(&advHandle, &data, NULL);   // đổi dữ liệu khi đang phát
    } else {
        ble_gap_adv_params_t p;
        memset(&p, 0, sizeof p);
        p.properties.type = BLE_GAP_ADV_TYPE_NONCONNECTABLE_NONSCANNABLE_UNDIRECTED;
        p.interval = ADV_INTERVAL_UNITS;
        p.duration = 0;                                                 // phát tới khi dừng
        p.primary_phy = BLE_GAP_PHY_1MBPS;
        p.filter_policy = BLE_GAP_ADV_FP_ANY;
        err = sd_ble_gap_adv_set_configure(&advHandle, &data, &p);
        if (err == NRF_SUCCESS) {
            sd_ble_gap_tx_power_set(BLE_GAP_TX_POWER_ROLE_ADV, advHandle, ADV_TX_POWER_DBM);
            err = sd_ble_gap_adv_start(advHandle, BLE_CONN_CFG_TAG_DEFAULT);
            if (err == NRF_SUCCESS) advOn = true;
        }
    }
    if (err != NRF_SUCCESS) { stAdvErrors++; return false; }
    stAdvUpdates++;
    return true;
}

// Mỗi TICK_MS; đổi khe mỗi TICKS_PER_SLOT nhịp, hoặc ngay khi có ACK mới. Có ACK → trong ACK_FOCUS_MS chỉ ACKS,
// sau đó ACKS mỗi khe thứ hai; còn lại xoay STATE → ATTEND → OPENMASK.
static void slotTick(TimerHandle_t) {
    uint8_t lens[K_COUNT];
    bool kick;
    uint32_t focusUntil;
    GW_LOCK();
    memcpy(lens, frameLen, sizeof lens);
    kick = ackKick;
    ackKick = false;
    focusUntil = ackFocusUntil;
    GW_UNLOCK();
    if (!kick && ++tickN < TICKS_PER_SLOT) return;
    tickN = 0;

    const bool ack = lens[K_ACKS] > 4;                              // "ACKS -" (0 bản ghi) = không phát ACK
    bool others = false;
    for (uint8_t k = K_STATE; k <= K_OPENMASK; ++k) others = others || lens[k];
    const bool focus = (int32_t)(focusUntil - millis()) > 0;

    int8_t kind = K_NONE;
    if (ack && (focus || !others || (phase++ & 1) == 0)) {
        kind = K_ACKS;
    } else {
        for (uint8_t i = 0; i < 3 && kind == K_NONE; ++i) {
            const uint8_t k = static_cast<uint8_t>((otherIdx + i) % 3);
            if (lens[k]) { kind = static_cast<int8_t>(k); otherIdx = static_cast<uint8_t>((k + 1) % 3); }
        }
    }
    if (kind == K_NONE) { advStop(); curLen = 0; return; }

    uint8_t frame[MAX_FRAME];
    uint8_t len;
    GW_LOCK();
    len = frameLen[kind];
    memcpy(frame, frameData[kind], len);
    GW_UNLOCK();
    if (!len) return;
    if (advOn && len == curLen && memcmp(frame, curData, len) == 0) return;   // không đổi gì (chỉ 1 khe đang phát)
    if (advPublish(frame, len)) { memcpy(curData, frame, len); curLen = len; }
}

void begin() {
    Bluefruit.autoConnLed(false);
    Bluefruit.begin();
    Bluefruit.setName("ARS_GATEWAY");

    Bluefruit.Scanner.setRxCallback(scanCallback);
    Bluefruit.Scanner.setInterval(SCAN_INTERVAL_UNITS, SCAN_WINDOW_UNITS);
    Bluefruit.Scanner.useActiveScan(false);
    Bluefruit.Scanner.start(0);

    slotTimer.begin(TICK_MS, slotTick);
    slotTimer.start();
}

void stats(Stats& s, bool reset) {
    GW_LOCK();
    s.heard = stHeard; s.queueDrop = stQueueDrop; s.advUpdates = stAdvUpdates; s.advErrors = stAdvErrors;
    s.advertising = advOn;
    if (reset) { stHeard = 0; stQueueDrop = 0; stAdvUpdates = 0; stAdvErrors = 0; }
    GW_UNLOCK();
}

} // namespace GwRadio
