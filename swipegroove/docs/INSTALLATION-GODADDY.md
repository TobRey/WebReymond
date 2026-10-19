# SwipeGroove – Installation auf GoDaddy cPanel

Kurzanleitung für die Installation aus `swipegroove-godaddy.zip`. Es wird kein SSH, kein Docker und kein dauerhaft laufender Node-Prozess benötigt.

## Voraussetzungen

- Hosting mit **PHP 8.2 oder neuer** (empfohlen: 8.4, in cPanel unter *MultiPHP Manager* wählbar)
- PHP-Erweiterungen: `pdo_mysql`, `curl`, `gd`, `sodium`, `mbstring`, `json` (bei GoDaddy standardmässig aktiv; prüfbar unter *Select PHP Version* bzw. *MultiPHP INI Editor*)
- Eine **MySQL/MariaDB-Datenbank** (cPanel → *MySQL Databases*)
- HTTPS für die Domain (Spotify akzeptiert nur `https://`-Redirect-URIs; cPanel → *SSL/TLS Status* → AutoSSL)
- Apache mit `mod_rewrite` (bei GoDaddy aktiv)

## 1. Dateien hochladen

1. cPanel → *File Manager* → Zielordner öffnen:
   - Domain-Root: z. B. `public_html` (für `https://swipe-groove.com`)
   - oder ein Unterordner: z. B. `public_html/groove` (für `https://example.com/groove`)
2. `swipegroove-godaddy.zip` hochladen und dort **entpacken** (Rechtsklick → *Extract*). Die Datei `index.php` muss danach direkt im Zielordner liegen.
3. ZIP-Datei anschliessend löschen.
4. Rechte: Ordner `config/`, `storage/` (inkl. Unterordner) und `uploads/` müssen vom Webserver beschreibbar sein (bei GoDaddy in der Regel bereits der Fall; sonst `755` für Ordner).

Hinweis: Die mitgelieferten `.htaccess`-Dateien sperren `app/`, `config/`, `storage/`, `migrations/`, `cron/`, `lang/` und `tests/` vor Webzugriff und verhindern die Ausführung von Skripten in `uploads/`.

## 2. Datenbank anlegen

cPanel → *MySQL Databases*:

1. Datenbank erstellen (z. B. `xyz_swipegroove`)
2. Benutzer erstellen, sicheres Passwort notieren
3. Benutzer der Datenbank mit **ALL PRIVILEGES** zuweisen
4. Host ist bei GoDaddy meist `localhost`

## 3. Installationsassistent ausführen

1. Im Browser die Basis-URL aufrufen (z. B. `https://swipe-groove.com/` oder `https://example.com/groove/`). Die Anwendung leitet automatisch zu `/install` weiter.
2. Ausfüllen:
   - **Basis-URL** (wird erkannt; inkl. Unterordner, ohne Schrägstrich am Ende)
   - **Datenbank**: Host, Name, Benutzer, Passwort
   - **Spotify Client-ID** (siehe `EXTERNE-FREIGABEN.md`; kann auch später im Adminbereich nachgetragen werden)
   - optional: OpenAI-API-Schlüssel, Stripe-Angaben
   - **Einrichtungsschlüssel**: wird vorgeschlagen – **jetzt notieren**, er wird nach der Besitzer-Registrierung ungültig
3. *Installieren* klicken. Die Tabellen werden angelegt und `config/config.php` geschrieben. Der Installer ist danach gesperrt.
4. Die Abschlussseite zeigt die exakten Werte für:
   - Spotify **Redirect-URI**: `https://…/auth/spotify/callback`
   - Stripe **Webhook-URL**: `https://…/webhooks/stripe`
   - Cron-Befehl

**Wichtig:** Führe die Installation direkt nach dem Upload aus. Solange `config/config.php` fehlt, ist der Installer erreichbar.

## 4. Besitzer registrieren

1. `https://…/setup` öffnen, Einrichtungsschlüssel eingeben, *Weiter mit Spotify*.
2. Bei Spotify anmelden und SwipeGroove freigeben.
3. Benutzername/Anzeigename wählen. Dieses Konto ist automatisch **Besitzer**, **Admin** und hat **dauerhaft Premium ohne Cover-Limit**.
4. Der Einrichtungsschlüssel ist jetzt ungültig; die Registrierung ist danach offen (Betriebsmodus *Öffentlich*). Schliessen lässt sie sich im Adminbereich unter *Einstellungen*.

## 5. Cronjob einrichten

cPanel → *Cron Jobs* → neuen Job anlegen, stündlich (`0 * * * *`):

```
/usr/local/bin/php /home/<cpanel-benutzer>/public_html/<ordner>/cron/run.php > /dev/null 2>&1
```

Der genaue Pfad zur PHP-CLI steht in cPanel (häufig `/usr/local/bin/php` oder `/opt/alt/php84/usr/bin/php`). Alternativ per URL (der Schlüssel steht in `config/config.php` unter `app.cron_key`):

```
curl -s "https://…/cron/run?key=<cron_key>" > /dev/null
```

Der Cron bereinigt abgelaufene Sessions, OAuth-Zustände, Rate-Limit-Zähler, Spotify-Zwischenspeicher, alte Feed-Kandidaten, verwaiste Cover-Reservierungen und alte Protokolle.

## 6. Integrationen hinterlegen oder nachtragen

Adminbereich → *Integrationen*: Spotify Client-ID, OpenAI-Schlüssel, Stripe-Schlüssel/Price-ID/Webhook-Secret können jederzeit ergänzt werden. Sie werden in `config/config.php` gespeichert und im Adminbereich nur maskiert angezeigt.

## 7. Betriebsmodus

Adminbereich → *Einstellungen*:
- **Öffentlich** (Standard nach Setup): Registrierung offen. Im Spotify Development Mode können sich trotzdem nur die im Spotify-Dashboard freigeschalteten Konten (max. 5) anmelden – siehe `EXTERNE-FREIGABEN.md`.
- **Geschlossene Beta**: Registrierung geschlossen; bestehende Konten können sich weiter anmelden.

## Updates

1. Neue ZIP entpacken und **über die bestehende Installation kopieren** (alle Dateien ersetzen).
2. **Nicht** überschreiben bzw. erhalten: `config/config.php`, `storage/`, `uploads/`.
3. Ausstehende Datenbank-Migrationen laufen beim nächsten Seitenaufruf automatisch (versionsbasiert, `migrations/`). Keine Neuinstallation, keine erneute Eingabe von Zugangsdaten.

## Fehlersuche

- Weisse Seite / Fehler 500: `storage/logs/app-YYYY-MM.log` und `storage/logs/php-error.log` prüfen.
- „Spotify ist noch nicht konfiguriert“: Client-ID im Adminbereich hinterlegen.
- Spotify-Fehler „INVALID_CLIENT: Invalid redirect URI“: Redirect-URI im Spotify-Dashboard muss **exakt** der angezeigten URL entsprechen (inkl. Unterordner, https).
- Nutzer kann sich nicht anmelden: Im Development Mode muss die Spotify-E-Mail des Nutzers im Spotify-Dashboard unter *User Management* freigeschaltet sein (max. 5 Nutzer).
