# Installation und Einstellungen

## Voraussetzungen

* Minecraft **1.21.1** mit **NeoForge 21.1.264 oder neuer** (wie BlockRave – beide Mods vertragen sich und
  können zusammen in einem Profil liegen).
* Die Mod-Datei `custompaintings-1.0.0.jar` muss **auf dem Server und bei allen Spielern** im Ordner `mods`
  liegen. Ohne Mod auf dem Client kann man nicht beitreten.

## CurseForge (Spieler)

1. Profil öffnen (z. B. das BlockRave-Profil) → **„⋯“ → „Open Folder“**.
2. `custompaintings-1.0.0.jar` in den Ordner **`mods`** kopieren (mit dem Windows-Explorer).
3. Spiel starten.

## Server

`custompaintings-1.0.0.jar` in den Ordner `mods/` des NeoForge-Servers legen und neu starten. Im Log erscheint
`Custom Paintings: Bildspeicher …`. Die Bilder liegen im Weltordner unter `world/custompaintings/`
(jedes Bild nur einmal, auch wenn es auf mehreren Gemälden hängt). Bei Backups der Welt ist alles dabei.

## Bedienung

* **Shift + Rechtsklick** auf ein aufgehängtes Gemälde öffnet „Gemälde gestalten“.
* **Bild auswählen …** öffnet den Dateidialog; alternativ die Datei ins Minecraft-Fenster ziehen.
* Formate: **PNG, JPEG, BMP, GIF** (bei GIF das erste Bild). WebP/HEIC/AVIF bitte vorher als PNG/JPEG speichern.
* **Anpassung:** *Füllen (zuschneiden)* – füllt immer das ganze Gemälde (Standard); *Einpassen (Ränder)* – ganzes
  Bild sichtbar, schwarze Ränder; *Strecken* – auf das Format gezogen.
* **Übernehmen** setzt das Bild, **Original** stellt das Minecraft-Motiv wieder her.
* Ändern darf, wer dort bauen darf: nicht im Zuschauer- oder Abenteuermodus, nicht im Spawnschutz, höchstens
  8 Blöcke entfernt; Schutz-Mods, die die Interaktion mit Gemälden sperren, werden beachtet.

## Einstellungen

Server: `world/serverconfig/custompaintings-server.toml`

| Schlüssel | Standard | Bedeutung |
|---|---|---|
| `bilder.operatorsOnly` | `false` | Nur Operatoren dürfen Gemälde gestalten |
| `bilder.maxImageKb` | `4096` | Größte Bilddatei pro Gemälde (KB, nach dem Zuschneiden) |
| `bilder.maxStorageMb` | `2048` | Speicher für alle Bilder zusammen (MB) |
| `bilder.maxEditDistance` | `8` | Maximaler Abstand zum Gemälde (Blöcke) |

Client: `config/custompaintings-client.toml` (auch im Spiel unter Mods → Custom Paintings → Konfiguration)

| Schlüssel | Standard | Bedeutung |
|---|---|---|
| `pixelsPerBlock` | `64` | Auflösung eigener Bilder (Vanilla: 16). 4×4-Gemälde = 256×256 Pixel |
| `diskCache` | `true` | Empfangene Bilder in `custompaintings-cache/` speichern (schneller beim nächsten Mal) |
