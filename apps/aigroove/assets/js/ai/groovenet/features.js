/**
 * GrooveNet – Merkmalsextraktion ("Ohren" der KI).
 *
 * Die KI vergleicht Klaenge nicht Sample fuer Sample – das waere sinnlos, weil
 * zwei gleich klingende Kicks voellig unterschiedliche Wellenformen haben.
 * Stattdessen wird jeder Klang in einen kompakten Fingerabdruck uebersetzt:
 *
 *   surface      Mel-Spektrogramm auf festem Raster (Frequenz x Zeit).
 *                Bildet ab, wie das menschliche Gehoer Frequenzen staffelt.
 *   envelope     Lautstaerkeverlauf ueber die Zeit.
 *   descriptors  Kennzahlen wie Helligkeit, Rauschanteil, Anschlag, Ausklang.
 *
 * Genau diese Groessen vergleicht der Optimierer mit dem, was aus dem Prompt
 * abgeleitet wurde. Der Fingerabdruck ist damit die Verlustfunktion der KI.
 */

import { FFT, hannWindow, nextPow2 } from './fft.js';

/** Anzahl Frequenzbaender und Zeitschritte des Fingerabdrucks. */
export const BANDS = 28;
export const FRAMES = 20;

const hzToMel = (hz) => 2595 * Math.log10(1 + hz / 700);
const melToHz = (mel) => 700 * (10 ** (mel / 2595) - 1);

const bankCache = new Map();

/**
 * Mittenfrequenzen der Mel-Baender in Hertz.
 * Das Zielspektrum muss dieselbe Staffelung benutzen wie die Analyse,
 * sonst wuerden Ziel und Kandidat aneinander vorbeigemessen.
 */
export function melBandCenters(sampleRate, bands = BANDS, fMin = 30, fMax = null) {
  const top = fMax || Math.min(sampleRate / 2, 18000);
  const melLo = hzToMel(fMin);
  const melHi = hzToMel(top);
  const out = new Float64Array(bands);
  for (let b = 0; b < bands; b++) out[b] = melToHz(melLo + ((melHi - melLo) * (b + 1)) / (bands + 1));
  return out;
}

/**
 * Dreiecksfilterbank auf der Mel-Skala.
 * Wird pro (fftSize, sampleRate, bands) einmal berechnet und wiederverwendet.
 */
export function melFilterBank(fftSize, sampleRate, bands = BANDS, fMin = 30, fMax = null) {
  const top = fMax || Math.min(sampleRate / 2, 18000);
  const key = `${fftSize}|${sampleRate}|${bands}|${fMin}|${top}`;
  const cached = bankCache.get(key);
  if (cached) return cached;

  const bins = fftSize / 2 + 1;
  const melLo = hzToMel(fMin);
  const melHi = hzToMel(top);
  const points = new Float64Array(bands + 2);
  for (let i = 0; i < bands + 2; i++) {
    points[i] = (melToHz(melLo + ((melHi - melLo) * i) / (bands + 1)) * fftSize) / sampleRate;
  }

  const bank = [];
  for (let b = 0; b < bands; b++) {
    const lo = points[b];
    const mid = points[b + 1];
    const hi = points[b + 2];
    const start = Math.max(0, Math.floor(lo));
    const end = Math.min(bins - 1, Math.ceil(hi));
    const weights = new Float32Array(Math.max(1, end - start + 1));
    let sum = 0;
    for (let i = start; i <= end; i++) {
      let w = 0;
      if (i >= lo && i <= mid) w = mid > lo ? (i - lo) / (mid - lo) : 1;
      else if (i > mid && i <= hi) w = hi > mid ? (hi - i) / (hi - mid) : 1;
      weights[i - start] = w;
      sum += w;
    }
    // Flaechennormierung: breite Baender sind sonst automatisch lauter.
    if (sum > 1e-9) for (let i = 0; i < weights.length; i++) weights[i] /= sum;
    bank.push({ start, weights });
  }

  bankCache.set(key, bank);
  return bank;
}

/** Wandelt Energie in eine gehoerrichtige Dezibel-aehnliche Skala (0..1). */
function logScale(energy) {
  const db = 20 * Math.log10(energy + 1e-7);
  return Math.min(1, Math.max(0, (db + 84) / 84));
}

/**
 * Erzeugt den Fingerabdruck eines Signals.
 *
 * @param {Float32Array} signal Mono
 * @param {number} sampleRate
 * @param {{bands?:number, frames?:number}} [opts]
 * @returns {{surface:Float32Array, envelope:Float32Array, descriptors:object, bands:number, frames:number}}
 */
export function fingerprint(input, sampleRate, opts = {}) {
  const bands = opts.bands || BANDS;
  const frames = opts.frames || FRAMES;

  // Auf gleichen Spitzenpegel bringen: Verglichen wird die Klangfarbe,
  // nicht die Lautstaerke. Sonst waere jeder leise Kandidat automatisch
  // schlechter, egal wie gut er klingt.
  let inPeak = 0;
  for (let i = 0; i < input.length; i++) {
    const v = input[i] < 0 ? -input[i] : input[i];
    if (v > inPeak) inPeak = v;
  }
  let signal = input;
  if (inPeak > 1e-7 && Math.abs(inPeak - 0.9) > 0.02) {
    signal = new Float32Array(input.length);
    const f = 0.9 / inPeak;
    for (let i = 0; i < input.length; i++) signal[i] = input[i] * f;
  }

  // Fensterlaenge an die Signaldauer koppeln: kurze Klaenge brauchen feine
  // Zeitaufloesung, lange Flaechen eine feine Frequenzaufloesung.
  const fftSize = Math.min(2048, Math.max(256, nextPow2(Math.floor(signal.length / frames) * 2)));
  const fft = new FFT(fftSize);
  const win = hannWindow(fftSize);
  const bank = melFilterBank(fftSize, sampleRate, bands);
  const binCount = fftSize / 2 + 1;

  const surface = new Float32Array(bands * frames);
  const envelope = new Float32Array(frames);
  const frameData = new Float64Array(fftSize);
  const spectrum = new Float32Array(binCount);

  // Zeitliche Schwerpunkte der Frames, gleichmaessig ueber das Signal verteilt.
  const step = Math.max(1, signal.length / frames);

  let centroidSum = 0;
  let centroidWeight = 0;
  let flatnessSum = 0;
  let rolloffSum = 0;
  let activeFrames = 0;

  for (let f = 0; f < frames; f++) {
    const center = Math.floor(f * step + step / 2);
    const start = center - fftSize / 2;
    for (let i = 0; i < fftSize; i++) {
      const s = start + i;
      frameData[i] = (s >= 0 && s < signal.length ? signal[s] : 0) * win[i];
    }
    fft.magnitude(frameData, spectrum);

    let energy = 0;
    for (let i = 0; i < binCount; i++) energy += spectrum[i] * spectrum[i];
    envelope[f] = Math.sqrt(energy / binCount);

    for (let b = 0; b < bands; b++) {
      const { start: bs, weights } = bank[b];
      let acc = 0;
      for (let i = 0; i < weights.length; i++) {
        const bin = bs + i;
        if (bin < binCount) acc += spectrum[bin] * weights[i];
      }
      surface[f * bands + b] = logScale(acc);
    }

    // Spektrale Kennzahlen nur aus Frames mit echtem Inhalt.
    if (energy > 1e-8) {
      activeFrames++;
      let num = 0;
      let den = 0;
      let logSum = 0;
      let linSum = 0;
      for (let i = 1; i < binCount; i++) {
        const m = spectrum[i];
        const hz = (i * sampleRate) / fftSize;
        // Energiegewichtung: laute Teiltoene bestimmen die empfundene
        // Helligkeit. Mit reiner Betragsgewichtung wuerden die vielen leisen
        // Hochtonbaender das Ergebnis verfaelschen.
        const e = m * m;
        num += hz * e;
        den += e;
        logSum += Math.log(m + 1e-9);
        linSum += m;
      }
      if (den > 1e-9) {
        centroidSum += (num / den) * den;
        centroidWeight += den;
        // Spektrale Flachheit: 1 = Rauschen, nahe 0 = klarer Ton.
        const geo = Math.exp(logSum / (binCount - 1));
        const arith = linSum / (binCount - 1);
        flatnessSum += arith > 1e-9 ? Math.min(1, geo / arith) : 0;
        // Rolloff: unterhalb dieser Frequenz liegen 85 % der Energie.
        let acc = 0;
        const limit = den * 0.85;
        let rolloffHz = sampleRate / 2;
        for (let i = 1; i < binCount; i++) {
          acc += spectrum[i];
          if (acc >= limit) {
            rolloffHz = (i * sampleRate) / fftSize;
            break;
          }
        }
        rolloffSum += rolloffHz;
      }
    }
  }

  // Die Oberflaeche auf ihr eigenes Maximum beziehen. Verglichen wird damit
  // die Verteilung der Energie, nicht der absolute Pegel – genau das, was
  // "klingt wie" bedeutet.
  let surfaceMax = -Infinity;
  for (let i = 0; i < surface.length; i++) if (surface[i] > surfaceMax) surfaceMax = surface[i];
  if (surfaceMax > -Infinity) for (let i = 0; i < surface.length; i++) surface[i] -= surfaceMax;

  // Huellkurve auf Spitzenwert normieren – die KI vergleicht Form, nicht Pegel.
  let envPeak = 0;
  for (let i = 0; i < frames; i++) if (envelope[i] > envPeak) envPeak = envelope[i];
  if (envPeak > 1e-9) for (let i = 0; i < frames; i++) envelope[i] /= envPeak;

  const descriptors = {
    centroid: centroidWeight > 1e-9 ? centroidSum / centroidWeight : 0,
    flatness: activeFrames ? flatnessSum / activeFrames : 0,
    rolloff: activeFrames ? rolloffSum / activeFrames : 0,
    ...timeDescriptors(signal, sampleRate, envelope),
    harmonicity: harmonicity(signal, sampleRate),
  };

  return { surface, envelope, descriptors, bands, frames };
}

/** Anschlagzeit, Ausklingzeit, Scheitelfaktor und Effektivwert. */
function timeDescriptors(signal, sampleRate, envelope) {
  let peak = 0;
  let sumSq = 0;
  for (let i = 0; i < signal.length; i++) {
    const v = Math.abs(signal[i]);
    if (v > peak) peak = v;
    sumSq += signal[i] * signal[i];
  }
  const rms = Math.sqrt(sumSq / Math.max(1, signal.length));
  const duration = signal.length / sampleRate;

  let peakFrame = 0;
  for (let i = 0; i < envelope.length; i++) if (envelope[i] > envelope[peakFrame]) peakFrame = i;
  const frameDur = duration / envelope.length;

  // Attack: bis zum lautesten Frame. Decay: bis auf 10 % des Spitzenwerts.
  const attack = (peakFrame + 0.5) * frameDur;
  let decayFrame = envelope.length - 1;
  for (let i = peakFrame; i < envelope.length; i++) {
    if (envelope[i] < 0.1) {
      decayFrame = i;
      break;
    }
  }
  const decay = Math.max(frameDur, (decayFrame - peakFrame) * frameDur);

  let crossings = 0;
  for (let i = 1; i < signal.length; i++) {
    if ((signal[i - 1] < 0 && signal[i] >= 0) || (signal[i - 1] >= 0 && signal[i] < 0)) crossings++;
  }

  return {
    rms,
    peak,
    crest: rms > 1e-9 ? peak / rms : 0,
    attack,
    decay,
    zcr: crossings / Math.max(1, duration),
    duration,
  };
}

/**
 * Harmonizitaet ueber normierte Autokorrelation:
 * 1 = klar tonal, 0 = reines Geraeusch. Wird gebraucht, um zwischen Bass/Lead
 * (tonal) und Hats/Noise (geraeuschhaft) zu unterscheiden.
 */
export function harmonicity(signal, sampleRate) {
  const maxLag = Math.min(Math.floor(sampleRate / 40), 2048);
  const minLag = Math.max(2, Math.floor(sampleRate / 2000));
  const n = Math.min(signal.length, maxLag * 4);
  if (n < minLag * 4) return 0;

  let energy = 0;
  for (let i = 0; i < n; i++) energy += signal[i] * signal[i];
  if (energy < 1e-9) return 0;

  let best = 0;
  // Grobe Rasterung: die genaue Tonhoehe ist hier nicht wichtig, nur die Staerke.
  const stride = maxLag > 512 ? 2 : 1;
  for (let lag = minLag; lag < Math.min(maxLag, n - 1); lag += stride) {
    let corr = 0;
    let norm = 0;
    for (let i = 0; i + lag < n; i += 2) {
      corr += signal[i] * signal[i + lag];
      norm += signal[i + lag] * signal[i + lag];
    }
    const r = norm > 1e-9 ? corr / Math.sqrt(energy * norm) : 0;
    if (r > best) best = r;
  }
  return Math.min(1, Math.max(0, best));
}

/**
 * Abstand zweier Fingerabdruecke. Kleiner ist besser – das ist die
 * Zielfunktion, die der Optimierer minimiert.
 *
 * @param {object} a Kandidat
 * @param {object} b Ziel
 * @param {object} [weights]
 */
export function distance(a, b, weights = {}) {
  const wSurface = weights.surface ?? 1;
  const wEnvelope = weights.envelope ?? 0.9;
  const wDesc = weights.descriptors ?? 0.6;

  // Zeitliche Gewichtung: Bei perkussiven Klaengen entscheidet der Anschlag
  // darueber, ob etwas wie eine Trommel klingt. Ohne diese Gewichtung faellt
  // der erste Zeitschritt gegen neunzehn Ausklang-Schritte nicht ins Gewicht,
  // und die Suche opfert den Anschlag fuer einen minimal besseren Ausklang.
  const attackBias = weights.attackBias ?? 0;
  let surf = 0;
  let surfWeight = 0;
  const n = Math.min(a.surface.length, b.surface.length);
  const bands = a.bands || 1;
  for (let i = 0; i < n; i++) {
    const frame = Math.floor(i / bands);
    const w = 1 + attackBias * Math.exp(-frame / 2.2);
    const d = a.surface[i] - b.surface[i];
    surf += d * d * w;
    surfWeight += w;
  }
  surf = Math.sqrt(surf / Math.max(1, surfWeight));

  let env = 0;
  const m = Math.min(a.envelope.length, b.envelope.length);
  for (let i = 0; i < m; i++) {
    const d = a.envelope[i] - b.envelope[i];
    env += d * d;
  }
  env = Math.sqrt(env / Math.max(1, m));

  const da = a.descriptors;
  const db = b.descriptors;
  // Helligkeit logarithmisch vergleichen: 200 zu 400 Hz ist derselbe
  // Hoerunterschied wie 2000 zu 4000 Hz.
  const centroid = Math.abs(Math.log2((da.centroid + 40) / (db.centroid + 40))) / 6;
  const flat = Math.abs(da.flatness - db.flatness);
  const harm = Math.abs(da.harmonicity - db.harmonicity);
  const desc = (centroid + flat * 0.8 + harm * 0.8) / 2.6;

  return surf * wSurface + env * wEnvelope + desc * wDesc;
}
