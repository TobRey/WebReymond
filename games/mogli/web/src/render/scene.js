// Zeichnet die Spielwelt in den 192×256-Puffer. Alles hier ist Darstellung –
// der Spielzustand wird nicht verändert.

import { GRID_W, PHYS, T, TILE, VIEW_H, VIEW_W, isSolid } from '../game/constants.js';
import { FRAME_SIZE, buildAtlases, drawSprite } from './sprites.js';
import { SLOT, buildTileSheet, drawTile } from './tiles.js';
import { buildBackground, drawBackground } from './background.js';
import { drawParticles, emitEmber, shakeOffset } from './particles.js';
import { COLORS, PALETTE } from './palette.js';

// Figuren- und Kachelblätter hängen nicht von der Bildhöhe ab und werden
// deshalb nur einmal gebrannt. Die Hintergrundebenen dagegen sind so hoch wie
// das Bild und müssen bei einer Grössenänderung neu entstehen.
let cachedAtlases = null;
let cachedTiles = null;

export function createScene() {
  if (cachedAtlases === null) cachedAtlases = buildAtlases();
  if (cachedTiles === null) cachedTiles = buildTileSheet();
  return {
    atlases: cachedAtlases,
    tiles: cachedTiles,
    background: buildBackground(),
    frame: 0,
  };
}

/**
 * Nach einer Änderung der Bildhöhe: nur die Hintergrundebenen sind so hoch wie
 * das Bild und müssen neu entstehen. Figuren und Kacheln bleiben.
 */
export function resizeScene(scene) {
  scene.background = buildBackground();
}

function slotForTile(tile, col, row, level, frame) {
  switch (tile) {
    case T.STONE: {
      // Liegt darüber ebenfalls Stein, ist diese Kachel verdeckt und bekommt
      // weder Moos noch Lichtkante – sonst wirkt ein massiver Block wie ein
      // Stapel Bretter.
      const covered = isSolid(level.tileAt(col, row - 1));
      const even = (col + row) % 2 === 0;
      if (covered) return even ? SLOT.BURIED_A : SLOT.BURIED_B;
      return even ? SLOT.STONE_A : SLOT.STONE_B;
    }
    case T.CRUMBLE: {
      const progress = level.crumbleProgress(col, row);
      return SLOT.CRUMBLE_0 + Math.min(2, Math.floor(progress * 3));
    }
    case T.WALL:
      return row % 2 === 0 ? SLOT.WALL_A : SLOT.WALL_B;
    case T.SPIKE:
      return SLOT.SPIKE;
    case T.LEAF:
      return SLOT.LEAF;
    case T.EMERALD:
      return SLOT.EMERALD_0 + ((frame >> 3) & 3);
    case T.VINE:
      return SLOT.VINE_0 + ((frame >> 4) & 1);
    default:
      return -1;
  }
}

/** Die aufsteigende Glut: der einzige warme Bereich im Bild. */
function drawHazard(ctx, hazardY, cameraY, frame) {
  const top = Math.round(hazardY - cameraY);
  if (top >= VIEW_H) return;

  const gradient = ctx.createLinearGradient(0, top, 0, VIEW_H);
  gradient.addColorStop(0, COLORS.emberHot);
  gradient.addColorStop(0.22, COLORS.emberCore);
  gradient.addColorStop(1, COLORS.emberDeep);
  ctx.fillStyle = gradient;
  ctx.fillRect(0, top, VIEW_W, VIEW_H - top);

  // Wellenkamm: zwei versetzte Sinuslinien, damit die Kante lebt.
  ctx.fillStyle = COLORS.emberHot;
  for (let x = 0; x < VIEW_W; x += 1) {
    const wave = Math.sin((x + frame * 1.6) * 0.16) + Math.sin((x - frame) * 0.09);
    ctx.fillRect(x, top + Math.round(wave) - 2, 1, 2);
  }
  ctx.fillStyle = COLORS.emberSpark;
  for (let x = 0; x < VIEW_W; x += 2) {
    const wave = Math.sin((x + frame * 1.6) * 0.16);
    if (wave > 0.75) ctx.fillRect(x, top + Math.round(wave) - 3, 1, 1);
  }
}

function drawPlayer(ctx, player, atlas, cameraY) {
  if (atlas === undefined) return;
  // Das 24×24-Bild ist grösser als die Trefferfläche: passend ausrichten.
  const x = player.body.x - PHYS.hitboxOffX;
  const y = player.body.y - PHYS.hitboxOffY;

  let tint = null;
  let alpha = 1;
  if (player.dead) {
    alpha = Math.max(0.15, 1 - player.deadTicks / 90);
  } else if (player.vine > 0) {
    // Kurzes helles Aufleuchten, solange die Ranke zieht.
    tint = player.vine % 6 < 3 ? PALETTE[7] : null;
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

  // --- Funken über der Glut -----------------------------------------------
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
