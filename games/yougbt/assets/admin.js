// YouGBT Admin: prüft, ob der Datenordner per Web wirklich gesperrt ist.
(function () {
  var el = document.getElementById('probe-result');
  if (!el) return;
  fetch(el.getAttribute('data-probe') + '?t=' + Date.now(), { cache: 'no-store' })
    .then(function (r) { return r.text().then(function (t) { return { status: r.status, text: t }; }); })
    .then(function (res) {
      if (res.text.indexOf('YOUGBT-PROBE-VISIBLE') !== -1) {
        el.className = 'fail';
        el.textContent = '⚠ Der Server ignoriert .htaccess: data/ ist per Web lesbar. Spielstände und Schlüssel bleiben durch die PHP-Sperrzeile trotzdem geschützt, aber prüfe die Serverkonfiguration.';
      } else {
        el.className = 'pass';
        el.textContent = '✔ Datenordner ist per Web gesperrt (HTTP ' + res.status + ')';
      }
    })
    .catch(function () { el.className = 'pass'; el.textContent = '✔ Datenordner nicht per Web erreichbar'; });
})();
