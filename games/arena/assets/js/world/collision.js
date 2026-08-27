/**
 * Kollisionsmaske als Bitraster über der Karte.
 *
 * Pixelgenaue Pruefungen waeren auf dem Handy zu teuer, deshalb liegt
 * über dem Kartenbild ein grobes Raster (Standard 128x128). Eine Abfrage
 * kostet damit nur eine Handvoll Array-Zugriffe.
 */
export class CollisionMask {
  constructor(collision, worldWidth, worldHeight) {
    this.cols = collision?.cols || 128;
    this.rows = collision?.rows || 128;
    this.worldWidth = worldWidth;
    this.worldHeight = worldHeight;
    this.cellW = worldWidth / this.cols;
    this.cellH = worldHeight / this.rows;
    this.bits = decodeMask(collision?.data, this.cols * this.rows);
  }

  cellBlocked(cx, cy) {
    if (cx < 0 || cy < 0 || cx >= this.cols || cy >= this.rows) return true;
    const index = cy * this.cols + cx;
    return (this.bits[index >> 3] & (1 << (index & 7))) !== 0;
  }

  blockedAt(x, y) {
    return this.cellBlocked((x / this.cellW) | 0, (y / this.cellH) | 0);
  }

  /** Prueft eine Ellipse (Fuß-Hitbox) gegen das Raster. */
  blockedEllipse(x, y, rx, ry) {
    const x0 = ((x - rx) / this.cellW) | 0;
    const x1 = ((x + rx) / this.cellW) | 0;
    const y0 = ((y - ry) / this.cellH) | 0;
    const y1 = ((y + ry) / this.cellH) | 0;
    for (let cy = y0; cy <= y1; cy++) {
      for (let cx = x0; cx <= x1; cx++) {
        if (this.cellBlocked(cx, cy)) return true;
      }
    }
    return false;
  }

  /**
   * Bewegt eine Entität und löst Kollisionen achsenweise auf.
   * Dadurch gleitet man an Wänden entlang, statt hängen zu bleiben.
   */
  moveEntity(entity, dx, dy, rx, ry) {
    let moved = false;
    if (dx !== 0) {
      const nx = entity.x + dx;
      if (!this.blockedEllipse(nx, entity.y, rx, ry) && this.insideWorld(nx, entity.y, rx, ry)) {
        entity.x = nx;
        moved = true;
      }
    }
    if (dy !== 0) {
      const ny = entity.y + dy;
      if (!this.blockedEllipse(entity.x, ny, rx, ry) && this.insideWorld(entity.x, ny, rx, ry)) {
        entity.y = ny;
        moved = true;
      }
    }
    return moved;
  }

  insideWorld(x, y, rx, ry) {
    return x - rx >= 0 && y - ry >= 0 && x + rx <= this.worldWidth && y + ry <= this.worldHeight;
  }

  /** Freie Position in der Nähe finden - z. B. wenn etwas eingeklemmt ist. */
  nearestFree(x, y, rx, ry, maxRadius = 260) {
    if (!this.blockedEllipse(x, y, rx, ry) && this.insideWorld(x, y, rx, ry)) return { x, y };
    for (let r = this.cellW; r <= maxRadius; r += this.cellW) {
      for (let i = 0; i < 12; i++) {
        const a = (i / 12) * Math.PI * 2;
        const nx = x + Math.cos(a) * r;
        const ny = y + Math.sin(a) * r;
        if (this.insideWorld(nx, ny, rx, ry) && !this.blockedEllipse(nx, ny, rx, ry)) return { x: nx, y: ny };
      }
    }
    return { x, y };
  }
}

export function decodeMask(base64, cellCount) {
  const bytes = new Uint8Array(Math.ceil(cellCount / 8));
  if (!base64) return bytes;
  try {
    const bin = atob(base64);
    for (let i = 0; i < bytes.length && i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
  } catch {
    // Kaputte Maske: lieber alles begehbar als ein unspielbares Level.
  }
  return bytes;
}

export function encodeMask(bits) {
  let bin = '';
  for (let i = 0; i < bits.length; i++) bin += String.fromCharCode(bits[i]);
  return btoa(bin);
}
