# Konfiguration

BlockRave nutzt das NeoForge-Konfigurationssystem. Alle Werte lassen sich auch im Spiel unter
*Mods → BlockRave → Konfiguration* ändern (Server-Werte nur als Operator bzw. im Einzelspieler).

## Server (`<Welt>/serverconfig/blockrave-server.toml`)

Pro Welt. Änderungen an Puffer/Bitrate werden beim Neuladen der Konfiguration übernommen
(neu gestartete Titel/Streams verwenden die neuen Werte).

| Schlüssel | Standard | Bereich | Bedeutung |
|---|---|---|---|
| `uploads.allowUploads` | `true` | – | Dürfen alle Spieler MP3s hochladen? |
| `uploads.maxUploadMb` | `50` | 1–500 | Maximale Größe einer MP3-Datei (MiB) |
| `uploads.maxStorageMb` | `2048` | 16–100000 | Maximaler Speicher aller Titel zusammen (MiB) |
| `uploads.maxTracks` | `500` | 1–10000 | Maximale Anzahl Titel in der Bibliothek |
| `uploads.maxTrackMinutes` | `90` | 1–600 | Maximale Titellänge (Minuten) |
| `uploads.maxParallelUploads` | `2` | 1–8 | Gleichzeitige Uploads pro Spieler |
| `uploads.uploadRateKbps` | `1024` | 64–65536 | Uploadrate pro Spieler in KiB/s (Drosselung über verzögerte Bestätigungen) |
| `playback.hearingRange` | `128` | 16–256 | Hörreichweite ab Bühnenmitte (Blöcke). Bis 16 Blöcke volle Lautstärke, danach sanft (Kosinuskurve) bis 0 am Rand, außerhalb stumm. Audio wird bis Reichweite+16 gesendet. |
| `playback.showRange` | `256` | 32–512 | Spieler bis zu dieser Entfernung erhalten nur Lichtshow-Daten (ohne Audio, ca. 0,4 KB/s) |
| `playback.mp3BufferMs` | `1000` | 300–5000 | Vorlauf der MP3-Wiedergabe (Netzwerkpuffer) |
| `playback.liveBufferMs` | `500` | 200–6000 | Pufferverzögerung für Live-Streams. LAN/guter Server: 300–500, Internet mit schwankendem Ping: 500–1000. Zu klein → Aussetzer. (Hieß bis Version 1.0.0 `liveDelayMs` mit 1500 ms; der alte Eintrag wird ignoriert.) |
| `playback.opusBitrate` | `160000` | 48000–320000 | Opus-Bitrate für die MP3-Wiedergabe (Bit/s) |
| `playback.allowLive` | `true` | – | Dürfen alle Spieler live senden? |

## Client (`config/blockrave-client.toml`)

Gilt nur auf dem eigenen PC. Grafik-Einstellungen ändern nur die Darstellung – die Show bleibt
zeitlich synchron mit allen anderen Spielern.

| Schlüssel | Standard | Bedeutung |
|---|---|---|
| `audio.localVolume` | `100` | Lokale Lautstärke der Rave-Musik (0–100 %). Zusätzlich gelten Minecrafts Regler *Gesamtlautstärke* und *Musikblöcke/Plattenspieler*. |
| `audio.muted` | `false` | Rave-Musik stumm (Taste **M** schaltet um) |
| `audio.monitorOwnLive` | `false` | Eigenen Live-Stream zusätzlich über Minecraft hören (Rückkopplungsgefahr bei Loopback) |
| `audio.liveBitrate` | `160000` | Opus-Bitrate beim eigenen Live-Senden (64000–320000) |
| `audio.liveSource` | `""` | Zuletzt gewählte Live-Quelle (wird nie automatisch gestartet) |
| `graphics.effectQuality` | `HOCH` | `NIEDRIG` / `MITTEL` / `HOCH`: Segmente der Lichtkegel, Anzahl Laserstrahlen, LED-Auflösung |
| `graphics.particleAmount` | `100` | Nebel- und Feuerwerksmenge (0–100 %) |
| `graphics.fog` | `true` | Nebeleffekte anzeigen |
| `graphics.strobeMode` | `VOLL` | `VOLL`, `REDUZIERT` (höchstens jeder zweite Beat, 35 % Helligkeit, Feuerwerk ohne Funkeln) oder `AUS` (keine Stroboskope, kein Funkeln) |

## Gespeicherte Daten (`<Welt>/blockrave/`)

| Datei/Ordner | Inhalt |
|---|---|
| `stage.json` (+ `.bak`) | Bühnenposition, Drehung und exakte Liste aller Bauteil-Positionen |
| `library.json` (+ `.bak`) | Titel-Metadaten, Playlist, Warteschlange, Masterlautstärke, Modus |
| `tracks/<id>.mp3` | Hochgeladene MP3s (Dateinamen vergibt nur der Server) |
| `incoming/` | Laufende Uploads (wird beim Start geleert) |

Alle JSON-Dateien werden atomar geschrieben (Temporärdatei → `fsync` → atomares Umbenennen, vorherige
Version als `.bak`). Ist die Hauptdatei beschädigt, wird automatisch die Sicherung geladen.
Nach einem Neustart ist die Wiedergabe gestoppt; Live-Streams starten nie automatisch.
