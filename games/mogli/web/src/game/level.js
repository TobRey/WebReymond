// Prozedurale Erzeugung des Tempelschachts. Kein DOM – in Node testbar
// (games/mogli/test/level.test.mjs).
//
// Zeilen wachsen nach UNTEN (wie y). Der Startabsatz ist Zeile 0, klettern
// heisst also, dass die Zeilennummer negativ wird. Abschnitte (chunks) sind
// CHUNK_ROWS Zeilen hoch und werden nur nach oben erzeugt und nur unten
// verworfen – die Kamera fährt nie zurück.
//
// DIE FORM DES TURMS
// Der Schacht ist eine Zickzack-Treppe. Absätze hängen abwechselnd an der
// linken und an der rechten Wand, immer genau drei Kacheln höher als der
// vorige. Daraus ergibt sich der Rhythmus des Spiels ganz von selbst:
//
//   landen -> in die Wand laufen -> umdrehen -> zum offenen Ende laufen ->
//   tippen -> hinüberspringen -> landen ...
//
// Zwei Festlegungen tragen das:
//   1. Jeder Absatz berührt seine Wand. Sonst liefe Mogli am fernen Ende ins
//      Leere, denn er läuft von allein und kann nicht bremsen.
//   2. Der Höhenunterschied ist immer drei Kacheln (48 px). Zwei wären
//      unmöglich – die Figur ist 16 px hoch, die Unterkante des oberen
//      Absatzes läge auf Kopfhöhe. Vier lägen über dem gemessenen
//      Sprungscheitel von 58 px.
//
// Die Schwierigkeit steckt deshalb nicht in der Höhe, sondern in der Breite:
// je weiter oben, desto schmaler die Absätze und desto grösser die Lücke
// dazwischen – von null bis vier Kacheln. Dazu kommen Dornen, morsche Quader
// und Blattplattformen.

import { CHUNK_ROWS, GEN, GRID_W, T, TILE } from './constants.js';
import { createRng } from './rng.js';

const CHUNK_TILES = GRID_W * CHUNK_ROWS;

/** Breite des Startabsatzes in Kacheln. */
const START_WIDTH = 6;

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

/** Ein Wertepaar [leicht, schwer] auf die Schwierigkeit abbilden. */
function ramp(pair, d) {
  return lerp(pair[0], pair[1], d);
}

export function createLevel(seed) {
  return buildLevel(seed);
}

function buildLevel(seed) {
  const rng = createRng(seed);
  /** @type {Map<number, Uint8Array>} */
  const chunks = new Map();
  /** Morsche Quader, die schon berührt wurden: "col,row" -> Restticks. */
  const crumbling = new Map();
  /** Für Tests und den Automaten: alle erzeugten Absätze, jüngster zuletzt. */
  const ledges = [];

  /** Seite des zuletzt gesetzten Absatzes: 1 = rechte Wand, -1 = linke Wand. */
  let side = -1;
  /** Breite des zuletzt gesetzten Absatzes – bestimmt die nächste. */
  let prevWidth = START_WIDTH;
  let cursorRow = 0;
  /** Oberste bereits erzeugte Zeile (kleinste Zahl). */
  let generatedTopRow = 0;

  function chunkIndexOf(row) {
    return Math.floor(row / CHUNK_ROWS);
  }

  function ensureChunk(index) {
    let chunk = chunks.get(index);
    if (chunk === undefined) {
      chunk = new Uint8Array(CHUNK_TILES);
      // Die beiden Randspalten sind durchgehende Tempelwände. Sie begrenzen
      // das Spielfeld, geben ihm die Form eines Schachts und sind die immer
      // verfügbare Wandsprungfläche – dadurch kann man nie feststecken.
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
    // Ein Absatz an der linken Wand, darunter ein kurzer Sockel. Mogli schaut
    // nach rechts und läuft sofort los – der erste Sprung ist derselbe wie
    // jeder spätere, das Spiel muss nichts erklären.
    for (let col = 1; col <= START_WIDTH; col += 1) {
      setTile(col, 0, T.STONE);
      for (let row = 1; row <= 3; row += 1) setTile(col, row, T.STONE);
    }
    ledges.push({ col: 1, width: START_WIDTH, row: 0, kind: T.STONE, side: -1 });
    side = -1;
    prevWidth = START_WIDTH;
    cursorRow = 0;
    generatedTopRow = 0;
  }

  // --- Ein Generatorschritt ------------------------------------------------

  function placeLedge() {
    const row = cursorRow - GEN.minDy;
    const d = difficultyAtRow(row);
    side = -side;

    // Zuerst die Lücke wählen, dann die Breite daraus ableiten. Andersherum
    // (erst zwei Breiten würfeln, dann schauen was übrig bleibt) entstehen
    // Lücken von null bis sechs Kacheln – die einen sind eine Falle, weil
    // Mogli sich am Zielabsatz den Kopf stösst, die anderen schlicht zu weit.
    const wide = d >= GEN.wideGapFrom && rng.chance(0.5);
    const gap = wide ? GEN.maxGapX : GEN.minGapX;
    const usable = GRID_W - 2; // die beiden Randwände zählen nicht mit
    const width = clamp(usable - gap - prevWidth, GEN.minLedgeW, GEN.maxLedgeW);
    const col = side > 0 ? GRID_W - 1 - width : 1;
    prevWidth = width;

    let kind = T.STONE;
    if (rng.chance(ramp(GEN.crumbleChance, d))) kind = T.CRUMBLE;
    else if (rng.chance(ramp(GEN.leafChance, d))) kind = T.LEAF;

    for (let i = 0; i < width; i += 1) setTile(col + i, row, kind);

    decorate(col, width, row, d, kind);

    cursorRow = row;
    ledges.push({ col, width, row, kind, side });
    return row;
  }

  function decorate(col, width, row, d, kind) {
    const used = new Set();
    // Die der Wand zugewandte Hälfte ist die Landezone und der Wendepunkt –
    // dort darf nichts stehen, sonst ist der Absatz eine Falle.
    const wallEnd = side > 0 ? col + width - 1 : col;
    const openEnd = side > 0 ? col : col + width - 1;

    // Dornen: nie auf der Wandseite, nie über die ganze Breite.
    if (width >= 4 && rng.chance(ramp(GEN.spikeChance, d))) {
      const spikeCol = side > 0 ? col + 1 : col + width - 2;
      setTile(spikeCol, row - 1, T.SPIKE);
      used.add(spikeCol);
    }

    // Smaragd: ein Feld über einer freien Kachel, gern in der Mitte.
    if (rng.chance(ramp(GEN.emeraldChance, d))) {
      const candidates = [];
      for (let i = 0; i < width; i += 1) {
        const c = col + i;
        if (!used.has(c) && c !== wallEnd) candidates.push(c);
      }
      if (candidates.length > 0) {
        const gem = rng.pick(candidates);
        setTile(gem, row - 1, T.EMERALD);
        used.add(gem);
      }
    }

    // Ranke: hängt über dem offenen Ende, also genau dort, wo Mogli abspringt.
    // Wer sie erwischt, wird ein gutes Stück nach oben gerissen und überspringt
    // zwei bis drei Absätze. Das ist die Belohnung fürs Risiko.
    if (kind !== T.CRUMBLE && rng.chance(ramp(GEN.vineChance, d))) {
      const vineCol = clamp(openEnd + (side > 0 ? -1 : 1), 1, GRID_W - 2);
      if (!used.has(vineCol) && tileAt(vineCol, row - 2) === T.EMPTY) {
        setTile(vineCol, row - 2, T.VINE);
      }
    }
  }

  /** Erzeugt so weit nach oben, dass `topRow` fertig ist. */
  function ensureUpTo(topRow) {
    if (chunks.size === 0) generateStart();
    let guard = 0;
    while (generatedTopRow > topRow) {
      generatedTopRow = placeLedge();
      // Reissleine: ein Generatorfehler soll den Browser nicht einfrieren.
      guard += 1;
      if (guard > 4000) break;
    }
    // Der Abschnitt über dem letzten Absatz muss existieren, sonst liefert
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
    while (ledges.length > 0 && ledges[0].row > row) ledges.shift();
  }

  // --- Morsche Quader ------------------------------------------------------

  /** Startet den Zerfall einer Kachel. */
  function touchCrumble(col, row) {
    const key = `${col},${row}`;
    if (crumbling.has(key)) return;
    crumbling.set(key, GEN.crumbleTicks);
  }

  /**
   * Muss VOR der Bewegung des Spielers laufen. Wenn ein Quader mitten in einem
   * Bewegungsteilschritt verschwindet, fällt man scheinbar grundlos durch den
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
    ledges,
    chunks,
    get topRow() {
      return generatedTopRow;
    },
    get chunkCount() {
      return chunks.size;
    },
  };
}
