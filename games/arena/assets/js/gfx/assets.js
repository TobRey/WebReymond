import { decodeGif } from './gif.js';

/**
 * Ein Sprite ist eine Liste fertiger Frames plus Zeitangaben.
 * PNGs haben genau einen Frame, GIFs ihre dekodierten Einzelbilder.
 */
export class Sprite {
  constructor(frames, delays, width, height, src) {
    this.frames = frames;
    this.delays = delays;
    this.width = width;
    this.height = height;
    this.src = src;
    this.duration = delays.reduce((a, b) => a + b, 0) || 100;
    this.animated = frames.length > 1;
  }

  /** Endlos laufende Animation. */
  frameAt(timeMs) {
    if (!this.animated) return this.frames[0];
    let t = timeMs % this.duration;
    for (let i = 0; i < this.frames.length; i++) {
      t -= this.delays[i];
      if (t < 0) return this.frames[i];
    }
    return this.frames[this.frames.length - 1];
  }

  /** Einmalige Animation - liefert null, wenn sie durchgelaufen ist. */
  frameOnce(timeMs) {
    if (!this.animated) return timeMs < this.duration ? this.frames[0] : null;
    if (timeMs >= this.duration) return null;
    let t = timeMs;
    for (let i = 0; i < this.frames.length; i++) {
      t -= this.delays[i];
      if (t < 0) return this.frames[i];
    }
    return null;
  }
}


/**
 * Schneidet transparente Raender weg.
 *
 * Die Quell-GIFs haben sehr unterschiedlich viel Leerraum (256x256 bis
 * 1000x1000). Ohne Trimmen wuerde derselbe Skalierungswert bei jedem
 * Gegner eine andere sichtbare Groesse ergeben. Die Grenzen werden auf
 * einer verkleinerten Kopie gesucht, das kostet beim Laden fast nichts.
 */
function trimFrames(frames, width, height) {
  const probeSize = 110;
  const scale = Math.min(1, probeSize / Math.max(width, height));
  const pw = Math.max(1, Math.round(width * scale));
  const ph = Math.max(1, Math.round(height * scale));
  const probe = document.createElement('canvas');
  probe.width = pw;
  probe.height = ph;
  const pctx = probe.getContext('2d', { willReadFrequently: true });

  let minX = pw;
  let minY = ph;
  let maxX = -1;
  let maxY = -1;

  for (const frame of frames) {
    pctx.clearRect(0, 0, pw, ph);
    pctx.drawImage(frame, 0, 0, pw, ph);
    const data = pctx.getImageData(0, 0, pw, ph).data;
    for (let y = 0; y < ph; y++) {
      for (let x = 0; x < pw; x++) {
        if (data[(y * pw + x) * 4 + 3] > 12) {
          if (x < minX) minX = x;
          if (x > maxX) maxX = x;
          if (y < minY) minY = y;
          if (y > maxY) maxY = y;
        }
      }
    }
  }

  if (maxX < 0) return null;

  const pad = 1;
  const x0 = Math.max(0, Math.floor((minX - pad) / scale));
  const y0 = Math.max(0, Math.floor((minY - pad) / scale));
  const x1 = Math.min(width, Math.ceil((maxX + 1 + pad) / scale));
  const y1 = Math.min(height, Math.ceil((maxY + 1 + pad) / scale));
  const w = x1 - x0;
  const h = y1 - y0;
  if (w <= 0 || h <= 0) return null;
  // Kaum Rand vorhanden - dann lohnt das Zuschneiden nicht.
  if (w * h > width * height * 0.93) return null;

  const trimmed = frames.map((frame) => {
    const canvas = document.createElement('canvas');
    canvas.width = w;
    canvas.height = h;
    canvas.getContext('2d').drawImage(frame, x0, y0, w, h, 0, 0, w, h);
    return canvas;
  });
  return { frames: trimmed, width: w, height: h };
}

const cache = new Map();
const pending = new Map();

/** Ersatzsprite, falls eine Datei fehlt - klar erkennbar, aber nicht stoerend. */
function fallbackSprite(src, label = '?') {
  const size = 48;
  const c = document.createElement('canvas');
  c.width = size;
  c.height = size;
  const ctx = c.getContext('2d');
  ctx.fillStyle = 'rgba(255,90,140,0.22)';
  ctx.fillRect(0, 0, size, size);
  ctx.strokeStyle = '#ff5a8c';
  ctx.lineWidth = 2;
  ctx.strokeRect(1, 1, size - 2, size - 2);
  ctx.fillStyle = '#ff9ec0';
  ctx.font = 'bold 20px system-ui, sans-serif';
  ctx.textAlign = 'center';
  ctx.textBaseline = 'middle';
  ctx.fillText(label, size / 2, size / 2);
  return new Sprite([c], [100], size, size, src);
}

async function loadOne(src) {
  const isGif = /\.gif(\?|$)/i.test(src);
  if (isGif) {
    const res = await fetch(src);
    if (!res.ok) throw new Error(res.status + ' ' + src);
    const gif = decodeGif(await res.arrayBuffer());
    let frames = gif.frames.map((f) => f.canvas);
    let width = gif.width;
    let height = gif.height;
    const trimmed = trimFrames(frames, width, height);
    if (trimmed) {
      frames = trimmed.frames;
      width = trimmed.width;
      height = trimmed.height;
    }
    return new Sprite(frames, gif.frames.map((f) => f.delay), width, height, src);
  }
  const img = new Image();
  img.decoding = 'async';
  await new Promise((resolve, reject) => {
    img.onload = resolve;
    img.onerror = () => reject(new Error('Bild fehlt: ' + src));
    img.src = src;
  });
  return new Sprite([img], [100], img.naturalWidth, img.naturalHeight, src);
}

export const Assets = {
  missing: [],

  /** Laedt ein Asset genau einmal; Fehler enden in einem sichtbaren Platzhalter. */
  async load(src) {
    if (!src) return fallbackSprite('', '?');
    if (cache.has(src)) return cache.get(src);
    if (pending.has(src)) return pending.get(src);

    const task = loadOne(src)
      .catch((err) => {
        console.warn('[assets]', err.message);
        if (!Assets.missing.includes(src)) Assets.missing.push(src);
        return fallbackSprite(src, '!');
      })
      .then((sprite) => {
        cache.set(src, sprite);
        pending.delete(src);
        return sprite;
      });

    pending.set(src, task);
    return task;
  },

  async loadAll(list) {
    const unique = [...new Set(list.filter(Boolean))];
    await Promise.all(unique.map((src) => Assets.load(src)));
  },

  get(src) {
    return cache.get(src) || null;
  },

  /** Verwirft ein Asset, damit ein neuer Upload sofort sichtbar wird. */
  forget(src) {
    cache.delete(src);
    pending.delete(src);
  },
};
