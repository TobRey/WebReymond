# CASH – privates Finanzsystem

CASH läuft unter **https://reymond-tobias.ch/cash** – PHP 8.4, ohne Datenbank, ohne Node, ohne kostenpflichtige Dienste.
Alle Daten liegen serverseitig in geschützten JSON-Dateien (Beträge intern als ganze Rappen).

**Fertige ZIP:** [`release/cash.zip`](release/cash.zip) (Prüfsumme in `release/cash.zip.sha256`)

---

## Installation (GoDaddy cPanel)

1. Im cPanel den **Datei-Manager** öffnen und in `public_html` den Ordner **`cash`** anlegen.
2. In `public_html/cash` die Datei **`cash.zip` hochladen**.
3. Rechtsklick auf `cash.zip` → **Extract** → Ziel `/public_html/cash` → danach `cash.zip` löschen.
4. Unter **cPanel → MultiPHP Manager** für die Domain **PHP 8.4** wählen (8.1 oder neuer funktioniert auch).
5. **https://reymond-tobias.ch/cash** öffnen und zuerst die **PIN festlegen** (4–8 Ziffern),
   danach den eigenen Account einrichten
   (Name, Benutzername, Passwort mit mind. 10 Zeichen).
6. Danach fragt CASH die **aktuellen Kontostände** von *Privatkonto* und *E-Jugendsparkonto* ab
   (0 ist erlaubt). Das Privatkonto zählt als Alltagskonto, das Sparkonto nicht.

Fertig. Auf dem iPhone in Safari **Teilen → «Zum Home-Bildschirm»** wählen – CASH öffnet sich dann wie eine App.

### Wo liegen die Daten?

CASH legt beim ersten Aufruf automatisch den Ordner **`/home/BENUTZER/cash_data`** an –
also **ausserhalb von `public_html`** und damit nicht über das Web erreichbar.
Darin: Benutzer, Tokens, Sessions, Finanzdaten pro Benutzer und automatische Tagessicherungen.

Nur falls dieser Ordner nicht angelegt werden darf, nutzt CASH `public_html/cash/data`.
Dieser Ordner ist dreifach gesperrt: `.htaccess` (Apache/LiteSpeed), ein `index.php`, und jede Datei
beginnt mit einer PHP-Sperrzeile, die bei direktem Aufruf nur einen leeren 404 liefert.

Eigenen Ort festlegen (optional): Datei `public_html/cash/config.local.php` anlegen:

```php
<?php return ['data_dir' => '/home/BENUTZER/mein_ordner'];
```

### Updates

Neue `cash.zip` genauso in `public_html/cash` entpacken und vorhandene Dateien überschreiben lassen.

**Update auf 1.3 (PIN-Schutz):** Beim ersten Aufruf nach dem Update erscheint «PIN festlegen».
Benutzername und Passwort des Hauptaccounts eingeben und die gewünschte PIN zweimal eintippen.
Die PIN steht nirgends im Code oder in Git – sie wird nur als Hash im Datenordner gespeichert.
PIN vergessen? Im cPanel-Datei-Manager die Datei `cash_data/pin.json.php` löschen – danach kann die PIN
mit Benutzername und Passwort des Hauptaccounts neu festgelegt werden.

Die ZIP enthält **keine** Daten und keine `config.local.php` – Accounts, Buchungen und Einstellungen
bleiben erhalten. Dateien der Hauptwebsite werden nie berührt (alles bleibt in `/cash`).

---

## Funktionen

Die Hauptzahl auf der Übersicht ist **«Frei für Freizeit»** – so viel Geld bleibt bis Ende Monat,
wenn alle Fixkosten bezahlt und die Sparraten gespart sind:

```
  Guthaben auf Alltagskonten
− noch offene Fixkosten bis Ende Monat
− noch nicht gesparte Sparraten des Monats
= Frei für Freizeit   (dazu: etwa CHF … pro Tag für die restlichen Tage)
```

| Bereich | Inhalt |
|---|---|
| **Übersicht** | Frei für Freizeit mit Rechnung dazu, Ausgabe/Einnahme erfassen, «Noch zu bezahlen» mit Bezahlt-Knopf, Ausgaben im Monat, Fixkosten pro Monat, Konten |
| **Buchungen** | Einnahmen, Ausgaben, Umbuchungen; Bearbeiten/Löschen; Suche; Filter nach Monat, Typ, Konto, Kategorie; CSV-Export |
| **Fixkosten** | wiederkehrende Ausgaben (monatlich, alle 3 Monate, jährlich oder einmalig), was im Monat offen bzw. bezahlt ist, Bearbeiten/Beenden |
| **Sparziele** | Zielbetrag, optional Zieldatum und **Sparrate pro Monat**; «Gespart» verschiebt das Geld per Umbuchung aufs Sparkonto |
| **Einstellungen** | Konten (inkl. «Alltagskonto»), Kategorien, Profil, Passwort, angemeldete Geräte, Backup/Wiederherstellung, CSV |

### Rechenregeln

- **Wiederkehrende Ausgabe**: Beim Erfassen «Wiederkehrend» einschalten. Die heutige Ausgabe gilt als bezahlt;
  ab dem nächsten Termin erscheint der Betrag unter «Noch zu bezahlen» und wird vom freien Geld abgezogen,
  bis du «Bezahlt» drückst. Bezahlt wird nie automatisch, und Termine werden nie doppelt erzeugt.
- **Alltagskonto**: Nur diese Konten zählen zum freien Geld. Sparkonten sind standardmässig ausgeschlossen
  (pro Konto umschaltbar unter Einstellungen → Konten).
- **Sparrate**: wird jeden Monat reserviert. «Gespart» bucht eine Umbuchung (z. B. Privatkonto → Sparkonto);
  das freie Geld bleibt dabei gleich, weil der Betrag schon reserviert war. Nie mehr als bis zum Ziel fehlt.
- **Kontostand** = Anfangssaldo + Einnahmen − Ausgaben ± Umbuchungen. Anfangssalden und Umbuchungen
  zählen nie als Einnahme oder Ausgabe.
- Früher erfasste Budgets, Fremdwährungsbuchungen und Darlehen bleiben unverändert gespeichert
  (Kontostände stimmen weiterhin), werden aber nicht mehr separat angezeigt.

## PostFinance-Import

### Gibt es eine offizielle Schnittstelle? (Stand Oktober 2026)

| Weg | Voraussetzung | Für dich nutzbar? |
|---|---|---|
| **bLink (SIX, Open Banking – Kontoinformationen/AIS)** | Zulassung als «Service User» mit Teilnahmevertrag, Zulassungsprüfung und Client-Zertifikat | Nein – nur für zugelassene Firmen |
| **EBICS (camt.053 direkt von PostFinance)** | Geschäftskunden-Kanal, EBICS-Vereinbarung, unterzeichnete Initialisierungsbriefe | Nein – nicht für Privatkonten |
| **PostFinance-Multibanking** | holt Konten *anderer* Banken in die PostFinance-App | Keine Schnittstelle nach aussen |
| **Export aus E-Finance / App (CSV, camt.053)** | normales E-Finance-Login | **Ja – wird verwendet** |

Darum importiert CASH **Kontoauszugs-Dateien**. Es werden keine Bank-Zugangsdaten verlangt oder gespeichert,
und es gibt kein Screen-Scraping. TWINT wird nicht angebunden (TWINT-Zahlungen erscheinen ganz normal als Zeilen im Auszug).

### So geht's

1. PostFinance E-Finance → Konto → Bewegungen → Zeitraum wählen → **Export als CSV**
   (alternativ den Kontoauszug als **camt.053-XML**, falls bei deinem Konto verfügbar).
2. In CASH **Buchungen → ⬆ (Import)** oder **Einstellungen → PostFinance-Kontoauszug importieren**.
3. Datei wählen. Das Konto wird per IBAN erkannt (IBAN unter Konten hinterlegen).
4. In der **Vorschau** siehst du jede Zeile:
   - **Neu** – mit vorgeschlagener Kategorie (änderbar, CASH merkt sich Korrekturen),
   - **Schon erfasst** – du hast den Betrag schon von Hand eingetragen → wird verknüpft statt doppelt gebucht,
   - **Bereits importiert** – überlappende Zeiträume oder CSV und camt gemischt → wird übersprungen,
   - **Vor Einrichtung** – liegt vor dem Anfangssaldo und ist dort schon enthalten → standardmässig übersprungen,
   - Überweisungen auf dein eigenes Sparkonto werden als **Umbuchung** erkannt,
   - passende offene **Fixkosten** werden als bezahlt markiert.
5. **Kontostand übernehmen**: Der Schlusssaldo der Bank wird übernommen; die Differenz gleicht CASH ohne Einnahme
   oder Ausgabe aus.
6. Jeder Import lässt sich unter «Letzte Importe» **rückgängig machen**; vorher wird automatisch eine Sicherung angelegt.

Die hochgeladene Datei wird nur im Arbeitsspeicher verarbeitet. Die Vorschau liegt höchstens 2 Stunden im
geschützten Datenordner und wird nach dem Übernehmen oder Abbrechen gelöscht.

## Sicherheit

- **PIN vor allem:** Ohne gültige PIN-Session liefert der Server nur das PIN-Formular aus – keinen App-Code,
  keine API-Antwort mit Daten (jede API-Anfrage → 401). Der App-Code liegt im gesperrten Ordner `app/`.
- **PIN-Speicherung:** nur `password_hash(HMAC-SHA256(PIN, Geheimschlüssel))`; der Schlüssel liegt in einer anderen Datei.
- **PIN-Sperren:** pro IP 5 Fehlversuche → 15 min gesperrt, jede weitere Sperre doppelt so lang (max. 24 h);
  global 10 Fehlversuche in 24 h von unbekannten Geräten → 24 h Sperre für alle unbekannten Geräte. Geräte, die
  schon einmal richtig entsperrt wurden, bleiben nutzbar (ein Angreifer kann dich nicht aussperren).
  Jeder Fehlversuch wird zusätzlich verzögert. PIN-Session: 1 h Inaktivität, max. 12 h; «Sperren»-Knopf auf der Übersicht.
- **Hinweis:** Eine 4-stellige PIN hat nur 10’000 Kombinationen und bietet **nur begrenzten Schutz** – sie ist
  eine zusätzliche Hürde, kein Ersatz für ein starkes Passwort. Darum bleibt die Anmeldung mit Passwort aktiv.
  Wer kann, wählt 6–8 Ziffern (Einstellungen → PIN ändern).
- **HTTPS:** Seiten werden auf HTTPS umgeleitet, die API verweigert unverschlüsselte Anfragen, Cookies sind `Secure`.
  HSTS ist optional, weil es für die ganze Domain gilt: `config.local.php` → `'hsts' => true`.
- **Logs:** Fehler werden ohne Inhalte protokolliert (Zahlenfolgen wie IBAN oder Beträge maskiert).

- Passwörter mit `password_hash` / `password_verify`, automatisches Re-Hashing.
- Sessions: eigener Speicherort, `HttpOnly`, `Secure` (unter HTTPS), `SameSite=Lax`, Pfad `/cash/`, Timeout 2 h Inaktivität.
- «Angemeldet bleiben»: zufälliges Selektor/Prüfwert-Token (nur Hash gespeichert), 30 Tage gleitend, max. 180 Tage,
  Rotation bei jeder Nutzung, Missbrauchserkennung (widerruft alle Tokens), Logout widerruft das Token,
  Geräteliste mit Einzel-Abmeldung, Passwortwechsel meldet alle anderen Geräte ab.
- CSRF-Token bei jeder Änderung + Herkunftsprüfung, Login-Limitierung (5 Fehlversuche pro Benutzer / 20 pro IP in 15 min).
- Serverseitige Validierung aller Eingaben; Benutzerzuordnung ausschliesslich aus der Session.
- Ausgabe im Browser nur über `textContent` (kein HTML aus Daten), Content-Security-Policy, `X-Frame-Options: DENY`.
- Dateisperren (`flock`) und atomares Schreiben (temporäre Datei, `fsync`, `rename`), Dateirechte 600.
- Doppelklick-Schutz: Knopf wird gesperrt, jede Anfrage trägt eine Einmal-ID, die der Server nur einmal ausführt.
- Erst der erste Account wird eingerichtet; danach ist die öffentliche Registrierung aus (im Hauptaccount umschaltbar).
  Jeder Account hat eine eigene Datendatei.

## Backup

- **Einstellungen → JSON-Backup herunterladen** (vollständig, inkl. Kategorien und Kursen).
- **Wiederherstellen**: Die Datei wird vollständig geprüft (Typen, Beträge, Daten, Verknüpfungen);
  vor dem Ersetzen legt CASH automatisch eine Sicherung des aktuellen Stands an.
- Zusätzlich legt CASH täglich vor der ersten Änderung eine Sicherung im Datenordner an (die letzten 30 bleiben).

## Entwicklung & Tests

```bash
./build.sh                                      # Syntaxprüfung + release/cash.zip
php tests/unit-test.php                         # Geld, Rundung, Datumslogik, parallele Dateizugriffe
tests/serve.sh /tmp/cash-test &                 # lokaler Server http://127.0.0.1:8099/cash/
php tests/api-test.php http://127.0.0.1:8099/cash/ /tmp/cash-test/data
NODE_PATH=$(npm root -g) node tests/ui-test.cjs http://127.0.0.1:8099/cash/ /tmp/screens   # Browser, iPhone/iPad/Desktop
```

Die API- und UI-Tests brauchen jeweils einen frischen, leeren Datenordner.
