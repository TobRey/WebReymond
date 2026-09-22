#!/usr/bin/env bash
# Startet Mock-Claude + App in einem verschachtelten Unterordner und führt die API-Tests aus.
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
WORK="${WORK:-$(mktemp -d)}"
WWW="$WORK/public_html"
APP="$WWW/games/party/yougbt"
rm -rf "$WWW" "$WORK"/yougbt-data-* ; mkdir -p "$APP"
cp -r "${SRC:-$HERE/../yougbt}/." "$APP/"
rm -f "$APP/storage-location.php"
echo ok > "$HERE/mock_mode.txt"; : > "$HERE/mock_calls.log"
php -S 127.0.0.1:8091 "$HERE/mock_claude.php" >/dev/null 2>&1 & MOCK=$!
YOUGBT_API_BASE=http://127.0.0.1:8091 YOUGBT_TEST_ANSWER_S=${ANSWER_S:-6} YOUGBT_TEST_REVEAL_S=${REVEAL_S:-4} \
  PHP_CLI_SERVER_WORKERS=16 php -d error_reporting=-1 -d log_errors=1 -d display_errors=0 -S 127.0.0.1:8090 -t "$WWW" >"$WORK/server.log" 2>&1 & SRV=$!
trap 'kill $MOCK $SRV 2>/dev/null || true' EXIT
sleep 1
export BASE="http://127.0.0.1:8090/games/party/yougbt/"
APP_DIR="$APP" WORK="$WORK" MOCK_DIR="$HERE" php "$HERE/api_test.php" "$@"
if [ "${KEEP:-0}" = "1" ]; then echo "Server läuft weiter (KEEP=1): $BASE"; trap - EXIT; echo "$MOCK $SRV" > "$WORK/pids"; fi
exit 0
