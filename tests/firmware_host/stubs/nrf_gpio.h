#pragma once
#include <cstdint>
#define NRF_GPIO_PIN_MAP(p,n) ((p)*32+(n))
inline bool gpioHigh[64]={};
inline void nrf_gpio_cfg_output(uint32_t){}
inline void nrf_gpio_cfg_default(uint32_t){}
inline void nrf_gpio_pin_set(uint32_t p){gpioHigh[p]=true;}
inline void nrf_gpio_pin_clear(uint32_t p){gpioHigh[p]=false;}
#define NRF_GPIO_PIN_NOPULL 0
inline void nrf_gpio_cfg_input(uint32_t,int){}
