// Der feste Bereich einer Kachel innerhalb ihrer 16 × 16 grossen Zelle.
//
// Voreingestellt ist überall die ganze Zelle – solange niemand im Admin-Bereich
// etwas anderes einzeichnet, rechnet die Physik also exakt wie vorher.
//
// WOZU DAS GUT IST
// Wer eigene Kachelgrafiken einsetzt, hat selten Bilder, die die Zelle randlos
// füllen: eine Plattform hat oben Gras und unten einen verzierten Sockel, und
// stehen soll man auf der Graskante, nicht drei Pixel darüber in der Luft.
// Ohne einstellbaren Kasten müsste sich die Grafik nach der Physik richten
// statt umgekehrt.
//
// WAS BEWUSST NICHT GEHT
// Die Kachelart (fest, nur von oben tragend, bröckelnd) ist NICHT einstellbar.
// Sie kommt aus dem Element-Typ auf der Karte; einstellbar ist nur, WO in der
// Zelle die Kachel fest ist – nicht, was sie bedeutet.

import { TILE } from './constants.js';

/** Die ganze Zelle. Wird geteilt, nie verändert. */
const FULL = Object.freeze({ x: 0, y: 0, w: TILE, h: TILE });

/**
 * Kachelart -> Kasten. Wird von render/assets.js aus einem Paket überschrieben
 * und von resetTileBoxes() wieder geleert.
 * @type {Map<number, {x:number,y:number,w:number,h:number}>}
 */
const boxes = new Map();

/** @returns {{x:number,y:number,w:number,h:number}} */
export function tileBox(tile) {
  return boxes.get(tile) ?? FULL;
}

/** Setzt den Kasten einer Kachelart. */
export function setTileBox(tile, box) {
  const clamped = {
    x: clamp(box.x, 0, TILE - 1),
    y: clamp(box.y, 0, TILE - 1),
    w: clamp(box.w, 1, TILE),
    h: clamp(box.h, 1, TILE),
  };
  clamped.w = Math.min(clamped.w, TILE - clamped.x);
  clamped.h = Math.min(clamped.h, TILE - clamped.y);
  boxes.set(tile, clamped);
}

export function resetTileBoxes() {
  boxes.clear();
}

function clamp(value, min, max) {
  const n = Math.round(Number(value));
  if (!Number.isFinite(n)) return min;
  return n < min ? min : n > max ? max : n;
}
