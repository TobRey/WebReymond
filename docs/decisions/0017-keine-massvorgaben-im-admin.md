# 0017 – Der Admin-Bereich rechnet um, statt abzulehnen

**Status:** akzeptiert (August 2026)
**Ersetzt** einen Teil von [0014](0014-admin-und-eigene-grafik.md): dort wurde festgelegt, dass
nur exakt passende PNG angenommen werden. Das gilt nicht mehr.

## Kontext

Der Admin-Bereich verlangte PNG in exakt 32 × 32 (Figur), 16 × 16 (Kachel) und 256 Pixel Breite
(Hintergrund). Alles andere wurde abgelehnt. Die Begründung stand als Kommentar im Code: *ein auf
32 × 32 heruntergerechnetes Foto ist kein Pixelbild, sondern Matsch.*

Das Argument stimmt. Die Regel war trotzdem falsch. Wer Grafik einsetzen will, soll zeichnen und
nicht Masse abzählen – und ein grob umgerechnetes Bild ist allemal besser als eine Absage, bei der
man nicht weiss, womit man jetzt anfangen soll.

## Entscheidung

Im Admin-Bereich gibt es keine Massvorgabe mehr. Jedes Format, das der Browser lesen kann, und
jede Grösse. Umgerechnet wird beim Hochladen im Browser; abgelegt wird wie bisher ein PNG in der
Zielgrösse, sodass sich am Paketformat, an `admin.php` und am Spiel nichts ändert.

Drei Regeln, alle in `src/render/fit.js`:

1. **Das Seitenverhältnis bleibt.** Ein 40 × 20 grosses Bild wird in ein 32 × 32 grosses Feld
   eingepasst, nicht zum Quadrat gezerrt. Ein verzerrter Kopf fällt sofort auf, ein durchsichtiger
   Rand niemandem.
2. **Geglättet wird nur, wenn es nötig ist.** Geht das Verhältnis glatt auf – 64 → 32, oder
   überhaupt jede Vergrösserung –, bleibt es hart: Pixelart bleibt scharf. Geht es krumm auf
   (100 → 32), wird geglättet, weil sonst ganze Zeilen wegfielen.
3. **Hintergrundebenen bekommen die Breite des Spiels**, die Höhe folgt dem Verhältnis. Ein sehr
   hohes Bild wird mittig beschnitten statt gestaucht.

## Begründung

Zwei Dinge sind dabei aufgefallen, die beide keine Entwurfsfragen waren, sondern Messwerte:

**Ein Foto als Hintergrundebene sprengt die Bildgrenze.** 800 × 3000 ergibt 256 × 960, und das
sind 667 kB – mehr als ein einzelnes Bild im Paket haben darf. Naheliegend wäre gewesen, die
Grenze anzuheben. Das wäre die schlechtere Wahl: das ganze Paket geht als ein POST an `admin.php`,
und auf einem gewöhnlichen Webspace ist bei acht Megabyte Schluss. Stattdessen wird die Höhe
schrittweise zurückgenommen, bis es passt. Für den, der die Datei einsetzt, gibt es damit keine
Grenze mehr, gegen die er laufen könnte – und das Paket bleibt klein genug, dass der Server es
annimmt.

**Der erste Versuch hat den Browser zum Stehen gebracht.** Die Datei wurde über `FileReader` in
eine Daten-URL verwandelt – base64 bläht um ein Drittel auf, aus sieben Megabyte werden zehn, und
bei einem 1920 × 1080 grossen Bild kam schlicht keine Antwort mehr. Behoben mit
`createImageBitmap`, das die Datei direkt entgegennimmt; ohne diese Änderung wäre ein Handyfoto
unbenutzbar geblieben, also genau der Fall, für den es die ganze Änderung gibt.

## Nachtrag: das Tempo entscheidet, wie eine Ebene benutzt wird

Gemeldet wurde danach: ein eingesetztes Foto „ist nur oben im Bild und deckt nicht alles ab".
Zwei Ursachen, beide meine:

**Die Zeichenschleife lief nur nach oben.** `tiled()` malte die Ebene an ihrer Position und
einmal darüber – das genügt, solange jede Ebene mindestens bildhoch ist, und die mitgelieferten
sind es. Ein Foto im Verhältnis 1:2 wird bei 256 Pixeln Breite aber nur 534 hoch, der Bildschirm
ist bis zu 640. Ab Zeile 534 blieb alles leer. `test/background.test.mjs` rechnet jetzt für jede
Kombination aus Bildhöhe, Ebenenhöhe und Tempo nach, ob eine Zeile ohne Bild bleibt – und meldet
gegen den alten Code genau „ab Zeile 534 ist nichts gezeichnet".

**Ein stehender Hintergrund ist keine mitlaufende Ebene.** Tempo 0 heisst: bewegt sich nicht. Sie
zu wiederholen ergäbe keinen Sinn, sondern nur eine Naht quer durchs Bild. Deshalb füllt eine
Ebene mit Tempo 0 den Bildschirm und wird mittig beschnitten, wo das Seitenverhältnis nicht
passt; alles über 0 wird wie bisher gekachelt. Das braucht kein neues Feld im Paketformat – das
Tempo *ist* die Angabe.

Damit ein stehender Hintergrund dabei nicht vergrössert werden muss, wird er schon beim
Hochladen so gerechnet, dass er den höchsten möglichen Bildschirm deckt (307 × 640 für das
gemeldete Bild), und nicht mehr nur auf die Breite gebracht.

Dafür brauchte es eine eigene Bytegrenze: 476 kB für dieses Bild, gegen 256 kB für ein Sprite.
Mit der Sprite-Grenze verkleinerte sich jede Ebene so weit, bis sie den Bildschirm wieder nicht
mehr deckte – die Verkleinerungsschleife von oben arbeitete also gegen den Zweck. Ebenen haben
jetzt `maxLayerBytes` (768 kB), das Paket insgesamt 5 MB; beide Zahlen stehen doppelt, in
`assetRules.js` und in `admin.php`, und ein Test hält sie zusammen – der fehlte bis jetzt.

## Alternativen

- **Beim Ablehnen bleiben und nur besser erklären.** Ehrlicher gegenüber der Bildqualität,
  aber es löst das Problem des Nutzers nicht.
- **Zuschneiden statt einpassen (`cover`).** Füllt das Feld immer aus, schneidet aber bei einer
  Figur Arme und Beine ab. Für Sprites falsch; für Hintergrundebenen wird es genau deshalb
  benutzt.
- **Immer glätten.** Einfacher, macht aber aus jeder sauberen Pixelzeichnung Matsch – genau der
  Vorwurf, mit dem die alte Regel begründet war.

## Konsequenzen

- Wer Grafik einsetzt, muss nichts mehr vorbereiten.
- Was umgerechnet wurde, steht nach jedem Einsetzen als Meldung da. Stillschweigend umzurechnen
  wäre der bequeme Weg und der falsche: wer ein 200 × 200 grosses Bild einsetzt und ein 32 × 32
  grosses zurückbekommt, soll das lesen und nicht raten.
- Am Server ändert sich nichts. Dort kommt weiterhin ausschliesslich PNG in der Zielgrösse an,
  und die Prüfung bleibt Wort für Wort dieselbe.
- Die Umrechnung selbst ist prüfbar: `src/render/fit.js` kennt kein DOM, und
  `test/fit.test.mjs` prüft, was vorher eine Fehlermeldung war.

## Wiedervorlage

Wenn jemand mit einer Zeichnung ankommt, die durch die automatische Umrechnung sichtbar leidet.
Dann wäre der nächste Schritt kein Verbot, sondern eine Wahl im Admin-Bereich: einpassen,
zuschneiden oder strecken.
