// Die Verdrahtung zwischen index.html und dem JavaScript.
//
// Diese Datei gibt es wegen eines echten Fehlers: beim Umbau auf die
// bildschirmfüllende Fassung fielen Elemente aus dem HTML, die main.js noch
// abfragte. Das Ergebnis war eine Seite, auf der die Bestenliste stand und das
// Spiel fehlte – ohne dass irgendein Test etwas gemerkt hätte, denn ein
// fehlendes getElementById ergibt nur `null` und stirbt erst beim Zugriff.
//
// Hier wird deshalb nachgerechnet, statt zu vertrauen: jede id, die das
// JavaScript sucht, muss im HTML stehen; jeder Übersetzungsschlüssel, den
// HTML oder JavaScript nennen, muss im Wörterbuch stehen – und umgekehrt darf
// kein Schlüssel im Wörterbuch verwaisen.

import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

import { DICT } from '../web/src/i18n.js';
import { VERSION } from '../web/src/version.js';
import { moduleDateien } from '../tools/make-importmap.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const webDir = join(here, '..', 'web');

/** Alle .js-Dateien unter einem Verzeichnis, samt Inhalt. */
function sourceFiles(dir) {
  const out = [];
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const path = join(dir, entry.name);
    if (entry.isDirectory()) out.push(...sourceFiles(path));
    else if (entry.name.endsWith('.js')) out.push({ path, text: readFileSync(path, 'utf8') });
  }
  return out;
}

const read = (...parts) => readFileSync(join(webDir, ...parts), 'utf8');
const joined = (dir) =>
  sourceFiles(join(webDir, ...dir))
    .map((file) => file.text)
    .join('\n');

const html = read('index.html');
const allSource = joined(['src']);

/**
 * Die beiden Seiten des Auslieferungsordners. Beide werden gleich streng
 * geprüft – der Admin-Bereich hat mehr Elemente als das Spiel und damit mehr
 * Gelegenheit, eine id zu verlieren.
 */
const PAGES = [
  { name: 'Spiel', html, source: allSource },
  { name: 'Admin', html: read('admin', 'index.html'), source: joined(['admin', 'src']) },
];

function matchAll(text, pattern) {
  return [...text.matchAll(pattern)].map((match) => match[1]);
}

test('jede id, die das JavaScript sucht, steht im HTML', () => {
  for (const page of PAGES) {
    const present = new Set(matchAll(page.html, /\bid="([^"]+)"/g));

    const wanted = new Set([
      ...matchAll(page.source, /\$\('([^']+)'\)/g),
      ...matchAll(page.source, /getElementById\('([^']+)'\)/g),
    ]);

    const missing = [...wanted].filter((id) => !present.has(id)).sort();
    assert.deepEqual(missing, [], `${page.name}: im HTML fehlt ${missing.join(', ')}`);
  }
});

test('jede id im HTML kommt genau einmal vor', () => {
  for (const page of PAGES) {
    const ids = matchAll(page.html, /\bid="([^"]+)"/g);
    const seen = new Set();
    const doubled = ids.filter((id) => (seen.has(id) ? true : (seen.add(id), false)));
    assert.deepEqual(doubled, [], `${page.name}: doppelt vergeben ${doubled.join(', ')}`);
  }
});

test('die Versionsnummer steht in LIESMICH.txt genauso wie im Spiel', () => {
  // Zwei Stellen, die niemand gleichzeitig im Kopf hat. Wer die eine hochzählt
  // und die andere vergisst, liefert eine ZIP aus, die sich selbst falsch
  // benennt – und beim Fehlersuchen ist genau das die Zahl, nach der man fragt.
  const liesmich = read('LIESMICH.txt');
  const first = liesmich.split('\n', 1)[0];
  assert.ok(
    first.includes(VERSION),
    `LIESMICH.txt beginnt mit "${first}", das Spiel ist aber Version ${VERSION}`,
  );
});

test('die Modul-Adresse in index.html trägt dieselbe Version', () => {
  // src/main.js?v=… ist die Notbremse gegen einen Browser-Zwischenspeicher, in
  // dem eine neue index.html auf ein altes main.js trifft. Bleibt die Zahl beim
  // Hochzählen stehen, tut die Notbremse still gar nichts mehr – und der
  // Fehler, den sie verhindern soll, sieht aus wie ein kaputter Startknopf.
  const found = html.match(/src="src\/main\.js\?v=([^"]+)"/);
  assert.ok(found !== null, 'index.html lädt src/main.js ohne ?v=Version');
  assert.equal(
    found[1],
    VERSION,
    `index.html lädt main.js?v=${found[1]}, das Spiel ist aber Version ${VERSION}`,
  );
});

test('der Meldungsstreifen ist im HTML vollständig angelegt', () => {
  // Er ist die einzige Möglichkeit, von einem fremden Handy zu erfahren, warum
  // das Spiel nicht startet. Fällt eine seiner Klassen aus dem HTML, schreibt
  // der Fänger ins Leere und man steht wieder vor „es passiert nichts".
  for (const needle of ['id="crash"', 'crash__list', 'crash__line', 'id="crashReload"']) {
    assert.ok(html.includes(needle), `index.html fehlt ${needle}`);
  }
  // Und das Gegenstück: ohne diese Regel schlägt `display` das `hidden`, und
  // der Streifen stünde bei jedem Start da. Genau diese Falle hat hier schon
  // zweimal zugeschlagen.
  assert.match(read('style.css'), /\.crash\[hidden\]\s*\{\s*display:\s*none/);
});

test('die importmap nennt jede Moduldatei mit der aktuellen Version', () => {
  // Sie ist der Grund, warum ein alter Stand im Zwischenspeicher nicht mehr
  // getroffen werden kann. Fehlt eine Datei darin, wird genau die wieder unter
  // ihrer blanken Adresse geholt - und ein einziges altes Modul reicht, damit
  // das Spiel stumm im Menue stehen bleibt. Das war der gemeldete Fehler.
  const gefunden = new Map(
    matchAll(html, /"\.\/(src\/[^"]+\.js)":\s*"[^"]*"/g).map((datei) => [datei, true]),
  );
  const erwartet = moduleDateien(webDir);
  assert.deepEqual(
    [...gefunden.keys()].sort(),
    [...erwartet].sort(),
    'importmap und Dateien auf der Platte weichen ab - node games/mogli/tools/make-importmap.mjs',
  );

  for (const ziel of matchAll(html, /"\.\/src\/[^"]+\.js":\s*"\.\/[^"?]+\?v=([^"]+)"/g)) {
    assert.equal(ziel, VERSION, `die importmap zeigt auf ?v=${ziel}, das Spiel ist ${VERSION}`);
  }
  assert.equal(
    matchAll(html, /"\.\/src\/[^"]+\.js":\s*"\.\/[^"?]+\?v=([^"]+)"/g).length,
    erwartet.length,
    'in der importmap fehlt an einem Ziel das ?v=',
  );

  // Die Reihenfolge im HTML ist keine Kosmetik: eine importmap nach dem ersten
  // Modul-Skript wird vom Browser verworfen.
  assert.ok(
    html.indexOf('type="importmap"') < html.indexOf('type="module"'),
    'die importmap muss vor dem Modul-Skript stehen',
  );
});

test('die Pruefseite bleibt in altem JavaScript', () => {
  // pruefung.html gibt es, damit sie auch dort noch laeuft, wo das Spiel selbst
  // schon an der ersten Zeile scheitert - ein zu alter Browser, ein Server, der
  // Module verweigert. Schleicht sich hier moderne Schreibweise ein, faellt die
  // Seite genau in dem Fall aus, fuer den es sie gibt: sie wuerde stumm bleiben
  // und man stuende wieder vor "es passiert nichts".
  const seite = read('pruefung.html');
  const script = seite.slice(seite.indexOf('<script>'), seite.lastIndexOf('</script>'));
  assert.ok(script.length > 2000, 'das Skript in pruefung.html wurde nicht gefunden');

  const verboten = [
    [/=>/, 'Pfeilfunktion'],
    [/\b(const|let)\s/, 'const/let'],
    [/`/, 'Vorlagenzeichenkette'],
    [/\?\./, 'optionale Verkettung'],
    [/\?\?/, 'Nullish-Operator'],
    [/\bfetch\s*\(/, 'fetch (XMLHttpRequest benutzen)'],
    [/\bclass\s+\w/, 'class'],
  ];
  for (const [muster, name] of verboten) {
    assert.ok(
      !muster.test(script),
      `pruefung.html benutzt ${name} - das muss altes JavaScript bleiben`,
    );
  }

  // Und sie darf nichts nachladen: sonst haengt die Fehlersuche an einem
  // fremden Server, der womoeglich gerade das Problem ist.
  assert.ok(!/src\s*=\s*["']https?:/.test(seite), 'pruefung.html laedt etwas von aussen nach');
});

test('der Admin-Bereich fragt nur Elemente ab, die es auch gibt', () => {
  // Zusätzlich zu den ids: die Reiter werden über data-tab gefunden, und ein
  // Tippfehler dort bliebe sonst still.
  const adminHtml = read('admin', 'index.html');
  const tabs = new Set(matchAll(adminHtml, /data-tab="([^"]+)"/g));
  const panels = new Set(matchAll(adminHtml, /\bid="tab-([^"]+)"/g));
  assert.deepEqual([...tabs].sort(), [...panels].sort(), 'Reiter und Tafeln passen nicht zusammen');
});

/** Alle Schlüssel, die irgendwo genannt werden – im HTML wie im JavaScript. */
function usedKeys() {
  const keys = new Set(matchAll(html, /\bdata-i18n="([^"]+)"/g));

  for (const group of matchAll(html, /\bdata-i18n-attr="([^"]+)"/g)) {
    for (const pair of group.split(',')) {
      const key = pair.split(':')[1];
      if (key !== undefined) keys.add(key.trim());
    }
  }

  // Nicht nur t('…') absuchen: Schlüssel stehen auch in Variablen
  // (updateTapHint) und in Datentabellen (game/story.js). Deshalb zählt jede
  // Zeichenkette im Quelltext, die wie ein Schlüssel aussieht – begrenzt auf
  // die Namensräume des Wörterbuchs, sonst zählten die Speicherschlüssel
  // (mogli.lang und Verwandte) mit. Ein Tippfehler IN einem Namensraum fällt
  // damit weiterhin auf.
  const namespaces = new Set(Object.keys(DICT.de).map((key) => key.split('.')[0]));
  for (const key of matchAll(allSource, /'([a-z][\w]*(?:\.[\w]+)+)'/g)) {
    if (namespaces.has(key.split('.')[0])) keys.add(key);
  }

  // Zusammengesetzt zur Laufzeit: nameKey() aus dem Namen des Wesens und
  // `error.${code}` aus der Antwort des Servers.
  for (const key of Object.keys(DICT.de)) {
    if (key.startsWith('story.name.') || key.startsWith('error.')) keys.add(key);
  }

  return keys;
}

test('jeder genannte Schlüssel steht in beiden Wörterbüchern', () => {
  const missing = [...usedKeys()].filter((key) => !(key in DICT.de) || !(key in DICT.en)).sort();
  assert.deepEqual(missing, [], `nicht übersetzt: ${missing.join(', ')}`);
});

test('kein Schlüssel im Wörterbuch ist verwaist', () => {
  const used = usedKeys();
  const orphans = Object.keys(DICT.de)
    .filter((key) => !used.has(key))
    .sort();
  assert.deepEqual(orphans, [], `wird nirgends verwendet: ${orphans.join(', ')}`);
});
