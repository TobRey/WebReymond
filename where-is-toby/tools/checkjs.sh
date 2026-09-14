#!/bin/sh
# Syntaxpruefung fuer ES-Module ohne Build-Schritt
status=0
for file in "$@"; do
  if ! node --input-type=module --check < "$file" 2>/tmp/jscheck.err; then
    echo "FEHLER in $file:"
    head -5 /tmp/jscheck.err
    status=1
  fi
done
[ $status -eq 0 ] && echo "JS-Syntax OK ($# Datei(en))"
exit $status
