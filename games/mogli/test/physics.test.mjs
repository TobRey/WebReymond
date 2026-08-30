// Bewegung und Sprunglogik – die Mario-Absprachen von 3.0.
//
// Alles läuft direkt in Node gegen kleine, von Hand gebaute Ebenen: die
// Fehlerklasse hier ist "fühlt sich kaputt an" (verschluckter Sprung, kein
// Coyote, Durchfallen), und jede dieser Zusagen steht als Test.

import test from 'node:test';
import assert from 'node:assert/strict';

import { PHYS, T, TILE } from '../web/src/game/constants.js';
import { createPlayer, updatePlayer } from '../web/src/game/player.js';
import { resetTileBoxes, setTileBox } from '../web/src/game/tilebox.js';

const START_Y = -PHYS.hitboxH;

/** Endloser Boden ab Zeile 0. */
const flat = {
  tileAt: (col, row) => (row >= 0 ? T.SOLID : T.EMPTY),
  touchCrumble() {},
};

/** Boden nur bis Spalte 7, danach Abgrund. */
const ledge = {
  tileAt: (col, row) => (row >= 0 && col <= 7 ? T.SOLID : T.EMPTY),
  touchCrumble() {},
};

/** Boden, und ab Spalte 8 eine Wand. */
const wall = {
  tileAt: (col, row) => {
    if (col >= 8) return T.SOLID;
    if (row >= 0) return T.SOLID;
    return T.EMPTY;
  },
  touchCrumble() {},
};

/** Eine Plattform (nur von oben tragend) auf Zeile -4, Boden auf Zeile 0. */
const platformLevel = {
  tileAt: (col, row) => {
    if (row === -4) return T.PLATFORM;
    if (row >= 0) return T.SOLID;
    return T.EMPTY;
  },
  touchCrumble() {},
};

function input(overrides = {}) {
  return {
    left: false,
    right: false,
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

test('ohne Eingabe steht Mogli – seit 3.0 läuft nichts von allein', () => {
  const { player } = run(() => input(), { ticks: 60 });
  assert.equal(Math.round(player.body.x), 100);
  assert.equal(player.anim, 'idle');
});

test('rechts halten beschleunigt bis zum Lauftempo, loslassen bremst aus', () => {
  const { player } = run((t) => input({ right: t < 60 }), { ticks: 120 });
  assert.ok(player.body.x > 100 + 50, 'er ist kaum vorangekommen');
  assert.equal(player.body.vx, 0, 'nach dem Loslassen muss er zum Stehen kommen');
});

test('ein Richtungswechsel greift zackig, nicht schwammig', () => {
  const { player } = run((t) => input({ right: t < 40, left: t >= 40 }), { ticks: 80 });
  assert.ok(player.body.vx < -PHYS.runMax * 0.8, `vx ist erst ${player.body.vx}`);
});

test('die volle Sprunghöhe trägt vier Kacheln', () => {
  const { apex } = run((t) => input({ jump: t < 40, jumpPressed: t === 0 }));
  assert.ok(apex >= TILE * 4, `Scheitel ${apex}px, gebraucht werden ${TILE * 4}`);
});

test('kurz tippen springt DEUTLICH niedriger – die variable Sprunghöhe', () => {
  const kurz = run((t) => input({ jump: t < 3, jumpPressed: t === 0, jumpReleased: t === 3 }));
  const lang = run((t) => input({ jump: t < 40, jumpPressed: t === 0 }));
  assert.ok(
    kurz.apex < lang.apex * 0.55,
    `kurz ${Math.round(kurz.apex)}px, lang ${Math.round(lang.apex)}px – kaum Unterschied`,
  );
  assert.ok(kurz.apex >= TILE * 1.2, 'aber auch ein Tipp muss über eine Kachel tragen');
});

test('es gibt keinen Doppelsprung', () => {
  // Vergleich statt fester Zahl: mit drei Drucken in der Luft darf der
  // Scheitel exakt der eines einzelnen Sprungs sein.
  const einzeln = run((t) => input({ jump: t < 40, jumpPressed: t === 0 }));
  const gehaemmert = run((t) =>
    input({ jump: t % 20 < 10, jumpPressed: t === 0 || t === 20 || t === 40 }),
  );
  assert.ok(
    gehaemmert.apex <= einzeln.apex + 0.5,
    `einzeln ${einzeln.apex}px, gehämmert ${gehaemmert.apex}px – da hat ein zweiter gezündet`,
  );
});

test('Coyote-Time lässt kurz nach der Kante noch springen, später nicht mehr', () => {
  // Nicht mit fester Tickzahl raten, wann die Kante kommt: erst den Abflug
  // messen, dann relativ dazu drücken. Sonst testet man die Anlaufstrecke.
  const abflugTick = (() => {
    const player = createPlayer(7 * TILE - 2, START_Y);
    for (let t = 0; t < 120; t += 1) {
      updatePlayer(player, input({ right: true }), ledge, []);
      if (!player.onGround && player.body.vy > 0) return t;
    }
    throw new Error('nie abgeflogen');
  })();

  const probiere = (druckTick) => {
    const { events } = run(
      (t) => input({ right: true, jump: t >= druckTick, jumpPressed: t === druckTick }),
      { level: ledge, x: 7 * TILE - 2, ticks: druckTick + 30 },
    );
    return events.includes('jump');
  };

  assert.equal(probiere(abflugTick + 2), true, 'im Coyote-Fenster muss der Sprung zünden');
  assert.equal(
    probiere(abflugTick + PHYS.coyoteTicks + 8),
    false,
    'weit nach der Kante darf nichts mehr zünden',
  );
});

test('der Sprungpuffer holt einen zu früh gedrückten Tipp nach', () => {
  // In der Luft drücken, kurz vor der Landung: der Sprung kommt beim Aufsetzen.
  const player = createPlayer(100, -80);
  const events = [];
  for (let t = 0; t < 60; t += 1) {
    const drueckt = player.body.y > -30 && !player.onGround && events.length === 0;
    updatePlayer(player, input({ jump: drueckt, jumpPressed: drueckt }), flat, events);
  }
  assert.ok(events.includes('jump'), 'der gepufferte Sprung muss beim Landen zünden');
});

test('am Boden gegen eine Wand: er bleibt stehen, nichts dreht sich von selbst', () => {
  const { player } = run(() => input({ right: true }), { level: wall, ticks: 120 });
  assert.equal(Math.round(player.body.x + player.body.w), 8 * TILE);
  assert.equal(player.facing, 1, 'die Blickrichtung gehört seit 3.0 dem Spieler');
});

test('eine Plattform trägt von oben und lässt von unten durch', () => {
  // Von unten durchspringen …
  const durch = run((t) => input({ jump: t < 40, jumpPressed: t === 0 }), {
    level: platformLevel,
  });
  assert.ok(durch.apex > TILE * 3.5, 'die Plattform hätte den Sprung nicht stoppen dürfen');
  // … und danach oben stehen bleiben.
  assert.equal(Math.round(durch.player.body.y + durch.player.body.h), -4 * TILE);
});

test('ein schneller Fall durchschlägt keinen einzelnen Boden', () => {
  const player = createPlayer(100, -400);
  player.body.vy = PHYS.maxFallSpeed;
  const events = [];
  for (let t = 0; t < 120; t += 1) updatePlayer(player, input(), flat, events);
  assert.equal(Math.round(player.body.y + player.body.h), 0);
});

test('aus jeder Fallhöhe kommt er exakt auf der Kachelkante zu stehen', () => {
  for (let drop = 8; drop <= 200; drop += 7) {
    const player = createPlayer(100, -drop - PHYS.hitboxH);
    for (let t = 0; t < 200; t += 1) updatePlayer(player, input(), flat, []);
    assert.equal(
      player.body.y + player.body.h,
      0,
      `Fallhöhe ${drop}: steht bei ${player.body.y + player.body.h}`,
    );
  }
});

test('ein eingezeichneter Kachelkasten verschiebt die Standfläche', () => {
  setTileBox(T.SOLID, { x: 0, y: 6, w: TILE, h: TILE - 6 });
  try {
    const player = createPlayer(100, -60);
    for (let t = 0; t < 120; t += 1) updatePlayer(player, input(), flat, []);
    assert.equal(Math.round(player.body.y + player.body.h), 6);
  } finally {
    resetTileBoxes();
  }
});

test('ein Bröckelblock meldet die Berührung', () => {
  let touched = null;
  const level = {
    tileAt: (col, row) => (row >= 0 ? T.CRUMBLE : T.EMPTY),
    touchCrumble: (col, row) => {
      touched = { col, row };
    },
  };
  run(() => input(), { level, ticks: 5 });
  assert.notEqual(touched, null, 'das Betreten muss die Zerfallsuhr starten');
});
