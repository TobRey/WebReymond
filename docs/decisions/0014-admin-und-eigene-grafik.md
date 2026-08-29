# 0014 – Eigene Grafik über einen Admin-Bereich, ohne Datenbank

**Status:** akzeptiert (August 2026)
**Ergänzt** [0011](0011-mogli-ohne-build-schritt.md) (kein Build-Schritt, keine Datenbank,
keine Binärdateien im Repo) und [0013](0013-mogli-fuellt-den-bildschirm.md) (die Spielseite
besteht aus nichts als dem Spiel).

## Kontext

Gewünscht war ein Bereich unter `/admin`, in dem sich ohne Entwickler eigene Sprites einsetzen
lassen: je Bewegung fünf Bilder, dazu Hintergrund und Plattformen, mit der Möglichkeit,
**Hitboxen einzuzeichnen**.

Das trifft auf ein Spiel, dessen Grafik bisher vollständig im Quelltext steht – als
Zeichenketten und als prozedurale Zeichenbefehle. Genau das war Absicht (0011: keine
Binärdateien), und genau das steht dem Wunsch im Weg.

## Entscheidung

1. **Die mitgelieferte Grafik bleibt, wie sie ist.** Ein Paket legt sich darüber, es ersetzt
   nichts im Repo. Ohne Paket – kein PHP, nichts hochgeladen, ein Bild kaputt – sieht das Spiel
   aus wie immer. Ein Grafikpaket ist Zierde, keine Voraussetzung.
2. **Ein Paket ist eine JSON-Datei mit base64-Bildern**, abgelegt in `data/assets.json`, im
   selben Muster wie die Bestenliste: `flock`, atomares `rename`, keine Datenbank.
3. **Die Regeln stehen einmal** in `src/net/assetRules.js` als reine Funktionen und laufen an
   drei Stellen: im Admin-Bereich vor dem Senden, in `admin.php` vor dem Schreiben (dort
   massgeblich) und im Test. Dieselbe Aufteilung wie bei `scoreRules.js`.
4. **Einsetzen ist platzweise.** Wer drei von fünf Laufbildern hochlädt, bekommt für die
   anderen zwei weiterhin die mitgelieferte Grafik. Ausnahme Hintergrund: dessen drei Ebenen
   sind aufeinander abgestimmt und werden nur zusammen ersetzt.
5. **Alle Bewegungen haben fünf Bilder** – auch die mitgelieferten. Damit haben eingesetzte und
   mitgelieferte Grafik dieselbe Form, und es braucht keinen Sonderfall für „diese Bewegung hat
   nur zwei Bilder". Das Tempo unterscheidet die Bewegungen, nicht die Anzahl.
6. **Einzeichnen lässt sich, WO eine Kachel fest ist – nicht, WAS sie bedeutet.**

## Was bewusst nicht geht

Die Kachelart (fest, nur von oben tragend, tödlich) ist nicht einstellbar, und an Wänden ist
auch der Kasten gesperrt.

Der Grund ist kein Vorsichtsprinzip, sondern eine konkrete Zusage: `test/level.test.mjs`
beweist für jede vorkommende Kombination aus Absatzbreite und Lücke, dass sie mit der echten
Physik überspringbar ist. Wer „Stein" zu „tödlich" erklären oder eine Wand verschmälern
könnte, hätte einen Turm, den niemand mehr besteigen kann – und der Beweis wäre wertlos.
Einstellbar ist deshalb nur die Geometrie innerhalb einer Zelle.

Damit auch das nicht ins Auge geht, hat der Admin-Bereich einen Reiter **Vorschau**: dort läuft
das echte Spiel mit dem noch nicht gespeicherten Paket, und der echte Automat aus `game/bot.js`
spielt darin. Kommt er nicht mehr vom Fleck, sagt die Oberfläche das, bevor gespeichert wird.

## Sicherheit, ohne Schönfärberei

**Der Code 6713 ist kurz.** Vier Ziffern sind 10 000 Möglichkeiten. Was ihn brauchbar macht,
ist nicht seine Länge, sondern die Ratenbegrenzung: fünf Versuche je zehn Minuten und
gehashter IP, mit demselben Verfahren wie in `score.php`. Geprüft wird serverseitig und mit
`hash_equals`; im ausgelieferten JavaScript steht die Zahl nirgends. `ADMIN_CODE` nimmt jede
Zeichenkette – ein längeres Wort dort ist der einzige wirkliche Schutz, und das steht so in der
README.

Der Preis: wer sich fünfmal vertippt, sperrt sich für zehn Minuten selbst aus. Das ist die
richtige Seite, auf der man irrt.

**Hochgeladene Bilder werden nie als Dateien abgelegt.** Alles wandert als base64 in eine
einzige JSON-Datei. Damit gibt es keinen Pfad, unter dem eine als PNG getarnte `.php` im
Webverzeichnis landen und ausgeführt werden könnte – die häufigste Art, wie Bilder-Uploads zur
Übernahme eines Webspace führen. Zusätzlich wird die **PNG-Signatur** geprüft, nicht nur der
MIME-Typ im Text davor; den kann jeder frei behaupten.

Nachgewiesen mit `curl` gegen einen laufenden Server: Schreiben ohne Marke → 401, als PNG
getarntes `<?php` → 400, unbekannte Kachel → 400, Kasten an der Wand → 400, sieben falsche
Versuche → ab dem fünften 429.

**`data/` bleibt gesperrt.** Die vorhandene `.htaccess` verwehrt den direkten Abruf; `assets.php`
ist das einzige Fenster darauf und gibt ausschliesslich `pack` heraus, nie den Rest der Ablage.

**Die ZIP bleibt binärfrei.** `pack.mjs` lehnt Binärdateien ab und schliesst `data/` aus. Eigene
Grafiken liegen nur auf dem Server des Nutzers, nie im Repository und nie in der Auslieferung.

## Folgen

- Die Kollision prüft nicht mehr nur die Zelle unter der vorderen Kante, sondern alle
  überdeckten Zellen gegen ein Rechteck je Kachelart (`game/tilebox.js`). Voreingestellt ist
  überall die ganze Zelle, die Physik rechnet ohne Paket also unverändert.
- `PHYS` ist zur Laufzeit veränderbar, weil ein Paket die Trefferfläche setzen darf. Das war
  vorher faktisch konstant.
- Der Admin-Bereich ist eine zweite Seite im Auslieferungsordner. Die Spielseite verweist
  bewusst nicht darauf: sie soll nach 0013 aus nichts als dem Spiel bestehen.
- Ohne PHP fällt der Admin-Bereich auf `localStorage` zurück und bietet „Als Datei laden" an.
  Das ist kein Notbehelf für kaputte Server, sondern der Normalfall für jeden Webspace ohne PHP.

## Wann neu bewerten

Wenn Grafik von mehreren Leuten gepflegt werden soll. Dann reicht ein gemeinsamer Code nicht
mehr, und es braucht Konten – womit die Entscheidung aus 0011 (keine Datenbank) zur Debatte
stünde.
