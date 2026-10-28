#!/usr/bin/env bash
# Baut release/cash.zip – Inhalt wird direkt in public_html/cash entpackt.
# Enthält nie Daten, Sessions oder config.local.php (Updates überschreiben nichts Persönliches).
set -euo pipefail
cd "$(dirname "$0")"
for f in src/app/*.php src/*.php; do php -l "$f" >/dev/null; done
tail -n +2 src/app/client.js.php > "${TMPDIR:-/tmp}/cash-check.js" && node --check "${TMPDIR:-/tmp}/cash-check.js"
mkdir -p release
rm -f release/cash.zip
(cd src && zip -X -q -r ../release/cash.zip \
  .htaccess index.php api.php manifest.webmanifest \
  app/.htaccess app/index.php app/*.php \
  data/.htaccess data/index.php \
  assets/app.css assets/icon.svg assets/icon-180.png assets/icon-512.png)
(cd release && unzip -l cash.zip && sha256sum cash.zip > cash.zip.sha256)
