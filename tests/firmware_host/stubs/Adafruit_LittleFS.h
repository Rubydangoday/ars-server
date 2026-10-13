#pragma once
#include <cstddef>
#define FILE_O_READ 1
#define FILE_O_WRITE 2
namespace Adafruit_LittleFS_Namespace {
struct File {
 template<class T>File(T&){}
 bool open(const char*,int){return false;}
 size_t read(void*,size_t){return 0;}
 size_t write(const void*,size_t){return 0;}
 void close(){}
};
}
