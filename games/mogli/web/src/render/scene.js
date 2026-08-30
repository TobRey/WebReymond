// Zeichnet die Spielwelt in den Puffer. Alles hier ist Darstellung – der
// Spielzustand wird nicht verändert.
//
// Seit 3.0 scrollt die Kamera waagerecht durch eine Karte aus dem Editor.
// Feste Blöcke, Plattformen und Bröckelsteine kommen aus dem Kachelblatt
// (tiles.js), alle übrigen Elemente aus elementArt.js oder – wenn eingesetzt
// – aus dem Grafikpaket.

import { PHYS, T, TILE, VIEW_H, VIEW_W, isSolid } from '../game/constants.js';
import { ELEMENTS } from '../game/elements.js';
import { FRAME_SIZE, buildAtlases, drawSprite } from './sprites.js';
import { SLOT, buildTileSheet, drawTile } from './tiles.js';
import { buildBackground, drawBackground } from './background.js';
import { drawParticles, shakeOffset } from './particles.js';
import { PALETTE } from './palette.js';
import { tileOverride } from './assets.js';
import { elementImage, resetElementArt } from './elementArt.js';

// Figuren- und Kachelblätter hängen nicht von der Bildhöhe ab und werden
// deshalb nur einmal gebrannt. Die Hintergrundebenen dagegen sind bildhoch
// und müssen bei einer Grössenänderung neu entstehen.
let cachedAtlases = null;
let cachedTiles = null;

/** Naturgrössen der Elemente in Zellen, für die Platzhalter-Grafik. */
const ELEMENT_SIZES = Object.fromEntries(
  Object.entries(ELEMENTS).map(([type, def]) => [type, def.cells]),
);

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

/** Nach einer Änderung der Bildhöhe: nur die Hintergrundebenen neu. */
export function resizeScene(scene) {
  scene.background = buildBackground();
}

/** Nach dem Einsetzen eines Grafikpakets: alles neu brennen. */
export function reloadScene(scene) {
  cachedAtlases = null;
  cachedTiles = null;
  resetElementArt();
  const fresh = createScene();
  scene.atlases = fresh.atlases;
  scene.tiles = fresh.tiles;
  scene.background = fresh.background;
}

function slotForTile(tile, col, row, level) {
  switch (tile) {
    case T.SOLID: {
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
    case T.PLATFORM:
      return SLOT.LEAF;
    default:
      return -1;
  }
}

/** Ein Element zeichnen: eigenes Bild oder Platzhalter, aufs Rechteck gezogen. */
function drawEntity(ctx, e, camX, camY, frame) {
  if (e.taken || (e.alive === false && (e.type === 'walker' || e.type === 'flyer'))) return;

  const x = Math.round(e.x - camX);
  const y = Math.round(e.y - camY);

  // Zustands-Varianten der Platzhalter.
  let variant = null;
  if (e.type === 'checkpoint' && e.active) variant = 'checkpointActive';

  const image = elementImage(ELEMENT_SIZES, e.type, variant);
  if (image !== null) {
    // Portale drehen sich ein wenig – billig, aber lebendig.
    if (e.type === 'portal') {
      ctx.save();
      ctx.translate(x + e.w / 2, y + e.h / 2);
      ctx.rotate(Math.sin(frame / 20) * 0.15);
      ctx.drawImage(image, -e.w / 2, -e.h / 2, e.w, e.h);
      ctx.restore();
    } else {
      ctx.drawImage(image, x, y, e.w, e.h);
    }
    return;
  }
  // Letzte Rettung: ein Kasten mit Umriss, damit nie "nichts" dasteht.
  ctx.fillStyle = PALETTE[6] ?? '#3fe3a8';
  ctx.fillRect(x, y, e.w, e.h);
}

/** Smaragde und Stacheln kommen aus dem Kachelblatt und dürfen animieren. */
function drawSheetEntity(ctx, scene, e, camX, camY, frame) {
  const x = Math.round(e.x - camX);
  const y = Math.round(e.y - camY);
  if (e.type === 'emerald') {
    if (e.taken) return true;
    const custom = tileOverride('EMERALD_0');
    if (custom !== null) {
      ctx.drawImage(custom, x, y, e.w, e.h);
      return true;
    }
    drawTile(ctx, scene.tiles, SLOT.EMERALD_0 + ((frame >> 3) & 3), x, y);
    return true;
  }
  if (e.type === 'spikes') {
    const custom = tileOverride('SPIKE');
    for (let dx = 0; dx < e.w; dx += TILE) {
      if (custom !== null) ctx.drawImage(custom, x + dx, y, TILE, e.h);
      else drawTile(ctx, scene.tiles, SLOT.SPIKE, x + dx, y);
    }
    return true;
  }
  return false;
}

function drawPlayer(ctx, player, atlas, camX, camY) {
  if (atlas === undefined) return;
  const x = player.body.x - PHYS.hitboxOffX;
  const y = player.body.y - PHYS.hitboxOffY;

  const alpha = player.dead ? Math.max(0.15, 1 - player.deadTicks / 90) : 1;
  drawSprite(
    ctx,
    atlas,
    player.anim,
    player.animFrame,
    Math.round(x - camX),
    Math.round(y - camY),
    {
      flip: player.facing < 0,
      tint: null,
      alpha,
    },
  );
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
  const camX = Math.round(world.camera.x) + shake.x;
  const camY = Math.round(world.camera.y) + shake.y;

  drawBackground(ctx, scene.background, camX);

  // --- Kachelgitter, nur der sichtbare Ausschnitt --------------------------
  const level = world.level;
  const firstCol = Math.max(0, Math.floor(camX / TILE) - 1);
  const lastCol = Math.min(level.cols - 1, Math.floor((camX + VIEW_W) / TILE) + 1);
  const firstRow = Math.max(0, Math.floor(camY / TILE) - 1);
  const lastRow = Math.min(level.rows - 1, Math.floor((camY + VIEW_H) / TILE) + 1);

  for (let row = firstRow; row <= lastRow; row += 1) {
    for (let col = firstCol; col <= lastCol; col += 1) {
      const tile = level.tileAt(col, row);
      if (tile === T.EMPTY) continue;
      const slot = slotForTile(tile, col, row, level);
      if (slot < 0) continue;
      drawTile(ctx, scene.tiles, slot, col * TILE - camX, row * TILE - camY);
    }
  }

  // --- Elemente ------------------------------------------------------------
  for (const e of world.entities) {
    if (e.x + e.w < camX - 16 || e.x > camX + VIEW_W + 16) continue;
    if (!drawSheetEntity(ctx, scene, e, camX, camY, frame)) {
      drawEntity(ctx, e, camX, camY, frame);
    }
  }

  drawParticles(ctx, particles, camY, camX);

  drawPlayer(ctx, world.player, scene.atlases[world.player.anim], camX, camY);
}

export { FRAME_SIZE };

/** Für die Trefferflächen-Anzeige des Editors: nichts weiter exportiert. */
export const _internal = { slotForTile };
