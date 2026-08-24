/**
 * Wegfindung für alle Gegner auf einmal.
 *
 * Statt jedem Gegner einen eigenen Weg zu suchen, wird einmal je Takt vom
 * Spieler aus über das Kollisionsraster geflutet (Breitensuche). Das
 * Ergebnis ist ein Feld aus Entfernungen: Jede Zelle weiß, wie viele
 * Schritte sie vom Spieler entfernt ist. Ein Gegner schaut nur noch in
 * seiner Zelle nach, welcher Nachbar näher dran ist, und läuft dorthin.
 *
 * Dadurch laufen Gegner um Wände herum statt dagegen - und zwar auf dem
 * kürzesten Weg. Der Aufwand hängt nur an der Kartengröße (128x128 Zellen),
 * nicht an der Zahl der Gegner.
 */
const UNREACHABLE = 0xffff;

export class FlowField {
  constructor(mask) {
    this.mask = mask;
    this.cols = mask.cols;
    this.rows = mask.rows;
    const count = this.cols * this.rows;

    this.dist = new Uint16Array(count);
    this.dist.fill(UNREACHABLE);
    // Warteschlange als fester Ring - kein Array-Wachstum im Spielbetrieb.
    this.queue = new Int32Array(count);
    // Begehbarkeit einmal vorberechnen: spart die Bitmaske je Zelle.
    this.open = new Uint8Array(count);
    for (let cy = 0; cy < this.rows; cy++) {
      for (let cx = 0; cx < this.cols; cx++) {
        this.open[cy * this.cols + cx] = mask.cellBlocked(cx, cy) ? 0 : 1;
      }
    }

    this.goalX = -1;
    this.goalY = -1;
    this.timer = 0;
    this.ready = false;
    this.rebuilds = 0;
  }

  /** Zellenindex einer Weltposition, oder -1 ausserhalb der Karte. */
  indexAt(x, y) {
    const cx = (x / this.mask.cellW) | 0;
    const cy = (y / this.mask.cellH) | 0;
    if (cx < 0 || cy < 0 || cx >= this.cols || cy >= this.rows) return -1;
    return cy * this.cols + cx;
  }

  /**
   * Baut das Feld neu, wenn es sich lohnt.
   *
   * Neu gerechnet wird nur, wenn der Spieler die Zelle gewechselt hat -
   * und höchstens alle 0,2 Sekunden. Steht er still, kostet die Wegfindung
   * gar nichts.
   */
  update(dt, targetX, targetY) {
    this.timer += dt;
    const cx = (targetX / this.mask.cellW) | 0;
    const cy = (targetY / this.mask.cellH) | 0;
    if (cx === this.goalX && cy === this.goalY && this.ready) return;
    if (this.timer < 0.2 && this.ready) return;
    this.timer = 0;
    this.build(cx, cy);
  }

  build(cx, cy) {
    if (cx < 0 || cy < 0 || cx >= this.cols || cy >= this.rows) return;
    this.goalX = cx;
    this.goalY = cy;
    this.rebuilds++;

    const { dist, queue, open, cols, rows } = this;
    dist.fill(UNREACHABLE);

    let start = cy * cols + cx;
    // Steht der Spieler in einer blockierten Zelle (z. B. halb in der Wand),
    // wird der nächste freie Nachbar zum Ziel.
    if (!open[start]) {
      start = this.nearestOpen(cx, cy);
      if (start < 0) {
        this.ready = false;
        return;
      }
    }

    let head = 0;
    let tail = 0;
    dist[start] = 0;
    queue[tail++] = start;

    while (head < tail) {
      const index = queue[head++];
      const d = dist[index] + 1;
      const x = index % cols;
      const y = (index / cols) | 0;

      // Vier Nachbarn reichen: Die Richtung wird später aus acht Nachbarn
      // gewählt, dadurch entstehen trotzdem Diagonalen.
      if (x > 0) {
        const n = index - 1;
        if (open[n] && dist[n] === UNREACHABLE) { dist[n] = d; queue[tail++] = n; }
      }
      if (x < cols - 1) {
        const n = index + 1;
        if (open[n] && dist[n] === UNREACHABLE) { dist[n] = d; queue[tail++] = n; }
      }
      if (y > 0) {
        const n = index - cols;
        if (open[n] && dist[n] === UNREACHABLE) { dist[n] = d; queue[tail++] = n; }
      }
      if (y < rows - 1) {
        const n = index + cols;
        if (open[n] && dist[n] === UNREACHABLE) { dist[n] = d; queue[tail++] = n; }
      }
    }
    this.ready = true;
  }

  /** Nächste begehbare Zelle in wachsenden Ringen. */
  nearestOpen(cx, cy) {
    for (let r = 1; r <= 8; r++) {
      for (let dy = -r; dy <= r; dy++) {
        for (let dx = -r; dx <= r; dx++) {
          if (Math.abs(dx) !== r && Math.abs(dy) !== r) continue;
          const x = cx + dx;
          const y = cy + dy;
          if (x < 0 || y < 0 || x >= this.cols || y >= this.rows) continue;
          const index = y * this.cols + x;
          if (this.open[index]) return index;
        }
      }
    }
    return -1;
  }

  /** Entfernung zum Spieler in Zellen, oder -1 wenn unerreichbar. */
  stepsAt(x, y) {
    const index = this.indexAt(x, y);
    if (index < 0) return -1;
    const d = this.dist[index];
    return d === UNREACHABLE ? -1 : d;
  }

  /**
   * Richtung zum Spieler an dieser Position.
   *
   * Gesucht wird der Nachbar mit der kleinsten Entfernung. Diagonalen
   * gelten nur, wenn auch beide angrenzenden geraden Nachbarn frei sind -
   * sonst würden Gegner durch Mauerecken schneiden.
   *
   * @param out  Objekt mit x/y, in das geschrieben wird
   * @returns true, wenn eine Richtung gefunden wurde
   */
  directionAt(x, y, out) {
    const index = this.indexAt(x, y);
    if (index < 0 || !this.ready) return false;
    const { dist, open, cols, rows } = this;

    let cx = index % cols;
    let cy = (index / cols) | 0;
    let here = dist[index];

    // Gegner steckt in einer Wand oder in einem abgeschnittenen Bereich:
    // den besten erreichbaren Nachbarn suchen.
    if (here === UNREACHABLE) {
      const fallback = this.bestNeighbour(cx, cy);
      if (fallback < 0) return false;
      here = dist[fallback] + 1;
    }

    let bestDist = here;
    let bestX = 0;
    let bestY = 0;

    for (let dy = -1; dy <= 1; dy++) {
      for (let dx = -1; dx <= 1; dx++) {
        if (!dx && !dy) continue;
        const nx = cx + dx;
        const ny = cy + dy;
        if (nx < 0 || ny < 0 || nx >= cols || ny >= rows) continue;
        const n = ny * cols + nx;
        if (!open[n]) continue;
        if (dx && dy) {
          // Keine Abkürzung durch Ecken.
          if (!open[cy * cols + nx] || !open[ny * cols + cx]) continue;
        }
        const d = dist[n];
        if (d < bestDist) {
          bestDist = d;
          bestX = dx;
          bestY = dy;
        }
      }
    }

    if (!bestX && !bestY) return false;
    const len = Math.hypot(bestX, bestY) || 1;
    out.x = bestX / len;
    out.y = bestY / len;
    return true;
  }

  bestNeighbour(cx, cy) {
    let best = -1;
    let bestDist = UNREACHABLE;
    for (let dy = -1; dy <= 1; dy++) {
      for (let dx = -1; dx <= 1; dx++) {
        const nx = cx + dx;
        const ny = cy + dy;
        if (nx < 0 || ny < 0 || nx >= this.cols || ny >= this.rows) continue;
        const n = ny * this.cols + nx;
        if (this.dist[n] < bestDist) {
          bestDist = this.dist[n];
          best = n;
        }
      }
    }
    return bestDist === UNREACHABLE ? -1 : best;
  }
}
