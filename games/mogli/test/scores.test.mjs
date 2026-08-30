// Die Regeln der Bestenliste – seit 3.0 Zeiten je Level statt Punkte.

import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

import {
  NAME_MAX,
  TICKS_MAX,
  TICKS_MIN,
  sanitizeName,
  validateEntry,
} from '../web/src/net/scoreRules.js';

const webDir = join(dirname(fileURLToPath(import.meta.url)), '..', 'web');

test('sanitizeName entfernt alles, was HTML werden könnte', () => {
  // Nach dem Entfernen der Zeichen greift zusätzlich die 12-Zeichen-Kappung.
  assert.equal(sanitizeName('<script>alert(1)</script>'), 'scriptalert1');
  assert.equal(sanitizeName('Rey"onmouseover='), 'Reyonmouseov');
  assert.ok(!sanitizeName('<img src=x onerror=alert(1)>').includes('<'));
});

test('sanitizeName kürzt auf die erlaubte Länge', () => {
  assert.equal(sanitizeName('A'.repeat(50)).length, NAME_MAX);
});

test('sanitizeName macht aus Leerem ANON', () => {
  assert.equal(sanitizeName(''), 'ANON');
  assert.equal(sanitizeName('   '), 'ANON');
  assert.equal(sanitizeName(undefined), 'ANON');
  assert.equal(sanitizeName('<<<>>>'), 'ANON');
});

test('sanitizeName lässt normale Namen in Ruhe', () => {
  assert.equal(sanitizeName('Rey_05'), 'Rey_05');
  assert.equal(sanitizeName('Zoë'), 'Zoë');
});

test('validateEntry nimmt einen gültigen Eintrag an', () => {
  const result = validateEntry({ name: ' Rey ', map: 'dschungelpfad', ticks: 4200 });
  assert.equal(result.ok, true);
  assert.deepEqual(result.entry, { name: 'Rey', map: 'dschungelpfad', ticks: 4200 });
});

test('validateEntry weist Unsinn ab', () => {
  const faelle = [
    { map: 'BÖSE/../x', ticks: 500 },
    { map: 'ok', ticks: TICKS_MIN - 1 },
    { map: 'ok', ticks: TICKS_MAX + 1 },
    { map: 'ok', ticks: 500.5 },
    { map: 'ok', ticks: '-500' },
    { map: '', ticks: 500 },
    null,
  ];
  for (const eintrag of faelle) {
    assert.equal(validateEntry(eintrag).ok, false, JSON.stringify(eintrag));
  }
});

test('score.php nennt dieselben Grenzen wie scoreRules.js', () => {
  const php = readFileSync(join(webDir, 'score.php'), 'utf8');
  const zahl = (name) => Number(php.match(new RegExp(`const ${name}\\s*=\\s*(\\d+)`))[1]);
  assert.equal(zahl('NAME_MAX'), NAME_MAX);
  assert.equal(zahl('TICKS_MIN'), TICKS_MIN);
  assert.equal(zahl('TICKS_MAX'), TICKS_MAX);
});
