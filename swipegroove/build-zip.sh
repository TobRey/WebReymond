#!/usr/bin/env bash
# Erzeugt swipegroove-godaddy.zip (installationsfertig) im übergeordneten Verzeichnis.
set -euo pipefail
cd "$(dirname "$0")"
OUT="${1:-../swipegroove-godaddy.zip}"
if [ -d frontend/node_modules ]; then
  (cd frontend && rm -f ../assets/app-*.js ../assets/main-*.css ../assets/chunk-*.js && npx vite build >/dev/null)
fi
rm -f "$OUT"
zip -qr "$OUT" . \
  -x 'frontend/node_modules/*' 'tests/e2e/node_modules/*' 'tests/e2e/package-lock.json' \
  -x 'config/config.php' 'config/*.tmp' \
  -x 'storage/logs/*' 'storage/cache/*' 'storage/covers/*' 'storage/tmp/*' 'storage/exports/*' \
  -x 'uploads/avatars/*' 'uploads/banners/*' 'uploads/backgrounds/*' \
  -x 'vendor/*' 'composer.lock' '*.DS_Store' 'build-zip.sh' '.git/*'
# Leere Verzeichnisse mit Platzhaltern sicherstellen
for d in storage/logs storage/cache storage/covers storage/tmp storage/exports uploads/avatars uploads/banners uploads/backgrounds; do
  zip -q "$OUT" "$d/.gitkeep"
done
echo "Geschrieben: $OUT ($(du -h "$OUT" | cut -f1))"
