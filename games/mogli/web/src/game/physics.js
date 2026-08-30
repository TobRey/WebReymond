// Bewegung und Kollision. Reine Rechnerei, kein DOM – deshalb in Node testbar
// (siehe games/mogli/test/physics.test.mjs).
//
// Koordinaten: x nach rechts, y nach UNTEN (wie auf dem Bildschirm). Klettern
// heisst also, dass y kleiner wird. Kachelspalte = floor(x / TILE),
// Kachelzeile = floor(y / TILE); beides darf negativ sein.

import { TILE, T, isSolid } from './constants.js';
import { tileBox } from './tilebox.js';

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
 * Der feste Bereich einer Zelle in Weltkoordinaten – oder null, wenn dort
 * nichts Festes ist.
 *
 * Voreingestellt ist die ganze Zelle; nur ein eingesetztes Kachelpaket kann
 * das ändern (siehe tilebox.js). Deshalb rechnet diese Datei ohne Paket exakt
 * so wie vorher.
 */
function solidRect(level, col, row) {
  const tile = level.tileAt(col, row);
  if (!isSolid(tile)) return null;
  const box = tileBox(tile);
  return { x: col * TILE + box.x, y: row * TILE + box.y, w: box.w, h: box.h, tile };
}

/** Nur von oben tragend: dieselbe Form, andere Bedingung. */
function platformRect(level, col, row) {
  const tile = level.tileAt(col, row);
  if (tile !== T.PLATFORM) return null;
  const box = tileBox(tile);
  return { x: col * TILE + box.x, y: row * TILE + box.y, w: box.w, h: box.h, tile };
}

/** Überlappen sich Körper und Rechteck echt (nicht nur bündig)? */
function overlaps(body, rect) {
  return (
    body.x + body.w > rect.x + EPS &&
    body.x < rect.x + rect.w - EPS &&
    body.y + body.h > rect.y + EPS &&
    body.y < rect.y + rect.h - EPS
  );
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

// Beide Achsen prüfen ALLE Zellen, die der Körper nach dem Schritt überdeckt,
// und nicht nur die Zelle unter der vorderen Kante.
//
// Das ist der Preis für einstellbare Kachelkästen: ist der feste Bereich einer
// Zelle nach innen gerückt, kann der Körper mitten in der Zelle stehen, ohne
// sie zu berühren – und umgekehrt kann er eine Zelle berühren, in der seine
// Vorderkante noch gar nicht liegt. Gesucht wird in Bewegungsrichtung, damit
// bei mehreren Treffern der nächstliegende gewinnt.

function moveX(body, level, dx, result) {
  if (dx === 0) return;
  body.x += dx;

  const [row0, row1] = tileSpan(body.y, body.y + body.h);
  const [col0, col1] = tileSpan(body.x, body.x + body.w);
  const from = dx > 0 ? col0 : col1;
  const to = dx > 0 ? col1 : col0;
  const step = dx > 0 ? 1 : -1;

  for (let col = from; dx > 0 ? col <= to : col >= to; col += step) {
    for (let row = row0; row <= row1; row += 1) {
      const rect = solidRect(level, col, row);
      if (rect === null || !overlaps(body, rect)) continue;
      if (dx > 0) {
        body.x = rect.x - body.w;
        result.hitRight = true;
      } else {
        body.x = rect.x + rect.w;
        result.hitLeft = true;
      }
      body.vx = 0;
      return;
    }
  }
}

function moveY(body, level, dy, result) {
  if (dy === 0) return;
  const bottomBefore = body.y + body.h;
  body.y += dy;

  const [col0, col1] = tileSpan(body.x, body.x + body.w);
  const [row0, row1] = tileSpan(body.y, body.y + body.h);
  const from = dy > 0 ? row0 : row1;
  const to = dy > 0 ? row1 : row0;
  const step = dy > 0 ? 1 : -1;

  for (let row = from; dy > 0 ? row <= to : row >= to; row += step) {
    for (let col = col0; col <= col1; col += 1) {
      let rect = solidRect(level, col, row);
      if (dy > 0 && rect === null) {
        // Plattform: trägt nur von oben, von unten springt man hindurch.
        const leaf = platformRect(level, col, row);
        if (leaf !== null && bottomBefore <= leaf.y) rect = leaf;
      }
      if (rect === null || !overlaps(body, rect)) continue;

      if (dy > 0) {
        body.y = rect.y - body.h;
        result.onGround = true;
        result.groundTile = rect.tile;
        result.groundCol = col;
        result.groundRow = row;
      } else {
        body.y = rect.y + rect.h;
        result.hitTop = true;
      }
      body.vy = 0;
      return;
    }
  }
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
