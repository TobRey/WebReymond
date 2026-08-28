// Kacheln werden prozedural gezeichnet statt als Zeichenketten hinterlegt:
// Fels, Wand und Stacheln sind aus wenigen Rechtecken aufgebaut, da wären
// 16 Zeilen Text je Kachel nur Ballast. Der Charakter dagegen lebt von
// handgesetzten Pixeln – deshalb liegt er in frames.js.

import { PALETTE, COLORS } from './palette.js';

export const TILE_SIZE = 16;

/** Feste Plätze im Kachelblatt. */
export const SLOT = {
  SOLID_A: 0,
  SOLID_B: 1,
  CRUMBLE_0: 2,
  CRUMBLE_1: 3,
  CRUMBLE_2: 4,
  WALL: 5,
  SPIKE: 6,
  ONEWAY: 7,
  GEM_0: 8,
  SPRING: 12,
  // Verdeckte Kacheln: dasselbe Gestein, aber ohne Lichtkante oben. Bekäme
  // jede Kachel eines massiven Blocks ihre Oberkante, sähe der Boden aus wie
  // ein Stapel Regalbretter statt wie Fels.
  BURIED_A: 13,
  BURIED_B: 14,
};

const SLOT_COUNT = 15;

// Feste Sprenkelmuster – kein Zufall zur Laufzeit, damit dieselbe Kachel
// immer gleich aussieht.
const SPECKLES_A = [
  [3, 7],
  [9, 6],
  [5, 11],
  [12, 9],
  [7, 13],
];
const SPECKLES_B = [
  [2, 9],
  [6, 5],
  [11, 12],
  [13, 7],
  [4, 13],
];

function rock(ctx, ox, oy, speckles, withTop = true) {
  ctx.fillStyle = PALETTE[1];
  ctx.fillRect(ox, oy, TILE_SIZE, TILE_SIZE);
  if (withTop) {
    // Oberkante: heller Streifen plus feine Lichtlinie. Das gibt der Plattform
    // eine klare Standfläche, ohne dass man einen Umriss malen muss.
    ctx.fillStyle = PALETTE[2];
    ctx.fillRect(ox, oy, TILE_SIZE, 3);
    ctx.fillStyle = PALETTE[4];
    ctx.fillRect(ox, oy, TILE_SIZE, 1);
  }
  ctx.fillStyle = PALETTE[0];
  ctx.fillRect(ox, oy + TILE_SIZE - 2, TILE_SIZE, 2);
  ctx.fillStyle = PALETTE[2];
  for (const [x, y] of speckles) ctx.fillRect(ox + x, oy + y, 1, 1);
}

function cracks(ctx, ox, oy, stage) {
  ctx.fillStyle = PALETTE[0];
  if (stage >= 0) {
    ctx.fillRect(ox + 7, oy + 4, 1, 5);
    ctx.fillRect(ox + 6, oy + 8, 1, 3);
  }
  if (stage >= 1) {
    ctx.fillRect(ox + 3, oy + 5, 1, 6);
    ctx.fillRect(ox + 4, oy + 9, 2, 1);
    ctx.fillRect(ox + 11, oy + 4, 1, 7);
  }
  if (stage >= 2) {
    ctx.fillRect(ox + 8, oy + 3, 5, 1);
    ctx.fillRect(ox + 1, oy + 7, 3, 1);
    ctx.fillRect(ox + 12, oy + 9, 1, 5);
    ctx.fillRect(ox + 5, oy + 12, 4, 1);
  }
}

function wall(ctx, ox, oy) {
  ctx.fillStyle = COLORS.nearRock;
  ctx.fillRect(ox, oy, TILE_SIZE, TILE_SIZE);
  ctx.fillStyle = PALETTE[1];
  ctx.fillRect(ox + 1, oy, TILE_SIZE - 2, TILE_SIZE);
  // Waagerechte Griffkanten – daran erkennt man auf einen Blick, dass hier
  // gerutscht und abgesprungen werden kann.
  ctx.fillStyle = PALETTE[2];
  for (let y = 2; y < TILE_SIZE; y += 5) {
    ctx.fillRect(ox + 2, oy + y, TILE_SIZE - 4, 1);
  }
  ctx.fillStyle = PALETTE[0];
  ctx.fillRect(ox, oy, 1, TILE_SIZE);
  ctx.fillRect(ox + TILE_SIZE - 1, oy, 1, TILE_SIZE);
}

function spike(ctx, ox, oy) {
  // Vier Zacken, nach oben zeigend, unten auf einer Sockelleiste.
  ctx.fillStyle = PALETTE[2];
  ctx.fillRect(ox, oy + TILE_SIZE - 3, TILE_SIZE, 3);
  for (let i = 0; i < 4; i += 1) {
    const cx = ox + i * 4 + 2;
    for (let h = 0; h < 11; h += 1) {
      const halfWidth = Math.max(0, Math.round((h / 11) * 2));
      ctx.fillStyle = h < 4 ? PALETTE[4] : PALETTE[3];
      ctx.fillRect(cx - halfWidth, oy + 2 + h, halfWidth * 2 + 1, 1);
    }
  }
  ctx.fillStyle = PALETTE[0];
  ctx.fillRect(ox, oy + TILE_SIZE - 1, TILE_SIZE, 1);
}

function oneway(ctx, ox, oy) {
  // Nur oben eine dünne Platte: signalisiert "von unten durchlässig".
  ctx.fillStyle = PALETTE[3];
  ctx.fillRect(ox, oy, TILE_SIZE, 1);
  ctx.fillStyle = PALETTE[2];
  ctx.fillRect(ox, oy + 1, TILE_SIZE, 3);
  ctx.fillStyle = PALETTE[0];
  ctx.fillRect(ox, oy + 4, TILE_SIZE, 1);
  for (let x = 1; x < TILE_SIZE; x += 4) {
    ctx.fillStyle = PALETTE[1];
    ctx.fillRect(ox + x, oy + 1, 1, 3);
  }
}

function gem(ctx, ox, oy, frame) {
  const cx = ox + 8;
  const cy = oy + 8;
  // Raute
  for (let dy = -5; dy <= 5; dy += 1) {
    const half = 5 - Math.abs(dy);
    ctx.fillStyle = dy < 0 ? PALETTE.e : PALETTE.f;
    ctx.fillRect(cx - half, cy + dy, half * 2 + 1, 1);
  }
  ctx.fillStyle = PALETTE.e;
  ctx.fillRect(cx - 2, cy - 1, 4, 2);
  // Wanderndes Glanzlicht
  const glintX = cx - 2 + (frame % 4);
  ctx.fillStyle = PALETTE[5];
  ctx.fillRect(glintX, cy - 3 + (frame % 2), 1, 1);
}

function spring(ctx, ox, oy) {
  ctx.fillStyle = PALETTE[1];
  ctx.fillRect(ox, oy + 10, TILE_SIZE, 6);
  ctx.fillStyle = PALETTE[0];
  ctx.fillRect(ox, oy + TILE_SIZE - 2, TILE_SIZE, 2);
  // Feder
  ctx.fillStyle = PALETTE.f;
  for (let i = 0; i < 3; i += 1) ctx.fillRect(ox + 3, oy + 6 + i * 2, 10, 1);
  ctx.fillStyle = PALETTE.a;
  for (let i = 0; i < 3; i += 1) ctx.fillRect(ox + 3, oy + 5 + i * 2, 10, 1);
  ctx.fillStyle = PALETTE[4];
  ctx.fillRect(ox + 2, oy + 3, 12, 2);
  ctx.fillStyle = PALETTE[3];
  ctx.fillRect(ox + 2, oy + 5, 12, 1);
}

/**
 * Brennt alle Kacheln einmalig in ein Blatt.
 * @returns {{canvas: HTMLCanvasElement, slots: number}}
 */
export function buildTileSheet() {
  const canvas = document.createElement('canvas');
  canvas.width = TILE_SIZE * SLOT_COUNT;
  canvas.height = TILE_SIZE;
  const ctx = canvas.getContext('2d');
  ctx.imageSmoothingEnabled = false;

  rock(ctx, SLOT.SOLID_A * TILE_SIZE, 0, SPECKLES_A);
  rock(ctx, SLOT.SOLID_B * TILE_SIZE, 0, SPECKLES_B);
  rock(ctx, SLOT.BURIED_A * TILE_SIZE, 0, SPECKLES_A, false);
  rock(ctx, SLOT.BURIED_B * TILE_SIZE, 0, SPECKLES_B, false);
  for (let stage = 0; stage < 3; stage += 1) {
    const ox = (SLOT.CRUMBLE_0 + stage) * TILE_SIZE;
    rock(ctx, ox, 0, SPECKLES_A);
    cracks(ctx, ox, 0, stage);
  }
  wall(ctx, SLOT.WALL * TILE_SIZE, 0);
  spike(ctx, SLOT.SPIKE * TILE_SIZE, 0);
  oneway(ctx, SLOT.ONEWAY * TILE_SIZE, 0);
  for (let frame = 0; frame < 4; frame += 1) {
    gem(ctx, (SLOT.GEM_0 + frame) * TILE_SIZE, 0, frame);
  }
  spring(ctx, SLOT.SPRING * TILE_SIZE, 0);

  return { canvas, slots: SLOT_COUNT };
}

/** Zeichnet eine Kachel aus dem Blatt an eine Bildschirmposition. */
export function drawTile(ctx, sheet, slot, x, y) {
  ctx.drawImage(
    sheet.canvas,
    slot * TILE_SIZE,
    0,
    TILE_SIZE,
    TILE_SIZE,
    Math.round(x),
    Math.round(y),
    TILE_SIZE,
    TILE_SIZE,
  );
}
