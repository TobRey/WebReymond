// Physiktests: sie fahren player.js direkt in Node, ohne Browser.
//
// Die Zahlen hier sind gemessen, nicht gerechnet. Die Schrittintegration
// verliert gegenüber der geschlossenen Formel ein paar Prozent, und genau der
// gemessene Wert ist der, den der Levelgenerator voraussetzt.

import test from 'node:test';
import assert from 'node:assert/strict';

import { GEN, PHYS, T, TILE } from '../web/src/game/constants.js';
import { createPlayer, updatePlayer } from '../web/src/game/player.js';

const START_Y = -PHYS.hitboxH;

/** Endloser Boden ab Zeile 0. */
const flat = {
  tileAt: (col, row) => (row >= 0 ? T.STONE : T.EMPTY),
  setTile() {},
  touchCrumble() {},
};

/** Boden nur bis Spalte 7, danach Abgrund. */
const ledge = {
  tileAt: (col, row) => (row >= 0 && col <= 7 ? T.STONE : T.EMPTY),
  setTile() {},
  touchCrumble() {},
};

/** Boden plus senkrechte Wand ab Spalte 8. */
const wall = {
  tileAt: (col, row) => {
    if (col >= 8) return T.WALL;
    if (row >= 0) return T.STONE;
    return T.EMPTY;
  },
  setTile() {},
  touchCrumble() {},
};

function input(overrides = {}) {
  return { jump: false, jumpPressed: false, jumpReleased: false, ...overrides };
}

/** Fährt ein Eingabeskript und liefert Spieler, Ereignisse und Scheitelhöhe. */
function run(script, { ticks = 120, level = flat, x = 100, y = START_Y } = {}) {
  const player = createPlayer(x, y);
  const events = [];
  let top = y;
  for (let tick = 0; tick < ticks; tick += 1) {
    updatePlayer(player, script(tick, player), level, events);
    top = Math.min(top, player.body.y);
  }
  return { player, events, apex: y - top };
}

test('Mogli läuft von allein los, ohne dass etwas gedrückt wird', () => {
  const { player } = run(() => input(), { ticks: 20 });
  assert.ok(player.body.vx > 0, 'er muss sich nach rechts in Bewegung setzen');
  assert.ok(Math.abs(player.body.vx - PHYS.runMax) < 0.01, 'und zwar auf volles Lauftempo');
});

test('ein Tipp reicht für die vom Generator verlangten 3 Kacheln', () => {
  const { apex, events } = run((tick) => input({ jump: true, jumpPressed: tick === 0 }));
  const needed = GEN.minDy * TILE;
  assert.ok(
    apex >= needed + 8,
    `Scheitel nur ${apex.toFixed(1)} px – zu wenig Reserve über ${needed} px`,
  );
  assert.ok(apex < 90, `Scheitel ${apex.toFixed(1)} px – unerwartet hoch`);
  assert.deepEqual(events.slice(0, 1), ['jump']);
});

test('die Sprunghöhe hängt NICHT davon ab, wie lange gedrückt wird', () => {
  // Bewusst so gebaut: bei einem Pflichtsprung über drei Kacheln darf ein
  // kurzer Tipp nicht zu wenig sein.
  const held = run((tick) => input({ jump: true, jumpPressed: tick === 0 })).apex;
  const tapped = run((tick) =>
    input({ jump: tick < 2, jumpPressed: tick === 0, jumpReleased: tick === 2 }),
  ).apex;
  assert.equal(Math.round(held), Math.round(tapped));
});

test('es gibt keinen Doppelsprung', () => {
  const { events } = run((tick) =>
    input({ jump: true, jumpPressed: tick === 0 || tick === 12 || tick === 20 }),
  );
  assert.equal(events.filter((e) => e === 'jump').length, 1, 'nur der Sprung vom Boden zählt');
  assert.equal(PHYS.maxAirJumps, 0);
});

test('Coyote-Time lässt kurz nach der Kante noch springen, später nicht mehr', () => {
  const jumpAfter = (delay) => {
    const player = createPlayer(100, START_Y);
    const events = [];
    let leftAt = -1;
    for (let tick = 0; tick < 40; tick += 1) {
      if (leftAt < 0 && !player.onGround) leftAt = tick;
      const now = leftAt >= 0 && tick === leftAt + delay;
      updatePlayer(player, input({ jump: now, jumpPressed: now }), ledge, events);
    }
    return events.includes('jump');
  };

  assert.equal(jumpAfter(1), true, 'kurz nach der Kante muss der Sprung noch greifen');
  assert.equal(jumpAfter(PHYS.coyoteTicks + 6), false, 'lange danach nicht mehr');
});

test('Sprungpuffer holt einen zu früh gedrückten Tipp nach', () => {
  const play = (withBufferedPress) => {
    const player = createPlayer(100, START_Y - 90);
    const events = [];
    let pressed = false;
    for (let tick = 0; tick < 90; tick += 1) {
      let press = false;
      if (
        withBufferedPress &&
        !pressed &&
        player.body.vy > 0 &&
        player.body.y > START_Y - 14 &&
        player.body.y < START_Y
      ) {
        press = true;
        pressed = true;
      }
      updatePlayer(player, input({ jump: press, jumpPressed: press }), flat, events);
    }
    return events.filter((event) => event === 'jump').length;
  };

  assert.equal(play(false), 0, 'ohne Tipp darf nichts passieren');
  assert.equal(play(true), 1, 'der gepufferte Tipp muss beim Aufkommen einen Sprung auslösen');
});

test('an der Wand wird gerutscht statt gefallen', () => {
  const player = createPlayer(110, START_Y - 90);
  const events = [];
  let slideSpeed = 0;
  for (let tick = 0; tick < 40; tick += 1) {
    updatePlayer(player, input(), wall, events);
    if (player.sliding) slideSpeed = Math.max(slideSpeed, player.body.vy);
  }
  assert.ok(slideSpeed > 0, 'es muss überhaupt gerutscht werden');
  assert.ok(
    slideSpeed <= PHYS.wallSlideMaxFall + 0.001,
    `Rutschgeschwindigkeit ${slideSpeed} über der Grenze ${PHYS.wallSlideMaxFall}`,
  );
});

test('der Wandsprung dreht die Laufrichtung um', () => {
  const player = createPlayer(110, START_Y - 90);
  const events = [];
  let jumped = false;
  let facingBefore = player.facing;

  for (let tick = 0; tick < 60; tick += 1) {
    const doJump = player.sliding && !jumped;
    if (doJump) {
      jumped = true;
      facingBefore = player.facing;
    }
    updatePlayer(player, input({ jump: doJump, jumpPressed: doJump }), wall, events);
  }

  assert.ok(events.includes('wallJump'), 'es muss ein Wandsprung stattgefunden haben');
  assert.equal(player.facing, -facingBefore, 'danach läuft er andersherum');
  assert.equal(player.wallJumps, 1);
});

test('am Boden gegen eine Wand: er dreht von selbst um', () => {
  const { player, events } = run(() => input(), { ticks: 60, level: wall, x: 100 });
  assert.ok(events.includes('turn'), 'das Umdrehen muss gemeldet werden');
  assert.equal(player.facing, -1, 'er läuft danach nach links');
});

test('eine Ranke reisst nach oben und setzt die Schwerkraft aus', () => {
  const withVine = {
    tileAt: (col, row) => {
      if (row >= 0) return T.STONE;
      if (row === -3 && col === 7) return T.VINE;
      return T.EMPTY;
    },
    setTile() {},
    touchCrumble() {},
  };
  const player = createPlayer(100, START_Y);
  const events = [];
  let top = START_Y;
  for (let tick = 0; tick < 90; tick += 1) {
    updatePlayer(player, input({ jump: tick === 0, jumpPressed: tick === 0 }), withVine, events);
    top = Math.min(top, player.body.y);
  }
  assert.ok(events.includes('vine'), 'die Ranke muss ausgelöst haben');
  assert.equal(player.vinesUsed, 1);
  const lift = START_Y - top;
  assert.ok(
    lift > GEN.minDy * TILE * 2,
    `die Ranke bringt nur ${lift.toFixed(0)} px – sie soll deutlich über einen Sprung hinausgehen`,
  );
});

test('ein schneller Fall durchschlägt keinen einzelnen Boden', () => {
  const player = createPlayer(100, START_Y - 400);
  const events = [];
  let belowFloor = false;
  for (let tick = 0; tick < 200; tick += 1) {
    updatePlayer(player, input(), flat, events);
    if (player.body.y + player.body.h > 0.001) belowFloor = true;
  }
  assert.equal(belowFloor, false, 'der Körper darf nie unter die Bodenoberkante geraten');
  assert.equal(player.onGround, true);
});

test('Dornen töten', () => {
  const spikes = {
    tileAt: (col, row) => {
      if (row >= 0) return T.STONE;
      if (row === -1 && col >= 7) return T.SPIKE;
      return T.EMPTY;
    },
    setTile() {},
    touchCrumble() {},
  };
  const { player, events } = run(() => input(), { ticks: 40, level: spikes });
  assert.equal(player.dead, true);
  assert.ok(events.includes('death'));
});

test('Smaragde zählen und verschwinden', () => {
  let taken = null;
  const gems = {
    tileAt: (col, row) => {
      if (row >= 0) return T.STONE;
      if (row === -1 && col === 7 && taken === null) return T.EMERALD;
      return T.EMPTY;
    },
    setTile(col, row) {
      taken = { col, row };
    },
    touchCrumble() {},
  };
  const { player, events } = run(() => input(), { ticks: 40, level: gems });
  assert.equal(player.emeralds, 1);
  assert.ok(events.includes('emerald'));
  assert.deepEqual(taken, { col: 7, row: -1 });
});
