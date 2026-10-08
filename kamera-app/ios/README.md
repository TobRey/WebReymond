# Kamera-Link als iPhone-App (optional)

Diese Hülle ist die **kleinste Ergänzung für den Betrieb ganz ohne Computer**: Das iPhone verbindet
sich direkt mit dem WLAN der Kamera (CCAPI, Kamera-Access-Point-Modus) oder demselben Heim-WLAN.
Die bekannte Web-App läuft unverändert in einer `WKWebView`; ein Scheme-Handler übernimmt die Rolle
der Kamera-Brücke (nur lesende Kamerazugriffe, Löschen gesperrt). Fotos und Videos landen über
PhotoKit **direkt in der Fotos-App**, als unveränderte Originaldateien.

> Status: Quellcode vollständig, aber **nicht kompiliert und nicht auf einem Gerät getestet**
> (in der Entwicklungsumgebung gab es weder Xcode noch ein iPhone). Rechne mit kleinen Anpassungen.

## Bauen (Mac mit Xcode 15 oder neuer, kostenlose Apple-ID genügt)

1. Xcode › *File › New › Project › iOS App*, Name `KameraLink`, Interface **SwiftUI**, Sprache Swift.
2. Die automatisch erzeugte `KameraLinkApp.swift` und `ContentView.swift` löschen.
3. `KameraLink/KameraLinkApp.swift` und `KameraLink/CameraBridge.swift` aus diesem Ordner ins Projekt ziehen.
4. Den Ordner `KameraLink/web` ins Projekt ziehen und **„Create folder references“** wählen
   (blauer Ordner – die Dateistruktur muss erhalten bleiben).
5. Unter *Target › Info* die drei Schlüssel aus `Info-Zusatz.plist` eintragen.
6. *Signing & Capabilities*: eigenes Team (Apple-ID) wählen, iPhone anschliessen, ▶︎ Run.
   Beim ersten Start auf dem iPhone: *Einstellungen › Allgemein › VPN & Geräteverwaltung* › Entwickler vertrauen.
   Mit kostenloser Apple-ID läuft die App 7 Tage, danach erneut aus Xcode installieren.

## Nutzung

- Kamera: CCAPI-Verbindung starten (Access-Point-Modus der Kamera oder Heim-WLAN).
- iPhone mit diesem WLAN verbinden, App öffnen › *Verbindung* › *Im Netzwerk suchen* oder IP eintragen.
- Beim ersten Zugriff fragt iOS nach „Lokales Netzwerk“ – erlauben. Beim ersten Sichern nach Fotos – erlauben.
- „In Fotos sichern“ lädt das Original direkt von der Kamera und legt es unverändert in der Fotos-App ab.
  RAW (CR3) wird als Foto-Ressource übergeben; ob Fotos die CR3-Datei der M50 Mark II annimmt, hängt von
  der RAW-Unterstützung von iOS ab (am Gerät prüfen).
