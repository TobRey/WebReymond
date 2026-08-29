// Bewegung und Kollision. Reine Rechnerei, kein DOM – deshalb in Node testbar
// (siehe games/mogli/test/physics.test.mjs).
//
// Koordinaten: x nach rechts, y nach UNTEN (wie auf dem Bildschirm). Klettern
// heisst also, dass y kleiner wird. Kachelspalte = floor(x / TILE),
// Kachelzeile = floor(y / TILE); beides darf negativ sein.

import { TILE, T, isSolid } from './constants.js';

// Winziger Abzug an der oberen Kante, damit ein bündiges Anliegen (Unterkante
// exakt auf der Kachelgrenze) nicht schon als Überlappung zählt. Bewusst
// 1e-4 und nicht 1: Positionen sind Fliesskommazahlen, ein ganzer Pixel Abzug
// verschluckt eine reale Überlappung von z. B. 0.62 px – der Körper fällt dann
// jeden zweiten Tick durch den Boden und gilt abwechselnd als fliegend.
const EPS = 1e-4;

/** Kachelbereich, den [min, max) überdeckt. */
function tileSpan(min, max) {
  return [Math.floor(min / TILE), Math.floor((max - EPS) / TILE)];
}

/**
 * Ein Körper: { x, y, w, h, vx, vy }. x/y ist die linke obere Ecke der
 * Trefferfläche.
 *
 * @param {{x:number,y:number,w:number,h:number,vx:number,vy:number}} body
 * @param {{ tileAt: (col:number,row:number) => number }} level
 * @returns {{hitLeft:boolean,hitRight:boolean,hitTop:boolean,onGround:boolean,
 *   groundTile:number,groundCol:number,groundRow:number}}
 */
export function moveAndCollide(body, level) {
  const result = {
    hitLeft: false,
    hitRight: false,
    hitTop: false,
    onGround: false,
    groundTile: T.EMPTY,
    groundCol: 0,
    groundRow: 0,
  };

  // In Teilschritten von höchstens einer halben Kachel bewegen. Sonst kann ein
  // schneller Fall (7.6 px/Tick ist zwar < 16, eine Sprungfeder mit 9 px/Tick
  // aber auch) an einer Kante vorbeirutschen, und vor allem kann eine
  // bröckelnde Plattform zwischen zwei Schritten verschwinden.
  const steps = Math.max(1, Math.ceil(Math.max(Math.abs(body.vx), Math.abs(body.vy)) / (TILE / 2)));
  const stepX = body.vx / steps;
  const stepY = body.vy / steps;

  for (let s = 0; s < steps; s += 1) {
    moveX(body, level, stepX, result);
    moveY(body, level, stepY, result);
  }

  return result;
}

function moveX(body, level, dx, result) {
  if (dx === 0) return;
  body.x += dx;

  const [row0, row1] = tileSpan(body.y, body.y + body.h);

  if (dx > 0) {
    const col = Math.floor((body.x + body.w - EPS) / TILE);
    for (let row = row0; row <= row1; row += 1) {
      if (isSolid(level.tileAt(col, row))) {
        body.x = col * TILE - body.w;
        body.vx = 0;
        result.hitRight = true;
        return;
      }
    }
  } else {
    const col = Math.floor(body.x / TILE);
    for (let row = row0; row <= row1; row += 1) {
      if (isSolid(level.tileAt(col, row))) {
        body.x = (col + 1) * TILE;
        body.vx = 0;
        result.hitLeft = true;
        return;
      }
    }
  }
}

function moveY(body, level, dy, result) {
  if (dy === 0) return;
  const bottomBefore = body.y + body.h;
  body.y += dy;

  const [col0, col1] = tileSpan(body.x, body.x + body.w);

  if (dy > 0) {
    const row = Math.floor((body.y + body.h - EPS) / TILE);
    for (let col = col0; col <= col1; col += 1) {
      const tile = level.tileAt(col, row);
      const blocking =
        isSolid(tile) ||
        // Blattplattform: trägt nur von oben. Von unten springt man hindurch,
        // was den Zickzack an manchen Stellen abkürzt.
        (tile === T.LEAF && bottomBefore <= row * TILE);
      if (blocking) {
        body.y = row * TILE - body.h;
        body.vy = 0;
        result.onGround = true;
        result.groundTile = tile;
        result.groundCol = col;
        result.groundRow = row;
        return;
      }
    }
  } else {
    const row = Math.floor(body.y / TILE);
    for (let col = col0; col <= col1; col += 1) {
      if (isSolid(level.tileAt(col, row))) {
        body.y = (row + 1) * TILE;
        body.vy = 0;
        result.hitTop = true;
        return;
      }
    }
  }
}

/**
 * An welcher Seite liegt eine greifbare Wand an?
 * @returns {-1|0|1} -1 = links, 1 = rechts, 0 = keine.
 */
export function wallSide(body, level) {
  // Etwas oberhalb des Fusses und unterhalb des Kopfes prüfen, damit eine
  // einzelne Bodenkachel neben den Füssen nicht als Wand zählt.
  const top = body.y + 2;
  const bottom = body.y + body.h - 3;
  const rows = tileSpan(top, bottom);

  const leftCol = Math.floor((body.x - 1) / TILE);
  const rightCol = Math.floor((body.x + body.w) / TILE);

  for (let row = rows[0]; row <= rows[1]; row += 1) {
    if (isSolid(level.tileAt(leftCol, row))) return -1;
  }
  for (let row = rows[0]; row <= rows[1]; row += 1) {
    if (isSolid(level.tileAt(rightCol, row))) return 1;
  }
  return 0;
}

/**
 * Alle Kacheln, die die Trefferfläche gerade überdeckt – für Stacheln,
 * Edelsteine und Sprungfedern.
 * @returns {{col:number,row:number,tile:number}[]}
 */
export function overlappingTiles(body, level) {
  const [col0, col1] = tileSpan(body.x, body.x + body.w);
  const [row0, row1] = tileSpan(body.y, body.y + body.h);
  const found = [];
  for (let row = row0; row <= row1; row += 1) {
    for (let col = col0; col <= col1; col += 1) {
      const tile = level.tileAt(col, row);
      if (tile !== T.EMPTY) found.push({ col, row, tile });
    }
  }
  return found;
}

/** Bewegt einen Wert mit Beschleunigung/Bremsung auf ein Ziel zu. */
export function approach(current, target, accel, decel) {
  if (target === 0) {
    if (current > 0) return Math.max(0, current - decel);
    if (current < 0) return Math.min(0, current + decel);
    return 0;
  }
  // Gegen die aktuelle Richtung wird stärker gebremst als beschleunigt –
  // Richtungswechsel fühlt sich sonst schwammig an.
  const rate = current !== 0 && Math.sign(target) !== Math.sign(current) ? accel + decel : accel;
  if (current < target) return Math.min(target, current + rate);
  return Math.max(target, current - rate);
}
