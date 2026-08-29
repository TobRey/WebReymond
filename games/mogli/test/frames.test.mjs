// Die Pixeldaten sind von Hand gesetzte Zeichenketten. Ein verrutschtes
// Zeichen fiele im Browser nur als schiefes Bild auf – hier fällt es sofort auf.

import test from 'node:test';
import assert from 'node:assert/strict';

import { FRAMES, FRAME_SIZE } from '../web/src/render/frames.js';
import { CREATURES, CREATURE_SIZE } from '../web/src/render/creatures.js';
import { PALETTE } from '../web/src/render/palette.js';
import { ANIMATIONS } from '../web/src/game/player.js';

test('jedes Einzelbild ist genau 24 × 24 Zeichen gross', () => {
  for (const [name, frames] of Object.entries(FRAMES)) {
    frames.forEach((rows, index) => {
      assert.equal(rows.length, FRAME_SIZE, `${name}[${index}] hat ${rows.length} Zeilen`);
      rows.forEach((row, y) => {
        assert.equal(
          row.length,
          FRAME_SIZE,
          `${name}[${index}] Zeile ${y} hat ${row.length} Zeichen`,
        );
      });
    });
  }
});

test('es kommen nur Zeichen aus der Palette vor', () => {
  for (const [name, frames] of Object.entries(FRAMES)) {
    frames.forEach((rows, index) => {
      rows.forEach((row, y) => {
        for (const char of row) {
          assert.ok(
            char === '.' || char in PALETTE,
            `${name}[${index}] Zeile ${y}: unbekanntes Zeichen "${char}"`,
          );
        }
      });
    });
  }
});

test('zu jeder Animation gibt es genau so viele Bilder wie angekündigt', () => {
  for (const [name, definition] of Object.entries(ANIMATIONS)) {
    assert.ok(name in FRAMES, `zur Animation "${name}" fehlen die Bilder`);
    assert.equal(
      FRAMES[name].length,
      definition.frames,
      `"${name}": ${FRAMES[name].length} Bilder statt ${definition.frames}`,
    );
  }
  for (const name of Object.keys(FRAMES)) {
    assert.ok(name in ANIMATIONS, `zu den Bildern "${name}" fehlt die Animation`);
  }
});

test('kein Einzelbild ist vollständig leer', () => {
  for (const [name, frames] of Object.entries(FRAMES)) {
    frames.forEach((rows, index) => {
      const pixels = rows
        .join('')
        .split('')
        .filter((c) => c !== '.').length;
      assert.ok(pixels > 20, `${name}[${index}] hat nur ${pixels} sichtbare Pixel`);
    });
  }
});

test('Folgeanimationen zeigen auf vorhandene Animationen', () => {
  for (const [name, definition] of Object.entries(ANIMATIONS)) {
    if (definition.next === undefined) continue;
    assert.ok(
      definition.next in ANIMATIONS,
      `"${name}" verweist auf unbekanntes "${definition.next}"`,
    );
  }
});

test('die Wesen der Vorgeschichte sind ebenfalls sauber gerastert', () => {
  for (const [name, frames] of Object.entries(CREATURES)) {
    assert.ok(frames.length >= 2, `"${name}" braucht mindestens zwei Bilder zum Blinzeln`);
    frames.forEach((rows, index) => {
      assert.equal(rows.length, CREATURE_SIZE, `${name}[${index}] hat ${rows.length} Zeilen`);
      rows.forEach((row, y) => {
        assert.equal(
          row.length,
          CREATURE_SIZE,
          `${name}[${index}] Zeile ${y} hat ${row.length} Zeichen`,
        );
        for (const char of row) {
          assert.ok(
            char === '.' || char in PALETTE,
            `${name}[${index}] Zeile ${y}: unbekanntes Zeichen "${char}"`,
          );
        }
      });
    });
  }
});
