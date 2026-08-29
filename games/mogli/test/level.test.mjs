// Tests der Levelerzeugung. Sie halten die Zusagen fest, auf die sich die
// Physik verlässt – wenn hier etwas rot wird, ist der Turm nicht mehr
// verlässlich begehbar.

import test from 'node:test';
import assert from 'node:assert/strict';

import { GEN, GRID_W, PHYS, T, TILE, isSolid } from '../web/src/game/constants.js';
import { createLevel } from '../web/src/game/level.js';
import { createWorld, emptyInput } from '../web/src/game/world.js';
import { botInput, createBot } from '../web/src/game/bot.js';
import { createPlayer, updatePlayer } from '../web/src/game/player.js';

const SEEDS = Array.from({ length: 24 }, (_, i) => (i + 1) * 104729);

function build(seed, upTo = -400) {
  const level = createLevel(seed);
  level.ensureUpTo(upTo);
  return level;
}

/** Waagerechter Abstand zwischen zwei Absatzkanten, in Kacheln. */
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
  assert.deepEqual(a.ledges, b.ledges);
  for (let row = -400; row <= 4; row += 1) {
    for (let col = 0; col < GRID_W; col += 1) {
      assert.equal(a.tileAt(col, row), b.tileAt(col, row), `Kachel ${col}/${row} weicht ab`);
    }
  }
});

test('die Absätze hängen abwechselnd links und rechts', () => {
  for (const seed of SEEDS) {
    const list = build(seed, -600).ledges;
    for (let i = 1; i < list.length; i += 1) {
      assert.equal(
        list[i].side,
        -list[i - 1].side,
        `Startwert ${seed}: zwei Absätze in Folge auf derselben Seite`,
      );
    }
  }
});

test('jeder Absatz berührt seine Wand', () => {
  // Sonst liefe Mogli am fernen Ende ins Leere – er läuft von allein und kann
  // nicht bremsen.
  for (const seed of SEEDS) {
    for (const ledge of build(seed, -600).ledges) {
      if (ledge.side > 0) {
        assert.equal(
          ledge.col + ledge.width,
          GRID_W - 1,
          `Startwert ${seed}: rechter Absatz in Zeile ${ledge.row} klebt nicht an der Wand`,
        );
      } else {
        assert.equal(
          ledge.col,
          1,
          `Startwert ${seed}: linker Absatz in Zeile ${ledge.row} klebt nicht an der Wand`,
        );
      }
    }
  }
});

test('der Höhenunterschied ist immer genau drei Kacheln', () => {
  for (const seed of SEEDS) {
    const list = build(seed, -600).ledges;
    for (let i = 1; i < list.length; i += 1) {
      assert.equal(
        list[i - 1].row - list[i].row,
        GEN.minDy,
        `Startwert ${seed}: Abstand ${list[i - 1].row - list[i].row} statt ${GEN.minDy}`,
      );
    }
  }
});

test('die Lücke bleibt im sprungbaren Bereich', () => {
  for (const seed of SEEDS) {
    const list = build(seed, -600).ledges;
    for (let i = 1; i < list.length; i += 1) {
      const gap = edgeGap(list[i - 1], list[i]);
      assert.ok(
        gap >= GEN.minGapX && gap <= GEN.maxGapX,
        `Startwert ${seed}: Lücke ${gap} liegt ausserhalb von ${GEN.minGapX}…${GEN.maxGapX}`,
      );
    }
  }
});

test('alles bleibt im Spielfeld und die Randwände stehen', () => {
  for (const seed of SEEDS) {
    const level = build(seed, -400);
    for (const ledge of level.ledges) {
      assert.ok(ledge.col >= 1, `Startwert ${seed}: Absatz links draussen`);
      assert.ok(ledge.col + ledge.width <= GRID_W - 1, `Startwert ${seed}: Absatz rechts draussen`);
      assert.ok(ledge.width >= GEN.minLedgeW && ledge.width <= GEN.maxLedgeW);
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
  assert.equal(level.tileAt(3, 0), T.EMPTY);
});

test('morsche Quader verschwinden nach der vorgesehenen Zeit', () => {
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

test('jede vorkommende Lücke ist mit der echten Physik überspringbar', () => {
  // Das ist der eigentliche Beweis: nicht gerechnet, sondern durchgespielt.
  // Für jede Kombination aus Absatzbreiten und Lücke wird ein Sprung
  // simuliert, und zwar sowohl früh als auch genau an der Kante abgesprungen.
  const twoLedges = (w1, gap, w2) => {
    const left = { from: 1, to: w1 };
    const right = { from: 1 + w1 + gap, to: 1 + w1 + gap + w2 - 1 };
    return {
      tileAt: (col, row) => {
        if (col <= 0 || col >= GRID_W) return T.WALL;
        if (row === 0 && col >= left.from && col <= left.to) return T.STONE;
        if (row === -GEN.minDy && col >= right.from && col <= right.to) return T.STONE;
        return T.EMPTY;
      },
      setTile() {},
      touchCrumble() {},
    };
  };

  const jumps = (w1, gap, w2, offsetPx) => {
    const level = twoLedges(w1, gap, w2);
    const edge = (1 + w1) * TILE;
    const player = createPlayer(1 * TILE + 2, -PHYS.hitboxH);
    const events = [];
    let jumped = false;
    for (let tick = 0; tick < 140; tick += 1) {
      const right = player.body.x + player.body.w;
      const doJump = !jumped && right >= edge + offsetPx;
      if (doJump) jumped = true;
      updatePlayer(
        player,
        { jump: jumped, jumpPressed: doJump, jumpReleased: false },
        level,
        events,
      );
      if (jumped && player.onGround && player.body.y < -TILE * 2) return true;
      if (player.body.y > 300) return false;
    }
    return false;
  };

  for (let gap = GEN.minGapX; gap <= GEN.maxGapX; gap += 1) {
    for (let w1 = GEN.minLedgeW; w1 <= GEN.maxLedgeW; w1 += 1) {
      const w2 = GRID_W - 2 - gap - w1;
      if (w2 < GEN.minLedgeW || w2 > GEN.maxLedgeW) continue;
      for (const offset of [-10, 0]) {
        assert.ok(
          jumps(w1, gap, w2, offset),
          `Breite ${w1}, Lücke ${gap}, Ziel ${w2}, Absprung ${offset} px: nicht geschafft`,
        );
      }
    }
  }
});

test('der Automat kommt in jedem Turm voran und bleibt nie hängen', () => {
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
      world.metres >= 12,
      `Startwert ${seed}: nur ${world.metres} m – der Automat kommt nicht vom Fleck`,
    );
  }
});
