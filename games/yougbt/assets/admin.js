// YouGBT Admin: prüft, ob gespeicherte Dateien per Web wirklich nichts preisgeben.
// Getestet wird eine Datei im selben Format wie Spielstände/Konfiguration (.php mit Sperrzeile).
(function () {
  var el = document.getElementById('probe-result');
  if (!el) return;
  fetch(el.getAttribute('data-probe') + '?t=' + Date.now(), { cache: 'no-store' })
    .then(function (r) { return r.text().then(function (t) { return { status: r.status, text: t }; }); })
    .then(function (res) {
      if (res.text.indexOf('YOUGBT-PROBE-VISIBLE') !== -1) {
        el.className = 'fail';
        el.textContent = '⚠ PHP wird im Ordner data/ nicht ausgeführt und Dateien sind lesbar. Bitte Hosting-Support fragen, ob PHP aktiv ist.';
      } else {
        el.className = 'pass';
        el.textContent = '✔ Gespeicherte Daten sind per Web nicht lesbar (HTTP ' + res.status + ')';
      }
    })
    .catch(function () { el.className = 'pass'; el.textContent = '✔ Gespeicherte Daten sind per Web nicht lesbar'; });
})();
