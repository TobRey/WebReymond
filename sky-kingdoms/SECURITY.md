# Sky Kingdoms – Sicherheit

Dieses Dokument beschreibt, was eingebaut ist, warum, und wo man nachsehen kann.

---

## 1. Konten und Anmeldung

| Schutz | Umsetzung |
|---|---|
| Passwortspeicherung | `password_hash()` mit **Argon2id** (Rückfall auf bcrypt), `password_verify()`, automatisches Neuhashen bei Algorithmuswechsel |
| Passwortqualität | mindestens 10 Zeichen, drei verschiedene Zeichenarten, Sperrliste häufiger Passwörter, darf Benutzername oder E-Mail nicht enthalten |
| Sitzungen | eigener Ablageort `storage/sessions`, `HttpOnly`, `SameSite=Lax`, `Secure` automatisch bei HTTPS, `use_strict_mode` |
| Sitzungsübernahme | `session_regenerate_id(true)` bei jeder Anmeldung; leichter Fingerabdruck über den Browsertyp (bewusst **ohne** IP-Adresse, damit Mobilfunkwechsel niemanden auswirft) |
| Untätigkeit | automatische Abmeldung nach `session_lifetime` (Standard 2 Stunden) |
| „Angemeldet bleiben" | Selector/Validator-Verfahren: nur der SHA-256-Wert des Validators liegt gespeichert, Rotation bei jeder Verwendung, höchstens fünf Geräte. Ein ungültiger Validator verwirft **alle** Merkmale des Kontos (Diebstahlerkennung) |
| Passwort zurücksetzen | einmalige 64-stellige Token, nur als SHA-256 gespeichert, eine Stunde gültig, Antwort immer gleich – egal ob die Adresse existiert |
| Kein Gastmodus | ohne Konto ist kein einziger Spielaufruf möglich |

**Wo:** `app/Core/Auth.php`, `app/Core/Password.php`, `app/Core/Session.php`

---

## 2. Angriffe auf Formulare und Schnittstellen

| Schutz | Umsetzung |
|---|---|
| CSRF | 32-Byte-Token je Sitzung, Pflicht bei **jeder** verändernden Anfrage – als Formularfeld oder im Kopf `X-SK-CSRF`, Vergleich mit `hash_equals()` |
| Methodenzwang | verändernde API-Aktionen nur per POST |
| Rate-Limits | Anmeldung 8/15 min je IP **und** je Konto, Registrierung 5/h, Zurücksetzen 5/h, API 240/min, Angriffe 30/h |
| XSS | jede Ausgabe über `e()` (`htmlspecialchars` mit `ENT_QUOTES`), im Browser `escapeHtml()`; Content-Security-Policy ohne `unsafe-inline` für Skripte (nur nonce-basiert) |
| Clickjacking | `X-Frame-Options: SAMEORIGIN`, `frame-ancestors 'self'` |
| MIME-Sniffing | `X-Content-Type-Options: nosniff` |
| Host-Header-Injection | Hostname wird gegen ein striktes Muster geprüft, sonst `localhost` |
| Kopfzeilen-Injection in E-Mails | Zeilenumbrüche werden aus Betreff und Namen entfernt, Betreff Base64-kodiert |

**Wo:** `app/Core/Csrf.php`, `app/Core/RateLimit.php`, `app/Core/Security.php`, `app/Core/Mailer.php`

---

## 3. Datenablage (ersetzt die Datenbank)

Es gibt kein SQL – damit entfällt SQL-Injection als Angriffsklasse vollständig.
An ihre Stelle treten zwei andere Risiken, die gezielt behandelt werden:

### Pfadmanipulation

Jeder Datenschlüssel wird streng geprüft, bevor daraus ein Pfad wird:

```php
preg_match('#^[A-Za-z0-9][A-Za-z0-9._/\-]*$#', $key)   // nur erlaubte Zeichen
!str_contains($key, '..') && !str_contains($key, '//')  // kein Ausbruch
!str_ends_with(strtolower($key), '.php')                // keine Endung selbst wählen
```

Spielerkennungen sind zusätzlich auf Hexadezimalzeichen begrenzt (`Ids::isValid`).

### Direkter Webzugriff auf Spielstände

**Doppelt abgesichert:**

1. `.htaccess` in `app/`, `config/`, `storage/`, `tests/`, `tools/` und in jedem
   Datenunterordner (`Require all denied`, dazu die alte Apache-2.2-Schreibweise).
2. **Jede Datendatei ist selbst eine PHP-Datei** und beginnt mit
   `<?php exit; /* … */ ?>`. Ruft jemand sie direkt auf, führt der Server sie aus –
   und sie gibt nichts aus. Das wirkt auch dann, wenn `.htaccess` ignoriert wird
   (nginx, `AllowOverride None`).

Damit in einer Datendatei niemals ausführbarer Code entstehen kann, werden beim
Speichern zusätzlich `<` und `>` als `<`/`>` kodiert (`JSON_HEX_TAG`).
Ein Spielername wie `<?php …?>` bleibt damit harmloser Text.

Der Installer prüft am Ende per HTTP-Aufruf selbst, ob die Spielstände von aussen
erreichbar sind, und erklärt im Fehlerfall die Gegenmassnahme. Der Adminbereich
wiederholt diese Prüfung unter „Diagnose".

### Datenverlust

| Risiko | Schutz |
|---|---|
| Abbruch beim Schreiben | Schreiben in eine temporäre Datei, dann `rename()` – ein atomarer Vorgang. Eine halb geschriebene Datei kann nicht entstehen |
| Gleichzeitige Zugriffe | `flock(LOCK_EX)` um jeden Lese-Ändere-Schreibe-Zyklus, mit Zeitlimit gegen Blockaden |
| Beschädigte Datei | Von jeder Datei existiert die vorige Fassung als `.bak.php`; beim Lesen wird automatisch auf sie zurückgegriffen und die Hauptdatei wiederhergestellt |
| Voller Datenträger | Unvollständige Schreibvorgänge werden erkannt und mit klarer Meldung abgebrochen, statt Daten zu überschreiben |

**Wo:** `app/Store/Store.php`, `app/Store/Lock.php`

---

## 4. Manipulierte Spielaktionen

Der Browser ist **nie** Entscheider:

- Kosten, Stufen, Mengen, Lagergrenzen und Punkte berechnet ausschliesslich der Server.
- Eine Anfrage enthält nur *was* getan werden soll (`upgrade`, `id`, `steps`) – niemals
  *was es kostet* oder *was danach herauskommt*.
- Jede Handlung läuft in `Player::withWorld()`: Sperre holen → Zeit nachrechnen →
  prüfen → buchen → atomar speichern → Sperre lösen. Zwei gleichzeitige Anfragen
  können denselben Rohstoff nicht zweimal ausgeben.
- `Economy::pay()` ist Alles-oder-nichts: Reicht ein Posten nicht, wird **nichts** abgebucht.
- Schrittzahlen werden begrenzt (`MAX_STEPS_PER_ACTION = 5000`), negative Werte auf 1
  angehoben, unbekannte Kennungen abgelehnt.
- Kosten ausserhalb des sicheren Zahlenbereichs führen zu einer Ablehnung mit
  verständlicher Meldung statt zu falschen Zahlen.

### Kämpfe

Der Browser schickt nur das Protokoll der Entscheidungen
(`[{t: Zeitpunkt, a: 'lane'|'ability', v: Wert}]`). Der Server rechnet die Mission
vollständig nach – gleicher Startwert, gleiche Regeln, gleiche Reihenfolge – und
verwendet ausschliesslich sein eigenes Ergebnis. Zusätzlich:

- Ein Angriff kann nur **einmal** ausgewertet werden (Statuswechsel unter Sperre).
- Nach 30 Minuten verfällt ein offener Angriff.
- Zu schnell hintereinander gemeldete Fähigkeiten werden verworfen (Abklingzeit).
- Weicht das vom Browser gemeldete Ergebnis stark ab, wird das als verdächtig
  protokolliert.

**Wo:** `app/Game/Actions.php`, `app/Game/Economy.php`, `app/Game/Combat/Battle.php`

---

## 5. Schutz der Spieler untereinander

- Neulingsschutz (Standard 3 Tage)
- Schild nach verlorenem Angriff (Standard 3 Stunden)
- höchstens 3 Angriffe pro Tag vom selben Angreifer auf dasselbe Königreich
- Gegnersuche nur im Bereich ±40 % Punktestand
- keine Angriffe innerhalb einer Allianz
- Beute höchstens 25 % eines Lagers und begrenzt durch die Tragfähigkeit der Truppe
- keine dauerhafte Zerstörung: Gebäude werden beschädigt und sind sofort reparierbar

---

## 6. Adminbereich

- Nur mit Rolle `admin` erreichbar; unberechtigte Versuche werden protokolliert.
- **Jede** Änderung landet im Audit-Log mit Zeitpunkt, handelnder Person, IP und
  Zusammenhang: Sperren, Rollen, Rohstoffkorrekturen (mit Pflichtbegründung),
  Passwort-Zurücksetzungen, Balanceänderungen, Ankündigungen, Sicherungen.
- Der Wartungsmodus lässt nur Administratoren ins Spiel.
- Sicherungen enthalten personenbezogene Daten und werden nach dem Herunterladen
  sofort vom Server gelöscht.

**Wo:** `app/Core/Audit.php`, `app/Http/Controllers/AdminController.php`

---

## 7. Was bewusst **nicht** eingebaut ist

- **Kein Zwei-Faktor-Verfahren.** Für ein Aufbauspiel mit Spielständen ohne Geldwert
  steht der Aufwand (TOTP-Bibliothek, Wiederherstellungscodes, Support) nicht im
  Verhältnis. Die Struktur erlaubt eine Ergänzung an einer Stelle (`Auth::login`).
- **Keine Verschlüsselung der Spielstände auf der Platte.** Wer Dateizugriff auf den
  Server hat, käme auch an den Schlüssel. Der Schutz liegt bei Hoster und Dateirechten.
- **Keine E-Mail-Bestätigung bei der Registrierung.** Auf vielen Shared-Hosting-Paketen
  funktioniert `mail()` unzuverlässig; eine Pflichtbestätigung würde Spieler aussperren.
  Im Adminbereich lässt sich die Registrierung stattdessen komplett schliessen.

---

## 8. Regelmässig selbst prüfen

```bash
php tests/run.php        # enthält Prüfungen zu Sperren, Doppelausgaben, Pfadschutz
php tools/smoke.php      # prüft CSRF, Methodenzwang, fremde Kennungen, Anmeldepflicht
```

Im Adminbereich: **Diagnose** (Erreichbarkeit der Spielstände, Dateirechte,
PHP-Version) und **Protokolle** (rot hinterlegt: verdächtige Vorgänge).

---

## 9. Eine Lücke melden

Bitte nicht öffentlich. Wende dich an den Betreiber deiner Installation.
Für die Weiterentwicklung gilt: Sicherheitsfehler haben Vorrang vor Funktionen.
