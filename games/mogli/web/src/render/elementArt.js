// Platzhalter-Grafik für die Elemente, die nicht aus dem Kachelblatt kommen.
//
// Feste Blöcke, Plattformen, Bröckelsteine, Stacheln und Smaragde zeichnet
// die Szene aus dem vorhandenen Kachelblatt (tiles.js) – hier stehen nur die
// Typen, die es dort nicht gibt. Alles ist gemalt, nichts geladen: das Repo
// bleibt frei von Binärdaten, und das Spiel läuft ohne einen einzigen Upload.
//
// Wer eigene Grafik einsetzt (Admin-Reiter "Elemente"), überdeckt diese
// Bilder; elementImage() unten entscheidet.

import { PALETTE } from './palette.js';
import { elementOverride } from './assets.js';

const px = (ctx, x, y, w, h, color) => {
  ctx.fillStyle = color;
  ctx.fillRect(x, y, w, h);
};

const C = {
  wood: '#6b4a2b',
  woodDark: '#4a3018',
  metal: '#8fa383',
  metalLight: '#c8d8bd',
  gold: '#f5c53a',
  goldDark: '#a8771c',
  red: '#c3281c',
  redDark: '#7c1410',
  teal: '#3fe3a8',
  tealDark: '#14a072',
  purple: '#9a4dd7',
  purpleDark: '#5c2a86',
  skin: '#c07a42',
  dark: '#12241d',
};

/** Jede Malfunktion bekommt einen Canvas in der Naturgrösse des Elements. */
const PAINTERS = {
  mover(ctx, w, h) {
    px(ctx, 0, 2, w, h - 4, C.wood);
    px(ctx, 0, 2, w, 2, PALETTE[10] ?? '#7fb85a');
    px(ctx, 0, h - 4, w, 2, C.woodDark);
    for (let x = 4; x < w; x += 12) px(ctx, x, 5, 2, h - 8, C.woodDark);
    px(ctx, 2, h - 2, 3, 2, C.metal);
    px(ctx, w - 5, h - 2, 3, 2, C.metal);
  },
  spring(ctx, w, h) {
    px(ctx, 2, h - 3, w - 4, 3, C.woodDark);
    px(ctx, 4, h - 7, w - 8, 2, C.metal);
    px(ctx, 5, h - 10, w - 10, 2, C.metal);
    px(ctx, 3, 2, w - 6, 4, C.red);
    px(ctx, 3, 2, w - 6, 1, '#e8604f');
  },
  key(ctx, w, _h) {
    px(ctx, 2, 3, 6, 6, C.gold);
    px(ctx, 4, 5, 2, 2, C.dark);
    px(ctx, 7, 5, w - 9, 2, C.gold);
    px(ctx, w - 4, 7, 2, 3, C.gold);
    px(ctx, w - 7, 7, 2, 2, C.goldDark);
  },
  door(ctx, w, h) {
    px(ctx, 0, 0, w, h, C.woodDark);
    px(ctx, 2, 2, w - 4, h - 2, C.wood);
    px(ctx, 4, 4, w - 8, h - 4, C.woodDark);
    px(ctx, 5, 5, w - 10, h - 5, C.wood);
    px(ctx, w - 8, Math.floor(h / 2), 3, 3, C.gold);
    for (let y = 5; y < h; y += 8) px(ctx, 5, y, w - 10, 1, C.woodDark);
  },
  portal(ctx, w, h) {
    const cx = w / 2;
    const cy = h / 2;
    for (let r = Math.min(cx, cy); r > 1; r -= 2) {
      ctx.fillStyle = r % 4 < 2 ? C.purple : C.purpleDark;
      ctx.beginPath();
      ctx.arc(cx, cy, r, 0, Math.PI * 2);
      ctx.fill();
    }
    px(ctx, cx - 1, cy - 1, 2, 2, '#f0e6ff');
  },
  walker(ctx, w, h) {
    px(ctx, 1, 4, w - 2, h - 6, C.redDark);
    px(ctx, 2, 5, w - 4, h - 9, C.red);
    px(ctx, 3, 6, 3, 3, '#fff');
    px(ctx, w - 6, 6, 3, 3, '#fff');
    px(ctx, 4, 7, 1, 1, C.dark);
    px(ctx, w - 5, 7, 1, 1, C.dark);
    px(ctx, 2, h - 2, 3, 2, C.dark);
    px(ctx, w - 5, h - 2, 3, 2, C.dark);
  },
  flyer(ctx, w, h) {
    px(ctx, 3, 5, w - 6, h - 8, C.purpleDark);
    px(ctx, 4, 6, w - 8, h - 10, C.purple);
    px(ctx, 0, 3, 4, 5, C.purpleDark);
    px(ctx, w - 4, 3, 4, 5, C.purpleDark);
    px(ctx, 5, 8, 2, 2, '#fff');
    px(ctx, w - 7, 8, 2, 2, '#fff');
  },
  checkpoint(ctx, w, h) {
    px(ctx, Math.floor(w / 2) - 1, 2, 2, h - 2, C.wood);
    px(ctx, Math.floor(w / 2) + 1, 3, w / 2 - 1, 6, C.metal);
  },
  checkpointActive(ctx, w, h) {
    px(ctx, Math.floor(w / 2) - 1, 2, 2, h - 2, C.wood);
    px(ctx, Math.floor(w / 2) + 1, 3, w / 2 - 1, 6, C.teal);
    px(ctx, Math.floor(w / 2) + 1, 3, w / 2 - 1, 2, '#baffe4');
  },
  flag(ctx, w, h) {
    px(ctx, 2, 0, 3, h, C.metal);
    px(ctx, 2, 0, 3, 2, C.gold);
    px(ctx, 5, 2, w - 7, 8, C.teal);
    px(ctx, 5, 2, w - 7, 2, '#baffe4');
    px(ctx, 5, 8, w - 7, 2, C.tealDark);
  },
};

let cache = null;

/**
 * Baut alle Platzhalter in Naturgrösse (Katalogzellen × 16 px).
 * @returns {Map<string, HTMLCanvasElement>}
 */
export function buildElementArt(sizes) {
  const art = new Map();
  for (const [key, paint] of Object.entries(PAINTERS)) {
    const type = key === 'checkpointActive' ? 'checkpoint' : key;
    const [cw, ch] = sizes[type] ?? [1, 1];
    const canvas = document.createElement('canvas');
    canvas.width = cw * 16;
    canvas.height = ch * 16;
    const ctx = canvas.getContext('2d');
    paint(ctx, canvas.width, canvas.height);
    art.set(key, canvas);
  }
  return art;
}

/**
 * Das Bild für ein Element: eigene Grafik aus dem Paket, sonst Platzhalter.
 * `variant` unterscheidet Zustände (Checkpoint an/aus).
 */
export function elementImage(sizes, type, variant = null) {
  const override = elementOverride(type);
  if (override !== null) return override;
  if (cache === null) cache = buildElementArt(sizes);
  if (variant !== null && cache.has(variant)) return cache.get(variant);
  return cache.get(type) ?? null;
}

/** Nach einem Paketwechsel neu aufbauen. */
export function resetElementArt() {
  cache = null;
}
