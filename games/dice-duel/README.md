# Dice Duel

Modernes browserbasiertes 2‑Spieler‑Würfelspiel mit Raumcode‑System.
**Reines PHP + HTML/CSS/JS – keine Datenbank, keine Composer‑Abhängigkeiten, kein Build‑Schritt.**

---

## Schnellstart

### Lokal testen

```bash
cd dice-duel
PHP_CLI_SERVER_WORKERS=8 php -S 127.0.0.1:8080
```

Dann `http://127.0.0.1:8080` in zwei Browserfenstern öffnen.

> `PHP_CLI_SERVER_WORKERS` ist beim eingebauten PHP‑Server wichtig: er ist sonst
> single‑threaded und blockiert beim Long‑Polling. Apache/nginx + PHP‑FPM
> brauchen das nicht.

### Auf Webhosting hochladen

1. Ordnerinhalt per FTP in ein Web‑Verzeichnis kopieren (z. B. `/dice-duel/`).
2. Sicherstellen, dass `data/` durch PHP **beschreibbar** ist (`chmod 775 data data/rooms`).
3. Fertig – `https://deine-domain.tld/dice-duel/` aufrufen.

**Voraussetzungen:** PHP 8.1 oder neuer. Keine Extensions außer dem Standardumfang.

#### Hinweis zu nginx

Für Apache liegt in `data/` bereits eine `.htaccess`, die den Ordner sperrt.
nginx ignoriert diese Datei – dort gehört folgender Block in die Server‑Config:

```nginx
location ^~ /dice-duel/data/ { deny all; return 404; }
```

Kritisch ist das nicht: Sitzungs‑Tokens werden nur als SHA‑256‑Hash gespeichert,
eine lesbare Raumdatei wäre also kein nutzbares Login.

---

## Aufbau

```
dice-duel/
├── index.php          Seite (HTML‑Gerüst + Katalog als JSON)
├── api.php            JSON‑API: create, join, state, select, roll, buy, rematch
├── lib/
│   ├── Game.php       Komplette Spiellogik (Zugreihenfolge, Wurf, Scoring)
│   ├── Content.php    Balancing: Würfel, Tränke, Preise, Startwerte
│   └── Store.php      Dateispeicher mit Lock (statt Datenbank)
├── assets/
│   ├── app.css        Design + Animationen (nur transform/opacity)
│   └── app.js         Client, diff‑basiertes Rendering, Long‑Polling
└── data/rooms/        Laufzeitdaten – je Raum eine JSON‑Datei
```

### Warum ohne Datenbank?

Jeder Raum liegt als JSON‑Datei unter `data/rooms/CODE.json`. Schreibzugriffe
laufen über `flock()`, damit zwei gleichzeitige Aktionen sich nicht überschreiben.
Räume, die länger als 6 Stunden nicht angefasst wurden, werden automatisch gelöscht.

### Synchronisation

Der Client fragt `api.php?action=state` mit der zuletzt bekannten Version an.
Der Server hält die Anfrage bis zu 9 Sekunden offen und antwortet, sobald sich
etwas ändert (Long‑Polling). Dadurch wirkt das Spiel wie eine Live‑Verbindung,
ohne WebSockets und ohne Dauer‑Requests.

**Alles Zufällige passiert serverseitig.** Der Client bekommt nur fertige
Ergebnisse und animiert sie – Manipulation im Browser bringt nichts.

---

## Spielregeln

* 2 Spieler, **20 Züge pro Spieler = 40 Züge** pro Runde.
* In der Mitte liegen immer **4 Zielzahlen** (2–12).
* Der Spieler am Zug wählt eine Zielzahl – für den Gegner ist sie in diesem
  Zugpaar gesperrt.
* Danach wählt er **1 oder 2 Würfel** aus seinem Inventar und wirft.
  Bei 2 Würfeln zählt die Summe.
* Je näher am Ziel, desto mehr Punkte und Geld.
* **Nach jedem Zugpaar werden alle 4 Zielzahlen neu ausgelost.**

### Faire Zugreihenfolge

```
S1 → S2 → S2 → S1 → S1 → S2 → S2 → S1 …
```

So darf nicht immer derselbe Spieler zuerst eine Zielzahl aussuchen.

### Belohnungen

| Abstand | Punkte | Geld |
|--------:|-------:|-----:|
| 0 (Perfect) | 120 | 70 |
| 1 | 55 | 34 |
| 2 | 22 | 14 |
| 3 | 10 | 7 |
| 4 | 5 | 4 |
| 5+ | 2 | 2 |

Punktlandungen sind mit Abstand am wertvollsten, Abstand 1 bleibt attraktiv,
danach fällt die Belohnung deutlich ab.

### Punktlandungs‑Streak

Mehrere Punktlandungen hintereinander erhöhen den Punkte‑Multiplikator:

| Streak | Multiplikator | mit Streak Potion |
|-------:|--------------:|------------------:|
| ×1 | 1,0 | 1,0 |
| ×2 | 1,8 | 2,2 |
| ×3 | 2,6 | 3,4 |
| ×4 | 3,4 | 4,6 |
| max | 5,0 | 7,0 |

Ein verfehlter Perfect setzt den Streak zurück.

### Verzauberte Zielzahlen

Jede Zielzahl ist mit **22 % Wahrscheinlichkeit verzaubert** (goldener Rahmen,
Schimmer‑Animation). Der Effekt löst nur aus, wenn der Spieler genau diese Zahl
gewählt **und** eine Punktlandung geschafft hat: **×2 Cash** für diesen Zug.

---

## Shop

Geld wird ausschließlich für Upgrades gebraucht, gewonnen wird über Punkte –
Einkaufen kostet also keinen Sieg.

### Spezialwürfel (dauerhaft)

| Würfel | Preis | Effekt |
|--------|------:|--------|
| Low Die | 95 | Seiten 1‑1‑2‑2‑3‑3 |
| High Die | 95 | Seiten 4‑4‑5‑5‑6‑6 |
| Precision Die | 120 | Seiten 3‑3‑4‑4‑4‑5 |
| Lucky Die | 175 | 14 % automatischer Perfect |
| Golden Die | 150 | +40 % Geld im Zug |
| Double Die | 130 | 6er‑Seite zählt doppelt (= 12) |
| Magnet Die | 165 | 70 % Chance: Ergebnis 1 Richtung Ziel |
| Mirror Die | 115 | 45 % Chance: Wert wird gespiegelt (7 − Wert) |
| Reroll Die | 140 | 35 % Chance auf automatischen Neuwurf |
| Wild Die | 200 | 18 % Chance: Seite passt sich der benötigten Zahl an |

Jeder Spieler startet mit 2 Standardwürfeln und **120 $**. Vor jedem Wurf wählt
er frei, welche 1 oder 2 seiner Würfel er benutzt.

### Tränke (bis Rundenende aktiv)

| Trank | Preis | Effekt |
|-------|------:|--------|
| Over Potion | 70 | Ergebnis über dem Ziel: +50 % Geld |
| Under Potion | 70 | Ergebnis unter dem Ziel: +50 % Geld |
| Even Potion | 65 | Gerades Ergebnis: +18 P, +10 $ |
| Odd Potion | 65 | Ungerades Ergebnis: +18 P, +10 $ |
| Perfect Potion | 110 | Punktlandung: +45 $ |
| Streak Potion | 130 | Streak‑Multiplikator wächst schneller |
| Close Call Potion | 95 | Abstand 1: +60 P, +15 $ |
| Double Dice Potion | 80 | Mit 2 Würfeln: +14 P, +8 $ |
| Single Dice Potion | 80 | Mit 1 Würfel: +16 P, +10 $ |
| Lucky Potion | 120 | 25 % Chance: Distanz −1 |
| Golden Potion | 105 | Alle Geldbelohnungen +20 % |
| Score Potion | 115 | Alle Punkte +15 % |
| Risk Potion | 125 | Abstand 0‑1: +60 % P, ab Abstand 3: −60 % P |
| High Target Potion | 85 | Ziel ≥ 8: +20 % P und $ |
| Low Target Potion | 85 | Ziel ≤ 5: +20 % P und $ |

Reihenfolge der Berechnung: Würfeleffekte → Distanz → Grundwerte → Streak →
flache Boni → prozentuale Boni → Verzauberung (×2 Cash).

---

## Balancing anpassen

Alles Relevante steht in **`lib/Content.php`** (Preise, Würfelseiten,
Trankeffekte, Startgeld, Zugzahl, Verzauberungschance) und in den Konstanten
`POINTS` / `MONEY` oben in **`lib/Game.php`**. Ein Neuladen der Seite reicht –
kein Build.

---

## Bedienung

| Aktion | Bedienung |
|--------|-----------|
| Raum erstellen | Name eingeben → „Raum erstellen“ |
| Beitreten | Name + 5‑stelliger Code → „Raum beitreten“ |
| Code teilen | Auf den Raumcode klicken (kopiert in die Zwischenablage) |
| Ziel wählen | Auf eine der 4 großen Zahlen tippen |
| Würfel wählen | Würfel unten antippen (max. 2, Auswahl bleibt für den nächsten Zug) |
| Shop | Button oben rechts, `Esc` schließt |
| Revanche | Nach dem Match – startet neu, sobald beide zustimmen |

Ein Reload ist gefahrlos: Die Sitzung liegt im `localStorage`, das Spiel wird an
derselben Stelle fortgesetzt.

---

## Technische Details

* **Animationen** laufen ausschließlich über `transform` und `opacity`
  (GPU‑freundlich), dauern 200–650 ms und blockieren nie eine Eingabe.
* **Rendering** ist diff‑basiert: Pro Server‑Update wird nur das neu gezeichnet,
  was sich tatsächlich geändert hat.
* **Zielauswahl** schaltet die Oberfläche optimistisch um, damit sich die
  Bedienung ohne Wartezeit anfühlt; der Server korrigiert bei Bedarf.
* `prefers-reduced-motion` wird respektiert.
* Kein Tracking, keine externen Skripte (nur Google Fonts als Webfont).
