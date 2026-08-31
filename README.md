# WebAtze

Moderne Websites aus der Schweiz – die eigene Website von WebAtze samt
verstecktem Bereich, in dem neue Kundenwebsites entstehen.

> **Kurzfassung:** ZIP herunterladen, im cPanel-Dateimanager nach
> `public_html` entpacken, `install.php` im Browser aufrufen, fertig.
> Die ausführliche Anleitung steht in [`docs/installation.md`](docs/installation.md).

## Aufbau

```
public_html/     das, was auf den Server kommt
  index.php        nimmt jede Anfrage entgegen
  worker.php       arbeitet Aufträge ab (per Cronjob)
  install.php      einmalige Einrichtung, löscht sich danach selbst
  assets/          fertig gebautes Frontend (nicht von Hand ändern)
  app/             der Programmcode
    Core/            Router, Datenbank, Sitzungen, Sicherheit, Sprachen
    Http/            die einzelnen Seiten und Schnittstellen
    Ai/              Anbindung an Claude
    Build/           Website-Erzeugung, ZIP, Upload, Domainumzug
    Domain/          das Formular als geprüfte Daten (Brief)
    Templates/       die Section-Vorlagen und ihr Schema
    Views/           HTML-Vorlagen der eigenen Seiten
    Kit/             was in die Kundenwebsites eingebaut wird
      site/            Stylesheets, Skript, Kontaktformular, Zähler,
                       Hilfeseite, Terminbuchung
      admin/           der Bearbeitungsbereich beim Kunden
    Lang/            alle Texte auf Deutsch und Englisch
    Support/         Anbieter-Anleitungen und kleine Hilfen
  storage/         Projekte, ZIPs, Vorschauen, Protokolle

frontend/        Quellcode der sichtbaren Website (Vite, three.js)
docs/            Einrichtung Schritt für Schritt
tests/           der Testlauf
build.php        erzeugt das Auslieferungs-ZIP
```

## Wie eine Kundenwebsite entsteht

```
Formular (/create/neu)
      │
      ▼   Auftrag in der Datenbank, worker.php arbeitet ihn ab
analyse → plan → theme → inhalte → bilder → sprachen → bauen → pruefen
      │                                                            │
      │                                    vorschau → zip → referenz
      │                                        │        │
      │                                        │        └─ liegt im Adminbereich bereit
      │                                        └─ /vorschau/<kennung>, 24 Stunden
      └─ alte Website lesen (nur Texte und Bilder)
```

Jeder Schritt hat ein Zeitbudget von etwa 50 Sekunden und macht dort
weiter, wo er aufgehört hat. So läuft nichts in das Zeitlimit des
Shared-Hostings.

Claude liefert **strukturierte Daten**, kein HTML: Seitenaufbau,
Abschnitte, Texte, Farbtokens. Daraus baut PHP die Seite aus geprüften
Vorlagen. Deshalb ist das Ergebnis verlässlich responsiv – und deshalb
kann eine Änderung an einem Abschnitt den Rest der Website nicht
berühren.

**Eine Datenbank bekommt eine erzeugte Website nie von sich aus.** Nur
wenn im Zusatzinfo-Feld etwas beschrieben ist, das etwas speichern muss,
entsteht ein Verwaltungssystem dazu – und auch das ohne Datenbank: Die
Daten liegen als Datei neben der Website.

### Was zusätzlich mitgeliefert werden kann

Alles davon wird im Formular einzeln angehakt. Was nicht angehakt ist,
wird nicht mitgeliefert – keine Datei, keine Zeile im Quelltext.

| Im Formular | Beim Kunden | Was es tut |
|---|---|---|
| Bearbeitungsbereich | `/admin` | Texte, Bilder, Abschnitte selbst ändern |
| Besucher zählen | `/zaehler.php` | Pixel-Zählung auf dem eigenen Server, Wochenbericht per E-Mail |
| Hilfeseite | `/support` | Fragen stellen, Antwort erscheint auf derselben Seite |
| Anleitung schreiben | `/doc` | Anleitung in einfacher Sprache, aus dem Gebauten erzeugt |
| Termine (über Zusatzinfos) | `/buchen` | Terminbuchung mit Öffnungszeiten und Übersicht im Bereich des Kunden |

Die Besucherzählung braucht **keinen Zustimmungsbanner**: Gezählt wird
auf dem Server des Kunden, die Kennung entsteht aus einem Salz, das jede
Nacht wechselt, und zu WebAtze wandern nur Summen.

### Nach dem Bauen wird geprüft

Vier Prüfungen laufen am fertigen Ergebnis, nicht am Bauplan, und stehen
danach beim Projekt: **Tempo** (Gewicht der Seite, springende Bilder,
bremsende Skripte), **Verweise** (interne und fremde), **Rechtliches**
(Impressum, Datenschutz, eingebundene Fremddienste) und
**Rechtschreibung**. Keine davon kann einen Auftrag scheitern lassen –
der Kunde bekäme sonst wegen eines Tippfehlers gar keine Website.

### Was von selbst weiterläuft

`worker.php` erledigt stündlich mehr als nur die Aufträge:

* **Sicherungen** – einmal am Tag WebAtze selbst (Datenbank als SQL plus
  Uploads), bei jedem Durchgang zusätzlich die Kundenwebsite, deren
  Sicherung am längsten zurückliegt. Aufbewahrt werden 14 Tage und je
  der Monatserste der letzten sechs Monate. `app/config.php` ist
  absichtlich nicht dabei: Läge der Schlüssel in der Sicherung, wäre die
  Verschlüsselung der FTP-Zugänge wertlos.
* **Besucherzahlen** – einmal täglich bei jeder Website abgeholt,
  montags als Bericht verschickt.
* **Wartungsverträge** – am Fälligkeitstag entsteht ein
  Rechnungsentwurf. Verschickt wird er von Hand.

## Entwickeln

Voraussetzung: PHP 8.1+, Node 22+.

```bash
# Frontend bauen (erzeugt public_html/assets)
cd frontend && npm install && npm run build && cd ..

# Konfiguration anlegen
cp public_html/app/config.example.php public_html/app/config.php
# darin driver auf 'sqlite' stellen und die beiden Schlüssel erzeugen:
php -r 'echo bin2hex(random_bytes(32)), "\n";'

# Server starten
php -S 127.0.0.1:8080 -t public_html public_html/index.php
```

| Befehl | Wofür |
|---|---|
| `php tests/run.php` | der Testlauf (754 Prüfungen) |
| `php build.php` | Auslieferungs-ZIP erzeugen |
| `cd frontend && npm run build` | Frontend neu bauen |
| `cd frontend && npm run dev` | Frontend mit Sofortaktualisierung |

## Regeln

1. **Farbwerte stehen ausschliesslich in `frontend/src/styles/tokens.css`.**
   Überall sonst `var(--wa-...)`.
2. **Kein Text fest im Code.** Alle Texte in `public_html/app/Lang/de.php`
   und `en.php`.
3. **Jede Ausgabe läuft durch `e()`.** Auch das, was von der KI kommt.
4. **`app/config.php` wird niemals committet.** Nur die Beispieldatei.
5. Auf der öffentlichen Website steht nirgends, wie die Websites entstehen –
   und keine einzige Preiszahl. Beides prüft der Testlauf.
6. **Der Anthropic-Schlüssel verlässt diesen Server nicht.** Das
   Kundenbackend fragt über `/assistant/v1/edit` hier an; beim Kunden
   liegt nur ein Kennwort, das für eine einzige Website gilt.
7. Beim Kunden heisst es **„WebAtze-Assistent"**, nie Claude. Auch in der
   Anleitung unter `/doc` steht kein Wort darüber, wie die Website
   entstanden ist. Der Testlauf prüft das.
8. **`robots.txt` nennt den versteckten Bereich nicht.** Wer wissen will,
   wo er liegt, müsste sonst nur eine Datei öffnen. Die Adressen sind
   ohnehin `noindex`; das ist die stärkere Zusage. Auch das prüft der
   Testlauf – die Zeile kann nicht unbemerkt zurückkommen.
9. **Geld steht überall in Rappen, als ganze Zahl.** Fliesskomma und Geld
   gehören nicht zusammen; bei einer Rechnung merkt man das erst, wenn
   sich der Kunde meldet.
