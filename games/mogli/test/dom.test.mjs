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

const here = dirname(fileURLToPath(import.meta.url));
const webDir = join(here, '..', 'web');
const html = readFileSync(join(webDir, 'index.html'), 'utf8');

/** Alle .js-Dateien unter web/src, samt Inhalt. */
function sourceFiles(dir = join(webDir, 'src')) {
  const out = [];
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const path = join(dir, entry.name);
    if (entry.isDirectory()) out.push(...sourceFiles(path));
    else if (entry.name.endsWith('.js')) out.push({ path, text: readFileSync(path, 'utf8') });
  }
  return out;
}

const sources = sourceFiles();
const allSource = sources.map((file) => file.text).join('\n');

function matchAll(text, pattern) {
  return [...text.matchAll(pattern)].map((match) => match[1]);
}

test('jede id, die das JavaScript sucht, steht im HTML', () => {
  const present = new Set(matchAll(html, /\bid="([^"]+)"/g));

  const wanted = new Set([
    ...matchAll(allSource, /\$\('([^']+)'\)/g),
    ...matchAll(allSource, /getElementById\('([^']+)'\)/g),
  ]);

  const missing = [...wanted].filter((id) => !present.has(id)).sort();
  assert.deepEqual(missing, [], `im HTML fehlt: ${missing.join(', ')}`);
});

test('jede id im HTML kommt genau einmal vor', () => {
  const ids = matchAll(html, /\bid="([^"]+)"/g);
  const seen = new Set();
  const doubled = ids.filter((id) => (seen.has(id) ? true : (seen.add(id), false)));
  assert.deepEqual(doubled, [], `doppelt vergeben: ${doubled.join(', ')}`);
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
