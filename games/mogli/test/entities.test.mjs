// Das Verhalten der Elemente – jede Regel einzeln, direkt in Node.
//
// Gebaut wird je Test eine winzige Karte über validateMap (dieselbe Prüfung
// wie im Editor), dann läuft die echte Welt. So testet das hier nicht nur
// entities.js, sondern den ganzen Weg Karte → Gitter → Physik → Berührung.

import test from 'node:test';
import assert from 'node:assert/strict';

import { createWorld, emptyInput } from '../web/src/game/world.js';
import { validateMap } from '../web/src/net/mapRules.js';
import { EMERALD_TICKS, TILE } from '../web/src/game/constants.js';

/** Flacher Boden, Start links, Flagge rechts – plus die Testelemente. */
function kleineWelt(elements, extra = {}) {
  const result = validateMap({
    version: 1,
    id: 'labor',
    name: 'Labor',
    cols: 40,
    rows: 21,
    cell: 16,
    spawn: { x: 1, y: 17 },
    elements: [
      { id: 'boden', type: 'ground', x: 0, y: 19, w: 40, h: 2 },
      { id: 'ziel', type: 'flag', x: 38, y: 16 },
      ...elements,
    ],
    ...extra,
  });
  assert.equal(result.ok, true, `Labor-Karte ungültig: ${result.error}`);
  return createWorld(result.map);
}

function laufe(world, script, ticks) {
  const alleEvents = [];
  for (let t = 0; t < ticks && !world.finished; t += 1) {
    alleEvents.push(...world.update(script(t, world)));
  }
  return alleEvents;
}

const rechts = () => ({ ...emptyInput(), right: true });
const still = () => emptyInput();

test('ein Smaragd gibt die eingestellte Zeitgutschrift', () => {
  const world = kleineWelt([{ id: 's', type: 'emerald', x: 5, y: 18, props: { bonus: 3 } }]);
  const events = laufe(world, rechts, 120);
  assert.ok(events.includes('emerald'));
  assert.equal(world.player.emeralds, 1);
  assert.equal(world.bonusTicks, 3 * EMERALD_TICKS);
  assert.ok(world.resultTicks < world.tick, 'die Gutschrift muss die Wertungszeit senken');
});

test('Stacheln töten, und der Wiedereinstieg kommt am Start', () => {
  const world = kleineWelt([{ id: 'st', type: 'spikes', x: 6, y: 18, w: 2, h: 1 }]);
  // Bis zum ERSTEN Wiedereinstieg laufen – danach rennt das stumpfe Skript
  // sofort wieder hinein, und jeder weitere Tod ist korrekt, aber uninteressant.
  let gestorben = false;
  let wieder = false;
  for (let t = 0; t < 200 && !wieder; t += 1) {
    const events = world.update(rechts());
    gestorben ||= events.includes('death');
    wieder = events.includes('respawn');
  }
  assert.equal(gestorben, true);
  assert.equal(wieder, true);
  assert.ok(world.player.body.x < 3 * TILE, 'zurück zum Start, es gab keinen Checkpoint');
});

test('ein erreichter Checkpoint wird der neue Wiedereinstieg', () => {
  const world = kleineWelt([
    { id: 'cp', type: 'checkpoint', x: 5, y: 17 },
    { id: 'st', type: 'spikes', x: 9, y: 18, w: 2, h: 1 },
  ]);
  const events = laufe(world, rechts, 260);
  assert.ok(events.includes('checkpoint'));
  assert.ok(events.includes('respawn'));
  const x = world.player.body.x / TILE;
  assert.ok(x >= 4.5 && x < 9, `Wiedereinstieg bei Zelle ${x.toFixed(1)}, erwartet ~5`);
});

test('die Uhr läuft beim Sterben weiter – Zeitverlust IST die Strafe', () => {
  const mitTod = kleineWelt([{ id: 'st', type: 'spikes', x: 6, y: 18, w: 3, h: 1 }]);
  laufe(mitTod, rechts, 300);
  assert.ok(mitTod.deaths >= 1);
  assert.equal(mitTod.tick, 300, 'kein Tick darf beim Sterben verloren gehen');
});

test('eine Feder schleudert hoch – höher als jeder Sprung', () => {
  const world = kleineWelt([{ id: 'f', type: 'spring', x: 5, y: 18, props: { power: 110 } }]);
  let top = Infinity;
  laufe(
    world,
    (t, w) => {
      top = Math.min(top, w.player.body.y);
      return rechts();
    },
    150,
  );
  const hoehe = (18 + 1) * TILE - 20 - top;
  assert.ok(hoehe > TILE * 6, `nur ${Math.round(hoehe)}px hoch – die Feder hat nicht gezündet`);
});

test('ein Läufer patrouilliert in seiner Reichweite und tötet von der Seite', () => {
  const world = kleineWelt([
    { id: 'w', type: 'walker', x: 8, y: 18, props: { range: 4, speed: 10, stompable: true } },
  ]);
  const walker = world.entities.find((e) => e.type === 'walker');
  let minX = Infinity;
  let maxX = -Infinity;
  const events = laufe(
    world,
    () => {
      minX = Math.min(minX, walker.x);
      maxX = Math.max(maxX, walker.x);
      return rechts();
    },
    400,
  );
  assert.ok(events.includes('death'), 'seitlich hineinlaufen muss töten');
  assert.ok(maxX - minX > TILE * 2, 'er ist kaum patrouilliert');
  assert.ok(maxX <= 8 * TILE + 4 * TILE + 1, 'er hat seine Reichweite verlassen');
});

test('Draufspringen besiegt einen besiegbaren Läufer und stösst ab', () => {
  const world = kleineWelt([
    { id: 'w', type: 'walker', x: 6, y: 18, props: { range: 1, speed: 2, stompable: true } },
  ]);
  // Anlauf und Sprung, so dass der Fall auf dem Läufer endet.
  const events = laufe(
    world,
    (t) => ({
      ...emptyInput(),
      right: t < 30,
      jump: t >= 8 && t < 30,
      jumpPressed: t === 8,
    }),
    180,
  );
  assert.ok(events.includes('stomp'), `kein stomp; Ereignisse: ${events.join(',')}`);
  assert.equal(world.deaths, 0);
  const walker = world.entities.find((e) => e.type === 'walker');
  assert.equal(walker.alive, false);
});

test('ein unbesiegbarer Läufer tötet auch von oben', () => {
  const world = kleineWelt([
    { id: 'w', type: 'walker', x: 6, y: 18, props: { range: 1, speed: 2, stompable: false } },
  ]);
  const events = laufe(
    world,
    (t) => ({ ...emptyInput(), right: t < 30, jump: t >= 8 && t < 30, jumpPressed: t === 8 }),
    180,
  );
  assert.ok(!events.includes('stomp'));
  assert.ok(events.includes('death'));
});

test('ein Portal teleportiert zum Ziel und nicht sofort zurück', () => {
  const world = kleineWelt([
    { id: 'pa', type: 'portal', x: 4, y: 17, props: { targetId: 'pb' } },
    { id: 'pb', type: 'portal', x: 20, y: 17, props: { targetId: 'pa' } },
  ]);
  const events = laufe(world, rechts, 90);
  assert.ok(events.includes('portal'));
  assert.ok(
    world.player.body.x > 18 * TILE,
    `steht bei ${(world.player.body.x / TILE).toFixed(1)} – zurückteleportiert?`,
  );
});

test('eine verschlossene Tür braucht den Schlüssel, dann ist sie der Ausgang', () => {
  const ohne = kleineWelt([
    { id: 't', type: 'door', x: 10, y: 16, props: { locked: true, mode: 'exit' } },
  ]);
  laufe(ohne, rechts, 200);
  assert.equal(ohne.finished, false, 'ohne Schlüssel darf die Tür nicht öffnen');

  const mit = kleineWelt([
    { id: 'k', type: 'key', x: 5, y: 18 },
    { id: 't', type: 'door', x: 10, y: 16, props: { locked: true, mode: 'exit' } },
  ]);
  const events = laufe(mit, rechts, 200);
  assert.ok(events.includes('key'));
  assert.ok(events.includes('unlock'));
  assert.equal(mit.finished, true);
});

test('eine bewegliche Plattform trägt den Spieler mit', () => {
  // Plattform fährt waagerecht; der Spieler stellt sich drauf und lässt los.
  const world = kleineWelt([
    { id: 'm', type: 'mover', x: 3, y: 16, props: { axis: 'h', range: 8, speed: 10 } },
  ]);
  // Draufspringen …
  laufe(
    world,
    (t) => ({ ...emptyInput(), right: t < 14, jump: t >= 2 && t < 22, jumpPressed: t === 2 }),
    40,
  );
  const vorher = world.player.body.x;
  // … und stehen bleiben: die Plattform muss ihn tragen und mitnehmen.
  laufe(world, still, 60);
  assert.ok(
    Math.abs(world.player.body.x - vorher) > TILE,
    `er steht still bei ${world.player.body.x.toFixed(0)} – nicht mitgenommen`,
  );
  assert.ok(world.player.onGround, 'auf der Plattform gilt er als am Boden');
});

test('unter der Karte ist Schluss', () => {
  // Karte mit Loch im Boden.
  const result = validateMap({
    version: 1,
    id: 'loch',
    name: 'Loch',
    cols: 40,
    rows: 21,
    cell: 16,
    spawn: { x: 1, y: 17 },
    elements: [
      { id: 'b1', type: 'ground', x: 0, y: 19, w: 5, h: 2 },
      { id: 'ziel', type: 'flag', x: 38, y: 16 },
    ],
  });
  const world = createWorld(result.map);
  const events = laufe(world, rechts, 300);
  assert.ok(events.includes('death'));
  assert.ok(events.includes('respawn'));
});

test('die Flagge beendet das Level und friert die Zeit ein', () => {
  const world = kleineWelt([], {
    elements: [
      { id: 'boden', type: 'ground', x: 0, y: 19, w: 40, h: 2 },
      { id: 'ziel', type: 'flag', x: 6, y: 16 },
    ],
  });
  const events = laufe(world, rechts, 300);
  assert.ok(events.includes('finish'));
  assert.equal(world.finished, true);
  const ticksBeimZiel = world.tick;
  world.update(rechts());
  assert.equal(world.tick, ticksBeimZiel, 'nach dem Ziel darf die Uhr nicht weiterlaufen');
});
