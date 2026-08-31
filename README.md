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
    Templates/       die Section-Vorlagen
    Views/           HTML-Vorlagen
    Lang/            alle Texte auf Deutsch und Englisch
  storage/         Projekte, ZIPs, Vorschauen, Protokolle

frontend/        Quellcode der sichtbaren Website (Vite, three.js)
customer-kit/    Bausteine der erzeugten Kundenwebsites
tests/           automatische Tests
build.php        erzeugt das Auslieferungs-ZIP
```

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
| `php tests/run.php` | alle automatischen Tests |
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
5. Auf der öffentlichen Website steht nirgends, wie die Websites entstehen.
