# Updates einspielen

1. Sicherung: `config/config.php`, `storage/`, `uploads/` und ein Datenbank-Dump (cPanel → phpMyAdmin → Export).
2. Neues `swipegroove-godaddy.zip` in den Installationsordner hochladen und entpacken, bestehende Dateien ersetzen.
3. `config/config.php`, `storage/` und `uploads/` bleiben erhalten (sie sind nicht im ZIP enthalten).
4. Erste Anfrage nach dem Update führt ausstehende Migrationen aus (`migrations/NNN_*.php`, Tabelle `schema_migrations`). Fortschritt steht in `storage/logs/app-*.log`.
5. Adminbereich → *Übersicht* zeigt Version und PHP-Version.

Eine Neuinstallation oder erneute Eingabe von Zugangsdaten ist nicht nötig. Der Besitzerstatus bleibt erhalten (`platform_owner`).

## Version 1.1.0 – Social-Redesign

- Neues helles Design («Aurora»), Feed mit Beiträgen von Personen, denen man folgt, Profil mit Raster, Suche nach Personen und Playlists.
- Ohne Konto stöbern: veröffentlichte Playlists, öffentliche Profile und Kommentare sind lesbar; Liken, Kommentieren, Folgen und Nachrichten verlangen eine Anmeldung.
- Migration `002_open_social` öffnet die Registrierung (Betriebsmodus «Öffentlich») und setzt lesbare Dateirechte für bestehende Uploads (Profilbild/Banner/Hintergrund luden auf Shared-Hosting nicht, weil Dateien mit 0640 gespeichert wurden). Schliessen lässt sich die Registrierung jederzeit unter Admin → Einstellungen.
- Wiedergabe direkt auf der Seite (siehe `FUNKTIONEN-UND-EINSCHRAENKUNGEN.md`, Punkt 6). Aktivieren: Admin → Einstellungen → Wiedergabe → «Auf der Seite abspielen» → Speichern. Nutzer bestätigen beim ersten Abspielen einmalig die zusätzlichen Spotify-Berechtigungen.
- Schriften werden selbst ausgeliefert (keine Google-Fonts-Anfragen).

## Version 1.2.0 – Spulen, Groove-Modus

- Mini-Player: Fortschrittsleiste zum Spulen (z. B. auf 1:30), Tasten −15 s / +15 s, Zeitanzeige. Auf dem Handy werden Titel und Position über Spotify Connect nachgeladen.
- Swipen: Startpunkt pro Karte wählbar (ab 0:00 / 0:30 / 1:00).
- Playlist-Play spielt die ganze Playlist direkt bei Spotify ab (auch sehr lange Playlists); fehlt eine gespeicherte Songliste, startet der erste Song statt «Keine abspielbaren Songs». Leere veröffentlichte Songlisten werden beim nächsten Öffnen der Playlist durch den Besitzer automatisch nachgezogen.
- Groove-Modus: Während Musik läuft, pulsiert die Seite im Takt. Spotify stellt neuen Apps keine Beat-Daten mehr bereit, und SwipeGroove analysiert kein Audio – das Tempo stellt man ein, indem man im Mini-Player ein paar Mal im Takt auf das Cover tippt (Standard 112 BPM). Bei «Bewegung reduzieren» im System ist die Animation aus.
- Hintergrundbild im Profil entfernt (nur noch Profilbild und Banner).
