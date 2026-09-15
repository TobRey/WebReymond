# Testprotokoll

Alle Tests laufen automatisiert gegen eine echte Installation. Stand: Version 1.0.0.

| Testlauf | Umfang | Ergebnis |
|---|---|---|
| Ende-zu-Ende (PHP/cURL), Paket mit Assistent | 189 Pruefungen | **189 bestanden, 0 fehlgeschlagen** |
| Automatische Einrichtung, Paket ohne Assistent | 165 Pruefungen | **165 bestanden, 0 fehlgeschlagen** |
| Installation in einem Unterordner | 17 Pruefungen | **17 bestanden, 0 fehlgeschlagen** |
| Basispfad-Erkennung (ohne Webserver) | 9 Serverkonstellationen | **9 bestanden, 0 fehlgeschlagen** |
| Oberflaeche (Chromium/Playwright), beide Pakete | je 24 Pruefungen inkl. Screenshots | **24 bestanden, 0 fehlgeschlagen** |
| Konsistenzpruefung Fall "Toby" | Raetsel, Beweise, Medien, Zeitachse | **0 Fehler, 0 Hinweise** |
| Paketpruefung | Pflichtdateien, keine Geheimnisse im ZIP | **bestanden** |

Alle Laeufe erfolgten gegen frisch entpackte ZIP-Pakete, nicht gegen den Arbeitsstand.

---

## 1. Was der Ende-zu-Ende-Test abdeckt

**Installation**

* Installationsassistent erreichbar, Systempruefung zeigt PHP-Version und Erweiterungen
* Installation ueber das echte Formular (kein Skript-Umweg)
* `app/config.local.php`, Sperrdatei, Fall "toby", Benutzerindex werden angelegt
* App-Schluessel (64 Hex-Zeichen) wird erzeugt
* Installer sperrt sich danach selbst (403)

**Konten**

* Admin-Login mit den Startzugangsdaten, Hinweis auf Passwortwechsel
* Passwortwechsel, Ablehnung schwacher Passwoerter, Ablehnung falscher Passwoerter
* Registrierung, doppelter Benutzername wird abgelehnt
* Gastmodus mit eigenem Spielstand

**Sicherheit**

* Direktzugriff auf `storage/`, `app/config.local.php` und `app/Data/cases/toby.json` blockiert
* POST ohne CSRF-Token wird abgelehnt (403)
* Path-Traversal ueber den Medien-Endpunkt blockiert
* Sicherheitsheader (CSP, `nosniff`) vorhanden
* Sperrregel der `.htaccess` ist am Ordneranfang verankert und kein festes `RewriteBase`
  gesetzt; eine Adresse wie `/api/case/toby/device/.../app/messages` erreicht die Anwendung
* `/index.php` fuehrt zur Startseite, ein aehnlicher Pfad (`/indexXphp`) dagegen auf 404
* ein falscher `base_path` in der Konfiguration legt die Seite nicht lahm: Start- und
  Unterseiten bleiben erreichbar, der erkannte Pfad wird verwendet
* Kein API-Schluessel im HTML, keine Loesungen und internen Felder im Quelltext
* Uploads: PHP-Datei abgelehnt, getarnte PHP-Datei mit `.png` abgelehnt, SVG wird bereinigt
  ausgeliefert, Upload ohne Rechtebestaetigung abgelehnt, Medien nur fuer angemeldete Konten

**Fall "Toby" - vollstaendiger Durchlauf im Offline-Modus**

* alle 19 Raetsel geloest (PIN, Passwort, Muster, Rekonstruktion, vier Videozeitpunkte,
  Audioanalyse, Morse, Protokollsuche, Widersprueche, Kartenzuordnung, Zeitleiste)
* falsche Antworten werden korrekt abgelehnt (Stichproben je Raetseltyp)
* gesperrte Inhalte bleiben gesperrt (Papierkorb vor der Rekonstruktion, geschuetzter Ordner)
* Bilddetails liefern Beweise, Medien werden erst nach Freischaltung ausgeliefert
* Verhoere mit allen Figuren: Luegen, Konfrontationen, Gestaendnisse, Zustandswechsel
* Horror-Ereignis "Hoer auf, mich zu suchen." wird ausgeloest und schaltet Folgeinhalte frei
* Ermittlungswand: Verbindung loest Fortschritt aus, Notizen anlegen und loeschen
* Hinweissystem: Stufen, Budget von zwei Hinweisen, Sperre danach
* Abschlussbericht: Rang S/A, bestes Ende, alle Luegen gezaehlt
* Gegenprobe mit falschem Bericht: schlechter Rang, schlechtes Ende, Falschanschuldigung gezaehlt

**Adminfunktionen**

* unbegrenzte Hinweise fuer Administratoren
* Fall exportieren, importieren, pruefen, loeschen
* Sicherung erstellen, wiederherstellen, loeschen
* KI-Konfiguration speichern, Verbindungstest, Wechsel der Betriebsart
* KI-Ausfall: NPC antwortet weiter ueber das Offline-System, keine technische Fehlermeldung
* Systemeinstellungen, Spielerverwaltung, Protokolle, Fall-Editor, Diagnose
* Belastungstest: 25 aufeinanderfolgende Schreibvorgaenge auf dieselbe Datei ohne Datenverlust

---

## 1b. Was der Test der automatischen Einrichtung abdeckt

Gilt fuer `where-is-toby-1.0.0-ohne-installer.zip`:

* `install.php` ist nicht im Paket und nicht erreichbar
* vor dem ersten Aufruf existiert keine Konfiguration
* der erste Seitenaufruf liefert die Startseite und legt dabei selbst an: Konfiguration mit
  App-Schluessel (64 Hex-Zeichen), Administratorkonto, Fall "toby", Sperrdatei
* der zweite Aufruf richtet nichts erneut ein
* Login mit den Startzugangsdaten, Hinweis auf den Passwortwechsel, Wechsel funktioniert
* Diagnose im Adminbereich meldet keine Fehler
* anschliessend der komplette Durchlauf des Falls wie unter Abschnitt 1

Zusaetzlich fuer eine Installation in einem Unterordner (`public_html/spiel`):

* der Basispfad wird erkannt, Verweise, Gestaltung, Anmeldung und API arbeiten mit Praefix
* **das Datenverzeichnis wird nicht als Nachbarordner im Webverzeichnis angelegt**, sondern
  bleibt im gesperrten Ordner `storage/`
* `storage/` ist per URL nicht erreichbar

---

## 2. Was der Oberflaechentest abdeckt

Chromium, Viewport 1600x950 und 390x844 (Smartphone):

* Startseite, Fallliste, Altersbestaetigung, Einsatzbefehl
* alle neun Bereiche rendern (Fallakte, Personen, Beweise, Geraete, Wand, Karte, Zeitleiste,
  Notizen, Bericht)
* Handy per Ziffernblock entsperren, App oeffnen, Chatverlauf lesen
* Verhoer mit Antwort der Figur
* Hinweis ueber die Gluehbirne
* Ermittlungswand: Karte anlegen und mit der Maus ziehen
* Einstellungsdialog
* Smartphone-Ansicht ohne horizontalen Ueberlauf
* Adminbereich: Dashboard, Fall-Editor mit allen Abschnitten, NPC-Formular, Diagnose
* **keine JavaScript-Fehler** in der Konsole, keine fehlgeschlagenen Anfragen

---

## 3. Tests selbst ausfuehren

Die Testskripte liegen im Projektarchiv unter `tools/` (nicht Teil des Installationspakets):

```bash
# lokalen Testserver starten (PHP-Entwicklungsserver)
cp tools/router.php /pfad/zur/installation/
tools/serve.sh start /pfad/zur/installation 8787

# Ende-zu-Ende-Test (installiert die Anwendung ueber den Assistenten)
php tools/test_e2e.php http://127.0.0.1:8787 /pfad/zur/installation

# Paket ohne Assistent: automatische Einrichtung plus kompletter Durchlauf
php tools/test_autosetup.php http://127.0.0.1:8791 /pfad/zur/installation

# Installation in einem Unterordner (Server auf dem uebergeordneten Ordner starten)
php tools/test_subfolder.php http://127.0.0.1:8792/spiel /pfad/zum/webordner/spiel

# Basispfad-Erkennung fuer verschiedene Hoster-Konstellationen (braucht keinen Server)
php tools/test_basepath.php

# Oberflaechentest inkl. Screenshots
node tools/ui/ui_test.mjs http://127.0.0.1:8787 /pfad/fuer/screenshots

# Fall neu bauen und pruefen
php tools/build_case_toby.php

# Installationspakete bauen und pruefen
php tools/build_zip.php
php tools/build_zip.php --no-installer
```

Fuer den Betrieb genuegt die **Diagnose im Adminbereich**: Sie prueft Umgebung, Schreibrechte,
Zugriffsschutz, Datenintegritaet, KI-Konfiguration und Fallqualitaet - auf Wunsch mit
Belastungstest fuer paralleles Schreiben.

---

## 4. Manuell geprueft

* Erneute Einrichtung ohne Assistent nach dem Loeschen von `app/config.local.php`: Seite und
  Konten bleiben erhalten, es wird jedoch ein neuer App-Schluessel erzeugt - ein gespeicherter
  API-Schluessel muss danach neu eingetragen werden (die Diagnose meldet das)
* Verhalten ohne die Erweiterungen `zip`, `gd` und `curl` (Rueckfallpfade greifen, Diagnose
  meldet die Einschraenkung)
* Wiederholtes Ausfuehren des Installers nach der Installation (bleibt gesperrt)
* Zuruecksetzen eines Falls im laufenden Spiel
* Tastatursteuerung (1-9, H, N, Esc) und Fokus-Sichtbarkeit
* Reduzierte Bewegung (`prefers-reduced-motion`) und abgeschaltete Effekte
