# YouGBT – Installation auf GoDaddy cPanel

Partyspiel: Die KI stellt als schräge Fragesteller Fragen, die Menschen antworten als „AI“, Claude bewertet.
Reines PHP, keine Datenbank, kein Node, kein Cronjob.

## Voraussetzungen
- PHP **8.0 oder neuer** (empfohlen 8.2+), mit den Erweiterungen **curl**, **mbstring**, **json**, **openssl**
  (cPanel → „Select PHP Version“ → Extensions). Bei GoDaddy meist schon aktiv.
- Ausgehende HTTPS-Verbindungen zu `api.anthropic.com` (bei GoDaddy-Shared-Hosting erlaubt).
- Ein eigener Anthropic-API-Schlüssel (console.anthropic.com).

## Upload & Entpacken
1. cPanel → **Dateimanager** → in den gewünschten Ordner wechseln, z. B. `public_html/games/`.
2. **Hochladen** → `yougbt.zip` wählen.
3. Rechtsklick auf die ZIP → **Extract** → es entsteht der Ordner `yougbt/`.
4. Die ZIP danach löschen.

Die App funktioniert in **jedem** Unterordner. Aufruf = Domain + Pfad zum Ordner, z. B.
`https://deine-domain.de/games/yougbt/` (oder `…/irgendwas/tief/yougbt/`). Alle Links und Dateien
sind relativ; es gibt keinen fest eingetragenen Domainnamen.

## Einrichtung (einmalig, ca. 2 Minuten)
1. Öffne `https://deine-domain.de/<pfad>/yougbt/admin.php`.
2. Öffne im cPanel-Dateimanager `yougbt/data/setup-code.php` (Rechtsklick → View) und kopiere den
   **Einrichtungscode**. So kann niemand Fremdes deine Installation übernehmen.
3. Trage Code, **API-Schlüssel** und ein **Admin-Passwort** (min. 10 Zeichen) ein → „einrichten“.
   Der Schlüssel wird bei Anthropic geprüft; als Modell wird das günstigste passende verfügbare Modell
   `claude-haiku-4-5` vorausgewählt.
4. Im Adminbereich kannst du später Schlüssel, Modell (Liste live von Anthropic), **Tageslimit für KI-Aufrufe**
   (Standard 400) und **max. gleichzeitige Partien** (Standard 10) ändern.

Kostenrichtwert: pro normaler Runde 2 KI-Aufrufe (1 Frage für die ganze Lobby + 1 gebündelte Bewertung),
pro Spezialrunde ca. 4. Hinweise und Polling kosten nichts.

## Sicherheit & Speicherung
- Spielstände, Konfiguration und Schlüssel liegen – wenn möglich – **außerhalb des Webroots**
  (`/home/<user>/yougbt-data-…`). Falls das Hosting das nicht erlaubt, im Ordner `yougbt/data/`,
  der per `.htaccess` gesperrt ist. Zusätzlich beginnt jede Datei mit einer PHP-Sperrzeile, sodass selbst
  bei ignorierter `.htaccess` kein Inhalt ausgeliefert wird. Der Adminbereich prüft den Webschutz live.
- Der API-Schlüssel wird nie an Browser gesendet. Alte Räume werden automatisch aufgeräumt.

## Deinstallation
Ordner `yougbt/` löschen und – falls angelegt – den Ordner `yougbt-data-…` im Home-Verzeichnis.

## Fehlerbehebung
- „cURL fehlt“ → Erweiterung im PHP-Selector aktivieren.
- „Speichern fehlgeschlagen“ → Ordner `yougbt/data` muss für PHP beschreibbar sein (Rechte 755).
- Seite lädt ohne Design → sicherstellen, dass mit abschließendem `/` aufgerufen wird bzw. `index.php`.
