#!/usr/bin/env bash
# Cài server ARS lên Ubuntu 22.04 / 24.04 (chạy lại được để cập nhật bản mới, không mất dữ liệu).
#   cd ars-server && sudo bash deploy/install_ubuntu.sh
# Kết quả:
#   - mã nguồn ở /opt/ars-server, cấu hình /opt/ars-server/config.ini (mật khẩu DB tự sinh)
#   - web: http://<máy>/gv (giáo viên)  http://<máy>/hs (học sinh)  — trong LAN còn có tên cố định http://ars.local/…
#   - dịch vụ systemd: ars-ingest (luôn chạy), ars-ble (Bluetooth laptop ⇄ Remote nRF52840), ars-mdns (tên ars.local)
#     (ars-bridge cho gateway nRF52840 cắm USB được cài sẵn nhưng tắt — bật bằng: sudo ars gateway usb)
#   - MQTT: 1883 trong máy, WebSocket /mqtt qua Apache
#   - link Internet cố định (tuỳ chọn): sudo ars public on   (Tailscale Funnel)
set -euo pipefail

SRC="$(cd "$(dirname "$0")/.." && pwd)"
DST=/opt/ars-server
SVC_USER=ars
[ "$(id -u)" -eq 0 ] || { echo "Chạy bằng sudo: sudo bash deploy/install_ubuntu.sh"; exit 1; }
say() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
HAS_SYSTEMD=0; [ -d /run/systemd/system ] && HAS_SYSTEMD=1

say "1/7 Cài gói (Apache + PHP, MariaDB, Mosquitto, Python)"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq apache2 libapache2-mod-php php-cli php-mysql php-zip php-xml php-mbstring php-curl poppler-utils \
    mariadb-server mosquitto mosquitto-clients avahi-daemon avahi-utils \
    python3 python3-paho-mqtt python3-pymysql python3-serial bluez rfkill rsync >/dev/null
# thư viện Bluetooth (bleak): gói Ubuntu, không có thì cài bằng pip
apt-get install -y -qq python3-bleak >/dev/null 2>&1 || pip3 install -q --break-system-packages bleak 2>/dev/null || pip3 install -q bleak

say "2/7 Chép mã nguồn vào $DST"
id -u $SVC_USER >/dev/null 2>&1 || useradd --system --home "$DST" --shell /usr/sbin/nologin $SVC_USER
usermod -aG dialout,bluetooth $SVC_USER       # Bluetooth của máy (ars-ble) · cổng USB gateway (/dev/ttyACM*)
getent group $SVC_USER >/dev/null && usermod -aG $SVC_USER www-data
mkdir -p "$DST"
rsync -a --delete --exclude config.ini --exclude .gateway-usb --exclude .git --exclude '__pycache__' "$SRC"/ "$DST"/
chown -R root:root "$DST"; chmod -R a+rX "$DST"

say "3/7 Khởi động MariaDB và Mosquitto"
if [ $HAS_SYSTEMD = 1 ]; then
    systemctl enable --now mariadb mosquitto >/dev/null
else                                           # máy không có systemd (container, WSL cũ)
    pgrep -x mariadbd >/dev/null || pgrep -x mysqld >/dev/null || { mkdir -p /run/mysqld; chown mysql:mysql /run/mysqld; (mysqld_safe >/dev/null 2>&1 &); sleep 5; }
    pgrep -x mosquitto >/dev/null || mosquitto -d -c /etc/mosquitto/mosquitto.conf
fi

say "4/7 Tạo CSDL và tài khoản"
CFG="$DST/config.ini"
if [ -f "$CFG" ]; then
    DBPASS=$(sed -n 's/^password *= *//p' "$CFG" | head -1)
    echo "   giữ nguyên config.ini đã có"
else
    DBPASS=$(head -c 18 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 20)
    sed -e "s/^password = .*/password = $DBPASS/" -e "s#^serial = .*#serial = /dev/ttyACM0#" \
        "$DST/config.ini.example" > "$CFG"
    echo "   đã tạo config.ini (mật khẩu DB ngẫu nhiên)"
fi
chown root:$SVC_USER "$CFG"; chmod 640 "$CFG"        # chỉ root, dịch vụ ars và Apache (www-data ∈ nhóm ars) đọc được
mysql < "$DST/db/schema.sql"
mysql ars < "$DST/db/migrate_002_hocsinh.sql"
mysql ars < "$DST/db/migrate_003_baigiang_ai.sql"
mysql ars < "$DST/db/migrate_004_link_nhieu_gv_ketnoi.sql"
mysql ars < "$DST/db/migrate_005_lms.sql"
mysql ars < "$DST/db/migrate_006_school_cloud_rewards.sql"
mysql ars < "$DST/db/migrate_007_oauth.sql"
mysql ars < "$DST/db/migrate_008_student_selfsignup.sql"
mysql ars < "$DST/db/migrate_009_language.sql"
mysql ars < "$DST/db/migrate_010_tutor_chats.sql"
mysql ars < "$DST/db/migrate_011_set_finalize.sql"
mysql ars < "$DST/db/migrate_012_nrf_only.sql"

# thư mục lưu file bài giảng (ngoài /opt để cập nhật mã không xoá mất)
UPL=/var/lib/ars/uploads
mkdir -p "$UPL"; chown www-data:$SVC_USER "$UPL"; chmod 2770 "$UPL"
grep -q '^\[storage\]' "$CFG" || printf '\n[storage]\n; file bài giảng giáo viên tải lên\nuploads = %s\n' "$UPL" >> "$CFG"
for H in localhost 127.0.0.1; do             # PHP/Python nối qua TCP 127.0.0.1 hoặc socket
  mysql -e "CREATE USER IF NOT EXISTS 'ars'@'$H' IDENTIFIED BY '$DBPASS';
            ALTER USER 'ars'@'$H' IDENTIFIED BY '$DBPASS';
            GRANT ALL PRIVILEGES ON ars.* TO 'ars'@'$H'; FLUSH PRIVILEGES;"
done

# tài khoản MQTT cho gateway nRF52840 nối qua trình duyệt (giữ nguyên nếu đã có)
if ! grep -q '^\[mqtt_public\]' "$CFG"; then
    MQPASS=$(head -c 18 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 16)
    printf '\n[mqtt_public]\n; gateway nRF52840 cắm vào máy giáo viên, nối về máy chủ qua trình duyệt (WebSocket /mqtt)\nuser = clicker\npassword = %s\nws_path = /mqtt\n' "$MQPASS" >> "$CFG"
fi
grep -q '^\[lan\]' "$CFG" || printf '\n[lan]\n; tên cố định trong mạng nội bộ (mDNS), đổi bằng: sudo ars lan-name ten.local\nname = ars.local\n' >> "$CFG"
ini() { awk -v s="[$1]" -v k="$2" '$0==s{f=1;next} /^\[/{f=0} f && $1==k {sub(/^[^=]*= */,""); print; exit}' "$CFG"; }
MQUSER=$(ini mqtt_public user); MQPASS=$(ini mqtt_public password); LANNAME=$(ini lan name)
mysql ars -e "INSERT INTO settings (k,v) VALUES ('lan_name','$LANNAME') ON DUPLICATE KEY UPDATE v=VALUES(v)"

say "4b/7 Mosquitto: WebSocket /mqtt cho gateway nối qua trình duyệt"
cp "$DST/deploy/mosquitto-ars.conf" /etc/mosquitto/conf.d/ars.conf
rm -f /etc/mosquitto/ars_passwd
mosquitto_passwd -b -c /etc/mosquitto/ars_passwd "$MQUSER" "$MQPASS" 2>/dev/null
printf 'user %s\ntopic readwrite ars/#\n' "$MQUSER" > /etc/mosquitto/ars_acl
chown mosquitto:mosquitto /etc/mosquitto/ars_passwd /etc/mosquitto/ars_acl 2>/dev/null || true
chmod 640 /etc/mosquitto/ars_passwd /etc/mosquitto/ars_acl
if [ $HAS_SYSTEMD = 1 ]; then systemctl restart mosquitto
else pkill -x mosquitto 2>/dev/null || true; sleep 1; mosquitto -d -c /etc/mosquitto/mosquitto.conf; fi
sleep 1
if mosquitto_pub -h 127.0.0.1 -p 1883 -t ars/_test -m ok 2>/dev/null; then
    echo "   MQTT: OK (WebSocket /mqtt dùng tài khoản $MQUSER)"
else echo "   ! MQTT chưa chạy — xem: journalctl -u mosquitto"; fi

say "5/7 Cấu hình Apache: /gv, /hs, /ars, /mqtt"
a2enmod -q proxy proxy_http proxy_wstunnel >/dev/null
cp "$DST/deploy/apache-ars.conf" /etc/apache2/conf-available/ars.conf
a2enconf -q ars
if [ $HAS_SYSTEMD = 1 ]; then systemctl enable -q apache2; systemctl restart apache2   # restart: www-data nhận nhóm ars
else apachectl -k stop 2>/dev/null || true; sleep 1; apachectl -k start; fi

say "6/7 Dịch vụ ingest (luôn chạy) và ars-ble (Bluetooth của máy chủ)"
if [ $HAS_SYSTEMD = 1 ]; then
    cp "$DST"/deploy/ars-ingest.service "$DST"/deploy/ars-ble.service "$DST"/deploy/ars-bridge.service /etc/systemd/system/
    systemctl daemon-reload
    systemctl enable -q bluetooth 2>/dev/null || true
    systemctl enable -q ars-ingest
    systemctl restart ars-ingest
    if systemctl is-enabled -q ars-bridge 2>/dev/null && [ -f "$DST/.gateway-usb" ]; then systemctl restart ars-bridge   # đã chọn gateway USB
    else systemctl disable -q --now ars-bridge 2>/dev/null || true; systemctl enable -q ars-ble; systemctl restart ars-ble; fi
else
    echo "   (không có systemd) tự chạy: sudo -u $SVC_USER python3 $DST/ingest/ingest.py &"
fi
install -m 755 "$DST/deploy/ars" /usr/local/bin/ars
chmod 755 "$DST/deploy/ars-mdns.sh"
if [ $HAS_SYSTEMD = 1 ]; then
    systemctl enable -q --now avahi-daemon 2>/dev/null || true
    cp "$DST/deploy/ars-mdns.service" /etc/systemd/system/
    systemctl daemon-reload; systemctl enable -q ars-mdns; systemctl restart ars-mdns
fi

say "7/7 Tường lửa"
if command -v ufw >/dev/null && ufw status | grep -q "Status: active"; then
    ufw allow 80/tcp >/dev/null && echo "   đã mở cổng 80 cho điện thoại học sinh trong mạng LAN"
    ufw allow 5353/udp >/dev/null && echo "   đã mở mDNS (tên $LANNAME)"
else
    echo "   ufw không bật — bỏ qua"
fi

IP=$(hostname -I 2>/dev/null | awk '{print $1}')
PUB=$(mysql -N ars -e "SELECT v FROM settings WHERE k='public_url'" 2>/dev/null || true)
echo
echo "────────────────────────────────────────────────────────────"
echo " Xong. Link cố định (nhiều giáo viên, nhiều học sinh dùng cùng lúc):"
echo "   Giáo viên : http://$LANNAME/gv   hoặc  http://${IP:-<IP-máy>}/gv    (ngay trên máy chủ: http://localhost/gv)"
echo "   Học sinh  : http://$LANNAME/hs   hoặc  http://${IP:-<IP-máy>}/hs"
if [ -n "$PUB" ]; then echo "   Internet  : $PUB/gv   ·   $PUB/hs"
else echo "   Internet  : chưa bật — chạy  sudo ars public on  để có link https cố định dùng ở mọi nơi"; fi
echo " Lệnh tiện ích: ars links | ars status | ars logs | ars watch | ars db | ars sim 30 | ars public on"
echo " Remote nRF52840: máy chủ nghe bằng Bluetooth của chính nó (dịch vụ ars-ble, room = [gateway] room trong $CFG)."
echo "          Kiểm tra Remote phát đúng gói: sudo ars ble   ·   dùng gateway nRF52840 cắm USB thay thế: sudo ars gateway usb"
echo "────────────────────────────────────────────────────────────"
