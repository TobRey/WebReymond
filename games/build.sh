#!/usr/bin/env bash
# Baut release/yougbt.zip (Ordner yougbt/ mit allen Dateien, ohne Laufzeitdaten).
set -euo pipefail
cd "$(dirname "$0")"
TMP="$(mktemp -d)"
mkdir -p "$TMP/yougbt" release
cp -r yougbt/. "$TMP/yougbt/"
rm -f "$TMP/yougbt/storage-location.php"
find "$TMP/yougbt/data" -mindepth 1 ! -name '.htaccess' ! -name 'index.html' ! -name 'probe.txt' -exec rm -rf {} +
find "$TMP/yougbt" -type d -exec chmod 755 {} + ; find "$TMP/yougbt" -type f -exec chmod 644 {} +
rm -f release/yougbt.zip
(cd "$TMP" && zip -qrX "$OLDPWD/release/yougbt.zip" yougbt)
rm -rf "$TMP"
unzip -l release/yougbt.zip
