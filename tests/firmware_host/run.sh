#!/usr/bin/env bash
set -euo pipefail
here=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
fw="$here/../../firmware/ARS_remote_gatt"
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
g++ -std=c++17 -Wall -Wextra -I"$here/stubs" -I"$fw" "$here/main.cpp" "$fw/ars_ble.cpp" "$fw/display.cpp" "$fw/status_led.cpp" -o "$tmp/firmware-host"
"$tmp/firmware-host"
