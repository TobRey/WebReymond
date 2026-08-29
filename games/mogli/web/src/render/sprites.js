// Aus den Zeichenketten in frames.js werden beim Start Offscreen-Canvas
// gebrannt. Pro Animation ein Blatt: obere Zeile nach rechts schauend, untere
// Zeile bereits gespiegelt.
//
// Warum vorgespiegelt statt ctx.scale(-1, 1) beim Zeichnen: das Spiegeln zur
// Laufzeit erzeugt bei nicht ganzzahligen Verschiebungen eine halbe Pixelnaht,
// und es kostet pro Bild ein save/restore. Einmal brennen ist beides los.

import { FRAMES, FRAME_SIZE } from './frames.js';
import { PALETTE } from './palette.js';
import { frameOverride } from './assets.js';

function createCanvas(width, height) {
  const canvas = document.createElement('canvas');
  canvas.width = width;
  canvas.height = height;
  return canvas;
}

/**
 * Malt ein Zeichenketten-Raster Pixel für Pixel. Wird von den Figurenbildern
 * und von den Wesen der Vorgeschichte benutzt.
 *
 * @param {CanvasRenderingContext2D} ctx
 * @param {string[]} rows quadratisches Raster, ein Zeichen je Pixel
 * @param {number} originX
 * @param {number} originY
 * @param {boolean} flip waagerecht spiegeln
 */
export function paintPixels(ctx, rows, originX, originY, flip = false) {
  const size = rows.length;
  for (let y = 0; y < size; y += 1) {
    const row = rows[y];
    for (let x = 0; x < size; x += 1) {
      const key = row[x];
      if (key === '.') continue;
      const color = PALETTE[key];
      if (color === undefined) continue;
      ctx.fillStyle = color;
      const px = flip ? size - 1 - x : x;
      ctx.fillRect(originX + px, originY + y, 1, 1);
    }
  }
}

function paintFrame(ctx, rows, originX, originY, flip) {
  paintPixels(ctx, rows, originX, originY, flip);
}

/**
 * Ein eingesetztes Bild in beide Zeilen des Blattes brennen.
 *
 * Gespiegelt wird über eine negative Skalierung. Bei den Zeichenketten geht
 * das Spiegeln pixelweise, hier nicht – ein PNG kennt seine Pixel nicht.
 */
function paintImage(ctx, image, originX, originY, flip) {
  ctx.save();
  if (flip) {
    ctx.translate(originX + FRAME_SIZE, originY);
    ctx.scale(-1, 1);
  } else {
    ctx.translate(originX, originY);
  }
  ctx.drawImage(image, 0, 0, FRAME_SIZE, FRAME_SIZE);
  ctx.restore();
}

/** @returns {Record<string, {canvas: HTMLCanvasElement, count: number}>} */
export function buildAtlases() {
  /** @type {Record<string, {canvas: HTMLCanvasElement, count: number}>} */
  const atlases = {};
  for (const [name, frames] of Object.entries(FRAMES)) {
    const canvas = createCanvas(FRAME_SIZE * frames.length, FRAME_SIZE * 2);
    const ctx = canvas.getContext('2d');
    ctx.imageSmoothingEnabled = false;

    // Eingesetzte Bilder ersetzen die mitgelieferten platzweise: wer nur drei
    // von fünf Laufbildern hochlädt, bekommt für die anderen zwei weiterhin
    // die mitgelieferte Grafik. Eine Lücke im Blatt wäre ein Loch im Bild.
    const custom = frameOverride(name);

    frames.forEach((rows, index) => {
      const image = custom?.[index] ?? null;
      if (image === null) {
        paintFrame(ctx, rows, index * FRAME_SIZE, 0, false);
        paintFrame(ctx, rows, index * FRAME_SIZE, FRAME_SIZE, true);
      } else {
        paintImage(ctx, image, index * FRAME_SIZE, 0, false);
        paintImage(ctx, image, index * FRAME_SIZE, FRAME_SIZE, true);
      }
    });
    atlases[name] = { canvas, count: frames.length };
  }
  return atlases;
}

// Eingefärbte Kopien (weisses Aufblitzen, roter Todesschimmer). Es gibt nur
// eine Handvoll davon, deshalb reicht ein kleiner Zwischenspeicher.
const tintCache = new Map();

function tintedCanvas(atlas, name, color) {
  const key = `${name}|${color}`;
  const cached = tintCache.get(key);
  if (cached !== undefined) return cached;

  const canvas = createCanvas(atlas.canvas.width, atlas.canvas.height);
  const ctx = canvas.getContext('2d');
  ctx.imageSmoothingEnabled = false;
  ctx.drawImage(atlas.canvas, 0, 0);
  ctx.globalCompositeOperation = 'source-atop';
  ctx.fillStyle = color;
  ctx.fillRect(0, 0, canvas.width, canvas.height);
  ctx.globalCompositeOperation = 'source-over';

  if (tintCache.size > 8) tintCache.clear();
  tintCache.set(key, canvas);
  return canvas;
}

/**
 * Zeichnet ein Einzelbild. x/y ist die linke obere Ecke des 32×32-Bildes und
 * wird auf ganze Pixel gerundet – sonst verschmiert die Pixelgrafik.
 *
 * @param {CanvasRenderingContext2D} ctx
 * @param {{canvas: HTMLCanvasElement, count: number}} atlas
 * @param {string} name
 * @param {number} frame
 * @param {number} x
 * @param {number} y
 * @param {{flip?: boolean, tint?: string|null, alpha?: number}} [options]
 */
export function drawSprite(ctx, atlas, name, frame, x, y, options = {}) {
  const index = Math.max(0, Math.min(atlas.count - 1, frame | 0));
  const source = options.tint ? tintedCanvas(atlas, name, options.tint) : atlas.canvas;
  const sy = options.flip ? FRAME_SIZE : 0;

  const alpha = options.alpha === undefined ? 1 : options.alpha;
  if (alpha !== 1) ctx.globalAlpha = alpha;
  ctx.drawImage(
    source,
    index * FRAME_SIZE,
    sy,
    FRAME_SIZE,
    FRAME_SIZE,
    Math.round(x),
    Math.round(y),
    FRAME_SIZE,
    FRAME_SIZE,
  );
  if (alpha !== 1) ctx.globalAlpha = 1;
}

export { FRAME_SIZE };
