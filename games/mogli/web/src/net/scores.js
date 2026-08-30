// Bestenliste: erst den Server versuchen, sonst lokal weitermachen.
//
// Der Modus wird EINMAL beim Laden bestimmt und danach nicht mehr gewechselt –
// eine Liste, die zwischen weltweit und lokal hin- und herspringt, wäre für
// niemanden nachvollziehbar.
//
// Seit 3.0 gibt es je Level eine Liste, sortiert aufsteigend nach Zeit.

import { readJson, writeJson } from '../engine/storage.js';
import { validateEntry } from './scoreRules.js';

const ENDPOINT = './score.php';
const LOCAL_KEY = 'mogli.times.v1';
const LOCAL_MAX = 25;
const PROBE_TIMEOUT_MS = 3500;

/** @type {'unknown'|'server'|'local'} */
let mode = 'unknown';

export function getMode() {
  return mode;
}

/** @returns {{[mapId: string]: {name:string,ticks:number,at:number}[]}} */
function localStore() {
  const stored = readJson(LOCAL_KEY, null);
  return stored !== null && typeof stored.maps === 'object' && stored.maps !== null
    ? stored.maps
    : {};
}

function sortAsc(entries) {
  return [...entries].sort((a, b) => a.ticks - b.ticks || a.at - b.at);
}

function addLocal(entry) {
  const maps = localStore();
  const fresh = { name: entry.name, ticks: entry.ticks, at: Math.floor(Date.now() / 1000) };
  const list = sortAsc([...(maps[entry.map] ?? []), fresh]);
  const rank = list.indexOf(fresh) + 1;
  maps[entry.map] = list.slice(0, LOCAL_MAX);
  writeJson(LOCAL_KEY, { version: 1, maps });
  return { entries: maps[entry.map], rank: rank <= LOCAL_MAX ? rank : 0 };
}

/**
 * Antwort des Servers holen und dabei sicherstellen, dass es wirklich JSON
 * von score.php ist – ein Webspace ohne PHP liefert den Quelltext aus, und
 * der beginnt mit "<?php".
 */
async function serverJson(url, options = {}) {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), PROBE_TIMEOUT_MS);
  try {
    const response = await fetch(url, { ...options, signal: controller.signal });
    const text = await response.text();
    if (text.trimStart().startsWith('<')) throw new Error('kein JSON');
    const data = JSON.parse(text);
    if (data.ok !== true) throw new Error(data.error ?? 'Fehler');
    return data;
  } finally {
    clearTimeout(timer);
  }
}

/**
 * Einmal beim Start: läuft dort ein Server? Geprüft wird mit einer echten
 * Levelliste-Anfrage (die Demo-Karte gibt es immer).
 */
export async function probe() {
  try {
    await serverJson(`${ENDPOINT}?map=dschungelpfad&top=1`);
    mode = 'server';
  } catch {
    mode = 'local';
  }
  return mode;
}

/** Die Top-Liste eines Levels. */
export async function fetchTop(mapId, count = 10) {
  if (mode === 'server') {
    try {
      const data = await serverJson(`${ENDPOINT}?map=${encodeURIComponent(mapId)}&top=${count}`);
      return { mode: 'server', entries: data.scores ?? [] };
    } catch {
      // Einmal Server, immer Server – ein Aussetzer zeigt eine leere Liste,
      // wechselt aber nicht den Modus.
      return { mode: 'server', entries: [] };
    }
  }
  return { mode: 'local', entries: sortAsc(localStore()[mapId] ?? []).slice(0, count) };
}

/**
 * Trägt eine Zeit ein.
 * @returns {Promise<{mode:string, rank:number, entries:object[]}>}
 */
export async function submit(name, mapId, ticks) {
  const checked = validateEntry({ name, map: mapId, ticks });
  if (!checked.ok) return { mode, rank: 0, entries: [], error: checked.error };
  const entry = checked.entry;

  if (mode === 'server') {
    try {
      const data = await serverJson(ENDPOINT, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(entry),
      });
      return { mode: 'server', rank: data.rank ?? 0, entries: data.scores ?? [] };
    } catch (error) {
      return { mode: 'server', rank: 0, entries: [], error: String(error.message ?? error) };
    }
  }

  const local = addLocal(entry);
  return { mode: 'local', rank: local.rank, entries: local.entries };
}
