// Lädt die Karten für das Spiel und merkt den Fortschritt.
//
// Zwei Quellen, dieselbe Reihenfolge wie bei der Bestenliste:
//   1. maps.php (Server) – die Karten aus dem Editor, für alle gleich.
//   2. localStorage – der Rückfall ohne PHP: dort speichert der Editor lokal.
// Ist beides leer, bleibt die eingebaute Demo-Karte. Das Spiel läuft also
// IMMER, auch auf einem Webspace ohne PHP und ohne je den Editor zu öffnen.

import { demoMap } from '../game/map.js';
import { validateMaps } from './mapRules.js';
import { readJson, writeJson } from '../engine/storage.js';

export const LOCAL_MAPS_KEY = 'mogli.maps.v1';
const PROGRESS_KEY = 'mogli.progress.v1';

/**
 * @returns {Promise<{maps: object[], source: 'server'|'local'|'builtin'}>}
 */
export async function loadMaps() {
  try {
    const response = await fetch('maps.php', { cache: 'no-store' });
    if (response.ok) {
      const data = await response.json();
      const checked = validateMaps(data.maps ?? []);
      if (checked.ok && checked.maps.length > 0) {
        return { maps: checked.maps, source: 'server' };
      }
    }
  } catch {
    // Kein PHP oder kein Netz: unten weiter.
  }

  const local = validateMaps(readJson(LOCAL_MAPS_KEY, []));
  if (local.ok && local.maps.length > 0) {
    return { maps: local.maps, source: 'local' };
  }

  return { maps: [demoMap()], source: 'builtin' };
}

/**
 * Fortschritt: je Karte die eigene Bestzeit in Ticks. Eine Karte ist
 * freigeschaltet, wenn sie die erste ist oder die davor eine Zeit hat.
 */
export function progress() {
  const raw = readJson(PROGRESS_KEY, {});
  return raw !== null && typeof raw === 'object' ? raw : {};
}

export function bestTicksFor(mapId) {
  const ticks = progress()[mapId];
  return Number.isFinite(ticks) ? ticks : null;
}

/** @returns {boolean} true, wenn das eine neue Bestzeit war */
export function rememberResult(mapId, ticks) {
  const all = progress();
  const before = all[mapId];
  if (Number.isFinite(before) && before <= ticks) return false;
  all[mapId] = ticks;
  writeJson(PROGRESS_KEY, all);
  return true;
}

export function isUnlocked(maps, index) {
  if (index === 0) return true;
  const before = maps[index - 1];
  return before !== undefined && bestTicksFor(before.id) !== null;
}
