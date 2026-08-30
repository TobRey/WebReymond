// Schreibt die Zuordnungstabelle der Modul-Adressen in web/index.html.
//
// WARUM ES DAS GIBT
// Die Versionsnummer hing nur an main.js: <script src="src/main.js?v=2.0.2">.
// Die 31 Dateien, die main.js seinerseits lädt, wurden unter ihrer blanken
// Adresse geholt – und damit aus dem Zwischenspeicher des Browsers, egal wie
// alt der Eintrag dort war. Eine neue index.html traf so auf alte Module. Das
// Ergebnis sieht aus wie ein kaputter Startknopf und war genau der gemeldete
// Fehler.
//
// Eine importmap hängt die Version an JEDE Adresse. Damit kann ein alter
// Eintrag im Zwischenspeicher gar nicht mehr getroffen werden – niemand muss
// etwas löschen, und es hängt auch nicht daran, ob der Hoster die .htaccess
// beachtet.
//
// Browser ohne importmap (vor Safari 16.4) ignorieren den Block einfach; dort
// bleibt es beim bisherigen Verhalten, schlechter wird es also nicht.
//
// Aufruf:  node games/mogli/tools/make-importmap.mjs

import { readFileSync, readdirSync, writeFileSync } from 'node:fs';
import { dirname, join, relative, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const WEB = join(HERE, '..', 'web');

const ANFANG = '    <!-- importmap:anfang -->';
const ENDE = '    <!-- importmap:ende -->';

/** Alle .js-Dateien unter web/src, als Adressen relativ zu index.html. */
export function moduleDateien(webDir = WEB) {
  const out = [];
  const gehe = (dir) => {
    for (const eintrag of readdirSync(dir, { withFileTypes: true }).sort((a, b) =>
      a.name < b.name ? -1 : 1,
    )) {
      const voll = join(dir, eintrag.name);
      if (eintrag.isDirectory()) gehe(voll);
      else if (eintrag.name.endsWith('.js')) out.push(relative(webDir, voll).split(sep).join('/'));
    }
  };
  gehe(join(webDir, 'src'));
  return out;
}

/** Der fertige Block, so wie er in index.html stehen muss. */
export function block(version, dateien) {
  const zeilen = dateien.map((d) => `        "./${d}": "./${d}?v=${version}"`);
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
  const pfad = join(WEB, 'index.html');
  const html = readFileSync(pfad, 'utf8');
  const von = html.indexOf(ANFANG);
  const bis = html.indexOf(ENDE);
  if (von < 0 || bis < 0) throw new Error('Die Marken importmap:anfang/ende fehlen in index.html');
  const dateien = moduleDateien();
  const neu = html.slice(0, von) + block(version(), dateien) + html.slice(bis + ENDE.length);
  writeFileSync(pfad, neu);
  console.log(`${dateien.length} Module auf Version ${version()} gesetzt`);
}
