/**
 * Objektgedächtnis: eigene Gegenstände benennen und wiedererkennen.
 *
 * Wiedererkennung ohne eigenes Modell: Aus dem Bildausschnitt eines
 * Gegenstands entsteht ein kleiner Zahlenvektor – 12×12 Graustufen für die
 * Form plus ein Farbton-Histogramm. Zwei Ausschnitte desselben Gegenstands
 * liefern ähnliche Vektoren.
 *
 * Was das leistet und was nicht: Es unterscheidet *deinen* roten Rucksack von
 * *einem* Stuhl sehr zuverlässig. Zwei baugleiche schwarze Rucksäcke
 * unterscheidet es nicht. Deshalb zählt die Objektklasse immer mit: Ein
 * gespeicherter "Rucksack" wird nur gegen erkannte Rucksäcke geprüft.
 */

import { makeId } from './store.js';

const GRID = 12;
const HUE_BINS = 24;
export const DESCRIPTOR_SIZE = GRID * GRID + HUE_BINS;

const DB_NAME = 'visionhud-objects';
const STORE = 'objects';

/**
 * Beschreibt einen Bildausschnitt als Zahlenvektor.
 * @returns {Float32Array}
 */
export function describeCrop(canvas) {
  const work = document.createElement('canvas');
  work.width = GRID;
  work.height = GRID;
  const ctx = work.getContext('2d', { willReadFrequently: true });
  ctx.drawImage(canvas, 0, 0, GRID, GRID);
  const data = ctx.getImageData(0, 0, GRID, GRID).data;

  const out = new Float32Array(DESCRIPTOR_SIZE);
  const hues = new Float32Array(HUE_BINS);
  let sum = 0;

  for (let i = 0, p = 0; i < data.length; i += 4, p += 1) {
    const r = data[i] / 255;
    const g = data[i + 1] / 255;
    const b = data[i + 2] / 255;

    const grey = 0.299 * r + 0.587 * g + 0.114 * b;
    out[p] = grey;
    sum += grey;

    const max = Math.max(r, g, b);
    const min = Math.min(r, g, b);
    const delta = max - min;
    // Graue Pixel haben keinen sinnvollen Farbton – sie zählen nicht mit.
    if (delta > 0.12) {
      let hue;
      if (max === r) hue = ((g - b) / delta + 6) % 6;
      else if (max === g) hue = (b - r) / delta + 2;
      else hue = (r - g) / delta + 4;
      hues[Math.min(HUE_BINS - 1, Math.floor((hue / 6) * HUE_BINS))] += delta;
    }
  }

  // Helligkeit herausrechnen: derselbe Gegenstand im Schatten soll passen.
  const mean = sum / (GRID * GRID);
  for (let p = 0; p < GRID * GRID; p += 1) out[p] -= mean;

  const hueSum = hues.reduce((a, b) => a + b, 0) || 1;
  for (let i = 0; i < HUE_BINS; i += 1) out[GRID * GRID + i] = (hues[i] / hueSum) * 2;

  // Auf Länge 1 bringen, damit das Skalarprodukt direkt die Ähnlichkeit ist.
  let norm = 0;
  for (const value of out) norm += value * value;
  norm = Math.sqrt(norm) || 1;
  for (let i = 0; i < out.length; i += 1) out[i] /= norm;

  return out;
}

/** Ähnlichkeit zweier Vektoren, 0 … 1. */
export function similarity(a, b) {
  if (!a || !b || a.length !== b.length) return 0;
  let dot = 0;
  for (let i = 0; i < a.length; i += 1) dot += a[i] * b[i];
  return Math.max(0, dot);
}

export class ObjectMemory {
  constructor() {
    this.db = null;
    this.items = [];
    /** Ab dieser Ähnlichkeit gilt ein Gegenstand als wiedererkannt. */
    this.threshold = 0.72;
  }

  async open() {
    if (this.db || typeof indexedDB === 'undefined') return;
    this.db = await new Promise((resolve, reject) => {
      const request = indexedDB.open(DB_NAME, 1);
      request.onupgradeneeded = () => {
        const db = request.result;
        if (!db.objectStoreNames.contains(STORE)) db.createObjectStore(STORE, { keyPath: 'id' });
      };
      request.onsuccess = () => resolve(request.result);
      request.onerror = () => reject(request.error ?? new Error('Speicher nicht verfügbar'));
      setTimeout(() => reject(new Error('Zeitüberschreitung')), 4000);
    }).catch(() => null);
    await this.reload();
  }

  async reload() {
    if (!this.db) {
      this.items = [];
      return this.items;
    }
    const tx = this.db.transaction(STORE, 'readonly');
    const rows = await new Promise((resolve) => {
      const request = tx.objectStore(STORE).getAll();
      request.onsuccess = () => resolve(request.result ?? []);
      request.onerror = () => resolve([]);
    });
    this.items = rows.map((row) => ({
      ...row,
      descriptors: (row.descriptors ?? []).map((d) => Float32Array.from(d)),
    }));
    return this.items;
  }

  get size() {
    return this.items.length;
  }

  /**
   * Legt einen Gegenstand an.
   * @param {{name: string, note?: string, cocoClass?: string, descriptor: Float32Array,
   *          thumb?: string, place?: object|null}} entry
   */
  async add(entry) {
    const item = {
      id: makeId(),
      name: entry.name.slice(0, 48),
      note: (entry.note ?? '').slice(0, 280),
      cocoClass: entry.cocoClass ?? null,
      descriptors: [entry.descriptor],
      thumb: entry.thumb ?? null,
      createdAt: new Date().toISOString(),
      lastSeenAt: new Date().toISOString(),
      lastPlace: entry.place ?? null,
      seenCount: 1,
    };
    await this.#put(item);
    await this.reload();
    return item;
  }

  /** Hält fest, wann und wo ein bekannter Gegenstand zuletzt auftauchte. */
  async touch(id, place) {
    const item = this.items.find((row) => row.id === id);
    if (!item) return;
    item.lastSeenAt = new Date().toISOString();
    item.seenCount = (item.seenCount ?? 0) + 1;
    if (place) item.lastPlace = place;
    await this.#put(item);
  }

  async remove(id) {
    if (!this.db) return;
    const tx = this.db.transaction(STORE, 'readwrite');
    tx.objectStore(STORE).delete(id);
    await new Promise((resolve) => {
      tx.oncomplete = resolve;
    });
    await this.reload();
  }

  async clear() {
    if (!this.db) return;
    const tx = this.db.transaction(STORE, 'readwrite');
    tx.objectStore(STORE).clear();
    await new Promise((resolve) => {
      tx.oncomplete = resolve;
    });
    await this.reload();
  }

  /**
   * Sucht den ähnlichsten gespeicherten Gegenstand.
   * @param {Float32Array} descriptor
   * @param {string|null} cocoClass Nur Einträge derselben Klasse vergleichen
   */
  match(descriptor, cocoClass = null) {
    let best = null;
    let bestScore = 0;

    for (const item of this.items) {
      if (cocoClass && item.cocoClass && item.cocoClass !== cocoClass) continue;
      for (const known of item.descriptors) {
        const score = similarity(descriptor, known);
        if (score > bestScore) {
          bestScore = score;
          best = item;
        }
      }
    }

    return bestScore >= this.threshold
      ? { item: best, score: bestScore }
      : { item: null, score: bestScore };
  }

  /** Sucht nach Namen – für „Wo ist mein Schlüssel?“. */
  findByName(name) {
    const needle = name.toLowerCase().trim();
    if (!needle) return null;
    return (
      this.items.find((item) => item.name.toLowerCase() === needle) ??
      this.items.find((item) => item.name.toLowerCase().includes(needle)) ??
      this.items.find((item) => needle.includes(item.name.toLowerCase())) ??
      null
    );
  }

  async #put(item) {
    if (!this.db) return;
    const tx = this.db.transaction(STORE, 'readwrite');
    tx.objectStore(STORE).put({ ...item, descriptors: item.descriptors.map((d) => Array.from(d)) });
    await new Promise((resolve) => {
      tx.oncomplete = resolve;
    });
  }
}

/** „vor 3 Minuten“, „gestern um 14:20“ – für die Sprachausgabe. */
export function describeWhen(iso) {
  const then = new Date(iso).getTime();
  if (!Number.isFinite(then)) return 'irgendwann';

  const minutes = Math.round((Date.now() - then) / 60000);
  if (minutes < 1) return 'gerade eben';
  if (minutes < 60) return `vor ${minutes} Minute${minutes === 1 ? '' : 'n'}`;

  const hours = Math.round(minutes / 60);
  if (hours < 24) return `vor ${hours} Stunde${hours === 1 ? '' : 'n'}`;

  const days = Math.round(hours / 24);
  if (days === 1) return 'gestern';
  if (days < 7) return `vor ${days} Tagen`;
  return `am ${new Date(then).toLocaleDateString('de-CH')}`;
}
