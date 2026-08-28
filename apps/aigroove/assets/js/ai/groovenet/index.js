/**
 * GrooveNet – die eigene Klang-KI von AI Groove.
 *
 * Laeuft vollstaendig im Browser: kein Konto, kein API-Key, keine Kosten,
 * keine Uebertragung von Daten. Der Prompt verlaesst das Geraet nie.
 *
 * Zwei Faehigkeiten, dieselben wie bei kostenpflichtigen Audio-Diensten:
 *
 *   generate()   Text -> Klang. Einzelklaenge und ganze Schleifen.
 *   transform()  Text + vorhandenes Sample -> veraenderter Klang.
 *
 * Aufbau der Verarbeitungskette:
 *
 *   encoder    Prompt verstehen        -> Absicht und 16 Klangachsen
 *   target     Ziel aufstellen         -> gewuenschtes Spektrogramm
 *   genome     Startwerte ableiten     -> 51 Syntheseparameter
 *   optimizer  Suchen und bewerten     -> bestes Genom (Evolution)
 *   synth      Klang erzeugen          -> Audiosignal
 *   compose    Musikalisch anordnen    -> Schleifen mit Drums, Bass, Akkorden
 *   master     Ausgabe aufbereiten     -> fertiges Sample
 */

import { encode, suggestedSeconds } from './encoder.js';
import { optimize, scoreSignal } from './optimizer.js';
import { renderMono, spatialize, normalize } from './synth.js';
import { decode, seedGenome, rng } from './genome.js';
import { composeLoop } from './compose.js';
import { master } from './master.js';
import { buildTarget } from './target.js';
import { stft, istft, griffinLim } from './fft.js';
import { melFilterBank, fingerprint, BANDS, FRAMES } from './features.js';

export { encode, suggestedSeconds, scoreSignal };

export const ENGINE = {
  id: 'groovenet',
  name: 'GrooveNet',
  version: '1.0',
  description:
    'Eigene Klang-KI: versteht den Prompt, stellt ein Zielspektrum auf und sucht per Evolution den passenden Klang – vollstaendig im Browser.',
};

/** Rechenaufwand je Qualitaetsstufe, in Millisekunden pro Variante. */
export const EFFORT_PRESETS = {
  fast: { budgetMs: 700, effort: 0.35, label: 'Schnell' },
  balanced: { budgetMs: 2200, effort: 0.7, label: 'Ausgewogen' },
  best: { budgetMs: 6000, effort: 1, label: 'Beste Qualität' },
};

/**
 * Erzeugt Klaenge aus einem Text.
 *
 * @param {object} opts
 * @param {string} opts.prompt
 * @param {number} [opts.duration] Sekunden; ohne Angabe schlaegt die KI vor
 * @param {number} [opts.count] Anzahl Varianten
 * @param {number} [opts.sampleRate]
 * @param {number} [opts.seed]
 * @param {'fast'|'balanced'|'best'} [opts.quality]
 * @param {(p:number, label:string) => void} [opts.onProgress]
 * @param {() => boolean} [opts.shouldStop]
 * @returns {Array<{channels:Float32Array[], sampleRate:number, label:string, meta:object}>}
 */
export function generate(opts) {
  const {
    prompt,
    count = 4,
    sampleRate = 44100,
    seed = Math.floor(Math.random() * 1e9),
    quality = 'balanced',
    onProgress = () => {},
    shouldStop = () => false,
  } = opts;

  const preset = EFFORT_PRESETS[quality] || EFFORT_PRESETS.balanced;
  const intent = encode(prompt);
  const seconds = Math.min(190, Math.max(0.05, opts.duration || suggestedSeconds(intent)));
  intent.durationSeconds = seconds;

  onProgress(0.04, 'Prompt wird gedeutet …');

  if (intent.mode === 'loop') return generateLoops({ intent, count, sampleRate, seed, seconds, onProgress, shouldStop });

  // --- Einzelklang -----------------------------------------------------------
  onProgress(0.1, 'Zielklang wird aufgestellt …');
  const search = optimize({
    intent,
    seconds,
    sampleRate,
    count,
    seed,
    budgetMs: preset.budgetMs * Math.min(3, Math.max(1, count / 2)),
    effort: preset.effort,
    onProgress: (p, label) => onProgress(0.1 + p * 0.7, label),
    shouldStop,
  });

  const results = [];
  for (let i = 0; i < search.genomes.length; i++) {
    onProgress(0.8 + (i / search.genomes.length) * 0.18, `Variante ${i + 1} wird ausgegeben …`);
    const gene = search.genomes[i];
    const mono = renderMono(gene, { seconds, sampleRate, seed: seed + i * 17, quality: 1 });
    const params = decode(gene);
    const channels = spatialize(mono, params, sampleRate);
    normalize(channels, 0.9);
    master(channels, sampleRate, { fadeOut: Math.min(0.02, seconds * 0.05) });

    const score = scoreSignal(mono, sampleRate, intent, seconds);
    results.push({
      channels,
      sampleRate,
      label: `Variante ${i + 1}`,
      meta: {
        engine: ENGINE.id,
        archetype: intent.archetype,
        mode: 'oneshot',
        seconds,
        match: score.match,
        genome: Array.from(gene),
        generations: search.generations,
        evaluations: search.evaluations,
      },
    });
  }

  onProgress(1, 'Fertig');
  return results;
}

/** Erzeugt mehrere Schleifen-Varianten. */
function generateLoops({ intent, count, sampleRate, seed, seconds, onProgress, shouldStop }) {
  const results = [];
  const bars = intent.bars || Math.max(1, Math.round((seconds * (intent.bpm || 120)) / 240)) || 2;

  for (let i = 0; i < count; i++) {
    if (shouldStop()) break;
    const loop = composeLoop({
      intent,
      sampleRate,
      seed: seed + i * 5471,
      bars,
      bpm: intent.bpm,
      onProgress: (p, label) => onProgress(0.05 + ((i + p) / count) * 0.9, label),
    });
    master(loop.channels, sampleRate, { fadeOut: 0.004 });
    results.push({
      channels: loop.channels,
      sampleRate,
      label: `Variante ${i + 1}`,
      meta: {
        engine: ENGINE.id,
        archetype: intent.archetype,
        mode: 'loop',
        seconds: loop.seconds,
        bpm: loop.bpm,
        bars: loop.bars,
        parts: loop.parts,
      },
    });
  }

  onProgress(1, 'Fertig');
  return results;
}

// --- Audio zu Audio ----------------------------------------------------------

/** Erkennt Bearbeitungswuensche, die keine Klangfarbe sind. */
function editFlags(text) {
  const t = String(text || '').toLowerCase();
  return {
    reverse: /(reverse|rueckwaerts|rückwärts|backwards|umgekehrt)/.test(t),
    halfSpeed: /(half speed|halbe geschwindigkeit|langsamer|slower)/.test(t),
    doubleSpeed: /(double speed|doppelt so schnell|schneller|faster)/.test(t),
    octaveUp: /(oktave hoch|octave up|higher pitch|hoeher|höher)/.test(t),
    octaveDown: /(oktave runter|octave down|lower pitch|tiefer stimmen|pitch down)/.test(t),
  };
}

/** Aendert die Abspielgeschwindigkeit (und damit die Tonhoehe) linear interpoliert. */
function resample(channel, factor) {
  const n = Math.max(8, Math.floor(channel.length / factor));
  const out = new Float32Array(n);
  for (let i = 0; i < n; i++) {
    const pos = i * factor;
    const i0 = Math.floor(pos);
    const frac = pos - i0;
    const a = channel[Math.min(channel.length - 1, i0)];
    const b = channel[Math.min(channel.length - 1, i0 + 1)];
    out[i] = a + (b - a) * frac;
  }
  return out;
}

/**
 * Formt ein vorhandenes Sample nach einem Text um.
 *
 * Kern ist ein spektrales Morphing: Das Sample wird in ein Spektrogramm
 * zerlegt, mit dem aus dem Prompt abgeleiteten Zielspektrum verglichen und
 * Band fuer Band in dessen Richtung verschoben. Das ist praktisch ein
 * zeitvariabler Vielband-Entzerrer, dessen Einstellungen die KI berechnet.
 *
 * Danach folgt der Charakter-Teil: Saettigung, Filter und Raum aus dem
 * abgeleiteten Genom, anteilig nach Staerke zugemischt.
 *
 * @param {object} opts
 * @param {Float32Array[]} opts.channels Eingangssignal
 * @param {number} opts.sampleRate
 * @param {string} opts.prompt
 * @param {number} [opts.strength] 0..1
 * @param {number} [opts.count]
 * @param {number} [opts.seed]
 * @param {(p:number, label:string) => void} [opts.onProgress]
 * @returns {Array<{channels:Float32Array[], sampleRate:number, label:string, meta:object}>}
 */
export function transform(opts) {
  const {
    channels: input,
    sampleRate,
    prompt,
    strength = 0.65,
    count = 4,
    seed = Math.floor(Math.random() * 1e9),
    onProgress = () => {},
  } = opts;

  const intent = encode(prompt);
  const seconds = input[0].length / sampleRate;
  intent.durationSeconds = seconds;
  const flags = editFlags(prompt);
  const target = buildTarget(intent, seconds, sampleRate);

  const fftSize = 1024;
  const hop = fftSize / 4;
  const bank = melFilterBank(fftSize, sampleRate, BANDS);
  const bins = fftSize / 2 + 1;

  // Zuordnung Frequenzband -> Spektrallinie. Wird einmal gebaut und fuer alle
  // Kanaele und Varianten wiederverwendet.
  const binBandWeights = new Float32Array(bins * BANDS);
  const binWeightSum = new Float32Array(bins);
  for (let b = 0; b < BANDS; b++) {
    const { start, weights } = bank[b];
    for (let i = 0; i < weights.length; i++) {
      const bin = start + i;
      if (bin >= bins) break;
      binBandWeights[bin * BANDS + b] = weights[i];
      binWeightSum[bin] += weights[i];
    }
  }

  const results = [];
  for (let v = 0; v < count; v++) {
    onProgress(v / count, `Variante ${v + 1} von ${count} …`);
    const random = rng(seed + v * 3571);
    // Jede Variante bekommt eine leicht andere Auslegung desselben Prompts.
    const gene = seedGenome(intent, random, v === 0 ? 0 : 0.06 + v * 0.03);
    const params = decode(gene);

    const outChannels = [];
    for (let c = 0; c < input.length; c++) {
      let signal = new Float32Array(input[c]);
      if (flags.reverse) signal.reverse();
      if (flags.halfSpeed) signal = resample(signal, 0.5);
      if (flags.doubleSpeed) signal = resample(signal, 2);
      if (flags.octaveUp) signal = resample(signal, 2);
      if (flags.octaveDown) signal = resample(signal, 0.5);

      signal = spectralMorph(signal, {
        sampleRate,
        target,
        strength,
        fftSize,
        hop,
        bins,
        binBandWeights,
        binWeightSum,
      });
      signal = applyCharacter(signal, params, sampleRate, strength);
      outChannels.push(signal);
    }

    // Mono bleibt mono, Stereo bleibt stereo; Raum kommt aus dem Genom.
    const mono = outChannels.length === 1 ? outChannels[0] : mixToMono(outChannels);
    const spaced = params.reverbAmount > 0.02 || params.width > 0.05 ? spatialize(mono, params, sampleRate) : outChannels.length === 1 ? [outChannels[0], new Float32Array(outChannels[0])] : outChannels;

    normalize(spaced, 0.9);
    master(spaced, sampleRate, { fadeOut: 0.006 });

    const score = scoreSignal(spaced[0], sampleRate, intent, spaced[0].length / sampleRate);
    results.push({
      channels: spaced,
      sampleRate,
      label: `Variante ${v + 1}`,
      meta: {
        engine: ENGINE.id,
        mode: 'transform',
        archetype: intent.archetype,
        strength,
        match: score.match,
        edits: Object.entries(flags).filter(([, on]) => on).map(([k]) => k),
      },
    });
  }

  onProgress(1, 'Fertig');
  return results;
}

function mixToMono(channels) {
  const out = new Float32Array(channels[0].length);
  for (const ch of channels) for (let i = 0; i < out.length; i++) out[i] += ch[i] / channels.length;
  return out;
}

/**
 * Verschiebt das Spektrum eines Signals in Richtung des Zielspektrums.
 * Phase bleibt erhalten – dadurch bleibt der Charakter des Originals
 * hoerbar, statt durch eine Neusynthese ersetzt zu werden.
 */
function spectralMorph(signal, ctx) {
  const { target, strength, fftSize, hop, bins, binBandWeights, binWeightSum } = ctx;
  if (strength < 0.02) return signal;

  const analysis = stft(signal, { fftSize, hop, keepPhase: true });
  const frames = analysis.mag.length;
  const maxBoostDb = 4 + strength * 14;

  // Ist-Zustand je Frame und Band bestimmen.
  for (let f = 0; f < frames; f++) {
    const mag = analysis.mag[f];

    let inputMax = -Infinity;
    const inputBands = new Float32Array(BANDS);
    // Bandpegel aus den Spektrallinien zusammenfassen.
    for (let bin = 0; bin < bins; bin++) {
      const m = mag[bin];
      if (m <= 0) continue;
      const base = bin * BANDS;
      for (let b = 0; b < BANDS; b++) {
        const w = binBandWeights[base + b];
        if (w > 0) inputBands[b] += m * w;
      }
    }
    for (let b = 0; b < BANDS; b++) {
      inputBands[b] = 20 * Math.log10(inputBands[b] + 1e-7);
      if (inputBands[b] > inputMax) inputMax = inputBands[b];
    }

    // Zielpegel fuer diesen Zeitpunkt: die 20 Zielframes werden auf die
    // tatsaechliche Frameanzahl gedehnt und linear zwischenwerte gebildet.
    const tp = (f / Math.max(1, frames - 1)) * (FRAMES - 1);
    const t0 = Math.floor(tp);
    const t1 = Math.min(FRAMES - 1, t0 + 1);
    const frac = tp - t0;

    const gainDb = new Float32Array(BANDS);
    for (let b = 0; b < BANDS; b++) {
      // Zielwerte liegen in der 0..1-Skala, ein Schritt entspricht 84 dB.
      const tv = target.surface[t0 * BANDS + b] * (1 - frac) + target.surface[t1 * BANDS + b] * frac;
      const targetDb = tv * 84;
      const inputDb = inputBands[b] - inputMax;
      let diff = (targetDb - inputDb) * strength;
      if (diff > maxBoostDb) diff = maxBoostDb;
      if (diff < -maxBoostDb * 1.5) diff = -maxBoostDb * 1.5;
      gainDb[b] = diff;
    }

    // Bandverstaerkungen auf die Spektrallinien zurueckrechnen.
    for (let bin = 0; bin < bins; bin++) {
      const sum = binWeightSum[bin];
      if (sum <= 0) continue;
      const base = bin * BANDS;
      let db = 0;
      for (let b = 0; b < BANDS; b++) {
        const w = binBandWeights[base + b];
        if (w > 0) db += gainDb[b] * w;
      }
      mag[bin] *= 10 ** (db / sum / 20);
    }
  }

  let out = istft(analysis.mag, analysis.phase, { fftSize, hop, length: signal.length });

  // Bei starker Veraenderung passt die alte Phase nicht mehr gut zum neuen
  // Betragsspektrum. Wenige Griffin-Lim-Schritte raeumen das auf.
  if (strength > 0.65) {
    out = griffinLim(analysis.mag, {
      fftSize,
      hop,
      length: signal.length,
      iterations: 6,
      phase: analysis.phase,
    });
  }
  return out;
}

/**
 * Traegt Charakter aus dem Genom nach: Saettigung, Neigung und
 * Anschlagformung – anteilig nach Staerke.
 */
function applyCharacter(signal, params, sampleRate, strength) {
  const out = new Float32Array(signal.length);
  const mix = Math.min(1, strength);

  // Saettigung
  const driveAmount = params.drive * mix;
  const gain = 1 + driveAmount * 8;
  const norm = Math.tanh(gain);

  // Neigungsentzerrer
  const tiltAmount = params.tilt * mix;
  const c = 1 - Math.exp((-2 * Math.PI * 650) / sampleRate);
  let lp = 0;

  // Anschlagformung
  const transientAmount = params.transient * mix;
  const fast = 1 - Math.exp((-2 * Math.PI * 400) / sampleRate);
  const slow = 1 - Math.exp((-2 * Math.PI * 12) / sampleRate);
  let envFast = 0;
  let envSlow = 0;

  for (let i = 0; i < signal.length; i++) {
    let x = signal[i];

    if (driveAmount > 0.01) x = (Math.tanh(x * gain) / norm) * mix + x * (1 - mix);

    if (Math.abs(tiltAmount) > 0.02) {
      lp += c * (x - lp);
      const high = x - lp;
      x = lp * (1 - tiltAmount * 0.7) + high * (1 + tiltAmount * 0.9);
    }

    if (Math.abs(transientAmount) > 0.02) {
      const level = Math.abs(x);
      envFast += fast * (level - envFast);
      envSlow += slow * (level - envSlow);
      const g = 1 + transientAmount * (envFast - envSlow) * 6;
      x *= Math.min(4, Math.max(0.05, g));
    }

    out[i] = x;
  }
  return out;
}

export { fingerprint, buildTarget };
