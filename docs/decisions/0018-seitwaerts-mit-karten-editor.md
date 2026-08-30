# 0018 – Mogli läuft seitwärts, und die Levels baut der Nutzer

**Status:** akzeptiert (August 2026)
**Ersetzt** [0012](0012-ein-knopf-steuerung.md) (Ein-Knopf-Steuerung) und den
Levelgenerator-Teil von [0011](0011-mogli-ohne-build-schritt.md); der Rest von 0011
(kein Build-Schritt, keine Binärdaten im Repo, eine ZIP) gilt unverändert.

## Kontext

Bis Fassung 2.x war Mogli ein endloser Turm: prozedural erzeugte Ebenen, aufsteigende
Glut, ein Knopf. Der Nutzer will stattdessen ein klassisches, seitwärts scrollendes
Jump'n'Run mit Mario-artiger Steuerung – und vor allem will er die Levels **selbst
bauen**: Hintergrund und Plattform-Bauteile hochladen, Elemente in ein Raster ziehen,
je Element Verhalten einstellen, später Türen, Portale und Gegner ergänzen.

Das ist kein Feature am Rand, sondern eine andere Architektur: ein Levelgenerator hat
keinen Platz mehr, dafür braucht es ein Kartenformat, einen Editor und einen
Element-Katalog, den beide Seiten (Editor und Spiel) teilen.

## Entscheidung

1. **Steuerung:** links/rechts + Sprung. Variable Sprunghöhe (früh loslassen bricht
   den Sprung ab), Coyote-Zeit und Sprungpuffer bleiben. Auf Touch-Geräten drei
   halbtransparente Knöpfe (◀ ▶ links, Sprung rechts), Tastatur Pfeile/AD +
   Leertaste, Gamepad. Wandsprung und Doppelsprung gibt es nicht mehr.
2. **Ein Element-Katalog als eine Quelle** (`web/src/game/elements.js`): je Typ Name,
   Standardgrösse, Eigenschaften mit Grenzen, Verhalten, Wunschtext für die Grafik.
   Editor-Palette, Eigenschaften-Panel, Prüfregeln und Spielverhalten lesen alle
   denselben Katalog; ein neuer Typ ist ein Eintrag, kein Umbau.
3. **Karten sind Daten** (`{cols, rows, cell, spawn, elements[]}`), geprüft von
   `net/mapRules.js` im Browser und gespiegelt in `admin.php` – nach dem Muster, das
   sich beim Grafikpaket bewährt hat; ein Node-Test vergleicht beide Zahlenwerke.
   Gespeichert wird über `admin.php` in `data/maps.json`, gelesen über `maps.php`;
   ohne PHP bleibt localStorage samt Datei-Export.
4. **Eigenschaften statt Prompts:** je Element strukturierte Einstellungen (Zahlen,
   Auswahlen, Ziel-Verweise) plus ein Freitext-Notizfeld, das das Spiel nie liest.
   Echte Prompt-Deutung bräuchte einen KI-Dienst auf dem Server – den gibt es hier
   bewusst nicht (kein Build, keine Abhängigkeiten, beliebiger Webspace), und ein
   Feld, das nur so tut, als verstünde es Sätze, wäre eine Falle. Mit dem Nutzer so
   abgesprochen.
5. **Bestzeiten je Level** statt einer Punkteliste: `score.php` sortiert aufsteigend
   nach Ticks je Karten-ID. Smaragde geben Zeitgutschrift, die Uhr hält nie an.
6. **Die eingebaute Demo-Karte** liegt als Daten in `map.js` und durchläuft dieselbe
   Prüfung wie jede Editor-Karte. Ein Test spielt sie mit einem einfachen
   Vorausschau-Läufer durch – die ZIP ist also auch ohne jeden Upload beweisbar
   schaffbar.

## Begründung

Der Katalog als eine Quelle ist die wichtigste der sechs Entscheidungen: die 2.x-Fehler
der Sorte „Admin erlaubt, was das Spiel ablehnt“ entstanden immer dort, wo zwei Stellen
dieselbe Regel getrennt pflegten. Deshalb wird auch das bewährte Spiegel-Muster
(JS-Regeln ↔ PHP-Konstanten ↔ Abgleichtest) unverändert auf Karten übertragen.

Der Determinismus-Test aus 2.0 (gleicher Lauf bei jeder Bildhöhe) bleibt Pflicht und
wurde auf die Seitwärts-Welt umgeschrieben: die Kamera ist weiterhin reine Darstellung.

## Folgen

- `hazard.js` (Glut), `bot.js` (Auto-Kletterer) und der Generator sind gelöscht;
  Levels testet man, indem man sie spielt („Testen“ öffnet `?map=<id>`).
- Alte Grafikpakete bleiben gültig (die acht Animationsnamen bestehen weiter);
  Kachel-Slots wurden auf die drei Gitterarten (fest, durchspringbar, bröckelnd)
  abgebildet, Stacheln und Smaragde sind jetzt Elemente mit eigener Trefferfläche.
- Element-Bilder sind vorerst Standbilder (GIF → erstes Bild). Animierte Elemente
  wären der nächste Schritt, ändern aber das Paketformat nicht.
