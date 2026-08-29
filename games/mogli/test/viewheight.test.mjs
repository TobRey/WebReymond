// Die Bildhöhe darf das Spiel nicht verändern.
//
// Diese Datei gibt es wegen eines echten Fehlers. Als die Höhe des Bildes
// beweglich wurde, damit das Spiel ein Handy füllt, hing die Leine der Glut
// noch am unteren BILDRAND: `hazard.y = min(hazard.y, cameraY + VIEW_H + 8)`.
// Damit war dasselbe Spiel auf einem hohen Handy ein anderes als auf einem
// breiten Monitor – derselbe Automat kam dort 60 m weit und hier 4 m.
//
// Die Regel dahinter: was man sehen kann, darf nicht bestimmen, wie schwer es
// ist. Der Test hält sie fest, indem er denselben Lauf bei jeder erlaubten
// Bildhöhe durchspielt und auf denselben Ausgang besteht.

import test from 'node:test';
import assert from 'node:assert/strict';

import { VIEW_H_MAX, VIEW_H_MIN, setViewHeight } from '../web/src/game/constants.js';
import { createWorld, emptyInput } from '../web/src/game/world.js';
import { botInput, createBot } from '../web/src/game/bot.js';

const SEEDS = [104729, 611953, 1299709, 15485863];
const HEIGHTS = [VIEW_H_MIN, 432, 528, VIEW_H_MAX];

/** Spielt einen Lauf mit dem Automaten und meldet, wie er ausgegangen ist. */
function run(seed) {
  const world = createWorld(seed);
  const bot = createBot();
  const input = emptyInput();
  let ticks = 0;
  while (!world.over && ticks < 60 * 90) {
    botInput(bot, world, input);
    world.update(input);
    ticks += 1;
  }
  return { metres: world.metres, emeralds: world.player.emeralds, ticks };
}

test('derselbe Lauf endet bei jeder Bildhöhe gleich', () => {
  for (const seed of SEEDS) {
    setViewHeight(VIEW_H_MIN);
    const reference = run(seed);

    for (const height of HEIGHTS) {
      assert.equal(setViewHeight(height), height, `${height} ist keine gültige Bildhöhe`);
      assert.deepEqual(
        run(seed),
        reference,
        `Startwert ${seed} bei Bildhöhe ${height}: anderer Ausgang als bei ${VIEW_H_MIN}`,
      );
    }
  }
  setViewHeight(VIEW_H_MIN);
});

test('setViewHeight rundet auf ganze Kacheln und bleibt in den Grenzen', () => {
  assert.equal(setViewHeight(10), VIEW_H_MIN);
  assert.equal(setViewHeight(9999), VIEW_H_MAX);
  assert.equal(setViewHeight(400), 400); // 25 Kacheln, geht glatt auf
  assert.equal(setViewHeight(407), 400); // 25.4 Kacheln -> 25
  assert.equal(setViewHeight(409), 416); // 25.6 Kacheln -> 26
  for (let wanted = VIEW_H_MIN - 40; wanted <= VIEW_H_MAX + 40; wanted += 7) {
    const height = setViewHeight(wanted);
    assert.equal(height % 16, 0, `${height} ist kein Vielfaches der Kachelgrösse`);
    assert.ok(height >= VIEW_H_MIN && height <= VIEW_H_MAX, `${height} liegt ausserhalb`);
  }
  setViewHeight(VIEW_H_MIN);
});
