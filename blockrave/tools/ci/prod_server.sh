#!/usr/bin/env bash
# Installiert einen echten NeoForge-Server (offizieller Installer) mit der gebauten Mod-JAR,
# startet ihn, prueft den Start und stoppt ihn wieder. Aufruf aus dem Ordner blockrave/.
set -eu
NEO=$(grep '^neo_version=' gradle.properties | cut -d= -f2)
rm -rf prodserver && mkdir prodserver && cd prodserver
curl -sSfLo installer.jar "https://maven.neoforged.net/releases/net/neoforged/neoforge/$NEO/neoforge-$NEO-installer.jar"
java -jar installer.jar --installServer > install.log 2>&1
mkdir -p mods && cp ../build/libs/blockrave-*.jar mods/
echo "eula=true" > eula.txt
printf 'level-type=minecraft\\:flat\nonline-mode=false\nspawn-protection=0\nenforce-secure-profile=false\n' > server.properties
rm -f console && mkfifo console
(tail -f console | ./run.sh nogui > server.log 2>&1) &
exec 3>console
for _ in $(seq 1 180); do grep -q 'Done (' server.log 2>/dev/null && break; sleep 2; done
echo "blockrave status" >&3
sleep 3
echo "stop" >&3
sleep 20
exec 3>&-
pkill -f "tail -f console" || true
grep -aE "BlockRave|blockrave|Exception|ERROR|Done \(" server.log | grep -av "Failed to load properties" | head -40
grep -q 'Audio-Bibliotheken geladen' server.log
grep -q 'Done (' server.log
