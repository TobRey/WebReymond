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

  // Rauschboden.
  //
  // Wichtig: Der Boden faellt mit der Frequenz. Ein waagerechter Boden waere
  // die Aufforderung, ueberall bis 18 kHz Energie zu haben – die Suche fuellt
  // das dann pflichtgemaess mit Rauschen auf, und aus einer Kick wird ein
  // Zischen. Bei einer tonalen Kick liegt der Boden dadurch rund 65 dB unter
  // der Spitze, bei einem Clap nur rund 35 dB.
  //
  // Metallische Klaenge zaehlen zum "vollen" Spektrum: Becken und Glocken
  // bestehen aus vielen unharmonischen Teiltoenen ueber den ganzen
  // Hoerbereich.
  const floorAt1k = -80 + d.noisiness * 46 + d.complexity * 6 + Math.max(0, d.metallic - 0.5) * 26;
  const floorSlope = 5 + (1 - d.noisiness) * 9;
  const sharpen = 0.75 + d.harmonicity * 0.5 - d.noisiness * 0.35;

  // --- Zeitverlauf -----------------------------------------------------------
  const rising = intent.archetype === 'riser' || intent.archetype === 'sweep';
  const attack = Math.min(seconds * 0.5, 0.0008 * 2 ** ((1 - d.transient) * 11));
  // Abklingzeit. Sie haengt nicht nur an der gewuenschten Laenge, sondern
  // ausdruecklich auch an der Knackigkeit: In eine Datei von 0,7 Sekunden
  // passt ein Kick von 150 Millisekunden. Ohne diesen Faktor verlangt das Ziel
  // eine Abklingzeit von gut 200 ms, und der Klang bleibt die erste zehntel
  // Sekunde durchgehend gleich laut – ein Knall statt eines Schlags.
  const decay = Math.max(0.01, seconds * (0.05 + d.sustain * 0.8) * (1 - d.transient * 0.55));
  // Haltepegel: Ein geschlagener Klang klingt bis zur Stille aus, eine Flaeche
  // steht. Ohne diese Kopplung an die Knackigkeit gibt das Ziel selbst fuer
  // eine Kick ein Plateau bei rund -13 dB vor – und die Suche liefert
  // folgerichtig einen Klang, der 160 ms lang gleich laut bleibt.
  const sustainLevel = Math.min(0.95, d.sustain * 0.9 * (1 - d.transient * 0.95));

  // Helligkeit des Anschlags: bis zu 34 dB Anhebung in den ersten
  // Millisekunden, abhaengig von der geforderten Knackigkeit.
  // Das Ziel hat FRAMES Zeitschritte. Ist das Anschlagfenster kuerzer als ein
  // Schritt, faellt es zwischen das Raster und bleibt wirkungslos. Es umfasst
  // deshalb mindestens den ersten Schritt.
  const clickAmount = rising ? 0 : Math.max(0, d.transient - 0.35) * 44;
  const clickTime = Math.max(0.006, (seconds / FRAMES) * 1.05);

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
      let floorDb = floorAt1k - Math.max(0, Math.log2(centers[b] / 1000)) * floorSlope;

      // Der Anschlag ist hell.
      //
      // Ohne diesen Zusatz beschreibt das Ziel einen Klang, der von der ersten
      // bis zur letzten Millisekunde gleich dunkel ist. Die Suche filtert dann
      // folgerichtig alle Hoehen weg – und mit ihnen den Anschlag, der eine
      // Trommel ueberhaupt erst als Trommel erkennbar macht. Gemessen: die
      // Filtergrenze lief auf den kleinsten erlaubten Wert.
      //
      // Deshalb wird der Boden in den ersten Millisekunden angehoben, und zwar
      // umso staerker, je knackiger der Prompt klingt.
      if (clickAmount > 0 && t < clickTime) {
        const anteil = 1 - t / clickTime;
        floorDb += clickAmount * anteil * Math.min(1, Math.log2(1 + centers[b] / 400));
      }
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
    // Je knackiger der Klang, desto staerker zaehlen die ersten Zeitschritte.
    attackBias: Math.max(0, d.transient - 0.4) * 6,
  };
}
