# HeavenClimb

Ein 2D-Jump'n'Run für den Browser: **klettere so hoch du kannst**, mit Doppelsprung und
Wandsprung, in Pixel-Art. Es wird als ZIP-Datei ausgeliefert, die man auf einen beliebigen
Webspace hochlädt und entpackt. Keine Datenbank, kein Build-Schritt, keine Abhängigkeiten.

> Warum liegt das hier und nicht in `apps/portal`? Das Spiel hat mit der Hosting-Plattform
> fachlich nichts zu tun und soll ausdrücklich **ohne** Next.js, ohne Build und ohne Datenbank
> auf fremden Webspaces laufen. Als eigenständiger Ordner bleibt es davon unabhängig. Die
> Berührungspunkte mit dem Monorepo sind drei Zeilen in `package.json`, zwei Blöcke in
> `eslint.config.mjs` und zwei Zeilen in `.gitignore`. Siehe
> [docs/decisions/0011-heavenclimb-ohne-build-schritt.md](../../docs/decisions/0011-heavenclimb-ohne-build-schritt.md).

## Befehle

| Befehl | Wofür |
|---|---|
| `pnpm game:dev` | lokaler Server auf <http://127.0.0.1:8137> (braucht PHP, damit auch die Bestenliste läuft) |
| `pnpm test:game` | die Tests des Spiels (laufen auch in `pnpm test` und damit in der CI mit) |
| `pnpm game:zip` | erzeugt `dist/heavenclimb.zip` zum Hochladen |

**Nicht per Doppelklick öffnen.** Das Spiel besteht aus ES-Modulen, die Browser über `file://`
nicht laden – die Seite bliebe leer. Es braucht immer einen Webserver.

## Aufbau

```
web/                     genau dieser Baum landet in der ZIP-Datei
  index.html             Seite, Menüs, Bildschirmtasten
  style.css              Gestaltung, --hc-* als einzige Farbquelle des Rahmens
  score.php              Bestenliste: flache JSON-Datei, kein SQL
  data/.htaccess         sperrt die Spielstände für den direkten Abruf
  LIESMICH.txt           Anleitung für die Person, die das ZIP hochlädt
  src/
    main.js              Verdrahtung: Szenen, Menüs, Ereignisse
    version.js           einzige Quelle der Versionsnummer
    i18n.js              de/en-Kataloge, data-i18n im HTML
    engine/              Schleife, Eingabe, Canvas-Skalierung, Ton, Speicher
    game/                Physik, Levelgenerator, Gefahr, Kletterautomat
    render/              Sprites, Kacheln, Hintergrund, Szene, Anzeige
    net/                 Bestenlisten-Client und die geteilten Prüfregeln
test/                    node --test, läuft ohne Browser
pack.mjs                 baut die ZIP-Datei, nur mit Node-Bordmitteln
```

### Eine Regel, auf der alles aufbaut

`game/constants.js`, `game/rng.js`, `game/physics.js`, `game/player.js`, `game/level.js`,
`game/hazard.js`, `game/world.js`, `game/bot.js`, `i18n.js` und `net/scoreRules.js` fassen beim
Import **keinen DOM an**. Nur deshalb lassen sich Physik und Levelerzeugung in Node testen, ohne
einen Browser zu starten. Wer dort ein `document` einbaut, macht die halbe Testabdeckung kaputt.

## Wie das Spiel funktioniert

**Fester Zeitschritt.** Die Logik läuft mit exakt 60 Schritten pro Sekunde, das Zeichnen ist
davon entkoppelt (`engine/loop.js`). Bei variablem Zeitschritt hinge die Sprunghöhe von der
Bildrate ab, und derselbe Lauf sähe auf zwei Rechnern verschieden aus. So ist ein Lauf aus
Startwert plus Eingabe reproduzierbar – die Grundlage der Tests.

**Alle Zahlen an einer Stelle.** Physik, Generator und Gefahr stehen in `game/constants.js`,
in Pixeln pro Tick. Wer die Steuerung anders haben will, ändert dort und nirgends sonst.

**Der Generator gibt eine Zusage.** Jede Plattform liegt höchstens 3 Kacheln (48 px) über der
vorherigen – ab hoher Schwierigkeit 4 Kacheln (64 px) – und höchstens 3 Kacheln seitlich
versetzt. Gemessen schafft ein Einfachsprung 58 px und ein Doppelsprung 94 px, es bleibt also
Reserve für ungenaues Spielen. Zusätzlich muss jede neue Plattform mindestens zwei Kacheln der
vorherigen frei lassen: senkrecht nach oben kann man auf keine Plattform springen, der Kopf
stösst an ihre Unterseite. Wäre die Standfläche vollständig überdeckt, stünde man unter einer
Decke fest. `test/level.test.mjs` prüft beides über zwei Dutzend Startwerte.

**Pixel-Art ohne Binärdateien.** Der Charakter besteht aus 27 handgesetzten Einzelbildern à
16 × 16 Zeichen in `render/frames.js`; ein Zeichen ist ein Pixel. Beim Start werden sie in
Offscreen-Canvas gebrannt, gleich mitsamt gespiegelter Fassung. Kacheln und Hintergrund werden
prozedural gezeichnet. Damit enthält das Repository weiterhin keine einzige Binärdatei, und
jede Änderung am Charakter ist im Diff lesbar.

**Ganzzahlige Skalierung.** Intern wird immer auf 240 × 320 gezeichnet und dann ganzzahlig
vergrössert – berechnet in *Geräte*pixeln, nicht in CSS-Pixeln (`engine/canvas.js`). Der
verbreitete Weg über `image-rendering: pixelated` allein verwischt bei einem gebrochenen
`devicePixelRatio` wie 1.5 oder 2.625, wie es auf Windows- und Android-Geräten üblich ist.

**Der Kletterautomat.** `game/bot.js` spielt das Spiel selbst. Im Menü klettert er im
Hintergrund, damit der Bildschirm nicht tot ist; in den Tests zeigt er, dass die erzeugte
Geometrie mit der echten Physik begehbar ist und man nirgends endgültig feststeckt. Er spielt
bewusst mittelmässig – seine Höhe ist **kein** Mass für die Schwierigkeit.

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
  `pnpm test:game` – die Physiktests halten die Werte fest, auf die sich der Generator verlässt.
- **Schwerer oder leichter?** `GEN` und `HAZARD` in derselben Datei.
- **Charakter umzeichnen?** `web/src/render/frames.js`. Jede Zeile muss genau 16 Zeichen haben,
  jedes Bild genau 16 Zeilen; `test/frames.test.mjs` prüft das.
- **Text ändern?** `web/src/i18n.js`, immer in **beiden** Sprachen – ein Test erzwingt
  identische Schlüsselmengen, dieselbe Regel wie im Portal.
- **Neue Version ausliefern?** `web/src/version.js` hochzählen, dann `pnpm game:zip`.
