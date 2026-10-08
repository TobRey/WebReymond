# Machbarkeitsprüfung: Canon EOS M50 Mark II ↔ iPhone im Browser

Stand: Oktober 2026. Ziel: Bilder/Videos der Kamera in Originalqualität aufs iPhone, als Web-App
(PHP + JavaScript) auf GoDaddy-cPanel, ohne Datenbank und ohne dauerhaft laufenden Node-Server.

## 1. Welche Schnittstellen hat die M50 Mark II?

| Schnittstelle | Protokoll | Für Web-Apps nutzbar? |
|---|---|---|
| **Camera Connect** (Smartphone-Funktion der Kamera) | PTP/IP (TCP-Port 15740) + Bluetooth-Kopplung | **Nein.** Browser können keine rohen TCP-Verbindungen öffnen. Protokoll ist proprietär und nicht dokumentiert. |
| **EOS Utility / Fernsteuerung per WLAN** | PTP/IP | **Nein**, gleicher Grund. |
| **image.canon** (Cloud-Upload) | Canon-Cloud | Nur Weiterleitung zu Google Fotos u. a., kein lokaler Zugriff. |
| **CCAPI** (Camera Control API) | **HTTP/REST**, Port 8080, JSON | **Ja – mit Einschränkungen (siehe 2.).** |

**CCAPI-Unterstützung der M50 Mark II:** Canon nennt die M50 Mark II auf der US-Supportseite unter den
Modellen mit EDSDK/CCAPI, und die CCAPI-Tabelle der Canon Developer Community Europa führt sie mit ✓.
Eine Community-Tabelle (2021) ordnet sie dem Funktionsumfang **CCAPI 1.0** zu. CCAPI ist **ab Werk
gesperrt**: Es braucht eine kostenlose Registrierung im Canon-Entwicklerprogramm und eine einmalige
Aktivierung per USB mit Canons Werkzeug (aktuelle Firmware vorausgesetzt).
→ Die App setzt CCAPI nicht blind voraus: Sie liest beim Verbinden das Manifest `GET /ccapi`
der Kamera und nutzt nur die Funktionen, die die Kamera dort tatsächlich anbietet.

## 2. Kann Safari direkt mit der Kamera sprechen?

| Prüfung | Ergebnis |
|---|---|
| HTTPS-Seite (GoDaddy) → `http://<Kamera>:8080` | **Blockiert** („Mixed Content“). Safari lädt keine HTTP-Inhalte per `fetch` in eine HTTPS-Seite. |
| HTTP-Seite → Kamera | **Blockiert** durch **CORS**: Die Kamera sendet keine `Access-Control-Allow-Origin`-Header (bekannt aus Community-Projekten, die deshalb Chrome mit `--disable-web-security` starten). Eine CORS-Konfiguration (`functions/cors`) gibt es erst in neueren CCAPI-Versionen, nicht in 1.0. |
| Bilder per `<img src="http://Kamera/…">` | Nur ohne HTTPS, und Listen/Metadaten (JSON) bleiben unlesbar → keine Galerie möglich. |
| Webhosting als Proxy (PHP) | **Unmöglich**: Der Server im Internet erreicht das lokale Kamera-WLAN nicht. |
| iPhone im Kamera-WLAN + Webseite aus dem Internet | Kamera-WLAN hat kein Internet; selbst wenn iOS über Mobilfunk lädt, gelten die Sperren oben. |
| Web Share API („Bild sichern“ → Fotos) | Nur in **sicherem Kontext (HTTPS)**. |
| Lokales Netzwerk / „Local Network“-Freigabe | Safari selbst darf ins lokale Netz; native Apps brauchen die iOS-Freigabe „Lokales Netzwerk“. |

**Fazit:** Eine reine Web-App auf GoDaddy kann die Kamera **nicht** erreichen. Das ist keine Frage
der Programmierung, sondern der Sicherheitsregeln von Safari und der Netztopologie.

## 3. Gewählte Lösung (kleinste funktionierende Ergänzung)

```
 Kamera (CCAPI, HTTP)  ←—WLAN—→  Kamera-Brücke (Python, 1 Datei)  ←—WLAN—→  iPhone Safari
                                   liefert die Web-App selbst aus
                                   + leitet nur lesende GETs weiter
```

- **Kamera-Brücke**: eine einzelne Python-Datei (nur Standardbibliothek) auf Mac/PC/Raspberry Pi im
  selben WLAN. Sie liefert dieselbe Web-App aus und leitet `GET /ccapi/…` weiter. Damit ist alles
  „same origin“ → kein CORS-, kein Mixed-Content-Problem. Löschen (DELETE) und alle anderen
  Schreibzugriffe sind gesperrt; einzige Steuerung ist Livebild an/aus.
- **Optional HTTPS** in der Brücke (lokale Zertifizierungsstelle über `openssl`): erst damit erlaubt
  Safari das Teilen-Menü „Bild sichern“ → **Fotos-App**. Ohne HTTPS landen Downloads in der
  **Dateien-App** (einziger Weg, den Safari über HTTP erlaubt).
- **GoDaddy-Teil**: Start- und Anleitungsseite (PWA, offline verfügbar), Download der Brücke,
  Weiterleitung zur Brücke. Funktioniert in jedem Unterordner (alle Pfade relativ).
- **Ohne Computer**: (a) Raspberry Pi als Taschen-Brücke mit eigenem WLAN, (b) iOS-Hülle
  (Swift-Quellcode im Paket: dieselbe Web-App in einer WKWebView, nativer Kamerazugriff, PhotoKit).

## 4. Verwendete CCAPI-Aufrufe (alle aus öffentlichen Clients belegt)

| Zweck | Aufruf |
|---|---|
| Manifest / Versionen | `GET /ccapi` |
| Gerät | `GET /ccapi/ver100/deviceinformation` |
| Akku | `GET …/devicestatus/battery` |
| Speicher/Ordner | `GET …/contents`, `GET …/contents/sd`, `GET …/contents/sd/100CANON?kind=number` |
| Dateiliste | `GET …/100CANON?page=N` (Ersatz: `?kind=list&page=N`) |
| Vorschau | `GET <Datei>?kind=thumbnail`, `?kind=display` |
| Info (Grösse, Datum) | `GET <Datei>?kind=info` → `filesize`, `lastmodifieddate` |
| Original | `GET <Datei>` (unverändert; Range wird weitergereicht oder von der Brücke nachgebildet) |
| Neue Aufnahmen | `GET …/event/polling?continue=off` → `addedcontents` |
| Livebild | `POST …/shooting/liveview` `{"liveviewsize":"medium","cameradisplay":"on"}`, dann `GET …/shooting/liveview/flip` (JPEG-Einzelbilder) |

Die konkreten Pfade (Version `ver100`/`ver110`…) liest die Brücke aus dem Manifest der Kamera.

## Quellen

- Canon USA, Support EOS M50 Mark II (SDK/CCAPI-Hinweis): https://www.usa.canon.com/support/p/eos-m50-mark-ii
- Canon Developer Community Europa, „Latest CCAPI“ (Kompatibilitätstabelle): https://developers.canon-europe.com/developers/s/article/Latest-CCAPI
- Canon Handbuch „Using Camera Control API (CCAPI)“ (Aktivierung, Nickname, Authentifizierung): https://cam.start.canon/en/C017/manual/html/UG-06_Network_0130.html
- CORS-Problem im Browser (Chrome mit deaktivierter Sicherheit nötig): https://github.com/horshack-dpreview/canondatehack.html
- Python-Client mit Endpunkten, „nur ein Client gleichzeitig“, „ca. 2–5 MB/s“: https://github.com/stefaema/CCAPI
- Event-Polling `addedcontents`, `kind=info`: https://github.com/horshack-dpreview/Canomate
- `kind=number`/`page`, `kind=thumbnail`: https://github.com/laszewsk/canon-r7-ccapi
- Liste CCAPI-fähiger Modelle (älter): https://github.com/camerahacks/canon-api
