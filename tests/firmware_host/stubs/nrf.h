#pragma once
#include <cstdint>
struct FicrMock {uint32_t DEVICEID[2]={1,2};};
inline FicrMock fakeFicr;
#define NRF_FICR (&fakeFicr)
// Repo: bộ tạo số ngẫu nhiên phần cứng và phát hiện VBUS (firmware/ARS_remote_gatt setup()).
struct RngMock {uint32_t CONFIG=0, EVENTS_VALRDY=1, TASKS_START=0, TASKS_STOP=0, VALUE=0x5A;};
inline RngMock fakeRng;
#define NRF_RNG (&fakeRng)
#define RNG_CONFIG_DERCEN_Msk 1u
struct PowerMock {uint32_t USBREGSTATUS=0;};      // 0 = chạy pin (không cắm USB): không chờ Serial
inline PowerMock fakePower;
#define NRF_POWER (&fakePower)
#define POWER_USBREGSTATUS_VBUSDETECT_Msk 1u
