// Schreibt die Zuordnungstabelle der Modul-Adressen in die HTML-Seiten.
//
// WARUM ES DAS GIBT
// Die Versionsnummer hing nur an der Einstiegsdatei: <script src="src/main.js
// ?v=2.0.2">. Die Dateien, die sie ihrerseits lädt, wurden unter ihrer blanken
// Adresse geholt – und damit aus dem Zwischenspeicher des Browsers, egal wie
// alt der Eintrag dort war. Eine neue Seite traf so auf alte Module. Das
// Ergebnis sieht aus wie ein kaputter Knopf und war genau der gemeldete Fehler.
//
// Eine importmap hängt die Version an JEDE Adresse. Damit kann ein alter
// Eintrag gar nicht mehr getroffen werden – niemand muss etwas löschen, und es
// hängt auch nicht daran, ob der Hoster die .htaccess beachtet.
//
// ZWEI SEITEN, ZWEI TABELLEN. Beim ersten Anlauf hatte nur das Spiel eine, und
// der Admin-Bereich behielt den Fehler: eine importmap gilt je Dokument, nicht
// je Ordner. Deshalb wird hier nicht mehr pauschal alles unter src/
// aufgelistet, sondern für jede Seite genau das, was sie wirklich lädt – dem
// Modulbaum von ihrer Einstiegsdatei aus gefolgt. Was keine Seite lädt, taucht
// dann auch nirgends auf, und was eine Seite lädt, kann nicht mehr vergessen
// werden.
//
// Browser ohne importmap (vor Safari 16.4) ignorieren den Block einfach; dort
// bleibt es beim bisherigen Verhalten, schlechter wird es also nicht.
//
// Aufruf:  node games/mogli/tools/make-importmap.mjs

import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, join, posix } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const WEB = join(HERE, '..', 'web');

const ANFANG = '    <!-- importmap:anfang -->';
const ENDE = '    <!-- importmap:ende -->';

/** Die Seiten mit Modulen. Schlüssel ist die HTML-Datei relativ zu web/. */
export const SEITEN = [
  { html: 'index.html', einstieg: 'src/main.js' },
  { html: 'admin/index.html', einstieg: 'admin/src/main.js' },
];

/**
 * Alle Module, die von einer Einstiegsdatei aus erreichbar sind.
 *
 * Gelesen wird der Quelltext, nicht das Verzeichnis: eine Datei, die niemand
 * importiert, gehört in keine Tabelle, und eine, die importiert wird, darf
 * nicht fehlen. Beides von Hand zu pflegen geht schief.
 *
 * @returns {string[]} Pfade relativ zu web/, aufsteigend sortiert
 */
export function modulBaum(einstieg, webDir = WEB) {
  const gesehen = new Set([einstieg]);
  const offen = [einstieg];

  while (offen.length > 0) {
    const datei = offen.shift();
    let text;
    try {
      text = readFileSync(join(webDir, datei), 'utf8');
    } catch {
      throw new Error(`${datei} wird importiert, ist aber nicht da`);
    }
    for (const treffer of text.matchAll(/(?:from|import)\s+['"](\.[^'"]+)['"]/g)) {
      const ziel = posix.normalize(posix.join(posix.dirname(datei), treffer[1]));
      if (!gesehen.has(ziel)) {
        gesehen.add(ziel);
        offen.push(ziel);
      }
    }
  }
  return [...gesehen].sort();
}

/** Der fertige Block, so wie er in der Seite stehen muss. */
export function block(version, dateien, htmlDatei) {
  const basis = posix.dirname(htmlDatei);
  const zeilen = dateien.map((datei) => {
    // Relativ zur SEITE, nicht zur Wurzel: die Schlüssel einer importmap
    // werden gegen die Adresse des Dokuments aufgelöst.
    let pfad = basis === '.' ? datei : posix.relative(basis, datei);
    if (!pfad.startsWith('.')) pfad = `./${pfad}`;
    return `        "${pfad}": "${pfad}?v=${version}"`;
  });
  return [
    ANFANG,
    '    <script type="importmap">',
    '      {',
    '        "imports": {',
    zeilen.join(',\n'),
    '        }',
    '      }',
    '    </script>',
    ENDE,
  ].join('\n');
}

export function version(webDir = WEB) {
  const treffer = readFileSync(join(webDir, 'src', 'version.js'), 'utf8').match(
    /VERSION = '([^']+)'/,
  );
  if (treffer === null) throw new Error('version.js ohne VERSION');
  return treffer[1];
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  const v = version();
  for (const seite of SEITEN) {
    const pfad = join(WEB, ...seite.html.split('/'));
    const html = readFileSync(pfad, 'utf8');
    const von = html.indexOf(ANFANG);
    const bis = html.indexOf(ENDE);
    if (von < 0 || bis < 0) {
      throw new Error(`Die Marken importmap:anfang/ende fehlen in ${seite.html}`);
    }
    const dateien = modulBaum(seite.einstieg);
    const neu = html.slice(0, von) + block(v, dateien, seite.html) + html.slice(bis + ENDE.length);
    writeFileSync(pfad, neu);
    console.log(`${seite.html.padEnd(18)} ${dateien.length} Module auf Version ${v}`);
  }
}
