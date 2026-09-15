# Eigene Faelle bauen

Faelle werden vollstaendig im Adminbereich erstellt - ohne Programmierkenntnisse. Der Editor
schreibt eine JSON-Datei unter `storage/cases/<fall-id>.json`. Der Aufbau ist in
`JSON-STRUKTUR.md` beschrieben, hier steht der Arbeitsablauf.

---

## 1. Neuen Fall anlegen

**Adminbereich → Faelle → Neuen Fall anlegen**

Der Editor hat links eine Abschnittsleiste. Sinnvolle Reihenfolge:

1. **Allgemeine Falldaten** - ID (Kleinbuchstaben, keine Leerzeichen), Titel, Ort, Datum,
   Kurzbeschreibung, Einsatzauftrag, Inhaltswarnung
2. **Vermisste Person** - Name, Alter, Foto, Beschreibung
3. **Orte** - jeder Ort mit ID, Name und Kartenposition (X/Y zwischen 0 und 1)
4. **Zeitachse (Wahrheit)** - der tatsaechliche Ablauf; Grundlage fuer alles Weitere
5. **Personen (NPCs)** - siehe unten
6. **Medien** - Fotos, Videos, Audios, Dokumente
7. **Geraete** - Handys und Rechner mit Apps und Inhalten
8. **Beweise** - alles, was der Spieler sichern kann
9. **Raetsel** - wie Beweise freigeschaltet werden
10. **Wandverbindungen**, **Horror-Ereignisse**
11. **Abschlussfragen und Bewertung**, **Enden**
12. **Aufloesung (intern)** - Taeter, Ort, Motiv, Ablauf

Zwischendurch immer **Entwurf speichern**. Erst **Pruefen und veroeffentlichen** macht den
Fall fuer Spieler sichtbar; die Veroeffentlichung wird abgelehnt, solange die
Konsistenzpruefung Fehler meldet.

---

## 2. Der goldene Grundsatz

> Jede Behauptung im Spiel braucht einen technischen Gegenbeweis, und jeder Beweis braucht
> einen Fundort.

Praktisch heisst das:

* Fuer jede **Luege** eines NPCs gibt es genau einen Beweis, der sie kippt
  (`lies[].evidence`) und einen Zustand, der sie als aufgedeckt markiert (`lies[].solved_flag`).
* Jeder **Beweis** wird von irgendetwas freigeschaltet: einem Raetsel (`on_success.evidence`),
  einem Bilddetail (`hotspots[].evidence`), einer Dialogregel (`effects.evidence`), einer
  Konfrontation oder einer Wandverbindung. Die Konsistenzpruefung meldet Beweise, die
  niemand erreichen kann.
* Jedes **Raetsel** hat drei Hinweisstufen und eine Loesungserklaerung.

---

## 3. Raetseltypen

| Typ | Eingabe | Loesung in `solutions` |
|---|---|---|
| `text` | Freitext | Zeichenkette(n), Gross-/Kleinschreibung egal |
| `password` | Passwortfeld | wie `text`, zusaetzlich `alternatives` fuer Schreibweisen |
| `pin` | Ziffernblock | Ziffernfolge, `length` setzen |
| `number` | Zahl | Ziffernfolge |
| `pattern` | Mustersperre (3x3) | Punktfolge, z. B. `14789` |
| `choice` | eine Auswahl | Options-ID |
| `multi` / `contradiction` / `pairs` | mehrere Auswahlen | Liste von Options-IDs (Reihenfolge egal) |
| `sequence` / `timeline` | Sortierung | Liste von Options-IDs in richtiger Reihenfolge |
| `timecode` | Videozeitpunkt | Sekunden (Toleranz ueber `tolerance`) |
| `locate` | Klick auf die Karte | `target` mit `x`, `y`, `r` oder Orts-ID in `solutions` |

Bei Mengen- und Reihenfolgeraetseln wird `solutions` als **Liste von Listen** geschrieben,
z. B. `[["opt_a","opt_b"]]`.

---

## 4. Geraete und App-Inhalte

Jedes Geraet hat Apps. Das Feld **Inhalt (JSON)** je App folgt diesem Aufbau:

```jsonc
// type: messages
{ "threads": [ { "id":"t1", "contact":"Nora", "number":"+1 802 ...",
  "messages": [ {"from":"me|them", "text":"...", "time":"11.10. 22:04", "deleted":false,
                 "attachment":"ph_foto_id"} ] } ] }

// type: gallery
{ "note":"...", "photos":[ {"media":"ph_id","title":"...","thumb":"assets/img/..."} ] }

// type: files
{ "root":"C:\\Users\\toby", "tree":[ {"name":"ordner","type":"folder","children":[
    {"name":"datei.txt","type":"file","size":"4 KB","modified":"11.10.2024 19:36",
     "body":"Inhalt","media":"doc_id","deleted":false,"puzzle":"pz_id",
     "requires_puzzle":"pz_id"} ]} ] }

// type: mail
{ "account":"...", "messages":[ {"id":"m1","from":"...","to":"...","subject":"...",
   "date":"...","body":"...","attachments":[{"name":"...","media":"ph_id"}]} ] }

// type: browser
{ "note":"...", "entries":[ {"time":"...","title":"...","url":"...","body":"..."} ] }

// type: notes
{ "notes":[ {"title":"...","date":"...","text":"..."} ] }

// type: calls
{ "entries":[ {"time":"...","name":"...","number":"...","direction":"eingehend","duration":"0:38"} ] }

// type: audio / cctv
{ "clips":[ {"media":"au_id","title":"...","meta":"..."} ] }
{ "videos":[ {"media":"vid_id","title":"...","camera":"CAM 03","meta":"..."} ] }

// type: deleted
{ "note":"...", "items":[ {"name":"datei.frag","size":"0,4 KB","deleted_at":"...",
   "body":"...","media":"au_id","meta":"..."} ] }

// type: logs  (durchsuchbare Tabelle)
{ "columns":["Zeit","Karte","Person"], "rows":[["...","...","..."]],
  "note":"...", "puzzle":"pz_id" }

// type: login  (simulierte Anmeldemaske)
{ "title":"Anmeldung","system":"SCADA","user":"leitung","note":"...","puzzle":"pz_id" }
```

Sperren: `requires_puzzle` (App oder Datei erst nach geloestem Raetsel) und
`requires_flags` (erst bei gesetztem Zustand). Der Server liefert gesperrte Inhalte gar nicht
erst aus - sie stehen nicht im Quelltext.

---

## 5. Bilddetails (Hotspots)

Bei Fotos koennen beliebig viele Detailpunkte gesetzt werden:

```json
{ "id":"hs_note", "label":"Notizzettel", "x":0.66, "y":0.5, "r":0.1,
  "zoom_min":1.5, "evidence":"E04", "puzzle":"", "text":"Was der Agent sieht ..." }
```

`x`, `y`, `r` sind relativ (0-1). `zoom_min` blendet das Detail erst ab einer Zoomstufe ein -
gut fuer versteckte Funde.

---

## 6. NPCs

Wichtige Felder:

* **Wissen** (`knowledge`): Thema + Inhalt, optional `min_trust` oder `requires_evidence`
* **Luegen** (`lies`): Behauptung, Wahrheit, widerlegender Beweis, Gestaendnis, `solved_flag`
* **Offline-Dialogregeln**: siehe `OFFLINE-MODUS.md`
* **Konfrontationen** (`offline.confront`): Beweis-ID → Antwort + Effekte
* **Darf freischalten** (`can_unlock_evidence`, `can_set_flags`): Sicherheitsnetz gegen
  erfundene Freischaltungen durch die KI

Der Systemprompt fuer die KI wird aus diesen Feldern automatisch gebaut. Man schreibt also
keine Prompts, sondern Figuren.

---

## 7. Horror-Ereignisse

```json
{ "id":"hr_message", "type":"message", "intensity":"normal", "priority":9, "once":true,
  "trigger": { "event":"solve", "min_solved":4, "chance":1.0, "ignore_cooldown":true },
  "payload": { "title":"Neue Nachricht", "text":"...", "effect":"flicker",
               "duration":4200, "sound":"ui_alert", "jumpscare":false },
  "sets_flags":["nachricht_erschienen"] }
```

Ausloeser: `solve`, `fail`, `open_app`, `open_panel`, `open_device`, `evidence`, `chat`,
`confront`, `board`, `report`, `finish`, `hint`, `start`.
Zwischen zwei Ereignissen liegt ein Mindestabstand (Standard 70 Sekunden), damit nichts zur
Jumpscare-Kette wird. `jumpscare: true` markierte Ereignisse lassen sich in den
Spieleinstellungen abschalten.

---

## 8. Bewertung und Enden

* **Abschlussfragen** mit Gewichtung; Freitextfragen werden ueber `keywords` bewertet.
* **Musterloesungen** unter *Abschlussfragen → Musterloesungen (JSON)*.
* **Enden** werden von oben nach unten geprueft; das erste passende Ende gewinnt.
  Bedingungen: `min_percent`, `max_percent`, `culprit_correct`, `location_correct`,
  `min_key_evidence`, `min_lies`, `max_wrong`, `requires_flag`.
  Das letzte Ende sollte ohne Bedingungen als Auffangfall dienen.

---

## 9. Pruefen, Testen, Veroeffentlichen

* **Konsistenzpruefung** meldet doppelte IDs, unloesbare Raetsel, nicht erreichbare Beweise,
  fehlende Mediendateien, zirkulaere Abhaengigkeiten, widerspruechliche Zeitangaben und
  fehlende Hinweisstufen.
* **Vorschau / Testmodus** oeffnet den Fall als Administrator: unbegrenzte Hinweise, auch
  unveroeffentlichte Faelle, freie Navigation.
* **Testfortschritt zuruecksetzen** startet den Fall fuer das eigene Konto neu.
* **Versionsverlauf**: Jedes Speichern sichert die vorherige Fassung (20 Versionen).
* **Export/Import**: JSON-Datei, z. B. um einen Fall auf einem anderen Server einzuspielen.

---

## 10. Faustregeln fuer die Falllaenge

| Ziel | Richtwert |
|---|---|
| Spielzeit 15-25 Minuten | 15-20 Raetsel, 20-25 Beweise, 6-8 NPCs |
| Spielzeit 30-45 Minuten | 25-30 Raetsel, 35 Beweise, 10 NPCs |

Wichtiger als die Menge ist die Kette: Jeder Fund soll genau eine neue Frage aufwerfen.


---

## Auftraege (Wegweiser im Fall)

Unter `objectives` steht, woran der Spieler gerade arbeiten soll. Angezeigt wird immer nur
das erste Kapitel, in dem noch etwas offen ist, und daraus hoechstens drei Punkte.

```json
{
  "id": "ob_phone",
  "chapter": 1,
  "chapter_title": "Tobys Zimmer",
  "title": "Tobys Smartphone entsperren",
  "detail": "Wo man suchen sollte - ohne die Loesung zu nennen.",
  "panel": "geraete",
  "requires": { "puzzles": ["pz_..."], "evidence": [], "flags": [], "devices": [] },
  "done":     { "puzzles": ["pz_phone_pin"] }
}
```

* `requires` bestimmt, ab wann der Punkt ueberhaupt auftaucht. Auftraege, deren
  Voraussetzungen fehlen, bleiben verborgen - sonst steht dort eine Aufgabe, die noch gar
  nicht loesbar ist.
* `done` bestimmt, wann er als erledigt gilt (dieselben Felder wie `requires`).
* `panel` ist der Bereich, in den der Knopf "Oeffnen" springt: `akte`, `personen`,
  `beweise`, `geraete`, `wand`, `karte`, `zeit`, `notizen`, `bericht`.
* Fehlt `objectives` ganz, blendet das Spiel die Leiste einfach aus. Bestehende Faelle
  funktionieren also unveraendert weiter.

Formuliere `detail` als Wegweiser, nicht als Loesung: *wo* zu suchen ist, nicht *was*
herauskommt. Die eigentliche Hilfe leisten die drei Hinweisstufen des Raetsels.
