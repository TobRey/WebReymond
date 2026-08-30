// Karten: das Format, die Prüfung, die Demo – und der PHP-Zahlenabgleich.

import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

import { MAP_LIMITS, slugify, validateMap, validateMaps } from '../web/src/net/mapRules.js';
import { ELEMENT_TYPES } from '../web/src/game/elements.js';
import { ELEMENT_TYPE_NAMES } from '../web/src/net/assetRules.js';
import { buildLevel, demoMap } from '../web/src/game/map.js';
import { createWorld, emptyInput } from '../web/src/game/world.js';
import { T } from '../web/src/game/constants.js';

const webDir = join(dirname(fileURLToPath(import.meta.url)), '..', 'web');

function gueltigeKarte(extra = {}) {
  return {
    version: 1,
    id: 'test',
    name: 'Test',
    cols: 40,
    rows: 21,
    cell: 16,
    spawn: { x: 2, y: 17 },
    elements: [
      { id: 'g1', type: 'ground', x: 0, y: 19, w: 40, h: 2 },
      { id: 'f1', type: 'flag', x: 36, y: 16 },
    ],
    ...extra,
  };
}

test('eine ordentliche Karte kommt durch, Unfug nicht', () => {
  assert.equal(validateMap(gueltigeKarte()).ok, true);

  const faelle = [
    [{ id: 'Böse!' }, 'invalid_id'],
    [{ name: '   ' }, 'invalid_name'],
    [{ cols: 10 }, 'invalid_size'],
    [{ cell: 24 }, 'invalid_cell'],
    [{ spawn: { x: 99, y: 0 } }, 'invalid_spawn'],
    [{ elements: [{ id: 'x', type: 'ufo', x: 0, y: 0 }] }, 'unknown_type'],
    [
      { elements: [{ id: 'g1', type: 'ground', x: 39, y: 19, w: 5, h: 1 }] },
      'element_out_of_bounds',
    ],
    [{ elements: [gueltigeKarte().elements[0]] }, 'needs_one_flag'],
  ];
  for (const [extra, fehler] of faelle) {
    const result = validateMap(gueltigeKarte(extra));
    assert.equal(result.ok, false, JSON.stringify(extra));
    assert.equal(result.error, fehler);
  }
});

test('zwei Flaggen sind genauso falsch wie keine', () => {
  const karte = gueltigeKarte();
  karte.elements.push({ id: 'f2', type: 'flag', x: 30, y: 16 });
  assert.equal(validateMap(karte).error, 'needs_one_flag');
});

test('unbekannte Eigenschaften überleben die Prüfung nicht', () => {
  const karte = gueltigeKarte();
  karte.elements.push({
    id: 'w1',
    type: 'walker',
    x: 10,
    y: 18,
    props: { speed: 9999, schadcode: '<script>' },
  });
  const result = validateMap(karte);
  assert.equal(result.ok, true);
  const walker = result.map.elements.find((e) => e.type === 'walker');
  assert.equal(walker.props.speed, 25, 'die Zahl muss geklemmt sein');
  assert.equal(walker.props.schadcode, undefined, 'Fremdes fliegt raus');
});

test('die Sammlung weist doppelte Kennungen ab', () => {
  const a = gueltigeKarte();
  const b = gueltigeKarte();
  assert.equal(validateMaps([a, b]).error, 'duplicate_map_id');
  b.id = 'anders';
  assert.equal(validateMaps([a, b]).ok, true);
});

test('slugify macht aus jedem Namen eine brauchbare Kennung', () => {
  assert.equal(slugify('Grüner Wald!!'), 'gruener-wald');
  assert.equal(slugify('***'), 'karte');
  assert.match(slugify('Ein sehr sehr sehr langer Kartenname'), /^[a-z0-9-]{1,24}$/);
});

test('die Demo-Karte besteht ihre eigene Prüfung und rastert sauber', () => {
  const map = demoMap();
  assert.equal(validateMap(map).ok, true);
  const level = buildLevel(map);
  // Der Start steht auf festem Boden.
  assert.equal(level.tileAt(map.spawn.x, map.spawn.y + 2), T.SOLID);
});

test('die Demo-Karte ist mit einfacher Vorausschau schaffbar', () => {
  // Kein Metronom, sondern ein Läufer, der springt, wenn vor ihm Lücke oder
  // Gefahr liegt – grob das, was ein Mensch beim ersten Versuch tut. Er darf
  // sterben (Checkpoints existieren), aber er muss ankommen.
  const world = createWorld(demoMap());
  const gefahren = world.entities.filter((e) => ['spikes', 'walker', 'flyer'].includes(e.type));

  const willJump = () => {
    const b = world.player.body;
    if (!world.player.onGround) return false;
    const col = Math.floor((b.x + b.w + 14) / 16);
    const footRow = Math.floor((b.y + b.h + 2) / 16);
    const gap = [0, 1, 2].every((d) => world.level.tileAt(col, footRow + d) === T.EMPTY);
    const danger = gefahren.some(
      (e) =>
        e.alive !== false && e.x < b.x + 60 && e.x + e.w > b.x + b.w && Math.abs(e.y - b.y) < 40,
    );
    return gap || danger;
  };

  let finished = false;
  let jumpHold = 0;
  for (let t = 0; t < 7200 && !finished; t += 1) {
    const input = emptyInput();
    input.right = true;
    // Beim Aufsetzen darf sofort wieder gesprungen werden – sonst läuft der
    // Läufer nach einem abgebrochenen Sprung stur in die Gefahr.
    if (world.player.onGround && world.player.body.vy === 0) jumpHold = 0;
    if (jumpHold === 0 && willJump()) jumpHold = 16;
    if (jumpHold > 0) {
      input.jump = true;
      input.jumpPressed = jumpHold === 16;
      jumpHold -= 1;
    }
    world.update(input);
    finished = world.finished;
  }
  assert.equal(finished, true, `nicht angekommen; Tode: ${world.deaths}`);
  assert.ok(world.deaths <= 6, `${world.deaths} Tode sind zu viele für die Demo`);
});

test('admin.php nennt dieselben Karten-Zahlen wie mapRules.js', () => {
  const php = readFileSync(join(webDir, 'admin.php'), 'utf8');
  const zahl = (name) => {
    const treffer = php.match(new RegExp(`const\\s+${name}\\s*=\\s*([^;]+);`));
    assert.ok(treffer !== null, `${name} steht nicht in admin.php`);
    return treffer[1]
      .split('*')
      .map((teil) => Number(teil.trim()))
      .reduce((a, b) => a * b, 1);
  };
  assert.equal(zahl('MAX_MAPS'), MAP_LIMITS.maxMaps);
  assert.equal(zahl('MAX_MAP_ELEMENTS'), MAP_LIMITS.maxElements);
  assert.equal(zahl('MAP_MIN_COLS'), MAP_LIMITS.minCols);
  assert.equal(zahl('MAP_MAX_COLS'), MAP_LIMITS.maxCols);
  assert.equal(zahl('MAP_MIN_ROWS'), MAP_LIMITS.minRows);
  assert.equal(zahl('MAP_MAX_ROWS'), MAP_LIMITS.maxRows);
  assert.equal(zahl('MAX_MAP_BYTES'), MAP_LIMITS.maxMapBytes);
  assert.equal(zahl('MAX_MAPS_BYTES'), MAP_LIMITS.maxTotalBytes);

  const liste = php.match(/MAP_ELEMENT_TYPES = \[([^\]]+)\]/s);
  assert.ok(liste !== null);
  const phpTypen = [...liste[1].matchAll(/'([a-z]+)'/g)].map((m) => m[1]).sort();
  assert.deepEqual(phpTypen, [...ELEMENT_TYPES].sort(), 'Element-Typen weichen ab');
  assert.deepEqual(
    [...ELEMENT_TYPE_NAMES].sort(),
    [...ELEMENT_TYPES].sort(),
    'assetRules.ELEMENT_TYPE_NAMES weicht vom Katalog ab',
  );

  const zellen = php.match(/MAP_CELLS\s*=\s*\[([^\]]+)\]/);
  assert.deepEqual(
    zellen[1].split(',').map((n) => Number(n.trim())),
    MAP_LIMITS.cells,
  );
});
