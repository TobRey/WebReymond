// Physiktests: sie fahren player.js direkt in Node, ohne Browser.
//
// Die Zahlen hier sind gemessen, nicht gerechnet. Die Schrittintegration
// verliert gegenüber der geschlossenen Formel ein paar Prozent, und genau der
// gemessene Wert ist der, den der Levelgenerator voraussetzt.

import test from 'node:test';
import assert from 'node:assert/strict';

import { PHYS, T } from '../web/src/game/constants.js';
import { createPlayer, updatePlayer } from '../web/src/game/player.js';

const START_Y = -PHYS.hitboxH;

/** Endloser Boden ab Zeile 0. */
const flat = {
  tileAt: (col, row) => (row >= 0 ? T.SOLID : T.EMPTY),
  setTile() {},
  touchCrumble() {},
};

/** Boden nur bis Spalte 7, danach Abgrund. */
const ledge = {
  tileAt: (col, row) => (row >= 0 && col <= 7 ? T.SOLID : T.EMPTY),
  setTile() {},
  touchCrumble() {},
};

/** Boden plus senkrechte Wand ab Spalte 8. */
const wall = {
  tileAt: (col, row) => {
    if (col >= 8) return T.WALL;
    if (row >= 0) return T.SOLID;
    return T.EMPTY;
  },
  setTile() {},
  touchCrumble() {},
};

function input(overrides = {}) {
  return {
    left: false,
    right: false,
    down: false,
    jump: false,
    jumpPressed: false,
    jumpReleased: false,
    ...overrides,
  };
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

test('im Stand passiert nichts', () => {
  const { events, apex } = run(() => input(), { ticks: 30 });
  assert.equal(apex, 0);
  assert.deepEqual(events, []);
});

test('Einfachsprung schafft mehr als die vom Generator verlangten 3 Kacheln', () => {
  const { apex, events } = run((tick) => input({ jump: true, jumpPressed: tick === 0 }));
  assert.ok(apex >= 48 + 6, `Scheitel nur ${apex.toFixed(1)} px – zu wenig Reserve über 48 px`);
  assert.ok(apex < 70, `Scheitel ${apex.toFixed(1)} px – unerwartet hoch`);
  assert.deepEqual(events.slice(0, 1), ['jump']);
});

test('Doppelsprung schafft die 4 Kacheln der hohen Schwierigkeit', () => {
  const { apex, events } = run((tick) =>
    input({ jump: true, jumpPressed: tick === 0 || tick === 17 }),
  );
  assert.ok(apex >= 64 + 10, `Scheitel nur ${apex.toFixed(1)} px – zu wenig Reserve über 64 px`);
  assert.ok(events.includes('doubleJump'));
});

test('der zweite Sprung in der Luft wird nicht angenommen', () => {
  const { events } = run((tick) =>
    input({ jump: true, jumpPressed: tick === 0 || tick === 10 || tick === 14 }),
  );
  assert.equal(events.filter((e) => e === 'doubleJump').length, 1);
});

test('Taste loslassen schneidet den Sprung ab', () => {
  const full = run((tick) => input({ jump: true, jumpPressed: tick === 0 })).apex;
  const cut = run((tick) =>
    input({ jump: tick < 5, jumpPressed: tick === 0, jumpReleased: tick === 5 }),
  ).apex;
  assert.ok(
    cut < full * 0.7,
    `abgeschnitten ${cut.toFixed(1)} px gegen voll ${full.toFixed(1)} px`,
  );
});

test('Coyote-Time lässt kurz nach der Kante noch springen, später nicht mehr', () => {
  // Über die Kante laufen und `delay` Ticks nach dem Abgang springen. Der
  // Bodensprung ist am Ereignis 'jump' erkennbar; ein zu spät gedrückter
  // Sprung wird zum Doppelsprung ('doubleJump') und zählt hier nicht.
  const groundJumpAfter = (delay) => {
    const player = createPlayer(100, START_Y);
    const events = [];
    let leftAt = -1;
    for (let tick = 0; tick < 40; tick += 1) {
      if (leftAt < 0 && !player.onGround) leftAt = tick;
      const now = leftAt >= 0 && tick === leftAt + delay;
      updatePlayer(player, input({ right: true, jump: now, jumpPressed: now }), ledge, events);
    }
    return events.includes('jump');
  };

  assert.equal(groundJumpAfter(2), true, 'kurz nach der Kante muss der Sprung noch greifen');
  assert.equal(
    groundJumpAfter(PHYS.coyoteTicks + 6),
    false,
    'lange nach der Kante darf es kein Bodensprung mehr sein',
  );
});

test('Sprungpuffer holt einen zu früh gedrückten Sprung nach', () => {
  // Erst regulär springen, dann den Doppelsprung verbrauchen, und kurz vor dem
  // Aufkommen noch einmal drücken. Ohne Puffer verpufft dieser dritte Druck.
  const play = (withBufferedPress) => {
    const player = createPlayer(100, START_Y);
    const events = [];
    let pressed = false;
    for (let tick = 0; tick < 80; tick += 1) {
      let press = tick === 0 || tick === 14;
      if (
        withBufferedPress &&
        !pressed &&
        tick > 20 &&
        player.airJumps === 0 &&
        player.body.vy > 0 &&
        player.body.y > START_Y - 12
      ) {
        press = true;
        pressed = true;
      }
      updatePlayer(player, input({ jump: press, jumpPressed: press }), flat, events);
    }
    return events.filter((event) => event === 'jump').length;
  };

  assert.equal(play(false), 1, 'ohne den dritten Druck darf es bei einem Bodensprung bleiben');
  assert.equal(play(true), 2, 'der gepufferte Druck muss beim Aufkommen einen Sprung auslösen');
});

test('an der Wand wird gerutscht statt gefallen', () => {
  const player = createPlayer(110, START_Y - 80);
  const events = [];
  let slideSpeed = 0;
  for (let tick = 0; tick < 40; tick += 1) {
    updatePlayer(player, input({ right: true }), wall, events);
    if (player.sliding) slideSpeed = Math.max(slideSpeed, player.body.vy);
  }
  assert.ok(slideSpeed > 0, 'es muss überhaupt gerutscht werden');
  assert.ok(
    slideSpeed <= PHYS.wallSlideMaxFall + 0.001,
    `Rutschgeschwindigkeit ${slideSpeed} über der Grenze ${PHYS.wallSlideMaxFall}`,
  );
});

test('Wandsprung stösst ab, trägt hoch und füllt den Doppelsprung wieder auf', () => {
  const player = createPlayer(110, START_Y - 80);
  const events = [];
  let jumped = false;
  let maxX = player.body.x;
  // Die Höhe wird ab dem Wandsprung gemessen, nicht ab dem Start: bis dahin
  // fällt die Figur ja erst einmal an der Wand herunter.
  let yAtJump = null;
  let topAfterJump = Infinity;
  let airJumpsAfterWallJump = -1;

  for (let tick = 0; tick < 60; tick += 1) {
    const doJump = player.sliding && !jumped;
    if (doJump) jumped = true;
    updatePlayer(
      player,
      input({ right: !jumped, jump: doJump, jumpPressed: doJump }),
      wall,
      events,
    );
    maxX = Math.max(maxX, player.body.x);
    if (doJump) {
      yAtJump = player.body.y;
      airJumpsAfterWallJump = player.airJumps;
    }
    if (yAtJump !== null) topAfterJump = Math.min(topAfterJump, player.body.y);
  }

  assert.ok(events.includes('wallJump'), 'es muss ein Wandsprung stattgefunden haben');
  assert.ok(player.body.x < maxX - 20, 'der Abstoss muss deutlich von der Wand wegtragen');
  assert.ok(yAtJump !== null, 'der Wandsprung muss ausgelöst worden sein');
  assert.ok(
    yAtJump - topAfterJump > 30,
    `der Wandsprung bringt nur ${(yAtJump - topAfterJump).toFixed(1)} px Höhe`,
  );
  assert.equal(
    airJumpsAfterWallJump,
    PHYS.maxAirJumps,
    'der Doppelsprung muss nach dem Wandsprung wieder verfügbar sein',
  );
});

test('ein schneller Fall durchschlägt keinen einzelnen Boden', () => {
  // Aus 400 px Höhe auf eine einzige Bodenkachel fallen lassen.
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

test('Laufen erreicht die Höchstgeschwindigkeit und kommt wieder zum Stehen', () => {
  const player = createPlayer(100, START_Y);
  const events = [];
  for (let tick = 0; tick < 30; tick += 1)
    updatePlayer(player, input({ right: true }), flat, events);
  assert.ok(Math.abs(player.body.vx - PHYS.runMax) < 0.01);
  for (let tick = 0; tick < 15; tick += 1) updatePlayer(player, input(), flat, events);
  assert.equal(player.body.vx, 0);
});

test('Stacheln töten', () => {
  const spikes = {
    tileAt: (col, row) => {
      if (row >= 0) return T.SOLID;
      if (row === -1 && col >= 6) return T.SPIKE;
      return T.EMPTY;
    },
    setTile() {},
    touchCrumble() {},
  };
  const { player, events } = run(() => input({ right: true }), { ticks: 40, level: spikes });
  assert.equal(player.dead, true);
  assert.ok(events.includes('death'));
});
