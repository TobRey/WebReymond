# Fehlerbehebung

## Der Installer erscheint nicht / weisse Seite

* PHP-Version pruefen (cPanel → MultiPHP Manager): mindestens 8.1.
* Liegt `index.php` wirklich direkt in `public_html`? Manche ZIP-Programme legen einen
  Unterordner an.
* Ist `.htaccess` mit hochgeladen worden? Versteckte Dateien im File Manager einblenden
  (**Settings → Show Hidden Files**).

## "Die Anwendung ist noch nicht installiert und install.php fehlt."

Nur beim Paket **mit** Assistent: `install.php` wurde geloescht, bevor die Installation
abgeschlossen war. Die Datei aus der ZIP erneut hochladen und den Assistenten durchlaufen.

## "Die automatische Einrichtung konnte keine Dateien anlegen."

Nur beim Paket **ohne** Assistent. Die Anwendung darf im Zielverzeichnis nichts schreiben.

1. Im File Manager die Ordner `storage/`, `uploads/` und `app/` markieren.
2. **Permissions** → **755** (Haken "Recurse into subdirectories" setzen), Dateien **644**.
3. Die Seite neu laden.

Bleibt die Meldung, ist meist der Kontospeicher voll (cPanel → **Disk Usage**).

## KI-Schluessel wird nicht mehr angenommen, Diagnose meldet "nicht entschluesselbar"

Die Datei `app/config.local.php` wurde geloescht oder ersetzt, dadurch gibt es einen neuen
App-Schluessel. Den API-Schluessel unter **Adminbereich → KI** einfach neu eintragen und
speichern. Konten, Spielstaende und Faelle sind davon nicht betroffen.

## Fehler 500 nach dem Hochladen

* Rechte pruefen: Verzeichnisse 755, Dateien 644.
* `storage/logs/app.log` im File Manager oeffnen - dort steht die letzte Fehlermeldung.
* Falls der Hoster `mod_rewrite` nicht bereitstellt, funktioniert nur `index.php` direkt.
  In dem Fall beim Support nachfragen (auf GoDaddy-cPanel ist `mod_rewrite` Standard).

## Jede Adresse zeigt die 404-Seite des Spiels ("Zugriff gestoert")

Die Seite stammt vom Spiel selbst, PHP laeuft also. Gepruefte Reihenfolge:

0. **Schnellster Weg:** die Datei `pfad-test.php` (liegt dem Projekt unter `tools/` bei)
   neben die `index.php` legen und im Browser oeffnen. Sie zeigt die Pfade des Servers,
   den gespeicherten und den tatsaechlich verwendeten Basispfad. Danach wieder loeschen.
1. **Adminbereich → Diagnose → Umgebung → "Basispfad".** Steht dort ein Unterordner, obwohl
   das Spiel im Hauptverzeichnis liegt (oder umgekehrt), war `base_path` in
   `app/config.local.php` falsch. Ab Version 1.0.0 verwendet die Anwendung in dem Fall den
   selbst erkannten Pfad; der Eintrag kann dort korrigiert oder auf `''` gesetzt werden.
2. **Diagnose → "mod_rewrite".** Fehlt das Modul, erreicht nur `index.php` selbst die
   Anwendung. Beim Hoster aktivieren lassen.
3. **Diagnose → "Apache-Konfiguration (.htaccess)".** Viele Dateimanager und
   Upload-Werkzeuge uebertragen Dateien mit einem Punkt am Anfang nicht. Die Anwendung
   legt die Datei in dem Fall beim naechsten Aufruf selbst aus `app/Data/htaccess.dist`
   an; klappt das nicht, den Ordner auf 755 setzen oder `htaccess.dist` von Hand nach
   `.htaccess` kopieren. Versteckte Dateien sieht man im File Manager unter
   **Settings → Show Hidden Files**.
4. Ist der Adminbereich selbst nicht erreichbar, `app/config.local.php` im File Manager
   oeffnen und `'base_path' => ''` eintragen (bzw. `'/unterordner'`), dann neu laden.

## Alle Links fuehren auf die Startseite / 404 im Unterordner

Der Basispfad steht in `app/config.local.php` (`base_path`). Beim Umzug in einen Unterordner
dort z. B. `'base_path' => '/spiel'` eintragen.

Bei einer Neuinstallation erkennt die Anwendung den Unterordner selbst - auch dann, wenn
die oeffentliche Adresse anders heisst als der Ordner auf der Platte (z. B. Ordner
`public_html/games/spiel`, erreichbar unter `/spiel`). Die mitgelieferte
`.htaccess` enthaelt bewusst kein festes `RewriteBase`; wurde die Zeile von Hand ergaenzt,
muss sie zum Unterordner passen (`RewriteBase /spiel/`) oder wieder entfernt werden.

## Anmeldung nicht moeglich: "Konto voruebergehend gesperrt"

Nach mehreren Fehlversuchen greift die Sperre (Standard 15 Minuten). Ein Administrator kann
sie unter **Spieler → Bearbeiten → Kontosperre aufheben** sofort loesen.

## Admin-Passwort vergessen

1. Datei `storage/users/_index.json` oeffnen und die ID des Kontos heraussuchen.
2. Die zugehoerige Datei `storage/users/<id>.json` oeffnen.
3. Den Wert von `password_hash` durch einen neuen bcrypt-Hash ersetzen. Einen Hash erzeugt
   man z. B. mit einer kleinen PHP-Datei:
   `<?php echo password_hash('NeuesPasswort', PASSWORD_DEFAULT);`
   Diese Hilfsdatei danach wieder loeschen.
4. Alternativ: `must_change_password` auf `true` setzen und ein bekanntes Passwort eintragen.

## Die NPCs antworten immer gleich / sehr knapp

Das ist der Offline-Modus. Er reagiert auf Schluesselwoerter. Konkrete Fragen nach Uhrzeiten,
Orten und Personen funktionieren am besten. Fuer freie Gespraeche im Adminbereich unter **KI**
einen Anbieter einrichten (siehe `KI-ANBIETER.md`).

## "Zu viele Anfragen. Bitte kurz warten."

Das Rate-Limit greift. Standard: 15 Chatnachrichten pro Minute, 120 API-Aufrufe pro Minute.
Anpassbar unter **Einstellungen → Sicherheit**.

## KI-Test meldet "Modell oder Basis-URL nicht gefunden (404)"

Der Modellname stimmt nicht mehr. Kostenlose Modelle werden von den Anbietern regelmaessig
ausgetauscht - aktuellen Namen aus der Anbieterkonsole uebernehmen.

## KI-Test meldet "Der API-Schluessel wurde abgelehnt (401/403)"

Schluessel neu erzeugen und eintragen. Achtung: keine Leerzeichen am Anfang oder Ende.

## Keine Toene

* In den Spieleinstellungen die Lautstaerke pruefen.
* Browser starten Ton erst nach der ersten Interaktion - einmal irgendwo klicken.
* Die Audiodateien werden beim ersten Abruf serverseitig berechnet und danach unter
  `storage/cache/audio/` zwischengespeichert. Ist das Verzeichnis nicht beschreibbar, wird
  jedes Mal neu berechnet (langsamer, aber funktionsfaehig).

## Spracheingabe (Mikrofon) fehlt

Die Web-Speech-API gibt es nicht in allen Browsern. In Chrome, Edge und Safari funktioniert
sie; in Firefox ist sie standardmaessig deaktiviert. Das Spiel bleibt ohne Spracheingabe
vollstaendig bedienbar - der Knopf ist dann ausgegraut.

## Bilder werden nicht angezeigt

* Im Fall-Editor pruefen, ob der Pfad stimmt: `assets/img/...` oder `uploads/media/...`.
* Die Konsistenzpruefung im Editor meldet fehlende Dateien.
* Uploads werden ueber `/medien/<datei>` ausgeliefert - dafuer muss eine Anmeldung bestehen.

## Der Fall laesst sich nicht veroeffentlichen

Die Konsistenzpruefung meldet Fehler (rot). Erst nach dem Beheben ist die Veroeffentlichung
moeglich. Typisch: fehlende Loesung bei einem Raetsel, unbekannte Beweis-ID, fehlende Datei.

## Spielstand haengt / Raetsel bleibt gesperrt

Im Spiel unter **Einstellungen → Fall neu starten**. Administratoren koennen unter
**Spieler → Fortschritt loeschen** den Stand eines Kontos zuruecksetzen.

## Diagnose meldet "Verzeichnis nicht beschreibbar"

Im File Manager Rechte auf 755 setzen (Ordner) bzw. 644 (Dateien). Auf manchen Hostern muss
zusaetzlich der Eigentuemer stimmen - dann hilft der Support des Hosters.
