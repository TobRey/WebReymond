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

### Lokal testen

```bash
cd arena
PHP_CLI_SERVER_WORKERS=6 php -S 127.0.0.1:8080
```

### Admin-Code ändern

Der Code wird **serverseitig** geprüft und nie an den Browser ausgeliefert.
Quelle in dieser Reihenfolge:

1. Umgebungsvariable `ADMIN_CODE` (z. B. in der `.htaccess`: `SetEnv ADMIN_CODE 1234`)
2. `data/admin.json` — enthält nur den bcrypt-Hash, wird beim ersten Start erzeugt.

Zum Ändern: `data/admin.json` löschen, `ADMIN_CODE` setzen, Seite neu laden.
Nach 6 Fehlversuchen ist der Login 5 Minuten gesperrt.

---

## Aufbau

```
arena/
├── index.php                 Spiel-Shell (lädt Inhalte als JSON in die Seite)
├── api.php                   JSON-API: content, login, put, delete, settings, upload, reset
├── lib/
│   ├── Defaults.php          Alle Startwerte: Waffen, Gegner, Upgrades, Balancing, Map
│   ├── Store.php             Persistenz in data/content.json (atomar, mit Sperre)
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
│   │   ├── world/            map, collision, spawn
│   │   ├── entities/         player, enemy
│   │   ├── combat/           weapon, projectiles, melee
│   │   ├── game/             arena, run, stats, waves, boss, upgrades
│   │   └── main.js           Menü, HUD, Overlays, Spielstart
│   ├── css/game.css
│   ├── sprites/              deine Sprites
│   └── uploads/              im Admin hochgeladene Bilder
└── data/                     content.json + admin.json (nicht öffentlich)
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

Die Schriftdatei wird **nicht mehr renderblockierend** geladen (`media="print"`
plus `onload`). Vorher hing die ganze Seite an der Google-Fonts-Anfrage: bei
langsamer oder blockierter Verbindung stand das Spiel minutenlang im Ladebild.
Gemessen auf einer vierfach gedrosselten CPU: **12 970 ms → 259 ms** bis zum
bedienbaren Menü. Die Schrift wird nachgeladen, bis dahin greift die
System-Schriftfamilie.

Zusätzlich werden GIF-Frames beim Dekodieren über eine 32-Bit-Palette
geschrieben (statt vier Einzelbytes) und Frames über 384 px verkleinert – im
Spiel werden sie ohnehin nur 60–200 px hoch gezeichnet.

### Performance

* Fester Simulationstakt (1/60 s) mit Delta-Time — 60-Hz- und 120-Hz-Geräte
  spielen exakt gleich schnell.
* Object Pools für Projektile, Partikel, Schadenszahlen, Nahkampfangriffe und Gegner.
* Räumlicher Hash (Spatial Hash) für Trefferabfragen statt Alle-gegen-alle.
* Offscreen-Culling beim Zeichnen, harte Obergrenzen für Partikel (220) und
  Schadenszahlen (60).
* HUD ist normales DOM und wird nur ~12×/s aktualisiert, nie pro Frame.
* Getestet mit 45+ gleichzeitigen Gegnern bei stabilen 60 FPS.

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

Sechs Figuren mit eigenen Werten und Fähigkeiten:

| Charakter | Rolle | Fähigkeit | Frei |
|-----------|-------|-----------|------|
| Nova | Späherin | +10 % Tempo, +5 % Krit | ja |
| Bruno | Bollwerk | +30 % Leben, +3 Rüstung, −6 % Tempo | ja |
| Kira | Duellantin | +12 % Schaden, +8 % Angriffstempo | ja |
| Ruun | Magier | +15 % Reichweite, +20 % Projektiltempo, bessere Upgrade-Karten | 20 XP |
| Vera | Vampirin | Jeder Kill heilt (Boss: 25 HP), −12 % Leben | 20 XP |
| Tor | Wächter | +20 % Leben, 25 Startschild, wirft Nahkampfschaden zurück | 20 XP |

Die drei Sonderfähigkeiten sind echte Mechaniken, keine reinen Zahlen:
**Lebensraub** heilt bei jedem Kill, **Dornen** gibt 30 % des Kontaktschadens
zurück, **Glückskarten** verschiebt die Seltenheiten der Upgrade-Karten nach oben.

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

20 Upgrades in vier Seltenheiten (Common, Rare, Epic, Legendary). Nach jeder
Welle werden drei Karten gezogen; mit steigendem Zyklus wachsen die Chancen auf
seltene Karten (`rarityCycleBonus`).

Beeinflussbare Werte: Schaden, Angriffstempo, Bewegungstempo, Max. Leben,
Rüstung, Schild, Krit-Chance, Krit-Schaden, Projektiltempo, Reichweite,
Rückstoß, Ausweichen, Regeneration.

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

Alles landet in `data/content.json` — derselben Datei, aus der das Spiel liest.
Nach einem Reload ist alles noch da. Schreibzugriffe laufen atomar über eine
temporäre Datei mit Dateisperre. Alle Eingaben werden serverseitig geprüft und
begrenzt (Leben > 0, Cooldown > 0, Pfade nur innerhalb der Asset-Ordner usw.).

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
* Der **♪-Knopf** im HUD und im Hauptmenü schaltet den Ton ab. Die Wahl wird pro
  Gerät im `localStorage` gemerkt.
* Im Admin unter **Audio**: eigenen Titel hochladen (MP3, OGG, WAV, M4A, max.
  20 MB), Grundlautstärke setzen, Autostart an- oder abschalten. Die
  Dateiprüfung läuft über die Signatur, nicht über die Endung.

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
| Kartenbilder | fehlten komplett | `assets/uploads/map-arena.png` (2048×2048) wurde als Startwelt generiert, inklusive passender Kollisionsmaske. Eigene Karten jederzeit im Admin hochladen. |
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
