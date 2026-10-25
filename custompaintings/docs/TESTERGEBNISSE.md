# Testergebnisse – Custom Paintings

Stand: 25.10.2026, CI-Lauf „Custom Paintings“ #1 (Commit 28c309b). Hier steht nur, was **tatsächlich ausgeführt**
wurde; Ungetestetes ist als **UNGETESTET** markiert.

## 1. Kern-Unit-Tests (ohne Minecraft) – 12/12 BESTANDEN

Lokal und im CI: Zielgröße je Block (64 px, Begrenzung 1024 px), **Füllen** schneidet mittig zu und füllt
vollflächig (Quer- und Hochformat), **Einpassen** zeigt alles mit Rand, **Strecken**, Transparenz → Hintergrund,
starkes Verkleinern, Einlesen von **PNG, JPEG, BMP, GIF**, verständliche Ablehnung von WebP, Serverprüfung
(nur PNG, Byte- und Pixelgrenzen, abgeschnittene Datei, gefälschter Kopf mit riesigen Abmessungen wird vor dem
Dekodieren abgelehnt), inhaltsadressierter Speicher (kein Pfad-Ausbruch, keine temporären Reste),
Blockzusammensetzung (Lücke, Überlappung, Überlauf).

## 2. Dedizierter GameTest-Server – 3/3 BESTANDEN („All 3 required tests passed“)

* `uploadAssignPersistReset`: Upload in mehreren 16-KB-Blöcken → geprüft, gespeichert, am Gemälde gesetzt;
  Gemälde über NBT gespeichert und neu geladen → Bild-ID erhalten; zweites Gemälde mit demselben Bild sofort
  ohne Übertragung; Zurücksetzen ändert andere Gemälde nicht.
* `rejectsInvalidAndForeign`: abgelehnt werden zu weit entfernte Spieler (20 Blöcke), Zuschauer, Abenteuermodus,
  ungültige ID (`../../evil`), nicht existierendes Gemälde, zu große Datei, falsche Prüfsumme, Zufallsdaten
  („kein PNG“, wird auch nicht gespeichert); danach klappt der berechtigte Upload.
* `payloadCodecs`: Netzwerkpakete verlustfrei, zu große Blöcke werden abgelehnt.

## 3. Produktiver Server – BESTANDEN

Offizieller NeoForge-21.1.264-Installer + gebaute JAR: Server startet, „Custom Paintings: Bildspeicher …“.

## 4. Client-Rauchtest (echter Client, Xvfb, Software-OpenGL) – BESTANDEN

4×2-Gemälde („fighters“) an einer Wand, Kamera davor. Pixelmessung im echten Bild (Testbild links rot, rechts blau):

| Schritt | Messung |
|---|---|
| Vanilla-Motiv | links (154,153,140), rechts (78,94,144) |
| Eigenes Bild hochgeladen | links **(167,15,15)**, rechts **(15,29,167)** → sichtbar und **richtig herum** |
| Gestaltungs-Oberfläche | geöffnet, Vorschau erzeugt, Screenshot |
| „Original“ | wieder (154,153,140) / (78,94,144) |
| Bekanntes Bild erneut | ohne erneute Übertragung gesetzt |

## 5. Mehrspieler-E2E: echter Server + zwei echte Clients – BESTANDEN

| Prüfung | Ergebnis |
|---|---|
| A hängt ein Gemälde mit dem **normalen Gegenstand** auf | zufälliges Motiv „burning_skull“ (4×4) |
| A lädt eigenes Bild über das Netzwerk hoch | OK, sichtbar und richtig herum (167,15,15)/(15,29,167) |
| B tritt **später** bei | bekommt Bild-ID, lädt das Bild vom Server, sieht es richtig herum |
| Server-Neustart | Bild noch am Gemälde und sichtbar |
| A2 stellt das Original wieder her | Minecraft-Motiv wieder sichtbar |

## 6. UNGETESTET

* **Echter Dateidialog** (Windows-Explorer-Dialog über tinyfd) und **Drag & Drop** einer Datei ins Fenster –
  im CI wird das Bild direkt an die Oberfläche übergeben; der Lade- und Verarbeitungsweg dahinter ist getestet.
* Windows/macOS (CI läuft unter Linux), echte Grafikkarten, Shader-Packs (Iris/Oculus).
* Sehr große Bildsammlungen (viele hundert Gemälde) und Spieler mit langsamer Verbindung.
* Zusammenspiel mit konkreten Schutz-Mods (das normale Interaktions-Ereignis wird geprüft).
