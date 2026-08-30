// Die Bildhöhe darf das Spiel nicht verändern.
//
// Diese Datei gibt es wegen eines echten Fehlers aus der Turm-Zeit: die Leine
// der Glut hing am unteren BILDRAND, und dasselbe Spiel war auf einem hohen
// Handy ein anderes als auf einem breiten Monitor. Die Glut ist weg, die
// Regel bleibt: was man sehen kann, darf nicht bestimmen, wie schwer es ist.
// Seit 3.0 heisst das konkret: die Kamera ist reine Darstellung, kein Wert
// aus ihr fliesst je in die Physik zurück.
//
// Geprüft wird, indem derselbe Eingabelauf auf der Demo-Karte bei jeder
// erlaubten Bildhöhe durchgespielt wird – Tick für Tick müssen Position und
// Ausgang exakt gleich sein.

import test from 'node:test';
import assert from 'node:assert/strict';

import { VIEW_H_MAX, VIEW_H_MIN, setViewHeight, TILE } from '../web/src/game/constants.js';
import { createWorld, emptyInput } from '../web/src/game/world.js';
import { demoMap } from '../web/src/game/map.js';

const HEIGHTS = [VIEW_H_MIN, 432, 528, VIEW_H_MAX];

/** Ein fester Eingabelauf: 30 Sekunden rennen und rhythmisch springen. */
function run() {
  const world = createWorld(demoMap());
  const spur = [];
  for (let t = 0; t < 60 * 30 && !world.finished; t += 1) {
    const input = emptyInput();
    input.right = true;
    input.jump = t % 37 < 12;
    input.jumpPressed = t % 37 === 0;
    input.jumpReleased = t % 37 === 12;
    world.update(input);
    if (t % 30 === 0) {
      spur.push([Math.round(world.player.body.x * 100), Math.round(world.player.body.y * 100)]);
    }
  }
  return {
    spur,
    finished: world.finished,
    deaths: world.deaths,
    emeralds: world.player.emeralds,
    ticks: world.tick,
  };
}

test('derselbe Lauf endet bei jeder Bildhöhe exakt gleich', () => {
  const referenz = setViewHeight(HEIGHTS[0]) && run();
  for (const height of HEIGHTS.slice(1)) {
    setViewHeight(height);
    assert.deepEqual(run(), referenz, `Bildhöhe ${height} spielt sich anders`);
  }
  setViewHeight(VIEW_H_MIN);
});

test('setViewHeight rundet auf ganze Kacheln und bleibt in den Grenzen', () => {
  assert.equal(setViewHeight(500) % TILE, 0);
  assert.equal(setViewHeight(10), VIEW_H_MIN);
  assert.equal(setViewHeight(9000), VIEW_H_MAX);
  setViewHeight(VIEW_H_MIN);
});
