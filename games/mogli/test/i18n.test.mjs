// Dieselbe Regel wie im Portal für messages/de.json und messages/en.json:
// beide Kataloge müssen dieselben Schlüssel haben. Ein fehlender Schlüssel
// fällt sonst erst auf, wenn jemand die Sprache umschaltet.

import test from 'node:test';
import assert from 'node:assert/strict';

import { DEFAULT_LOCALE, DICT, LOCALES } from '../web/src/i18n.js';

test('es gibt genau die beiden vorgesehenen Sprachen, Deutsch als Standard', () => {
  assert.deepEqual([...LOCALES].sort(), ['de', 'en']);
  assert.equal(DEFAULT_LOCALE, 'de');
});

test('beide Kataloge haben dieselben Schlüssel', () => {
  const de = Object.keys(DICT.de).sort();
  const en = Object.keys(DICT.en).sort();

  const missingInEn = de.filter((key) => !(key in DICT.en));
  const missingInDe = en.filter((key) => !(key in DICT.de));

  assert.deepEqual(missingInEn, [], `fehlt in en: ${missingInEn.join(', ')}`);
  assert.deepEqual(missingInDe, [], `fehlt in de: ${missingInDe.join(', ')}`);
});

test('kein Text ist leer', () => {
  for (const locale of LOCALES) {
    for (const [key, value] of Object.entries(DICT[locale])) {
      assert.equal(typeof value, 'string', `${locale}.${key} ist kein Text`);
      assert.ok(value.trim().length > 0, `${locale}.${key} ist leer`);
    }
  }
});

test('Platzhalter stimmen in beiden Sprachen überein', () => {
  const placeholders = (text) => (text.match(/\{(\w+)\}/g) ?? []).sort();
  for (const key of Object.keys(DICT.de)) {
    assert.deepEqual(
      placeholders(DICT.de[key]),
      placeholders(DICT.en[key]),
      `unterschiedliche Platzhalter in ${key}`,
    );
  }
});
