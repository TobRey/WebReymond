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
   **Es gibt genau einen Wandsprung je Flugphase**; zurück gibt es ihn beim Landen und beim
   Rankenzug. Ohne diese Grenze könnte man sich zwischen zwei Wänden beliebig weit hochhangeln,
   ohne je einen Absatz zu treffen – der Turm wäre nur noch Kulisse.
6. Smaragde geben Punkte. Ranken reissen ihn ein gutes Stück nach oben.
7. Von unten steigt die Glut. Wer stehen bleibt, wird geholt.

## Aufbau

```
web/                     genau dieser Baum landet in der ZIP-Datei
  index.html             Seite, Menüs, Story-Tafeln, Fänger für Startfehler
  pruefung.html          prüft den Server, wenn das Spiel nicht anläuft
  .htaccess              HTML und Code nicht zwischenspeichern, MIME für .js
  style.css              Gestaltung, --hc-* als einzige Farbquelle des Rahmens
  score.php              Bestenliste: flache JSON-Datei, kein SQL
  admin.php              Admin-Bereich: Anmeldung, Grafikpaket schreiben
  assets.php             gibt das Grafikpaket an das Spiel heraus
  data/.htaccess         sperrt Spielstände und Paket für den direkten Abruf
  LIESMICH.txt           Anleitung für die Person, die das ZIP hochlädt
  admin/                 die Oberfläche unter /admin (vier Reiter)
  src/
    main.js              Verdrahtung: Szenen, Story, Menüs, Ereignisse
    version.js           einzige Quelle der Versionsnummer
    i18n.js              de/en-Kataloge, data-i18n im HTML
    engine/              Schleife, Eingabe, Canvas-Skalierung, Ton, Speicher,
                         Meldungsstreifen für Fehler beim Start
    game/                Physik, Levelgenerator, Glut, Story-Ablauf, Automat
    render/              Mogli, Wesen, Kacheln, Hintergrund, Szene, Anzeige,
                         GIF-Leser für den Admin-Bereich
    net/                 Bestenliste und Grafikpaket: die geteilten Prüfregeln
test/                    node --test, läuft ohne Browser
tools/
  make-frames.mjs        erzeugt render/frames.js (die 40 Bilder von Mogli)
  sheet.html             zeigt alle Einzelbilder zum Nachsehen
pack.mjs                 baut die ZIP-Datei, nur mit Node-Bordmitteln
```

`tools/` liegt bewusst NEBEN `web/`: was dort steht, gehört nicht in die Auslieferung.

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
hängen abwechselnd an der linken und rechten Wand, immer genau vier Kacheln höher, mit Lücken
von fünf bis sechs Kacheln dazwischen. Diese Zahlen sind nicht gewählt, sondern **gesucht**: ein
Suchlauf hat mit der echten Physik jede vorkommende Kombination aus Absatzbreite und Lücke
durchgespielt und den Satz genommen, der alle schafft und dabei den kürzesten Takt hat – 42
Ticks je Absatz.

Deshalb wird zuerst die Lücke gewürfelt und die Absatzbreite daraus abgeleitet, nicht
umgekehrt: die Breite ergibt sich aus `(GRID_W − 2) − Lücke − vorige Breite`. Die Schwierigkeit
steckt in der Breite, je weiter oben, desto schmaler die Landefläche.

`test/level.test.mjs` spielt jede vorkommende Kombination mit der echten Physik durch, statt sie
nachzurechnen – er ist die Instanz, die über jede Änderung an diesen Zahlen entscheidet.

**Pixel-Art ohne Binärdateien.** Mogli besteht aus 40 Einzelbildern à 32 × 32 Zeichen in
`render/frames.js` – acht Bewegungen zu je fünf Bildern; ein Zeichen ist ein Pixel. Erzeugt
werden sie von `tools/make-frames.mjs` aus einem Strichmodell (Kopf, Rumpf, Arme, Beine mit
Anfangs- und Endpunkt je Bild). Von Hand getippt wären 1280 Zeilen Raster nicht nur lang,
sondern vor allem inkonsistent: die Figur schrumpfte zwischen zwei Bildern um einen Pixel, und
das sieht man in Bewegung sofort. Das Ergebnis ist eingecheckt und ganz normale Datenquelle –
es gibt weiterhin keinen Build-Schritt.

Die drei Wesen der Vorgeschichte liegen von Hand in `render/creatures.js`. Kacheln und
Hintergrund werden prozedural gezeichnet. Damit enthält das Repository weiterhin keine einzige
Binärdatei. Die Palette hat 44 Farben in geschlossenen Abstufungsreihen – Schatten, Grundton,
Licht, Glanz. Genau das trennt den Eindruck einer 8-Bit- von einer 32-Bit-Konsole: nicht mehr
Pixel, sondern mehr Zwischentöne auf denselben Pixeln.

**Ganzzahlige Skalierung.** Intern wird auf 256 Pixel Breite gezeichnet und dann ganzzahlig
vergrössert – berechnet in *Geräte*pixeln, nicht in CSS-Pixeln (`engine/canvas.js`). Der
verbreitete Weg über `image-rendering: pixelated` allein verwischt bei einem gebrochenen
`devicePixelRatio` wie 1.5 oder 2.625, wie es auf Windows- und Android-Geräten üblich ist.

**Die Höhe richtet sich nach dem Gerät.** Die Breite liegt fest – sechzehn Kacheln, daran hängt
die Levelgeometrie. Die Höhe wird beim Start und bei jeder Grössenänderung gewählt
(`setViewHeight()`, 336 bis 640 Pixel, immer ein Vielfaches der Kachelgrösse). Ein Handy ist
mehr als doppelt so hoch wie breit; mit fester Höhe bliebe dort oben und unten je ein Drittel
schwarz. Mehr Höhe zeigt nur mehr Schacht über Mogli und macht das Spiel nicht leichter – die
Gefahr kommt von unten.

Gewählt wird dabei nicht nach dem Seitenverhältnis, sondern nach dem ganzzahligen
Vergrösserungsfaktor: ein Bild im exakten Verhältnis des Bildschirms nützt nichts, wenn danach
4.57 auf 4 abgeschnitten wird und unten ein Balken bleibt. Auf einem iPhone-Format sind es so
6 px Rand statt 49. Seitlich bleibt bei 256 px Breite je nach Gerät ein schmaler Rand – der ist
als dunkle Vignette gestaltet, wie der Rahmen um die Vorlage.

**Was man sehen kann, darf nicht bestimmen, wie schwer es ist.** `test/viewheight.test.mjs`
spielt denselben Lauf bei jeder erlaubten Bildhöhe durch und besteht auf demselben Ausgang. Der
Test steht dort wegen eines echten Fehlers: die Leine der aufsteigenden Glut hing am unteren
Bildrand, und damit war dasselbe Spiel auf einem hohen Handy ein anderes als auf einem breiten
Monitor.

**Die Seite ist das Spiel.** `index.html` enthält genau ein Element: die Bühne. Bestenliste,
Sprache und Ton liegen als Einblendung darüber, nicht darunter. Es gibt nichts zu scrollen, an
dem man auf dem Handy vorbeikommen könnte, und keine Steuerungstafel – der einzige Knopf ist
der Bildschirm selbst, und ein Hinweis beim Start sagt das einmal.

**Ein Startfehler steht auf dem Bildschirm.** Das Menü ist statisches HTML – es sieht auch dann
normal aus, wenn `boot()` nie durchgelaufen ist, und ein toter Startknopf ist von einem
Startfehler nicht zu unterscheiden. Genau so wurde ein Fehler gemeldet, und genau deshalb war
er aus der Ferne nicht zu finden. Jetzt sammelt ein kurzes Skript **in `index.html`** ab der
ersten Zeile jeden Fehler ein (ein Modul, das gar nicht ausgeführt wird, kann sich nicht selbst
beschweren), `engine/crash.js` übernimmt die Sammlung, sobald es läuft, und ist die Seite
fertig geladen, ohne dass sich das Spiel als bedienbar gemeldet hat, erscheint der Streifen von
allein. In `boot()`
wird deshalb erst verdrahtet und dann verziert: eine kaputte Kleinigkeit kostet höchstens sich
selbst. Näheres in [0015](../../docs/decisions/0015-startfehler-werden-sichtbar.md).

**Ein GIF statt fünf Dateien.** Neben jeder Bewegung im Admin-Bereich nimmt eine Fläche ein
animiertes GIF und füllt alle fünf Plätze auf einmal. Zerlegt wird es im Browser
(`render/gif.js`) und als die schon bekannten fünf PNG abgelegt – Paketformat, `admin.php` und
Spiel bleiben unverändert. Hat das GIF mehr als fünf Bilder, entscheidet die **Laufzeit** je
Bild, welche fünf es werden; hat es weniger, werden sie verteilt und keines fällt weg. Beide
Regeln stehen in [0016](../../docs/decisions/0016-gif-statt-fuenf-dateien.md), samt der Fälle,
an denen die jeweils andere Regel scheitert.

Der Leser ist selbstgeschrieben, weil kein Browser die Bilder eines GIF einzeln herausgibt und
`ImageDecoder` auf iOS fehlt. Weil ein eigener Leser, geprüft an eigenen Testdateien, nichts
beweist, hält `tools/gif-gegenprobe.mjs` beide gegen **Chromiums eigenen `ImageDecoder`** und
vergleicht Pixel für Pixel – verschränkte Zeilen, Teilbilder, Durchsichtigkeit, beide
Räum-Verfahren, lange LZW-Ketten.

**Die Version hängt an jeder Modul-Adresse.** `index.html` enthält eine `importmap`, erzeugt von
`tools/make-importmap.mjs`. Vorher trug nur `main.js` ein `?v=`; die 31 Dateien darunter wurden
unter ihrer blanken Adresse geholt und kamen damit aus dem Zwischenspeicher des Browsers — eine
neue Seite traf auf alte Module, und das Spiel blieb stumm im Menü stehen. Wer unter `src/` eine
Datei anlegt oder umbenennt, lässt das Werkzeug einmal laufen; `test/dom.test.mjs` erzwingt es
und prüft auch die Reihenfolge im HTML (eine `importmap` nach dem ersten Modul-Skript wird
verworfen).

**Die Prüfseite.** `pruefung.html` gehört nicht zum Spiel: sie prüft den Server vom Gerät des
Nutzers aus und sagt im Klartext, was nicht stimmt – ausgelieferte Fassung, jede JS-Datei auf
Erreichbarkeit, Vollständigkeit und MIME-Typ (sie folgt dem Modulbaum aus `main.js`, es gibt
keine fest eingetragene Dateiliste), eingehängte Fremdskripte, PHP, und zuletzt ein echter
`import()`. Sie ist in altem JavaScript geschrieben und lädt nichts nach, weil sie sonst genau
den Fall nicht melden könnte, für den es sie gibt; `test/dom.test.mjs` hält das fest. Der
Meldungsstreifen sagt, was im Browser schiefging – die Prüfseite, was am Server.

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

## Eigene Grafik: der Admin-Bereich

Unter **`/admin`** lassen sich Sprites, Kacheln und Hintergrund austauschen, ohne eine Zeile
Code anzufassen. Vier Reiter: Figur (acht Bewegungen à fünf Bilder, 32 × 32 PNG), Kacheln
(16 × 16 PNG plus Kollisionskasten), Hintergrund (bis vier Ebenen mit Scrolltempo) und
Vorschau. Voreingestellter Zugangscode: **6713**.

Ein Paket legt sich über die mitgelieferte Grafik, es ersetzt nichts im Repo. Ohne Paket sieht
das Spiel aus wie immer – und zwar auch, wenn PHP fehlt, ein Bild kaputt ist oder das Paket
nicht durch die Prüfung kommt. Eingesetzt wird platzweise: drei von fünf Laufbildern ersetzen,
die anderen zwei bleiben.

**Der Reiter Vorschau ist der wichtige.** Dort läuft das echte Spiel mit dem noch nicht
gespeicherten Paket, und der echte Automat aus `game/bot.js` spielt darin. Einzeichnen lässt
sich nämlich, *wo* eine Kachel fest ist – wer die Kästen zu klein zieht, macht den Turm
unbesteigbar, und genau das sagt die Vorschau, bevor gespeichert wird.

Nicht einstellbar ist, was eine Kachel *bedeutet* (fest, nur von oben tragend, tödlich): daran
hängt der Beweis in `test/level.test.mjs`, dass jeder erzeugte Turm besteigbar ist. An Wänden
ist auch der Kasten gesperrt – dort wird abgesprungen.

### Zur Sicherheit, ohne Schönfärberei

**Vier Ziffern sind 10 000 Möglichkeiten** – ohne Bremse in Sekunden durchprobiert. Brauchbar
macht den Code nicht seine Länge, sondern die Ratenbegrenzung: fünf Versuche je zehn Minuten
und gehashter IP. Geprüft wird serverseitig mit `hash_equals`; im ausgelieferten JavaScript
steht die Zahl nirgends. **`ADMIN_CODE` in `web/admin.php` nimmt jede Zeichenkette – ein
längeres Wort dort ist der einzige wirkliche Schutz.** Ebenso einmalig ändern: `ADMIN_SALT`.

Der Preis der Bremse: wer sich fünfmal vertippt, sperrt sich für zehn Minuten selbst aus.

**Hochgeladene Bilder werden nie als Dateien abgelegt.** Alles wandert als base64 in eine
einzige JSON-Datei. Damit gibt es keinen Pfad, unter dem eine als PNG getarnte `.php` im
Webverzeichnis landen und ausgeführt werden könnte – die häufigste Art, wie Bilder-Uploads zur
Übernahme eines Webspace führen. Zusätzlich wird die PNG-Signatur geprüft, nicht nur der
MIME-Typ im Text davor; den kann jeder frei behaupten.

Ohne PHP fällt der Admin-Bereich auf `localStorage` zurück und bietet „Als Datei laden" an: die
erzeugte `assets.json` selbst nach `data/` hochladen, fertig.

Die Prüfregeln stehen wie bei der Bestenliste zweimal – als reine Funktion in
`net/assetRules.js` und in PHP in `admin.php`. Wer eine ändert, muss die andere mitändern;
`test/assets.test.mjs` hält die Zahlen fest.

## Ändern

- **Steuerung anders?** `web/src/game/constants.js`, Abschnitt `PHYS`. Danach
  `pnpm test:game` – die Tests halten die Werte fest, auf die sich der Generator verlässt, und
  spielen jede Lücke gegen die geänderte Physik durch.
- **Schwerer oder leichter?** `GEN` und `HAZARD` in derselben Datei.
- **Mogli umzeichnen?** Entweder im Admin-Bereich eigene PNG einsetzen – oder die
  mitgelieferte Figur ändern: Posen in `tools/make-frames.mjs`, dann
  `node games/mogli/tools/make-frames.mjs`. `web/src/render/frames.js` wird dabei überschrieben
  und ist nicht von Hand zu bearbeiten. Zum Nachsehen: `tools/sheet.html`.
- **Story ändern?** Ablauf in `web/src/game/story.js`, Texte in `web/src/i18n.js`.
- **Text ändern?** Immer in **beiden** Sprachen – ein Test erzwingt identische Schlüsselmengen,
  dieselbe Regel wie im Portal.
- **Neue Version ausliefern?** `web/src/version.js` hochzählen, dann `pnpm game:zip`.
