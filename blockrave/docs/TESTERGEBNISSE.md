# Testergebnisse

Stand: 24.10.2026. Hier steht nur, was **tatsächlich ausgeführt** wurde. Nicht ausführbare Tests sind
ausdrücklich als **UNGETESTET** gekennzeichnet.

## Testumgebungen

| Umgebung | Was dort läuft |
|---|---|
| Entwicklungscontainer (Linux, ohne Netzzugang zu NeoForge/Mojang) | Kern-Unit-Tests, Audio-Prüfstand mit echtem OpenAL Soft (Ausgabe in WAV-Datei) |
| GitHub Actions `ubuntu-latest` (Job „Build, Unit- und GameTests“) | Kompilieren gegen NeoForge 21.1.264, Unit-Tests, **dedizierter GameTest-Server** |
| GitHub Actions (Job „Produktivserver, Client-Rauchtest, Mehrspieler-E2E“) | echter NeoForge-Server per offiziellem Installer mit der gebauten JAR; echte Minecraft-Clients unter Xvfb mit Software-OpenGL (Mesa llvmpipe), OpenAL-„null“-Ausgabe |

<!-- ERGEBNISSE -->
