// Die Regeln für Karten – als reine Funktionen, ohne DOM und ohne Netz.
// Dieselbe Prüfung läuft an drei Stellen:
//
//   1. im Editor, bevor gesendet wird (damit man den Fehler sofort sieht)
//   2. in admin.php, bevor geschrieben wird (dort ist sie massgeblich)
//   3. in games/mogli/test/map.test.mjs
//
// Dieselbe Aufteilung wie bei scoreRules.js und assetRules.js. Wer hier eine
// Zahl ändert, ändert sie auch in admin.php – der Abgleichtest hält beide
// zusammen (Muster aus test/assets.test.mjs).

import { ELEMENTS, cleanProps } from '../game/elements.js';

export const MAP_LIMITS = {
  maxMaps: 30,
  maxElements: 600,
  minCols: 20,
  maxCols: 600,
  minRows: 12,
  maxRows: 40,
  /**
   * Erlaubte Rastergrössen des Editors, in Pixeln. Nur Vielfache der
   * Kachelgrösse (16): feste Blöcke werden ins 16er-Kollisionsgitter
   * gerastert, und ein 24er-Raster ergäbe dort halbe Zellen.
   */
  cells: [16, 32, 48],
  nameMax: 24,
  noteMax: 200,
  /** Eine Karte als JSON, in Bytes. */
  maxMapBytes: 256 * 1024,
  /** Alle Karten zusammen, wie sie an admin.php gehen. */
  maxTotalBytes: 2 * 1024 * 1024,
};

const ID = /^[a-z0-9-]{1,24}$/;

/** Ein sauberer, eindeutiger Kartenname als Kennung. */
export function slugify(name) {
  const slug = String(name)
    .toLowerCase()
    .replace(/[äöüß]/g, (c) => ({ ä: 'ae', ö: 'oe', ü: 'ue', ß: 'ss' })[c])
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 24);
  return slug.length > 0 ? slug : 'karte';
}

/**
 * Prüft und bereinigt EINE Karte.
 * @returns {{ok: true, map: object} | {ok: false, error: string, detail?: string}}
 */
export function validateMap(input) {
  if (input === null || typeof input !== 'object') return { ok: false, error: 'invalid_map' };

  const id = input.id;
  if (typeof id !== 'string' || !ID.test(id)) return { ok: false, error: 'invalid_id' };

  const name = typeof input.name === 'string' ? input.name.trim().slice(0, MAP_LIMITS.nameMax) : '';
  if (name.length === 0) return { ok: false, error: 'invalid_name' };

  const cols = Number(input.cols);
  const rows = Number(input.rows);
  if (
    !Number.isInteger(cols) ||
    cols < MAP_LIMITS.minCols ||
    cols > MAP_LIMITS.maxCols ||
    !Number.isInteger(rows) ||
    rows < MAP_LIMITS.minRows ||
    rows > MAP_LIMITS.maxRows
  ) {
    return { ok: false, error: 'invalid_size' };
  }

  const cell = Number(input.cell);
  if (!MAP_LIMITS.cells.includes(cell)) return { ok: false, error: 'invalid_cell' };

  // Der Start: in Zellen, muss auf der Karte liegen.
  const spawn = input.spawn;
  if (
    spawn === null ||
    typeof spawn !== 'object' ||
    !Number.isInteger(spawn.x) ||
    !Number.isInteger(spawn.y) ||
    spawn.x < 0 ||
    spawn.x >= cols ||
    spawn.y < 0 ||
    spawn.y >= rows
  ) {
    return { ok: false, error: 'invalid_spawn' };
  }

  if (!Array.isArray(input.elements)) return { ok: false, error: 'invalid_elements' };
  if (input.elements.length > MAP_LIMITS.maxElements) {
    return { ok: false, error: 'too_many_elements' };
  }

  const elements = [];
  const seenIds = new Set();
  let flags = 0;
  for (const raw of input.elements) {
    if (raw === null || typeof raw !== 'object') return { ok: false, error: 'invalid_element' };
    const type = raw.type;
    const def = ELEMENTS[type];
    if (def === undefined) return { ok: false, error: 'unknown_type', detail: String(type) };

    if (typeof raw.id !== 'string' || !ID.test(raw.id) || seenIds.has(raw.id)) {
      return { ok: false, error: 'invalid_element_id' };
    }
    seenIds.add(raw.id);

    // Lage und Grösse in ZELLEN. Aufziehbare Elemente dürfen grösser sein,
    // alle anderen behalten ihre Katalog-Grösse.
    const x = Number(raw.x);
    const y = Number(raw.y);
    let w = Number(raw.w ?? def.cells[0]);
    let h = Number(raw.h ?? def.cells[1]);
    if (!def.resizable) {
      w = def.cells[0];
      h = def.cells[1];
    }
    if (
      ![x, y, w, h].every(Number.isInteger) ||
      w < 1 ||
      h < 1 ||
      x < 0 ||
      y < 0 ||
      x + w > cols ||
      y + h > rows
    ) {
      return { ok: false, error: 'element_out_of_bounds', detail: type };
    }

    if (type === 'flag') flags += 1;

    const note = typeof raw.note === 'string' ? raw.note.trim().slice(0, MAP_LIMITS.noteMax) : '';

    elements.push({ id: raw.id, type, x, y, w, h, props: cleanProps(type, raw.props), note });
  }

  // Ohne Ziel ist eine Karte nicht beendbar – lieber hier scheitern als im
  // Spiel. (unique wird hier gleich mitgeprüft.)
  if (flags !== 1) return { ok: false, error: 'needs_one_flag' };

  const map = {
    version: 1,
    id,
    name,
    cols,
    rows,
    cell,
    spawn: { x: spawn.x, y: spawn.y },
    elements,
  };
  if (JSON.stringify(map).length > MAP_LIMITS.maxMapBytes) {
    return { ok: false, error: 'map_too_large' };
  }
  return { ok: true, map };
}

/**
 * Prüft die ganze Sammlung, wie sie gespeichert wird: eine geordnete Liste.
 * @returns {{ok: true, maps: object[]} | {ok: false, error: string, detail?: string}}
 */
export function validateMaps(input) {
  if (!Array.isArray(input)) return { ok: false, error: 'invalid_maps' };
  if (input.length > MAP_LIMITS.maxMaps) return { ok: false, error: 'too_many_maps' };

  const maps = [];
  const ids = new Set();
  for (const raw of input) {
    const result = validateMap(raw);
    if (!result.ok) return { ...result, detail: result.detail ?? raw?.id };
    if (ids.has(result.map.id)) return { ok: false, error: 'duplicate_map_id' };
    ids.add(result.map.id);
    maps.push(result.map);
  }
  if (JSON.stringify(maps).length > MAP_LIMITS.maxTotalBytes) {
    return { ok: false, error: 'maps_too_large' };
  }
  return { ok: true, maps };
}
