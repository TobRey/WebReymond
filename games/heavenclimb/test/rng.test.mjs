import test from 'node:test';
import assert from 'node:assert/strict';

import { createRng, seedFromString } from '../web/src/game/rng.js';

test('derselbe Startwert ergibt dieselbe Folge', () => {
  const a = createRng(12345);
  const b = createRng(12345);
  const first = Array.from({ length: 200 }, () => a.next());
  const second = Array.from({ length: 200 }, () => b.next());
  assert.deepEqual(first, second);
});

test('verschiedene Startwerte ergeben verschiedene Folgen', () => {
  const a = createRng(1);
  const b = createRng(2);
  assert.notEqual(a.next(), b.next());
});

test('next() bleibt in [0, 1)', () => {
  const rng = createRng(999);
  for (let i = 0; i < 5000; i += 1) {
    const value = rng.next();
    assert.ok(value >= 0 && value < 1, `Wert ausserhalb des Bereichs: ${value}`);
  }
});

test('int() hält beide Grenzen ein und erreicht sie auch', () => {
  const rng = createRng(7);
  const seen = new Set();
  for (let i = 0; i < 2000; i += 1) {
    const value = rng.int(3, 7);
    assert.ok(Number.isInteger(value));
    assert.ok(value >= 3 && value <= 7, `Wert ausserhalb: ${value}`);
    seen.add(value);
  }
  assert.deepEqual([...seen].sort(), [3, 4, 5, 6, 7]);
});

test('int() mit umgekehrten Grenzen liefert das Minimum', () => {
  const rng = createRng(1);
  assert.equal(rng.int(5, 2), 5);
});

test('seedFromString ist stabil und unterscheidet', () => {
  assert.equal(seedFromString('heavenclimb'), seedFromString('heavenclimb'));
  assert.notEqual(seedFromString('a'), seedFromString('b'));
});
