# Gleiche Hirnzelle – Installation (GoDaddy cPanel)

Dauer: ca. 5 Minuten. Keine Datenbank, kein Composer, keine Kommandozeile nötig.

## 1. Hochladen und entpacken

1. In cPanel den **Dateimanager** öffnen und in `public_html` wechseln
   (oder in einen Unterordner, z. B. `public_html/spiele`).
2. **Hochladen** → `gleiche-hirnzelle.zip` auswählen.
3. Die ZIP-Datei anklicken → **Extrahieren**.
   Es entsteht der Ordner `gleiche-hirnzelle`. Die ZIP danach löschen.

Das Spiel läuft dann unter `https://deine-domain.ch/gleiche-hirnzelle/`
(bzw. `https://deine-domain.ch/spiele/gleiche-hirnzelle/`).
Den Ordner darfst du beliebig umbenennen oder verschieben.

## 2. API-Key eintragen

1. API-Key erstellen: <https://console.anthropic.com/settings/keys>
   (Guthaben bzw. Zahlungsmittel im Anthropic-Konto hinterlegen).
2. Im Dateimanager `gleiche-hirnzelle/config.php` → **Bearbeiten**.
3. Den Key zwischen die Anführungszeichen setzen:

   ```php
   'anthropic_api_key' => 'sk-ant-...',
   ```

4. Speichern. Fertig!

Der Key bleibt auf dem Server: `config.php` ist per `.htaccess` gesperrt und
gibt beim Aufruf nichts aus. Der Browser spricht nur mit `api.php`, nie direkt
mit Anthropic.

## 3. PHP-Version prüfen

Benötigt wird **PHP 8.1 oder neuer** (empfohlen 8.2/8.3) mit den Erweiterungen
`curl`, `json`, `mbstring` und `openssl` – bei GoDaddy standardmässig aktiv.
Ändern in cPanel unter **Select PHP Version** bzw. **MultiPHP Manager**.

## 4. Spielen

Seite öffnen → **Raum erstellen** → Code, Link oder QR-Code an die zweite
Person schicken → los geht's. Am besten spielt jede Person am eigenen Handy.

## Gut zu wissen

- **Ohne API-Key / bei API-Ausfall** läuft das Spiel trotzdem weiter: Fragen
  kommen aus einem eingebauten Pool, bewertet wird nach Buchstabenähnlichkeit
  (max. 9 Punkte), Titel aus einer eingebauten Liste.
- **Speicherort:** Räume liegen als geschützte Dateien in `data/rooms/` und
  werden nach 48 Stunden Inaktivität automatisch gelöscht. Noch sicherer:
  in `config.php` bei `data_dir` einen Ordner ausserhalb von `public_html`
  angeben, z. B. `/home/DEINBENUTZER/gleiche-hirnzelle-data`.
- **Verbindungsabbruch:** Einfach die Seite neu laden oder den Einladungslink
  erneut öffnen – man landet wieder am eigenen Platz. Wechselt jemand das Gerät,
  kann der Platz nach 90 Sekunden Abwesenheit über den Link übernommen werden.
- **Fehlersuche:** Probleme mit Claude (z. B. falscher Key) stehen in
  `data/error-log.php` (im Dateimanager öffnen, nicht im Browser).
- **Kosten:** Pro Spiel ca. 21 kurze Claude-Anfragen (10 Fragen, 10 Bewertungen,
  1 Titel). Modell und Denktiefe sind in `config.php` einstellbar.
