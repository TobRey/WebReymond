#!/usr/bin/env bash
# Legt fuer automatisierte Client-Laeufe eine options.txt an (keine Einfuehrungsbildschirme, ruhige Kamera).
for d in "$@"; do
  mkdir -p "$d"
  printf 'onboardAccessibility:false\nskipMultiplayerWarning:true\npauseOnLostFocus:false\njoinedFirstServer:true\nrenderDistance:6\nsimulationDistance:6\nmaxFps:30\nbobView:false\nfov:0.0\n' > "$d/options.txt"
done
