# Testergebnisse

Stand: 24.10.2026. Hier steht nur, was **tatsächlich ausgeführt** wurde. Nicht ausführbare Tests sind
ausdrücklich als **UNGETESTET** gekennzeichnet.

## Testumgebungen

| Umgebung | Was dort läuft |
|---|---|
| Entwicklungscontainer (Linux, ohne Netzzugang zu NeoForge/Mojang) | Kern-Unit-Tests, Audio-Prüfstand mit echtem OpenAL Soft (Ausgabe in WAV-Datei) |
| GitHub Actions `ubuntu-latest` (Job „Build, Unit- und GameTests“) | Kompilieren gegen NeoForge 21.1.264, Unit-Tests, **dedizierter GameTest-Server** |
| GitHub Actions (Job „Produktivserver, Client-Rauchtest, Mehrspieler-E2E“) | echter NeoForge-Server per offiziellem Installer mit der gebauten JAR; echte Minecraft-Clients unter Xvfb mit Software-OpenGL (Mesa llvmpipe), OpenAL-„null“-Ausgabe |

## 1. Kern-Unit-Tests (ohne Minecraft) – BESTANDEN

28 JUnit-Tests (Dateien `Mp3Test`, `DspTest`, `AnalyzerTest`, `SyncTest`, `StageTest`, `LibraryTest`, `ShowTest`),
lokal und in jedem CI-Lauf grün. Abgedeckt u. a.:

- MP3-Prüfung (Frame-Kette, ID3-Titel/Interpret, Ablehnung von Nicht-MP3/abgeschnittenen Dateien), Decodierung
  MPEG-1 und MPEG-2 (44,1/22,05 kHz) über JLayer, Längenmessung
- Resampler auf 48 kHz, Opus-Kodierung/-Decodierung inkl. Verlustverschleierung
- Beat-Erkennung an synthetischen Kick-Mustern: Beats liegen innerhalb ±30 ms der Kicks, Tempo 70–180 BPM
- Uhrenabgleich (kürzeste Laufzeit gewinnt, Sprünge werden geglättet), Driftregler, Jitterpuffer (Reihenfolge,
  Duplikate, Schnitte bei Pause/Stopp)
- Bühnenlayout/Drehung (alle 4 Richtungen, Fußabdruck identisch zwischen Vorschau und Server)
- Bibliothek/Playlist/Warteschlange, atomares Speichern mit `.bak`, Upload-Zusammenbau (Reihenfolge, Lücken,
  Größenlimit), Lichtshow-Programm deterministisch

## 2. Audio-Prüfstand mit echtem OpenAL Soft – BESTANDEN

`core-check: runAudioHarness` spielt 16 s Testtöne (alle 0,5 s ein 15-ms-Ton) über den echten `RavePlayer`
mit OpenAL Soft, Ausgabe über dessen „wave“-Backend in eine WAV-Datei, die anschließend ausgewertet wird.
Ergebnis nach der Startkorrektur (24.10.2026, zwei Läufe):

| Messgröße | Ergebnis |
|---|---|
| Gefundene Töne | 30/30 (beide Läufe) |
| Töne während der Pause (6–7 s) | 0 (erwartet 0) |
| Abweichung Töne 0,5–5,5 s | 0 bis −3 ms |
| Allererster Ton beim allerersten Start | +29 bis +33 ms (Geräte-Latenz erst nach dem Start messbar; danach einmalige Nachkalibrierung) |
| Simulierter Uhrensprung +30 ms | wird über die Tempoanpassung (max. 0,6 %) innerhalb von ca. 5–6 s aufgeholt |

## 3. Dedizierter GameTest-Server (CI-Lauf 16) – BESTANDEN

„All 3 required tests passed“:

- `stageAndMultiplayer`: Prüfung/Hash, Aufbau (1 792 Bauteile in ca. 30–90 ms), Aufbau ohne Bauset abgelehnt,
  Einpacken lässt fremde Blöcke stehen, Bauset genau einmal zurück, nur eine Bühne pro Server, zwei
  (Schein-)Spieler bedienen das Pult ohne OP
- `audioEngineDecodesAndPaces`: echte MP3 → 206 Opus-Frames in Echtzeit-Taktung, Titelende korrekt gemeldet
- `payloadCodecs`: alle Netzwerkpakete kodieren/dekodieren verlustfrei

## 4. Produktiver dedizierter Server (CI-Lauf 16) – BESTANDEN

Offizieller NeoForge-21.1.264-Installer, gebaute `blockrave-1.0.0.jar` in `mods/`: Server startet („Done“),
Mod geladen, „Audio-Bibliotheken geladen (Opus/Concentus, MP3/JLayer)“, sauberes Beenden.

## 5. Client-Rauchtest, echter Client (CI-Lauf 16) – BESTANDEN

Echter Minecraft-Client (Xvfb, Mesa-Software-OpenGL, OpenAL „null“-Ausgabe), Einzelspielerwelt:
Bühne gebaut, Wiedergabe gestartet, Client kennt Bühne und Status, Uhrenabgleich gültig, Lichtshow-Zeitleiste
liefert Daten (BPM 127,8), Rave-Audio spielt (Sync-Fehler −7 ms), DJ-Pult-Oberfläche öffnet, Bühne eingepackt,
Bauvorschau aktiv mit Position, Screenshots erstellt. „ERGEBNIS: alle Pruefungen bestanden“.

## 6. Mehrspieler-E2E: echter Server + zwei echte Clients (CI-Lauf 16)

Produktiver NeoForge-Server, Clients „RaveDJ“ (A) und „RaveGast“ (B) als getrennte Prozesse über das Netzwerk.

| Prüfung | Ergebnis |
|---|---|
| A: Bühne per Vorschau → Serverprüfung (700 betroffene Blöcke markiert) → Bestätigen → Aufbau | OK |
| A: DJ-Pult sofort nach Aufbau im Client sichtbar | FAIL in Lauf 16 – Testablauf prüfte im selben Tick, bevor die Blockupdates ankamen; Prüfung wartet jetzt bis 4 s, in Lauf 18 OK |
| A: MP3-Upload über das Netzwerk (321 085 Bytes in 838 ms), Titel aus ID3 | OK |
| A: synchroner Start, 25 Messungen über 2 Titeldurchläufe | OK, 25/25 innerhalb ±40 ms, **max. 11,7 ms** |
| A: Lichtshow aus Audioanalyse aktiv | OK (BPM 127,8 beim 128-BPM-Testtrack) |
| B: späterer Beitritt hört laufenden Titel an aktueller Position | OK (Einstieg bei 6,41 s) |
| B: Synchronität, 15 Messungen | OK, 15/15 innerhalb ±40 ms, **max. 11,1 ms** |
| B: ca. 200 Blöcke entfernt kein Audio, Lichtshow-Daten kommen weiter | OK |
| B: zurück in Reichweite, Audio läuft wieder | OK |
| Server-Neustart: Bühne, MP3, Playlist vorhanden; Wiedergabe gestoppt | OK |
| A2: Abbauen mit Spitzhacke am DJ-Pult, Bauset genau einmal zurück, Pult entfernt | OK |
| A2: erneuter Aufbau an anderer Stelle | OK |

Vor der Korrektur (Lauf 15) lag B nach dem Spätbeitritt anfangs bis 70 ms daneben, und beim Start traten
`ArrayIndexOutOfBoundsException`s auf (Anfangsstille passte nicht in den Puffer). Beides ist behoben (Lauf 16).

### Abschlusslauf 18 (Commit c61a922, 24.10.2026) – ALLE JOBS GRÜN

| Teil | Ergebnis |
|---|---|
| Kern-Tests, Mod-Build, GameTest-Server | bestanden |
| Produktiver dedizierter Server | bestanden |
| Client-Rauchtest | „alle Pruefungen bestanden“, Sync-Fehler −2 ms |
| E2E A (Aufbau, Pult sichtbar, Upload 473 ms, Start, Sync) | alle bestanden, 25/25 Messungen, **max. 11,5 ms** |
| E2E B (Spätbeitritt bei 13,46 s, Sync, Reichweite) | alle bestanden, 15/15 Messungen, **max. 11,3 ms** |
| E2E A2 (Neustart-Persistenz, Einpacken mit Spitzhacke, Bauset 1×, Neuaufbau) | alle bestanden |

Hinweis zur Messung: „Fehler“ ist die Abweichung der von OpenAL gemeldeten Abspielposition (inkl. gemeldeter
Gerätelatenz) von der Serveruhr. Mit OpenAL „null“-Ausgabe ist das eine Software-Messung; der akustische
Gleichlauf an echten Lautsprechern zweier PCs ist UNGETESTET.


## 7. UNGETESTET (nicht ausführbar in dieser Umgebung)

- **Live-Aufnahme unter Windows (WASAPI-Loopback, Prozess-Loopback für rekordbox.exe, Aufnahmegeräte):**
  Code kompiliert, wurde aber **nie auf einem echten Windows-PC ausgeführt**. Erwartbar sind Fehler bei den
  COM-Aufrufen über JNA, die erst auf Windows sichtbar werden.
- **Rekordbox-Routing** gemäß `REKORDBOX.md`: UNGETESTET.
- **Live-Abbruch beim Sender (Verbindungsverlust während Live)**: Serverlogik (Live endet, kein Auto-Neustart)
  ist im Code vorhanden, mit echter Live-Quelle UNGETESTET.
- **Echte Audio-Hardware/Lautsprecher, Hörtest, räumlicher Eindruck**: UNGETESTET (CI nutzt OpenAL „null“,
  der Prüfstand eine WAV-Datei).
- **Optische Qualität der Lichtshow, Laser, Nebel, Strobe-Reduktion, Shader-Packs**: UNGETESTET bzw. nur per
  Software-Rendering-Screenshot im CI ohne menschliche Bewertung.
- **5–10 gleichzeitige Spieler, echte Internetlatenz/Paketverlust**: UNGETESTET (getestet: 2 Clients lokal).
- **Performance auf realen Gaming-PCs (FPS-Einbruch durch Effekte)**: UNGETESTET.
- **Andere Schutz-Mods (z. B. Claim-Plugins)**: Das BreakEvent wird ausgelöst, die Zusammenarbeit mit konkreten
  Mods ist UNGETESTET.

