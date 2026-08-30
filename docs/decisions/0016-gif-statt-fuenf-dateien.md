# 0016 – Ein GIF darf die fünf Einzelbilder ersetzen

**Status:** akzeptiert (August 2026)
**Ergänzt** [0014](0014-admin-und-eigene-grafik.md) – am Paketformat und am Admin-Bereich ändert
sich nichts, nur die Art, wie Bilder hineinkommen.

## Kontext

Der Admin-Bereich verlangte acht Bewegungen mal fünf PNG-Dateien: vierzig einzelne Dateien,
einzeln exportiert, einzeln hochgeladen, in der richtigen Reihenfolge. Wer eine Bewegung zeichnet,
hat sie am Ende aber sowieso schon als GIF vorliegen – aus Aseprite, aus Piskel, aus einem
Anhang. Vierzigmal „Export Frame As…" ist Fleissarbeit, bei der man sich vertut.

## Entscheidung

Neben jeder Bewegung sitzt eine Fläche für ein animiertes GIF. Sie füllt alle fünf Plätze auf
einmal.

**Das GIF wird im Browser zerlegt und als die schon bekannten fünf PNG abgelegt.** Damit ändert
sich nichts am Paketformat, nichts an `admin.php`, nichts am Spiel – auf dem Server kommt
weiterhin ausschliesslich PNG an, und die Prüfung dort bleibt Wort für Wort dieselbe.

Wie viele Bilder in die fünf Plätze kommen, hängt davon ab, wie viele das GIF hat:

- **bis zu fünf** – der Reihe nach, jedes mindestens einmal.
- **mehr als fünf** – ausgewählt nach ZEIT, nicht nach Position.

## Begründung

Zum Zerlegen musste ein GIF-Leser her (`src/render/gif.js`, rund 300 Zeilen). Das ist mehr Code,
als mir lieb ist, aber es gibt keinen Weg daran vorbei: ein Browser gibt die Bilder in einem GIF
nicht einzeln heraus. `<img>` spielt es ab, `drawImage` erwischt je nach Zufall irgendeines, und
`ImageDecoder` gibt es auf iOS nicht – also auf genau dem Gerät, um das es hier geht.

Die zwei Regeln für die Bildzahl sehen willkürlich aus, sind es aber nicht. Beide sind an einem
Fall entstanden, der schiefging:

- **Nach Zeit auszuwählen ist bei wenigen Bildern falsch.** Ein GIF aus drei Bildern, dessen
  letztes eine Sekunde steht und dessen erste beiden je 40 ms dauern, ist zeitlich zu 93 % das
  letzte Bild. Nach Zeit ausgewählt bekommt man fünfmal dasselbe – aus der Bewegung wird ein
  Standbild. Der Test dazu ist beim ersten Lauf genau darüber gefallen. Ein gezeichnetes Bild
  wegzuwerfen ist immer falsch.
- **Nach Position auszuwählen ist bei vielen Bildern falsch.** Bei acht Bildern, von denen sieben
  aufblitzen und eines steht, gewichtet jedes zweite Bild das stehende genauso wie die anderen.
  Nach Zeit sieht das Ergebnis aus wie das GIF; nach Position sieht es nur ähnlich aus.

Die Abstände selbst kann das Spiel ohnehin nicht übernehmen: es läuft je Bewegung im festen Takt
(`ticksPerFrame` in `game/player.js`).

## Alternativen

- **Ein Bildstreifen (fünf Bilder nebeneinander in einem PNG).** Weniger Code, aber es
  verlangt vom Zeichner einen Zwischenschritt, den das GIF gerade erspart.
- **Das GIF unverändert speichern und im Spiel abspielen.** Hätte den Leser auch gebraucht, dazu
  ein zweites Format im Paket, eine zweite Prüfung in `admin.php` und eine zweite Zeichenart im
  Spiel. Der Gewinn wäre die GIF-eigene Taktung gewesen – die das Spiel nicht benutzt.
- **Eine fertige Bibliothek.** `games/mogli` hat bewusst keinen Build-Schritt und keine
  Abhängigkeiten ([0011](0011-mogli-ohne-build-schritt.md)). Eine Bibliothek von einem CDN wäre
  ein fremder Server im Ladeweg.

## Nachweis

Ein selbstgeschriebener Leser, der von selbstgeschriebenen Testdateien gelesen wird, beweist
nichts: enthielten beide denselben Denkfehler, wären alle Tests grün. In dieser Umgebung kann
kein Werkzeug GIF erzeugen (das vorhandene ffmpeg ist auf Video zusammengestrichen, Pillow und
gifsicle fehlen), also musste ein eigener Schreiber her – und damit erst recht eine fremde
Instanz.

Die ist **Chromiums eigener `ImageDecoder`**. `tools/gif-gegenprobe.mjs` lässt dieselben zehn
Dateien von beiden lesen und vergleicht Pixel für Pixel: verschränkte Zeilen, Teilbilder mit
Versatz, Durchsichtigkeit, beide Räum-Verfahren, eigene Farbtabelle je Bild, lange LZW-Ketten.
Alle zehn stimmen überein.

Dazu der ganze Weg im Browser: ein GIF mit acht Bildern über `/admin` eingesetzt, gespeichert,
Spielseite geladen – und auf dem Spielfeld gezählt, wie oft die zwei Farben vorkommen, die es
nur in diesem GIF gibt (960 und 1088 Punkte).

## Konsequenzen

- Vierzig Dateien werden zu acht.
- 300 Zeilen Code mehr, die ein Dateiformat lesen. Das ist der Preis, und er ist nicht klein.
- Auf dem Server ändert sich nichts – die Prüfung dort kennt weiterhin nur PNG.
- Wer ein GIF auf einen einzelnen Platz zieht, wird auf den GIF-Knopf hingewiesen, statt nur
  eine Absage zu bekommen.

## Wiedervorlage

Wenn `ImageDecoder` auch auf iOS ankommt, kann `gif.js` dort dagegen ausgetauscht werden – der
eigene Leser bliebe als Rückfall. Vorher lohnt es nicht.
