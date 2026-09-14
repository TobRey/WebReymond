# Aufbau der JSON-Dateien

Alle Daten liegen als JSON im Datenverzeichnis (`storage/`, alternativ ausserhalb von
`public_html`). Jede Datei traegt eine Schema-Version (`_schema`); beim Lesen werden aeltere
Schemata automatisch migriert.

```
storage/
├── settings/settings.json      Systemeinstellungen (inkl. verschluesseltem API-Schluessel)
├── settings/install.lock       Sperrdatei des Installers
├── users/<id>.json             ein Konto je Datei
├── users/_index.json           Zuordnung Benutzername/E-Mail -> Konto-ID
├── cases/<fall>.json           ein Fall je Datei
├── cases/_versions/<fall>/     Versionsverlauf (max. 20 Staende)
├── progress/<konto>/<fall>.json Spielstand
├── media/library.json          Medienbibliothek des Adminbereichs
├── sessions/                   PHP-Sitzungen
├── cache/                      Rate-Limits, erzeugte Audiodateien
├── logs/app.log, security.log  Protokolle (JSON-Zeilen)
└── backups/                    Sicherungen, dazu backups/corrupt/ fuer beschaedigte Dateien
```

---

## 1. Fall (`cases/<fall>.json`)

```jsonc
{
  "_schema": 3,
  "id": "toby",                      // Kleinbuchstaben, Zahlen, Bindestrich
  "code": "WIT-2024-1011",
  "title": "Where is Toby?",
  "status": "published",             // draft | published | disabled
  "order": 1,
  "difficulty": "schwer",
  "duration": "15-25 Min.",
  "incident_date": "2024-10-11",
  "location": "Millbrook, Vermont",
  "cover": "assets/img/scenes/case-cover-toby.svg",
  "summary": "...",                  // Kurztext in der Fallliste
  "briefing": "...",                 // Einsatzauftrag in der Fallakte
  "content_warning": "...",
  "intro": { "title": "...", "lines": ["Absatz 1", "Absatz 2"] },
  "missing_person": { "name","age","photo","last_seen","height","clothing","traits","description" },
  "map": { "image": "...", "title": "...", "legend": "..." },

  "start":       { "evidence": [], "devices": [], "flags": [], "locations": [] },
  "locations":   [ { "id","name","type","address","x","y","always_visible","requires_flags",
                     "description","notes","evidence" } ],
  "timeline_truth": [ { "time":"22:16","day_offset":0,"event":"...","actor":"...",
                        "location":"...","evidence":["E12"],"hidden":false } ],
  "npcs":        [ /* siehe unten */ ],
  "devices":     [ /* siehe FAELLE.md */ ],
  "media":       { "photos": [], "videos": [], "audios": [], "documents": [] },
  "evidence":    [ /* siehe unten */ ],
  "puzzles":     [ /* siehe unten */ ],
  "board_links": [ { "a":"E13","b":"loc_waterworks","flag":"...","evidence":[] } ],
  "horror_events": [ /* siehe FAELLE.md */ ],
  "file_entries":  [ { "code","title","summary","body","media","requires_flags" } ],
  "reference":     [ { "title","summary","body","image" } ],
  "report":      { "questions": [], "correct": {}, "weights": {}, "hints": [],
                   "culprit_question":"q_culprit", "location_question":"q_location",
                   "liars_question":"q_liars" },
  "endings":     [ { "id","title","tone","text","epilogue","image","conditions": {} } ],
  "scoring":     { "target_minutes": 25 },
  "solution":    { "culprit","location","motive","summary","liars" }
}
```

### Beweis

```jsonc
{ "id":"E09", "code":"E-09", "title":"Sprachnachricht 23:12 Uhr",
  "category":"audio",            // dokument | digital | foto | video | audio | aussage | objekt
  "importance":"kern",           // kern zaehlt fuer Bewertung und Enden staerker
  "timestamp":"11.10. 23:12", "source":"Cloud-Backup",
  "found_hint":"Wo der Beweis zu finden ist (nur fuer die Auswertung)",
  "summary":"kurz", "detail":"ausfuehrlich",
  "media": { "type":"audio", "id":"au_voice" },
  "tags":["audio","ort"], "related":["E15"] }
```

### Raetsel

```jsonc
{ "id":"pz_phone_pin", "type":"pin", "title":"...", "panel":"geraete",
  "hint_order":10, "length":4, "tolerance":15, "max_attempts":0,
  "prompt":"...", "context":"...", "placeholder":"...",
  "options":[ {"id":"opt_a","label":"...","note":"..."} ],
  "solutions":["0409"], "alternatives":["04.09"],
  "target":{"x":0.78,"y":0.35,"r":0.07},
  "requires":{ "puzzles":[], "evidence":[], "flags":[], "devices":[] },
  "on_success":{ "message":"...", "evidence":["E05"], "devices":["dev_x"],
                 "flags":["..."], "locations":["..."], "horror":"hr_x", "score":15,
                 "npc": { "npc_diane": { "trust": 5, "stress": -5, "unlock": ["thema"] } } },
  "on_fail":{ "message":"...", "close_message":"...", "blocked_message":"..." },
  "solution_explanation":"...", "hints":["Stufe 1","Stufe 2","Stufe 3"] }
```

### NPC

```jsonc
{ "id":"npc_diane", "name":"Diane Brennan", "age":44, "role":"Mutter",
  "relationship":"...", "avatar":"assets/img/avatars/diane.svg", "phone":"...",
  "short":"...", "personality":"...", "style":"...", "background":"...",
  "emotional_state":"...", "alibi_claimed":"...", "alibi_actual":"...",
  "initial_trust":55, "initial_stress":35, "leave_seconds":90,
  "requires_flags":[], "show_locked":false, "locked_hint":"...",
  "can_unlock_evidence":["E03"], "can_set_flags":["diane_gestanden"],
  "secrets":["..."], "unknown_topics":["..."],
  "knowledge":[ {"topic":"...","content":"...","min_trust":0,"requires_evidence":[]} ],
  "lies":[ {"id","claim","truth","evidence":["E11"],"evidence_hint":["..."],
            "confession":"...","solved_flag":"diane_gestanden"} ],
  "suggested_questions":[ {"label":"...","text":"..."} ],
  "proactive":[ {"id","text","requires_flags":[],"requires_evidence":[],"min_messages":0} ],
  "offline": { "greeting":[], "fallbacks":[], "break_off":"", "confront_unknown":"",
               "intents": {"greeting":"...","accuse":"..."},
               "rules":[ {"id","any":[],"all":[],"reply":[],"priority":7,"once":false,
                          "min_trust":0,"requires_flags":[],"requires_evidence":[],
                          "effects":{"trust":0,"stress":0,"evidence":[],"flags":[],"reveal":[]}} ],
               "confront": { "E11": { "reply":["..."], "effects": {} } } } }
```

---

## 2. Konto (`users/<id>.json`)

```jsonc
{ "_schema":3, "id":"<32 Hex-Zeichen>", "username":"tobi", "email":"",
  "password_hash":"$2y$...",          // bcrypt, niemals Klartext
  "role":"admin",                     // admin | player
  "agent_name":"Special Agent Tobi",
  "created_at":"...", "updated_at":"...", "last_login":"...",
  "must_change_password":false, "failed_logins":0, "locked_until":null,
  "age_confirmed":true,
  "settings":{ "volume":0.7,"subtitles":true,"effects":true,
               "reduced_motion":false,"jumpscares":true,"font_scale":1 },
  "stats":{ "cases_completed":1,"best_rank":"S","total_playtime":1480 } }
```

`users/_index.json` haelt nur die Zuordnung `byName` / `byEmail` auf die Konto-ID.

---

## 3. Spielstand (`progress/<konto>/<fall>.json`)

```jsonc
{ "_schema":3, "user_id":"...", "case_id":"toby",
  "started_at":"...", "updated_at":"...", "completed_at":null, "playtime":1480,
  "flags": { "phone_offen":true, "nora_gestanden":true },
  "solved": ["pz_phone_pin","pz_laptop_pw"],
  "attempts": { "pz_phone_pin": {"tries":2,"wrong":1,"last":1760000000} },
  "evidence": ["E01","E03"], "devices": ["dev_toby_phone"], "apps": [],
  "notes": [ {"id","text","ref":{"type","id"},"created"} ],
  "npc": { "npc_nora": { "trust":68,"stress":22,"alibi":"...","messages":[],
                          "revealed":[],"confronted":["E06"],"used_rules":[],
                          "message_count":7,"left_until":0,"proactive_sent":[] } },
  "board": { "nodes":[], "links":[], "view":{"x":0,"y":0,"zoom":1} },
  "hints_used":1, "hint_levels": {"pz_plate":2}, "hint_log":[],
  "horror_seen":["hr_message"], "horror_last":1760000000,
  "report": { "q_culprit":"npc_doss" }, "result": { /* Auswertung */ },
  "visited":["loc_home"], "discovered":["dev_toby_phone:messages"] }
```

Vertrauen, Stress und Regelzustand der NPCs sind bewusst **nicht** Teil der Antwort an den
Browser - sie bleiben serverseitig.

---

## 4. Einstellungen (`settings/settings.json`)

```jsonc
{ "_schema":3,
  "site": { "name","tagline","agency","language","allow_guests","allow_register","imprint","privacy" },
  "ai":   { "provider":"offline|gemini|openai_compatible", "base_url","model",
            "api_key_enc":"sb1:...", "timeout":30,"retries":2,"max_tokens":420,
            "temperature":0.85,"rate_per_minute":12,"rate_per_hour":180,
            "fallback_offline":true,"last_test":{"at","ok","message"} },
  "gameplay": { "hints_per_case":2,"horror_intensity":"normal","jumpscares":true,
                "autosave_seconds":20,"default_case":"toby","show_timer":true },
  "security": { "max_login_attempts":6,"lockout_minutes":15,"chat_per_minute":15,
                "api_per_minute":120,"registration_per_hour":8 },
  "updated_at":"..." }
```

`api_key_enc` ist mit dem App-Schluessel aus `app/config.local.php` verschluesselt
(`sb1:` = libsodium, `og1:` = OpenSSL AES-256-GCM). Ohne diese Datei ist der Schluessel
wertlos.

---

## 5. Lokale Konfiguration (`app/config.local.php`)

```php
return [
  'installed'    => true,
  'installed_at' => '2026-09-14T22:36:00+00:00',
  'storage_path' => '/home/kunde/wit_data',   // oder .../public_html/storage
  'uploads_path' => '/home/kunde/public_html/uploads',
  'app_key'      => '<64 Hex-Zeichen>',
  'base_path'    => '',                        // '' oder '/unterordner'
  'debug'        => false,
];
```

Diese Datei niemals oeffentlich zugaenglich machen und bei Sicherungen mitnehmen - ohne
`app_key` laesst sich ein gespeicherter API-Schluessel nicht mehr entschluesseln.
