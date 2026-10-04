#!/usr/bin/env bash
# Baut die fertige Upload-ZIP inkl. Anthropic-SDK (vendor/), damit auf dem
# Hosting kein Composer nötig ist.
#   Aufruf:  ./build.sh   →  build/gleiche-hirnzelle.zip
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
OUT="$HERE/build"
APP="$OUT/gleiche-hirnzelle"

rm -rf "$OUT"
mkdir -p "$APP"

# Quelldateien kopieren (ohne lokale Spielstände und vendor)
cd "$HERE/src"
tar --exclude='./vendor' --exclude='./data/rooms' \
    --exclude='./data/*.php' -cf - . | (cd "$APP" && tar -xf -)

# Abhängigkeiten installieren (PHP-8.1-kompatibel, ohne Dev-Pakete)
cd "$APP"
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress --ignore-platform-req=ext-*
rm -f composer.lock

# vendor verschlanken und sperren
find vendor -type d \( -name .git -o -name tests -o -name Tests -o -name docs -o -name .github \) -prune -exec rm -rf {} +
find vendor -type f \( -name '*.md' ! -name 'LICENSE*' -o -name 'phpunit.xml*' -o -name '.gitattributes' -o -name '.gitignore' \) -delete
cp "$APP/lib/.htaccess" vendor/.htaccess

# Kein Schlüssel darf mitgeliefert werden
if grep -q "sk-ant-[A-Za-z0-9]" config.php; then
  echo "FEHLER: config.php enthält einen API-Key!" >&2
  exit 1
fi

chmod -R u+rwX,go+rX,go-w "$APP"
chmod 755 "$APP/data"

cd "$OUT"
zip -qr -X gleiche-hirnzelle.zip gleiche-hirnzelle
echo "Fertig: $OUT/gleiche-hirnzelle.zip ($(du -h gleiche-hirnzelle.zip | cut -f1))"
