/**
 * GrooveNet – Komposition.
 *
 * Erzeugt vollstaendige Schleifen statt einzelner Klaenge: Drums, Bass,
 * Akkorde und Melodie, passend zu Stil, Tempo und Tonart aus dem Prompt.
 *
 * Musikalisches Wissen steckt in drei Tabellen:
 *   GROOVES       Rhythmusvorlagen je Stil, als 16-Schritt-Muster
 *   PROGRESSIONS  uebliche Akkordfolgen je Tonleiter
 *   VOICINGS      wie ein Akkord gegriffen wird
 *
 * Jede Spur wird als eigener Klang erzeugt (synth.js) und an die berechneten
 * Zeitpunkte gemischt. Kleine Abweichungen in Zeit und Anschlagstaerke sorgen
 * dafuer, dass es nicht wie ein Metronom klingt.
 */

import { seedGenome, rng } from './genome.js';
import { renderMono, spatialize, normalize } from './synth.js';
import { decode } from './genome.js';
import { ARCHETYPES } from './lexicon.js';
import { SCALES } from './encoder.js';

/**
 * Rhythmusvorlagen. Jede Zeile ist ein Takt in 16 Schritten.
 * Zeichen: X = harter Schlag, x = normal, . = still, o = leise/Geisternote.
 */
export const GROOVES = {
  four: {
    kick: 'X...X...X...X...',
    clap: '....X.......X...',
    hat: 'x.x.x.x.x.x.x.x.',
    openhat: '..x...x...x...x.',
    perc: '.....o....o..o..',
  },
  house: {
    kick: 'X...X...X...X...',
    clap: '....X.......X...',
    hat: '..x...x...x...x.',
    openhat: '......x.......x.',
    perc: '..o..o..o..o..o.',
  },
  breaks: {
    kick: 'X.....X...X.....',
    clap: '....X.......X...',
    hat: 'x.xxx.xxx.xxx.xx',
    openhat: '.......x........',
    perc: '..o...o..o....o.',
  },
  halftime: {
    kick: 'X.......X.......',
    clap: '........X.......',
    hat: 'x...x...x...x...',
    openhat: '............x...',
    perc: '......o.......o.',
  },
  trap: {
    kick: 'X.....X...X.....',
    clap: '........X.......',
    hat: 'xxxxxxxxxxxxxxxx',
    openhat: '..........x.....',
    perc: '....o.......o...',
  },
  boombap: {
    kick: 'X.......X..X....',
    clap: '....X.......X...',
    hat: 'x.x.x.x.x.x.x.x.',
    openhat: '..............x.',
    perc: '......o.....o...',
  },
  garage: {
    kick: 'X.......X.......',
    clap: '....X.......X...',
    hat: 'x.xx..x.x.xx..x.',
    openhat: '......x.......x.',
    perc: '...o.....o......',
  },
  afro: {
    kick: 'X..X....X..X....',
    clap: '....x.......x...',
    hat: 'x.x.xx.x.x.xx.x.',
    openhat: '.......x........',
    perc: 'o..o.o..o..o.o..',
  },
  funk: {
    kick: 'X..X..X...X.....',
    clap: '....X.......X...',
    hat: 'xxx.xxx.xxx.xxx.',
    openhat: '..............x.',
    perc: '..o...o...o...o.',
  },
  rock: {
    kick: 'X...X...X...X...',
    clap: '....X.......X...',
    hat: 'x.x.x.x.x.x.x.x.',
    openhat: '...............x',
    perc: '................',
  },
  dembow: {
    kick: 'X..X..X...X..X..',
    clap: '....X.......X...',
    hat: 'x.x.x.x.x.x.x.x.',
    openhat: '......x.........',
    perc: '...o...o...o...o',
  },
  sparse: {
    kick: 'X...............',
    clap: '................',
    hat: '....o.......o...',
    openhat: '................',
    perc: '..........o.....',
  },
};

/** Akkordfolgen als Stufen der Tonleiter (0 = Grundton). */
const PROGRESSIONS = {
  minor: [
    [0, 5, 3, 4],
    [0, 3, 4, 0],
    [0, 6, 5, 4],
    [0, 0, 5, 4],
  ],
  major: [
    [0, 4, 5, 3],
    [0, 3, 4, 4],
    [0, 5, 3, 4],
  ],
  dorian: [
    [0, 3, 0, 6],
    [0, 6, 3, 0],
  ],
  phrygian: [
    [0, 1, 0, 6],
    [0, 6, 1, 0],
  ],
  lydian: [
    [0, 4, 1, 4],
    [0, 1, 4, 0],
  ],
  mixolydian: [
    [0, 6, 3, 0],
    [0, 3, 6, 4],
  ],
  harmonic: [
    [0, 5, 4, 0],
    [0, 3, 4, 4],
  ],
  pentatonic: [
    [0, 3, 4, 0],
  ],
};

/** Griffweisen: Stufen innerhalb der Tonleiter, relativ zum Akkordgrundton. */
const VOICINGS = {
  triad: [0, 2, 4],
  seventh: [0, 2, 4, 6],
  spread: [0, 2, 4, 7],
  power: [0, 4],
};

/** Tonhoehe eines Tonleiterschritts als MIDI-Note. */
function scaleNote(root, scaleName, degree) {
  const steps = SCALES[scaleName] || SCALES.minor;
  const octave = Math.floor(degree / steps.length);
  const index = ((degree % steps.length) + steps.length) % steps.length;
  return root + octave * 12 + steps[index];
}

const midiToHz = (midi) => 440 * 2 ** ((midi - 69) / 12);

/** Baut aus der Gesamtdeutung eine Deutung fuer eine einzelne Spur. */
function partIntent(intent, archetype, tweaks = {}) {
  const proto = ARCHETYPES[archetype].dims;
  const dims = { ...intent.dimsByName };
  // Die Spur folgt ihrem eigenen Klangtyp, behaelt aber die Stimmung des
  // Prompts: "dunkler Techno" faerbt Kick, Hi-Hat und Bass gleichermassen.
  for (const key of Object.keys(dims)) {
    const base = proto[key];
    if (base != null) dims[key] = base * 0.68 + dims[key] * 0.32;
  }
  Object.assign(dims, tweaks);
  return {
    ...intent,
    archetype,
    family: ARCHETYPES[archetype].family,
    dimsByName: dims,
    dims: null,
  };
}

/** Mischt ein Einzelsignal an eine Position im Gesamtsignal. */
function mixInto(target, source, offset, gain) {
  const start = Math.max(0, Math.floor(offset));
  const n = Math.min(source.length, target.length - start);
  for (let i = 0; i < n; i++) target[start + i] += source[i] * gain;
}

/**
 * Erzeugt eine Schleife.
 *
 * @param {object} opts
 * @param {object} opts.intent
 * @param {number} opts.sampleRate
 * @param {number} [opts.bars]
 * @param {number} [opts.bpm]
 * @param {number} [opts.seed]
 * @param {(p:number, label:string) => void} [opts.onProgress]
 * @returns {{channels:Float32Array[], sampleRate:number, seconds:number, bpm:number, bars:number, parts:string[]}}
 */
export function composeLoop(opts) {
  const { intent, sampleRate, seed = 1, onProgress = () => {} } = opts;
  const random = rng(seed);

  const bpm = Math.min(220, Math.max(50, opts.bpm || intent.bpm || 120));
  const bars = Math.min(16, Math.max(1, opts.bars || intent.bars || 2));
  const beatSeconds = 60 / bpm;
  const stepSeconds = beatSeconds / 4;
  const seconds = bars * 4 * beatSeconds;
  const n = Math.floor(seconds * sampleRate);
  const mix = new Float32Array(n);

  const groove = GROOVES[intent.groove] || GROOVES.four;
  const swing = intent.swing || 0;
  const root = intent.root ?? 45; // A2, wenn nichts angegeben ist
  const scale = intent.scale || 'minor';

  // Welche Spuren? Ein ausdruecklich genanntes Instrument bekommt eine eigene
  // Schleife, sonst entsteht ein vollstaendiges Arrangement.
  const focus = intent.focus;
  const drumFocus = ['kick', 'snare', 'clap', 'hat', 'openhat', 'perc', 'tom', 'rim', 'cymbal'];
  let parts;
  if (focus && drumFocus.includes(focus)) parts = [focus];
  else if (focus) parts = [focus];
  else parts = ['kick', 'clap', 'hat', 'openhat', 'perc', 'bass', 'chord'];

  // Zwischenspeicher: derselbe Klang wird nicht zweimal berechnet.
  const cache = new Map();
  const renderCached = (key, gene, noteSeconds, pitchHz) => {
    const id = `${key}|${noteSeconds.toFixed(3)}|${pitchHz ? pitchHz.toFixed(2) : ''}`;
    let buf = cache.get(id);
    if (!buf) {
      buf = renderMono(gene, { seconds: noteSeconds, sampleRate, seed: seed + key.length, pitchHz });
      normalize([buf], 0.85);
      cache.set(id, buf);
    }
    return buf;
  };

  const stepTime = (bar, step) => {
    // Swing verschiebt jede zweite Sechzehntel nach hinten.
    const swung = step % 2 === 1 ? swing * stepSeconds * 0.5 : 0;
    const human = (random() * 2 - 1) * 0.004;
    return (bar * 16 + step) * stepSeconds + swung + human;
  };

  const used = [];
  let done = 0;
  const announce = (label) => {
    done++;
    onProgress(Math.min(0.9, done / (parts.length + 1)), `${label} wird erzeugt …`);
  };

  // --- Perkussion ------------------------------------------------------------
  // Pegel der Spuren zueinander. Kick und Bass tragen die Schleife und stehen
  // deshalb deutlich vorn; Hi-Hats und Percussion wuerden sonst den Unterbau
  // zudecken – gemessen lag der Bassanteil einer Techno-Schleife bei 11 %.
  const drumGain = { kick: 1.15, clap: 0.6, hat: 0.3, openhat: 0.28, perc: 0.32, snare: 0.66, tom: 0.6, rim: 0.42, cymbal: 0.34 };
  for (const part of parts) {
    const pattern = groove[part] || groove[part === 'snare' ? 'clap' : part];
    if (!pattern) continue;
    announce(ARCHETYPES[part]?.label || part);

    const pIntent = partIntent(intent, part);
    const gene = seedGenome(pIntent, rng(seed + part.length * 31));
    const p = decode(gene);
    // Ein Schlag darf nicht in den naechsten hineinlaufen.
    const maxLen = Math.min(seconds, ARCHETYPES[part].seconds * 1.2);
    const buf = renderCached(part, gene, maxLen);

    for (let bar = 0; bar < bars; bar++) {
      for (let step = 0; step < 16; step++) {
        const c = pattern[step];
        if (c === '.') continue;
        const velocity = c === 'X' ? 1 : c === 'x' ? 0.78 : 0.45;
        // Leichte Schwankung der Anschlagstaerke.
        const vel = velocity * (0.9 + random() * 0.16);
        mixInto(mix, buf, stepTime(bar, step) * sampleRate, vel * (drumGain[part] ?? 0.5));
      }
    }
    used.push(part);
    void p;
  }

  // --- Akkordfolge -----------------------------------------------------------
  const progressions = PROGRESSIONS[scale] || PROGRESSIONS.minor;
  const progression = progressions[Math.floor(random() * progressions.length)];

  // --- Bass ------------------------------------------------------------------
  if (parts.includes('bass') || parts.includes('sub')) {
    const part = parts.includes('sub') ? 'sub' : 'bass';
    announce('Bass');
    const pIntent = partIntent(intent, part);
    const gene = seedGenome(pIntent, rng(seed + 977));
    const noteLen = Math.min(stepSeconds * 3, 0.6);

    for (let bar = 0; bar < bars; bar++) {
      const degree = progression[bar % progression.length];
      const midi = scaleNote(root - 12, scale, degree);
      for (let step = 0; step < 16; step++) {
        // Bass folgt der Kick, laesst aber den ersten Schlag frei atmen.
        const kickPattern = groove.kick;
        const onKick = kickPattern[step] !== '.';
        const offbeat = step % 4 === 2 && random() < 0.45;
        if (!onKick && !offbeat) continue;
        const buf = renderCached('bass', gene, noteLen, midiToHz(midi));
        mixInto(mix, buf, stepTime(bar, step) * sampleRate, (onKick ? 0.9 : 0.58) * (0.9 + random() * 0.15));
      }
    }
    used.push(part);
  }

  // --- Akkorde ---------------------------------------------------------------
  if (parts.includes('chord') || parts.includes('stab') || parts.includes('pad')) {
    const part = parts.includes('pad') ? 'pad' : parts.includes('stab') ? 'stab' : 'chord';
    announce('Akkorde');
    const pIntent = partIntent(intent, part);
    const gene = seedGenome(pIntent, rng(seed + 313));
    const voicing =
      intent.dimsByName.density > 0.7 ? VOICINGS.seventh : intent.dimsByName.complexity > 0.6 ? VOICINGS.spread : VOICINGS.triad;
    const isPad = part === 'pad';
    const noteLen = isPad ? beatSeconds * 4 : Math.min(beatSeconds * 1.5, 1.2);

    for (let bar = 0; bar < bars; bar++) {
      const degree = progression[bar % progression.length];
      // Stabs sitzen auf der Zaehlzeit oder synkopiert dazwischen.
      const steps = isPad ? [0] : bar % 2 === 0 ? [0, 6, 10] : [0, 6, 11];
      for (const step of steps) {
        for (const interval of voicing) {
          const midi = scaleNote(root, scale, degree + interval);
          const buf = renderCached(part, gene, noteLen, midiToHz(midi));
          const gain = (isPad ? 0.22 : 0.27) / Math.sqrt(voicing.length);
          mixInto(mix, buf, stepTime(bar, step) * sampleRate, gain * (0.88 + random() * 0.2));
        }
      }
    }
    used.push(part);
  }

  // --- Melodie ---------------------------------------------------------------
  if (parts.includes('lead') || parts.includes('pluck') || parts.includes('bell')) {
    const part = parts.includes('lead') ? 'lead' : parts.includes('pluck') ? 'pluck' : 'bell';
    announce('Melodie');
    const pIntent = partIntent(intent, part);
    const gene = seedGenome(pIntent, rng(seed + 641));
    const noteLen = Math.min(beatSeconds * 1.2, 1.4);

    // Melodiefuehrung: kleine Schritte sind wahrscheinlicher als grosse
    // Spruenge – das ist die einfachste Form eines musikalischen Modells.
    let degree = 0;
    for (let bar = 0; bar < bars; bar++) {
      const chordDegree = progression[bar % progression.length];
      for (let step = 0; step < 16; step += 2) {
        if (random() < 0.35) continue;
        const move = random();
        const delta = move < 0.45 ? (random() < 0.5 ? 1 : -1) : move < 0.75 ? (random() < 0.5 ? 2 : -2) : 0;
        degree = Math.max(-3, Math.min(11, degree + delta));
        // Auf Zaehlzeit eins zurueck zum Akkordton.
        const target = step === 0 ? chordDegree : degree;
        const midi = scaleNote(root + 12, scale, target);
        const buf = renderCached(part, gene, noteLen, midiToHz(midi));
        mixInto(mix, buf, stepTime(bar, step) * sampleRate, 0.34 * (0.85 + random() * 0.25));
      }
    }
    used.push(part);
  }

  // --- Raum und Stereo -------------------------------------------------------
  onProgress(0.94, 'Schleife wird abgemischt …');
  const spaceGene = seedGenome(intent, rng(seed + 55));
  const spaceParams = decode(spaceGene);
  // Bei einer Schleife bleibt der Hall zurueckhaltender als beim Einzelklang,
  // sonst verwaschen die Schlaege ineinander.
  spaceParams.reverbAmount *= 0.55;
  const channels = spatialize(mix, spaceParams, sampleRate);
  normalize(channels, 0.9);

  return { channels, sampleRate, seconds, bpm, bars, parts: used };
}
