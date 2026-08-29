// Vier Parallax-Ebenen, alle prozedural erzeugt und einmalig gebrannt. Pro
// Bild kostet der Hintergrund danach acht drawImage-Aufrufe.
//
// Die Ebenen wiederholen sich vertikal: gezeichnet wird jeweils an
// (versatz mod Höhe) und eine Bildhöhe darüber. Zwei Aufrufe je Ebene decken
// den Bildschirm lückenlos ab, egal wie hoch man geklettert ist.
//
// Motiv: ein Tempelschacht, der im Dschungel versinkt. Ganz hinten Dunst und
// verfallene Stufenpyramiden, davor drei Laubschichten, dazwischen
// Wasserfäden und der Schein einzelner Fackeln. Nach oben hin wird alles
// heller und grüner – man sieht dem Bild an, wie weit man gekommen ist.

import { VIEW_H, VIEW_W } from '../game/constants.js';
import { COLORS, PALETTE } from './palette.js';
import { createRng } from '../game/rng.js';

/** Höhe in Pixeln, ab der der Dunst sein helles Maximum erreicht hat. */
const HAZE_TOP = 9000;

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
  return `rgb(${Math.round(ca[0] + (cb[0] - ca[0]) * t)},${Math.round(
    ca[1] + (cb[1] - ca[1]) * t,
  )},${Math.round(ca[2] + (cb[2] - ca[2]) * t)})`;
}

/** Farbverlauf des Dunsts für eine bestimmte Höhe. */
function hazeColors(heightPx) {
  const t = Math.max(0, Math.min(1, heightPx / HAZE_TOP));
  return [mix(COLORS.hazeMid, COLORS.hazeTop, t), mix(COLORS.hazeLow, COLORS.hazeHigh, t)];
}

function layerCanvas() {
  const canvas = document.createElement('canvas');
  canvas.width = VIEW_W;
  canvas.height = VIEW_H;
  const ctx = canvas.getContext('2d');
  ctx.imageSmoothingEnabled = false;
  return { canvas, ctx };
}

/**
 * Wie viele Objekte eine Ebene bekommt.
 *
 * Feste Anzahlen gehen hier nicht: die Bildhöhe richtet sich nach dem Gerät,
 * und dieselben 22 Laubkronen, die ein kurzes Bild füllen, verlieren sich in
 * einem 640 px hohen. Die Zahlen unten sind für die kleinste Fläche gedacht
 * und wachsen mit ihr mit.
 */
function count(perSmallestScreen) {
  const area = (VIEW_W * VIEW_H) / (256 * 336);
  return Math.max(1, Math.round(perSmallestScreen * area));
}

/** Ganz hinten: verfallene Stufenpyramiden und ein paar Lichtpunkte. */
function buildFar() {
  const { canvas, ctx } = layerCanvas();
  const rng = createRng(0x7e11);

  for (let i = 0; i < count(7); i += 1) {
    const baseY = rng.int(20, VIEW_H - 40);
    const w = rng.int(40, 96);
    const x = rng.int(-10, VIEW_W - w + 10);
    const steps = rng.int(4, 8);
    for (let s = 0; s < steps; s += 1) {
      const sw = w - s * Math.round(w / (steps * 1.6));
      if (sw <= 4) break;
      const sx = x + (w - sw) / 2;
      ctx.fillStyle = COLORS.farCanopy;
      ctx.fillRect(sx, baseY - s * 5, sw, 5);
      // Eine Kante Licht auf jeder Stufe: erst dadurch liest man Stufen und
      // nicht einen Klotz.
      ctx.fillStyle = PALETTE[3];
      ctx.fillRect(sx, baseY - s * 5, sw, 1);
    }
    // Ein Türsturz in der Mitte – die Silhouette bekommt einen Zweck.
    ctx.fillStyle = COLORS.hazeLow;
    ctx.fillRect(x + w / 2 - 4, baseY - 8, 8, 8);
  }

  // Ferne Lichter im Dunst: Fackeln, die man nie erreicht.
  for (let i = 0; i < count(9); i += 1) {
    const x = rng.int(4, VIEW_W - 4);
    const y = rng.int(4, VIEW_H - 4);
    ctx.globalAlpha = 0.35;
    ctx.fillStyle = COLORS.torchGlow;
    ctx.fillRect(x - 1, y - 1, 3, 3);
    ctx.globalAlpha = 0.8;
    ctx.fillRect(x, y, 1, 1);
  }
  ctx.globalAlpha = 1;
  return canvas;
}

/**
 * Ein weicher Klecks aus waagerechten Zeilen. Laub besteht nicht aus
 * Rechtecken – mit reinen fillRect-Blöcken sieht ein Dschungel aus wie ein
 * Lagerregal. Ellipsen kosten hier nichts, weil sie nur einmal beim Start
 * gezeichnet werden.
 */
function blob(ctx, cx, cy, rx, ry, color) {
  ctx.fillStyle = color;
  for (let dy = -ry; dy <= ry; dy += 1) {
    const t = dy / ry;
    const half = Math.round(rx * Math.sqrt(Math.max(0, 1 - t * t)));
    if (half > 0) ctx.fillRect(cx - half, cy + dy, half * 2, 1);
  }
}

/** Eine Laubkrone: mehrere überlappende Kleckse mit Lichtkante oben. */
function foliage(ctx, cx, cy, size, rng, base, light) {
  const lobes = rng.int(3, 5);
  for (let i = 0; i < lobes; i += 1) {
    const ox = cx + rng.int(-size, size);
    const oy = cy + rng.int(-size / 2, size / 2);
    blob(ctx, ox, oy, rng.int(size / 2, size), rng.int(size / 3, size / 1.6), base);
  }
  ctx.fillStyle = light;
  for (let i = 0; i < lobes; i += 1) {
    const ox = cx + rng.int(-size, size);
    const oy = cy + rng.int(-size / 2, 0);
    ctx.fillRect(ox - rng.int(2, 5), oy - Math.round(size / 2), rng.int(4, 9), 1);
  }
}

/** Ein Wasserfall mit Gischt oben und Becken unten. */
function waterfall(ctx, x, top, len, width, rng) {
  ctx.globalAlpha = 0.45;
  ctx.fillStyle = COLORS.waterDeep;
  ctx.fillRect(x - 1, top, width + 2, len);
  ctx.globalAlpha = 0.6;
  ctx.fillStyle = COLORS.waterMid;
  ctx.fillRect(x, top, width, len);

  // Einzelne hellere Strähnen, die den Fall in Bewegung setzen.
  ctx.globalAlpha = 0.75;
  ctx.fillStyle = COLORS.waterFoam;
  for (let i = 0; i < width; i += 2) {
    const start = top + rng.int(0, 10);
    const end = top + len - rng.int(0, 14);
    if (end > start) ctx.fillRect(x + i, start, 1, end - start);
  }

  // Becken: eine flache, breite Gischt.
  ctx.globalAlpha = 0.4;
  blob(ctx, x + width / 2, top + len + 1, width + 5, 3, COLORS.waterFoam);
  ctx.globalAlpha = 0.2;
  blob(ctx, x + width / 2, top + len + 3, width + 9, 4, COLORS.waterMid);
  ctx.globalAlpha = 1;
}

/** Mittelgrund: dichte Laubmassen und Wasserfälle. */
function buildMid() {
  const { canvas, ctx } = layerCanvas();
  const rng = createRng(0x3a44);

  for (let i = 0; i < count(4); i += 1) {
    waterfall(
      ctx,
      rng.int(14, VIEW_W - 26),
      rng.int(-10, VIEW_H - 70),
      rng.int(46, 110),
      rng.int(5, 11),
      rng,
    );
  }

  for (let i = 0; i < count(30); i += 1) {
    foliage(
      ctx,
      rng.int(-6, VIEW_W + 6),
      rng.int(0, VIEW_H),
      rng.int(9, 20),
      rng,
      COLORS.midCanopy,
      PALETTE[3],
    );
  }

  // Palmwedel: die Silhouette, an der man einen Dschungel auf einen Blick
  // erkennt. Ein Stamm, davon fächerförmig gebogene Wedel.
  for (let i = 0; i < count(5); i += 1) {
    palm(ctx, rng.int(6, VIEW_W - 6), rng.int(24, VIEW_H - 6), rng.int(14, 24), rng);
  }
  return canvas;
}

/** Ein Palmwedel-Büschel auf kurzem Stamm. */
function palm(ctx, x, baseY, size, rng) {
  ctx.fillStyle = COLORS.farCanopy;
  ctx.fillRect(x - 1, baseY - size, 3, size);

  const fronds = rng.int(5, 7);
  for (let f = 0; f < fronds; f += 1) {
    // Wedel gleichmässig um die Krone verteilt, leicht gestört.
    const angle = (f / fronds) * Math.PI * 2 + rng.range(-0.2, 0.2);
    const len = size * rng.range(0.7, 1.2);
    const dirX = Math.cos(angle);
    const dirY = Math.sin(angle) * 0.55;
    for (let s = 0; s < len; s += 1) {
      const t = s / len;
      // Der Wedel hängt zum Ende hin durch.
      const px = Math.round(x + dirX * s);
      const py = Math.round(baseY - size + dirY * s + t * t * size * 0.45);
      ctx.fillStyle = t < 0.5 ? COLORS.midCanopy : COLORS.farCanopy;
      ctx.fillRect(px, py, 2, 2);
      if (s % 3 === 0 && t > 0.2) {
        ctx.fillRect(px, py - 2, 1, 2);
        ctx.fillRect(px, py + 2, 1, 2);
      }
    }
  }
}

/** Vordergrund: Farnwedel an den Rändern und Ranken, die von oben hängen. */
function buildNear() {
  const { canvas, ctx } = layerCanvas();
  const rng = createRng(0xfe2d);

  // Hängende Ranken – das Merkmal, das einen Ort sofort nach Dschungel
  // aussehen lässt.
  for (let i = 0; i < count(14); i += 1) {
    const x = rng.int(2, VIEW_W - 3);
    const top = rng.int(0, VIEW_H - 30);
    const len = rng.int(18, 70);
    ctx.fillStyle = COLORS.nearCanopy;
    for (let y = 0; y < len; y += 1) {
      const wobble = Math.round(Math.sin(y * 0.18 + i) * 1.4);
      ctx.fillRect(x + wobble, top + y, 2, 1);
      if (y % 7 === 3) {
        ctx.fillRect(x + wobble - 2, top + y, 2, 2);
        ctx.fillRect(x + wobble + 2, top + y + 2, 2, 2);
      }
    }
  }

  for (let i = 0; i < count(18); i += 1) {
    const fromLeft = i % 2 === 0;
    const y = rng.int(0, VIEW_H);
    const len = rng.int(18, 52);
    const x = fromLeft ? 0 : VIEW_W - len;

    ctx.fillStyle = COLORS.nearCanopy;
    ctx.fillRect(x, y, len, 3);
    // Fiederblättchen wechselseitig am Stiel
    for (let s = 2; s < len; s += 3) {
      const px = fromLeft ? x + s : x + len - s;
      const drop = Math.round((s / len) * 5);
      ctx.fillRect(px, y - 3 - drop, 2, 4);
      ctx.fillRect(px, y + 3, 2, 4 + drop);
    }
    ctx.fillStyle = PALETTE[4];
    ctx.fillRect(x, y, len, 1);
  }
  return canvas;
}

export function buildBackground() {
  return { far: buildFar(), mid: buildMid(), near: buildNear() };
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
  const [top, bottom] = hazeColors(heightPx);
  const gradient = ctx.createLinearGradient(0, 0, 0, VIEW_H);
  gradient.addColorStop(0, top);
  gradient.addColorStop(1, bottom);
  ctx.fillStyle = gradient;
  ctx.fillRect(0, 0, VIEW_W, VIEW_H);

  // cameraY wird beim Klettern negativ, deshalb das Minus: die Ebenen sollen
  // nach unten wandern, wenn man steigt.
  tiled(ctx, bg.far, -cameraY * 0.12, 1);
  tiled(ctx, bg.mid, -cameraY * 0.3, 1);
  tiled(ctx, bg.near, -cameraY * 0.55, 0.9);
}
