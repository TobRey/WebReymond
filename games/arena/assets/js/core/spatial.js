/**
 * Raster-Hash fuer Nachbarschaftsabfragen.
 * Ohne ihn muesste jedes Projektil gegen jeden Gegner geprueft werden -
 * bei 80 Gegnern und 60 Projektilen waeren das 4800 Tests pro Frame.
 */
export class SpatialHash {
  constructor(cellSize = 96) {
    this.cellSize = cellSize;
    this.cells = new Map();
  }

  clear() {
    this.cells.clear();
  }

  _key(cx, cy) {
    return cx * 73856093 ^ cy * 19349663;
  }

  insert(item) {
    const cx = (item.x / this.cellSize) | 0;
    const cy = (item.y / this.cellSize) | 0;
    const key = this._key(cx, cy);
    let bucket = this.cells.get(key);
    if (!bucket) {
      bucket = [];
      this.cells.set(key, bucket);
    }
    bucket.push(item);
  }

  rebuild(items) {
    this.clear();
    for (const item of items) this.insert(item);
  }

  /** Ruft fn fuer alle Objekte im Umkreis auf (grob, Zellenraster). */
  query(x, y, radius, fn) {
    const min = this.cellSize;
    const cx0 = ((x - radius) / min) | 0;
    const cx1 = ((x + radius) / min) | 0;
    const cy0 = ((y - radius) / min) | 0;
    const cy1 = ((y + radius) / min) | 0;
    for (let cy = cy0; cy <= cy1; cy++) {
      for (let cx = cx0; cx <= cx1; cx++) {
        const bucket = this.cells.get(this._key(cx, cy));
        if (!bucket) continue;
        for (let i = 0; i < bucket.length; i++) fn(bucket[i]);
      }
    }
  }
}
