import test from 'node:test';
import assert from 'node:assert/strict';

import {
  NAME_MAX,
  SCORE_MAX,
  compareEntries,
  isPlausible,
  sanitizeName,
  validateEntry,
} from '../web/src/net/scoreRules.js';

test('sanitizeName entfernt alles, was HTML werden könnte', () => {
  const cleaned = sanitizeName('<img src=x onerror=alert(1)>');
  for (const char of '<>"\'`=()/&;') {
    assert.ok(!cleaned.includes(char), `"${char}" hat überlebt: ${cleaned}`);
  }
  assert.ok(!sanitizeName('<b>REY</b>').includes('<'));
  assert.ok(!sanitizeName('a"b\'c`d').includes('"'));
});

test('sanitizeName kürzt auf die erlaubte Länge', () => {
  const long = sanitizeName('ABCDEFGHIJKLMNOPQRSTUVWXYZ');
  assert.equal(long.length, NAME_MAX);
});

test('sanitizeName kürzt auch mehrbytige Zeichen korrekt', () => {
  const name = sanitizeName('äöüäöüäöüäöüäöü');
  assert.equal([...name].length, NAME_MAX);
});

test('sanitizeName macht aus Leerem ANON', () => {
  assert.equal(sanitizeName('   '), 'ANON');
  assert.equal(sanitizeName('***'), 'ANON');
  assert.equal(sanitizeName(null), 'ANON');
  assert.equal(sanitizeName(42), 'ANON');
});

test('sanitizeName lässt normale Namen in Ruhe', () => {
  assert.equal(sanitizeName(' Rey_01 '), 'Rey_01');
  assert.equal(sanitizeName('Anna-Lena'), 'Anna-Lena');
});

test('isPlausible weist unmögliche Punkte pro Sekunde ab', () => {
  assert.equal(isPlausible(999999, 4), false);
  assert.equal(isPlausible(5000, 10), false);
});

test('isPlausible lässt einen sehr guten Lauf durch', () => {
  // 300 m plus zwölf Edelsteine in zweieinhalb Minuten – ehrlich erreichbar.
  assert.equal(isPlausible(300 + 12 * 25, 150), true);
  assert.equal(isPlausible(60, 20), true);
});

test('validateEntry nimmt einen gültigen Eintrag an', () => {
  const result = validateEntry({ name: ' Rey ', score: 481, time: 96 });
  assert.equal(result.ok, true);
  assert.deepEqual(result.entry, { name: 'Rey', score: 481, time: 96 });
});

test('validateEntry weist kaputte Eingaben ab', () => {
  const bad = [
    null,
    'kein Objekt',
    { score: 0, time: 10 },
    { score: -5, time: 10 },
    { score: 1.5, time: 10 },
    { score: SCORE_MAX + 1, time: 10000 },
    { score: 10, time: 1 },
    { score: 10, time: 99999 },
    { score: 999999, time: 4 },
  ];
  for (const entry of bad) {
    assert.equal(
      validateEntry(entry).ok,
      false,
      `hätte abgewiesen werden müssen: ${JSON.stringify(entry)}`,
    );
  }
});

test('compareEntries sortiert nach Punkten, bei Gleichstand der ältere zuerst', () => {
  const list = [
    { name: 'b', score: 100, at: 50 },
    { name: 'a', score: 200, at: 10 },
    { name: 'c', score: 100, at: 20 },
  ];
  list.sort(compareEntries);
  assert.deepEqual(
    list.map((e) => e.name),
    ['a', 'c', 'b'],
  );
});
