/**
 * AI Groove – Bearbeitung von AudioBuffern.
 *
 * Alle Funktionen sind rein: sie erzeugen neue Buffer und veraendern die
 * Eingabe nicht. Der Sample-Editor arbeitet damit nicht-destruktiv, bis der
 * Nutzer die Aenderungen bestaetigt.
 */

import { clamp, dbToGain, gainToDb } from '../core/util.js';

export function createBuffer(ctx, channels, length, sampleRate) {
  return ctx.createBuffer(Math.max(1, channels), Math.max(1, length), sampleRate);
}

export function copyBuffer(ctx, buffer) {
  const out = createBuffer(ctx, buffer.numberOfChannels, buffer.length, buffer.sampleRate);
  for (let c = 0; c < buffer.numberOfChannels; c++) {
    out.getChannelData(c).set(buffer.getChannelData(c));
  }
  return out;
}

/** Schneidet einen Bereich heraus (Frames). */
export function sliceBuffer(ctx, buffer, startFrame, endFrame) {
  const s = clamp(Math.round(startFrame), 0, buffer.length);
  const e = clamp(Math.round(endFrame), s + 1, buffer.length);
  const out = createBuffer(ctx, buffer.numberOfChannels, e - s, buffer.sampleRate);
  for (let c = 0; c < buffer.numberOfChannels; c++) {
    out.getChannelData(c).set(buffer.getChannelData(c).subarray(s, e));
  }
  return out;
}

/** Entfernt einen Bereich und setzt die Enden zusammen. */
export function deleteRange(ctx, buffer, startFrame, endFrame) {
  const s = clamp(Math.round(startFrame), 0, buffer.length);
  const e = clamp(Math.round(endFrame), s, buffer.length);
  const newLen = buffer.length - (e - s);
  if (newLen < 1) return createBuffer(ctx, buffer.numberOfChannels, 1, buffer.sampleRate);

  const out = createBuffer(ctx, buffer.numberOfChannels, newLen, buffer.sampleRate);
  for (let c = 0; c < buffer.numberOfChannels; c++) {
    const src = buffer.getChannelData(c);
    const dst = out.getChannelData(c);
    dst.set(src.subarray(0, s), 0);
    dst.set(src.subarray(e), s);
  }
  return out;
}

/** Fuegt Stille an einer Position ein. */
export function insertSilence(ctx, buffer, atFrame, frames) {
  const at = clamp(Math.round(atFrame), 0, buffer.length);
  const n = Math.max(1, Math.round(frames));
  const out = createBuffer(ctx, buffer.numberOfChannels, buffer.length + n, buffer.sampleRate);
  for (let c = 0; c < buffer.numberOfChannels; c++) {
    const src = buffer.getChannelData(c);
    const dst = out.getChannelData(c);
    dst.set(src.subarray(0, at), 0);
    dst.set(src.subarray(at), at + n);
  }
  return out;
}

/** Verstaerkung auf einen Bereich anwenden (linearer Faktor). */
export function applyGain(ctx, buffer, gain, startFrame = 0, endFrame = null) {
  const out = copyBuffer(ctx, buffer);
  const s = clamp(Math.round(startFrame), 0, out.length);
  const e = endFrame == null ? out.length : clamp(Math.round(endFrame), s, out.length);
  for (let c = 0; c < out.numberOfChannels; c++) {
    const d = out.getChannelData(c);
    for (let i = s; i < e; i++) d[i] *= gain;
  }
  return out;
}

/** Ein- oder Ausblenden. shape: 'linear' | 'exp' */
export function applyFade(ctx, buffer, startFrame, endFrame, direction = 'in', shape = 'exp') {
  const out = copyBuffer(ctx, buffer);
  const s = clamp(Math.round(startFrame), 0, out.length);
  const e = clamp(Math.round(endFrame), s + 1, out.length);
  const len = e - s;
  for (let c = 0; c < out.numberOfChannels; c++) {
    const d = out.getChannelData(c);
    for (let i = 0; i < len; i++) {
      const t = i / len;
      const lin = direction === 'in' ? t : 1 - t;
      const g = shape === 'linear' ? lin : lin * lin;
      d[s + i] *= g;
    }
  }
  return out;
}

export function reverseBuffer(ctx, buffer, startFrame = 0, endFrame = null) {
  const out = copyBuffer(ctx, buffer);
  const s = clamp(Math.round(startFrame), 0, out.length);
  const e = endFrame == null ? out.length : clamp(Math.round(endFrame), s, out.length);
  for (let c = 0; c < out.numberOfChannels; c++) {
    const d = out.getChannelData(c);
    const seg = d.subarray(s, e);
    seg.reverse();
  }
  return out;
}

/** Groesster Absolutwert im Buffer. */
export function peakOf(buffer, startFrame = 0, endFrame = null) {
  let peak = 0;
  const s = clamp(Math.round(startFrame), 0, buffer.length);
  const e = endFrame == null ? buffer.length : clamp(Math.round(endFrame), s, buffer.length);
  for (let c = 0; c < buffer.numberOfChannels; c++) {
    const d = buffer.getChannelData(c);
    for (let i = s; i < e; i++) {
      const v = Math.abs(d[i]);
      if (v > peak) peak = v;
    }
  }
  return peak;
}

/** Effektivwert (RMS) in dB. */
export function rmsDb(buffer) {
  let sum = 0;
  let n = 0;
  for (let c = 0; c < buffer.numberOfChannels; c++) {
    const d = buffer.getChannelData(c);
    for (let i = 0; i < d.length; i += 4) {
      sum += d[i] * d[i];
      n++;
    }
  }
  return n ? gainToDb(Math.sqrt(sum / n)) : -96;
}

/** Normalisiert auf einen Zielpegel in dBFS (Standard: -0.3 dB). */
export function normalizeBuffer(ctx, buffer, targetDb = -0.3) {
  const peak = peakOf(buffer);
  if (peak <= 0.000001) return copyBuffer(ctx, buffer);
  const factor = dbToGain(targetDb) / peak;
  return applyGain(ctx, buffer, factor);
}

/** Entfernt Stille am Anfang und Ende (Schwelle in dBFS). */
export function trimSilence(ctx, buffer, thresholdDb = -48) {
  const th = dbToGain(thresholdDb);
  const len = buffer.length;
  let start = len;
  let end = 0;
  for (let c = 0; c < buffer.numberOfChannels; c++) {
    const d = buffer.getChannelData(c);
    for (let i = 0; i < len; i++) {
      if (Math.abs(d[i]) > th) {
        if (i < start) start = i;
        break;
      }
    }
    for (let i = len - 1; i >= 0; i--) {
      if (Math.abs(d[i]) > th) {
        if (i > end) end = i;
        break;
      }
    }
  }
  if (start >= end) return copyBuffer(ctx, buffer);
  const pad = Math.round(buffer.sampleRate * 0.002);
  return sliceBuffer(ctx, buffer, Math.max(0, start - pad), Math.min(len, end + pad));
}

/** Wandelt in Mono (Mittelwert aller Kanaele). */
export function toMono(ctx, buffer) {
  if (buffer.numberOfChannels === 1) return copyBuffer(ctx, buffer);
  const out = createBuffer(ctx, 1, buffer.length, buffer.sampleRate);
  const d = out.getChannelData(0);
  for (let c = 0; c < buffer.numberOfChannels; c++) {
    const src = buffer.getChannelData(c);
    for (let i = 0; i < buffer.length; i++) d[i] += src[i];
  }
  const inv = 1 / buffer.numberOfChannels;
  for (let i = 0; i < d.length; i++) d[i] *= inv;
  return out;
}

/** Erzeugt einen Buffer aus Float-Kanaelen. */
export function bufferFromChannels(ctx, channels, sampleRate) {
  const len = channels[0].length;
  const out = createBuffer(ctx, channels.length, len, sampleRate);
  for (let c = 0; c < channels.length; c++) out.getChannelData(c).set(channels[c]);
  return out;
}

/**
 * Berechnet Min/Max-Paare fuer die Waveform-Darstellung.
 * @returns {{min: Float32Array, max: Float32Array, buckets: number}}
 */
export function computePeaks(buffer, buckets) {
  const n = Math.max(1, Math.floor(buckets));
  const min = new Float32Array(n);
  const max = new Float32Array(n);
  const len = buffer.length;
  const per = len / n;
  const chCount = buffer.numberOfChannels;
  const chans = [];
  for (let c = 0; c < chCount; c++) chans.push(buffer.getChannelData(c));

  for (let b = 0; b < n; b++) {
    const s = Math.floor(b * per);
    const e = Math.min(len, Math.max(s + 1, Math.floor((b + 1) * per)));
    let lo = 1;
    let hi = -1;
    for (let c = 0; c < chCount; c++) {
      const d = chans[c];
      for (let i = s; i < e; i++) {
        const v = d[i];
        if (v < lo) lo = v;
        if (v > hi) hi = v;
      }
    }
    if (lo > hi) {
      lo = 0;
      hi = 0;
    }
    min[b] = lo;
    max[b] = hi;
  }
  return { min, max, buckets: n };
}

/**
 * Mischt einen Buffer in einen Zielpuffer an einer Frameposition.
 * Wird beim Offline-Rendering nicht gebraucht, aber beim Zusammenfuegen
 * von Aufnahmen.
 */
export function mixInto(target, source, atFrame, gain = 1) {
  const chs = Math.min(target.numberOfChannels, source.numberOfChannels);
  for (let c = 0; c < chs; c++) {
    const dst = target.getChannelData(c);
    const src = source.getChannelData(c);
    const n = Math.min(src.length, dst.length - atFrame);
    for (let i = 0; i < n; i++) dst[atFrame + i] += src[i] * gain;
  }
  return target;
}
