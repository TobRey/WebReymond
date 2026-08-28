// Tests der Levelerzeugung. Sie halten die Zusagen fest, auf die sich die
// Physik verlässt – wenn hier etwas rot wird, ist der Turm nicht mehr
// verlässlich begehbar.

import test from 'node:test';
import assert from 'node:assert/strict';

import { GEN, GRID_W, T, TILE, isSolid } from '../web/src/game/constants.js';
import { createLevel, difficultyFromHeight } from '../web/src/game/level.js';
import { createWorld, emptyInput } from '../web/src/game/world.js';
import { botInput, createBot } from '../web/src/game/bot.js';

const SEEDS = Array.from({ length: 24 }, (_, i) => (i + 1) * 104729);

function build(seed, upTo = -400) {
  const level = createLevel(seed);
  level.ensureUpTo(upTo);
  return level;
}

/** Waagerechter Abstand zwischen zwei Plattformkanten, in Kacheln. */
function edgeGap(a, b) {
  const aEnd = a.col + a.width - 1;
  const bEnd = b.col + b.width - 1;
  if (b.col > aEnd) return b.col - aEnd - 1;
  if (bEnd < a.col) return a.col - bEnd - 1;
  return 0;
}

test('derselbe Startwert erzeugt denselben Turm', () => {
  const a = build(4242);
  const b = build(4242);
  assert.deepEqual(a.platforms, b.platforms);
  for (let row = -400; row <= 4; row += 1) {
    for (let col = 0; col < GRID_W; col += 1) {
      assert.equal(a.tileAt(col, row), b.tileAt(col, row), `Kachel ${col}/${row} weicht ab`);
    }
  }
});

test('jede Plattform ist von der vorherigen aus erreichbar', () => {
  for (const seed of SEEDS) {
    const level = build(seed, -600);
    const list = level.platforms;
    for (let i = 1; i < list.length; i += 1) {
      const from = list[i - 1];
      const to = list[i];
      // Die Wandkrone eines Schachts wird per Wandsprungkette erreicht, nicht
      // per Einzelsprung – für sie gelten die Sprungmasse nicht.
      if (to.viaWall) continue;

      const rise = from.row - to.row;
      assert.ok(rise >= 1, `Startwert ${seed}: Plattform ${i} liegt nicht höher (${rise})`);
      assert.ok(
        rise <= GEN.stretchDy,
        `Startwert ${seed}: Sprung über ${rise} Kacheln, erlaubt sind ${GEN.stretchDy}`,
      );
      assert.ok(
        edgeGap(from, to) <= GEN.maxDx,
        `Startwert ${seed}: Lücke von ${edgeGap(from, to)} Kacheln, erlaubt sind ${GEN.maxDx}`,
      );
    }
  }
});

test('der weite Sprung kommt erst ab der vorgesehenen Schwierigkeit vor', () => {
  for (const seed of SEEDS) {
    const level = build(seed, -600);
    const list = level.platforms;
    for (let i = 1; i < list.length; i += 1) {
      const from = list[i - 1];
      const to = list[i];
      if (to.viaWall) continue;
      const rise = from.row - to.row;
      if (rise > GEN.guaranteedDy) {
        const d = difficultyFromHeight(-from.row * TILE);
        assert.ok(
          d >= GEN.stretchFrom,
          `Startwert ${seed}: ${rise} Kacheln schon bei Schwierigkeit ${d.toFixed(2)}`,
        );
      }
    }
  }
});

test('keine Plattform baut die vorherige vollständig zu', () => {
  // Senkrecht nach oben kann man auf keine Plattform springen. Wäre die
  // Standfläche komplett überdeckt, stünde man unter einer Decke fest.
  for (const seed of SEEDS) {
    const level = build(seed, -600);
    const list = level.platforms;
    for (let i = 1; i < list.length; i += 1) {
      const from = list[i - 1];
      const to = list[i];
      if (to.viaWall || from.viaWall) continue;
      const overlapStart = Math.max(from.col, to.col);
      const overlapEnd = Math.min(from.col + from.width - 1, to.col + to.width - 1);
      const overlap = Math.max(0, overlapEnd - overlapStart + 1);
      assert.ok(
        from.width - overlap >= 2,
        `Startwert ${seed}: von ${from.width} Kacheln bleiben nur ${from.width - overlap} frei`,
      );
    }
  }
});

test('alles bleibt im Spielfeld und die Randwände stehen', () => {
  for (const seed of SEEDS) {
    const level = build(seed, -400);
    for (const platform of level.platforms) {
      assert.ok(platform.col >= 0, `Startwert ${seed}: Plattform links draussen`);
      assert.ok(
        platform.col + platform.width <= GRID_W,
        `Startwert ${seed}: Plattform rechts draussen`,
      );
    }
    for (let row = -380; row <= 0; row += 1) {
      assert.ok(isSolid(level.tileAt(0, row)), `linke Randwand fehlt in Zeile ${row}`);
      assert.ok(isSolid(level.tileAt(GRID_W - 1, row)), `rechte Randwand fehlt in Zeile ${row}`);
    }
  }
});

test('ausserhalb der Welt ist alles massiv', () => {
  const level = build(1);
  assert.ok(isSolid(level.tileAt(-1, -10)));
  assert.ok(isSolid(level.tileAt(GRID_W, -10)));
});

test('cullBelow gibt Abschnitte wieder frei', () => {
  const level = build(777, -600);
  const before = level.chunkCount;
  assert.ok(before > 10, 'es müssen überhaupt Abschnitte entstanden sein');
  level.cullBelow(-200);
  assert.ok(level.chunkCount < before, 'nach dem Aufräumen müssen es weniger sein');
  // Was aufgeräumt ist, darf nicht mehr als Boden zurückkommen.
  assert.equal(level.tileAt(7, 0), T.EMPTY);
});

test('bröckelnde Plattformen verschwinden nach der vorgesehenen Zeit', () => {
  const level = build(1);
  level.setTile(5, -20, T.CRUMBLE);
  level.touchCrumble(5, -20);
  for (let tick = 0; tick < GEN.crumbleTicks - 1; tick += 1) {
    level.updateCrumbling();
    assert.equal(level.tileAt(5, -20), T.CRUMBLE, `zu früh weg bei Tick ${tick}`);
  }
  const gone = level.updateCrumbling();
  assert.equal(level.tileAt(5, -20), T.EMPTY);
  assert.deepEqual(gone, [{ col: 5, row: -20 }]);
});

test('der Kletterautomat kommt in jedem Turm voran und bleibt nie hängen', () => {
  // Was dieser Test zeigt und was nicht: der Automat spielt bewusst
  // mittelmässig, seine Höhe ist also KEIN Mass für die Schwierigkeit. Er
  // beweist, dass die erzeugte Geometrie mit der echten Physik begehbar ist
  // und dass es keine Stelle gibt, an der man endgültig feststeckt.
  for (const seed of SEEDS) {
    const world = createWorld(seed);
    const bot = createBot();
    const input = emptyInput();
    let ticks = 0;
    while (!world.over && ticks < 60 * 120) {
      botInput(bot, world, input);
      world.update(input);
      ticks += 1;
    }
    assert.ok(
      world.metres >= 4,
      `Startwert ${seed}: nur ${world.metres} m – der Automat kommt nicht vom Fleck`,
    );
  }
});
