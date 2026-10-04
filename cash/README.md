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
5. **https://reymond-tobias.ch/cash** öffnen und den eigenen Account einrichten
   (Name, Benutzername, Passwort mit mind. 10 Zeichen).
6. Danach fragt CASH die **aktuellen Kontostände** von *Privatkonto* und *E-Jugendsparkonto* ab
   (0 ist erlaubt) und bereitet das **Thailand-Budget** vor: CHF 315.00 verfügbares Restbudget ab heute,
   Eingabewährung THB. Beim ersten THB-Betrag fragst du den Kurs «THB pro CHF» selbst ein.

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
Die ZIP enthält **keine** Daten und keine `config.local.php` – Accounts, Buchungen und Einstellungen
bleiben erhalten. Dateien der Hauptwebsite werden nie berührt (alles bleibt in `/cash`).

---

## Funktionen

| Bereich | Inhalt |
|---|---|
| **Übersicht** | Kontostand aller Konten, Einnahmen/Ausgaben des gewählten Monats, noch unbezahlte Rechnungen, ein Diagramm (kumulierter Monatsverlauf), aktive Budgets mit Restbetrag, Schnellknöpfe Einnahme/Ausgabe |
| **Buchungen** | Einnahmen, Ausgaben, Umbuchungen; Bearbeiten/Löschen; Suche; Filter nach Monat, Typ, Konto, Kategorie; CSV-Export |
| **Budgets** | Monatsbudgets pro Kategorie, freie Budgets mit optionalem Zeitraum, Ferienbudgets mit Zusatzwährung (THB, EUR, USD, GBP) |
| **Rechnungen** | einmalig oder wiederkehrend (wöchentlich … jährlich), Offen / Überfällig / Bezahlt, «Als bezahlt markieren» mit Datum und Konto, Zurücknehmen, Überspringen |
| **Sparziele** | Zielbetrag, Zieldatum, Zuweisen/Entnehmen mit Verlauf, Fortschritt |
| **Schulden** | «Ich schulde anderen» / «Andere schulden mir», Teilzahlungen, Restbetrag, Verlauf |
| **Konten** | anlegen, bearbeiten, archivieren, löschen (nur ohne Buchungen) |
| **Einstellungen** | Profil, Passwort, Kategorien, Wechselkurse, angemeldete Geräte, Registrierung, Backup/Wiederherstellung, CSV |

### Rechenregeln

- **Kontostand** = Anfangssaldo + Einnahmen − Ausgaben ± Umbuchungen ± Darlehensbewegungen.
- **Anfangssalden, Umbuchungen, Darlehen und Rückzahlungen** zählen nie als Einnahme oder Ausgabe.
- **Budgets und Sparziele** verändern keine Kontostände. Eine Ausgabe einem Budget zuzuordnen erzeugt keine zweite Buchung.
- **Monatsbudget** = Ausgaben der Kategorie im Monat (ohne Ausgaben, die einem anderen Budget zugeordnet sind).
- **Fremdwährung**: CHF = Betrag ÷ Kurs (Fremdwährung pro CHF), kaufmännisch auf ganze Rappen gerundet.
  Gespeichert werden Originalbetrag, Währung, Kurs und CHF-Betrag. Spätere Kursänderungen ändern alte Buchungen nicht.
- **Wiederkehrende Rechnungen** erzeugen fehlende Fälligkeiten beim nächsten Öffnen nach (ohne Duplikate,
  Monatsende korrekt, z. B. 31. → 28. Feb. → 31. März). Bezahlt wird nie automatisch.
- **Als bezahlt markieren** erzeugt genau eine verknüpfte Ausgabe; Änderungen an dieser Ausgabe werden in die
  Rechnung übernommen, Löschen oder «Zurücknehmen» setzt die Rechnung wieder auf offen.
- **Schulden**: Ursprung und Zahlungen erzeugen nur dann eine Kontobewegung, wenn du ein Konto wählst
  (z. B. «Nein» wenn jemand dein Essen bezahlt hat). Überzahlungen werden abgelehnt.

## Sicherheit

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
