# Kamera-Link – Canon EOS M50 Mark II → iPhone

Fotos und Videos der Kamera kabellos aufs iPhone, **als unveränderte Originale**: Galerie mit
Vorschauen, Vollbild mit Zoom, Downloads mit Warteschlange und Fortsetzung, Live-Übertragung neuer
Aufnahmen und optional Kamera-Livebild. Deutsch, dunkel, für das iPhone gebaut.

**Wichtig vorab:** Eine Webseite auf dem Webhosting kann die Kamera im lokalen WLAN technisch nicht
erreichen (Safari-Sicherheitsregeln, siehe [docs/MACHBARKEIT.md](docs/MACHBARKEIT.md)). Deshalb gibt
es die **Kamera-Brücke**: eine einzelne Python-Datei, die auf einem Computer oder Raspberry Pi im
selben WLAN läuft und die App fürs iPhone ausliefert.

## Installation

### 1. Webhosting (GoDaddy cPanel)
1. `kamera-link-hosting.zip` im cPanel-Dateimanager in `public_html` (oder einen beliebigen
   Unterordner) hochladen und **Entpacken**. Es entsteht der Ordner `kamera` – beliebig umbenennbar
   (z. B. `public_html/fotos`). Keine Pfadanpassung nötig.
2. Aufrufen: `https://deine-domain.ch/kamera/` (bzw. dein Ordnername). Benötigt PHP ≥ 7.4.
3. Auf dem iPhone in Safari: Teilen › „Zum Home-Bildschirm“ – die Startseite funktioniert dann auch offline.

### 2. Kamera (einmalig)
1. Kostenlos im Canon-Entwicklerprogramm registrieren, CCAPI für die EOS M50 Mark II anfordern
   und mit Canons Aktivierungswerkzeug per USB freischalten (aktuelle Firmware).
2. Im WLAN-Menü der Kamera die CCAPI-Verbindung mit dem gleichen WLAN herstellen, in dem Computer
   und iPhone sind (Heimnetz oder iPhone-Hotspot).

### 3. Kamera-Brücke
1. Auf der Webseite „Mac“ bzw. „Windows“ herunterladen und entpacken (Python 3.9+ nötig).
2. Doppelklick auf „Kamera-Bruecke starten“ (Mac beim ersten Mal: Rechtsklick › Öffnen).
   Für „In Fotos sichern“: „Kamera-Bruecke mit Fotos-Funktion (HTTPS)“.
3. Das Fenster zeigt Adresse und QR-Code → mit dem iPhone scannen. In der App unter
   *Verbindung* › *Im Netzwerk suchen* oder Kamera-IP eintragen.

Details: [bridge/LIESMICH.txt](bridge/LIESMICH.txt) · Ohne Computer: [ios/README.md](ios/README.md)

## Was funktioniert – ehrliche Übersicht

| Funktion | Status |
|---|---|
| Echter Verbindungsstatus (Brücke, Kamera erreichbar, CCAPI aktiv, Modell, Akku) | ✅ getestet¹ |
| Galerie aller Dateien, schnelle Vorschauen, RAW/JPEG/Video getrennt filterbar | ✅ getestet¹ |
| Vollbild, Pinch-/Doppeltipp-Zoom, Wischen, Datum, Typ, Grösse | ✅ getestet¹ |
| Einzeln / Mehrfachauswahl / alle laden; Originale bytegleich (SHA-256 geprüft) | ✅ getestet¹ |
| Warteschlange: Fortschritt, Pause, Abbrechen, Wiederholen, automatische Wiederholung | ✅ getestet¹ |
| Fortsetzung nach WLAN-Abriss und nach Neuladen der Seite (HTTP-Range) | ✅ getestet¹ |
| Duplikate erkennen (gleiche Datei + Grösse bereits übertragen) | ✅ getestet¹ |
| ZIP der Auswahl in die Dateien-App (unkomprimiert, Originale unverändert) | ✅ getestet¹ |
| Live: neue Aufnahmen erkennen, neuestes gross, automatisch übertragen (JPEG / +RAW / alles) | ✅ getestet¹ |
| Livebild (Sucher), klar getrennt von der Fotoübertragung | ✅ getestet¹ |
| Nie etwas auf der Kamera löschen (Brücke sperrt DELETE/PUT/POST technisch) | ✅ getestet¹ |
| Web-Version in beliebigem Unterordner, Downloads, offline | ✅ getestet (PHP) |
| „In Fotos sichern“ über Teilen-Menü (nur mit HTTPS-Brücke) | ⚠️ Code fertig, nur am iPhone prüfbar |
| Über HTTP: Download in die Dateien-App | ⚠️ am iPhone prüfen |
| iOS-Hülle (native App, direkt in Fotos, ohne Computer) | ⚠️ Quellcode, nicht kompiliert |

¹ automatisiert gegen ein CCAPI-Test-Doppel (`tools/mock_ccapi.py`) in Chromium mit iPhone-Profil.
Das Test-Doppel bildet nur öffentlich belegtes CCAPI-Verhalten nach und ist **nicht** Teil der App.

### Erst mit der echten Kamera prüfbar
- CCAPI-Aktivierung und die genauen Menüpunkte der M50 Mark II; welche CCAPI-Version/Endpunkte das Manifest meldet.
- Format von `lastmodifieddate` (die App zeigt bei unbekanntem Format den Rohtext).
- Ob die Kamera HTTP-Range kann (sonst bildet die Brücke es nach – funktioniert, kostet aber Zeit).
- Ob `event/polling` neue Aufnahmen sofort meldet, während man an der Kamera fotografiert. Falls nicht, erkennt der zusätzliche Ordnerabgleich (alle ~10 s) neue Dateien trotzdem.
- Livebild-Grössen (`medium`/`small`) und ob Livebild und Dateizugriff gleichzeitig gehen.
- Übertragungsrate (Erfahrungswerte CCAPI: wenige MB/s).
- iPhone: Teilen › „Bild sichern“ mit CR3-RAW (iOS-RAW-Unterstützung), Safari-Downloads, Zertifikatsinstallation.

## Entwicklung & Tests

```bash
cd kamera-app
python3 tools/build.py                 # baut dist/kamera-link-hosting.zip und dist/kamera-bruecke.py
python3 -m unittest tests/test_bridge.py   # 16 Integrationstests der Brücke
python3 tests/test_qr.py               # QR-Code gegen unabhängigen Decoder (pip install zxing-cpp)
node tests/e2e.mjs                     # 13 End-to-End-Tests (Playwright/Chromium, iPhone-Profil)
```

Lokal ausprobieren ohne Kamera: `python3 tools/mock_ccapi.py --port 18080` und
`python3 bridge/kamera_bruecke.py --web-dir web --camera 127.0.0.1:18080`.

| Ordner | Inhalt |
|---|---|
| `web/` | Web-App (HTML, CSS, JS-Module), gemeinsam für Hosting, Brücke und iOS |
| `hosting/` | PHP-Einstieg, Downloads, `.htaccess`, Service Worker |
| `bridge/` | Kamera-Brücke (Python), Startdateien, Anleitung |
| `ios/` | optionale native Hülle (Swift) |
| `tools/` | Build, Icons, CCAPI-Test-Doppel |
| `tests/` | Brücken-, QR- und End-to-End-Tests |
