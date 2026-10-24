# BlockRave – Festivalbühne für Minecraft 1.21.1 (NeoForge)

BlockRave bringt eine große, automatisch aufgebaute **Festivalbühne mit DJ-Pult** auf deinen Server:
hochgeladene **MP3-Playlists** oder dein **Live-Mix aus rekordbox (Windows)** laufen für alle Spieler
**synchron** und **räumlich** (bis 128 Blöcke, sanft leiser werdend) – begleitet von einer
**automatischen, musikgesteuerten Lichtshow**, die ohne Shader sichtbar ist.

* Bühne 35 × 20 × 19 Blöcke: erhöhte Plattform, zentrales DJ-Pult, schwarze Lautsprechertürme,
  Metalltraversen, bunte LED-Wände/-Panels, leuchtende Bühnenfront, Moving Heads, Laser,
  Stroboskope, Nebelmaschinen und leuchtende Tanzfläche.
* Transparente Bauvorschau mit Drehung, roter Markierung aller betroffenen Blöcke und
  serverseitig geprüfter, ausdrücklicher Bestätigung. Einpacken mit der Spitzhacke am DJ-Pult.
* DJ-Pult-Oberfläche auf Deutsch im Minecraft-Inventarstil: MP3/Live, Wiedergabe/Pause/Stopp/Weiter,
  Fortschritt, Playlist, Warteschlange, Bibliothek, Uploads per Dateiauswahl, Masterlautstärke,
  Live-Quelle, Sendestatus mit Pegel und geschätzter Verzögerung, lokale Lautstärke/Stumm.
* Lichtshow aus echter Audioanalyse (Bassimpulse, Onsets, Tempo/Beat-Tracking, Frequenzbänder,
  Energie, Höhepunkte) – synchron zur **gehörten** Wiedergabezeit auf allen Clients.
* Lokale Grafikeinstellungen: Effektqualität, Partikel, Nebel, reduzierte/abgeschaltete Stroboskope.

## Dokumentation

| Dokument | Inhalt |
|---|---|
| [docs/INSTALLATION.md](docs/INSTALLATION.md) | Voraussetzungen, Installation Server & Clients, erste Schritte |
| [docs/REKORDBOX.md](docs/REKORDBOX.md) | Live-Auflegen aus rekordbox, Audio-Routing unter Windows |
| [docs/KONFIGURATION.md](docs/KONFIGURATION.md) | Alle Konfigurationswerte und gespeicherten Daten |
| [docs/ARCHITEKTUR.md](docs/ARCHITEKTUR.md) | Technische Prüfung (NeoForge, MP3, WASAPI, Sync, Rendering) und Architektur |
| [docs/TESTERGEBNISSE.md](docs/TESTERGEBNISSE.md) | Tatsächlich ausgeführte Tests, ungetestete Punkte, Einschränkungen |
| [docs/ABHAENGIGKEITEN_LIZENZEN.md](docs/ABHAENGIGKEITEN_LIZENZEN.md) | Abhängigkeiten und Lizenzen |

## Bauen

```bash
./gradlew build                 # Mod-JAR: build/libs/blockrave-1.0.0.jar
./gradlew runGameTestServer     # dedizierter GameTest-Server mit den BlockRave-GameTests
./gradlew -p core-check test    # Kern-Tests ohne Minecraft/NeoForge (nur Maven Central nötig)
```

Projektstruktur: `src/main/java/de/blockrave/core` (Minecraft-unabhängig, unit-getestet),
`…/blockrave/{stage,audio,network,server,…}` (gemeinsam/Server), `…/blockrave/client` (nur Client),
`tools/` (Generatoren für Texturen/Modelle/Texte/Testaudio, Audio-Prüfstand).
