#!/bin/sh
# Startet oder stoppt den lokalen Testserver.
#   tools/serve.sh start <verzeichnis> <port>
#   tools/serve.sh stop
SCRATCH="${WIT_SCRATCH:-/tmp/wit-test}"
PIDFILE="$SCRATCH/server.pid"
case "$1" in
  start)
    DIR="$2"; PORT="${3:-8787}"
    mkdir -p "$SCRATCH"
    if [ -f "$PIDFILE" ]; then kill "$(cat "$PIDFILE")" 2>/dev/null; rm -f "$PIDFILE"; sleep 1; fi
    cd "$DIR" || exit 1
    nohup php -S "127.0.0.1:$PORT" router.php > "$SCRATCH/server.log" 2>&1 &
    echo $! > "$PIDFILE"
    sleep 2
    echo "Server laeuft auf http://127.0.0.1:$PORT (PID $(cat "$PIDFILE"))"
    ;;
  stop)
    if [ -f "$PIDFILE" ]; then kill "$(cat "$PIDFILE")" 2>/dev/null; rm -f "$PIDFILE"; echo "gestoppt"; else echo "kein Server"; fi
    ;;
  *) echo "Verwendung: $0 start <verzeichnis> [port] | stop"; exit 1 ;;
esac
