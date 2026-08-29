# Mogli – Tempelflucht

Ein 2D-Jump'n'Run für den Browser mit **genau einem Knopf**: Tippen springt. Mogli läuft von
allein durch einen überwucherten Tempelschacht, und man kommt nur nach oben, wenn man den
richtigen Moment erwischt. Es wird als ZIP-Datei ausgeliefert, die man auf einen beliebigen
Webspace hochlädt und entpackt. Keine Datenbank, kein Build-Schritt, keine Abhängigkeiten.

> Warum liegt das hier und nicht in `apps/portal`? Das Spiel hat mit der Hosting-Plattform
> fachlich nichts zu tun und soll ausdrücklich **ohne** Next.js, ohne Build und ohne Datenbank
> auf fremden Webspaces laufen. Als eigenständiger Ordner bleibt es davon unabhängig. Die
> Berührungspunkte mit dem Monorepo sind vier Zeilen in `package.json`, zwei Blöcke in
> `eslint.config.mjs` und zwei Zeilen in `.gitignore`. Siehe
> [ADR 0011](../../docs/decisions/0011-mogli-ohne-build-schritt.md) und
> [ADR 0012](../../docs/decisions/0012-ein-knopf-steuerung.md).

## Befehle

| Befehl | Wofür |
|---|---|
| `pnpm game:dev` | lokaler Server auf <http://127.0.0.1:8137> (braucht PHP, damit auch die Bestenliste läuft) |
| `pnpm test:game` | die Tests des Spiels (laufen auch in `pnpm test` und damit in der CI mit) |
| `pnpm game:zip` | erzeugt `dist/mogli.zip` zum Hochladen |

**Nicht per Doppelklick öffnen.** Das Spiel besteht aus ES-Modulen, die Browser über `file://`
nicht laden – die Seite bliebe leer. Es braucht immer einen Webserver.

## Wie es sich spielt

1. Mogli steht still, bis man das erste Mal tippt. Erst dann läuft er los – und erst dann
   steigt die Glut.
2. Er läuft von allein, immer in seine Blickrichtung. Es gibt keine Richtungstaste.
3. **Tippen springt.** Immer gleich hoch, ohne Doppelsprung.
4. Läuft er am Boden in eine Wand, dreht er von selbst um.
5. Rutscht er an einer Wand ab, stösst ihn ein Tipp weg – **und dreht dabei seine
   Laufrichtung um.** Das ist die einzige Stelle, an der man die Richtung im Flug ändert.
6. Smaragde geben Punkte. Ranken reissen ihn ein gutes Stück nach oben.
7. Von unten steigt die Glut. Wer stehen bleibt, wird geholt.

## Aufbau

```
web/                     genau dieser Baum landet in der ZIP-Datei
  index.html             Seite, Menüs, Story-Tafeln
  style.css              Gestaltung, --hc-* als einzige Farbquelle des Rahmens
  score.php              Bestenliste: flache JSON-Datei, kein SQL
  data/.htaccess         sperrt die Spielstände für den direkten Abruf
  LIESMICH.txt           Anleitung für die Person, die das ZIP hochlädt
  src/
    main.js              Verdrahtung: Szenen, Story, Menüs, Ereignisse
    version.js           einzige Quelle der Versionsnummer
    i18n.js              de/en-Kataloge, data-i18n im HTML
    engine/              Schleife, Eingabe, Canvas-Skalierung, Ton, Speicher
    game/                Physik, Levelgenerator, Glut, Story-Ablauf, Automat
    render/              Mogli, Wesen, Kacheln, Hintergrund, Szene, Anzeige
    net/                 Bestenlisten-Client und die geteilten Prüfregeln
test/                    node --test, läuft ohne Browser
pack.mjs                 baut die ZIP-Datei, nur mit Node-Bordmitteln
```

### Eine Regel, auf der alles aufbaut

`game/constants.js`, `game/rng.js`, `game/physics.js`, `game/player.js`, `game/level.js`,
`game/hazard.js`, `game/world.js`, `game/bot.js`, `game/story.js`, `i18n.js` und
`net/scoreRules.js` fassen beim Import **keinen DOM an**. Nur deshalb lassen sich Physik und
Levelerzeugung in Node testen, ohne einen Browser zu starten. Wer dort ein `document` einbaut,
macht die halbe Testabdeckung kaputt.

## Wie das Spiel funktioniert

**Fester Zeitschritt.** Die Logik läuft mit exakt 60 Schritten pro Sekunde, das Zeichnen ist
davon entkoppelt (`engine/loop.js`). Bei variablem Zeitschritt hinge die Sprunghöhe von der
Bildrate ab. So ist ein Lauf aus Startwert plus Eingabe reproduzierbar – die Grundlage der Tests.

**Ein Tipp geht nie verloren.** `engine/input.js` zählt Tastendrücke, statt nur einen Zustand
zu merken. Ein Finger kann zwischen zwei Logikschritten tippen und wieder loslassen; ohne den
Zähler verschwände genau dieser Tipp, und das fühlt sich beim Spielen wie ein Aussetzer an.
Gehorcht wird `pointerdown`, nicht `click` – `click` feuert erst beim Loslassen.

**Alle Zahlen an einer Stelle.** Physik, Generator und Glut stehen in `game/constants.js`, in
Pixeln pro Tick. Wer die Steuerung anders haben will, ändert dort und nirgends sonst.

**Die Form des Turms folgt aus der Sprungbahn.** Der Schacht ist eine Zickzack-Treppe: Absätze
hängen abwechselnd an der linken und rechten Wand, immer genau drei Kacheln höher. Das ist
keine Geschmacksfrage, sondern gerechnet:

- Zwei Kacheln Abstand wären unmöglich – Mogli ist 16 px hoch, die Unterkante des oberen
  Absatzes läge auf Kopfhöhe.
- Die waagerechte Lücke muss mindestens drei Kacheln betragen, sonst gerät er unter den
  Zielabsatz, bevor er hoch genug ist, und stösst sich den Kopf.
- Sie darf höchstens vier betragen, weil er nur rund 20 Ticks lang über der Kante ist und in
  dieser Zeit bei Lauftempo 64 px schafft.

Deshalb wird zuerst die Lücke gewürfelt und die Absatzbreite daraus abgeleitet, nicht
umgekehrt. Die Schwierigkeit steckt in der Breite: je weiter oben, desto schmaler die
Landefläche. `test/level.test.mjs` spielt jede vorkommende Kombination mit der echten Physik
durch, statt sie nachzurechnen.

**Pixel-Art ohne Binärdateien.** Mogli besteht aus 28 handgesetzten Einzelbildern à 24 × 24
Zeichen in `render/frames.js`; ein Zeichen ist ein Pixel. Die drei Wesen der Vorgeschichte
liegen in `render/creatures.js`. Kacheln und Hintergrund werden prozedural gezeichnet. Damit
enthält das Repository weiterhin keine einzige Binärdatei, und jede Änderung an der Figur ist
im Diff lesbar. Die Palette hat 32 Farben in geschlossenen Abstufungsreihen – Schatten,
Grundton, Licht, Glanz. Genau das trennt den Eindruck einer 8-Bit- von einer 32-Bit-Konsole:
nicht mehr Pixel, sondern mehr Zwischentöne auf denselben Pixeln.

**Ganzzahlige Skalierung.** Intern wird auf 192 Pixel Breite gezeichnet und dann ganzzahlig
vergrössert – berechnet in *Geräte*pixeln, nicht in CSS-Pixeln (`engine/canvas.js`). Der
verbreitete Weg über `image-rendering: pixelated` allein verwischt bei einem gebrochenen
`devicePixelRatio` wie 1.5 oder 2.625, wie es auf Windows- und Android-Geräten üblich ist.

**Die Höhe richtet sich nach dem Gerät.** Die Breite liegt fest – zwölf Kacheln, daran hängt
die Levelgeometrie. Die Höhe wird beim Start und bei jeder Grössenänderung aus dem
Seitenverhältnis gewählt (`setViewHeight()`, 256 bis 448 Pixel, immer ein Vielfaches der
Kachelgrösse). Ein Handy ist mehr als doppelt so hoch wie breit; mit fester Höhe bliebe dort
oben und unten je ein Drittel schwarz. Mehr Höhe zeigt nur mehr Schacht über Mogli und macht
das Spiel nicht leichter – die Gefahr kommt von unten. Auf einem iPhone-Format füllt das Bild
so 97 % des Bildschirms.

**Die Seite ist das Spiel.** `index.html` enthält genau ein Element: die Bühne. Bestenliste,
Sprache und Ton liegen als Einblendung darüber, nicht darunter. Es gibt nichts zu scrollen, an
dem man auf dem Handy vorbeikommen könnte, und keine Steuerungstafel – der einzige Knopf ist
der Bildschirm selbst, und ein Hinweis beim Start sagt das einmal.

**Der Automat.** `game/bot.js` spielt das Spiel selbst. Hinter den Menüs klettert er im
Hintergrund, damit der Bildschirm nicht tot ist; in den Tests zeigt er, dass die erzeugte
Geometrie mit der echten Physik begehbar ist. Bei einem Ein-Knopf-Spiel ist er erfreulich kurz:
er muss nur entscheiden, *wann* getippt wird. Er spielt bewusst mittelmässig – seine Höhe ist
**kein** Mass für die Schwierigkeit.

## Bestenliste ohne Datenbank

`score.php` legt die besten 100 Ergebnisse in `data/scores.json` ab. Jeder Schreibvorgang läuft
unter `flock()` und endet mit einem atomaren `rename()`; sonst zerschiessen sich zwei
gleichzeitige Einträge gegenseitig. IP-Adressen für die Ratenbegrenzung werden nur als
täglich wechselnder Hash gespeichert.

Fehlt PHP oder ist `data/` nicht beschreibbar, schaltet der Client (`net/scores.js`) einmalig
und dauerhaft auf `localStorage` um. Die Anzeige sagt sichtbar, welcher Modus läuft.

**Punkte eines Browserspiels sind fälschbar.** Der Client rechnet sie aus, also kann jeder eine
erfundene Zahl senden. `score.php` prüft Wertebereiche, das Verhältnis von Punkten zu Spielzeit
und begrenzt Einträge pro IP; gegen jemanden, der es darauf anlegt, hilft das nicht. Das steht
so auch im Spiel und in `LIESMICH.txt` – als Sicherheitsversprechen wird es nicht verkauft.

Die Prüfregeln stehen zweimal: als reine Funktion in `net/scoreRules.js` (Client und Tests) und
in PHP in `score.php`. Diese Doppelung ist gewollt – die Serverprüfung ist die massgebliche, die
Clientprüfung spart nur sinnlose Anfragen. Wer eine ändert, muss die andere mitändern.

## Ändern

- **Steuerung anders?** `web/src/game/constants.js`, Abschnitt `PHYS`. Danach
  `pnpm test:game` – die Tests halten die Werte fest, auf die sich der Generator verlässt, und
  spielen jede Lücke gegen die geänderte Physik durch.
- **Schwerer oder leichter?** `GEN` und `HAZARD` in derselben Datei.
- **Mogli umzeichnen?** `web/src/render/frames.js`. Jede Zeile muss genau 24 Zeichen haben,
  jedes Bild genau 24 Zeilen; `test/frames.test.mjs` prüft das.
- **Story ändern?** Ablauf in `web/src/game/story.js`, Texte in `web/src/i18n.js`.
- **Text ändern?** Immer in **beiden** Sprachen – ein Test erzwingt identische Schlüsselmengen,
  dieselbe Regel wie im Portal.
- **Neue Version ausliefern?** `web/src/version.js` hochzählen, dann `pnpm game:zip`.
