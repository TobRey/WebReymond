/**
 * GrooveNet – Zielvorstellung.
 *
 * Der Optimierer braucht etwas, worauf er hinarbeiten kann. Diese Datei baut
 * aus der Prompt-Deutung einen kuenstlichen Fingerabdruck: So *soll* der
 * gewuenschte Klang aussehen, bevor ein einziger Ton erzeugt wurde.
 *
 * Das Ziel besteht aus denselben drei Teilen wie die Analyse in features.js:
 *   surface      Wo liegt die Energie ueber Frequenz und Zeit?
 *   envelope     Wie verlaeuft die Lautstaerke?
 *   descriptors  Helligkeit, Rauschanteil, Tonhaftigkeit
 *
 * Damit ist die Aufgabe der KI klar gestellt: den Abstand zu diesem Ziel
 * so klein wie moeglich machen.
 */

import { BANDS, FRAMES, melBandCenters } from './features.js';

/**
 * Baut den Ziel-Fingerabdruck.
 *
 * @param {object} intent Ergebnis von encoder.encode()
 * @param {number} seconds
 * @param {number} sampleRate Abtastrate, mit der bewertet wird
 * @returns {{surface:Float32Array, envelope:Float32Array, descriptors:object, bands:number, frames:number}}
 */
export function buildTarget(intent, seconds, sampleRate) {
  const d = intent.dimsByName;
  const centers = melBandCenters(sampleRate, BANDS);
  const surface = new Float32Array(BANDS * FRAMES);
  const envelope = new Float32Array(FRAMES);

  // --- Schwerpunkt des Spektrums --------------------------------------------
  // Von rund 60 Hz (ganz dunkel) bis rund 7 kHz (sehr hell).
  const pivot = 60 * 2 ** (d.brightness * 6.9);

  // Flanken links und rechts vom Schwerpunkt, in Dezibel pro Oktave.
  // Schwere Klaenge behalten ihre Tiefen, helle ihre Hoehen.
  const slopeLow = 5 + (1 - d.weight) * 11 + d.brightness * 4;
  const slopeHigh = 5 + (1 - d.brightness) * 15 + (1 - d.complexity) * 4;

  // Rauschen fuellt das Spektrum auf, Tonhaftigkeit spitzt es zu.
  //
  // Metallische Klaenge zaehlen hier zum "vollen" Spektrum: Becken und
  // Glocken bestehen aus vielen unharmonischen Teiltoenen, die sich ueber
  // den ganzen Hoerbereich verteilen. In der Mel-Darstellung sieht das einem
  // breiten Band aehnlicher als einem einzelnen Ton.
  const floorDb = -46 + d.noisiness * 30 + d.complexity * 6 + Math.max(0, d.metallic - 0.5) * 26;
  const sharpen = 0.75 + d.harmonicity * 0.5 - d.noisiness * 0.35;

  // --- Zeitverlauf -----------------------------------------------------------
  const rising = intent.archetype === 'riser' || intent.archetype === 'sweep';
  const attack = Math.min(seconds * 0.5, 0.0008 * 2 ** ((1 - d.transient) * 11));
  const decay = Math.max(0.01, seconds * (0.08 + d.sustain * 0.85));
  const sustainLevel = Math.min(0.95, d.sustain * 0.9);

  for (let f = 0; f < FRAMES; f++) {
    const t = ((f + 0.5) / FRAMES) * seconds;
    let env;
    if (rising) {
      // Riser und Sweeps werden zum Ende hin lauter.
      env = ((t / seconds) ** 1.7) * 0.95 + 0.05;
    } else if (t < attack) {
      env = t / attack;
    } else {
      env = sustainLevel + (1 - sustainLevel) * Math.exp(-(t - attack) / decay);
    }
    envelope[f] = Math.min(1, Math.max(0, env));

    for (let b = 0; b < BANDS; b++) {
      const octaves = Math.log2(centers[b] / pivot);
      // Grundform: Spitze am Schwerpunkt, danach abfallende Flanken.
      let db = octaves < 0 ? octaves * slopeLow * sharpen : -octaves * slopeHigh * sharpen;
      // Rauschboden anheben: bei geraeuschhaften Klaengen traegt das ganze
      // Spektrum, nicht nur die Umgebung des Schwerpunkts.
      db = Math.max(db, floorDb);

      // Hohe Anteile klingen frueher aus als tiefe – so verhalten sich fast
      // alle natuerlichen und elektronischen Klaenge.
      const bandDecayFactor = 1 / (1 + (centers[b] / 900) ** 0.45 * 0.55);
      const bandEnv = rising
        ? envelope[f]
        : t < attack
          ? envelope[f]
          : sustainLevel + (1 - sustainLevel) * Math.exp(-(t - attack) / (decay * bandDecayFactor));

      const envDb = 20 * Math.log10(Math.max(1e-4, bandEnv));
      // Gleiche Skala wie in features.js: -84 dB … 0 dB auf 0 … 1.
      surface[f * BANDS + b] = Math.min(1, Math.max(0, (db + envDb + 84) / 84));
    }
  }

  // Auf das eigene Maximum beziehen, genau wie bei der Analyse.
  let max = -Infinity;
  for (let i = 0; i < surface.length; i++) if (surface[i] > max) max = surface[i];
  for (let i = 0; i < surface.length; i++) surface[i] -= max;

  let envPeak = 0;
  for (let i = 0; i < FRAMES; i++) if (envelope[i] > envPeak) envPeak = envelope[i];
  if (envPeak > 1e-9) for (let i = 0; i < FRAMES; i++) envelope[i] /= envPeak;

  return {
    surface,
    envelope,
    bands: BANDS,
    frames: FRAMES,
    descriptors: {
      centroid: pivot,
      flatness: 0.015 + d.noisiness * 0.6,
      harmonicity: 0.05 + d.harmonicity * 0.8,
      attack,
      decay,
      duration: seconds,
    },
  };
}

/**
 * Gewichtung der Zielanteile.
 * Bei perkussiven Klaengen zaehlt der Zeitverlauf mehr, bei Flaechen das
 * Spektrum – dort ist die Huellkurve ohnehin fast konstant.
 */
export function targetWeights(intent) {
  const d = intent.dimsByName;
  return {
    surface: 1,
    envelope: 0.55 + d.transient * 0.75,
    descriptors: 0.5 + d.harmonicity * 0.35,
  };
}
