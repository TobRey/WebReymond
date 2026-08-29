// Die Regeln für einen Bestenlisteneintrag – einmal aufgeschrieben, an drei
// Stellen benutzt: im Client (spart sinnlose Anfragen), in den Tests, und in
// score.php noch einmal in PHP. Massgeblich ist immer die Prüfung im Server;
// diese hier ist die freundliche Vorprüfung.
//
// Kein DOM, keine Abhängigkeiten – damit test/scores.test.mjs die Datei direkt
// in Node laden kann.

export const NAME_MAX = 12;
export const SCORE_MAX = 200000;
export const TIME_MIN = 3;
export const TIME_MAX = 7200;

/**
 * Obergrenze für Punkte je Sekunde. Hergeleitet: die Gefahr steigt mit
 * höchstens 90 px/s = 5.6 m/s, schneller kann niemand dauerhaft klettern.
 * Edelsteine geben je 25 Punkte extra. 20 Punkte pro Sekunde plus 100
 * Startguthaben lässt jeden ehrlichen Lauf durch und wirft trotzdem die
 * "999999 Punkte in vier Sekunden"-Fälschungen heraus.
 */
export const MAX_SCORE_PER_SECOND = 20;
export const SCORE_GRACE = 100;

/** Erlaubt Buchstaben, Ziffern, Leerzeichen und - _ . – sonst nichts. */
const FORBIDDEN = /[^\p{L}\p{N} _.-]/gu;

export function sanitizeName(raw) {
  if (typeof raw !== 'string') return 'ANON';
  const cleaned = raw.replace(FORBIDDEN, '').replace(/\s+/g, ' ').trim().slice(0, NAME_MAX);
  return cleaned.length === 0 ? 'ANON' : cleaned;
}

export function isPlausible(score, seconds) {
  if (!Number.isFinite(score) || !Number.isFinite(seconds)) return false;
  return score <= MAX_SCORE_PER_SECOND * seconds + SCORE_GRACE;
}

/**
 * @param {{name?: unknown, score?: unknown, time?: unknown}} entry
 * @returns {{ok: true, entry: {name: string, score: number, time: number}}
 *   | {ok: false, error: string}}
 */
export function validateEntry(entry) {
  if (entry === null || typeof entry !== 'object') return { ok: false, error: 'invalid_payload' };

  const score = Number(entry.score);
  const time = Number(entry.time);

  if (!Number.isInteger(score) || score < 1 || score > SCORE_MAX) {
    return { ok: false, error: 'invalid_payload' };
  }
  if (!Number.isInteger(time) || time < TIME_MIN || time > TIME_MAX) {
    return { ok: false, error: 'invalid_payload' };
  }
  if (!isPlausible(score, time)) return { ok: false, error: 'invalid_payload' };

  return { ok: true, entry: { name: sanitizeName(entry.name), score, time } };
}

/** Sortierung der Bestenliste: mehr Punkte zuerst, bei Gleichstand der ältere. */
export function compareEntries(a, b) {
  if (b.score !== a.score) return b.score - a.score;
  return (a.at ?? 0) - (b.at ?? 0);
}
