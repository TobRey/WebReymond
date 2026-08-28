// Zeichnet die Spielwelt in den 240×320-Puffer. Alles hier ist Darstellung –
// der Spielzustand wird nicht verändert.

import { GRID_W, T, TILE, VIEW_H, VIEW_W, isSolid } from '../game/constants.js';
import { FRAME_SIZE, buildAtlases, drawSprite } from './sprites.js';
import { SLOT, buildTileSheet, drawTile } from './tiles.js';
import { buildBackground, drawBackground } from './background.js';
import { drawParticles, emitEmber, shakeOffset } from './particles.js';
import { COLORS, PALETTE } from './palette.js';
import { PHYS } from '../game/constants.js';

export function createScene() {
  return {
    atlases: buildAtlases(),
    tiles: buildTileSheet(),
    background: buildBackground(),
    frame: 0,
  };
}

function slotForTile(tile, col, row, level, frame) {
  switch (tile) {
    case T.SOLID: {
      // Liegt darüber ebenfalls Fels, ist diese Kachel verdeckt und bekommt
      // keine Lichtkante – sonst wirkt ein massiver Block wie ein Stapel Bretter.
      const covered = isSolid(level.tileAt(col, row - 1));
      if (covered) return (col + row) % 2 === 0 ? SLOT.BURIED_A : SLOT.BURIED_B;
      return (col + row) % 2 === 0 ? SLOT.SOLID_A : SLOT.SOLID_B;
    }
    case T.CRUMBLE: {
      const progress = level.crumbleProgress(col, row);
      return SLOT.CRUMBLE_0 + Math.min(2, Math.floor(progress * 3));
    }
    case T.WALL:
      return SLOT.WALL;
    case T.SPIKE:
      return SLOT.SPIKE;
    case T.ONEWAY:
      return SLOT.ONEWAY;
    case T.GEM:
      return SLOT.GEM_0 + ((frame >> 3) & 3);
    case T.SPRING:
      return SLOT.SPRING;
    default:
      return -1;
  }
}

function drawHazard(ctx, hazardY, cameraY, frame) {
  const top = Math.round(hazardY - cameraY);
  if (top >= VIEW_H) return;

  const gradient = ctx.createLinearGradient(0, top, 0, VIEW_H);
  gradient.addColorStop(0, COLORS.hazardHot);
  gradient.addColorStop(0.25, COLORS.hazardCore);
  gradient.addColorStop(1, COLORS.hazardEdge);
  ctx.fillStyle = gradient;
  ctx.fillRect(0, top, VIEW_W, VIEW_H - top);

  // Wellenkamm: zwei versetzte Sinuslinien, damit die Kante lebt.
  ctx.fillStyle = COLORS.hazardHot;
  for (let x = 0; x < VIEW_W; x += 1) {
    const wave = Math.sin((x + frame * 1.5) * 0.15) + Math.sin((x - frame) * 0.09);
    ctx.fillRect(x, top + Math.round(wave) - 2, 1, 2);
  }
  ctx.fillStyle = PALETTE[5];
  for (let x = 0; x < VIEW_W; x += 2) {
    const wave = Math.sin((x + frame * 1.5) * 0.15);
    if (wave > 0.8) ctx.fillRect(x, top + Math.round(wave) - 3, 1, 1);
  }
}

function drawPlayer(ctx, player, atlas, cameraY) {
  if (atlas === undefined) return;
  // Das 16×16-Bild ist breiter als die Trefferfläche: mittig ausrichten.
  const x = player.body.x - PHYS.hitboxOffX;
  const y = player.body.y - PHYS.hitboxOffY;

  let tint = null;
  let alpha = 1;
  if (player.dead) {
    alpha = Math.max(0.15, 1 - player.deadTicks / 90);
  } else if (player.anim === 'spin' && player.animFrame === 0) {
    // Kurzes weisses Aufblitzen genau im ersten Bild des Saltos. Beim Landen
    // wäre dasselbe zu viel: die Figur würde bei jedem Aufkommen zur weissen
    // Silhouette – auffällig getestet und wieder entfernt.
    tint = PALETTE[5];
  }

  drawSprite(ctx, atlas, player.anim, player.animFrame, x, y - cameraY, {
    flip: player.facing < 0,
    tint,
    alpha,
  });
}

/**
 * @param {CanvasRenderingContext2D} ctx
 * @param {object} world
 * @param {ReturnType<typeof createScene>} scene
 * @param {object} particles
 * @param {{reducedMotion?: boolean}} [options]
 */
export function drawScene(ctx, world, scene, particles, options = {}) {
  scene.frame += 1;
  const frame = scene.frame;
  const reducedMotion = options.reducedMotion === true;

  const shake = shakeOffset(particles, reducedMotion);
  const cameraY = Math.round(world.camera.y) + shake.y;

  drawBackground(ctx, scene.background, cameraY, world.heightPx);

  ctx.save();
  ctx.translate(shake.x, 0);

  // --- Kacheln -------------------------------------------------------------
  const firstRow = Math.floor(cameraY / TILE) - 1;
  const lastRow = Math.floor((cameraY + VIEW_H) / TILE) + 1;
  const level = world.level;
  for (let row = firstRow; row <= lastRow; row += 1) {
    const screenY = row * TILE - cameraY;
    for (let col = 0; col < GRID_W; col += 1) {
      const tile = level.tileAt(col, row);
      if (tile === T.EMPTY) continue;
      const slot = slotForTile(tile, col, row, level, frame);
      if (slot < 0) continue;
      drawTile(ctx, scene.tiles, slot, col * TILE, screenY);
    }
  }

  // --- Glut über der Gefahr -----------------------------------------------
  if (!reducedMotion && frame % 3 === 0) {
    const hazardScreenY = world.hazard.y - cameraY;
    if (hazardScreenY < VIEW_H + 20) {
      emitEmber(particles, Math.random() * VIEW_W, world.hazard.y - 2);
    }
  }

  drawParticles(ctx, particles, cameraY);

  drawPlayer(ctx, world.player, scene.atlases[world.player.anim], cameraY);

  drawHazard(ctx, world.hazard.y, cameraY, frame);

  ctx.restore();
}

export { FRAME_SIZE };
