/**
 * AI Groove – Sample-Verwaltung und Stimmen (Voices).
 *
 * Samples werden EINMAL dekodiert und als AudioBuffer im Speicher gehalten.
 * Beim Abspielen entsteht pro Ton ein AudioBufferSourceNode (Einwegknoten,
 * so sieht es die Web Audio API vor). Die Knoten trennen sich nach dem
 * Ausklingen selbst vom Graphen, damit kein Speicher leckt.
 */

import { audioStore } from '../core/idb.js';
import { semitonesToRate, clamp } from '../core/util.js';
import { decodeErrorMessage, extensionOf } from '../core/file.js';

/** Dekodierte Buffer, Schluessel ist die dataId. */
const cache = new Map();
/** Laufende Dekodierungen, damit dieselbe Datei nicht doppelt dekodiert wird. */
const pending = new Map();

/** Dekodiert Audiodaten – mit Fallback auf die alte Callback-Form (Safari). */
export function decodeAudio(ctx, arrayBuffer) {
  return new Promise((resolve, reject) => {
    let settled = false;
    const ok = (buf) => {
      if (!settled) {
        settled = true;
        resolve(buf);
      }
    };
    const fail = (err) => {
      if (!settled) {
        settled = true;
        reject(err);
      }
    };
    try {
      const ret = ctx.decodeAudioData(arrayBuffer, ok, fail);
      if (ret && typeof ret.then === 'function') ret.then(ok, fail);
    } catch (err) {
      fail(err);
    }
  });
}

export const samples = {
  has(dataId) {
    return cache.has(dataId);
  },

  peek(dataId) {
    return cache.get(dataId) || null;
  },

  set(dataId, buffer) {
    cache.set(dataId, buffer);
  },

  /** Laedt und dekodiert ein Sample (mit Zwischenspeicher). */
  async load(ctx, dataId) {
    if (cache.has(dataId)) return cache.get(dataId);
    if (pending.has(dataId)) return pending.get(dataId);

    const task = (async () => {
      const row = await audioStore.get(dataId);
      if (!row) throw new Error('Die Audiodaten dieses Samples fehlen im lokalen Speicher.');
      try {
        // decodeAudioData "verbraucht" den Buffer in manchen Browsern: Kopie geben.
        const buffer = await decodeAudio(ctx, row.bytes.slice(0));
        cache.set(dataId, buffer);
        return buffer;
      } catch (err) {
        const ext = extensionOf(row.mime.replace('audio/', '.'));
        throw new Error(decodeErrorMessage(ext));
      } finally {
        pending.delete(dataId);
      }
    })();

    pending.set(dataId, task);
    return task;
  },

  /** Laedt mehrere Samples parallel und meldet Fehler einzeln. */
  async loadAll(ctx, dataIds, onError) {
    await Promise.all(
      dataIds.map((id) =>
        this.load(ctx, id).catch((err) => {
          onError?.(id, err);
        }),
      ),
    );
  },

  evict(dataId) {
    cache.delete(dataId);
  },

  clear() {
    cache.clear();
    pending.clear();
  },

  /** Geschaetzter Speicherbedarf der dekodierten Buffer in Bytes. */
  memoryUsage() {
    let bytes = 0;
    for (const buf of cache.values()) {
      bytes += buf.length * buf.numberOfChannels * 4;
    }
    return bytes;
  },

  count() {
    return cache.size;
  },
};

/**
 * Startet eine Stimme.
 *
 * @param {BaseAudioContext} ctx
 * @param {AudioBuffer} buffer
 * @param {AudioNode} destination
 * @param {object} opts
 * @param {number} opts.time      Startzeit (AudioContext-Zeit)
 * @param {number} [opts.gain]    Linearer Faktor
 * @param {number} [opts.pitch]   Halbtoene
 * @param {number} [opts.offset]  Startversatz im Sample (Sekunden)
 * @param {number} [opts.duration] Maximale Laenge (Sekunden)
 * @param {number} [opts.fade]    Ausblendzeit am Ende (Sekunden)
 * @returns {{source: AudioBufferSourceNode, gainNode: GainNode, stop: (t?:number)=>void, endTime:number}}
 */
export function playVoice(ctx, buffer, destination, opts) {
  const time = Math.max(ctx.currentTime, opts.time ?? ctx.currentTime);
  const rate = semitonesToRate(opts.pitch || 0);
  const gain = Math.max(0, opts.gain ?? 1);

  const source = ctx.createBufferSource();
  source.buffer = buffer;
  source.playbackRate.value = rate;

  const gainNode = ctx.createGain();
  gainNode.gain.value = gain;

  source.connect(gainNode);
  gainNode.connect(destination);

  const offset = clamp(opts.offset || 0, 0, Math.max(0, buffer.duration - 0.001));
  const naturalDur = (buffer.duration - offset) / rate;
  const duration = opts.duration != null ? Math.min(opts.duration, naturalDur) : naturalDur;

  // Sehr kurze Ein-/Ausblendung verhindert Knackser an den Schnittkanten.
  const fade = Math.min(opts.fade ?? 0.004, Math.max(0.001, duration / 4));
  gainNode.gain.setValueAtTime(0, time);
  gainNode.gain.linearRampToValueAtTime(gain, time + Math.min(0.003, fade));

  const endTime = time + duration;
  gainNode.gain.setValueAtTime(gain, Math.max(time, endTime - fade));
  gainNode.gain.linearRampToValueAtTime(0, endTime);

  try {
    source.start(time, offset);
    source.stop(endTime + 0.01);
  } catch (err) {
    // Kann passieren, wenn der Context zwischenzeitlich geschlossen wurde.
    try {
      source.disconnect();
      gainNode.disconnect();
    } catch (_) {
      /* egal */
    }
    return { source, gainNode, stop() {}, endTime: time };
  }

  source.onended = () => {
    try {
      source.disconnect();
      gainNode.disconnect();
    } catch (_) {
      /* egal */
    }
  };

  return {
    source,
    gainNode,
    endTime,
    /** Beendet die Stimme mit kurzem Ausblenden (kein Knacken). */
    stop(at) {
      const t = Math.max(ctx.currentTime, at ?? ctx.currentTime);
      try {
        gainNode.gain.cancelScheduledValues(t);
        gainNode.gain.setValueAtTime(Math.max(0.0001, gainNode.gain.value), t);
        gainNode.gain.exponentialRampToValueAtTime(0.0001, t + 0.02);
        source.stop(t + 0.025);
      } catch (_) {
        /* bereits beendet */
      }
    },
  };
}

/**
 * Verwaltet laufende Stimmen pro Quelle (Pad, Kanal, Vorhoeren).
 * Ermoeglicht "Hold"- und "Toggle"-Pads sowie ein sauberes Stoppen.
 */
export class VoicePool {
  constructor() {
    /** @type {Map<string, Set<object>>} */
    this.map = new Map();
  }

  add(key, voice) {
    let set = this.map.get(key);
    if (!set) {
      set = new Set();
      this.map.set(key, set);
    }
    set.add(voice);
    voice.source.addEventListener?.('ended', () => set.delete(voice));
    const prev = voice.source.onended;
    voice.source.onended = (e) => {
      prev?.(e);
      set.delete(voice);
      if (set.size === 0) this.map.delete(key);
    };
    return voice;
  }

  has(key) {
    const set = this.map.get(key);
    return !!set && set.size > 0;
  }

  stop(key, at) {
    const set = this.map.get(key);
    if (!set) return;
    for (const v of set) v.stop(at);
    this.map.delete(key);
  }

  stopAll(at) {
    for (const key of [...this.map.keys()]) this.stop(key, at);
  }

  count() {
    let n = 0;
    for (const set of this.map.values()) n += set.size;
    return n;
  }
}
