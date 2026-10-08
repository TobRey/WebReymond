#!/bin/bash
# Kamera-Brücke starten (Mac). Fenster offen lassen, solange die App genutzt wird.
cd "$(dirname "$0")" || exit 1
if ! command -v python3 >/dev/null 2>&1; then
  echo "Python 3 fehlt. macOS bietet die Installation gleich an (Befehlszeilentools)."
  echo "Danach dieses Fenster schliessen und erneut doppelklicken."
  xcode-select --install 2>/dev/null
  read -r -p "Enter zum Schliessen …"
  exit 1
fi
python3 kamera-bruecke.py "$@"
read -r -p "Enter zum Schliessen …"
