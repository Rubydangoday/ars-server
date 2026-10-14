#!/usr/bin/env bash
# Chạy toàn bộ test trên MariaDB + PHP tạm, KHÔNG đụng CSDL thật.
#   bash tests/run_local.sh            # dùng thư mục tạm mới, tự dọn khi xong
#   KEEP=1 bash tests/run_local.sh     # giữ máy chủ tạm chạy để xem tay (in đường dẫn)
# Cần: mariadb-server (mariadbd, mariadb-install-db), php-cli 8.1+, python3-requests, python3-bs4, python3-pymysql.
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/.." && pwd)
W=$(mktemp -d /tmp/ars-test.XXXXXX)
DBPORT=${DBPORT:-3407}; WEBPORT=${WEBPORT:-8091}
cleanup() {
  [ -n "${KEEP:-}" ] && { echo "Giữ máy chủ tạm: http://127.0.0.1:$WEBPORT/  (thư mục $W)"; return; }
  # PHP_CLI_SERVER_WORKERS tạo tiến trình con: dừng cả cha lẫn con
  if [ -f "$W/php.pid" ]; then pid=$(cat "$W/php.pid"); pkill -P "$pid" 2>/dev/null || true; kill "$pid" 2>/dev/null || true; fi
  [ -f "$W/m.pid" ] && kill "$(cat "$W/m.pid")" 2>/dev/null || true
  sleep 1; rm -rf "$W"
}
trap cleanup EXIT

for port in "$DBPORT" "$WEBPORT" 8092 8093 8094; do
  if (exec 3<>"/dev/tcp/127.0.0.1/$port") 2>/dev/null; then echo "Cổng $port đang bận (lần chạy trước chưa tắt?). Đổi DBPORT/WEBPORT hoặc tắt tiến trình đó."; exit 1; fi
done
mkdir -p "$W/db" "$W/up"
mariadb-install-db --no-defaults --datadir="$W/db" --auth-root-authentication-method=normal --skip-test-db >/dev/null
mariadbd --no-defaults --datadir="$W/db" --socket="$W/m.sock" --port="$DBPORT" --bind-address=127.0.0.1 --pid-file="$W/m.pid" >"$W/m.log" 2>&1 &
for _ in $(seq 1 30); do mariadb -S "$W/m.sock" -uroot -e 'select 1' >/dev/null 2>&1 && break; sleep 1; done
M="mariadb -S $W/m.sock -uroot"
$M < "$ROOT/db/schema.sql"
for f in migrate_002_hocsinh migrate_003_baigiang_ai migrate_004_link_nhieu_gv_ketnoi migrate_005_lms migrate_006_school_cloud_rewards migrate_007_oauth migrate_008_student_selfsignup migrate_009_language migrate_010_tutor_chats migrate_011_set_finalize migrate_012_nrf_only migrate_013_no_facebook migrate_014_attendance_parent_msgs migrate_015_cloud_autosync migrate_016_parent_phone_login migrate_017_teacher_verified; do $M ars < "$ROOT/db/$f.sql"; done
$M -e "CREATE USER ars@'127.0.0.1' IDENTIFIED BY 'arspw'; GRANT ALL ON ars.* TO ars@'127.0.0.1';"

cat > "$W/test.ini" <<EOF
[db]
host = 127.0.0.1
port = $DBPORT
name = ars
user = ars
password = arspw
[storage]
uploads = $W/up
[oauth_endpoints]
google_auth = http://127.0.0.1:8094/google/auth
google_token = http://127.0.0.1:8094/google/token
google_userinfo = http://127.0.0.1:8094/google/userinfo
facebook_auth = http://127.0.0.1:8094/facebook/auth
facebook_token = http://127.0.0.1:8094/facebook/token
facebook_userinfo = http://127.0.0.1:8094/facebook/me
EOF
export ARS_CONFIG="$W/test.ini" TEST_BASE="http://127.0.0.1:$WEBPORT/"
# Nhiều worker: "Sync to Cloud" gọi lại chính server thử
(cd "$ROOT/web" && PHP_CLI_SERVER_WORKERS=4 php -S "127.0.0.1:$WEBPORT" >"$W/php.log" 2>&1 & echo $! >"$W/php.pid")
sleep 1

cd "$ROOT"
python3 tests/http_smoke.py >"$W/smoke.json"
printf '%-24s %s\n' http_smoke "$(python3 -c "import json;print(json.load(open('$W/smoke.json'))['passed'])")"
for t in ingest_integration ble_scan_unit gateway_codec_test bridge_unit gatt_unit attendance_integration parent_accounts_integration teacher_verify_integration extended_integration v11_integration oauth_integration student_signup_integration i18n_integration tutor_integration; do
  python3 "tests/$t.py" "$W/smoke.json" >"$W/$t.json"
  printf '%-24s %s\n' "$t" "$(python3 -c "import json;print(json.load(open('$W/$t.json'))['passed'])")"
done
# firmware Remote GATT chạy trên máy tính với GPIO/BLE giả (cần g++)
if command -v g++ >/dev/null; then
  bash tests/firmware_host/run.sh >"$W/fw_host.txt" 2>&1 && printf '%-24s %s\n' firmware_host_gatt OK || { cat "$W/fw_host.txt"; exit 1; }
fi
n=$(grep -ciE 'php (warning|fatal|deprecated)' "$W/php.log" || true)
echo "Cảnh báo PHP: $n"
[ "$n" = 0 ]
