#!/usr/bin/env bash
# Gom thông tin card Bluetooth + log để phân tích radio ARS (Bước 2). Chạy trên laptop test:
#   sudo bash tools/ble_diag_collect.sh            # chỉ thông tin card + log dịch vụ
#   sudo bash tools/ble_diag_collect.sh 120        # + ghi btmon 120 s (làm bài test trong lúc đó)
# Kết quả: ~/ars_ble_diag_<giờ>.tar.gz — gửi kèm log Serial của Remote.
set -u
SECS=${1:-0}
OUT=$(eval echo "~${SUDO_USER:-$USER}")/ars_ble_diag_$(date +%Y%m%d_%H%M%S)
mkdir -p "$OUT"
run() { echo "\$ $*" >>"$OUT/system.txt"; timeout 10 "$@" >>"$OUT/system.txt" 2>&1; echo >>"$OUT/system.txt"; }

run uname -a
run bluetoothd -v
run lsusb
run hciconfig -a
run btmgmt info
run btmgmt advinfo
run busctl get-property org.bluez /org/bluez/hci0 org.bluez.LEAdvertisingManager1 SupportedInstances ActiveInstances SupportedSecondaryChannels
run rfkill list bluetooth
awk '/^\[LE\]/{f=1;print;next} /^\[/{f=0} f && !/^#/ && NF' /etc/bluetooth/main.conf >"$OUT/main_conf_LE.txt" 2>&1
cp /opt/ars-server/config.ini "$OUT/config.ini" 2>/dev/null && sed -i 's/^\(password\s*=\).*/\1 ***/' "$OUT/config.ini"
journalctl -u ars-ble -u ars-ingest --since "30 min ago" --no-pager >"$OUT/journal_30min.txt" 2>&1

if [ "$SECS" -gt 0 ] 2>/dev/null; then
  echo "Ghi btmon $SECS s — làm bài test ngay bây giờ…"
  timeout "$SECS" btmon -w "$OUT/btmon.snoop" >/dev/null 2>&1
  journalctl -u ars-ble -u ars-ingest --since "-$((SECS + 10))s" --no-pager >"$OUT/journal_test.txt" 2>&1
  # Tóm tắt nhanh từ btmon: lệnh bật/tắt quét và quảng bá, số báo cáo quảng bá nhận được
  btmon -r "$OUT/btmon.snoop" 2>/dev/null | grep -E "LE Set (Extended )?(Scan|Advertising)( Set)? Enable|LE Set (Extended )?Advertising (Parameters|Data)|Number of reports|LE Extended Advertising Report|LE Advertising Report" \
    | sed 's/^[[:space:]]*//' | cut -c1-80 | sort | uniq -c | sort -rn | head -20 >"$OUT/btmon_summary.txt"
fi

chown -R "${SUDO_USER:-$USER}" "$OUT" 2>/dev/null
tar -czf "$OUT.tar.gz" -C "$(dirname "$OUT")" "$(basename "$OUT")" && rm -rf "$OUT"
echo "Đã lưu: $OUT.tar.gz"
