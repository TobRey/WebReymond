#!/usr/bin/env bash
# Legt fuer automatisierte Client-Laeufe eine options.txt an (keine Einfuehrungsbildschirme).
for d in "$@"; do
  mkdir -p "$d"
  printf 'onboardAccessibility:false\nskipMultiplayerWarning:true\npauseOnLostFocus:false\njoinedFirstServer:true\nrenderDistance:8\nsimulationDistance:8\nmaxFps:30\n' > "$d/options.txt"
done
