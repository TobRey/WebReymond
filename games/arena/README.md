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

## Spielablauf

1. **Spielen** → Starterwaffe wählen → Run startet sofort.
2. Steuerung: **virtueller Joystick** (entsteht dort, wo der Finger die Fläche
   berührt) oder **WASD / Pfeiltasten** am PC.
3. Die Waffe **zielt und feuert automatisch** auf den nächsten Gegner in Reichweite.
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

| Waffe | Verhalten | Schaden | Cooldown | Besonderheit |
|-------|-----------|--------:|---------:|--------------|
| Pistole | PROJECTILE | 22 | 0,40 s | solider Allrounder |
| Sturmgewehr | PROJECTILE | 8 | 0,11 s | hohe Feuerrate, Streuung |
| Bogen | PROJECTILE | 46 | 0,78 s | durchbohrt 1 Gegner |
| Armbrust | PROJECTILE | 85 | 1,35 s | durchbohrt 2 Gegner |
| Zauberstab | MAGIC | 28 | 0,55 s | Zielsuche, trifft fast immer |
| Speer | THRUST | 48 | 0,62 s | Stoß nach vorne, schmal |
| Dolch | MELEE_ARC 46° | 15 | 0,20 s | blitzschnell, kurze Reichweite |
| Schwert | MELEE_ARC 85° | 22 | 0,50 s | trifft mehrere Gegner |
| Axt | MELEE_360 | 18 | 0,95 s | volle Drehung, trifft alles |
| Granate | GRENADE | 55 | 1,90 s | AoE 130, verzögerte Explosion |

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

## Fehlende Assets

Diese Dateien lagen **nicht** im Sprite-Ordner. Das Spiel funktioniert
vollständig, nutzt aber Ersatz:

| Erwartet | Status | Ersatz |
|----------|--------|--------|
| `pfeil` | fehlt | Pfeile für Bogen und Armbrust werden **programmatisch gezeichnet** (Schaft, Spitze, Federn). Sobald `assets/sprites/pfeil.png` existiert, im Admin bei der Waffe als Projektil auswählen. |
| `explosion2` | fehlt | Die Bossbombe nutzt `explosion1.gif` (15 Frames). Granaten nutzen `explosion.gif`. |
| Kartenbilder | fehlten komplett | `assets/uploads/map-arena.png` (2048×2048) wurde als Startwelt generiert, inklusive passender Kollisionsmaske. Eigene Karten jederzeit im Admin hochladen. |
| Sounddateien | keine | Die Audio-Schicht (`core/audio.js`) ist vollständig verdrahtet, aber stumm. Mit `Audio.register('shoot', 'assets/audio/shoot.wav')` lässt sich jedes Event nachrüsten: shoot, melee, hit, crit, enemyDeath, explosion, upgrade, bossSpawn, bossWarning, playerHit, coin, gameOver, uiClick. |

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
