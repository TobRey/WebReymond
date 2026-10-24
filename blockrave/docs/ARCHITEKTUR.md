# Technische Prüfung und Architektur

## 1. Technische Prüfung der kritischen Punkte

### 1.1 NeoForge für Minecraft 1.21.1
* Versionen aus dem offiziellen NeoForge-MDK für 1.21.1 (`NeoForgeMDKs/MDK-1.21.1-ModDevGradle`):
  **NeoForge 21.1.264**, **ModDevGradle 2.0.148**, **Gradle 9.2.1**, **Java 21**, Parchment 2024.11.17.
* APIs wurden am NeoForge-Quelltext (Branch `1.21.1`) geprüft: `RegisterPayloadHandlersEvent` /
  `PayloadRegistrar` (inkl. `executesOn(HandlerThread.NETWORK)`), `PacketDistributor`,
  `RenderLevelStageEvent` (Stufe `AFTER_PARTICLES` mit Modelview-Matrix im RenderSystem),
  `ICommonPacketListener#hasChannel`, GameTest-Annotationen.
* Ergebnis: Die Mod kompiliert gegen das echte NeoForge (GitHub Actions) und läuft auf dem
  dedizierten GameTest-Server.

### 1.2 MP3-Decodierung und Streaming
* Beliebige MP3s lassen sich **nicht** als registrierte Vanilla-Sounds abspielen (die nutzen OGG aus
  Ressourcenpaketen und haben keine gemeinsame Zeitbasis). Deshalb eigener Weg:
* **Server** decodiert MP3 (JLayer, reines Java, MPEG-1/2/2.5 Layer III) auf einem eigenen Thread,
  rechnet per gefensterter Sinc-Interpolation auf 48 kHz um, analysiert und codiert mit **Opus**
  (Concentus, reines Java) in 20-ms-Frames. Jeder Frame trägt seinen **Abspielzeitpunkt auf der
  Serveruhr**. Versand ca. 1 s im Voraus (konfigurierbar) an Spieler in Hörweite.
* Vorteile: nur ein Decoder/Encoder für alle Zuhörer, Spieler brauchen die Datei nicht, späte
  Zuhörer steigen sofort an der aktuellen Position ein (Server hält ~2,5 s Verlauf vor), Pause/Weiter
  ohne erneutes Decodieren (zurückgehaltene PCM-Frames werden neu codiert).
* Bandbreite: 160 kbit/s ≈ 20 KB/s pro Zuhörer (bei 10 Spielern ca. 200 KB/s Upload am Server).
* Uploads: Blöcke à 16 KiB (unter dem 32-KiB-Limit für Pakete Client→Server), Fensterflusskontrolle
  (max. 8 unbestätigte Blöcke), Ratenbegrenzung, Schreiben auf eigenem I/O-Thread, Formatprüfung
  über Frame-Header-Kette **und** vollständige Probe-Decodierung im Hintergrund.

### 1.3 Windows-Aufnahme des Rekordbox-Mixes
* Offizielle Microsoft-Dokumentation (WASAPI „Loopback Recording“, „ActivateAudioInterfaceAsync“):
  Loopback nur für **Shared-Mode**-Streams; **Prozess-Loopback** (Einschluss/Ausschluss eines
  Prozessbaums) ab Windows 10 Build 20438; ASIO umgeht die Windows-Audio-Engine.
* Umsetzung direkt in der Mod per **JNA** (liegt Minecraft bei) über die COM-vtable – kein
  Hilfsprogramm: Geräteliste (IMMDeviceEnumerator), Endpunkt-Loopback, Aufnahmegeräte, Prozess-
  Loopback mit eigenem agilen `IActivateAudioInterfaceCompletionHandler`.
* Ehrliche Grenze: ASIO-/Exklusivmodus-Ausgabe kann nicht direkt erfasst werden → Anleitung
  `REKORDBOX.md` mit kleinster nötiger Routing-Anpassung; Anzeige „Kein Signal!“ nach 5 s Stille.

### 1.4 Synchronisierte, räumliche Wiedergabe
* Gemeinsame Zeitbasis: **Serveruhr** (monoton). Clients schätzen ihren Versatz NTP-artig
  (Anfrage/Antwort auf dem Netzwerk-Thread, kürzeste Laufzeit gewinnt, sanftes Nachführen).
* Jeder Client spielt Frame *n* zu dessen Abspielzeit: eigener **OpenAL-Kontext auf eigenem Thread**
  (`ALC_EXT_thread_local_context`, Minecrafts Soundsystem bleibt unberührt), Warteschlange von ca.
  120 ms, Messung der tatsächlich hörbaren Position (`AL_SOFT_source_latency`) und **sanfte
  Driftkorrektur** per Resampling (max. ±0,6 %), harte Neusynchronisation erst ab 120 ms Abweichung.
* Räumlich: linker Kanal am linken, rechter Kanal am rechten Lautsprecherturm (zwei Mono-Quellen,
  kein Signal doppelt), OpenAL-Panning nach Kopfrichtung; Lautstärke über eigene Kurve (bis 16 Blöcke
  voll, Kosinus-Abfall bis 128, danach stumm). Andere Dimension → stumm.

### 1.5 Lichtstrahlen ohne Shader
* Eigene `RenderType`s (Vanilla-Shader `position_color`, additive Mischung wie bei Blitzen) in
  `RenderLevelStageEvent.AFTER_PARTICLES`. Lichtkegel als Kegelmantel mit Alpha-Verlauf, Laser als
  kamerazugewandte Bänder, LED-/Bodenflächen als vollhelle Quads mit Polygon-Offset.
* **Verdeckung**: Tiefentest gegen die Welt (Strahlen hinter Wänden unsichtbar) **und**
  Strahlverfolgung (`level.clip`) – Kegel/Laser enden am ersten festen Block.
* Leuchtende Blöcke haben feste Lichtstufen (statisches Weltlicht). **Es gibt keine dynamische
  Weltbeleuchtung durch die Scheinwerfer.**

## 2. Architektur

```
                      ┌──────────────── Server (auch dediziert, ohne Grafik/Audio) ───────────────┐
 DJ-Pult-GUI ─Befehle─▶ PlaybackController (Hauptthread, ein Zustand, Revisionen)                 │
 Upload-Blöcke ───────▶ UploadManager ─ I/O-Thread ─ Decode-Thread (Prüfung) ─▶ LibraryService     │
 Live-Frames (Sender) ─▶ AudioEngine (eigener Thread): MP3 → 48 kHz → Analyse → Opus             │
                      │            + Abspielzeit (Serveruhr) → Verteilung an Zuhörer in Reichweite│
 Bauvorschau ─Prüfen──▶ StageManager: exakter Eingriffsbereich, Hash, Schutz-Events, Aufbau/Abbau │
                      └───────────────────────────────────────────────────────────────────────────┘
                      ┌──────────────── Client ──────────────────────────────────────────────────┐
 Netzwerk-Thread ─────▶ ShowTimeline (Analyse nach Abspielzeit)  ─▶ StageEffectsRenderer (Render) │
                      │ JitterBuffer ─▶ RavePlayer (eigener Thread, eigener OpenAL-Kontext)      │
 Windows/WASAPI ──────▶ LiveSender (Aufnahme → 48 kHz → Analyse → Opus → Server)                 │
                      └───────────────────────────────────────────────────────────────────────────┘
```

| Paket | Inhalt | Seite |
|---|---|---|
| `de.blockrave.core.*` | Minecraft-unabhängig: Audio (MP3, Opus, Resampler), Analyse, Sync, Bühnenlayout, Show-Programm, Speicherung, Upload | beide (unit-getestet) |
| `de.blockrave` (`block`, `item`, `menu`, `registry`, `network`, `stage`, `audio`, `server`, `gametest`) | Registrierung, Pakete, Bühnenverwaltung, Audio-Engine, Uploads | gemeinsam/Server |
| `de.blockrave.client.*` | Wiedergabe, Rendering, Oberflächen, Live-Aufnahme, Uploads, Vorschau | nur Client |
| `de.blockrave.client.live.windows` | WASAPI/COM per JNA | nur Client unter Windows |

Der dedizierte Server lädt niemals Clientklassen: Pakethandler für den Client laufen über die
Schnittstelle `ClientBridge` (Standard: leer), der Client-Einstieg ist `@Mod(dist = Dist.CLIENT)`.

### Lichtshow
* **Analyse** (Server für MP3, Sender für Live) je 20-ms-Frame: Lautheit, 4 Frequenzbänder (FFT 2048),
  spektraler Fluss → Onsets und Bassimpulse mit adaptiven Schwellen, Tempo per Autokorrelation
  (70–180 BPM), Beat-Phasenregelschleife, Energie/Intensität relativ zum Track, Höhepunkt-Erkennung,
  Szenenwahl mit Hysterese (deterministisch, keine Zufallszahlen). 16 Byte pro Frame.
* Das Audio wird um 2 Frames verzögert, damit Akzente nicht nach dem Ton kommen (gemessen: Beats im
  Mittel ±20 ms am Kick).
* **Show-Programm** (`ShowProgram`): reine Funktion aus Abspielzeit + Analysewerten → Scheinwerfer-
  winkel/-farben, Laser, Stroboskope, LED-Muster, Boden, Nebel, Feuerwerk. Daher auf allen Clients
  gleich, ohne jede Lichtbewegung zu übertragen. Spieler außerhalb der Hörweite bekommen nur die
  Analysewerte.
* Feuerwerk/Nebel sind reine Clienteffekte: kein Schaden, keine Items, keine Blockänderungen, kein Feuer.

### Bühne
* Bauplan 35 × 20 × 19 (`StageLayout`), 1 792 Bauteile; alle übrigen Positionen im Quader werden
  geleert (Innenraum). Die Vorschau und der Server verwenden dieselbe Berechnung (`StageFootprint`).
* Bestätigung bindet an einen Hash über den gesamten Quader; ändert sich etwas, wird neu bestätigt.
* Aufbau und Einpacken laufen atomar auf dem Server-Hauptthread (keine Duplikation). Beim Einpacken
  werden nur Positionen entfernt, an denen noch das erfasste Bauteil steht.
