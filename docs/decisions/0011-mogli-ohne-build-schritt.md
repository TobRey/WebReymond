# 0011 – Mogli als eigenständiger Ordner ohne Build-Schritt

**Status:** akzeptiert (August 2026) – zur Spielgestaltung siehe zusätzlich
[0012](0012-ein-knopf-steuerung.md)

## Kontext
Gewünscht war ein 2D-Jump'n'Run mit animiertem Pixel-Art-Charakter, das als **ZIP-Datei auf eine
beliebige Website** hochgeladen werden kann. Ausdrücklich **ohne Datenbank**, aber mit einer
**weltweiten Bestenliste**, mit Doppelsprung und Wandsprung, und mit dem Ziel „so hoch wie
möglich".

Das Repository ist ein pnpm/TypeScript-Monorepo für die Hosting-Plattform WebHeaven: Next.js im
Portal, Fastify und PostgreSQL in der API, Design-Tokens in `packages/ui`. Ein Spiel passt
fachlich in keinen dieser Bausteine, und die Zielumgebung ist gerade **nicht** unser Server,
sondern irgendein fremder Webspace.

## Entscheidung
1. Das Spiel liegt unter **`games/mogli/`** und ist **kein Workspace-Paket**. Es hat kein
   `package.json`, keine Abhängigkeiten und keinen Build-Schritt – reines HTML, CSS und
   ES-Module.
2. Die Bestenliste läuft über ein **einzelnes PHP-Skript mit flacher JSON-Datei**
   (`score.php` → `data/scores.json`), nicht über die vorhandene API und nicht über PostgreSQL.
   Fehlt PHP, fällt der Client dauerhaft auf `localStorage` zurück und sagt das sichtbar.
3. Ausgeliefert wird über **`pnpm game:zip`**; das Skript `pack.mjs` schreibt die ZIP-Datei mit
   Node-Bordmitteln (`node:zlib` plus PKZIP-Kopfdaten von Hand).
4. Der Charakter besteht aus **Zeichenketten im Quelltext**, nicht aus PNG-Dateien.
5. Berührung mit dem Monorepo: vier Skripte in `package.json`, zwei Blöcke in
   `eslint.config.mjs`, zwei Zeilen in `.gitignore`. Sonst nichts.

## Begründung
- **Die Zielumgebung bestimmt die Bauweise.** Ein Next.js-Build wäre auf einem Wald-und-Wiesen-
  Webspace nicht lauffähig. Ohne Build gilt: entpacken, fertig.
- **„Keine Datenbank" war eine Vorgabe, keine Vereinfachung.** PHP mit einer flachen Datei ist
  der kleinste gemeinsame Nenner klassischer Hoster. Jeder Schreibvorgang läuft unter `flock()`
  und endet mit einem atomaren `rename()`.
- **Kein Workspace-Paket heisst keine Lockfile-Änderung.** `pnpm install --frozen-lockfile` in
  der CI kann daran nicht scheitern. `pnpm -r build/typecheck` fasst den Ordner ohnehin nicht an,
  `eslint .` dagegen schon – deshalb die Globals-Blöcke.
- **Eigener ZIP-Schreiber statt `zip`-Kommando oder Bibliothek.** Das Kommando fehlt unter
  Windows, und die Entwicklungsumgebung dieses Projekts ist laut
  `docs/phase-2-entwicklungsumgebung.md` Windows 11 mit WSL2. Eine Bibliothek wäre ein
  Abhängigkeitsbaum für eine Datei von 60 kB.
- **Keine Binärdaten.** Das Repository enthält bis heute keine einzige Bilddatei. Sprites als
  Zeichenketten halten das so und machen jede Änderung am Charakter im Diff lesbar.

## Alternativen
- **Seite im Portal unter `/de/spiel`:** hätte Design-Tokens und `next-intl` mitgenutzt, wäre
  aber nicht als ZIP auslieferbar gewesen – die eigentliche Anforderung.
- **Bestenliste über `apps/api` und PostgreSQL:** technisch sauberer und fälschungssicherer,
  widerspricht aber „keine DB" und macht die ZIP-Datei von unserem Server abhängig.
- **Externer Gratis-Dienst (z. B. dreamlo):** kein eigener Servercode, dafür ein fremder
  Anbieter in der Verantwortungskette und ein Schlüssel im Client.
- **Nur lokale Bestenliste:** kein Server nötig, aber eben nicht weltweit.
- **Canvas-Text statt DOM-Anzeige:** hätte eine eigene Pixelschrift gebraucht und wäre weder
  übersetzbar noch für Vorleseprogramme lesbar.

## Konsequenzen
- **Ausnahme zur Regel aus ADR 0010** („kein `#hex` ausserhalb von `tokens.css`"): Das Spiel kann
  ohne Build keine CSS-Variablen aus `packages/ui` beziehen. Es bringt deshalb eine eigene,
  ebenso zentralisierte Farbquelle mit – `web/src/render/palette.js` für die Spielgrafik und die
  `--hc-*`-Variablen in `web/style.css` für den Rahmen. Ausserhalb dieser beiden Dateien stehen
  auch hier keine Hex-Werte. Die Ausnahme gilt nur für `games/`.
- **`pnpm test` bedeutet jetzt Portal, API und Spiel.** Die Spieltests laufen über `node --test`
  ohne neue Abhängigkeit und damit automatisch in der CI mit.
- **Punktestände sind fälschbar.** Der Client rechnet sie aus; ohne serverseitige Nachsimulation
  eines Laufs lässt sich das nicht verhindern. `score.php` prüft Wertebereiche, das Verhältnis
  von Punkten zu Spielzeit und begrenzt Einträge pro IP. Das steht offen im Spiel und in
  `LIESMICH.txt` und wird nicht als Sicherheitsmerkmal verkauft.
- **Kein `typecheck` für den Ordner.** Ohne `tsconfig.json` gibt es dort keine Typprüfung; die
  Absicherung leisten die Tests unter `games/mogli/test/`.
- Ein Ordner ohne Build ist nur so lange wartbar, wie er klein bleibt. Wächst das Spiel deutlich,
  ist das der Zeitpunkt für eine neue Entscheidung.

## Wiedervorlage
Wenn Punktestände einmal verlässlich sein sollen: Der Startwert eines Laufs wird bereits
mitgeführt, und die Physik läuft mit festem Zeitschritt und ohne DOM. Eine serverseitige
Nachsimulation aus Startwert plus Eingabeprotokoll wäre damit möglich – das ist dann aber ein
eigenes Vorhaben und keine Ergänzung dieser Entscheidung.
