# Mogli – Dschungellauf

Ein 2D-Jump'n'Run für den Browser: **rennen, springen, die Flagge erreichen** – und die
Levels baut man selbst, im Karten-Editor des Admin-Bereichs. Es wird als ZIP-Datei
ausgeliefert, die man auf einen beliebigen Webspace hochlädt und entpackt. Keine Datenbank,
kein Build-Schritt, keine Abhängigkeiten.

> Warum liegt das hier und nicht in `apps/portal`? Das Spiel hat mit der Hosting-Plattform
> fachlich nichts zu tun und soll ausdrücklich **ohne** Next.js, ohne Build und ohne Datenbank
> auf fremden Webspaces laufen. Als eigenständiger Ordner bleibt es davon unabhängig. Die
> Berührungspunkte mit dem Monorepo sind vier Zeilen in `package.json`, zwei Blöcke in
> `eslint.config.mjs` und zwei Zeilen in `.gitignore`. Siehe
> [ADR 0011](../../docs/decisions/0011-mogli-ohne-build-schritt.md) und
> [ADR 0018](../../docs/decisions/0018-seitwaerts-mit-karten-editor.md).

## Befehle

| Befehl           | Wofür                                                                                                     |
| ---------------- | --------------------------------------------------------------------------------------------------------- |
| `pnpm game:dev`  | lokaler Server auf <http://127.0.0.1:8137> (braucht PHP, damit auch Bestenliste, Karten und Admin laufen) |
| `pnpm test:game` | die Tests des Spiels (laufen auch in `pnpm test` und damit in der CI mit)                                 |
| `pnpm game:zip`  | erzeugt `dist/mogli.zip` zum Hochladen                                                                    |

**Nicht per Doppelklick öffnen.** Das Spiel besteht aus ES-Modulen, die Browser über `file://`
nicht laden – die Seite bliebe leer. Es braucht immer einen Webserver.

## Wie es sich spielt

1. Links/rechts laufen, springen – **Mario-artig**: variable Sprunghöhe (früh loslassen
   bricht den Sprung ab), Coyote-Zeit an Kanten, Sprungpuffer vor der Landung. Kein
   Doppelsprung, kein Wandsprung.
2. Auf Touch-Geräten liegen drei halbtransparente Knöpfe über dem Bild (◀ ▶ links, Sprung
   rechts); Tastatur Pfeile/AD + Leertaste; Gamepad geht auch.
3. Die Uhr startet mit dem ersten Schritt und hält nie an – auch nicht beim Tod, der zum
   letzten Checkpoint zurückwirft. Smaragde geben Zeitgutschrift. Je Level zählt die
   **Bestzeit**, weltweit sortiert.
4. Unterwegs: Plattformen (von unten durchspringbar), bewegliche und bröckelnde Plattformen,
   Federn, Stacheln, Schlüssel und Türen, Portale, zwei Gegnerarten (die meisten lassen sich
   platt springen), Checkpoints, Zielflagge. Durchspielen schaltet das nächste Level frei.

## Aufbau

```
web/                     genau dieser Baum landet in der ZIP-Datei
  index.html             Seite, Menü mit Levelliste, Story-Tafeln, Touch-Knöpfe,
                         Fänger für Startfehler
  pruefung.html          prüft den Server, wenn das Spiel nicht anläuft
  .htaccess              HTML und Code nicht zwischenspeichern, MIME für .js
  style.css              Gestaltung, --hc-* als einzige Farbquelle des Rahmens
  score.php              Bestzeiten je Level: flache JSON-Datei, kein SQL
  admin.php              Admin-Bereich: Anmeldung, Grafikpaket UND Karten schreiben
  assets.php             gibt das Grafikpaket an das Spiel heraus
  maps.php               gibt die Karten an das Spiel heraus
  data/.htaccess         sperrt Spielstände, Paket und Karten für den direkten Abruf
  LIESMICH.txt           Anleitung für die Person, die das ZIP hochlädt
  admin/                 die Oberfläche unter /admin (Figur, Elemente, Karten, Hintergrund)
  src/
    main.js              Verdrahtung: Szenen, Levelwahl, Story, Menüs, Ereignisse
    version.js           einzige Quelle der Versionsnummer
    i18n.js              de/en-Kataloge, data-i18n im HTML
    engine/              Schleife, Eingabe (3 Achsen, Touch-Knöpfe), Canvas-Skalierung,
                         Ton, Speicher, Meldungsstreifen für Fehler beim Start
    game/                Physik, Spieler, Element-Katalog, Kartenlader samt Demo-Karte,
                         Elementverhalten, Welt, Story-Ablauf
    render/              Mogli, Wesen, Kacheln, Element-Platzhalter, Hintergrund, Szene,
                         Anzeige, GIF-Leser und Bildumrechnung für den Admin-Bereich
    net/                 die geteilten Prüfregeln: Bestzeiten, Grafikpaket, Karten
test/                    node --test, läuft ohne Browser
tools/
  make-frames.mjs        erzeugt render/frames.js (die 40 Bilder von Mogli)
  make-importmap.mjs     erzeugt die importmap beider Seiten
  sheet.html             zeigt alle Einzelbilder zum Nachsehen
pack.mjs                 baut die ZIP-Datei, nur mit Node-Bordmitteln
```

`tools/` liegt bewusst NEBEN `web/`: was dort steht, gehört nicht in die Auslieferung.

### Eine Regel, auf der alles aufbaut

`game/constants.js`, `game/physics.js`, `game/player.js`, `game/elements.js`, `game/map.js`,
`game/entities.js`, `game/world.js`, `game/story.js`, `i18n.js` und alles unter `net/` fassen
beim Import **keinen DOM an**. Nur deshalb lassen sich Physik, Kartenprüfung und
Elementverhalten in Node testen, ohne einen Browser zu starten. Wer dort ein `document`
einbaut, macht die halbe Testabdeckung kaputt.

## Wie das Spiel funktioniert

**Fester Zeitschritt.** Die Logik läuft mit exakt 60 Schritten pro Sekunde, das Zeichnen ist
davon entkoppelt (`engine/loop.js`). Bei variablem Zeitschritt hinge die Sprunghöhe von der
Bildrate ab. So ist ein Lauf aus Karte plus Eingabe reproduzierbar – die Grundlage der Tests.

**Ein Tipp geht nie verloren.** `engine/input.js` zählt Sprungdrücke, statt nur einen Zustand
zu merken. Die Touch-Knöpfe verfolgen jeden Finger einzeln (`pointerId`), sodass Laufen und
Springen gleichzeitig gehen und ein Finger von ◀ nach ▶ gleiten darf, ohne loszulassen.
Gehorcht wird `pointerdown`, nicht `click` – `click` feuert erst beim Loslassen.

**Alle Zahlen an einer Stelle.** Die Physik steht in `game/constants.js`, in Pixeln pro Tick.
Der Kommentar an `jumpVel` erklärt, warum der Wert **gemessen** und nicht berechnet ist: die
Physik läuft diskret, die Schulformel für die Scheitelhöhe gilt nur im Kontinuierlichen.

**Ein Element-Katalog als eine Quelle.** `game/elements.js` beschreibt jeden Element-Typ
einmal: Name (de/en), Standardgrösse, Eigenschaften mit Grenzen, Verhalten, Grafik-Wunsch.
Editor-Palette, Eigenschaften-Panel, Prüfregeln und Spiel lesen alle denselben Katalog – ein
neuer Typ ist ein Eintrag, kein Umbau. Die Trennung im Spiel: feste Typen (Boden, Plattform,
Bröckelblock) werden in ein 16-px-Kachelgitter gerastert (`game/map.js`) und von der Physik
behandelt; alles andere sind Entitäten mit Pixel-Rechtecken (`game/entities.js`).

**Karten sind Daten und werden zweimal geprüft.** `net/mapRules.js` prüft im Browser,
`admin.php` prüft mit denselben Zahlen auf dem Server; `test/map.test.mjs` liest die
PHP-Datei und vergleicht die Konstanten – dasselbe Spiegel-Muster wie bei Bestzeiten und
Grafikpaket. Die eingebaute Demo-Karte in `game/map.js` durchläuft dieselbe Prüfung, und ein
Test spielt sie mit einem einfachen Vorausschau-Läufer bis zur Flagge durch: die ZIP ist ohne
jeden Upload beweisbar schaffbar.

**Pixel-Art ohne Binärdateien.** Mogli besteht aus 40 Einzelbildern à 32 × 32 Zeichen in
`render/frames.js` – erzeugt von `tools/make-frames.mjs` aus einem Strichmodell. Die Wesen
der Vorgeschichte liegen von Hand in `render/creatures.js`; Kacheln, Hintergrund und die
Platzhalter aller Elemente (`render/elementArt.js`) werden prozedural gezeichnet. Das
Repository enthält damit weiterhin keine einzige Binärdatei.

**Ganzzahlige Skalierung.** Intern wird auf 256 Pixel Breite gezeichnet und dann ganzzahlig
vergrössert – berechnet in *Geräte*pixeln, nicht in CSS-Pixeln (`engine/canvas.js`). Der
verbreitete Weg über `image-rendering: pixelated` allein verwischt bei einem gebrochenen
`devicePixelRatio` wie 1.5 oder 2.625, wie es auf Windows- und Android-Geräten üblich ist.

**Die Höhe richtet sich nach dem Gerät, die Kamera nach der Karte.** Die Breite liegt fest –
sechzehn Kacheln. Die Höhe wird beim Start und bei jeder Grössenänderung gewählt
(`setViewHeight()`, 336 bis 640 Pixel). Horizontal folgt die Kamera mit Totzone, senkrecht
wird sie auf die Kartenhöhe geklemmt; ist der Bildschirm höher als die Karte, sitzt die Karte
unten und der Hintergrund füllt den Rest (Tempo-0-Ebenen decken als stehendes Bild den ganzen
Bildschirm).

**Was man sehen kann, darf nicht bestimmen, wie schwer es ist.** `test/viewheight.test.mjs`
spielt denselben Lauf bei jeder erlaubten Bildhöhe durch und besteht auf demselben Ausgang:
die Kamera ist reine Darstellung. Der Test stammt aus einem echten 2.0-Fehler und wurde für
die Seitwärts-Welt neu geschrieben, die Pflicht blieb.

**Ein Startfehler steht auf dem Bildschirm.** Ein kurzes Skript in `index.html` sammelt ab
der ersten Zeile jeden Fehler ein, `engine/crash.js` übernimmt, sobald es läuft, und meldet
sich das Spiel nicht binnen der Ladefrist als bedienbar, erscheint der rote Streifen von
allein. In `boot()` wird erst verdrahtet und dann verziert. Näheres in
[0015](../../docs/decisions/0015-startfehler-werden-sichtbar.md).

**Keine Massvorgaben im Admin-Bereich.** Jedes Format, das der Browser lesen kann, und jede
Grösse; umgerechnet wird beim Hochladen (`render/fit.js`). Element-Bilder behalten sogar ihre
Originalauflösung (bis 128 px Kantenlänge) – auf ihnen zeichnet man die Trefferfläche in
Bildpixeln, gespeichert wird sie als Anteile und beim Zeichnen auf die platzierte Grösse
gezogen. Näheres in [0017](../../docs/decisions/0017-keine-massvorgaben-im-admin.md).

**Das Tempo einer Hintergrundebene entscheidet, wie sie gezeichnet wird.** Tempo 0 heisst
stehendes Bild: füllt den Bildschirm, mittig beschnitten. Über 0 heisst mitlaufend – seit 3.0
**waagerecht**: auf Bildhöhe skaliert und nebeneinander wiederholt, versetzt um Kameraposition
mal Tempo. `test/background.test.mjs` rechnet für jede Kombination nach, ob eine Spalte ohne
Bild bleibt.

**Ein GIF statt fünf Dateien.** Der selbstgeschriebene GIF-Leser (`render/gif.js`, geprüft
gegen Chromiums `ImageDecoder` durch `tools/gif-gegenprobe.mjs`) zerlegt Animationen im
Browser. Bei der Figur füllt ein GIF alle fünf Plätze einer Bewegung; bei Elementen nimmt das
Spiel vorerst das erste Bild. [0016](../../docs/decisions/0016-gif-statt-fuenf-dateien.md).

**Die Version hängt an jeder Modul-Adresse – auf jeder Seite.** `index.html` und
`admin/index.html` enthalten je eine `importmap`, erzeugt von `tools/make-importmap.mjs` aus
dem tatsächlichen Modulbaum. Wer eine Datei anlegt oder umbenennt, lässt das Werkzeug einmal
laufen; `test/dom.test.mjs` erzwingt es für **jede** Seite.

**Die Prüfseite.** `pruefung.html` gehört nicht zum Spiel: sie prüft den Server vom Gerät des
Nutzers aus durch – Fassung, jede JS-Datei, MIME-Typen, Fremdskripte, PHP, echter `import()`.
Sie ist in altem JavaScript geschrieben und lädt nichts nach.

## Bestzeiten ohne Datenbank

`score.php` legt je Level die besten 50 Zeiten in `data/scores.json` ab, aufsteigend nach
Ticks. Jeder Schreibvorgang läuft unter `flock()` und endet mit einem atomaren `rename()`.
IP-Adressen für die Ratenbegrenzung werden nur als täglich wechselnder Hash gespeichert.

Fehlt PHP oder ist `data/` nicht beschreibbar, schaltet der Client (`net/scores.js`) auf
`localStorage` um; die Anzeige sagt sichtbar, welcher Modus läuft.

**Zeiten eines Browserspiels sind fälschbar.** Der Client misst sie, also kann jeder eine
erfundene Zahl senden. `score.php` prüft Wertebereiche (3 Sekunden bis 2 Stunden) und
begrenzt Einträge pro IP; gegen jemanden, der es darauf anlegt, hilft das nicht. Das steht so
auch in `LIESMICH.txt` – als Sicherheitsversprechen wird es nicht verkauft.

Die Prüfregeln stehen zweimal: in `net/scoreRules.js` (Client und Tests) und in PHP in
`score.php`. Die Serverprüfung ist die massgebliche; `test/scores.test.mjs` hält beide
Zahlenwerke zusammen.

## Der Admin-Bereich: Grafik, Elemente, Karten

Unter **`/admin`** (Zugangscode ab Werk: **6713**) liegen vier Reiter:

- **Figur** – acht Bewegungen à fünf Bilder plus Trefferfläche, GIF-Fläche je Bewegung.
- **Elemente** – je Katalog-Element ein Bild (beliebige Grösse, GIF nimmt das erste Bild) und
  eine einzeichenbare Trefferfläche. Leere Plätze behalten die mitgelieferte Grafik.
- **Karten** – der Editor: Palette links, Raster in der Mitte, Eigenschaften und Notiz
  rechts. Boden/Plattformen/Stacheln als Rechteck aufziehen, Elemente verschieben, Start
  setzen, Karten anlegen/ordnen/löschen (die Reihenfolge ist die Levelfolge), Rastergrösse
  16/32/48 px, „Testen" öffnet das Spiel mit `?map=<id>`.
- **Hintergrund** – bis vier Ebenen mit Scrolltempo.

**Speichern** sichert Grafikpaket und Karten in einem Schritt (`data/assets.json`,
`data/maps.json`). Ohne PHP fällt alles auf `localStorage` zurück, „Als Datei laden" gibt
beide Dateien zum Hochladen von Hand heraus.

### Zur Sicherheit, ohne Schönfärberei

**Vier Ziffern sind 10 000 Möglichkeiten** – brauchbar macht den Code nicht seine Länge,
sondern die Ratenbegrenzung: fünf Versuche je zehn Minuten und gehashter IP, geprüft
serverseitig mit `hash_equals`. **`ADMIN_CODE` in `web/admin.php` nimmt jede Zeichenkette –
ein längeres Wort dort ist der einzige wirkliche Schutz.** Ebenso einmalig ändern:
`ADMIN_SALT`.

**Hochgeladene Bilder werden nie als Dateien abgelegt.** Alles wandert als base64 in eine
einzige JSON-Datei – es gibt keinen Pfad, unter dem eine als PNG getarnte `.php` landen und
ausgeführt werden könnte. Die PNG-Signatur wird geprüft, nicht nur der behauptete MIME-Typ.
Karten sind reine Daten und werden Feld für Feld gegen den Katalog geprüft; unbekannte Typen
und Eigenschaften fliegen raus.

Die Prüfregeln stehen wie überall zweimal – `net/assetRules.js`/`net/mapRules.js` und
`admin.php`; `test/assets.test.mjs` und `test/map.test.mjs` halten die Zahlen fest.

## Ändern

- **Steuerung anders?** `web/src/game/constants.js`, Abschnitt `PHYS`. Danach
  `pnpm test:game` – die Tests halten die Mario-Zusagen fest (Sprunghöhe vier Kacheln,
  variable Höhe, Coyote, Puffer) und spielen die Demo-Karte mit der geänderten Physik durch.
- **Neues Element?** Ein Eintrag in `web/src/game/elements.js` (Katalog), Verhalten in
  `web/src/game/entities.js`, Platzhalter in `web/src/render/elementArt.js`, den Namen in die
  Spiegel-Listen in `admin.php` und `net/assetRules.js` – die Tests sagen, wenn eine Stelle
  fehlt.
- **Demo-Karte ändern?** Daten in `web/src/game/map.js`; `test/map.test.mjs` spielt sie
  durch und lehnt eine unschaffbare Karte ab.
- **Mogli umzeichnen?** Im Admin-Bereich eigene Bilder einsetzen – oder Posen in
  `tools/make-frames.mjs` ändern und `node games/mogli/tools/make-frames.mjs` laufen lassen.
- **Story ändern?** Ablauf in `web/src/game/story.js`, Texte in `web/src/i18n.js` – immer in
  **beiden** Sprachen, ein Test erzwingt identische Schlüsselmengen.
- **Neue Version ausliefern?** `web/src/version.js` hochzählen,
  `node games/mogli/tools/make-importmap.mjs`, dann `pnpm game:zip`.
