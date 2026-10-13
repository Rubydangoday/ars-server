#pragma once
struct FilesystemMock {void begin(){}void remove(const char*){}};
inline FilesystemMock InternalFS;
