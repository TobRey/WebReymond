// Die Prüfung eines Grafikpakets. Sie ist die einzige Stelle, an der fremde
// Daten ins Spiel kommen – ein Paket wird über den Admin-Bereich hochgeladen
// und danach von jedem Besucher geladen.
//
// Geprüft wird deshalb streng und in beide Richtungen: dass Gültiges
// durchkommt UND dass Ungültiges hängen bleibt.

import test from 'node:test';
import assert from 'node:assert/strict';

import {
  ANIMATION_NAMES,
  FIXED_BOX_TILES,
  LIMITS,
  TILE_NAMES,
  dataUrlBytes,
  emptyPack,
  isPngDataUrl,
  packIsEmpty,
  validatePack,
} from '../web/src/net/assetRules.js';
import { ANIMATIONS } from '../web/src/game/player.js';
import { SLOT } from '../web/src/render/tiles.js';

/** Ein winziges, gültiges PNG (1 × 1, durchsichtig). */
const PNG =
  'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

const framesOf = (image = PNG) => [image, null, null, null, null];

test('das leere Paket ist gültig und gilt als leer', () => {
  const result = validatePack(emptyPack());
  assert.equal(result.ok, true);
  assert.ok(packIsEmpty(result.pack));
});

test('die Signatur wird wirklich geprüft, nicht nur der Text davor', () => {
  assert.equal(isPngDataUrl(PNG), true);
  // Richtiger Vorspann, aber der Inhalt ist kein PNG: genau der Fall, mit dem
  // man sonst eine getarnte Datei in die Ablage bekäme.
  assert.equal(isPngDataUrl('data:image/png;base64,PD9waHAgZWNobyAxOw=='), false);
  assert.equal(isPngDataUrl('data:image/gif;base64,R0lGODlh'), false);
  assert.equal(isPngDataUrl('data:image/png;base64,'), false);
  assert.equal(isPngDataUrl('nicht einmal eine URL'), false);
  assert.equal(isPngDataUrl(null), false);
  assert.equal(isPngDataUrl(42), false);
});

test('dataUrlBytes zählt die Bytes hinter dem Komma', () => {
  assert.equal(dataUrlBytes('data:image/png;base64,QQ=='), 1);
  assert.equal(dataUrlBytes('data:image/png;base64,QUJD'), 3);
  assert.equal(dataUrlBytes(null), 0);
});

test('die Bildzahl je Bewegung deckt sich mit dem Spiel', () => {
  for (const [name, definition] of Object.entries(ANIMATIONS)) {
    assert.ok(ANIMATION_NAMES.includes(name), `"${name}" fehlt in ANIMATION_NAMES`);
    assert.equal(
      definition.frames,
      LIMITS.framesPerAnimation,
      `"${name}" hat ${definition.frames} Bilder, das Paket bietet ${LIMITS.framesPerAnimation}`,
    );
  }
  assert.equal(ANIMATION_NAMES.length, Object.keys(ANIMATIONS).length);
});

test('jeder anbietbare Kachelname existiert wirklich als Platz', () => {
  for (const name of TILE_NAMES) {
    assert.ok(name in SLOT, `"${name}" ist kein Platz im Kachelblatt`);
  }
});

test('ein vollständiges Paket kommt durch', () => {
  const result = validatePack({
    version: 1,
    player: { hitbox: { x: 10, y: 12, w: 12, h: 20 }, frames: { run: framesOf() } },
    tiles: { STONE_A: { image: PNG, box: { x: 0, y: 4, w: 16, h: 12 } } },
    background: { layers: [{ image: PNG, speed: 0.3 }] },
  });
  assert.equal(result.ok, true, result.error);
  assert.equal(result.pack.player.frames.run.length, 5);
  assert.deepEqual(result.pack.tiles.STONE_A.box, { x: 0, y: 4, w: 16, h: 12 });
  assert.equal(result.pack.background.layers[0].speed, 0.3);
});

test('eine Bewegung ohne ein einziges Bild wird weggelassen', () => {
  // Sonst überschriebe sie die mitgelieferte Grafik mit Nichts.
  const result = validatePack({
    version: 1,
    player: { frames: { run: [null, null, null, null, null] } },
  });
  assert.equal(result.ok, true);
  assert.equal(result.pack.player, undefined);
});

test('falsche Version, unbekannte Namen und kaputte Kästen fliegen raus', () => {
  const rejected = [
    [{ version: 2 }, 'wrong_version'],
    [null, 'invalid_pack'],
    ['nein', 'invalid_pack'],
    [{ version: 1, player: { frames: { fliegen: framesOf() } } }, 'unknown_animation'],
    [{ version: 1, player: { frames: { run: [PNG, null] } } }, 'wrong_frame_count'],
    [{ version: 1, player: { frames: { run: framesOf('data:text/html,<b>') } } }, 'not_a_png'],
    [{ version: 1, player: { hitbox: { x: 0, y: 0, w: 99, h: 20 } } }, 'invalid_hitbox'],
    [{ version: 1, player: { hitbox: { x: 0, y: 0, w: 2, h: 2 } } }, 'hitbox_too_small'],
    [{ version: 1, tiles: { MOND: { image: PNG } } }, 'unknown_tile'],
    [{ version: 1, tiles: { STONE_A: { box: { x: 12, y: 0, w: 8, h: 16 } } } }, 'invalid_box'],
    [{ version: 1, background: { layers: [{ image: PNG, speed: 9 }] } }, 'invalid_speed'],
    [
      { version: 1, background: { layers: Array(5).fill({ image: PNG, speed: 1 }) } },
      'too_many_layers',
    ],
  ];

  for (const [input, expected] of rejected) {
    const result = validatePack(input);
    assert.equal(result.ok, false, `hätte abgelehnt werden müssen: ${JSON.stringify(input)}`);
    assert.equal(result.error, expected, `falscher Grund für ${JSON.stringify(input)}`);
  }
});

test('an Wänden lässt sich kein Kasten einzeichnen', () => {
  for (const name of FIXED_BOX_TILES) {
    const result = validatePack({
      version: 1,
      tiles: { [name]: { box: { x: 4, y: 0, w: 8, h: 16 } } },
    });
    assert.equal(result.ok, false, `${name}: der Kasten hätte abgelehnt werden müssen`);
    assert.equal(result.error, 'box_not_allowed');
  }

  // Eine neue Grafik ist dort trotzdem erlaubt – nur die Physik bleibt fest.
  const withImage = validatePack({ version: 1, tiles: { WALL_A: { image: PNG } } });
  assert.equal(withImage.ok, true, withImage.error);
});

test('zu grosse Bilder werden abgewiesen', () => {
  // Gültige Signatur, gültiges base64 – nur eben viel zu lang. Genau so sähe
  // der Versuch aus, die Ablage mit einem Riesenbild vollzuschreiben.
  const huge = `data:image/png;base64,iVBORw0KGgoAAAANSUhEUg${'A'.repeat(LIMITS.maxImageBytes * 2)}`;
  assert.equal(isPngDataUrl(huge), true, 'die Signatur soll stimmen, nur die Grösse nicht');

  const result = validatePack({ version: 1, tiles: { STONE_A: { image: huge } } });
  assert.equal(result.ok, false);
  assert.equal(result.error, 'image_too_large');
});

test('unbekannte Felder werden nicht durchgereicht', () => {
  // Was hier durchkäme, läge danach in der Ablage und ginge an jeden Besucher.
  const result = validatePack({
    version: 1,
    boeses: '<script>',
    player: { hitbox: { x: 10, y: 12, w: 12, h: 20 }, sonst: 'was' },
    tiles: { STONE_A: { image: PNG, kind: 'toedlich' } },
  });
  assert.equal(result.ok, true, result.error);
  assert.deepEqual(Object.keys(result.pack).sort(), ['player', 'tiles', 'version']);
  assert.deepEqual(Object.keys(result.pack.player), ['hitbox']);
  assert.deepEqual(Object.keys(result.pack.tiles.STONE_A), ['image']);
});
