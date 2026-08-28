// Vier Parallax-Ebenen, alle prozedural erzeugt und einmalig gebrannt. Pro
// Bild kostet der Hintergrund danach acht drawImage-Aufrufe.
//
// Die Ebenen wiederholen sich vertikal: gezeichnet wird jeweils an
// (versatz mod Höhe) und eine Bildhöhe darüber. Zwei Aufrufe je Ebene decken
// den Bildschirm lückenlos ab, egal wie hoch man geklettert ist.

import { VIEW_H, VIEW_W } from '../game/constants.js';
import { COLORS } from './palette.js';
import { createRng } from '../game/rng.js';

/** Höhe in Pixeln, ab der der Himmel vollständig Weltraum ist. */
const SKY_TOP = 9000;

function hexToRgb(hex) {
  return [
    parseInt(hex.slice(1, 3), 16),
    parseInt(hex.slice(3, 5), 16),
    parseInt(hex.slice(5, 7), 16),
  ];
}

function mix(a, b, t) {
  const ca = hexToRgb(a);
  const cb = hexToRgb(b);
  const r = Math.round(ca[0] + (cb[0] - ca[0]) * t);
  const g = Math.round(ca[1] + (cb[1] - ca[1]) * t);
  const bl = Math.round(ca[2] + (cb[2] - ca[2]) * t);
  return `rgb(${r},${g},${bl})`;
}

/** Farbverlauf des Himmels für eine bestimmte Höhe. */
function skyColors(heightPx) {
  const t = Math.max(0, Math.min(1, heightPx / SKY_TOP));
  if (t < 0.5) {
    const k = t / 0.5;
    return [mix(COLORS.skyLow, COLORS.skyMid, k), mix(COLORS.skyMid, COLORS.skyHigh, k)];
  }
  const k = (t - 0.5) / 0.5;
  return [mix(COLORS.skyMid, COLORS.skySpace, k), mix(COLORS.skyHigh, COLORS.skySpace, k)];
}

function layerCanvas() {
  const canvas = document.createElement('canvas');
  canvas.width = VIEW_W;
  canvas.height = VIEW_H;
  const ctx = canvas.getContext('2d');
  ctx.imageSmoothingEnabled = false;
  return { canvas, ctx };
}

function buildStars() {
  const { canvas, ctx } = layerCanvas();
  const rng = createRng(0x51a12);
  for (let i = 0; i < 130; i += 1) {
    const x = rng.int(0, VIEW_W - 1);
    const y = rng.int(0, VIEW_H - 1);
    const bright = rng.next();
    ctx.globalAlpha = 0.25 + bright * 0.6;
    ctx.fillStyle = COLORS.star;
    ctx.fillRect(x, y, 1, 1);
    if (bright > 0.93) {
      ctx.globalAlpha = 0.3;
      ctx.fillRect(x - 1, y, 1, 1);
      ctx.fillRect(x + 1, y, 1, 1);
      ctx.fillRect(x, y - 1, 1, 1);
      ctx.fillRect(x, y + 1, 1, 1);
    }
  }
  ctx.globalAlpha = 1;
  return canvas;
}

function buildFarRocks() {
  const { canvas, ctx } = layerCanvas();
  const rng = createRng(0xfa2c);
  ctx.fillStyle = COLORS.farRock;
  // Zackige Felssilhouetten an beiden Rändern.
  for (const side of [0, 1]) {
    let width = rng.int(18, 34);
    for (let y = 0; y < VIEW_H; y += 4) {
      width += rng.int(-3, 3);
      width = Math.max(10, Math.min(44, width));
      const x = side === 0 ? 0 : VIEW_W - width;
      ctx.fillRect(x, y, width, 4);
    }
  }
  return canvas;
}

function buildClouds() {
  const { canvas, ctx } = layerCanvas();
  const rng = createRng(0xc10d);
  ctx.fillStyle = COLORS.cloud;
  for (let i = 0; i < 9; i += 1) {
    const x = rng.int(-20, VIEW_W);
    const y = rng.int(0, VIEW_H - 1);
    const w = rng.int(28, 64);
    const h = rng.int(5, 9);
    ctx.globalAlpha = 0.1 + rng.next() * 0.12;
    ctx.fillRect(x, y, w, h);
    ctx.fillRect(x + 6, y - 3, w - 14, 3);
    ctx.fillRect(x + 10, y + h, w - 24, 3);
  }
  ctx.globalAlpha = 1;
  return canvas;
}

export function buildBackground() {
  return {
    stars: buildStars(),
    rocks: buildFarRocks(),
    clouds: buildClouds(),
  };
}

function tiled(ctx, canvas, offset, alpha) {
  // Positiver Rest, auch bei negativen Versätzen.
  const y = ((offset % VIEW_H) + VIEW_H) % VIEW_H;
  ctx.globalAlpha = alpha;
  ctx.drawImage(canvas, 0, Math.round(y));
  ctx.drawImage(canvas, 0, Math.round(y) - VIEW_H);
  ctx.globalAlpha = 1;
}

/**
 * @param {CanvasRenderingContext2D} ctx
 * @param {ReturnType<typeof buildBackground>} bg
 * @param {number} cameraY Weltkoordinate der oberen Bildkante (negativ beim Klettern).
 * @param {number} heightPx
 */
export function drawBackground(ctx, bg, cameraY, heightPx) {
  const [top, bottom] = skyColors(heightPx);
  const gradient = ctx.createLinearGradient(0, 0, 0, VIEW_H);
  gradient.addColorStop(0, top);
  gradient.addColorStop(1, bottom);
  ctx.fillStyle = gradient;
  ctx.fillRect(0, 0, VIEW_W, VIEW_H);

  // cameraY wird beim Klettern negativ, deshalb das Minus: die Ebenen sollen
  // nach unten wandern, wenn man steigt.
  tiled(ctx, bg.stars, -cameraY * 0.15, 0.9);
  tiled(ctx, bg.rocks, -cameraY * 0.35, 1);
  tiled(ctx, bg.clouds, -cameraY * 0.6, 1);
}
