#!/usr/bin/env bash
# Phát tên cố định trong mạng LAN (mặc định ars.local) trỏ tới IP hiện tại của máy chủ, qua Avahi (mDNS).
# Không đổi hostname của máy. Tự phát lại khi IP đổi (đổi Wi-Fi, router cấp IP mới).
NAME="${1:-$(awk '/^\[lan\]/{f=1;next} /^\[/{f=0} f && /^name/{sub(/^name *= */,""); print; exit}' /opt/ars-server/config.ini 2>/dev/null)}"
NAME="${NAME:-ars.local}"
pid=""; cur=""
cleanup() { [ -n "$pid" ] && kill "$pid" 2>/dev/null; exit 0; }
trap cleanup TERM INT
while :; do
  ip=$(ip -4 route get 1.1.1.1 2>/dev/null | sed -n 's/.* src \([0-9.]*\).*/\1/p')
  [ -z "$ip" ] && ip=$(hostname -I 2>/dev/null | awk '{print $1}')
  if [ -n "$ip" ] && [ "$ip" != "$cur" ]; then
    [ -n "$pid" ] && kill "$pid" 2>/dev/null
    avahi-publish -a -R "$NAME" "$ip" >/dev/null 2>&1 &
    pid=$!; cur="$ip"
    echo "mDNS: $NAME → $ip"
  fi
  if [ -n "$pid" ] && ! kill -0 "$pid" 2>/dev/null; then cur=""; pid=""; fi   # avahi khởi động lại → phát lại
  sleep 15
done
