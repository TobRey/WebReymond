# 0012 – Mogli wird ein Ein-Knopf-Spiel, und der Turm folgt der Sprungbahn

**Status:** akzeptiert (August 2026)
**Ergänzt** [0011](0011-mogli-ohne-build-schritt.md) – die Auslieferung als ZIP, der Verzicht
auf Build-Schritt und Datenbank und die Sprites als Zeichenketten bleiben unverändert gültig.

## Kontext
Die erste Fassung war ein Kletterspiel mit voller Steuerung: links, rechts, springen,
doppelspringen, durchfallen, dazu Bildschirmtasten fürs Handy. Sie funktionierte, war aber
weder besonders schnell noch besonders eigen – und auf dem Handy verdeckten die Tasten einen
guten Teil des Spielfelds.

Gewünscht war stattdessen: Dschungel statt Weltraum, Mogli als Figur, **keine Knöpfe mehr –
nur noch auf den Bildschirm tippen**, kein Doppelsprung, Smaragde und Ranken zum Einsammeln,
und ein schnelles Spiel „zum Reinschwitzen".

## Entscheidung
1. **Ein einziger Eingabekanal.** Die gesamte Spielfläche ist der Knopf; ein Tipp springt.
   Tastatur und Gamepad bleiben als Bequemlichkeit am Rechner, mehr Eingaben gibt es nicht.
2. **Mogli läuft von allein.** Die Richtung dreht sich an genau zwei Stellen: beim Wandsprung
   und wenn er am Boden in eine Wand läuft.
3. **Kein Doppelsprung, keine variable Sprunghöhe.** Jeder Sprung ist gleich hoch.
4. **Der Lauf beginnt beim ersten Tipp.** Bis dahin steht alles still, auch die Glut.
5. **Die Form des Turms wird aus der Sprungbahn hergeleitet**, nicht umgekehrt: eine
   Zickzack-Treppe mit fest drei Kacheln Höhenunterschied, und die waagerechte Lücke wird
   zuerst gewürfelt, die Absatzbreite daraus abgeleitet.
6. **Ranken** sind der einzige Weg, schneller nach oben zu kommen: sie setzen die Schwerkraft
   für 15 Ticks aus und reissen Mogli acht Kacheln hoch.

## Begründung
- **Ein Knopf verträgt keine Zufälle.** Wenn es nur eine Eingabe gibt, muss sie immer
  ankommen. `engine/input.js` zählt Tastendrücke, statt einen Zustand zu merken: ein Finger
  kann zwischen zwei Logikschritten tippen und wieder loslassen, und genau dieser Tipp ginge
  sonst verloren. Gehorcht wird `pointerdown`, nicht `click` – letzteres feuert erst beim
  Loslassen.
- **Variable Sprunghöhe wäre hier ein Fehler.** Jeder Absatz liegt drei Kacheln höher; ein
  kurzer Tipp müsste also trotzdem 48 px schaffen. Eine Kappung beim Loslassen würde genau das
  kaputt machen. Ohne sie hängt alles am Zeitpunkt und nichts an der Haltedauer – das ist die
  Fähigkeit, die das Spiel abfragen soll.
- **Der Doppelsprung nimmt dem Wandsprung die Rolle.** Mit ihm ist jeder Fehler billig zu
  reparieren; ohne ihn ist die Wand die einzige Rettung, und der Wandsprung wird zum
  eigentlichen Werkzeug.
- **Der Höhenunterschied von drei Kacheln ist gerechnet, nicht gewählt.** Zwei wären
  unmöglich: Mogli ist 16 px hoch, die Unterkante des oberen Absatzes läge auf Kopfhöhe. Vier
  lägen über dem Sprungscheitel. Ebenso die Lücke: mindestens drei Kacheln, sonst gerät er
  unter den Zielabsatz, bevor er hoch genug ist; höchstens vier, weil er nur rund 20 Ticks über
  der Kante ist und dabei 64 px schafft. Die Schwierigkeit steckt deshalb in der Breite der
  Landefläche, nicht in der Weite des Sprungs.
- **Das Stillstehen am Anfang ist kein Komfort, sondern notwendig.** Liefe Mogli sofort los,
  hätte man nach dem Start eine Fünftelsekunde bis zum ersten Abgrund. Dieser erste Tipp weckt
  ihn nur; springen würde ihn über den ganzen Startabsatz hinaustragen.

## Alternativen
- **Bildschirmtasten beibehalten:** vertraut, aber sie verdecken auf dem Handy einen guten Teil
  des Spielfelds, und der Anspruch verlagert sich vom Zeitpunkt auf die Fingerakrobatik.
- **Tippen = springen, Halten = höher:** übliche Lösung, hier aber unvereinbar mit
  Pflichtsprüngen über drei Kacheln (siehe oben).
- **Richtung bei jeder Wandberührung drehen statt erst beim Wandsprung:** liest sich einfacher,
  nimmt aber die Möglichkeit, sich an einer Wand bewusst fallen zu lassen.
- **Zufällige Absatzhöhen mit Erreichbarkeitsprüfung im Nachhinein:** wäre abwechslungsreicher,
  aber der Generator müsste jede Kombination selbst durchsimulieren. Eine feste Höhe und eine
  abgeleitete Breite erreichen dasselbe ohne diese Maschinerie.

## Konsequenzen
- **Die Physikwerte sind jetzt Vertragsbestandteil des Generators.** Wer `PHYS` ändert, ändert
  damit, welche Türme begehbar sind. `test/level.test.mjs` spielt deshalb jede vorkommende
  Kombination aus Absatzbreite und Lücke mit der echten Physik durch, statt sie nachzurechnen –
  einmal früh und einmal kantengenau abgesprungen.
- **Der Automat wurde einfacher.** Er muss nur noch entscheiden, wann getippt wird: an der Wand
  immer, am Boden bei einer Kante voraus. Zwei Regeln statt Absprungpunktsuche.
- **Die Bildschirmtasten sind ersatzlos entfallen**, ebenso die Durchfall-Eingabe. Blatt­
  plattformen halten weiterhin nur von oben, aber bewusst hindurchfallen kann man nicht mehr.
- **Auf dem Handy muss nichts mehr markiert oder gezoomt werden können.** `user-select`,
  `-webkit-touch-callout`, `-webkit-tap-highlight-color` und `touch-action` sind global
  abgeschaltet; das Eingabefeld für den Namen ist die einzige Ausnahme.
- Der Ordner heisst jetzt `games/mogli/`; die ZIP-Datei entsprechend `mogli.zip`.

## Wiedervorlage
Wenn das Spiel je einen zweiten Eingabekanal bekommen soll – etwa Ducken oder einen Sprint –
ist das der Punkt, an dem diese Entscheidung neu zu bewerten ist. Bis dahin gilt: kommt eine
Fähigkeit dazu, muss sie ohne zusätzliche Taste auskommen, so wie es die Ranken tun.
