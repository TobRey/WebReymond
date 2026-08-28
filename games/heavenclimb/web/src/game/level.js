// Prozedurale Erzeugung des Turms. Kein DOM – in Node testbar
// (games/heavenclimb/test/level.test.mjs).
//
// Zeilen wachsen nach UNTEN (wie y). Der Startboden ist Zeile 0, klettern heisst
// also, dass die Zeilennummer negativ wird. Abschnitte (chunks) sind
// CHUNK_ROWS Zeilen hoch und werden nur nach oben erzeugt und nur unten
// verworfen – die Kamera fährt nie zurück.
//
// Die zentrale Zusage des Generators: **jede** Plattform ist von der
// vorherigen aus erreichbar. Die Grenzen dafür stehen in GEN und sind aus den
// Sprungwerten in PHYS hergeleitet:
//   Einfachsprung  54.5 px = 3.4 Kacheln hoch
//   mit Doppelsprung 93.7 px = 5.8 Kacheln hoch
//   waagerechte Reichweite im Sprung > 90 px = 5.6 Kacheln
// Erzeugt werden höchstens 3 (bzw. 4 ab GEN.stretchFrom) Kacheln Höhe und
// 3 Kacheln Lücke. Der Puffer ist Absicht: wer nicht bildgenau spielt, soll
// trotzdem hochkommen.

import { CHUNK_ROWS, GEN, GRID_W, T, TILE } from './constants.js';
import { createRng } from './rng.js';

const CHUNK_TILES = GRID_W * CHUNK_ROWS;

function clamp(value, min, max) {
  return value < min ? min : value > max ? max : value;
}

function lerp(a, b, t) {
  return a + (b - a) * t;
}

/** Schwierigkeit 0…1 aus der Höhe in Pixeln. */
export function difficultyFromHeight(heightPx) {
  return clamp(heightPx / GEN.fullDifficultyPx, 0, 1);
}

/**
 * @param {number} seed
 * @returns {ReturnType<typeof buildLevel>}
 */
export function createLevel(seed) {
  return buildLevel(seed);
}

function buildLevel(seed) {
  const rng = createRng(seed);
  /** @type {Map<number, Uint8Array>} */
  const chunks = new Map();
  /** Bröckelnde Kacheln, die schon berührt wurden: "col,row" -> Restticks. */
  const crumbling = new Map();
  /** Für Tests und die Anzeige: alle erzeugten Plattformen, jüngste zuletzt. */
  const platforms = [];

  /** Zuletzt erzeugte Landefläche – Ausgangspunkt für die nächste. */
  let cursor = { col: 1, width: GRID_W - 2, row: 0 };
  /** Oberste bereits erzeugte Zeile (kleinste Zahl). */
  let generatedTopRow = 0;
  let lastWasCrumble = false;
  let lastWasShaft = false;

  function chunkIndexOf(row) {
    return Math.floor(row / CHUNK_ROWS);
  }

  function ensureChunk(index) {
    let chunk = chunks.get(index);
    if (chunk === undefined) {
      chunk = new Uint8Array(CHUNK_TILES);
      // Die beiden Randspalten sind durchgehende Fels­wände. Sie begrenzen das
      // Spielfeld und sind gleichzeitig die immer verfügbare Wandsprungfläche –
      // dadurch kann man nie endgültig feststecken.
      for (let localRow = 0; localRow < CHUNK_ROWS; localRow += 1) {
        chunk[localRow * GRID_W] = T.WALL;
        chunk[localRow * GRID_W + GRID_W - 1] = T.WALL;
      }
      chunks.set(index, chunk);
    }
    return chunk;
  }

  /** Kachel an (col, row). Ausserhalb der Welt: massiv. */
  function tileAt(col, row) {
    if (col < 0 || col >= GRID_W) return T.WALL;
    const index = chunkIndexOf(row);
    const chunk = chunks.get(index);
    if (chunk === undefined) return T.EMPTY;
    const localRow = row - index * CHUNK_ROWS;
    return chunk[localRow * GRID_W + col];
  }

  function setTile(col, row, tile) {
    if (col < 0 || col >= GRID_W) return;
    const index = chunkIndexOf(row);
    const chunk = ensureChunk(index);
    const localRow = row - index * CHUNK_ROWS;
    chunk[localRow * GRID_W + col] = tile;
  }

  function heightOfRow(row) {
    return -row * TILE;
  }

  function difficultyAtRow(row) {
    return difficultyFromHeight(heightOfRow(row));
  }

  // --- Startbereich --------------------------------------------------------

  function generateStart() {
    ensureChunk(0);
    for (let row = 0; row < CHUNK_ROWS; row += 1) {
      for (let col = 1; col < GRID_W - 1; col += 1) {
        setTile(col, row, T.SOLID);
      }
    }
    cursor = { col: 1, width: GRID_W - 2, row: 0 };
    platforms.push({ ...cursor, kind: T.SOLID });
    generatedTopRow = 0;
  }

  // --- Ein Generatorschritt ------------------------------------------------

  /** Erlaubter Spaltenbereich für eine Plattform der Breite `width`. */
  function reachableColumnRange(width) {
    const min = Math.max(1, cursor.col - width - GEN.maxDx);
    const max = Math.min(GRID_W - 1 - width, cursor.col + cursor.width + GEN.maxDx);
    // Bei sehr breiten Plattformen an der Kante kann das Fenster leer werden.
    // Dann lieber enger stellen als die Zusage brechen.
    return max < min ? { min, max: min } : { min, max };
  }

  /**
   * Wie viele Kacheln der vorherigen Plattform bleiben unbedeckt?
   *
   * Das ist keine Kosmetik: senkrecht nach oben kann man in keinem
   * Jump'n'Run auf eine Plattform springen – der Kopf stösst an ihre
   * Unterseite. Man muss sie von der Seite anfliegen. Deckt die neue
   * Plattform die alte vollständig ab, steht der Spieler unter einer Decke
   * und kommt nicht mehr weg. Deshalb müssen mindestens FREE_TILES Kacheln
   * der Standfläche frei bleiben.
   */
  function uncoveredTiles(col, width) {
    const aStart = cursor.col;
    const aEnd = cursor.col + cursor.width - 1;
    const bStart = col;
    const bEnd = col + width - 1;
    const overlap = Math.max(0, Math.min(aEnd, bEnd) - Math.max(aStart, bStart) + 1);
    return cursor.width - overlap;
  }

  const FREE_TILES = 2;

  /** Wählt eine Spalte, die erreichbar ist UND die Standfläche nicht zubaut. */
  function chooseColumn(width) {
    const range = reachableColumnRange(width);
    const good = [];
    for (let col = range.min; col <= range.max; col += 1) {
      if (uncoveredTiles(col, width) >= FREE_TILES) good.push(col);
    }
    if (good.length > 0) return rng.pick(good);
    // Kein Platz gefunden: die Spalte nehmen, die am wenigsten zubaut.
    let bestCol = range.min;
    let bestFree = -1;
    for (let col = range.min; col <= range.max; col += 1) {
      const free = uncoveredTiles(col, width);
      if (free > bestFree) {
        bestFree = free;
        bestCol = col;
      }
    }
    return bestCol;
  }

  function placePlatform(d) {
    const stretch = d >= GEN.stretchFrom ? 1 : 0;
    // Die allererste Plattform bekommt vollen Abstand: mit nur zwei Kacheln
    // stünde der Spieler beim Start unter einer Decke, was für den ersten
    // Eindruck denkbar schlecht ist.
    const minGap = platforms.length <= 1 ? GEN.guaranteedDy : 2;
    const gap = rng.int(minGap, GEN.guaranteedDy + stretch);
    const row = cursor.row - gap;

    const targetWidth = Math.round(lerp(5, 2, d)) + rng.int(-1, 1);
    const width = clamp(targetWidth, GEN.minPlatW, GEN.maxPlatW);
    const col = chooseColumn(width);

    // Bröckelnde Plattformen nie zweimal hintereinander: sonst entsteht eine
    // Stelle, an der man zwangsläufig stirbt.
    const crumbleChance = lerp(0, 0.35, d);
    let kind = T.SOLID;
    if (!lastWasCrumble && rng.chance(crumbleChance)) {
      kind = T.CRUMBLE;
    } else if (d > 0.2 && rng.chance(0.15)) {
      kind = T.ONEWAY;
    }
    lastWasCrumble = kind === T.CRUMBLE;
    lastWasShaft = false;

    for (let i = 0; i < width; i += 1) setTile(col + i, row, kind);

    decorate(col, width, row, d, kind);

    cursor = { col, width, row };
    platforms.push({ col, width, row, kind });
    return row;
  }

  function decorate(col, width, row, d, kind) {
    const used = new Set();

    // Stacheln: nie über die ganze Breite, nie auf der Anflugseite. Die
    // Anflugseite ist die, aus der der Spieler kommt.
    if (width >= 3 && rng.chance(lerp(0, 0.22, d))) {
      const fromLeft = cursor.col <= col;
      const count = rng.int(1, Math.min(2, width - 2));
      const start = fromLeft ? col + width - count : col;
      for (let i = 0; i < count; i += 1) {
        setTile(start + i, row - 1, T.SPIKE);
        used.add(start + i);
      }
    }

    // Sprungfeder: ersetzt eine Plattformkachel. Nur auf tragendem Grund –
    // eine Feder auf einer bröckelnden Plattform wäre reine Schikane.
    if (kind === T.SOLID && rng.chance(0.06)) {
      const candidates = [];
      for (let i = 0; i < width; i += 1) {
        if (!used.has(col + i)) candidates.push(col + i);
      }
      if (candidates.length > 0) {
        const springCol = rng.pick(candidates);
        setTile(springCol, row, T.SPRING);
        used.add(springCol);
      }
    }

    // Edelstein: ein Feld über einer freien Kachel.
    if (rng.chance(0.3)) {
      const candidates = [];
      for (let i = 0; i < width; i += 1) {
        if (!used.has(col + i)) candidates.push(col + i);
      }
      if (candidates.length > 0) setTile(rng.pick(candidates), row - 1, T.GEM);
    }
  }

  /**
   * Wandschacht: zwei senkrechte Wände ohne Plattformen dazwischen. Der einzige
   * Weg nach oben ist eine Kette von Wandsprüngen.
   */
  function placeShaft() {
    // Höhe bewusst begrenzt: ein Wandsprung gewinnt 48 px = 3 Kacheln, also
    // sind 7 Kacheln höchstens drei Sprünge. Alles darüber wird zur Geduldsprobe.
    const height = rng.int(4, 7);
    const inner = rng.int(3, 5); // freie Kacheln zwischen den Wänden

    // Einstiegsplattform: von der letzten Plattform aus regulär erreichbar.
    const entryCol = chooseColumn(inner);
    const entryRow = cursor.row - rng.int(2, GEN.guaranteedDy);
    for (let i = 0; i < inner; i += 1) setTile(entryCol + i, entryRow, T.SOLID);
    platforms.push({ col: entryCol, width: inner, row: entryRow, kind: T.SOLID });

    // Zwei Wände darüber, dazwischen nichts: der einzige Weg nach oben ist
    // eine Kette von Wandsprüngen.
    const leftWall = entryCol - 1;
    const rightWall = entryCol + inner;
    for (let i = 1; i <= height; i += 1) {
      setTile(leftWall, entryRow - i, T.WALL);
      setTile(rightWall, entryRow - i, T.WALL);
    }

    if (rng.chance(0.6)) {
      setTile(clamp(entryCol + (inner >> 1), 1, GRID_W - 2), entryRow - height + 1, T.GEM);
    }

    // Der Schacht bekommt bewusst KEINE Ausstiegsplattform. Die Wandkrone ist
    // die Landefläche: von dort gelten wieder die normalen Reichweitenregeln,
    // und der nächste Generatorschritt setzt die nächste Plattform darüber.
    // Eine Plattform quer über den Schacht wäre eine Decke – man käme von
    // innen gar nicht darauf.
    const topRow = entryRow - height;
    lastWasCrumble = false;
    lastWasShaft = true;
    cursor = { col: leftWall, width: inner + 2, row: topRow };
    platforms.push({ col: leftWall, width: inner + 2, row: topRow, kind: T.WALL, viaWall: true });
    return topRow;
  }

  function generateStep() {
    const d = difficultyAtRow(cursor.row);
    if (!lastWasShaft && d >= GEN.shaftFrom && rng.chance(lerp(0, 0.3, d))) {
      return placeShaft();
    }
    return placePlatform(d);
  }

  /** Erzeugt so weit nach oben, dass `topRow` fertig ist. */
  function ensureUpTo(topRow) {
    if (chunks.size === 0) generateStart();
    let guard = 0;
    while (generatedTopRow > topRow) {
      generatedTopRow = generateStep();
      // Reissleine: ein Generatorfehler soll den Browser nicht einfrieren.
      guard += 1;
      if (guard > 4000) break;
    }
    // Der Abschnitt über der letzten Plattform muss existieren, sonst liefert
    // tileAt() dort EMPTY statt der Randwände.
    ensureChunk(chunkIndexOf(generatedTopRow - CHUNK_ROWS));
  }

  /** Verwirft alles unterhalb von `row`. Ohne das wächst der Speicher endlos. */
  function cullBelow(row) {
    const keepFrom = chunkIndexOf(row);
    for (const index of chunks.keys()) {
      if (index > keepFrom) chunks.delete(index);
    }
    for (const key of crumbling.keys()) {
      if (Number(key.split(',')[1]) > row) crumbling.delete(key);
    }
    while (platforms.length > 0 && platforms[0].row > row) platforms.shift();
  }

  // --- Bröckelnde Plattformen ---------------------------------------------

  /** Startet den Zerfall einer Kachel. */
  function touchCrumble(col, row) {
    const key = `${col},${row}`;
    if (crumbling.has(key)) return;
    crumbling.set(key, GEN.crumbleTicks);
  }

  /**
   * Muss VOR der Bewegung des Spielers laufen. Wenn eine Plattform mitten in
   * einem Teilschritt verschwindet, fällt man scheinbar grundlos durch den
   * Boden – der Klassiker unter den Kollisionsfehlern.
   * @returns {{col:number,row:number}[]} in diesem Tick zerfallene Kacheln
   */
  function updateCrumbling() {
    const gone = [];
    for (const [key, ticks] of crumbling) {
      if (ticks <= 1) {
        const [col, row] = key.split(',').map(Number);
        setTile(col, row, T.EMPTY);
        crumbling.delete(key);
        gone.push({ col, row });
      } else {
        crumbling.set(key, ticks - 1);
      }
    }
    return gone;
  }

  /** 0…1: wie weit der Zerfall fortgeschritten ist (für die Darstellung). */
  function crumbleProgress(col, row) {
    const ticks = crumbling.get(`${col},${row}`);
    return ticks === undefined ? 0 : 1 - ticks / GEN.crumbleTicks;
  }

  return {
    tileAt,
    setTile,
    ensureUpTo,
    cullBelow,
    touchCrumble,
    updateCrumbling,
    crumbleProgress,
    difficultyAtRow,
    heightOfRow,
    platforms,
    chunks,
    get topRow() {
      return generatedTopRow;
    },
    get chunkCount() {
      return chunks.size;
    },
  };
}
