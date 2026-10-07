#!/usr/bin/env bash
# Chụp màn hình trang trên máy chủ thử (KEEP=1 bash tests/run_local.sh) bằng Firefox headless.
#   bash tools/shot.sh 1366,900 ten.png <who> <vi|en> /duong-dan.php
# <who> là khoá phiên trong web/_shot_tmp.php (file tạm, không đưa lên git).
D=$HOME/snap/firefox/common/ars-shots; mkdir -p "$D/prof"
[ -f "$D/prof/user.js" ] || echo 'user_pref("ui.prefersReducedMotion", 1);' > "$D/prof/user.js"
cd "$D" && timeout 60 firefox --headless --profile prof --window-size "$1" --screenshot "$D/$2" "http://127.0.0.1:8091/_shot_tmp.php?who=$3&l=$4&to=$5" >/dev/null 2>&1
