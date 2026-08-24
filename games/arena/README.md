# Arena Survivors

Mobiles 2D-Top-Down-Roguelite: Wellen überleben, Upgrades sammeln, Bosse legen.
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

### Admin-Code ändern

Der Code wird **serverseitig** geprüft und nie an den Browser ausgeliefert.
Quelle in dieser Reihenfolge:

1. Umgebungsvariable `ADMIN_CODE` (z. B. in der `.htaccess`: `SetEnv ADMIN_CODE 1234`)
2. `data/admin.php` — enthält nur den bcrypt-Hash, wird beim ersten Start erzeugt.

Zum Ändern: `data/admin.php` löschen, `ADMIN_CODE` setzen, Seite neu laden.
Nach 6 Fehlversuchen ist der Login 5 Minuten gesperrt.

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
│   │   ├── world/            map, collision, spawn, pickups (Heilflaschen)
│   │   ├── entities/         player, enemy
│   │   ├── combat/           weapon, projectiles, melee
│   │   ├── game/             arena, run, stats, waves, boss, upgrades, perks
│   │   └── main.js           Menü, HUD, Overlays, Spielstart
│   ├── css/game.css
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

Im Admin unter **Charaktere** hat jede Richtung (vorne, hinten, seitlich) zwei
Möglichkeiten:

* ein **animiertes GIF** – wird wie bisher automatisch zerlegt, oder
* bis zu **fünf Einzelbilder** – das Spiel baut daraus selbst die Animation.

Liegen Einzelbilder vor, haben sie Vorrang. Das Tempo steuert **Bildwechsel
(ms)** – 80 ms sind schnelle Schritte, 200 ms ein ruhiger Gang. Bilder
unterschiedlicher Größe werden am Fußpunkt zentriert eingepasst, damit die
Figur beim Wechsel nicht springt.

Solange keine eigenen Sprites hochgeladen sind, unterscheiden sich die Figuren
über eine **Farbdrehung** (Feld *Farbdrehung*, 0–360°) auf demselben Basissprite.

---

## Spielablauf

1. **Spielen** → Charakter wählen → Starterwaffe wählen → Run startet sofort.
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

Nach **jeder** Welle pausiert das Spiel und bietet drei zufällige Upgrades an.
Danach beginnt der nächste Zyklus mit höheren Werten — endlos.

Überlebt der Boss die 120 Sekunden, verschwindet er nicht, sondern wird
**wütend** (mehr Tempo, mehr Schaden, kürzerer Bomben-Cooldown). Der Kampf endet
also immer mit einer Entscheidung.

### Schwierigkeitsskalierung

```
Gegnerleben  = Basisleben  × healthScaling^(Zyklus-1)     (Standard 1.45)
Gegnerschaden= Basisschaden× damageScaling^(Zyklus-1)     (Standard 1.28)
Tempo        = Basistempo  × speedScaling^(Zyklus-1)      (Standard 1.035)
Spawnrate    = Basisrate   × spawnRateScaling^(Zyklus-1)  (Standard 1.18)
Geld         = Basisgeld   × rewardScaling^(Zyklus-1)     (Standard 1.25)
```

Alle fünf Werte sind im Admin unter **Balancing** einstellbar.

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
| Pistole | PROJECTILE | 22 | 0,40 s | 46 px | 16 px | solider Allrounder |
| Sturmgewehr | PROJECTILE | 8 | 0,11 s | 54 px | 14 px | hohe Feuerrate, Streuung |
| Bogen | PROJECTILE | 46 | 0,78 s | 50 px | 20 px | durchbohrt 1 Gegner |
| Armbrust | PROJECTILE | 85 | 1,35 s | 56 px | 26 px | durchbohrt 2 Gegner |
| Zauberstab | MAGIC | 28 | 0,55 s | 50 px | 18 px | Zielsuche, trifft fast immer |
| Speer | THRUST | 48 | 0,62 s | 120 px | – | Stoß nach vorne, schmal |
| Dolch | MELEE_ARC 46° | 15 | 0,20 s | 58 px | – | blitzschnell, kurze Reichweite |
| Schwert | MELEE_ARC 85° | 22 | 0,50 s | 95 px | – | trifft mehrere Gegner |
| Axt | MELEE_360 | 18 | 0,95 s | 88 px | – | volle Drehung, trifft alles |
| Granate | GRENADE | 55 | 1,90 s | 46 px | 34 px | AoE 130, verzögerte Explosion |

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

## Upgrades

23 Upgrades in vier Seltenheiten (Common, Rare, Epic, Legendary). Nach jeder
Welle werden drei Karten gezogen; mit steigendem Zyklus wachsen die Chancen auf
seltene Karten (`rarityCycleBonus`).

Beeinflussbare Werte: Schaden, Angriffstempo, Bewegungstempo, Max. Leben,
Rüstung, Schild, Krit-Chance, Krit-Schaden, Projektiltempo, Reichweite,
Rückstoß, Ausweichen, Regeneration, **Feuerschaden** und
**Heilflaschen-Chance**.

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

### Heilflaschen

Alle `potionInterval` Sekunden (Standard 14) wird gewürfelt; mit
`potionChance` (Standard 35 %) erscheint eine Flasche in Laufweite –
höchstens `potionMax` (Standard 3) gleichzeitig, jede verschwindet nach
`potionLifetime` (Standard 26 s) und blinkt vorher.

* Aufgesammelt heilt sie `potionHeal` Prozent (Standard 10) des Maximallebens.
* In Aufsammelreichweite fliegt sie dir entgegen.
* **Bei vollem Leben bleibt sie liegen**, statt sich zu verschwenden.
* Die Karte **Alchemie** (Selten, +40 %, bis 6 Stufen) erhöht die Chance.

Gemessen ohne Alchemie: knapp **15 Flaschen in zehn Minuten**, also etwa eine
alle vierzig Sekunden. Mit zwei Stufen Alchemie sind es 27.

Mit `weaponOfferChance` (Standard 28 %) kann statt eines Upgrades eine **neue
Waffe** angeboten werden. Da es nur einen Waffenslot gibt, fragt das Spiel dann
nach, ob die aktuelle Waffe ersetzt werden soll.

Der Knopf **≡** im HUD zeigt jederzeit alle aktuellen Werte plus die Liste der
gewählten Upgrades mit Stapelzahl (z. B. „Flinke Füße ×3").

---

## Admin-Dashboard

| Seite | Kann |
|-------|------|
| **Dashboard** | Überblick, alles auf Standard zurücksetzen |
| **Maps** | Bild hochladen, Hindernisse malen, Startpunkt und Gegnerzonen setzen, aktivieren, löschen |
| **Gegner** | Anlegen, bearbeiten, duplizieren, löschen; Sprite, Werte, Welle, Hitbox |
| **Waffen** | Anlegen, bearbeiten, duplizieren, löschen; alle Kampfwerte, Verhalten, Starterwaffe |
| **Upgrades** | Anlegen, bearbeiten, duplizieren, löschen; Stat, Modifikator, Seltenheit, Gewicht, Stapel |
| **Spieler** | Basiswerte und alle vier Spieler-Sprites |
| **Balancing** | Wellendauer, Spawnrate, Skalierung, Bombenwerte, Kartenchancen |
| **Charaktere** | Anlegen, bearbeiten, duplizieren, löschen; Sprites (GIF oder 5 Einzelbilder je Richtung), Bildtempo, Farbdrehung, Fähigkeiten, Werte, Hitbox, Freischaltkosten |
| **Audio** | Musiktitel und **je Ereignis eine eigene Sounddatei** mit eigener Lautstärke, Vorhören, Autostart |

### Collision-Editor

Eine neue Map ist nach dem Upload sofort ein spielbares Level. Im Editor:

* **Malen / Radieren** mit einstellbarer Pinselgröße
* **Rückgängig / Wiederholen** (40 Schritte)
* **Alles löschen**, **Einpassen**, Zoom-Regler
* **Startpunkt** setzen (grüner Kreis)
* **Gegnerzonen** setzen (blaue Kreise) — ohne Zonen spawnen Gegner automatisch
  rund um den Bildausschnitt
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
