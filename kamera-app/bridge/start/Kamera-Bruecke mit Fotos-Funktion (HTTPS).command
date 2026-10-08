#!/bin/bash
# Wie "Kamera-Bruecke starten", zusätzlich mit HTTPS – nötig für "In Fotos sichern" über das Teilen-Menü.
cd "$(dirname "$0")" || exit 1
if ! command -v python3 >/dev/null 2>&1; then
  echo "Python 3 fehlt. macOS bietet die Installation gleich an (Befehlszeilentools)."
  xcode-select --install 2>/dev/null
  read -r -p "Enter zum Schliessen …"
  exit 1
fi
python3 kamera-bruecke.py --https "$@"
read -r -p "Enter zum Schliessen …"
