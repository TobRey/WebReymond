// Bestenliste: erst den Server versuchen, sonst lokal weitermachen.
//
// Der Modus wird EINMAL beim Laden bestimmt und danach nicht mehr gewechselt –
// eine Liste, die zwischen weltweit und lokal hin- und herspringt, wäre für
// niemanden nachvollziehbar.

import { readJson, writeJson } from '../engine/storage.js';
import { compareEntries, validateEntry } from './scoreRules.js';

const ENDPOINT = './score.php';
const LOCAL_KEY = 'mogli.scores.v1';
const BEST_KEY = 'mogli.best.v1';
const LOCAL_MAX = 25;
const PROBE_TIMEOUT_MS = 3500;

/** @type {'unknown'|'server'|'local'} */
let mode = 'unknown';

export function getMode() {
  return mode;
}

function localList() {
  const stored = readJson(LOCAL_KEY, null);
  if (stored === null || !Array.isArray(stored.entries)) return [];
  return stored.entries.filter((e) => e && typeof e.name === 'string' && Number.isFinite(e.score));
}

function saveLocal(entries) {
  writeJson(LOCAL_KEY, { version: 1, entries: entries.slice(0, LOCAL_MAX) });
}

function addLocal(entry) {
  const entries = localList();
  const fresh = { ...entry, at: Math.floor(Date.now() / 1000) };
  entries.push(fresh);
  entries.sort(compareEntries);
  // sort() arbeitet auf denselben Objekten, deshalb findet indexOf den neuen
  // Eintrag zuverlässig auch bei gleichem Punktestand.
  const index = entries.indexOf(fresh);
  const trimmed = entries.slice(0, LOCAL_MAX);
  saveLocal(trimmed);
  return { entries: trimmed, rank: index >= 0 && index < LOCAL_MAX ? index + 1 : 0 };
}

export function personalBest() {
  const stored = readJson(BEST_KEY, null);
  return stored !== null && Number.isFinite(stored.score) ? stored.score : 0;
}

export function rememberPersonalBest(score) {
  if (score > personalBest()) {
    writeJson(BEST_KEY, { score, at: Math.floor(Date.now() / 1000) });
    return true;
  }
  return false;
}

/**
 * Antwort des Servers holen und dabei sicherstellen, dass es wirklich JSON von
 * score.php ist. Ein statischer Webspace ohne PHP liefert je nach Einstellung
 * den PHP-Quelltext, eine 404-Seite oder gleich HTML – alles davon würde
 * JSON.parse werfen und muss als "kein Server" gelten.
 */
async function requestJson(options, timeoutMs) {
  const controller = new AbortController();
  const timer = window.setTimeout(() => controller.abort(), timeoutMs);
  try {
    const response = await window.fetch(ENDPOINT, { ...options, signal: controller.signal });
    const type = response.headers.get('content-type') || '';
    if (!type.includes('application/json')) return null;
    const data = await response.json();
    if (data === null || typeof data !== 'object') return null;
    return { status: response.status, data };
  } catch {
    return null;
  } finally {
    window.clearTimeout(timer);
  }
}

/** Einmalige Erkennung: gibt es ein funktionierendes score.php? */
export async function probe() {
  if (mode !== 'unknown') return mode;
  const result = await requestJson(
    { method: 'GET', headers: { Accept: 'application/json' } },
    PROBE_TIMEOUT_MS,
  );
  mode = result !== null && result.data.ok === true ? 'server' : 'local';
  return mode;
}

/**
 * @returns {Promise<{mode: string, entries: object[], error?: string}>}
 */
export async function fetchTop(count = 25) {
  await probe();
  if (mode === 'local') return { mode, entries: localList() };

  const result = await requestJson(
    { method: 'GET', headers: { Accept: 'application/json' } },
    PROBE_TIMEOUT_MS,
  );
  if (result === null || result.data.ok !== true) {
    // Der Server war beim Start noch da, jetzt nicht mehr: lokale Liste
    // zeigen, aber den Modus nicht heimlich umstellen.
    return { mode, entries: localList(), error: 'network' };
  }
  const entries = Array.isArray(result.data.scores) ? result.data.scores : [];
  return { mode, entries: entries.slice(0, count) };
}

/**
 * Trägt ein Ergebnis ein. Lokal wird IMMER geschrieben – auch im Servermodus,
 * damit ein persönlicher Bestwert einen Serverausfall überlebt.
 *
 * @param {{name: string, score: number, time: number}} raw
 * @returns {Promise<{ok: boolean, mode: string, rank: number, entries: object[], error?: string}>}
 */
export async function submit(raw) {
  const checked = validateEntry(raw);
  if (!checked.ok) return { ok: false, mode, rank: 0, entries: localList(), error: checked.error };

  const entry = checked.entry;
  const local = addLocal(entry);
  await probe();

  if (mode === 'local') {
    return { ok: true, mode, rank: local.rank, entries: local.entries };
  }

  const result = await requestJson(
    {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(entry),
    },
    PROBE_TIMEOUT_MS,
  );

  if (result === null) {
    return { ok: false, mode, rank: local.rank, entries: local.entries, error: 'network' };
  }
  if (result.data.ok !== true) {
    const error = typeof result.data.error === 'string' ? result.data.error : 'unknown';
    return { ok: false, mode, rank: local.rank, entries: local.entries, error };
  }

  const entries = Array.isArray(result.data.scores) ? result.data.scores : [];
  return { ok: true, mode, rank: Number(result.data.rank) || 0, entries };
}
