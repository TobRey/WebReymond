// Die Regeln für einen Bestenlisteneintrag – einmal aufgeschrieben, an drei
// Stellen benutzt: im Client (spart sinnlose Anfragen), in den Tests, und in
// score.php noch einmal in PHP. Massgeblich ist immer die Prüfung im Server;
// diese hier ist die freundliche Vorprüfung.
//
// Seit 3.0 zählt die ZEIT, nicht mehr die Höhe: je Level eine Liste, sortiert
// aufsteigend nach Wertungszeit in Ticks (60 je Sekunde). Smaragde sind schon
// eingerechnet – der Client meldet world.resultTicks, nichts anderes.
//
// Kein DOM, keine Abhängigkeiten – damit test/scores.test.mjs die Datei direkt
// in Node laden kann.

export const NAME_MAX = 12;
export const MAP_ID = /^[a-z0-9-]{1,24}$/;

/**
 * Schneller als drei Sekunden ist kein Level – die kleinste erlaubte Karte
 * ist zwanzig Zellen breit, und selbst die läuft man nicht schneller ab.
 * Nach oben zwei Stunden, wie bisher.
 */
export const TICKS_MIN = 180;
export const TICKS_MAX = 432000;

/** Erlaubt Buchstaben, Ziffern, Leerzeichen und - _ . – sonst nichts. */
const FORBIDDEN = /[^\p{L}\p{N} _.-]/gu;

export function sanitizeName(raw) {
  if (typeof raw !== 'string') return 'ANON';
  const cleaned = raw.replace(FORBIDDEN, '').replace(/\s+/g, ' ').trim().slice(0, NAME_MAX);
  return cleaned.length === 0 ? 'ANON' : cleaned;
}

/**
 * @param {{name?: unknown, map?: unknown, ticks?: unknown}} entry
 * @returns {{ok: true, entry: {name: string, map: string, ticks: number}}
 *   | {ok: false, error: string}}
 */
export function validateEntry(entry) {
  if (entry === null || typeof entry !== 'object') return { ok: false, error: 'invalid' };
  const map = entry.map;
  if (typeof map !== 'string' || !MAP_ID.test(map)) return { ok: false, error: 'invalid_map' };
  const ticks = Number(entry.ticks);
  if (!Number.isInteger(ticks) || ticks < TICKS_MIN || ticks > TICKS_MAX) {
    return { ok: false, error: 'invalid_ticks' };
  }
  return { ok: true, entry: { name: sanitizeName(entry.name), map, ticks } };
}
