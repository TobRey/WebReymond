AI GROOVE 1.0.1
===============


1. UPLOAD
---------
1. Diese ZIP-Datei entpacken.
2. Den GESAMTEN Inhalt (index.php, sw.js, manifest.webmanifest, .htaccess,
   README.txt sowie die Ordner "api" und "assets") in den Webroot hochladen,
   also z. B. nach public_html/  (oder in einen Unterordner, z. B.
   public_html/aigroove/ - beides funktioniert).
3. Wichtig: Die versteckten Dateien .htaccess (im Hauptordner UND im Ordner
   "api") muessen mit hochgeladen werden. Im cPanel-Dateimanager dazu unter
   "Einstellungen" die Option "Versteckte Dateien anzeigen" aktivieren.
4. Seite im Browser oeffnen: https://deine-domain.tld/  bzw. /aigroove/
5. Fertig. Es muss nichts konfiguriert, installiert oder eingerichtet werden.


2. BENOETIGTE PHP-VERSION
-------------------------
PHP 8.1 oder neuer (empfohlen: PHP 8.2 / 8.3).
Benoetigte Erweiterungen: json (Standard) und curl (fast immer aktiv).
Ohne curl funktioniert der KI-Proxy ueber allow_url_fopen weiter.
Eine Datenbank wird NICHT benoetigt.


3. NOTWENDIGE CPANEL-EINSTELLUNGEN
----------------------------------
- MultiPHP Manager: PHP 8.1+ fuer die Domain auswaehlen.
- SSL/TLS Status: HTTPS aktivieren (AutoSSL / Let's Encrypt).
  HTTPS ist Pflicht fuer Mikrofonaufnahme und fuer die Installation als App.
- Sonst nichts. Keine Datenbank, kein Cronjob, kein Node.js, keine
  Schreibrechte ausserhalb des Standards.
- Optional: In "MultiPHP INI Editor" memory_limit auf 128M und
  max_execution_time auf 180 setzen - nur relevant, wenn der KI-Proxy
  sehr lange Audiodateien weiterreicht.


4. KI-ANBIETER EINRICHTEN
-------------------------
AI Groove funktioniert sofort und vollstaendig OHNE KI-Zugang: der eingebaute
lokale Generator erzeugt Kicks, Snares, Claps, Hats, Bass, Stabs, Riser und
FX direkt im Browser.

Fuer Modelle wie Stable Audio:
1. In AI Groove auf "KI verbinden" gehen.
2. Anbieter waehlen (Stability AI oder ElevenLabs).
3. Eigenen API-Key einfuegen (z. B. von platform.stability.ai).
4. Uebertragungsweg "Ueber eigenen Server" lassen (die meisten Anbieter
   blockieren direkte Browser-Aufrufe).
5. "Verbinden", danach "Verbindung testen".

Datenschutz: Der Key bleibt im Browser des jeweiligen Nutzers. Er wird nie auf
dem Server gespeichert, nie protokolliert und nie in .aigroove-Projektdateien
geschrieben. Der PHP-Proxy reicht ihn nur fuer die eine ausgeloeste Anfrage
weiter. Jeder Nutzer verbindet seinen eigenen Zugang - Keys werden zwischen
Nutzern nicht geteilt.


5. BEKANNTE BROWSER-EINSCHRAENKUNGEN
------------------------------------
- iPhone/iPad (Safari): Ton startet erst nach der ersten Beruehrung des
  Bildschirms (Vorgabe von Apple). Der seitliche Stummschalter muss aus sein.
- Safari (alle Geraete): OGG/Vorbis, Opus und teilweise FLAC koennen nicht
  dekodiert werden - dort WAV, MP3 oder M4A verwenden.
- Auswahl des Audio-Ausgangs funktioniert nur in Chrome/Edge, nicht in
  Safari und Firefox.
- Mikrofonaufnahme braucht HTTPS. Ueber http:// bleibt der Zugriff gesperrt.
- Privater Modus / "Private Browsing": Projekte lassen sich dort meist nicht
  lokal sichern. Projekte in dem Fall als .aigroove-Datei exportieren.
- Projekte liegen nur im Browser des Geraets (IndexedDB) und gelten nach rund
  12 Stunden ohne Bearbeitung als temporaer abgelaufen. Sie bleiben im
  Dashboard erreichbar, sollten aber exportiert werden.
- Sehr lange Arrangements (mehr als 30 Minuten) koennen nicht in einem Stueck
  exportiert werden.
- Getestet mit Safari (iOS/macOS), Chrome, Edge und Android-Chrome.
