AI GROOVE 1.2.1
===============

NEU IN 1.2.0: Eigene KI "GrooveNet"
-----------------------------------
Die Klangerzeugung laeuft jetzt vollstaendig ueber eine eigene KI, die im
Browser rechnet. Kein Konto, kein API-Key, keine laufenden Kosten, keine
Nutzungsgrenzen - und nichts verlaesst das Geraet. Die Anbindung an
kostenpflichtige Dienste wurde vollstaendig entfernt.


1. UPLOAD
---------
1. Diese ZIP-Datei entpacken.
2. Den GESAMTEN Inhalt (index.php, sw.js, manifest.webmanifest, .htaccess,
   README.txt sowie den Ordner "assets") in den Webroot hochladen,
   also z. B. nach public_html/  (oder in einen Unterordner, z. B.
   public_html/aigroove/ - beides funktioniert).
3. Wichtig: Die versteckte Datei .htaccess im Hauptordner muss mit
   hochgeladen werden. Im cPanel-Dateimanager dazu unter "Einstellungen"
   die Option "Versteckte Dateien anzeigen" aktivieren.
4. Seite im Browser oeffnen: https://deine-domain.tld/  bzw. /aigroove/
5. Fertig. Es muss nichts konfiguriert, installiert oder eingerichtet werden.


2. BENOETIGTE PHP-VERSION
-------------------------
PHP 8.1 oder neuer (empfohlen: PHP 8.2 / 8.3).
PHP liefert nur die Startseite samt Sicherheits-Headern aus. Es werden keine
PHP-Erweiterungen ueber den Standard hinaus gebraucht, keine Datenbank und
kein Ausgang ins Internet - die KI rechnet im Browser.


3. NOTWENDIGE CPANEL-EINSTELLUNGEN
----------------------------------
- MultiPHP Manager: PHP 8.1+ fuer die Domain auswaehlen.
- SSL/TLS Status: HTTPS aktivieren (AutoSSL / Let's Encrypt).
  HTTPS ist Pflicht fuer Mikrofonaufnahme und fuer die Installation als App.
- Sonst nichts. Keine Datenbank, kein Cronjob, kein Node.js, keine
  Schreibrechte ausserhalb des Standards, keine ausgehenden Verbindungen.


4. DIE KI (GROOVENET)
---------------------
Es gibt nichts einzurichten. AI Groove erzeugt Klaenge mit einer eigenen KI,
die im Browser laeuft: keine Anmeldung, kein API-Key, keine Kosten, kein
Internet noetig - auch nicht als installierte App.

Was sie kann:
- Text zu Klang: Einzelklaenge (Kick, Snare, Hi-Hat, Bass, Pad, Riser,
  Glocke, Vocal, Impact und weitere).
- Text zu Schleife: ganze Takte mit Drums, Bass und Akkorden, passend zu
  Stil, Tempo und Tonart aus dem Prompt.
- Klang zu Klang: ein vorhandenes Sample nach Textbeschreibung umformen
  ("heller und verzerrt", "kuerzer", "rueckwaerts").

Wie sie arbeitet (in Kurzform):
1. Der Prompt wird in Woerter zerlegt und mit einem eingebauten Klanglexikon
   abgeglichen - deutsch und englisch, auch bei Tippfehlern ("snaer") und
   zusammengesetzten Woertern ("technokick").
2. Daraus entsteht ein Profil aus 16 Klangeigenschaften (Helligkeit, Wucht,
   Anschlag, Ausklang, Rauschanteil, Raum, Saettigung und weitere).
3. Die KI stellt ein Zielspektrum auf: so soll der Klang ueber Frequenz und
   Zeit aussehen.
4. Ein Suchverfahren (Differentielle Evolution) erzeugt hunderte Klang-
   kandidaten, misst jeden gegen das Ziel und verbessert die besten weiter.
5. Der beste Kandidat wird in voller Aufloesung berechnet und fertig
   aufbereitet ausgegeben.

Damit die Suche musikalisch bleibt, kennt jede Klangart ihre Grenzen und ihre
typische Abklingzeit: Eine Kick hat einen Grundton zwischen 45 und 72 Hz und
traegt rund 300 Millisekunden, eine geschlossene Hi-Hat 50, eine Glocke fast
zwei Sekunden. Sie klingt aus statt zu stehen und bekommt nur wenig
Saettigung und Hall. Wer ausdruecklich "verzerrt" oder "mit
viel Hall" schreibt, hebt die jeweilige Grenze wieder auf.

Drums, Baesse und Impacts bekommen ausserdem immer eine Tiefenanhebung - ein
satter Unterbau ist bei elektronischer Musik Grundausstattung. Hi-Hats,
Becken und Glocken bleiben davon unberuehrt.

Rechenqualitaet einstellen:
Unter "KI" laesst sich zwischen "Schnell", "Ausgewogen" (Vorgabe) und
"Beste Qualitaet" waehlen. Hoehere Stufen suchen laenger und treffen den
Prompt genauer. Die Berechnung laeuft in einem eigenen Rechenstrang, die
Oberflaeche bleibt dabei bedienbar.

Datenschutz: Prompts und Samples verlassen das Geraet nie. Es gibt keine
Anfrage an einen fremden Dienst, keine Zugangsdaten und nichts, was
protokolliert werden koennte.

5. BEKANNTE BROWSER-EINSCHRAENKUNGEN
------------------------------------
- iPhone/iPad (Safari): Ton startet erst nach der ersten Beruehrung des
  Bildschirms (Vorgabe von Apple).
  Der seitliche Stummschalter schaltete bisher den gesamten Ton stumm - ohne
  jede Meldung. Seit Fassung 1.1.2 meldet sich AI Groove als Musik-Wiedergabe
  an, dadurch wird der Schalter ignoriert (Safari 16.4 und neuer). Auf
  aelteren Fassungen muss er weiterhin ausgeschaltet sein.
  Massgeblich ist die MEDIEN-Lautstaerke: waehrend ein Klang laeuft die
  Lautstaerketasten druecken, sonst wird der Klingelton geregelt.
  Bei Tonproblemen hilft der Selbsttest unter "KI": er prueft Audioausgang,
  Erzeugung, Lesbarkeit und Wiedergabe einzeln.
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
- Die KI braucht Rechenzeit. Auf aelteren Telefonen empfiehlt sich unter
  "KI" die Stufe "Schnell". Sehr alte Browser ohne Modul-Worker rechnen im
  Hauptstrang weiter - dann reagiert die Oberflaeche waehrend der Erzeugung
  kurz traeger.
