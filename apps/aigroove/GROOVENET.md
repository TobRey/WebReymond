# GrooveNet – die eigene Klang-KI von AI Groove

GrooveNet ersetzt die frühere Anbindung an kostenpflichtige Audio-Dienste
(Stability AI, ElevenLabs). Die Engine läuft vollständig im Browser des
Nutzers: **kein Konto, kein API-Key, keine laufenden Kosten, keine
Nutzungsgrenzen, keine Datenübertragung.**

Alles liegt unter `assets/js/ai/groovenet/`.

---

## Warum kein neuronales Modell?

Ein vortrainiertes Audiomodell wäre mehrere Gigabyte groß und müsste bei
jedem Seitenaufruf geladen werden – auf einem Shared-Hosting-Paket und einem
Telefon ist das ausgeschlossen. GrooveNet erreicht das Ziel deshalb über
einen anderen, für diese Umgebung passenden Weg:

**Analyse durch Synthese.** Statt Audio direkt vorherzusagen, beschreibt die
KI, wie das Ergebnis *aussehen soll*, und sucht dann mit einem
Optimierungsverfahren die Syntheseparameter, die dieser Beschreibung am
nächsten kommen. Das ist derselbe Grundgedanke wie bei einem Diffusionsmodell
– schrittweise Annäherung an ein Ziel – nur mit einem physikalisch
interpretierbaren Klangmodell statt gelernter Gewichte.

Vorteile in dieser Umgebung: rund 120 KB Quelltext statt Gigabyte,
Ergebnisse in Sekunden, offline lauffähig, deterministisch reproduzierbar
und jederzeit nachvollziehbar.

---

## Verarbeitungskette

| Schritt | Datei | Aufgabe |
|---|---|---|
| 1. Verstehen | `lexicon.js`, `encoder.js` | Text → Absicht und 16 Klangachsen |
| 2. Ziel setzen | `target.js` | Absicht → gewünschtes Spektrogramm |
| 3. Start ableiten | `genome.js` | Absicht → 51 Syntheseparameter |
| 4. Suchen | `optimizer.js` | Evolution gegen das Ziel |
| 5. Erzeugen | `synth.js` | Genom → Audiosignal |
| 6. Messen | `fft.js`, `features.js` | Signal → Fingerabdruck |
| 7. Anordnen | `compose.js` | Schleifen mit Drums, Bass, Akkorden |
| 8. Ausgeben | `master.js` | Pegel, Begrenzer, saubere Ränder |
| Fassade | `index.js` | `generate()` und `transform()` |
| Rechenstrang | `../../workers/groovenet-worker.js` | hält die Oberfläche flüssig |

### 1. Sprachverständnis

`lexicon.js` enthält rund 300 Begriffe (deutsch und englisch), 23 Klangarten
und 36 Stilrichtungen. Jeder Begriff verschiebt Werte auf 16 Achsen:
Helligkeit, Wucht, Dichte, Rauheit, Anschlag, Ausklang, Rauschanteil,
Tonhaftigkeit, Bewegung, Breite, Raum, Sättigung, Metallik, Wärme,
Tonhöhensturz, Komplexität.

`encoder.js` erkennt zusätzlich:

* Wortgruppen vor Einzelwörtern (`open hat` schlägt `hat`)
* zusammengesetzte Wörter (`technokick` → `techno` + `kick`)
* Tippfehler über Trigramm-Ähnlichkeit und Editierdistanz (`snaer` → `snare`)
* Verstärker (`sehr`, `ultra`, `leicht`) und Verneinungen (`kein Hall`)
* Tempo, Tonart, Tonleiter, Taktzahl und Länge
* ob ein Einzelklang oder eine Schleife gewünscht ist

Füllwörter und Fachangaben stehen auf einer Stoppliste – ohne sie würde die
Ähnlichkeitssuche aus `moll` ein `voll` machen.

### 2. Zielspektrum

`target.js` baut aus den 16 Achsen einen künstlichen Fingerabdruck: ein
Spektrogramm aus 28 Mel-Bändern über 20 Zeitschritte, dazu Hüllkurve und
Kennzahlen (Helligkeit, Flachheit, Tonhaftigkeit). Hohe Bänder klingen im
Ziel früher aus als tiefe, weil sich fast alle realen Klänge so verhalten.

### 3. Synthese

`synth.js` ist ein vollständiger Synthesizer: Oszillatoren mit
Wellenform-Morphing und PolyBLEP-Korrektur, Unisono, FM, Sub-Ton, gefärbtes
Rauschen, ein modaler Resonator für Fell/Metall/Holz, ein
Zustandsvariablen-Filter, Sättigungskennlinien, Bit- und Ratenreduktion,
Transientenformer, Neigungsentzerrer sowie Nachhall und Stereobreite
(Tiefen bleiben in der Mitte).

Wichtig: Anregung und schwingender Körper haben getrennte Hüllkurven. Eine
Glocke wird kurz angeregt und klingt danach von selbst aus – ohne diese
Trennung klänge jede Glocke wie ein langsam aufziehendes Pad.

### 4. Optimierung

`optimizer.js` verwendet **Differentielle Evolution**: Aus je drei
bestehenden Kandidaten entsteht ein neuer; behalten wird nur, was messbar
besser ist. Das Verfahren braucht keine Ableitungen – die durch Filter,
Sättigung und Resonatoren laufende Kette lässt sich ohnehin nicht sinnvoll
differenzieren.

Bewertet wird zuerst bei reduzierter Abtastrate (rund 60 000 Abtastwerte je
Kandidat, unabhängig von der Länge), danach werden die aussichtsreichsten
Kandidaten bei voller Auflösung geprüft und der beste noch einmal
nachgebessert. Zusätzliche Strafen verhindern Stille, Dauerübersteuerung,
reine Knackser und Gleichspannung.

Typischer Lauf: 150–700 bewertete Kandidaten in 2–3 Sekunden.

### 5. Klang zu Klang

`transform()` zerlegt das vorhandene Sample in ein Spektrogramm, vergleicht
es Band für Band mit dem Zielspektrum aus dem Prompt und verschiebt es in
dessen Richtung – ein zeitvariabler Vielband-Entzerrer, dessen Einstellungen
die KI berechnet. Die Phase des Originals bleibt erhalten, damit der
Charakter hörbar bleibt; bei starker Veränderung räumen einige
Griffin-Lim-Schritte nach. Danach folgen Sättigung, Neigung und
Anschlagformung aus dem abgeleiteten Genom.

---

## Rechenqualität

Unter „KI" wählbar; die Einstellung steuert Rechenzeit und Bevölkerungsgröße
des Optimierers.

| Stufe | Budget je Variante | Wofür |
|---|---|---|
| Schnell | 0,7 s | Ausprobieren, ältere Telefone |
| Ausgewogen | 2,2 s | Vorgabe |
| Beste Qualität | 6,0 s | wenn der Prompt genau sitzen soll |

Die Berechnung läuft in einem Modul-Worker. Ist der nicht verfügbar, rechnet
dieselbe Engine im Hauptstrang weiter – es fällt nichts aus.

---

## Grenzen (ehrlich benannt)

* GrooveNet erzeugt **synthetische** Klänge. Es kann keine echte Aufnahme
  einer Gitarre, einer Stimme oder eines Orchesters nachbilden. Für Drums,
  Bass, Synths, Flächen und Effekte ist das kein Nachteil, für
  „echter Gesang" schon.
* Die Passgenauigkeit misst den Abstand zum selbst aufgestellten Ziel, nicht
  einen Publikumsgeschmack. Werte um 50–75 % sind normal und klingen gut;
  metallische Klänge liegen naturgemäß niedriger, weil sich ihre einzelnen
  Teiltöne in der Mel-Darstellung nicht sauber abbilden lassen.
* Lange Flächen (über 8 Sekunden) werden bei niedriger Abtastrate bewertet.
  Der Optimierer hat dort weniger Durchläufe; das Ergebnis stützt sich
  stärker auf die abgeleiteten Startwerte.
