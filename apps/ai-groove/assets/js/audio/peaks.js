/**
 * AI Groove – Waveform-Spitzenwerte.
 *
 * Peaks werden einmal in hoher Aufloesung berechnet und danach nur noch
 * heruntergerechnet. Grosse Samples laufen im Worker.
 */

import { computePeaks } from './dsp.js';

/** Aufloesung des zwischengespeicherten Peak-Satzes. */
const BASE_BUCKETS = 4096;

/** Ab dieser Laenge lohnt sich der Worker. */
const WORKER_THRESHOLD = 400000;

const cache = new Map();
const pending = new Map();

let worker = null;
let workerSeq = 0;
const workerJobs = new Map();

function getWorker() {
  if (worker) return worker;
  try {
    worker = new Worker(new URL('../workers/peaks-worker.js', import.meta.url), { type: 'classic' });
    worker.onmessage = (event) => {
      const job = workerJobs.get(event.data.id);
      if (job) {
        workerJobs.delete(event.data.id);
        job.resolve(event.data);
      }
    };
    worker.onerror = () => {
      for (const job of workerJobs.values()) job.reject(new Error('Peak-Worker abgestürzt'));
      workerJobs.clear();
      worker = null;
    };
  } catch (_) {
    worker = null;
  }
  return worker;
}

function computeInWorker(buffer, buckets) {
  const w = getWorker();
  if (!w) return null;
  const channels = [];
  for (let c = 0; c < Math.min(2, buffer.numberOfChannels); c++) {
    channels.push(new Float32Array(buffer.getChannelData(c)));
  }
  const id = ++workerSeq;
  const promise = new Promise((resolve, reject) => workerJobs.set(id, { resolve, reject }));
  w.postMessage({ id, channels, buckets }, channels.map((c) => c.buffer));
  return promise;
}

/**
 * Liefert (und speichert) die Basis-Peaks eines Samples.
 * @param {string} key   eindeutiger Schluessel (dataId)
 * @param {AudioBuffer} buffer
 */
export async function getPeaks(key, buffer) {
  if (cache.has(key)) return cache.get(key);
  if (pending.has(key)) return pending.get(key);

  const buckets = Math.min(BASE_BUCKETS, Math.max(64, buffer.length));
  const task = (async () => {
    let result = null;
    if (buffer.length > WORKER_THRESHOLD) {
      try {
        result = await computeInWorker(buffer, buckets);
      } catch (_) {
        result = null;
      }
    }
    if (!result) result = computePeaks(buffer, buckets);
    cache.set(key, result);
    pending.delete(key);
    return result;
  })();

  pending.set(key, task);
  return task;
}

/** Synchroner Zugriff, wenn die Peaks bereits vorliegen. */
export function peekPeaks(key) {
  return cache.get(key) || null;
}

export function invalidatePeaks(key) {
  cache.delete(key);
  pending.delete(key);
}

export function clearPeaks() {
  cache.clear();
  pending.clear();
}

/**
 * Rechnet einen Peak-Satz auf die gewuenschte Breite herunter.
 * @returns {{min: Float32Array, max: Float32Array}}
 */
export function resamplePeaks(peaks, width, from = 0, to = 1) {
  const n = Math.max(1, Math.floor(width));
  const min = new Float32Array(n);
  const max = new Float32Array(n);
  const total = peaks.buckets;
  const startBucket = from * total;
  const endBucket = to * total;
  const per = (endBucket - startBucket) / n;

  for (let i = 0; i < n; i++) {
    const s = Math.floor(startBucket + i * per);
    const e = Math.max(s + 1, Math.floor(startBucket + (i + 1) * per));
    let lo = 1;
    let hi = -1;
    for (let b = s; b < e && b < total; b++) {
      if (b < 0) continue;
      if (peaks.min[b] < lo) lo = peaks.min[b];
      if (peaks.max[b] > hi) hi = peaks.max[b];
    }
    if (lo > hi) {
      lo = 0;
      hi = 0;
    }
    min[i] = lo;
    max[i] = hi;
  }
  return { min, max };
}

/**
 * Zeichnet eine Waveform in einen 2D-Kontext.
 * Bewusst ohne Schatten/Blur – das waere auf dem iPhone zu teuer.
 */
export function drawWaveform(ctx, peaks, opts) {
  const {
    x = 0,
    y = 0,
    width,
    height,
    color = '#8f7dff',
    background = null,
    from = 0,
    to = 1,
    center = true,
    mirror = true,
  } = opts;

  if (background) {
    ctx.fillStyle = background;
    ctx.fillRect(x, y, width, height);
  }
  if (!peaks || width < 1) return;

  const data = resamplePeaks(peaks, Math.floor(width), from, to);
  const midY = center ? y + height / 2 : y + height;
  const scale = center ? height / 2 : height;

  ctx.fillStyle = color;
  ctx.beginPath();
  for (let i = 0; i < data.min.length; i++) {
    const hi = Math.max(-1, Math.min(1, data.max[i]));
    const lo = Math.max(-1, Math.min(1, data.min[i]));
    const top = midY - hi * scale;
    const bottom = mirror ? midY - lo * scale : midY;
    const h = Math.max(1, bottom - top);
    ctx.rect(x + i, top, 1, h);
  }
  ctx.fill();
}
