# Arena Survivors

Mobiles 2D-Top-Down-Roguelite: Wellen überleben, Upgrades sammeln, Bosse legen.
104 Upgrades, 15 Charaktere, 10 Waffen, ein Händler zwischen den Wellen.
**Reines PHP + Vanilla JS (ES-Module), keine Datenbank, kein Build-Schritt, keine externen Libraries.**

---

## Schnellstart

### Auf den Webspace laden

1. Den kompletten Ordner per FTP hochladen, z. B. nach `/arena/`.
2. `data/` und `assets/uploads/` beschreibbar machen: `chmod 775 data assets/uploads`.
3. `https://deine-domain.tld/arena/` aufrufen.
4. Admin: `https://deine-domain.tld/arena/admin/` — Standardcode **6713**.

**Voraussetzung:** PHP 8.1+ mit GD (nur für den Upload-Check). Sonst nichts.

#### Wenn dein FTP-Programm Punkt-Dateien überspringt

Manche Datei-Manager melden beim Upload so etwas:

```
Übersprungen (nicht erlaubte Dateitypen): .htaccess, data/.htaccess, ...
```

**Das ist kein Problem — das Spiel läuft vollständig ohne diese Dateien.**
Alle Spieldaten liegen in `.php`-Dateien, die mit `<?php exit; ?>` beginnen:
Ruft jemand `data/content.php` direkt im Browser auf, kommt eine leere Antwort
zurück. Der Schutz hängt also nicht mehr an `.htaccess`.

Die einzige Einbuße: `.htaccess` und `.user.ini` heben die Uploadgrenze auf
24 MB an. Fehlen sie, gilt die Grenze deines Hosters (oft 2 MB). Den echten
Wert zeigt das Admin-Dashboard oben und auf der Audio-Seite an. Zwei Wege:

* Im Hosting-Panel `upload_max_filesize` und `post_max_size` erhöhen, **oder**
* die beiliegenden `htaccess-vorlage.txt` und `user-ini-vorlage.txt` hochladen
  und auf dem Server in `.htaccess` bzw. `.user.ini` umbenennen.

Dieselbe Erklärung liegt als `WICHTIG-BEIM-HOCHLADEN.txt` im Paket.

### Lokal testen

```bash
cd arena
PHP_CLI_SERVER_WORKERS=6 php -S 127.0.0.1:8080
```

### In den Adminbereich

`/admin` aufrufen. Zwei Wege führen hinein:

* **Mit dem Adminkonto** — wer im Spiel als **`spielatze`** angemeldet ist,
  kommt ohne Code direkt hinein. Der Name steht über die Umgebungsvariable
  `ADMIN_USER` fest (`SetEnv ADMIN_USER meinname`), Standard ist `spielatze`.
  Geprüft wird die Sitzung, nicht die Anfrage: Wer sich als dieses Konto
  ausgeben will, muss dessen Passwort kennen.
* **Mit dem Code** — sonst fragt `/admin` nach dem Admin-Code (Standard
  `6713`).

Der Code wird **serverseitig** geprüft und nie an den Browser ausgeliefert.
Quelle in dieser Reihenfolge:

1. Umgebungsvariable `ADMIN_CODE` (z. B. in der `.htaccess`: `SetEnv ADMIN_CODE 1234`)
2. `data/admin.php` — enthält nur den bcrypt-Hash, wird beim ersten Start erzeugt.

Zum Ändern: `data/admin.php` löschen, `ADMIN_CODE` setzen, Seite neu laden.
Nach 6 Fehlversuchen ist der Login 5 Minuten gesperrt.

#### Warum man vorher hinausflog

Spieler und Admin liefen unter **zwei getrennten PHP-Sitzungen**
(`arena_player` und `arena_admin`). PHP kann pro Anfrage aber nur eine
Sitzung öffnen: Wer sich zuerst meldete, gewann — und jedes
`session_regenerate_id` des einen warf den anderen hinaus. Wer im Spiel
angemeldet war, kam deshalb nicht mehr in den Adminbereich. Beide Rollen
teilen sich jetzt **eine** Sitzung und liegen darin unter eigenen Schlüsseln.

---

## Aufbau

```
arena/
├── index.php                 Spiel-Shell (lädt Inhalte als JSON in die Seite)
├── api.php                   JSON-API: content, login, put, delete, settings, upload, reset
├── lib/
│   ├── Defaults.php          Alle Startwerte: Waffen, Gegner, Upgrades, Balancing, Map
│   ├── Store.php             Persistenz in data/content.php (atomar, mit Sperre)
│   ├── Version.php           Cache-Verwaltung: Import Map und no-store
│   ├── Accounts.php          Spielerkonten, Erfahrung, Bestenliste
│   ├── Auth.php              Admin-Session, Code-Hash, Fehlversuchsbremse
│   └── Validate.php          Prüft und begrenzt alle Admin-Eingaben
├── admin/
│   ├── index.php             Serverseitig geschütztes Dashboard
│   ├── app.js                Dashboard-Logik inkl. Collision- und Hitbox-Editor
│   └── admin.css
├── assets/
│   ├── js/
│   │   ├── core/             loop, input, camera, spatial, pool, audio, util
│   │   ├── gfx/              gif (Decoder), assets, renderer, effects
│   │   ├── world/            map, collision, spawn, pickups, portals, flowfield
│   │   ├── entities/         player, enemy
│   │   ├── combat/           weapon, projectiles, melee
│   │   ├── game/             arena, run, stats, waves, boss, upgrades, shop, perks, effects
│   │   └── main.js           Menü, HUD, Overlays, Laden, Spielstart
│   ├── css/game.css
│   ├── fonts/                Pixelify Sans (woff2, mitgeliefert)
│   ├── sprites/              deine Sprites
│   └── uploads/              im Admin hochgeladene Bilder
└── data/                     content.php + admin.php + accounts/ (schützen sich selbst)
```

---

## Wie die Technik funktioniert

### Animierte GIFs auf dem Canvas

`drawImage` zeigt bei einem animierten GIF immer nur das Bild, das der Browser
gerade anzeigt — steuerbar ist das nicht. Deshalb liegt in `gfx/gif.js` ein
eigener GIF-Decoder: Er zerlegt jede Datei beim Laden einmal in fertige
Frame-Canvases (inklusive LZW, Interlacing und Disposal-Methoden). Danach ist
die Animation reine Index-Arithmetik und kostet im Spiel nichts mehr.

Anschließend werden die Frames automatisch **beschnitten**: Die Quell-GIFs haben
sehr unterschiedlich viel transparenten Rand (256×256 bis 1000×1000). Ohne
Trimmen würde derselbe Skalierungswert bei jedem Gegner eine andere sichtbare
Größe ergeben.

Pixel-Art bleibt scharf: `image-rendering: pixelated`, `imageSmoothingEnabled = false`
und ganzzahliger Kameraversatz.

### Kollision ohne Pixelprüfung

Über jeder Karte liegt ein Bitraster (Standard 128×128 Zellen, also 16 px pro
Zelle bei einer 2048er-Karte). Eine Abfrage kostet damit ein paar
Array-Zugriffe statt tausender Pixelvergleiche. Gespeichert wird die Maske als
Base64 in Kartenkoordinaten — unabhängig von Bildschirmgröße, Zoom und Gerät.

Bewegung wird achsenweise aufgelöst: Erst X, dann Y. Dadurch gleitet man an
Wänden entlang, statt daran kleben zu bleiben. Die Spieler-Hitbox ist bewusst
klein und sitzt an den Füßen — der Kopf darf optisch vor einer Wand stehen.

### Startzeit

Zwei Dinge ließen das Spiel früher „ewig laden".

**1. Die Schrift blockierte das Rendern.** Die Google-Fonts-Anfrage hing vor
der ganzen Seite. Jetzt lädt sie über `media="print"` plus `onload` nebenher,
bis dahin greift die System-Schriftfamilie. Auf einer vierfach gedrosselten
CPU: **12 970 ms → 259 ms** bis zum bedienbaren Menü.

**2. Es wurde alles auf einmal geladen.** Vor dem Start warteten sämtliche
Gegner (auch `enemy3` aus Welle 3 mit 220 KB), die Bossbombe (218 KB), alle
zehn Waffensprites und eine 824 KB große Karten-PNG. Gemessen bei 400 kbit/s
mit 300 ms Latenz und vierfach gedrosselter CPU: **48 249 ms** und **1 964 KB**
vom Klick auf die Waffe bis zum ersten Frame.

Dagegen:

* **Nur laden, was Welle 1 braucht** — Karte, gewählter Charakter, gewählte
  Waffe und die Gegner der ersten Welle. Alles andere läuft danach als
  `backgroundLoad` weiter, während schon gespielt wird.
* **Nachzügler-Schutz:** Taucht ein Gegner oder Boss auf, dessen Sprite noch
  fehlt, wird der Spawn übersprungen und das Bild sofort nachgeladen — statt
  einen leeren Platzhalter zu zeichnen.
* **Karte als JPEG** (1536², ~200 KB statt 824 KB PNG).
* **Zeitlimit pro Datei:** 12 s, dann bricht der Ladevorgang für dieses Bild ab
  (`AbortController`) und das Spiel startet trotzdem. Ein hängender Download
  kann das Ladebild nicht mehr blockieren.
* **Fortschrittsbalken** statt Endlos-Spinner. Dauert es über 8 s, nennt ein
  Hinweis die Dateien, die noch fehlen.

Ergebnis unter denselben 400 kbit/s: **12 530 ms** und **436 KB**.

Zusätzlich werden GIF-Frames beim Dekodieren über eine 32-Bit-Palette
geschrieben (statt vier Einzelbytes) und Frames über 384 px verkleinert – im
Spiel werden sie ohnehin nur 60–200 px hoch gezeichnet.

### Warum Änderungen aus dem Admin sofort ankommen

Das Spiel besteht aus 27 ES-Modulen, die sich gegenseitig mit relativen Pfaden
laden. Früher hing nur an `main.js` eine Versionsnummer – der Browser holte
also `main.js` neu und nahm die 26 importierten Dateien weiter aus seinem
Zwischenspeicher. Ein Upload wirkte dann halb oder gar nicht, und im Admin
geänderte Werte tauchten im Spiel nicht auf.

Jetzt gilt:

* `index.php` und das Dashboard senden `Cache-Control: no-store`. Die Seite
  trägt die Spieldaten in sich und darf nie aus dem Zwischenspeicher kommen.
* Eine **Import Map** in der Seite hängt an **jedes** Modul seine eigene
  Versionsnummer aus dem Änderungsdatum der Datei. Wird eine Datei ersetzt,
  ändert sich ihre Adresse – der Browser muss sie neu holen.

Beides läuft über PHP und braucht keine `.htaccess`, die viele FTP-Programme
ohnehin überspringen.

### Gegner laufen um Wände herum

Früher steuerten Gegner stur auf den Spieler zu und rutschten an Hindernissen
entlang — vor einer langen Mauer blieben sie hängen. Jetzt liegt über der
Karte ein **Strömungsfeld**: Einmal je Takt flutet eine Breitensuche vom
Spieler aus über das Kollisionsraster, danach weiß jede Zelle, wie viele
Schritte sie vom Spieler entfernt ist. Ein Gegner schaut nur noch nach,
welcher Nachbar näher dran ist, und läuft dorthin.

* Der Aufwand hängt an der **Kartengröße, nicht an der Zahl der Gegner** —
  ein Feld genügt für alle. Gemessen: **0,96 ms** je Neuberechnung bei
  16 384 Zellen und vierfach gedrosselter CPU.
* Neu gerechnet wird nur, wenn der Spieler die Zelle wechselt, und höchstens
  fünfmal pro Sekunde. Steht er still, kostet die Wegfindung gar nichts.
* Diagonalen gelten nur, wenn beide angrenzenden geraden Nachbarn frei sind —
  sonst würden Gegner durch Mauerecken schneiden.
* **Wandnähe kostet extra.** Jede Zelle kostet 1 plus einen Aufschlag, wenn
  eine Wand nah ist. Der günstigste Weg führt damit an Ecken vorbei statt
  daran entlang. Weil die Kosten klein und ganzzahlig sind, reicht eine
  Eimer-Warteschlange — das bleibt so schnell wie eine Breitensuche. Der
  Aufschlag muss dabei **in** die Suche, nicht erst in die Richtungswahl:
  sonst entstehen Senken, in denen kein Nachbar besser aussieht als die
  eigene Zelle und der Gegner stehen bleibt.
* Kommt einer trotz allem nicht voran, drückt ihn ein kurzer Stoss von der
  Wand weg; hilft auch das nach 1,6 s nicht, wird er auf die nächste freie
  Zelle gesetzt.
* Im Nahbereich (gut zwei Zellen) steuern Gegner wieder direkt, damit sie
  dicht vor dem Spieler nicht am Zellenraster entlangzucken.

Nachgemessen: Ein Gegner hinter einer 700 px langen Mauer läuft außen herum
und kommt nach 9,7 s an. Auf der Standardkarte erreichen in zwölf Sekunden
**23 von 24** Gegnern den Spieler bei 45 px Restabstand — ohne Wegfindung
sind es 20 von 24 bei 132 px. Die Neuberechnung kostet **1,75 ms** bei 16 384
Zellen und vierfach gedrosselter CPU, höchstens fünfmal pro Sekunde.

### Warum es flüssig läuft

Die Spiellogik selbst ist nicht das Problem — sie braucht bei 60 Gegnern und
vierfach gedrosselter CPU keine 3 ms pro Bild. Teuer war alles rundherum:

* **Kein Weichzeichner über der laufenden Leinwand.** Die Overlays liegen mit
  `backdrop-filter` über dem Spielfeld. Zeichnete die Leinwand darunter weiter,
  musste der Browser den Weichzeichner in *jedem* Bild neu über den ganzen
  Bildschirm legen. Jetzt steht das Zeichnen still, solange ein Fenster offen
  ist — das letzte Bild bleibt einfach stehen. Auch die HUD-Pillen haben ihren
  `backdrop-filter` verloren; sie sind stattdessen etwas dunkler.
  Das allein war der Unterschied zwischen 35 und 60 FPS.
* **Automatische Qualitätsstufe.** Fällt die Bildrate unter 45, zeichnet das
  Spiel schrittweise gröber (bis 55 % der Auflösung) und wieder feiner, sobald
  Luft da ist. Pixel-Art verträgt das gut — sichtbar ist vor allem, dass es
  flüssig bleibt.
* **Kleinere Sprites.** Figuren werden 60–200 px hoch gezeichnet; Sprites
  werden deshalb auf höchstens **192 px** Kantenlänge verkleinert (vorher 384).
  Das drückt den Sprite-Speicher von 38,5 MB auf 18,5 MB — davon 9 MB die
  Karte, die als einzige ihre volle Auflösung behält.
* **Nachschlagetabelle statt Schleife** für das aktuelle Einzelbild einer
  Animation. Bei 60 Gegnern wurde die Schleife 60× pro Bild durchlaufen.
* **Schatten als fertiges Bild** statt einer gezeichneten Ellipse je Gegner.
* Fester Simulationstakt (1/60 s) mit Delta-Time — 60-Hz- und 120-Hz-Geräte
  spielen exakt gleich schnell.
* Object Pools für Projektile, Partikel, Schadenszahlen, Nahkampfangriffe und Gegner.
* Räumlicher Hash (Spatial Hash) für Trefferabfragen statt Alle-gegen-alle.
* Offscreen-Culling beim Zeichnen, harte Obergrenzen für Partikel (220) und
  Schadenszahlen (60).
* HUD ist normales DOM und wird nur ~12×/s aktualisiert, nie pro Frame.
* `maxEnemies` steht auf 60 statt 80 — jeder Gegner kostet Zeichenzeit, und
  60 füllen den Bildschirm bereits gut. Im Admin änderbar.

Gemessen über drei Minuten Dauerspiel bei vierfach gedrosselter CPU:
**durchgehend 60 FPS**, Speicherverbrauch konstant bei 9,5 MB (vorher fiel die
Bildrate auf 35 und blieb dort).

---

## Menü

Das Hauptmenü liegt auf dem Titelbild (`assets/sprites/menu-hintergrund.jpg`,
900 × 900, 159 KB). Weil das Artwork quadratisch ist und den Schriftzug selbst
trägt, passt sich die Darstellung dem Format an:

* **Hochkant** — das Bild belegt oben ein Quadrat und bleibt vollständig
  sichtbar, die Knöpfe sitzen mittig darunter.
* **Quadratisch und breiter** — das Bild füllt den Hintergrund, oben
  ausgerichtet, damit der Schriftzug nie angeschnitten wird.
* **Handy quer** — die Knöpfe rücken auf eine dunkle Säule links, rechts
  bleibt das Artwork frei.

Die Knöpfe greifen die Farben des Bildes auf: *Spielen* in demselben Gold wie
der Schriftzug, die übrigen als gehauener Stein mit Goldkante. Der Text-Titel
bleibt im HTML für Vorleseprogramme erhalten, ist aber ausgeblendet — den
Namen trägt das Bild.

Das Hintergrundbild lässt sich im Admin unter *Laden & Aussehen* austauschen.

### Zwei Schriften, beide mitgeliefert

Eine Pixelschrift sah zum Artwork stimmig aus, war aber klein gesetzt nicht
zu gebrauchen: Ein Pixelraster liegt bei 11 px unter der Auflösung des
Displays, und eine **2 war kaum von einer 8 zu unterscheiden**. Sie ist
deshalb ganz raus. Jetzt zwei Schnitte mit klarer Aufgabenteilung:

* **Cinzel** für alles Grosse — Titel, Knöpfe, Banner, Countdown,
  Überschriften. Eine kräftige Schrift mit Fantasy-Anmutung, die auch bei
  13 px eindeutig bleibt und deutlich besser zum Artwork passt.
* **Rubik** für Fliesstext und alle Zahlen — in jeder Grösse eindeutig.

Zusätzlich ist **alles ein gutes Stück grösser** geworden: Grundgrösse 16 px,
der kleinste Text im Spiel 13 px statt 10,5, die Werte im HUD 19 px, Knöpfe
16 px bei 52 px Höhe. Zahlen laufen mit `tabular-nums`, damit sie nicht
springen. Für schmale Handys (unter 380 px) und flache Querformate gibt es je
eine Stufe darunter, damit nichts kollidiert.

Beide Schriften liegen als `assets/fonts/*.woff2` (95 KB zusammen) **beim
Spiel selbst**, es wird nichts von Google nachgeladen — auch im Admin nicht.
Das Spiel sieht damit ohne Verbindung nach aussen gleich aus, und im ZIP ist
alles dabei.

Alle Flächen, die vorher schlichtes Darkmode-Grau waren — Panels, Overlays,
Formulare, die Anmeldung, Regler, Schalter, Lebensbalken, der Ultimate-Knopf —
tragen jetzt dieselbe Sprache wie das Titelbild: gehauener Stein, Goldkanten,
warmes Pergament als Textfarbe.

### Charakterauswahl

Statt einer Liste aus fünfzehn Karten steht **eine Figur in der Mitte eines
Raums** (`assets/sprites/charakter-hintergrund.jpg`, 1200 × 800, 172 KB) und
zeigt ihr Ruhebild. Links und rechts blättert man durch, daneben stehen Name,
Kurzbezeichnung, Beschreibung, Werte und die Spezialfähigkeit. Eine Punktreihe
zeigt, wo man in der Liste steht; gesperrte Figuren sind darin rot markiert.
Auf dem Rechner blättern zusätzlich die Pfeiltasten, Enter wählt aus.

Hintergrundbild, Standort und Grösse der Figur kommen aus dem Admin — der
angegebene Punkt ist die **Standfläche**, nicht die Mitte, damit die Figur auf
jedem eigenen Hintergrund wieder auf dem Podest landet.

---

## Konto, Erfahrung und Bestenliste

* **Konto anlegen** im Hauptmenü unter *Anmelden*: Benutzername (3–16 Zeichen)
  und Passwort (ab 6 Zeichen). Passwörter liegen nur als bcrypt-Hash in
  `data/accounts/`. Nach 8 Fehlversuchen ist das Konto 5 Minuten gesperrt.
* **Ohne Konto** kann man normal spielen – Erfahrung und Bestwerte werden dann
  aber nicht gespeichert. Das Spiel sagt das offen an.
* **Jede geschaffte Welle gibt genau einen Erfahrungspunkt.** Das läuft still im
  Hintergrund mit; erst im Todesbildschirm steht, wie viele dazugekommen sind.
* **Punktestand** = Wellen × 100 + Kills × 5 + Geld. Der beste Wert je Konto
  landet in der Bestenliste (Menü → *Bestenliste*, Top 25, eigener Platz
  hervorgehoben).
* **Charaktere freischalten:** Drei Figuren sind von Anfang an spielbar, jede
  weitere kostet 20 Erfahrungspunkte. Die Punkte werden beim Freischalten
  verbraucht, die Erfahrung selbst bleibt als Gesamtwert stehen.

Die Werte werden serverseitig begrenzt (max. 500 Wellen, 100 000 Kills pro Run),
und der Freischaltpreis kommt aus den Serverdaten, nicht aus der Anfrage.

---

## Charaktere

Fünfzehn Figuren, drei davon von Anfang an frei. Jede hat eigene Werte, zwölf
haben zusätzlich eine **Spezialfähigkeit** – und jede dieser Fähigkeiten ist im
Spiel wirklich umgesetzt, nicht nur ein Satz auf der Karte.

| Charakter | Rolle | Fähigkeit | Werte | Frei |
|-----------|-------|-----------|-------|------|
| Nova | Späherin | – | +12 % Tempo, +15 % Aufsammeln, +6 % Krit | ja |
| Bruno | Bollwerk | – | +30 % Leben, +3 Rüstung, +25 % Rückstoß, −6 % Tempo | ja |
| Kira | Duellantin | – | +12 % Schaden, +10 % Angriffstempo, +20 % Krit-Schaden | ja |
| Ruun | Magier | Glückskarten | +18 % Reichweite, +20 % Projektiltempo, −8 % Leben | 20 XP |
| Vera | Vampirin | Lebensraub | −12 % Leben, +5 % Angriffstempo | 20 XP |
| Tor | Wächter | Wächter | +20 % Leben, 30 Startschild, −4 % Tempo | 20 XP |
| Ember | Brandstifterin | Brandstifter | +4 Feuerschaden, −6 % Schaden | 20 XP |
| Sela | Frostläuferin | Frost | +8 % Tempo, +10 % Reichweite | 20 XP |
| Grom | Berserker | Blutrausch | +10 % Leben, +2 Rüstung, −5 % Angriffstempo | 20 XP |
| Zeno | Sprengmeister | Sprengmeister | +5 % Schaden, +5 % Reichweite, −8 % Leben | 20 XP |
| Mira | Sammlerin | Magnet | doppelte Aufsammelreichweite, +50 % Flaschen | 20 XP |
| Dax | Glücksritter | Goldrausch | +60 % Geld, +5 % Ausweichen, −5 % Leben | 20 XP |
| Iva | Scharfschützin | Scharfschütze | +30 % Reichweite, +25 % Projektiltempo, −14 % Leben | 20 XP |
| Ossa | Zähe Haut | Zweiter Atem | +15 % Leben, +2 Rüstung, +0,5 HP/s | 20 XP |
| Nix | Klingentänzerin | Dornen | +12 % Ausweichen, +6 % Tempo, −6 % Leben | 20 XP |

### Die zwölf Spezialfähigkeiten

| Fähigkeit | Wirkung im Spiel |
|-----------|------------------|
| Lebensraub | Jeder Kill heilt 1,5 HP, ein Boss 25 HP. |
| Dornen | 30 % des Kontaktschadens plus 4 gehen an den Gegner zurück. |
| Glückskarten | Die Seltenheit der Upgrade-Karten wird um zwei Zyklen angehoben. |
| Blutrausch | Bis **+60 % Schaden**, linear steigend mit fehlendem Leben. |
| Zweiter Atem | Einmal je Welle überlebst du den Todesstoß mit 25 % Leben. |
| Brandstifter | Treffer zünden mit 3 HP/s – auch ganz ohne Verbrennungs-Upgrade. |
| Frost | Getroffene Gegner laufen 1,6 s lang nur noch mit 45 % Tempo. |
| Sprengmeister | Getötete Gegner reißen im Umkreis von 95 px 55 % ihres Lebens mit. |
| Magnet | Doppelte Aufsammelreichweite, doppelt so viele Heilflaschen. |
| Goldrausch | 60 % mehr Geld für jeden Gegner. |
| Wächter | Zu Beginn jeder Welle ist der Schild wieder komplett voll. |
| Scharfschütze | Bis **+55 % Schaden**, je weiter der Gegner entfernt ist. |

Im Admin unter **Charaktere** lassen sich pro Figur **siebzehn Werte** einstellen:
Faktoren für Leben, Tempo, Schaden, Angriffstempo, Reichweite, Projektiltempo,
Rückstoß, Aufsammelreichweite, Heilflaschen-Chance und Geld, dazu Zuschläge für
Rüstung, Krit-Chance, Krit-Schaden, Ausweichen, Regeneration, Startschild und
Feuerschaden. Die Fähigkeit wird aus einer Liste gewählt, ihre Wirkung steht
direkt darunter.

### Eigene Sprites: GIF oder fünf Einzelbilder

Im Admin unter **Charaktere** hat jede Richtung (vorne, hinten, seitlich und
**Ruhebild**) zwei Möglichkeiten:

* ein **animiertes GIF** – wird wie bisher automatisch zerlegt, oder
* bis zu **fünf Einzelbilder** – das Spiel baut daraus selbst die Animation.

Das **Ruhebild** ist neu und freiwillig: Solange nichts hinterlegt ist, steht
die Figur im Stand weiter mit dem Bild von vorne da, genau wie bisher. Sobald
dort ein GIF oder Einzelbilder liegen, läuft im Stillstand diese Animation —
im Spiel wie auf der Charakterauswahl, wo die Figur mitten im Raum steht. Das
Ruhebild hat eine eigene Grösse und eigene Spiegelungen wie jede andere
Richtung.

Je Richtung lassen sich zusätzlich einstellen:

* **Größe dieser Richtung** — nur das Bild, die Hitbox bleibt unberührt.
  Seitliche Sprites sind oft anders gezeichnet als die von vorne; 1,35 macht
  das Seitenbild um ein Drittel größer, ohne die Kollision zu verändern.
* **Ganze Richtung spiegeln** — für Sprites, die falsch herum gezeichnet sind.
* **Einzelnes Bild spiegeln** — der kleine ⇋-Knopf an jedem Einzelbild dreht
  genau dieses eine um.

Darunter läuft eine **Laufvorschau**: Die Figur dreht sich durch acht
Richtungen, ein Pfeil zeigt die Laufrichtung, und Größe, Spiegelung und das
aktuelle Einzelbild stehen als Text daneben. Nach der achten Richtung bleibt
sie einmal stehen und zeigt ihr Ruhebild. Änderungen wirken sofort.

Liegen Einzelbilder vor, haben sie Vorrang. Das Tempo steuert **Bildwechsel
(ms)** – 80 ms sind schnelle Schritte, 200 ms ein ruhiger Gang. Bilder
unterschiedlicher Größe werden am Fußpunkt zentriert eingepasst, damit die
Figur beim Wechsel nicht springt.

Der **Staub** unter den Füßen wird beim Laden um 180 Grad gedreht (die
mitgelieferte Wolke zeigt sonst nach oben) und blendet weit innen aus: voll
deckend nur im Kern, danach über zwei Stufen bis auf null. Damit ist von der
rechteckigen Bildkante nichts mehr zu sehen.

Solange keine eigenen Sprites hochgeladen sind, unterscheiden sich die Figuren
über eine **Farbdrehung** (Feld *Farbdrehung*, 0–360°) auf demselben Basissprite.

---

## Spielablauf

1. **Spielen** → Charakter wählen → Starterwaffe wählen → Run startet sofort.
   Die **Karte wird ausgewürfelt**, nicht ausgewählt: Es gab dafür einmal
   einen eigenen Bildschirm, aber wer ihn benutzte, nahm doch jedes Mal
   dieselbe. Jetzt entscheidet der Zufall unter allen aktiven Karten, und
   jede Runde beginnt woanders. Im Admin bleibt alles wie gehabt.
2. Steuerung: **virtueller Joystick** (entsteht dort, wo der Finger die Fläche
   berührt) oder **WASD / Pfeiltasten** am PC.
3. Die Waffe **zielt und feuert automatisch** auf den nächsten Gegner in Reichweite.
   Der **♪-Knopf** oben rechts schaltet die Musik ab.
4. Ein Zyklus besteht aus vier Wellen:

| Welle | Inhalt | Dauer |
|------:|--------|-------|
| 1 | enemy1 (Kriecher) | 60 s |
| 2 | enemy2 (Schleicher) | 60 s |
| 3 | enemy3 (Brecher) | 60 s |
| 4 | boss1 (Bombenwerfer) | bis zu 120 s |

Nach **jeder** Welle pausiert das Spiel und bietet drei zufällige Upgrades an;
direkt danach geht der **Laden** auf, in dem das gesammelte Geld ausgegeben
wird. Danach beginnt der nächste Zyklus mit höheren Werten — endlos.

Das HUD hält oben links Welle, Zeit und Geld, oben rechts Ton, Statistik und
Pause. Beides steht in derselben Reihe im normalen Fluss: Vorher lagen die
Knöpfe fest in der Ecke und deckten auf schmalen Handys die Geldanzeige zu.

Überlebt der Boss die 120 Sekunden, verschwindet er nicht, sondern wird
**wütend** (mehr Tempo, mehr Schaden, kürzerer Bomben-Cooldown). Der Kampf endet
also immer mit einer Entscheidung.

### Wellen bauen aufeinander auf

Jeder Zyklus hat drei normale Wellen und einen Bosskampf. Früher spawnte in
jeder Welle **ausschliesslich** der ihr zugeordnete Gegnertyp — mit zwei
unschönen Folgen: In Welle 2 starben die Gegner aus Welle 1 einfach aus, und
nach dem Boss fing Zyklus 2 wieder nur mit dem schwächsten Gegner an. Es
wirkte, als würde das Spiel nach jedem Upgrade von vorne beginnen.

Jetzt bleibt alles im Spiel, was schon aufgetreten ist. Der Typ der aktuellen
Welle führt, die übrigen kommen mit ihrem Anteil (`waveMixShare`, Standard
45 %) dazu. Ab Zyklus 2 mischt jede Welle alle Typen:

| Welle | Verteilung (gemessen über 600 Ziehungen) |
|-------|------------------------------------------|
| 1-1 | enemy1 100 % — die ruhige Einführung bleibt |
| 1-2 | enemy2 69 %, enemy1 31 % |
| 1-3 | enemy3 55 %, enemy2 24 %, enemy1 22 % |
| 2-1 | enemy1 54 %, enemy2 24 %, enemy3 23 % |
| 3-1 | enemy1 53 %, enemy3 26 %, enemy2 22 % |

Dazu stehen zum Wellenstart schon `waveStartEnemies` Gegner bereit (Standard
4). Nach der Upgrade-Auswahl ist das Feld meist leergeräumt — ohne diese
Starthilfe stand man ein paar Sekunden allein herum, bis der erste Gegner
hereingelaufen kam. Beide Werte stehen im Balancing.

Das Banner nennt jetzt den vollen Stand (*Zyklus 1 · Welle 2 von 4*), damit
ein Wellenwechsel nicht wie ein Neustart aussieht.

### Schwierigkeitsskalierung

Alle Werte hängen an **einer** Zahl: dem Fortschritt. Der ist eine
Kommazahl — 0 in Welle 1, +0,25 je Welle, also +1 je Zyklus:

```
Fortschritt  = (Zyklus − 1) + (Welle − 1) / 4

Gegnerleben  = Basisleben   × healthScaling^Fortschritt     (Standard 1.62)
Gegnerschaden= Basisschaden × damageScaling^Fortschritt     (Standard 1.34)
Tempo        = Basistempo   × speedScaling^Fortschritt      (Standard 1.03)
Spawnrate    = Basisrate    × spawnRateScaling^Fortschritt  (Standard 1.20)
Geld         = Basisgeld    × rewardScaling^Fortschritt     (Standard 1.06)
Gegnergrenze = maxEnemies   × maxEnemiesGrowth^Fortschritt  (Standard 1.16)
Ladenpreise  = Grundpreis   × priceCycleGrowth^Fortschritt  (Standard 1.40)
```

Vorher lief die Rechnung über `Zyklus − 1`. Innerhalb eines Zyklus blieb
damit **drei Wellen lang alles gleich**, dann kam ein Sprung — und es fühlte
sich an, als würden die Gegner gar nicht stärker. Über vier Wellen kommt
jetzt genau dasselbe heraus, aber gleichmässig verteilt.

So sieht die Kurve mit den Standardwerten aus:

| Welle | Leben × | Schaden × | Gegner gleichzeitig | Beute × | Elite | Preise × |
|------:|--------:|----------:|--------------------:|--------:|------:|---------:|
| 1  | 1,0   | 1,0  | 22  | 1,00 | –    | 1,0  |
| 9  | 2,6   | 1,8  | 30  | 1,12 | –    | 2,0  |
| 17 | 6,9   | 3,2  | 40  | 1,26 | 12 % | 3,8  |
| 25 | 18,1  | 5,8  | 54  | 1,42 | 24 % | 7,5  |
| 33 | 47,4  | 10,4 | 72  | 1,59 | 36 % | 14,8 |
| 41 | 124,5 | 18,7 | 97  | 1,79 | 48 % | 28,9 |
| 45 | 201,7 | 25,0 | 113 | 1,90 | 54 % | 40,5 |

Alle Werte sind im Admin unter **Balancing** einstellbar.

### Keine feste Gegnergrenze mehr

`maxEnemies` war früher eine harte Zahl — 60, egal ob Welle 1 oder Zyklus 20.
Sie ist jetzt nur noch der **Startwert** und wächst mit dem Fortschritt: 22
zu Beginn, 113 bei Welle 45. Je weiter man kommt, desto voller wird das Feld.

Zwei Dinge schützen dabei schwache Geräte, ohne dass eine feste Obergrenze
das Spiel bestimmt:

* **`maxEnemiesLimit`** (Standard 260) ist die reine Notbremse.
* Ein **Leistungsbudget** zieht die Grenze langsam zurück, wenn die Bildrate
  unter 38 fällt, und gibt sie wieder frei, sobald Luft da ist. Parallel
  wird bereits die Renderauflösung angepasst; erst wenn das nicht reicht,
  greift die Bremse.

### Elitegegner

Ab Zyklus 3 erscheint ein wachsender Anteil der Gegner als **Elite**:
2,2-faches Leben, 1,4-facher Schaden, 20 % grösser, doppelte Beute und ein
roter Ring am Boden. Der Anteil wächst um 6 Prozentpunkte je Welle bis
höchstens 55 %. Damit sieht man, dass es härter wird — statt es nur an der
Lebensleiste zu merken. Der Ring ist ein einmal gezeichnetes Bild und kostet
je Elite genau einen `drawImage`-Aufruf.

### Boss-Bombe

1. Boss wirft `bombe1` auf die **vorausberechnete** Position des Spielers.
2. Die Bombe landet — **der Aufprall macht keinen Schaden**.
3. Ein Gefahrenkreis wächst (Standard 1,3 s Vorwarnzeit).
4. Die Explosion (`explosion1`) läuft ab.
5. Schaden bekommt **nur**, wer sich im Moment der Explosion im sichtbaren
   Radius befindet. Der Trefferradius entspricht exakt dem gezeichneten Kreis.

---

## Waffen

Zehn Waffen mit sechs wiederverwendbaren Angriffsverhalten. Neue Waffen im
Admin brauchen deshalb keinen neuen Code, nur Werte.

| Waffe | Verhalten | Schaden | Cooldown | Sprite | Projektil | Besonderheit |
|-------|-----------|--------:|---------:|-------:|----------:|--------------|
| Pistole | PROJECTILE | 25 | 0,36 s | 46 px | 16 px | solider Allrounder |
| Sturmgewehr | PROJECTILE | 9 | 0,11 s | 54 px | 14 px | hohe Feuerrate, Streuung |
| Bogen | PROJECTILE | 46 | 0,72 s | 50 px | 20 px | durchbohrt 1 Gegner |
| Armbrust | PROJECTILE | 82 | 1,40 s | 56 px | 26 px | durchbohrt 2 Gegner |
| Zauberstab | MAGIC | 38 | 0,55 s | 50 px | 18 px | Zielsuche, trifft fast immer |
| Speer | THRUST | 42 | 0,62 s | 120 px | – | Stoß nach vorne, schmal |
| Dolch | MELEE_ARC 46° | 17 | 0,19 s | 58 px | – | blitzschnell, kurze Reichweite |
| Schwert | MELEE_ARC 85° | 25 | 0,48 s | 95 px | – | trifft mehrere Gegner |
| Axt | MELEE_360 | 28 | 0,76 s | 88 px | – | volle Drehung, trifft alles |
| Granate | GRENADE | 50 | 1,55 s | 46 px | 34 px | AoE 130, verzögerte Explosion |

### Balancing: alle Waffen sind ungefähr gleich stark

Reiner Schaden pro Sekunde vergleicht Waffen falsch — eine Axt trifft im
Getümmel vier Gegner gleichzeitig, eine Armbrust einen. Deshalb liegt dem
Balancing der **wirksame Schaden** zugrunde:

```
wirksam = Schaden / Nachladezeit × erwartete Ziele je Angriff × Trefferquote
```

| Waffe | DPS | Ziele | Trefferquote | wirksam |
|-------|----:|------:|-------------:|--------:|
| Pistole | 69 | 1,00 | 0,90 | 62 |
| Sturmgewehr | 82 | 1,00 | 0,80 | 65 |
| Bogen | 64 | 1,35 | 0,80 | 69 |
| Armbrust | 59 | 1,60 | 0,75 | 70 |
| Zauberstab | 69 | 1,00 | 1,00 | 69 |
| Speer | 68 | 1,30 | 0,85 | 75 |
| Dolch | 89 | 1,00 | 0,85 | 76 |
| Schwert | 52 | 1,80 | 0,80 | 75 |
| Axt | 37 | 2,60 | 0,80 | 77 |
| Granate | 32 | 2,80 | 0,85 | 77 |

Alles liegt damit zwischen 62 und 77. Nahkampf sitzt bewusst am oberen Rand:
Wer so nah heran muss, kassiert Berührungsschaden. Die Trefferquote schätzt,
wie viel davon im Kampf tatsächlich ankommt — Zielsuche trifft immer, ein
langsamer Pfeil oder eine geworfene Granate nicht.

Beim Aktualisieren einer bestehenden Installation zieht die Migration das
Balancing **einmalig** nach: Nur Schaden und Nachladezeit der mitgelieferten
Waffen werden angepasst, eigene Waffen und alle anderen Felder bleiben
unangetastet.

**Größe und Sitz sind einstellbar.** Im Waffen-Editor legen vier Felder fest,
wie die Waffe getragen wird: *Waffensprite-Größe* und *Projektil-Größe* in
Pixeln, dazu **Waffenhöhe** (negativ = höher, positiv = tiefer – gegen Waffen,
die am Fuß kleben) und **Abstand zum Körper**. Die Vorschau zeigt Waffe und
Projektil in echter Spielgröße neben einer Spielerfigur samt Hüftlinie. Eine Live-Vorschau zeigt beides sofort
in echter Spielgröße neben einem Spieler-Maßstab (78 px). Der Wert ändert nur
die Darstellung, nicht die Trefferzone: Reichweite, Schwungwinkel und AoE-Radius
bleiben eigene Felder.

**Balancing-Regel:** Einzelziel-Waffen schlagen härter zu, Flächenwaffen machen
pro Ziel weniger. Jeder Nahkampfangriff führt eine eigene Trefferliste — eine
Axtrotation trifft denselben Gegner also genau einmal, egal wie viele Frames die
Klinge über ihm steht.

---

### Wo der Schuss die Waffe verlässt

Zwei Werte je Waffe, beide im Admin:

| Feld | Bedeutung |
|------|-----------|
| **Projektil-Starthöhe** (`muzzleOffsetY`) | Höhe des Startpunkts, negativ = höher |
| **Projektil-Startabstand** (`muzzleDistance`) | Abstand vom Körper; **0 = automatisch** aus Haltepunkt und Waffengröße |

Ab Werk sitzt die Mündung auf derselben Höhe wie das gezeichnete Sprite und
knapp hinter dessen Spitze — bei der Pistole also 43 px vor dem Körper. Wer
ein Sprite mit ungewöhnlichem Griff hochlädt, zieht den Startpunkt nach.
Nahkampfwaffen bleiben davon unberührt: Ihr Schwung geht weiter aus der
Körpermitte aus.

### Vorschau: Schuss in alle Richtungen

Sowohl im **Charakter-** als auch im **Waffen-Editor** läuft eine Animation,
die genau mit derselben Formel rechnet wie das Spiel: Die Figur dreht sich
durch acht Blickrichtungen, hält die Waffe und feuert, ein grüner Ring
markiert den Startpunkt des Projektils. Darüber stehen Starthöhe und Abstand
in Zahlen.

* Im **Charakter-Editor** wählst du die Waffe für die Vorschau — so prüfst du
  eigene Sprites gegen jede Waffe.
* Im **Waffen-Editor** wählst du den Charakter — so prüfst du eine Waffe gegen
  jede Figur.

Beide Vorschauen reagieren sofort auf jede Änderung an Größe, Haltepunkt,
Starthöhe und Startabstand, ohne dass das Spiel gestartet werden muss.

---

## Ultimate: Druckwelle

Jeder Charakter hat denselben Notknopf — rund, rechts über der Waffenanzeige.
Er füllt sich sichtbar auf und leuchtet, sobald er bereit ist; am PC löst
zusätzlich die **Leertaste** aus.

Beim Auslösen stösst eine Druckwelle alle Gegner im Umkreis nach aussen. Nah
am Spieler wirkt sie am stärksten, Bosse fängt sie zu einem Viertel. Getroffene
Gegner sind danach kurz benommen, damit sie nicht sofort zurücklaufen.

| Wert | Standard | Bedeutung |
|------|----------|-----------|
| `ultCooldown` | 30 s | Abklingzeit |
| `ultRadius` | 300 px | Wirkungskreis |
| `ultKnockback` | 1400 | Wucht des Stosses |
| `ultDamage` | 0 | Schaden (0 = reiner Rückstoss) |

Nachgemessen: Gegner in 80–90 px Abstand landen nach dem Auslösen bei
158–480 px, die Abklingzeit springt auf 30 s und ein zweiter Druck wird
abgewiesen.

---

## Countdown und Wellenwechsel

Vor jeder Welle und nach jeder Pause laufen **drei Sekunden** herunter —
gross in der Bildmitte, mit dem Stand darüber (*Zyklus 1 · Welle 2 von 4*).
Erst danach erscheinen Boss und Starttruppe. Ohne diesen Vorlauf stand man
nach dem Fortsetzen sofort wieder unter Beschuss.

Am Ende einer Welle **fallen alle übrigen Gegner um** — ohne Belohnung, wer
die Welle aussitzt, hat davon nichts. Der Boss bleibt ausgenommen, seinen
Kampf beendet man selbst.

### Gegner fallen um

Getötete Gegner verschwinden nicht sofort: Sie kippen in 0,26 s zur Seite,
sacken ab und sind nach **0,72 s** ganz weg. Der Schwung aus dem Todesstoss
trägt sie dabei noch ein Stück. Für Treffer, Ziele und die Gegnerzählung sind
sie sofort raus — nur zu sehen sind sie noch.

Zwei Dinge sorgen dafür, dass das Umfallen auch wie ein Umfallen aussieht:

* **Die Laufanimation friert im Moment des Todes ein.** Vorher lief sie
  weiter, und die Leiche zappelte am Boden, als stünde sie gleich wieder auf.
* **Das Ausblenden läuft mit dem Umfallen zusammen** statt danach. Ab einem
  Drittel der Zeit wird die Figur durchsichtig und ist beim Aufkommen fast
  verschwunden — sie bleibt nicht mehr sichtbar liegen und blinkt dann weg.

### Warum ein Run nicht mehr entgleist

Zwei Sorten Runs gab es vorher: nach drei Wellen tot — oder nach fünfzig
Wellen unsterblich mit Millionen auf dem Konto. Beides hatte klare Ursachen.

**Der Schaden lief davon.** Jeder Sondereffekt hatte einen Deckel, aber der
wurde mit der Stufe multipliziert: acht Karten „Berserker" ergaben +400 %
statt +50 %. Ein Dutzend solcher Karten nebeneinander kam auf einen
Schadensfaktor von zwanzig. Jede weitere Stufe zählt jetzt nur noch **gut zur
Hälfte** (`stufenFaktor` in `effects.js`) — stapeln lohnt sich weiter, läuft
aber nicht mehr davon. Zusätzlich hat jeder dauerhafte Zuschlag ein eigenes
Feld mit eigenem Deckel, statt dass alle in einen Topf laufen:

| Karte | vorher | jetzt |
|-------|--------|-------|
| Bankier | +4 % je 100 Gold, **jede Welle obendrauf** | jede Welle neu berechnet, höchstens +60 % |
| Seelenfänger | +6 % je Boss, unbegrenzt | höchstens +80 % |
| Mutation | +4 % je Welle, unbegrenzt | höchstens +120 % |
| Albtraum | +9 % je Zyklus, unbegrenzt | höchstens +120 % |
| Schneeball | +0,25 % je Kill, +75 % je Stufe | hart bei +90 % |
| Überkritisch | Krit-Überschuss unbegrenzt | Überschuss bei 150 gedeckelt |

**Heilung hing an der Angriffsrate.** Lebensraub und Vampirzähne lösen bei
jedem Treffer aus — eine Waffe mit fünf Angriffen je Sekunde heilte fünfmal
so viel wie eine langsame. Genau das machte **Ruun mit dem Dolch** praktisch
unbesiegbar. Heilung aus Treffern läuft jetzt über ein **Budget**: höchstens
5 % des Maximallebens je Sekunde, egal wie oft man trifft. Heilflaschen,
Notverband und Ernte laufen bewusst daran vorbei — die haben ihren eigenen
Takt und lassen sich nicht hochskalieren.

**Ein Pulk war der sofortige Tod.** Der eingehende Schaden hing direkt an der
Zahl der Gegner, die einen berührten: zwanzig Gegner mit 0,8 s
Berührungspause ergeben fünfundzwanzig Treffer je Sekunde. Nach jedem Treffer
ist man jetzt **0,4 Sekunden unverwundbar** (`hitInvuln`), was den Schaden auf
gut zwei Treffer je Sekunde deckelt — unabhängig davon, wie viele anstehen.
Rüstung wirkt dadurch nebenbei doppelt so stark, weil sie je Treffer abzieht.

**Geld wuchs schneller als die Preise.** Die Beute stieg exponentiell
(×1,25 je Zyklus), die Ladenpreise nur linear. Ab Zyklus zehn konnte man sich
jede Welle alles kaufen. Jetzt wachsen **beide exponentiell**, und die Preise
schneller als das Einkommen: Beute ×1,06, Preise ×1,40 je Zyklus. Dazu ist
die Grundbeute halbiert (`moneyMultiplier` 0,45) und die Beute je Gegnertyp
gesenkt. Über einen ganzen Run bleibt der Kontostand damit meist zweistellig
— Kaufen ist wieder eine Entscheidung.

**Einzelne Kombinationen stachen heraus.** Ruuns *Glückskarten* hoben die
Seltenheit um zwei ganze Zyklen an (jetzt einen), der Seltenheitsbonus je
Zyklus lag bei 1,35 (jetzt 1,22), und Dax hatte den Geldbonus doppelt — in
der Fähigkeit *und* in seinen Werten. Der steht jetzt nur noch in der
Fähigkeit, und die gibt +45 % statt +60 %.

#### Gemessen, nicht geraten

Für das Balancing gibt es einen Prüfstand: Das Spiel läuft im
Schnelldurchlauf ohne Bild, von Hand getaktet, mit einer Spieler-KI, die aus
24 Richtungen die beste wählt, Bossbomben ausweicht, die Ultimate benutzt und
mit Nahkampfwaffen bewusst herangeht. Nach jeder Welle zieht sie eine
zufällige Karte und kauft im Laden, solange das Geld reicht.

Zwölf Runs quer durch die Charaktere, vorher und nachher:

| | vorher | jetzt |
|---|---|---|
| Erreichte Wellen | 2 · 2 · 4 · 6 · 7 · 10 · 11 · 11 · 20 · **∞** · **∞** | 3 · 4 · 7 · 9 · 11 · 11 · 12 · 13 · 14 · 16 · 17 · 18 |
| Gold bei Welle 40 | 1 877 529 | – (niemand kommt so weit) |
| Leben bei Welle 30 | 100 % | – |

Die beiden Endlos-Runs sind weg, und der Abstand zwischen dem schlechtesten
und dem besten Lauf ist von Faktor 20 auf Faktor 6 geschrumpft. Ruun mit dem
Dolch, vorher zweimal von sechs unendlich, kommt jetzt auf 5 · 6 · 7 · 9 · 17.
Die KI spielt deutlich schlechter als ein Mensch — die Zahlen sind zum
Vergleichen da, nicht als Vorhersage.

---

## Stimmung: Teilchen über dem ganzen Bild

Über der Spielfläche schwebt feiner Staub — winzige Punkte, die langsam
aufsteigen, seitlich driften und dabei sacht flimmern. Sie liegen im
**Bildschirmraum**, nicht in der Welt: So sind sie immer zu sehen, egal wo
die Kamera steht, und es fällt keine einzige Umrechnung an.

Farbe und Menge stehen **je Karte** im Admin (*Maps → Bearbeiten*): warmes
Gold für eine Steinbruch-Arena, kaltes Blau für eine Eishöhle, 0 schaltet sie
ganz ab. Alle Werte liegen in flachen `Float32Array`s, damit pro Bild kein
einziges Objekt angefasst wird.

Gemessen: 45 Teilchen auf einem Mobil-Viewport mit 43–50 Gegnern —
**60 FPS mit und ohne**, kein messbarer Unterschied.

---

## Wie ein Schlag aussieht

Nahkampfwaffen zogen früher einen flachen weissen Keil hinter sich her. Jetzt
zieht eine **Sichel** mit:

* ein einziger Pfad — aussen ein sauberer Kreisbogen, innen eine Linie aus
  24 Punkten, deren Abstand zur Klinge hin wächst,
* gefüllt mit einem **Farbverlauf** entlang der Sehne: hinten unsichtbar,
  vorne hell. In Streifen gezeichnet gab es sichtbare Kanten — eine Fläche
  mit Verlauf hat keine,
* eine **scharfe Vorderkante** direkt an der Klinge, darunter ein weicher
  Schein,
* ein paar **Funken**, die entlang der Spur verglühen,
* alles im Additiv-Modus, damit es leuchtet statt zu decken.

Der Stoss (Speer) bekommt dasselbe in gerade: ein nach vorne spitz
zulaufender Streifen, ein heller Kern auf der Achse und Funken an der Spitze.

Die **Farbe der Spur** steht je Waffe im Admin — der Zauberstab schwingt
violett, Stahl bleibt goldweiss.

### Wo ein Schlag ansetzt

Der Angriff sass bisher immer auf Fusshöhe, während die Waffe mittig in der
Hand lag. Beim Speer sah man das deutlich: zentriert im Stand, aber beim
Stich rutschte er zehn Pixel nach unten. Jede Waffe hat jetzt zwei eigene
Werte im Admin:

| Wert | Wirkung |
|------|---------|
| **Schlag-Starthöhe** | Höhe des Ansatzpunktes. Derselbe Wert wie die Waffenhöhe lässt den Stich genau dort ansetzen, wo die Waffe liegt. |
| **Schlag-Startabstand** | schiebt den Ansatzpunkt nach vorne, weg vom Körper. |

Die Vorschau im Waffen-Editor zeigt bei Nahkampfwaffen jetzt den **Schlag
statt eines Projektils** — Schwung, Stoss und ein grüner Punkt am
Ansatzpunkt, in allen acht Richtungen.

---

## Der Laden

Geld war bisher nur eine Zahl im HUD. Jetzt geht **nach jeder Upgrade-Karte
der Laden auf**, und dort wird es ausgegeben.

* **Vier Auslagen** (im Admin einstellbar) — Upgrades, gelegentlich auch eine
  Waffe. Höchstens eine Waffe je Auslage: Man kann ohnehin nur eine tragen.
* **Kaufen, so lange Geld da ist.** Man muss nicht. Wer spart, hat beim
  nächsten Besuch mehr — die Preise steigen aber mit dem Zyklus.
* **Besser heisst teurer.** Der Preis entsteht aus dem Grundpreis mal dem
  Faktor der Seltenheit, mal dem Preiswachstum hoch Fortschritt, mal einem
  Aufschlag je Stufe, die man von diesem Upgrade schon hat:

  ```
  Preis = Grundpreis × Seltenheit × priceCycleGrowth^Fortschritt
                                  × (1 + Stufenaufschlag × vorhandene Stufen)
  ```

  Mit den Standardwerten kostet ein gewöhnliches Upgrade 26, ein legendäres
  140, eine Waffe 104 — und die zweite Stufe desselben Upgrades 45 % mehr.
  Der Aufschlag je Zyklus war früher **linear**, während das Geld
  exponentiell wuchs; ab Zyklus zehn konnte man sich alles leisten. Jetzt
  wächst er exponentiell und schneller als die Beute (1,40 gegen 1,06), damit
  Kaufen bis zum Schluss eine Entscheidung bleibt.
* **Tauschen** wirft alle nicht gemerkten Auslagen weg und legt neue hin. Der
  erste Tausch kostet 18, jeder weitere das 1,6-fache. Verkaufte Plätze zählen
  dabei wie freie: Wer alles gekauft hat, darf gegen Aufpreis neue Ware holen.
* **Merken** (Schloss oben rechts an der Karte) hält eine Auslage fest. Etwas
  Legendäres, für das das Geld noch nicht reicht, bleibt so über den Tausch
  hinweg liegen. Standardmässig lassen sich drei gleichzeitig merken.
* **Gekauft** zeigt eine Übersicht: die aktuelle Waffe und jedes Upgrade des
  Runs mit seiner Stufe.

### Aufbau des Ladens

Drei Ebenen liegen übereinander, alle drei im Admin austauschbar:

| Ebene | Datei | Grösse |
|-------|-------|--------|
| Hintergrund | `assets/sprites/shop-hintergrund.jpg` | 1200 × 800, 159 KB |
| Händler | `assets/sprites/haendler-schwein.png` | 394 × 700, 220 KB |
| Tresen (davor) | `assets/sprites/shop-tresen.png` | 1200 × 400, 399 KB |

Der Händler nimmt bis zu **fünf Einzelbilder** als Ruhebild — genau wie die
Charaktere. Liegt nur eines vor, atmet er über eine leichte Bewegung. Position
und Grösse von Händler und Tresen stehen im Admin unter *Laden & Aussehen*, mit
einer Vorschau, die die Anordnung live zeigt.

Auf breiten Bildschirmen liegen die Auslagen links und der Händler steht
rechts hinter dem Tresen. Hochkant rückt er in die Lücke zwischen Auslagen und
Tresen, statt hinter den Karten zu verschwinden.

### Warum der Laden nicht ruckelt

Läden mit fliegenden Karten ruckeln oft, weil jede Bewegung ein neues Layout
auslöst. Hier bewegt sich nichts, was den Browser zum Nachrechnen zwingt:

* **Nur `transform` und `opacity`** in allen Animationen — beide rechnet der
  Browser im Compositor, ohne Layout und ohne Neuzeichnen.
* **Beim Kauf wird nicht die ganze Auslage neu gebaut**, sondern genau die
  eine Karte umgeschrieben. Der Rest steht still.
* **Die Münzen** fliegen über die Web-Animations-API und räumen sich selbst
  wieder ab; es gibt keine Endlosschleife im Hintergrund.
* **Die Bilder des Händlers liegen alle gleichzeitig übereinander**, sichtbar
  ist immer genau eines. So wird mitten in der Animation nichts nachgeladen.

Gemessen auf einem vierfach gedrosselten Handy (390 × 844): 90 Bilder im
geöffneten Laden, **kein einziges länger als 50 ms**; fünf Käufe und Täusche
hintereinander in 195–439 ms.

---

## Upgrades

**104 Upgrades** in vier Seltenheiten — 22 gewöhnliche, 32 seltene, 33
epische, 17 legendäre. Nach jeder Welle werden drei Karten gezogen; mit
steigendem Zyklus — und mit **Glück** — wachsen die Chancen auf seltene
Karten. **55 davon tragen einen Sondereffekt** mit eigener Logik im Spiel.

Eine Karte kann **mehrere Werte gleichzeitig** verschieben (Proteinshake gibt
Leben *und* Schaden, Glaskanone gibt Schaden *und* nimmt Rüstung) und
zusätzlich einen **Sondereffekt** mitbringen, der eigene Logik im Spiel hat.

Waffen erscheinen **nicht mehr als Karte** — die gibt es nur noch beim Händler.

### Stufen: was man schon hat, sieht man

Hat man ein Upgrade bereits, zeigt die Karte (und die Auslage im Laden) das an:

* eine goldene Marke **Stufe 2**, **Stufe 3** … mit der Stufe nach dem Kauf,
* darunter **Gesamt danach:** mit der Summe aller Stufen zusammen — bei einem
  Upgrade mit +18 % Schaden also *+36 % Schaden* beim zweiten Kauf,
* und einen höheren Preis, weil jede weitere Stufe teurer wird.

Auch die Statistik im Spiel und der Todesbildschirm listen jedes Upgrade mit
seiner Stufe statt nur mit einem „×2".

Beeinflussbare Werte: Schaden, Angriffstempo, Bewegungstempo, Max. Leben,
Rüstung, Schild, Krit-Chance, Krit-Schaden, Projektiltempo, Reichweite,
Rückstoß, Ausweichen, Regeneration, Feuerschaden, Heilflaschen-Chance,
Aufsammelreichweite, **Lebensraub, Glück, Geld, Projektilschaden,
Nahkampfreichweite, Fernkampftempo und Dornen**.

### Die 55 Sondereffekte

| Effekt | Wirkung |
|--------|---------|
| Lebensraub | Ein Teil des Schadens kommt als Leben zurück |
| Vampirzähne | Kritische Treffer heilen zusätzlich |
| Dornenhaut | Berührende Gegner nehmen Schaden |
| Kampfrausch | Kills geben 3 s lang mehr Angriffstempo |
| Kampfdroge | Nach einem Treffer 2,5 s mehr Angriffstempo |
| Multikill | Vier Kills in 2,5 s geben 4 s mehr Schaden |
| Perfektionist | Bis zu +30 % Schaden nach 8 s ohne Treffer |
| Berserker | Bis zu +50 % Schaden bei fast leerer Leiste |
| Henker | +35 % gegen Gegner unter halbem Leben |
| Doppelschuss | 20 % Chance auf ein zweites Projektil |
| Geistergeschoss | Projektile durchdringen einen Gegner mehr |
| Kettenreaktion | 28 % Chance, dass die Leiche explodiert |
| Turbo | Wer dich rammt, nimmt Schaden |
| Notverband | +12 % Leben zu jeder Welle |
| Schatzjäger | +60 % aus Bossen und Truhen |
| Letzte Kartoffel | Überlebt einmal je Welle den Todesstoss |
| Schwarzes Loch | Zieht alle 9 s alles im Umkreis von 420 px heran |
| Zeitlupe | Unter 35 % Leben laufen Gegner mit 55 % Tempo |
| Midas-Hand | 16 % Chance auf Bonusgold je Kill |
| Todeswelle | Unter 25 % Leben eine Druckwelle, alle 14 s |
| Seelenfänger | Jeder Boss gibt dauerhaft +6 % Schaden |
| Unendlicher Hunger | Je 25 Kills dauerhaft +6 Leben |
| Klonmaschine | Alle 4 s ein zusätzlicher Schuss |
| Blutpakt | +40 % Schaden, jede Welle kostet 8 Leben |
| Fluch der Gier | +60 % Geld, +25 % Gegnerleben |
| Chaos-Kern | Jede Welle ein Vorteil und ein Nachteil |
| Goldener Würfel | Jede Welle ein zufälliger Wert dauerhaft besser |
| Mutation | Jede Welle +4 % Schaden, +5 % Gegnerleben |
| Kartoffelgott | Sechs Werte auf einmal — und zähere Gegner |

Und die verrückte Abteilung — Karten, deren Stärke davon abhängt, wie gerade
gespielt wird:

| Effekt | Wirkung |
|--------|---------|
| Schwarmherz | **+1 % Schaden je lebendem Gegner** (bis +60 %) |
| Einzelgänger | Das Gegenteil: bis +45 %, je leerer die Karte |
| Goldklinge | +5 % Schaden je 100 Geld im Beutel (bis +50 %) |
| Armenrecht | +30 % Schaden, solange du unter 60 Geld hast |
| Schwung | +16 % Schaden, solange du läufst |
| Bollwerk | +22 % Schaden, solange du stehst |
| Zocker | Jeder einzelne Treffer halb **oder** doppelt |
| Schneeball | Jeder Kill dauerhaft +0,25 % Schaden (bis +75 %) |
| Überkritisch | Alles über 100 % Krit-Chance wird zu Schaden |
| Albtraum | +9 % Schaden je Zyklus |
| Adrenalin | Bis +40 % Angriffstempo bei fehlendem Leben |
| Rage | Unter 30 % Leben +45 % Angriffstempo |
| Ernte | Je 10 Kills +5 Leben |
| Blutgeld | Jeder Kill: +3 Gold und dauerhaft +0,15 % Schaden |
| Schutzengel | Nach jedem Treffer 0,9 s unverwundbar |
| Vergeltung | Treffer lösen eine Druckwelle aus (220 px, 30 Schaden) |
| Bankier | Je Welle +4 % Schaden je 100 Gold — ohne dass Gold wegfällt |
| Roulette | Jede Welle ein zufälliger Wert, aber richtig |
| Frostaura | Alles im Umkreis von 170 px läuft mit 60 % Tempo |
| Flammenaura | Alles im Umkreis von 150 px fängt Feuer |
| Igelpanzer | 14 Schaden/s im Umkreis von 130 px |
| Zeitriss | Alle 12 s für 3 s +80 % Angriffstempo |
| Magnetfeld | Aufsammelreichweite über die ganze Karte |
| Durchschlag | +3 Durchschläge je Stufe |
| Echo | Jeder vierte Schuss feuert sofort noch einmal |
| Wuchtgeschoss | +40 % Projektilgrösse, +18 % Projektilschaden |

Alle Zahlen stehen gebündelt in `assets/js/game/effects.js`. Getestet wurde
das, indem **alle 104 Karten nacheinander auf einen laufenden Run angewandt**
wurden: keine Fehler, 55 Effekte gleichzeitig aktiv, danach 60 FPS.

### Verbrennung

| Karte | Seltenheit | Wirkung | Max. Stufen |
|-------|-----------|---------|-------------|
| Verbrennung | Selten | +3 Feuerschaden / Sek. | 10 |
| Inferno | Episch | +9 Feuerschaden / Sek. | 6 |

Ein Treffer setzt den Gegner in Brand: Er verliert `burnDuration` Sekunden lang
(Standard 3) weiter Leben und trägt dabei sichtbar Flammen. Jede weitere Stufe
addiert sich, deshalb fängt die erste Karte bewusst klein an – eine Stufe macht
9 Schaden pro Brand, zehn Stufen 90. Der Feuerschaden wird mit dem
Schadensfaktor des Runs multipliziert, damit er in späten Zyklen nicht
bedeutungslos wird.

Ein neuer Treffer frischt die Brenndauer auf, stapelt aber keine zweiten
Brände auf demselben Gegner. Abgerechnet wird alle 0,25 s, eine Schadenszahl
erscheint nur etwa einmal pro Sekunde je Gegner – sonst würden die orangen
Zahlen bei vielen Gegnern alles andere überdecken.

### Gegenstände auf der Karte

Heiltrank und Schatztruhe laufen über dasselbe Modell. Im Dashboard unter
**Gegenstände** legst du beliebig viele an und stellst je Stück ein:

| Feld | Bedeutung |
|------|-----------|
| Effekt | `heal` (Prozent Leben), `money` (zufällig zwischen Wert und Wert 2), `shield`, `speed` (Prozent für Wert 2 Sekunden), `magnet` |
| Art | *Aufsammeln* fliegt zu und verschwindet · *Truhe* öffnet sich und löst sich danach auf |
| Versuch alle (s) | Wie oft gewürfelt wird |
| Chance je Versuch | Wahrscheinlichkeit, dass dabei etwas erscheint |
| Höchstens gleichzeitig | 0 schaltet den Gegenstand ab |
| Verschwindet nach | Lebensdauer, blinkt vorher |
| Mindest-/Höchstabstand | Wo er auftaucht — in Laufweite, nie vor den Füßen |
| Offen sichtbar | Nur für Truhen: wie lange die offene Truhe stehen bleibt |
| Liegen lassen | Nicht aufnehmen, solange es nichts brächte (volles Leben) |
| Partikelfarbe, Ton | Wie es aussieht und klingt |

Die Seite rechnet dir direkt aus, wie oft ein Gegenstand pro Minute erscheint.

**Mitgeliefert:**

* **Heiltrank** — heilt 10 %, alle 14 s mit 35 % Chance, höchstens 3 gleichzeitig.
  Fliegt in Aufsammelreichweite zu und bleibt bei vollem Leben liegen.
* **Schatztruhe** — 25–70 Geld, alle 26 s mit 30 % Chance, höchstens 2. Sie
  fliegt *nicht* mit: man geht zu ihr hin. Beim Berühren öffnet sie sich mit
  einem Schwall Goldpartikel, zeigt das offene Bild und löst sich nach zwei
  Sekunden auf.

Die Karte **Alchemie** und die Fähigkeit **Magnet** erhöhen die Chance auf
alles, was heilt — Truhen bleiben davon unberührt.

Gemessen ohne Alchemie: knapp **15 Tränke in zehn Minuten**, also etwa einer
alle vierzig Sekunden. Mit zwei Stufen Alchemie sind es 27.

### Portale

Im Karten-Editor (**Maps → Hindernisse & Portale**) setzt du mit *Portal rot*
und *Portal blau* Portale auf die Karte. Ein Tippen auf ein gesetztes Portal
entfernt es wieder, die Nummer im Kreis zeigt die Zuordnung.

Verbunden wird **paarweise in der Reihenfolge**: das erste rote führt zum
ersten blauen und zurück, das zweite zum zweiten und so weiter. Bleibt eine
Farbe übrig, führt sie zum letzten Gegenstück — so entsteht nie ein Portal ins
Nichts. Höchstens 16 Portale je Karte.

Der Sprung läuft in zwei Hälften: Erst zieht sich die Figur zusammen und wird
durchsichtig, dann erscheint sie am Ziel wieder und die Kamera springt mit.
An beiden Enden stiebt ein Schwall Funken in der Portalfarbe. Danach ist das
Portal 1,6 Sekunden gesperrt, damit man nicht sofort zurückgezogen wird.
Portale sprühen auch im Ruhezustand ein paar aufsteigende Funken und pulsieren
leicht.

---

## Admin-Dashboard

| Seite | Kann |
|-------|------|
| **Dashboard** | Überblick, alles auf Standard zurücksetzen |
| **Maps** | Bild hochladen, Hindernisse malen, Startpunkt, Gegnerzonen und **Portale** setzen, **Farbe und Menge der Stimmungsteilchen**, aktivieren, löschen |
| **Gegner** | Anlegen, bearbeiten, duplizieren, löschen; Sprite, Werte, Welle, Hitbox |
| **Waffen** | Anlegen, bearbeiten, duplizieren, löschen; alle Kampfwerte, Verhalten, Starterwaffe, **Ansatzpunkt und Farbe des Schlags** |
| **Upgrades** | Anlegen, bearbeiten, duplizieren, löschen; Stat, Modifikator, Seltenheit, Gewicht, Stapel |
| **Gegenstände** | Tränke, Truhen und eigene Fundstücke: Wirkung, Spawnrate, Anzahl, Lebensdauer, Fundort, Partikelfarbe, Ton |
| **Spieler** | Basiswerte und alle vier Spieler-Sprites |
| **Balancing** | Wellendauer, Spawnrate, Skalierung, Bombenwerte, Kartenchancen, Ultimate |
| **Charaktere** | Anlegen, bearbeiten, duplizieren, löschen; Sprites (GIF oder 5 Einzelbilder je Richtung, **inklusive Ruhebild**), Bildtempo, Farbdrehung, Fähigkeiten, Werte, Hitbox, Freischaltkosten |
| **Audio** | **Zwei Musiktitel** (Menü und Kampf) und **je Ereignis eine eigene Sounddatei** mit eigener Lautstärke, Vorhören, Autostart |
| **Laden & Aussehen** | Bilder und Anordnung des Ladens (Hintergrund, Händler mit 5 Ruhebildern, Tresen), alle Preise, Tauschkosten, Merk-Grenze; dazu die Hintergründe von Menü und Charakterauswahl und der Standort der Figur — beides mit Live-Vorschau |

### Karten-Editor

Eine neue Map ist nach dem Upload sofort ein spielbares Level. Im Editor:

* **Touch** — der Editor startet in diesem Modus. Schieben und zoomen, ohne
  dass ein Finger etwas malt. Erst mit *Malen* oder *Radieren* verändert eine
  Bewegung die Karte. Vorher konnte man beim Zurechtrücken versehentlich
  Hindernisse einzeichnen.
* **Malen / Radieren** mit einstellbarer Pinselgröße
* **Rückgängig / Wiederholen** (40 Schritte)
* **Alles löschen**, **Einpassen**, Zoom-Regler
* **Startpunkt** setzen (grüner Kreis)
* **Gegnerzonen** setzen (blaue Kreise) — ohne Zonen spawnen Gegner automatisch
  rund um den Bildausschnitt
* **Portale** setzen (*Portal rot* / *Portal blau*); erneutes Tippen entfernt
  eines wieder, die Nummer zeigt die Zuordnung
* **Touch:** ein Finger malt, zwei Finger verschieben und zoomen

Rote Flächen sind blockiert — sichtbar **nur** im Editor, im Spiel unsichtbar.

### Datenhaltung

Alles landet in `data/content.php` — derselben Datei, aus der das Spiel liest.
Nach einem Reload ist alles noch da. Schreibzugriffe laufen atomar über eine
temporäre Datei mit Dateisperre. Alle Eingaben werden serverseitig geprüft und
begrenzt (Leben > 0, Cooldown > 0, Pfade nur innerhalb der Asset-Ordner usw.).

**Warum `.php` und nicht `.json`:** Jede Datendatei beginnt mit `<?php exit; ?>`
und dahinter steht das JSON. PHP liest die Datei, schneidet die erste Zeile ab
und wertet den Rest aus. Ruft jemand die Datei direkt über den Browser auf,
führt der Server sie aus — und sie bricht sofort ab, ohne etwas auszugeben.
Der Schutz braucht also kein `.htaccess`, das viele FTP-Programme ohnehin
überspringen. Das gilt für `data/content.php`, `data/admin.php` und alle
Konten unter `data/accounts/`. Eine alte `data/content.json` aus einer
früheren Version wird beim ersten Start automatisch übernommen.

---

## Musik und Ton

`assets/audio/music-arena.mp3` läuft als Endlosschleife im Hintergrund
(2,3 MB, 64 kbps, ca. 5 Minuten).

* Die Datei wird **erst nach der ersten Berührung** geladen — das Menü erscheint
  also sofort, auch bei langsamer Mobilverbindung.
* Browser starten Ton grundsätzlich nicht von allein. Das Spiel merkt sich den
  Startwunsch und löst ihn bei der ersten Geste (Tippen, Klick, Taste) ein.
* Während Pause, Statistik- und Upgrade-Bildschirm wird die Musik automatisch
  auf 30 % heruntergeblendet und danach wieder hochgezogen.
* **Zwei Titel:** `music-menu.mp3` läuft im Menü und auf allen Bildschirmen
  ausserhalb des Kampfes, `music-arena.mp3` nur während einer laufenden Runde.
  Beide sind im Admin austauschbar.
* **Beim Wechsel stoppt der alte Titel sofort**, der neue blendet ein. Vorher
  blendete der alte über eine halbe Sekunde aus — in dieser Zeit hörte man
  beide gleichzeitig. Ein sauberer Übergang ist hier weniger wert als die
  Gewissheit, dass nie zwei Lieder übereinanderliegen.
* Die **Musik läuft auf 30 %** — sie soll unter dem Spiel liegen, nicht
  darüber. Treffer, Schüsse und Schritte stehen damit klar im Vordergrund.
  Die Effektlautstärke bleibt unverändert; beides lässt sich in der Pause
  getrennt regeln, und der Grundwert steht im Admin unter *Audio*.
* Der **🔊-Knopf** im HUD und im Hauptmenü schaltet alles stumm und wieder an.
  Feiner geht es in der **Pause**: dort sitzen getrennte Schalter und Regler für
  Musik und Effekte. Beides wird pro Gerät im `localStorage` gemerkt.
* Musik und Effekte sind **unabhängig** voneinander. Früher hat der Tonknopf
  beides zusammen abgeschaltet und das gespeichert – wer die Musik einmal aus
  hatte, bekam dauerhaft überhaupt keinen Ton mehr. Das ist behoben; der
  Speicherschlüssel wurde gewechselt, damit alte, festhängende Einstellungen
  nicht mitwandern.
* Im Spiel läuft die Musik **immer**. Bei jedem Wellenstart, nach jeder Pause
  und zusätzlich alle vier Sekunden prüft das Spiel nach und wirft sie wieder
  an, falls der Browser die Wiedergabe einmal abgelehnt hat. Vorher ging sie
  irgendwann von selbst an – oder eben nicht.
* Im Admin unter **Audio**: eigenen Titel hochladen (MP3, OGG, WAV, M4A, max.
  20 MB), Grundlautstärke setzen, Autostart an- oder abschalten. Die
  Dateiprüfung läuft über die Signatur, nicht über die Endung.

### Warum die Effekte über Web Audio laufen

Ein Zwischenschritt hat für jede Tonvariante mehrere `<audio>`-Elemente
angelegt und sie beim Freischalten alle einmal lautlos angespielt. Das waren
**132 Elemente**, die gleichzeitig zu laden begannen – hörbar als Spielgeräusche
schon in der Charakterauswahl, und spürbar als Ruckeln bis hin zum Einfrieren.

Jetzt laufen die Effekte über die **Web Audio API**: Jede der 18 Dateien wird
genau einmal geladen und entschlüsselt, danach ist ein Ton nur noch ein
Puffer-Abspieler. Beim Freischalten wird nichts abgespielt, es entsteht kein
einziges zusätzliches Element, und die Dateien laden erst **nach** den
Spielgrafiken, damit sie auf langsamer Leitung nicht um Bandbreite streiten.
Nur die Musik bleibt ein `<audio>`-Element – sie ist minutenlang und soll
gestreamt und nicht komplett in den Speicher entschlüsselt werden.

### Soundeffekte

18 Sounddateien liegen bei und sind fertig verdrahtet. Jeder Ton besteht aus:

* **bis zu vier Dateien** – das Spiel wählt bei jedem Mal zufällig eine davon,
  damit sich Wiederholungen nicht abnutzen,
* einer **Lautstärke je Datei** und einer **Lautstärke für das ganze Ereignis**,
* einer **Häufigkeit in Prozent** (100 % = bei jedem Mal),
* einem **An/Aus-Schalter** – standardmäßig ist alles an.

Das gilt an drei Stellen:

| Wo | Was |
|----|-----|
| **Audio** | 16 allgemeine Ereignisse: Schuss, Nahkampfschlag, Treffer, kritischer Treffer, Gegner stirbt, Explosion, Laufschritte, Spieler getroffen, Geld, Upgrade, Welle geschafft, Boss erscheint, Bombenwarnung, Run startet, Run beendet, Menüklick |
| **Waffen** | eigener Angriffston je Waffe – ohne eigene Datei greift der allgemeine Schuss- bzw. Schlagton |
| **Gegner** | eigener Treffer- und Todeston je Gegnertyp |

Beispiel aus den Standardwerten: Der Nahkampfschlag hat vier Dateien
(`swoosh`, `short-swoosh`, `player-attack`, `player-heavy-attack`), der Bogen
seinen eigenen `arrow`-Ton, und der Trefferton läuft nur zu 45 %, damit er bei
schnellen Waffen nicht hämmert. Die Laufschritte laufen im Takt der
Bewegungsgeschwindigkeit.

**Upload-Größe:** Das Projekt bringt `.user.ini` und `.htaccess` mit 24 MB mit.
Ignoriert dein Hoster beides (manche Setups tun das), nennt die Fehlermeldung
das tatsächliche Limit – dann muss es im Hosting-Panel angehoben werden.

---

## Fehlende Assets

Diese Dateien lagen **nicht** im Sprite-Ordner. Das Spiel funktioniert
vollständig, nutzt aber Ersatz:

| Erwartet | Status | Ersatz |
|----------|--------|--------|
| `pfeil` | fehlt | Pfeile für Bogen und Armbrust werden **programmatisch gezeichnet** (Schaft, Spitze, Federn). Sobald `assets/sprites/pfeil.png` existiert, im Admin bei der Waffe als Projektil auswählen. |
| `explosion2` | fehlt | Die Bossbombe nutzt `explosion1.gif` (15 Frames). Granaten nutzen `explosion.gif`. |
| Kartenbilder | fehlten komplett | `assets/uploads/map-arena.jpg` (1536×1536, ~200 KB) wurde als Startwelt generiert, inklusive passender Kollisionsmaske. Eigene Karten jederzeit im Admin hochladen. |
| Soundeffekte | **vorhanden** | 18 Dateien unter `assets/audio/` sind eingebaut und den Ereignissen, Waffen und Gegnern zugeordnet. Nur „Geld eingesammelt" und „Menüklick" haben noch keine Datei. |

Vorhandene und genutzte Assets: `playerfront`, `playerback`, `playerside`,
`staub`, `enemy1`, `enemy2`, `enemy3`, `boss1`, `bombe1`, `explosion`,
`explosion1`, `schuss` sowie alle zehn Waffen-PNGs.

Die Waffen-PNGs wurden einmalig beschnitten und verkleinert (6,5 MB → 976 KB
für den kompletten Sprite-Ordner) — sichtbar ändert sich nichts, aber der erste
Ladevorgang auf dem Handy ist deutlich kürzer.

---

## Debug-Modus

**F2** am PC oder **Pause → Debug-Ansicht** zeigt FPS, Gegner- und
Projektilzahl, Spielerposition, alle Hitboxen und die Kollisionszellen der
Karte. Standardmäßig aus.

---

## Getestet

Automatisiert im echten Browser geprüft (Chromium, Desktop und Mobil-Viewport):

* Spieler kann Kartengrenzen nicht verlassen (alle vier Seiten)
* Spieler und Gegner können gemalte Hindernisse nicht durchqueren
* Im Admin gemalte Wand blockiert im Spiel exakt an der Kante
* Richtungssprites: hoch → back, runter → front, links → side gespiegelt
* Staub erscheint nur während der Bewegung
* Gegner verfolgen den Spieler (400 px → 23 px in 2 s), spiegeln sich korrekt
* Separation: 12 Gegner auf einem Punkt trennen sich auf mind. 25 px
* Alle zehn Waffen treffen; Axt rundum, Schwert nur im Bogen, Speer nur nach vorne
* Eine Axtrotation trifft denselben Gegner genau einmal
* Granate: Schaden mit Abstandsabfall, außerhalb des Radius kein Schaden
* Bossbombe: Aufprall 0 Schaden, Explosion 40 Schaden, außerhalb des Radius 0
* Wellen 1→2→3→Boss→neuer Zyklus, Schwierigkeit ×1,45 pro Zyklus
* Upgrade verändert die tatsächlichen Werte (z. B. Reichweite 1,10 → 1,20)
* Waffentausch inklusive Nachfrage
* Admin: Login nötig (API ohne Session → 401), Code steht nicht im HTML,
  6 Fehlversuche → 5 Minuten Sperre
* Maps, Gegner, Waffen, Upgrades, Spieler- und Balancingwerte überleben Reload
* Kamera bleibt exakt innerhalb der Karte
* 45 Gegner gleichzeitig bei 60 FPS
* Charakterwerte greifen wirklich (Bruno: 130 statt 100 Leben, 158 statt 168 Tempo)
* Fünf-Bilder-Animation: drei hochgeladene Bilder ergeben drei Frames à 80 ms,
  die im Spiel nachweislich durchlaufen
* Staubrand ist weich (Alpha 0 an Rand und Ecken, 255 in der Mitte)
* Konto: Registrierung, Login, falsches Passwort, Sperre nach 8 Versuchen
* 21 geschaffte Wellen ergeben 21 Erfahrungspunkte, Freischalten kostet 20
* Bestenliste sortiert korrekt, eigener Eintrag hervorgehoben, Anmeldung
  überlebt den Reload
* Waffenhöhe, Sounddatei je Ereignis und Bildtempo im Admin gespeichert und im
  Spiel wirksam
* Tonvarianten: 400 Nahkampfschläge verteilen sich gleichmäßig auf die vier
  Dateien (106/95/103/96)
* Häufigkeit greift: 1000 Treffer bei 45 % ergaben 446 Wiedergaben
* Abgeschalteter Ton spielt 0 von 50 Mal
* Im echten Spiel gemessen (6 s mit Bogen, laufend): walk 46×, arrow 7×
  (eigener Waffenton statt Laser), death-enemy 5×, Trefferton 3×
* Musik startet erst nach der ersten Geste, läuft in Schleife, blendet bei
  Overlays auf 30 % ab und wieder hoch; Aus-Schalter überlebt den Reload
* Waffen- und Projektilgröße im Admin geändert (46→130 px und 16→52 px) und im
  Spiel wie eingestellt gezeichnet; Live-Vorschau wächst mit
* Schriftdatei blockiert das Rendern nicht mehr: 12 970 ms → 259 ms bis zum
  Menü (vierfach gedrosselte CPU)
* Startzeit bei 400 kbit/s, 300 ms Latenz und vierfach gedrosselter CPU:
  48 249 ms / 1 964 KB → 12 530 ms / 436 KB
* Nach dem Start lädt der Rest im Hintergrund weiter; der Boss der vierten
  Welle erscheint trotzdem mit fertigem Sprite
* `data/content.php` und eine Kontodatei direkt im Browser aufgerufen: beide
  liefern 0 Byte — auch ohne `.htaccess`
* Alte `data/content.json` wird beim Start übernommen, Admin-Login und
  gespeicherte Waffenwerte funktionieren danach unverändert
* Verbrennung: ohne Upgrade brennt nichts; eine Stufe ergibt 3 HP/s, drei
  Stufen 9 HP/s; 9,6 Schaden in 1,1 s; nach der Brenndauer erlischt das Feuer;
  Feuer allein tötet einen Gegner
* Flammen erscheinen auf jedem brennenden Gegner, auch im Gedränge
* Heilflasche: heilt exakt 10 %, fliegt aus 70 px Entfernung zu, bleibt bei
  vollem Leben liegen, verschwindet nach Ablauf der Zeit
* Spawnrate über 600 simulierte Sekunden gemessen: 14,8 Flaschen im Schnitt
  (erwartet 14,7), mit zwei Stufen Alchemie 26,8
* Alle zwölf Spezialfähigkeiten einzeln im Spiel nachgemessen: Blutrausch
  100 → 154 Schaden bei 10 % Leben, Scharfschütze 103 → 155 auf Distanz,
  Brandstifter zündet ohne Upgrade (3 HP/s), Frost setzt Tempo auf 45 % für
  1,6 s, Sprengmeister nimmt Nachbarn 19 von 34 Leben, Zweiter Atem rettet
  einmal (25 Leben) und beim zweiten Mal nicht mehr, Goldrausch 100 → 160 Geld,
  Wächter füllt den Schild zum Wellenstart auf
* Ton ohne Autoplay-Ausnahme im Browser: 16 Töne im Spiel, Stummschalten
  ergibt 0, Wiedereinschalten 14 – und mit **abgeschalteter Musik** laufen die
  Effekte weiter (der alte Fehler)
* Alle drei HUD-Knöpfe per Touch bedient: Spiel läuft nach dem Schließen
  weiter, weder Pause noch Eingabe bleiben hängen
* Waffen- und Projektilgröße im Admin auf 132 / 54 px gesetzt: nach dem
  Neuladen genau diese Werte im Spiel und am fliegenden Projektil
* Alle 27 Module werden mit eigener Versionsnummer geladen (vorher nur eine)
* In der Charakterauswahl entsteht **ein** Audio-Element (die Musik) statt 132,
  und es wird **kein** Spielgeräusch abgespielt
* Fünfzehn Charakterkarten zeigen Standbilder statt fünfzehn laufender GIFs
* Drei Minuten Dauerspiel bei vierfach gedrosselter CPU: durchgehend 60 FPS,
  Speicher konstant 9,5 MB, DOM-Knoten konstant, Musik durchgehend an
  (vorher: Einbruch auf 35 FPS ab der zweiten Minute)
* Sprite-Speicher 38,5 MB → 18,5 MB; die Karte behält 1536×1536
* Ton: 13 Effekte im Spiel, stumm 0, wieder an 11; mit abgeschalteter Musik
  laufen 16 Effekte weiter; Musik springt beim Wiedereinschalten sofort an
* Bei zehnfach gedrosselter CPU regelt die Qualitätsstufe selbsttätig auf
  0,55 herunter — das Bild bleibt lesbar (Screenshot geprüft)
* Startzeit auf 400 kbit/s unverändert bei 12,9 s
* Truhe: gibt Geld (71 von 25–70 nach Admin-Änderung auf 25–250), öffnet sich
  beim Berühren, ist nach 1,2 s noch da und nach 2,4 s verschwunden
* Portal rot → blau und zurück: Spieler landet 43 bzw. 31 px am Ziel,
  Sperre von 1,6 s verhindert das sofortige Zurückspringen
* Portale im Editor gesetzt, gespeichert und im Spiel wiedergefunden
  (`red@137,731`, `blue@2005,1358`), Sprites geladen, Paarung korrekt
* Gegenstand im Dashboard geändert (Geld 25–250, Chance 60 %) — Werte
  überleben den Reload und greifen im Spiel
* Wegfindung: Gegner hinter einer langen Mauer läuft außen herum und kommt an;
  auf der Standardkarte 23/24 statt 21/24 Ankünfte
* Drei Minuten Dauerspiel mit Wegfindung und Gegenständen: durchgehend 60 FPS,
  Speicher konstant
* Wellenwechsel verliert keine Gegner: 7 vor der Karte, 6 danach; 12 → 14;
  25 → 25. Auch beim Sprung von der Bosswelle in Zyklus 2 bleiben sie stehen
* Zyklus 2 Welle 1 mischt alle drei Gegnertypen statt nur den schwächsten
* Nach der Upgrade-Auswahl stehen sofort 4 Gegner bereit statt eines leeren
  Feldes
* Projektil-Starthöhe greift im Spiel exakt: −6 → Start bei −4 px, −40 → −38 px;
  Startabstand automatisch 43 px, von Hand gesetzt 90 bzw. 30 px
* Beide Schuss-Vorschauen laufen im Charakter- und im Waffen-Editor und
  übernehmen Änderungen sofort
* Karten-Editor im Touch-Modus: Ziehen ändert die Maske nicht (4959 Zellen
  vorher wie nachher); mit *Malen* 5121, mit *Radieren* 4922
* Menü in Hochkant, Querformat und auf dem Desktop geprüft — der Schriftzug
  wird in keinem Format angeschnitten
* Menümusik läuft in der Auswahl, Kampfmusik in der Runde; beim Wechsel
  blendet die eine aus und die andere ein
* Countdown: sichtbar mit Zahl, Schleife angehalten, danach läuft das Spiel
* Gefallene Gegner kippen sofort um und sind nach 1,3 s verschwunden
* Wellenende räumt ab: 5 kämpfende Gegner → 0, 11 fallen um
* Ultimate: Gegner aus 80–90 px landen bei 158–480 px, Abklingzeit springt
  auf 30 s, ein zweiter Druck wird abgewiesen
* Alle 53 Upgrade-Karten nacheinander angewandt: keine Fehler, 29 Effekte
  aktiv, danach 60 FPS im laufenden Spiel
* Wellenstart-Effekte greifen (Mutation +0,04 Schaden, Blutpakt kostet Leben)
* Sprite-Größe und Spiegelung je Richtung gespeichert und im Spiel wirksam
  (Seite 1,35, gespiegelt)
* Staub wird gedreht geladen (`staub.gif#faded-gedreht`)
* Wegfindung: 23 von 24 Ankünften bei 45 px statt 20 von 24 bei 132 px;
  Gegner hinter einer Mauer kommt nach 9,7 s an
* Sterben: Kippen nach 0,26 s abgeschlossen, Leiche nach 0,72 s weg; die
  Laufanimation steht dabei still, das Ausblenden läuft mit dem Umfallen
* Musikwechsel über den ganzen Ablauf verfolgt (Menü → Auswahl → Runde →
  Pause → Menü → zweite Runde): **an keiner Stelle liefen zwei Titel**
* HUD auf 844 × 420: Knöpfe (694–832 px) und Werte (12–686 px) überschneiden
  sich nicht mehr
* Laden: 4 Auslagen, Kauf zieht Geld ab und trägt das Upgrade ein, Merken hält
  eine Auslage über den Tausch, Tausch legt neue Ware hin, Übersicht listet
  Waffe und Upgrades, *Weiter* führt in Welle 2 bei 60 FPS
* Laden auf vierfach gedrosseltem Handy: 90 Bilder ohne ein einziges über
  50 ms, fünf Käufe/Täusche in 195–439 ms
* Kein Nachschub mehr, wenn alles gemerkt ist; verkaufte Plätze lassen sich
  gegen Aufpreis neu bestücken
* Charakterauswahl: Pfeile blättern vor und zurück (Nova → Bruno → Nova),
  Auswahl kommt im Run an
* Ruhebild: im Stand nutzt die Figur das Ruhesprite mit eigener Grösse, beim
  Laufen wieder die Richtungssprites — und ohne hinterlegtes Ruhebild bleibt
  alles wie vorher
* Admin *Laden & Aussehen*: fünf Karten, Speichern bestätigt, Werte überleben
  den Reload; Charakter-Editor zeigt alle vier Richtungen inklusive Ruhebild
* Alle Bildschirme in Hochkant (390 × 844), Querformat (844 × 420) und auf dem
  Desktop (1280 × 800) angesehen — keine JavaScript-Fehler, keine 404
* Startbild: eigenes Bild im Admin hochgeladen, gespeichert und im Menü
  wiedergefunden (`background-image` zeigt auf die hochgeladene Datei)
* Adminzugang über alle vier Wege geprüft: Code, danach Spieler-Login
  (bleibt drin), `spielatze` ohne Code, Abmelden, fremdes Konto (Code wird
  verlangt)
* Alle 104 Upgrades nacheinander angewandt: keine Fehler, 55 Effekte
  gleichzeitig aktiv, danach 60 FPS
* Stimmungsteilchen kosten nichts: 45 Teilchen mit 43–50 Gegnern — 60 FPS
  mit und ohne
* Speer: Ansatzpunkt des Stichs liegt auf Waffenhöhe (−10 px), nicht mehr
  auf Fusshöhe; Schwung von Schwert, Axt und Dolch mit Sichel und Funken
  angesehen
* Balancing im Schnelldurchlauf gemessen: zwölf Runs quer durch die
  Charaktere enden nach 3–18 Wellen (Median 12) statt nach 2–∞; kein Run
  wird mehr unsterblich, der Kontostand bleibt über den ganzen Lauf
  zweistellig
* Ruun mit dem Dolch — vorher zweimal von sechs endlos — endet jetzt nach
  5, 6, 7, 9 und 17 Wellen
* Elitegegner erscheinen wie gerechnet: 9 von 46 bei Zyklus 6, 51 von 113
  bei Zyklus 12
* Gegnergrenze wächst mit dem Fortschritt: 22 in Welle 1, 46 bei Zyklus 6,
  113 bei Zyklus 12 — und das Leistungsbudget zieht sie auf einem vierfach
  gedrosselten Handy selbsttätig auf 86 zurück
* Startbild kommt zurück: `.screen--titel` behält sein Bild, obwohl die
  allgemeine Stein-Regel später in der Datei steht
