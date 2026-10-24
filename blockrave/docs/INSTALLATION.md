# Installation (Server und Clients)

BlockRave muss **auf dem Server und auf allen Clients** installiert sein (gleiche Version). Ohne Mod
auf dem Client verweigert NeoForge die Verbindung.

## Voraussetzungen

| | Server | Client (Spieler) |
|---|---|---|
| Minecraft | Java Edition **1.21.1** | Java Edition **1.21.1** |
| Modloader | **NeoForge 21.1.264** oder neuer (21.1.x) | **NeoForge 21.1.264** oder neuer (21.1.x) |
| Java | **Java 21** (64 Bit) | Java 21 (der Minecraft-Launcher bringt es mit) |
| Betriebssystem | beliebig (Linux, Windows, macOS); **kein** Grafik- oder Audiogerät nötig | Windows, Linux, macOS. **Live-Senden nur unter Windows** (64 Bit). |
| Weitere Mods | keine | keine |
| Netz | Upload pro Zuhörer ca. 20 KB/s (bei 10 Spielern ca. 200 KB/s), Zusatz für MP3-Uploads | ca. 20 KB/s Download während der Musik |
| Kosten/Konten | keine | keine |

Benötigte Bibliotheken (Concentus/Opus und JLayer/MP3) sind **in der Mod-JAR enthalten** (Jar-in-Jar).
JNA und LWJGL/OpenAL bringt Minecraft selbst mit.

## Mod-JAR erhalten

* **Fertig gebaut:** GitHub → Actions → Workflow „BlockRave (Minecraft-Mod)“ → letzter Lauf →
  Artefakt `blockrave-mod` herunterladen und entpacken (`blockrave-1.0.0.jar`).
* **Selbst bauen** (Java 21 nötig, Internetzugang zu maven.neoforged.net und Mojang):
  ```bash
  cd blockrave
  ./gradlew build          # Windows: gradlew.bat build
  # Ergebnis: build/libs/blockrave-1.0.0.jar
  ```

## Server einrichten

1. NeoForge-Installer für 1.21.1 von <https://neoforged.net> laden und den Server installieren:
   `java -jar neoforge-21.1.xxx-installer.jar --installServer`
2. `blockrave-1.0.0.jar` in den Ordner `mods/` des Servers kopieren.
3. Server starten (`run.bat` bzw. `run.sh`), EULA bestätigen (`eula.txt`).
4. Optional: `world/serverconfig/blockrave-server.toml` anpassen (siehe [KONFIGURATION.md](KONFIGURATION.md)).
5. Speicherplatz einplanen: Standardlimit 2 GiB für hochgeladene Musik (`maxStorageMb`).

Der Server benötigt weder Grafikkarte noch Soundkarte noch Windows. Es werden keine Ports zusätzlich
zum Minecraft-Port benötigt (alles läuft über die normale Minecraft-Verbindung).

## Client einrichten (jeder Spieler)

1. NeoForge-Installer für 1.21.1 ausführen → „Install client“.
2. Im Minecraft-Launcher das Profil „NeoForge 1.21.1“ einmal starten und schließen.
3. `blockrave-1.0.0.jar` nach `%APPDATA%\.minecraft\mods\` (Windows) bzw. `~/.minecraft/mods/` kopieren.
4. Starten und mit dem Server verbinden.

Empfehlung: In den Minecraft-Musikeinstellungen den Regler **„Musikblöcke/Plattenspieler“** nicht auf
0 stellen – er wirkt auch auf die Rave-Musik. Der Regler „Musik“ (Hintergrundmusik) bleibt unberührt.

## Erste Schritte im Spiel

1. **Bühne craften** (Werkbank):
   ```
   Redstone-Lampe | Plattenspieler | Redstone-Lampe
   Notenblock     | Eisenblock     | Notenblock
   Schwarzer Beton| Schwarzer Beton| Schwarzer Beton
   ```
2. **Platzieren:** Bühnen-Item in die Hand, Rechtsklick → transparente Vorschau. Blick bestimmt die
   vordere Mitte der Tanzfläche, **R** dreht, rot markierte Blöcke werden entfernt.
   Erneuter **Rechtsklick** → Bestätigungsansicht mit Serverprüfung → „Bühne aufbauen“.
   **Rücktaste** bricht ab (nichts wird verändert).
3. **DJ-Pult** (Mitte der Bühne) mit Rechtsklick öffnen: „Datei auswählen“ lädt MP3s hoch, Playlist/
   Warteschlange bearbeiten, „▶ Abspielen“. Modus „Live“ für das Senden aus rekordbox
   (siehe [REKORDBOX.md](REKORDBOX.md)).
4. **Einpacken:** DJ-Pult mit einer **Spitzhacke** abbauen. Die Bühne verschwindet, das Bühnen-Item
   kommt genau einmal zurück. Musik und Playlists bleiben gespeichert.

Tasten (änderbar unter *Optionen → Steuerung → BlockRave*): **R** Vorschau drehen, **Rücktaste**
Vorschau abbrechen, **M** Rave-Musik stumm, *(frei)* lokale Grafikeinstellungen.

Admin-Befehle: `/blockrave status`, `/blockrave abbauen` (OP-Level 2; Notfall, falls das DJ-Pult
z. B. per Befehl entfernt wurde – gibt das Bühnen-Item an den ausführenden Spieler).
