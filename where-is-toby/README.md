# WHERE IS TOBY? - Projektordner

Dieser Ordner enthaelt das vollstaendige Projekt des FBI-Horror-Ermittlungsspiels
**WHERE IS TOBY?** sowie die Werkzeuge, mit denen Grafiken, Fallinhalte und das
Installationspaket erzeugt werden.

```
where-is-toby/
├── src/          Die Anwendung selbst - genau dieser Inhalt liegt spaeter in public_html
│   └── README.md Anwenderdokumentation (Installation, Bedienung, Adminbereich)
├── dist/         Fertiges Installationspaket (ZIP) fuer cPanel
├── tools/        Entwicklungswerkzeuge (nicht Teil des Pakets)
│   ├── build_assets.php     erzeugt Favicon, Cover, Portraits, Karte
│   ├── build_scenes.php     erzeugt Tatort- und Objektfotos (SVG)
│   ├── build_cams.php       erzeugt Kamerabilder, Dokumente, Plakat
│   ├── build_case_toby.php  baut den Fall "Toby" aus tools/case/ und prueft ihn
│   ├── build_zip.php        baut und prueft das Installationspaket
│   ├── test_e2e.php         Ende-zu-Ende-Test (installiert, spielt, prueft)
│   ├── test_playthrough.php kompletter Durchlauf des Falls (wird eingebunden)
│   ├── ui/ui_test.mjs       Oberflaechentest mit Chromium (Playwright)
│   ├── router.php           Router fuer den PHP-Entwicklungsserver
│   └── serve.sh             startet/stoppt den lokalen Testserver
└── tools/case/   Quelltexte des Falls "Toby" (Stammdaten, Medien, NPCs, Raetsel ...)
```

## Schnellstart fuer Entwickler

```bash
# 1. Fall und Grafiken erzeugen (nur bei Aenderungen noetig)
php tools/build_assets.php && php tools/build_scenes.php && php tools/build_cams.php
php tools/build_case_toby.php

# 2. Lokale Testinstallation starten
rm -rf /tmp/wit && cp -r src /tmp/wit && cp tools/router.php /tmp/wit/
tools/serve.sh start /tmp/wit 8787

# 3. Tests
php tools/test_e2e.php http://127.0.0.1:8787 /tmp/wit
node tools/ui/ui_test.mjs http://127.0.0.1:8787 /tmp/wit-shots

# 4. Installationspaket bauen
php tools/build_zip.php
```

Die vollstaendige Anwenderdokumentation steht in [`src/README.md`](src/README.md) und
[`src/docs/`](src/docs/).
