#!/usr/bin/env bash
# Mehrspieler-Ende-zu-Ende-Test (CI): echter NeoForge-Server mit der gebauten JAR + zwei echte
# Clients (Xvfb, Software-OpenGL, OpenAL "null"-Ausgabe). Aufruf aus dem Ordner blockrave/,
# nachdem tools/ci/prod_server.sh den Server installiert hat.
set -u
ROOT=$(pwd)
SRV="$ROOT/prodserver"
E2E="$ROOT/runs/e2e"
command -v xvfb-run >/dev/null || { sudo apt-get update -qq && sudo apt-get install -y -qq xvfb libgl1-mesa-dri libglx-mesa0 >/dev/null; }
XVFB=(xvfb-run -a -s "-screen 0 854x480x24")
rm -rf "$E2E" && mkdir -p "$E2E"
tools/ci/client_options.sh runs/e2eA runs/e2eA2

start_server() {
  cd "$SRV"
  rm -f console && mkfifo console
  (tail -f console | ./run.sh nogui > "$1" 2>&1) &
  exec 3>console
  for _ in $(seq 1 150); do grep -q 'Done (' "$1" 2>/dev/null && break; sleep 2; done
  cd "$ROOT"
}

stop_server() {
  echo "stop" >&3 || true
  sleep 20
  exec 3>&- || true
  pkill -f "tail -f console" || true
}

# frische Welt, beide Testspieler als Operator (Offline-UUIDs wie Minecraft sie berechnet)
rm -rf "$SRV/world"
python3 - > "$SRV/ops.json" <<'PY'
import hashlib, json, uuid
ops = []
for name in ("RaveDJ", "RaveGast"):
    u = uuid.UUID(bytes=hashlib.md5(("OfflinePlayer:" + name).encode()).digest(), version=3)
    ops.append({"uuid": str(u), "name": name, "level": 4, "bypassesPlayerLimit": False})
print(json.dumps(ops))
PY
printf 'level-type=minecraft\\:flat\nonline-mode=false\nspawn-protection=0\nenforce-secure-profile=false\n' > "$SRV/server.properties"

start_server "$ROOT/server_e2e_1.log"

# zweite Arbeitskopie fuer Client B (zwei Gradle-Laeufe parallel brauchen getrennte Projektordner)
rsync -a --exclude build --exclude runs --exclude prodserver --exclude .gradle --exclude core-check/build ./ ../blockraveB/
(cd ../blockraveB && tools/ci/client_options.sh runs/e2eB)

"${XVFB[@]}" ./gradlew runE2eClientA --no-daemon --console=plain -Pe2eDir="$E2E" > clientA.log 2>&1 &
PID_A=$!
for _ in $(seq 1 300); do [ -f "$E2E/playing" ] && break; kill -0 $PID_A 2>/dev/null || break; sleep 2; done
echo "Client A spielt (oder ist beendet) – starte Client B (spaeter Beitritt)"
(cd ../blockraveB && "${XVFB[@]}" ./gradlew runE2eClientB --no-daemon --console=plain -Pe2eDir="$E2E" > "$ROOT/clientB.log" 2>&1) &
PID_B=$!
wait $PID_B
wait $PID_A
pkill -f DevLaunch || true
stop_server

echo "=== Server-Neustart ==="
start_server "$ROOT/server_e2e_2.log"
"${XVFB[@]}" ./gradlew runE2eClientA2 --no-daemon --console=plain -Pe2eDir="$E2E" > clientA2.log 2>&1
stop_server

pkill -f DevLaunch || true
pkill -f Xvfb || true
status=0
for f in clientA.log clientB.log clientA2.log; do
  echo "=== $f ==="
  grep -aE "BlockRave-E2E|BlockRave:|Exception|FATAL" "$f" | grep -avE "DEBUG|flite|Narrator|resource path" | tail -45
  grep -aq "ERGEBNIS: alle Pruefungen bestanden" "$f" || status=1
done
echo "=== Server ==="
grep -ahE "BlockRave|Exception|ERROR" server_e2e_1.log server_e2e_2.log | grep -avE "Failed to load properties|DEBUG|resource path" | tail -40
exit $status
