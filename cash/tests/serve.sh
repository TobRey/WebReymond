#!/usr/bin/env bash
# Startet einen lokalen Testserver: http://127.0.0.1:8099/cash/
# Nutzung: tests/serve.sh <arbeitsverzeichnis> [quellordner, z. B. entpackte ZIP]
set -euo pipefail
WORK="${1:?Arbeitsverzeichnis angeben}"
SRC="${2:-$(cd "$(dirname "$0")/../src" && pwd)}"
mkdir -p "$WORK/www" "$WORK/data"
ln -sfn "$SRC" "$WORK/www/cash"
export CASH_DATA_DIR="$WORK/data"
export PHP_CLI_SERVER_WORKERS=8
exec php -d opcache.enable_cli=0 -S 127.0.0.1:8099 -t "$WORK/www"
