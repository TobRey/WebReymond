# Abhängigkeiten und Lizenzen

| Komponente | Version | Zweck | Lizenz | Einbindung |
|---|---|---|---|---|
| BlockRave | 1.0.0 | diese Mod | MIT (`LICENSE`) | – |
| Minecraft Java Edition | 1.21.1 | Spiel | Mojang EULA | vom Spieler/Server bereitgestellt |
| NeoForge | 21.1.264+ | Modloader | LGPL-2.1 | vom Spieler/Server installiert |
| Concentus (`io.github.jaredmdobson:concentus`) | 1.0.2 | Opus-Codec in reinem Java (Live + MP3-Streaming) | BSD-3-Clause (Opus-Lizenz) | Jar-in-Jar, unverändert |
| JLayer (`com.googlecode.soundlibs:jlayer`) | 1.0.1.4 | MP3-Decoder in reinem Java | **LGPL-2.1** | Jar-in-Jar, unverändert und austauschbar (eigene JAR unter `META-INF/jarjar/`) |
| JNA | (mit Minecraft) | Windows-Audio (WASAPI/COM) | Apache-2.0 / LGPL-2.1 | von Minecraft mitgeliefert, nicht eingebettet |
| LWJGL 3 / OpenAL Soft | (mit Minecraft) | Audioausgabe, Dateidialog (tinyfd) | BSD-3 / LGPL | von Minecraft mitgeliefert, nicht eingebettet |
| Gson | (mit Minecraft) | JSON-Speicherung | Apache-2.0 | von Minecraft mitgeliefert |

Nur zum Bauen/Testen (nicht in der Mod-JAR): ModDevGradle (LGPL-2.1), Gradle (Apache-2.0),
JUnit 5 (EPL-2.0), Parchment-Mappings (CC0).

Die vollständigen Lizenztexte von Concentus und JLayer (LGPL-2.1) liegen in
[`THIRD_PARTY_LICENSES.txt`](THIRD_PARTY_LICENSES.txt) und werden in die Mod-JAR (`META-INF/`) kopiert.

**LGPL-Hinweis (JLayer):** JLayer wird nicht verändert und als separate JAR eingebettet. Wer eine
andere Version verwenden möchte, kann die JAR unter `META-INF/jarjar/` ersetzen oder die Mod mit einer
anderen Version aus dem Quelltext bauen (`gradle.properties` → `jlayer_version`).

**Keine** bezahlten Dienste, Cloud-Konten oder externen Server werden benötigt. Alle Texturen und
Modelle der Mod sind eigene Arbeiten (prozedural erzeugt durch `tools/make_assets.py`).
