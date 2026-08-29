// Staub, Blätter und Funken. Rein kosmetisch – nichts hier beeinflusst die
// Physik.

import { PALETTE, COLORS } from './palette.js';

export function createParticles() {
  return { items: [], shake: 0 };
}

function add(ps, x, y, vx, vy, life, color, size) {
  // Obergrenze, damit eine lange Wandrutschpartie nicht Tausende Punkte anhäuft.
  if (ps.items.length > 220) return;
  ps.items.push({ x, y, vx, vy, life, maxLife: life, color, size });
}

/** Staubwolke beim Landen – aufgewirbeltes Moos und Sand. */
export function emitLandDust(ps, x, y, strength = 1) {
  const count = Math.round(5 + strength * 4);
  for (let i = 0; i < count; i += 1) {
    const dir = i % 2 === 0 ? -1 : 1;
    add(
      ps,
      x,
      y,
      dir * (0.4 + Math.random() * 1.2),
      -Math.random() * 0.6,
      14 + Math.random() * 8,
      i % 3 === 0 ? PALETTE.e : PALETTE.b,
      1,
    );
  }
}

/** Abrieb beim Wandrutschen. */
export function emitWallDust(ps, x, y, side) {
  if (Math.random() > 0.45) return;
  add(ps, x, y, -side * (0.2 + Math.random() * 0.5), -0.3 - Math.random() * 0.6, 12, PALETTE.c, 1);
}

/** Abstoss beim Wandsprung: Blätter stieben weg. */
export function emitWallJump(ps, x, y, dir) {
  for (let i = 0; i < 9; i += 1) {
    add(
      ps,
      x,
      y,
      -dir * (0.6 + Math.random()),
      (Math.random() - 0.5) * 1.5,
      16,
      i % 2 === 0 ? PALETTE[6] : PALETTE.e,
      1,
    );
  }
}

/** Einsammeln eines Smaragds. */
export function emitEmerald(ps, x, y) {
  for (let i = 0; i < 14; i += 1) {
    const angle = Math.random() * Math.PI * 2;
    const speed = 0.6 + Math.random() * 1.3;
    add(
      ps,
      x,
      y,
      Math.cos(angle) * speed,
      Math.sin(angle) * speed - 0.6,
      22,
      i % 3 === 0 ? PALETTE.u : PALETTE.t,
      1,
    );
  }
  ps.shake = Math.max(ps.shake, 1.5);
}

/** Die Ranke packt zu: eine Spur nach oben. */
export function emitVine(ps, x, y) {
  for (let i = 0; i < 16; i += 1) {
    add(
      ps,
      x + (Math.random() - 0.5) * 10,
      y + Math.random() * 14,
      (Math.random() - 0.5) * 0.6,
      1.6 + Math.random(),
      20,
      i % 2 === 0 ? PALETTE.f : PALETTE[7],
      1,
    );
  }
  ps.shake = Math.max(ps.shake, 2.5);
}

/** Brocken eines zerfallenen Quaders. */
export function emitCrumble(ps, x, y) {
  for (let i = 0; i < 7; i += 1) {
    add(
      ps,
      x + Math.random() * 16,
      y,
      (Math.random() - 0.5) * 1.2,
      Math.random() * 0.4,
      26,
      i % 3 === 0 ? PALETTE.d : PALETTE[9],
      2,
    );
  }
}

/** Todesausbruch. */
export function emitDeath(ps, x, y) {
  for (let i = 0; i < 28; i += 1) {
    const angle = Math.random() * Math.PI * 2;
    const speed = 0.8 + Math.random() * 2.2;
    add(
      ps,
      x,
      y,
      Math.cos(angle) * speed,
      Math.sin(angle) * speed - 1,
      34,
      i % 2 === 0 ? PALETTE.l : PALETTE.m,
      1,
    );
  }
  ps.shake = Math.max(ps.shake, 7);
}

/** Aufsteigende Funken über der Glut. */
export function emitEmber(ps, x, y) {
  add(
    ps,
    x,
    y,
    (Math.random() - 0.5) * 0.4,
    -0.5 - Math.random() * 0.8,
    40,
    Math.random() < 0.5 ? COLORS.emberSpark : COLORS.emberHot,
    1,
  );
}

export function updateParticles(ps) {
  const items = ps.items;
  for (let i = items.length - 1; i >= 0; i -= 1) {
    const p = items[i];
    p.x += p.vx;
    p.y += p.vy;
    p.vy += 0.06;
    p.vx *= 0.96;
    p.life -= 1;
    if (p.life <= 0) {
      // Reihenfolge ist egal – das letzte Element an die Lücke schieben ist
      // billiger als splice.
      items[i] = items[items.length - 1];
      items.pop();
    }
  }
  if (ps.shake > 0) ps.shake = Math.max(0, ps.shake - 0.45);
}

/**
 * @param {CanvasRenderingContext2D} ctx
 * @param {ReturnType<typeof createParticles>} ps
 * @param {number} cameraY
 */
export function drawParticles(ctx, ps, cameraY) {
  for (const p of ps.items) {
    ctx.globalAlpha = Math.min(1, p.life / p.maxLife);
    ctx.fillStyle = p.color;
    ctx.fillRect(Math.round(p.x), Math.round(p.y - cameraY), p.size, p.size);
  }
  ctx.globalAlpha = 1;
}

/** Versatz für das Bildschirmwackeln. Bei reduzierter Bewegung immer 0. */
export function shakeOffset(ps, reducedMotion) {
  if (reducedMotion || ps.shake <= 0) return { x: 0, y: 0 };
  return {
    x: Math.round((Math.random() - 0.5) * ps.shake),
    y: Math.round((Math.random() - 0.5) * ps.shake),
  };
}
