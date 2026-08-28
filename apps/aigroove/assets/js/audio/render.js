/**
 * AI Groove – Offline-Rendering und Export.
 *
 * Der Export nimmt NICHT den Lautsprecher- oder Mikrofonausgang auf.
 * Stattdessen wird das komplette Arrangement in einem OfflineAudioContext
 * neu berechnet – schneller als Echtzeit und exakt reproduzierbar.
 *
 * Beruecksichtigt werden: Arrangement, Samples, Tonhoehe, Lautstaerke,
 * Panorama, alle Kanaleffekte, die Master-Kette und der Master-Pegel.
 */

import { audioStore } from '../core/idb.js';
import { MixerGraph } from './graph.js';
import { buildTimeline, swingOffset } from './timeline.js';
import { decodeAudio, playVoice } from './sampler.js';
import { encodeWav } from './wav.js';
import { toInt16Channels } from './wav.js';
import { TICKS_PER_BAR, ticksToSeconds, clamp } from '../core/util.js';

/**
 * Rendert ein Projekt in einen AudioBuffer.
 *
 * @param {object} project
 * @param {object} options
 * @param {'song'|'pattern'} [options.mode]
 * @param {string} [options.patternId]
 * @param {number} [options.sampleRate]
 * @param {number} [options.tailSeconds] Nachlauf fuer Hall/Delay
 * @param {{from:number,to:number}} [options.range] Bereich in Ticks
 * @param {(p:number, label:string) => void} [options.onProgress]
 * @returns {Promise<AudioBuffer>}
 */
export async function renderProject(project, options = {}) {
  const {
    mode = 'song',
    patternId = project.ui?.selectedPatternId || null,
    sampleRate = 44100,
    tailSeconds = 2.5,
    range = null,
    onProgress = () => {},
  } = options;

  onProgress(0.02, 'Vorbereiten …');

  const { events, end } = buildTimeline(project, mode, patternId);

  const fromTick = range ? Math.max(0, range.from) : 0;
  const toTick = range ? Math.max(fromTick + 1, range.to) : end;

  const bpm = project.bpm;
  const durationSec = ticksToSeconds(toTick - fromTick, bpm) + tailSeconds;
  const frames = Math.ceil(durationSec * sampleRate);

  if (frames < 1 || !isFinite(frames)) {
    throw new Error('Das Projekt enthält nichts zum Exportieren.');
  }
  if (frames > sampleRate * 60 * 30) {
    throw new Error('Das Arrangement ist länger als 30 Minuten und kann nicht in einem Stück exportiert werden.');
  }

  const OfflineCtor = window.OfflineAudioContext || window.webkitOfflineAudioContext;
  if (!OfflineCtor) throw new Error('Dieser Browser unterstützt kein Offline-Rendering.');

  const ctx = new OfflineCtor(2, frames, sampleRate);

  // --- Samples in der Zielabtastrate dekodieren ------------------------------
  onProgress(0.06, 'Samples laden …');
  const buffers = new Map();
  const needed = new Set(events.map((e) => e.sampleId));
  let loaded = 0;
  for (const sampleId of needed) {
    const sample = project.samples.find((s) => s.id === sampleId);
    if (!sample) continue;
    const row = await audioStore.get(sample.dataId);
    if (!row) continue;
    try {
      buffers.set(sampleId, await decodeAudio(ctx, row.bytes.slice(0)));
    } catch (err) {
      console.warn('[render] Sample übersprungen', sample.name, err);
    }
    loaded++;
    onProgress(0.06 + (loaded / Math.max(1, needed.size)) * 0.18, 'Samples laden …');
  }

  // --- Mixer aufbauen --------------------------------------------------------
  const graph = new MixerGraph(ctx, { getBpm: () => bpm });
  graph.sync(project);

  // --- Ereignisse planen -----------------------------------------------------
  onProgress(0.26, 'Arrangement planen …');
  const startOffset = ticksToSeconds(fromTick, bpm);
  let scheduled = 0;

  for (const ev of events) {
    if (ev.tick < fromTick || ev.tick >= toTick) continue;
    const sample = project.samples.find((s) => s.id === ev.sampleId);
    const buffer = buffers.get(ev.sampleId);
    if (!sample || !buffer) continue;

    const time = ticksToSeconds(ev.tick, bpm) - startOffset + swingOffset(ev.tick, project.swing, bpm);
    if (time < 0) continue;

    const opts = {
      time,
      gain: sample.gain * (ev.vel ?? 1) * (ev.gain ?? 1),
      pitch: (sample.pitch || 0) + (ev.pitch || 0),
      offset: ev.offsetTicks ? ticksToSeconds(ev.offsetTicks, bpm) : 0,
    };
    if (ev.audioClip && ev.dur) {
      opts.duration = ticksToSeconds(ev.dur, bpm);
      opts.fade = 0.01;
    }
    playVoice(ctx, buffer, graph.inputFor(sample.id), opts);
    scheduled++;
  }

  if (scheduled === 0) {
    throw new Error('Es gibt nichts zu exportieren – im gewählten Bereich liegen keine Noten oder Clips.');
  }

  // --- Rendern mit Fortschrittsanzeige ---------------------------------------
  onProgress(0.3, 'Rendern …');
  const steps = 12;
  let progressWired = false;
  try {
    for (let i = 1; i < steps; i++) {
      const at = (durationSec * i) / steps;
      // suspend() ist der einzige Weg, waehrend des Offline-Renderings
      // Zwischenstaende zu melden. Nicht jeder Browser unterstuetzt es.
      ctx.suspend(at).then(() => {
        onProgress(0.3 + (i / steps) * 0.55, 'Rendern …');
        ctx.resume();
      });
    }
    progressWired = true;
  } catch (_) {
    progressWired = false;
  }
  if (!progressWired) onProgress(0.5, 'Rendern …');

  const rendered = await ctx.startRendering();
  graph.dispose();
  onProgress(0.9, 'Fertigstellen …');
  return rendered;
}

/** Verlustfreier WAV-Export. */
export function toWavBlob(buffer, bitDepth = 24) {
  return new Blob([encodeWav(buffer, { bitDepth })], { type: 'audio/wav' });
}

/**
 * MP3-Export (320 kbit/s) – vollstaendig im Browser, ohne Server.
 * Die Kodierung laeuft in einem Worker, damit die Oberflaeche bedienbar bleibt.
 */
export function toMp3Blob(buffer, { bitrate = 320, onProgress = () => {} } = {}) {
  return new Promise((resolve, reject) => {
    let worker;
    try {
      worker = new Worker(new URL('../workers/mp3-worker.js', import.meta.url), { type: 'classic' });
    } catch (err) {
      reject(new Error('Der MP3-Encoder konnte nicht gestartet werden.'));
      return;
    }

    const channels = toInt16Channels(buffer);
    const payload = {
      cmd: 'encode',
      left: channels[0],
      right: channels[1],
      channels: Math.min(2, buffer.numberOfChannels),
      sampleRate: buffer.sampleRate,
      bitrate: clamp(bitrate, 96, 320),
    };

    worker.onmessage = (event) => {
      const data = event.data;
      if (data.type === 'progress') {
        onProgress(data.value);
      } else if (data.type === 'done') {
        worker.terminate();
        resolve(new Blob(data.chunks, { type: 'audio/mpeg' }));
      } else if (data.type === 'error') {
        worker.terminate();
        reject(new Error(data.message || 'Die MP3-Kodierung ist fehlgeschlagen.'));
      }
    };
    worker.onerror = (err) => {
      worker.terminate();
      reject(new Error('Die MP3-Kodierung ist fehlgeschlagen.'));
    };

    const transfer = [];
    if (payload.left.buffer !== payload.right.buffer) {
      transfer.push(payload.left.buffer, payload.right.buffer);
    } else {
      transfer.push(payload.left.buffer);
    }
    worker.postMessage(payload, transfer);
  });
}

/** Geschaetzte Laenge des Arrangements in Sekunden. */
export function estimateDuration(project, mode = 'song') {
  const { end } = buildTimeline(project, mode, project.ui?.selectedPatternId);
  return ticksToSeconds(Math.max(end, TICKS_PER_BAR), project.bpm);
}
