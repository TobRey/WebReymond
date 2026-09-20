# Sky Kingdoms – Spielregeln, Formeln und Erweiterung

Dieses Dokument beschreibt, wie das Spiel funktioniert, wo die Zahlen stehen und wie
man es weiterbaut.

---

## 1. Die Grundidee

Jeder Spieler baut ein Königreich aus mehreren **schwebenden Inseln**. Das Besondere:
Rohstoffe entstehen nicht einfach als Zahl auf einem Konto. Jede Ware hat einen Ort.

```
Eisenmine  →  Puffer der Mine  →  Träger über die Brücke  →  Lagerinsel  →  ausgebbar
                                                                   ↓
                                                    Route zur Schmiede  →  Werkzeuge
```

**Erst eingelagerte Ware zählt.** Was im Puffer einer Mine liegt oder gerade unterwegs
ist, kann man nicht ausgeben. Wer keine Träger hat, produziert ins Leere.

Daraus ergibt sich das eigentliche Spiel: Produktion, Wege, Engpässe, Umwege.

---

## 2. Keine Wartezeiten

Es gibt **keine Bau- oder Upgradetimer**. Wer die Rohstoffe hat, baut sofort.
Die Zeit spielt nur dort eine Rolle, wo sie inhaltlich hingehört:

- Produktion je Minute
- Transportdauer einer Rundfahrt
- Nahrungsverbrauch
- Schutzzeiten nach einem Angriff

---

## 3. Formeln

Alle Zahlen stehen in **`config/balance.php`** und sind im Adminbereich änderbar.

### Wirkung einer Stufe

```
wert(L) = basis × wachstum^(L−1)
```

| Merkmal | Wachstum je Stufe |
|---|---|
| Produktion | 1,010 (+1,0 %) |
| Lagerplatz | 1,014 (+1,4 %) |
| Transporttempo | 1,015 (+1,5 %) |
| Ladung | 1,012 (+1,2 %) |
| Brückenkapazität | 1,018 (+1,8 %) |
| Lebenspunkte einer Einheit | 1,010 |
| Schaden einer Einheit | 1,008 |

### Kosten einer Stufe

```
kosten(L) = basis × kostenwachstum^(L−1)      Standard: 1,0575
```

### Mehrere Stufen auf einmal

Die Kosten für `n` Stufen ab Stufe `L` sind eine geometrische Reihe und werden in
**einer Rechnung** bestimmt – nicht in einer Schleife:

```
kosten(L, n) = basis × g^(L−1) × (g^n − 1) / (g − 1)
```

### „Maximum bezahlbar"

Nach `n` aufgelöst:

```
n ≤ log( 1 + vorrat × (g−1) / (basis × g^(L−1)) ) / log(g)
```

Danach höchstens 64 Korrekturschritte gegen Rundungsfehler. Auch bei riesigen
Beständen bleibt das eine Rechnung von Millisekunden.

### Warum es kein Maximallevel gibt – und trotzdem nichts kaputtgeht

Es gibt **keine** Obergrenze für Stufen. Begrenzt wird allein durch Mathematik:

- Kosten wachsen mit 5,75 % je Stufe, Wirkung mit 1 %. Das Verhältnis öffnet sich
  stetig – jede Stufe ist teurer erkämpft als die vorige.
- Alle Mengen sind Ganzzahlen und auf `9 × 10^17` begrenzt (`formulas.max_amount`).
- Übersteigen die Kosten diesen Bereich, lehnt der Server die Verbesserung mit einer
  verständlichen Meldung ab, statt falsch zu rechnen.
- Weil auch der Lagerplatz dieser Grenze unterliegt, endet die bezahlbare Entwicklung
  in der Praxis bei etwa **Stufe 600–700** je Gebäude – erreichbar nur über sehr lange
  Zeit und mit aufeinander abgestimmten Produktionsketten.

### Optische Meilensteine

Bei Stufe 10, 25, 50, 100, 250 und 500 verändert sich das Aussehen eines Gebäudes
(mehr Stockwerke, Zinnen, Fahnen, Goldverzierungen). Danach steigen die Werte weiter,
die Optik bleibt auf der höchsten Stufe.

---

## 4. Die Logistik im Detail

### Route

Eine Route verbindet **Quelle** und **Ziel**. Beides kann ein Gebäude oder das Lager sein:

| Von | Nach | Zweck |
|---|---|---|
| Gebäude | Lager | Abtransport der Produktion |
| Lager | Gebäude | Nachschub für Verarbeitung |
| Gebäude | Gebäude | kurzer Weg auf derselben Insel |

### Durchsatz

```
rundfahrt  = 2 × strecke / tempo + ladezeit
durchsatz  = träger × ladung / rundfahrt
```

- **Strecke**: 14 Felder je Brücke plus 6 Felder Umschlag. Auf derselben Insel: 5.
- **Ladezeit**: 6 Sekunden, durch Ladekräne verkürzt.
- **Tempo und Ladung** hängen vom Transportmittel und der Routenstufe ab.

### Stau auf Brücken

```
nachfrage = Summe aller Routen über diese Brücke
faktor    = min(1, kapazität / nachfrage)
```

Alle Routen über eine überlastete Brücke werden gleichmässig gebremst. Sichtbar wird
das sofort: Die Brücke pulsiert rot, die Träger bekommen einen Warnpunkt, und die
Transportübersicht zeigt „Stau 62 %".

Gegenmittel: Brücke ausbauen, zweite Brücke bauen, schnelleres Transportmittel,
oder Routen anders führen.

### Transportmittel

| Stufe | Tempo | Ladung | Freischaltung |
|---|---|---|---|
| Träger zu Fuss | 1,00 | 10 | von Anfang an |
| Tragesäcke | 0,98 | 20 | Logistik 1 |
| Handkarren | 0,94 | 45 | Logistik 3 |
| Packpferde | 1,55 | 60 | Logistik 5 + Stallungen |
| Pferdewagen | 1,35 | 150 | Logistik 8 + Stallungen |
| Seilbahn | 2,10 | 220 | Logistik 14 |
| Lastenaufzug | 1,70 | 400 | Logistik 18 |
| Luftschiff | 2,80 | 700 | Logistik 24 + Ladekran |

### Woran es hakt

Der Server nennt für jede Route den Grund, wenn sie nicht voll läuft:

- „Es wird weniger hergestellt, als die Träger tragen könnten."
- „Das Lager ist voll – baue oder verbessere Lagergebäude."
- „Der Eingang des Zielgebäudes ist voll."
- „Stau auf der Brücke."
- „Keine Brückenverbindung – es fehlt eine Brücke."

---

## 5. Offline-Berechnung ohne Serverprozess

Das Königreich wird als Netz aus **Behältern** (Gebäudepuffer, Lager) und **Flüssen**
(Produktion, Routen, Verbrauch) beschrieben.

Solange sich nichts ändert, sind alle Flüsse konstant. Dann lässt sich exakt ausrechnen,
**wann der nächste Behälter voll oder leer läuft**. Genau bis dorthin springt die
Simulation, bestimmt die Flüsse neu und springt weiter.

```
while (Restzeit > 0 und Abschnitte < 240):
    Flüsse drosseln, bis kein Behälter überläuft   (max. 8 Durchgänge)
    dt = Zeit bis zum nächsten Ereignis
    alle Behälter um dt weiterrechnen
```

Ein ganzer Tag Abwesenheit braucht so meist **unter zehn Abschnitte** und wenige
Millisekunden – kein Cronjob, keine Endlosschleife, keine Serverlast.

Begrenzt wird die nachgerechnete Zeit durch `max_offline_seconds` (Standard 24 Stunden,
im Adminbereich änderbar). Nach der Rückkehr zeigt das Spiel eine Zusammenfassung mit
Zuwachs und Engpässen.

---

## 6. Bevölkerung und Verpflegung

- Einwohner = 20 + Wohnhäuser
- Jedes Gebäude und jeder Träger braucht Arbeiter
- Fehlen Arbeiter, sinkt **alles** gleichmässig: `faktor = einwohner / bedarf`
- Jeder Einwohner isst 0,02 Brot je Minute
- Ist das Brot alle, fällt die Produktion auf 55 %

---

## 7. Kämpfe

### Ablauf

1. Gegner wählen (Punktestand ±40 %, kein Neuling, kein Schild, keine Allianzmitglieder)
2. Missionsziel wählen (acht verschiedene)
3. Truppe zusammenstellen (höchstens 20 Einheiten)
4. Kurze interaktive Mission: Spur wechseln, Fähigkeiten einsetzen
5. Auswertung **auf dem Server**

### Missionsziele

| Ziel | Wirkung |
|---|---|
| Transport überfallen | Beute aus dem laufenden Warenfluss |
| Lager plündern | Anteil des Lagers |
| Brücke beschädigen | Brücke wird vorübergehend langsamer |
| Mine besetzen | Puffer der Mine plus Beschädigung |
| Aussenposten erobern | Verteidigungsgebäude beschädigt |
| Lieferung abfangen | wie Transport überfallen, höhere Beute |
| Gefangene befreien | Arbeiter kehren zurück |
| Ausspionieren | zeigt Lager, Truppen und Verteidigung |

### Warum man nicht schummeln kann

Der Browser schickt **nur die Entscheidungen** (`{Zeitpunkt, Spur|Fähigkeit}`).
Der Server rechnet die Mission mit demselben Verfahren komplett nach und benutzt
ausschliesslich sein eigenes Ergebnis. Weicht die Meldung des Browsers stark ab, wird
das im Protokoll als verdächtig vermerkt.

Damit beide Seiten identisch rechnen, verwendet die Simulation nur Grundrechenarten
und `sqrt` sowie einen Zufallsgenerator mit festem Startwert (xorshift32), der in PHP
und JavaScript Zeichen für Zeichen gleich aufgebaut ist.

### Schutz der Spieler

- Neulingsschutz: 3 Tage (einstellbar)
- Schild nach verlorenem Angriff: 3 Stunden
- Höchstens 3 Angriffe pro Tag vom selben Angreifer
- Beute höchstens 25 % eines Lagers
- **Nichts wird zerstört** – Gebäude werden nur beschädigt und lassen sich sofort
  gegen Rohstoffe reparieren

---

## 8. Fortschritt über Monate

| Freischaltung | Wann |
|---|---|
| Kupfer, Kohle | Burg 2 |
| Forschungsgilde, Markt, Pferdezucht, Schatzkammer | Burg 3 |
| Waffenschmiede, Ladekran | Burg 4 |
| Weitere Inselplätze | je 5 Burgstufen |
| Kristallmine | Bergbau 5 |
| Militärinsel | Taktik 5 |
| Handelsinsel | Logistik 8 |
| Forschungsinsel | Handwerk 10 |
| Seilbahn | Logistik 14 |
| Hafeninsel, Luftschiff | Logistik 20/24 |
| Magische Insel, Ätherquelle | Ätherkunde 1/5 |
| Prestige | ab 250 000 Punkten |

**Prestige** ist freiwillig und löscht nichts: Inseln, Gebäude und Forschung bleiben.
Man erhält Prestigepunkte (`punkte / 50 000`), die dauerhaft +1 % Produktion je Punkt
geben.

---

## 9. Datenablage statt Datenbank

Es gibt keine Datenbank. Jede „Tabelle" aus einer klassischen Planung entspricht einem
Dokument oder einem Feld darin:

| Klassische Tabelle | Hier |
|---|---|
| users, user_profiles | `users/<ab>/<uid>/account.json` |
| sessions, remember_tokens | PHP-Sitzung + `account.json` → `remember` + `index/remember/` |
| password_resets | `resets/<hash>.json` |
| islands, island_types | `world.json` → `islands` + `config/balance.php` |
| buildings, building_types, building_levels | `world.json` → `buildings` (Stufe als Zahl, Werte per Formel) |
| resources, inventories | `world.json` → `store` + Puffer je Gebäude |
| production_jobs | entfällt – Produktion ist ein Fluss, kein Auftrag |
| transport_routes, transports | `world.json` → `routes` (+ sichtbare Träger im Browser) |
| bridges | `world.json` → `bridges` |
| workers, animals | abgeleitet aus Gebäuden, Routen und `store.horse` |
| units, defenses | `world.json` → `units`, Verteidigungsgebäude |
| researches | `world.json` → `research` |
| quests, achievements | `meta/quests.json`, Fortschritt in `world.json` |
| alliances, alliance_members | `alliances/<aid>.json` |
| attacks, battle_reports | `attacks/<id>.json`, `users/…/reports/<id>.json` |
| trades | `trades/<id>.json` |
| notifications | `users/…/notifications.json` |
| rankings | `index/ranking.json` (Momentaufnahme) |
| game_settings, balance_settings | `meta/settings.json`, `meta/balance.json` |
| audit_logs | `logs/audit-JJJJ-MM-TT.jsonl` |

**Warum das funktioniert:** Fast alle Zugriffe betreffen genau einen Spieler. Dessen
gesamtes Königreich liegt in einer Datei, die in einem Rutsch gelesen und geschrieben
wird. Für die wenigen übergreifenden Fragen (Rangliste, Gegnersuche) gibt es eine
kleine Momentaufnahme, die im Hintergrund erneuert wird.

**Wo die Grenze liegt:** Einige hundert aktive Spieler sind auf Shared Hosting
unproblematisch. Bei deutlich mehr würde man die Klasse `app/Store/Store.php` gegen
eine SQL-Fassung tauschen – alle übrigen Klassen kennen nur ihre Schnittstelle
(`read`, `write`, `update`, `claim`, `append`, `listFiles`).

---

## 10. Erweitern

### Ein neues Gebäude

In `config/balance.php` unter `buildings` ergänzen:

```php
'glassworks' => [
    'name' => 'Glashütte', 'desc' => 'Schmilzt Sand zu Glas.',
    'role' => 'converter', 'islands' => ['main'], 'size' => [2, 1], 'limit' => 0,
    'cost' => ['wood' => 300, 'stone' => 260],
    'consumes' => ['coal' => 2.0], 'produces' => ['glass' => 1.2],
    'buffer' => 120, 'workers' => 4, 'sprite' => 'glassworks',
],
```

Danach in `assets/js/render/buildings.js` eine Zeichenvorschrift `glassworks(ctx, w, d, tier, t)`
ergänzen. Fehlt sie, wird automatisch ein passables Standardhaus gezeichnet.

### Ein neuer Rohstoff

Unter `resources` ergänzen (mit `class`, `order`, `hud`, `color`) und in
`assets/js/render/icons.js` ein Symbol hinzufügen.

### Eine neue Insel

Unter `island_types` ergänzen: `grid`, `mask` (`#` = bebaubar), `tint`, `unlock_cost`.

### Balance ändern

Ohne Codeänderung: Adminbereich → Balance. Pfad eingeben
(z. B. `buildings.iron_mine.produces.iron`), Wert setzen. Wirkt sofort für alle.

### Neue Missionsziele, Einheiten, Forschung

Jeweils ein Eintrag unter `missions`, `units` oder `research` in `config/balance.php`.
