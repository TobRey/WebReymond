/**
 * AI Groove – lokaler Sample-Generator.
 *
 * Dieser Generator laeuft komplett im Browser und braucht weder Internet noch
 * API-Key. Er ist kein neuronales Modell, sondern echte Klangsynthese:
 * Oszillatoren, Rauschen, Filter, Huellkurven und Saettigung.
 *
 * Ergebnis sind sofort brauchbare Drums, Bass, Stabs, Riser und FX –
 * gesteuert ueber Schluesselwoerter im Prompt.
 */

import { clamp } from '../core/util.js';

// --- Grundbausteine ----------------------------------------------------------

/** Deterministischer Zufall, damit ein Seed immer dasselbe Ergebnis liefert. */
function rng(seed) {
  let a = (seed >>> 0) || 1;
  return () => {
    a ^= a << 13;
    a >>>= 0;
    a ^= a >> 17;
    a ^= a << 5;
    a >>>= 0;
    return a / 4294967296;
  };
}

/** Biquad-Filter nach dem RBJ-Kochbuch. */
class Biquad {
  constructor(type, freq, q, sampleRate, gainDb = 0) {
    this.set(type, freq, q, sampleRate, gainDb);
    this.x1 = 0;
    this.x2 = 0;
    this.y1 = 0;
    this.y2 = 0;
  }

  set(type, freq, q, sampleRate, gainDb = 0) {
    const w0 = (2 * Math.PI * clamp(freq, 10, sampleRate / 2 - 100)) / sampleRate;
    const cos = Math.cos(w0);
    const sin = Math.sin(w0);
    const alpha = sin / (2 * Math.max(0.05, q));
    const A = 10 ** (gainDb / 40);
    let b0 = 1;
    let b1 = 0;
    let b2 = 0;
    let a0 = 1;
    let a1 = 0;
    let a2 = 0;

    switch (type) {
      case 'lowpass':
        b0 = (1 - cos) / 2;
        b1 = 1 - cos;
        b2 = b0;
        a0 = 1 + alpha;
        a1 = -2 * cos;
        a2 = 1 - alpha;
        break;
      case 'highpass':
        b0 = (1 + cos) / 2;
        b1 = -(1 + cos);
        b2 = b0;
        a0 = 1 + alpha;
        a1 = -2 * cos;
        a2 = 1 - alpha;
        break;
      case 'bandpass':
        b0 = alpha;
        b1 = 0;
        b2 = -alpha;
        a0 = 1 + alpha;
        a1 = -2 * cos;
        a2 = 1 - alpha;
        break;
      case 'peaking':
        b0 = 1 + alpha * A;
        b1 = -2 * cos;
        b2 = 1 - alpha * A;
        a0 = 1 + alpha / A;
        a1 = -2 * cos;
        a2 = 1 - alpha / A;
        break;
      default:
        break;
    }
    this.b0 = b0 / a0;
    this.b1 = b1 / a0;
    this.b2 = b2 / a0;
    this.a1 = a1 / a0;
    this.a2 = a2 / a0;
  }

  process(x) {
    const y = this.b0 * x + this.b1 * this.x1 + this.b2 * this.x2 - this.a1 * this.y1 - this.a2 * this.y2;
    this.x2 = this.x1;
    this.x1 = x;
    this.y2 = this.y1;
    this.y1 = y;
    return y;
  }
}

const env = {
  /** Exponentiell abfallende Huellkurve. */
  decay: (t, time) => Math.exp(-t / Math.max(0.0005, time)),
  /** Attack/Decay. */
  ad: (t, a, d) => (t < a ? t / Math.max(0.0001, a) : Math.exp(-(t - a) / Math.max(0.0005, d))),
  /** Weiche Ausblendung am Ende, damit nie ein Knacken entsteht. */
  tail: (t, total, len = 0.008) => (t > total - len ? Math.max(0, (total - t) / len) : 1),
};

/** Weiche Saettigung. */
const sat = (x, drive) => Math.tanh(x * (1 + drive * 4)) / Math.tanh(1 + drive * 4);

// --- Prompt-Analyse ----------------------------------------------------------

const KIND_RULES = [
  ['kick', /\b(kick|bassdrum|bass drum|bd|808 kick|stomp)\b/i],
  ['sub', /\b(sub|808 bass|sine bass|deep bass)\b/i],
  ['bass', /\b(bass|bassline|reese|acid)\b/i],
  ['snare', /\b(snare|rimshot|rim)\b/i],
  ['clap', /\b(clap|handclap)\b/i],
  ['openhat', /\b(open hat|open hi.?hat|ohh|crash|ride|cymbal)\b/i],
  ['hat', /\b(hi.?hat|hihat|hat|hh|shaker|tick)\b/i],
  ['tom', /\b(tom|floor tom)\b/i],
  ['perc', /\b(perc|percussion|conga|bongo|click|wood|clave)\b/i],
  ['riser', /\b(riser|uplifter|sweep|build|rise)\b/i],
  ['impact', /\b(impact|downlifter|boom|slam|hit|drop fx)\b/i],
  ['noise', /\b(noise|texture|atmo|ambience|air|wind)\b/i],
  ['stab', /\b(stab|chord|organ|brass)\b/i],
  ['pad', /\b(pad|drone|string|ambient)\b/i],
  ['lead', /\b(lead|arp|melody|pluck|saw)\b/i],
  ['vocal', /\b(vocal|vox|voice|chop|choir)\b/i],
];

const NOTE_MAP = { c: 0, d: 2, e: 4, f: 5, g: 7, a: 9, b: 11, h: 11 };

/** Liest Klangcharakter und Tonhoehe aus dem Prompt. */
export function analysePrompt(prompt) {
  const text = String(prompt || '');
  let kind = 'perc';
  for (const [name, re] of KIND_RULES) {
    if (re.test(text)) {
      kind = name;
      break;
    }
  }

  const hard = /\b(hard|punchy|aggressive|industrial|distorted|dirty|heavy|brutal|techno|rough)\b/i.test(text);
  const soft = /\b(soft|smooth|warm|gentle|round|vintage|lofi|lo-fi)\b/i.test(text);
  const dark = /\b(dark|deep|low|muffled|dull|underground)\b/i.test(text);
  const bright = /\b(bright|crisp|sharp|airy|open|shiny|high)\b/i.test(text);
  const short = /\b(short|tight|snappy|clean tail|no tail|dry)\b/i.test(text);
  const long = /\b(long|tail|sustained|booming|reverb|wet|huge)\b/i.test(text);
  const distorted = /\b(distort|distorted|saturate|overdrive|clip|crunch|industrial)\b/i.test(text);

  // BPM aus dem Prompt (z. B. "155 BPM")
  const bpmMatch = text.match(/(\d{2,3})\s*(?:bpm|b\.p\.m)/i);
  const bpm = bpmMatch ? clamp(parseInt(bpmMatch[1], 10), 40, 250) : null;

  // Note (z. B. "in F#", "key of A")
  let midi = null;
  const noteMatch = text.match(/\b(?:in|key of|tuned to)\s+([a-hA-H])(#|b)?\s*(-?\d)?/);
  if (noteMatch) {
    const base = NOTE_MAP[noteMatch[1].toLowerCase()];
    if (base != null) {
      const acc = noteMatch[2] === '#' ? 1 : noteMatch[2] === 'b' ? -1 : 0;
      const oct = noteMatch[3] != null ? parseInt(noteMatch[3], 10) : 1;
      midi = 12 * (oct + 1) + base + acc;
    }
  }

  return {
    kind,
    hard,
    soft,
    dark,
    bright,
    short,
    long,
    distorted,
    bpm,
    midi,
    text,
  };
}

// --- Generatoren -------------------------------------------------------------

function generateKind(analysis, seconds, sampleRate, seed) {
  const rand = rng(seed);
  const n = Math.max(64, Math.floor(seconds * sampleRate));
  const out = new Float32Array(n);
  const sr = sampleRate;
  const a = analysis;

  const vary = (base, amount) => base * (1 + (rand() * 2 - 1) * amount);

  switch (a.kind) {
    case 'kick': {
      const startF = vary(a.hard ? 190 : 150, 0.12);
      const endF = vary(a.dark ? 40 : 48, 0.08);
      const pitchDecay = vary(a.hard ? 0.028 : 0.05, 0.2);
      const ampDecay = vary(a.short ? 0.16 : a.long ? 0.75 : 0.34, 0.15);
      const clickAmt = a.hard ? 0.55 : 0.28;
      const drive = a.distorted ? 0.9 : a.hard ? 0.55 : 0.18;
      const lp = new Biquad('lowpass', a.dark ? 3200 : 7000, 0.7, sr);
      let phase = 0;

      for (let i = 0; i < n; i++) {
        const t = i / sr;
        const f = endF + (startF - endF) * Math.exp(-t / pitchDecay);
        phase += (2 * Math.PI * f) / sr;
        let s = Math.sin(phase) * env.decay(t, ampDecay);
        // Anschlagsgeraeusch fuer den "Punch"
        s += (rand() * 2 - 1) * clickAmt * env.decay(t, 0.0035);
        s = sat(s * 1.25, drive);
        out[i] = lp.process(s) * env.tail(t, seconds);
      }
      break;
    }

    case 'sub': {
      const f = a.midi != null ? 440 * 2 ** ((a.midi - 69) / 12) : vary(52, 0.1);
      const decay = a.short ? 0.35 : a.long ? 2.2 : 0.9;
      let phase = 0;
      for (let i = 0; i < n; i++) {
        const t = i / sr;
        phase += (2 * Math.PI * f) / sr;
        const s = Math.sin(phase) * env.ad(t, 0.006, decay);
        out[i] = sat(s, a.distorted ? 0.5 : 0.08) * env.tail(t, seconds);
      }
      break;
    }

    case 'bass': {
      const f = a.midi != null ? 440 * 2 ** ((a.midi - 69) / 12) : vary(a.dark ? 55 : 82, 0.08);
      const decay = a.short ? 0.28 : a.long ? 1.6 : 0.7;
      const cutoffStart = vary(a.bright ? 4200 : 1500, 0.25);
      const filt = new Biquad('lowpass', cutoffStart, a.hard ? 4.5 : 1.6, sr);
      let phase = 0;
      for (let i = 0; i < n; i++) {
        const t = i / sr;
        phase += (2 * Math.PI * f) / sr;
        const p = phase % (2 * Math.PI);
        // Saegezahn + leicht verstimmte zweite Stimme (Reese-Charakter)
        const saw = p / Math.PI - 1;
        const p2 = (phase * 1.005) % (2 * Math.PI);
        const saw2 = p2 / Math.PI - 1;
        let s = (saw * 0.6 + saw2 * 0.4) * env.ad(t, 0.004, decay);
        const co = 120 + (cutoffStart - 120) * Math.exp(-t / (decay * 0.6));
        filt.set('lowpass', co, a.hard ? 4.5 : 1.6, sr);
        s = filt.process(s);
        out[i] = sat(s * 0.9, a.distorted ? 0.8 : 0.25) * env.tail(t, seconds);
      }
      break;
    }

    case 'snare': {
      const tone = vary(a.dark ? 160 : 200, 0.12);
      const noiseDecay = vary(a.short ? 0.09 : a.long ? 0.45 : 0.19, 0.18);
      const bodyDecay = vary(0.09, 0.2);
      const hp = new Biquad('highpass', a.dark ? 900 : 1500, 0.8, sr);
      const bp = new Biquad('bandpass', vary(3200, 0.2), 0.9, sr);
      let ph1 = 0;
      let ph2 = 0;
      for (let i = 0; i < n; i++) {
        const t = i / sr;
        ph1 += (2 * Math.PI * tone) / sr;
        ph2 += (2 * Math.PI * tone * 1.6) / sr;
        const body = (Math.sin(ph1) * 0.7 + Math.sin(ph2) * 0.3) * env.decay(t, bodyDecay);
        const noise = hp.process(rand() * 2 - 1) * env.decay(t, noiseDecay);
        let s = body * 0.55 + (noise + bp.process(noise) * 0.5) * 0.75;
        s = sat(s, a.distorted ? 0.7 : a.hard ? 0.35 : 0.1);
        out[i] = s * env.tail(t, seconds);
      }
      break;
    }

    case 'clap': {
      const spread = vary(0.011, 0.25);
      const bursts = [0, spread, spread * 2, spread * 3.1];
      const bp = new Biquad('bandpass', vary(a.bright ? 1900 : 1300, 0.2), 0.7, sr);
      const hp = new Biquad('highpass', 700, 0.7, sr);
      const bodyDecay = a.short ? 0.11 : a.long ? 0.42 : 0.2;
      for (let i = 0; i < n; i++) {
        const t = i / sr;
        let amp = 0;
        for (let b = 0; b < bursts.length; b++) {
          if (t >= bursts[b]) {
            amp += env.decay(t - bursts[b], b === bursts.length - 1 ? bodyDecay : 0.007) * (b === 3 ? 1 : 0.7);
          }
        }
        const noise = hp.process(rand() * 2 - 1);
        out[i] = sat(bp.process(noise) * amp * 0.9, a.hard ? 0.3 : 0.08) * env.tail(t, seconds);
      }
      break;
    }

    case 'hat':
    case 'openhat': {
      const isOpen = a.kind === 'openhat';
      const decay = isOpen ? vary(a.long ? 0.9 : 0.42, 0.2) : vary(a.short ? 0.028 : 0.06, 0.25);
      const hp = new Biquad('highpass', a.dark ? 5000 : 8000, 0.7, sr);
      const bp = new Biquad('bandpass', vary(11000, 0.15), 1.1, sr);
      // Metallischer Anteil: sechs unharmonische Rechteckschwinger (klassisches 808-Prinzip)
      const ratios = [1, 1.4471, 1.6170, 1.9265, 2.5028, 2.6637];
      const base = vary(a.bright ? 320 : 250, 0.15);
      const phases = new Float32Array(6);
      for (let i = 0; i < n; i++) {
        const t = i / sr;
        let metal = 0;
        for (let k = 0; k < 6; k++) {
          phases[k] += (2 * Math.PI * base * ratios[k]) / sr;
          metal += Math.sign(Math.sin(phases[k]));
        }
        metal /= 6;
        const noise = rand() * 2 - 1;
        let s = (metal * 0.65 + noise * 0.35) * env.decay(t, decay);
        s = bp.process(hp.process(s));
        out[i] = sat(s * 1.2, a.distorted ? 0.4 : 0.05) * env.tail(t, seconds);
      }
      break;
    }

    case 'tom': {
      const startF = vary(a.dark ? 150 : 220, 0.15);
      const endF = startF * 0.55;
      const decay = vary(a.short ? 0.18 : 0.45, 0.15);
      let phase = 0;
      for (let i = 0; i < n; i++) {
        const t = i / sr;
        const f = endF + (startF - endF) * Math.exp(-t / 0.09);
        phase += (2 * Math.PI * f) / sr;
        const s = Math.sin(phase) * env.decay(t, decay) + (rand() * 2 - 1) * 0.12 * env.decay(t, 0.004);
        out[i] = sat(s, a.hard ? 0.3 : 0.08) * env.tail(t, seconds);
      }
      break;
    }

    case 'perc': {
      const f = vary(a.bright ? 1400 : 700, 0.3);
      const decay = vary(a.short ? 0.05 : 0.14, 0.25);
      const bp = new Biquad('bandpass', f, 3.5, sr);
      let phase = 0;
      for (let i = 0; i < n; i++) {
        const t = i / sr;
        phase += (2 * Math.PI * f) / sr;
        const s =
          (Math.sin(phase) * 0.5 + (rand() * 2 - 1) * 0.5) * env.decay(t, decay) +
          bp.process(rand() * 2 - 1) * env.decay(t, decay * 0.7) * 0.6;
        out[i] = sat(s * 0.9, a.hard ? 0.35 : 0.1) * env.tail(t, seconds);
      }
      break;
    }

    case 'riser': {
      const startF = 120;
      const endF = a.bright ? 9000 : 5200;
      const bp = new Biquad('bandpass', startF, 3, sr);
      let phase = 0;
      for (let i = 0; i < n; i++) {
        const t = i / sr;
        const p = t / seconds;
        const f = startF * (endF / startF) ** p;
        bp.set('bandpass', f, 3.5, sr);
        phase += (2 * Math.PI * f * 0.5) / sr;
        const noise = rand() * 2 - 1;
        const tone = Math.sin(phase) * 0.35;
        const amp = p ** 1.6;
        out[i] = sat((bp.process(noise) * 2.2 + tone) * amp, 0.2) * env.tail(t, seconds, 0.02);
      }
      break;
    }

    case 'impact': {
      const lp = new Biquad('lowpass', 400, 1.2, sr);
      let phase = 0;
      for (let i = 0; i < n; i++) {
        const t = i / sr;
        const f = 90 * Math.exp(-t * 1.6) + 28;
        phase += (2 * Math.PI * f) / sr;
        const boom = Math.sin(phase) * env.decay(t, seconds * 0.45);
        const noise = lp.process(rand() * 2 - 1) * env.decay(t, 0.28) * 0.8;
        out[i] = sat((boom + noise) * 1.1, a.distorted ? 0.8 : 0.3) * env.tail(t, seconds, 0.02);
      }
      break;
    }

    case 'noise': {
      const lp = new Biquad('lowpass', a.dark ? 1800 : 6000, 0.7, sr);
      const hp = new Biquad('highpass', a.dark ? 60 : 400, 0.7, sr);
      let mod = 0;
      for (let i = 0; i < n; i++) {
        const t = i / sr;
        mod += 0.00002;
        const noise = rand() * 2 - 1;
        const s = hp.process(lp.process(noise)) * (0.6 + 0.4 * Math.sin(mod * 60));
        const fade = Math.min(1, t / 0.05) * env.tail(t, seconds, 0.05);
        out[i] = s * fade * 0.8;
      }
      break;
    }

    case 'stab': {
      const root = a.midi != null ? a.midi : 57; // A3
      const chord = a.dark ? [0, 3, 7, 10] : [0, 4, 7, 11];
      const decay = a.short ? 0.16 : a.long ? 1.1 : 0.42;
      const lp = new Biquad('lowpass', a.bright ? 6500 : 3000, 1.1, sr);
      const phases = new Float32Array(chord.length);
      for (let i = 0; i < n; i++) {
        const t = i / sr;
        let s = 0;
        for (let k = 0; k < chord.length; k++) {
          const f = 440 * 2 ** ((root + chord[k] - 69) / 12);
          phases[k] += (2 * Math.PI * f) / sr;
          const p = phases[k] % (2 * Math.PI);
          s += (p / Math.PI - 1) * 0.25; // Saegezahn
        }
        s *= env.ad(t, 0.004, decay);
        out[i] = sat(lp.process(s), a.distorted ? 0.6 : 0.2) * env.tail(t, seconds);
      }
      break;
    }

    case 'pad': {
      const root = a.midi != null ? a.midi : 50;
      const voices = [0, 7, 12, 16, 19];
      const lp = new Biquad('lowpass', a.bright ? 5200 : 2400, 0.8, sr);
      const phases = new Float32Array(voices.length);
      const dets = voices.map(() => 1 + (rand() * 2 - 1) * 0.004);
      for (let i = 0; i < n; i++) {
        const t = i / sr;
        let s = 0;
        for (let k = 0; k < voices.length; k++) {
          const f = 440 * 2 ** ((root + voices[k] - 69) / 12) * dets[k];
          phases[k] += (2 * Math.PI * f) / sr;
          s += Math.sin(phases[k]) * 0.2;
        }
        const attack = Math.min(1, t / (seconds * 0.25));
        const release = env.tail(t, seconds, seconds * 0.3);
        out[i] = lp.process(s) * attack * release * 0.9;
      }
      break;
    }

    case 'lead': {
      const root = a.midi != null ? a.midi : 69;
      const decay = a.short ? 0.2 : 0.8;
      const lp = new Biquad('lowpass', a.bright ? 8000 : 3500, 2.2, sr);
      let phase = 0;
      let phase2 = 0;
      const f = 440 * 2 ** ((root - 69) / 12);
      for (let i = 0; i < n; i++) {
        const t = i / sr;
        phase += (2 * Math.PI * f) / sr;
        phase2 += (2 * Math.PI * f * 1.01) / sr;
        const saw = ((phase % (2 * Math.PI)) / Math.PI - 1) * 0.6;
        const saw2 = ((phase2 % (2 * Math.PI)) / Math.PI - 1) * 0.4;
        const s = (saw + saw2) * env.ad(t, 0.006, decay);
        out[i] = sat(lp.process(s), a.distorted ? 0.7 : 0.25) * env.tail(t, seconds);
      }
      break;
    }

    case 'vocal': {
      // Formant-Synthese: drei Bandpaesse auf einem Saegezahn ergeben einen
      // stimmaehnlichen Klang ("Vocal Chop"-Charakter), bewusst synthetisch.
      const root = a.midi != null ? a.midi : 62;
      const f0 = 440 * 2 ** ((root - 69) / 12);
      const formants = a.dark ? [400, 900, 2400] : [660, 1700, 2900];
      const bp = formants.map((f) => new Biquad('bandpass', f, 6, sr));
      const decay = a.short ? 0.22 : a.long ? 1.3 : 0.6;
      let phase = 0;
      for (let i = 0; i < n; i++) {
        const t = i / sr;
        const vib = 1 + Math.sin(2 * Math.PI * 5.2 * t) * 0.006;
        phase += (2 * Math.PI * f0 * vib) / sr;
        const src = (phase % (2 * Math.PI)) / Math.PI - 1;
        let s = 0;
        for (let k = 0; k < bp.length; k++) s += bp[k].process(src) * (1 - k * 0.22);
        s *= env.ad(t, 0.02, decay);
        out[i] = sat(s * 1.4, a.distorted ? 0.6 : 0.12) * env.tail(t, seconds, 0.02);
      }
      break;
    }

    default:
      break;
  }

  return out;
}

/** Normalisiert auf ca. -1 dBFS. */
function normalize(data, target = 0.89) {
  let peak = 0;
  for (let i = 0; i < data.length; i++) {
    const v = Math.abs(data[i]);
    if (v > peak) peak = v;
  }
  if (peak < 1e-6) return data;
  const f = target / peak;
  for (let i = 0; i < data.length; i++) data[i] *= f;
  return data;
}

/** Erzeugt ein leichtes Stereobild (kurzes Delay + Filter auf dem rechten Kanal). */
function toStereo(mono, sampleRate, width, seed) {
  const rand = rng(seed + 7);
  const left = new Float32Array(mono.length);
  const right = new Float32Array(mono.length);
  const delay = Math.floor(sampleRate * 0.0008 * width);
  const hp = new Biquad('highpass', 120, 0.7, sampleRate);
  for (let i = 0; i < mono.length; i++) {
    left[i] = mono[i];
    const d = i - delay >= 0 ? mono[i - delay] : 0;
    right[i] = mono[i] * (1 - width * 0.25) + hp.process(d) * width * 0.3 + (rand() - 0.5) * 0.0002;
  }
  return [left, right];
}

/**
 * Erzeugt Varianten eines Klangs.
 *
 * @param {object} opts
 * @param {string} opts.prompt
 * @param {number} opts.duration  Laenge in Sekunden
 * @param {number} [opts.count]   Anzahl Varianten
 * @param {number} [opts.sampleRate]
 * @param {number} [opts.seed]
 * @param {Float32Array[]} [opts.source] Ausgangsmaterial fuer "Variation"
 * @returns {Array<{channels: Float32Array[], sampleRate:number, seed:number}>}
 */
export function generateVariants(opts) {
  const {
    prompt,
    duration,
    count = 4,
    sampleRate = 44100,
    seed = Math.floor(Math.random() * 1e9),
  } = opts;

  const analysis = analysePrompt(prompt);
  const seconds = clamp(duration, 0.05, 30);
  const results = [];

  for (let v = 0; v < count; v++) {
    const s = (seed + v * 7919) >>> 0;
    const mono = normalize(generateKind(analysis, seconds, sampleRate, s));
    const stereo = toStereo(mono, sampleRate, analysis.kind === 'kick' || analysis.kind === 'sub' ? 0.1 : 0.6, s);
    results.push({ channels: stereo, sampleRate, seed: s, analysis });
  }
  return results;
}

/**
 * Bearbeitet ein vorhandenes Sample anhand eines Prompts (lokal, ohne KI-Dienst).
 * Setzt echte DSP-Schritte um: Filter, Saettigung, Huellkurve, Tonhoehe.
 */
export function transformBuffer(channels, sampleRate, prompt, seed = 1) {
  const a = analysePrompt(prompt);
  const rand = rng(seed);
  const text = String(prompt || '').toLowerCase();

  const wantHarder = /(harder|härter|harter|punchier|aggressiv|hart)/.test(text) || a.hard;
  const wantSub = /(more sub|mehr sub|sub bass|tiefer|deeper|bass boost)/.test(text);
  const wantShort = /(shorter|kürzer|kurzer|short tail|tight|snappy|trocken)/.test(text) || a.short;
  const wantLonger = /(longer|länger|langer|more tail|sustain)/.test(text) || a.long;
  const wantDark = /(darker|dunkler|muffled|dumpfer|warm)/.test(text) || a.dark;
  const wantBright = /(brighter|heller|crisp|air|sharper)/.test(text) || a.bright;
  const wantDist = /(distort|verzerr|industrial|dirty|crunch|drive)/.test(text) || a.distorted;
  const wantReverse = /(reverse|rückwärts|rueckwaerts|backwards)/.test(text);

  const len = channels[0].length;
  const out = channels.map((ch) => new Float32Array(ch));

  for (let c = 0; c < out.length; c++) {
    let d = out[c];

    if (wantReverse) d.reverse();

    if (wantDark || wantBright) {
      const filt = wantDark
        ? new Biquad('lowpass', 2200, 0.8, sampleRate)
        : new Biquad('peaking', 6500, 1.1, sampleRate, 7);
      for (let i = 0; i < len; i++) d[i] = filt.process(d[i]);
    }

    if (wantSub) {
      const lp = new Biquad('lowpass', 110, 0.9, sampleRate);
      const sub = new Float32Array(len);
      for (let i = 0; i < len; i++) sub[i] = lp.process(d[i]) * 1.6;
      for (let i = 0; i < len; i++) d[i] = d[i] + sub[i];
    }

    if (wantHarder || wantDist) {
      const drive = wantDist ? 1.4 : 0.7;
      const hp = new Biquad('peaking', 2400, 1.0, sampleRate, wantHarder ? 4 : 0);
      for (let i = 0; i < len; i++) d[i] = sat(hp.process(d[i]) * (1 + drive), drive);
    }

    if (wantShort || wantLonger) {
      const factor = wantShort ? 0.32 : 1.8;
      const tau = (len / sampleRate) * factor * 0.5;
      for (let i = 0; i < len; i++) {
        const t = i / sampleRate;
        const e = wantShort ? Math.exp(-t / Math.max(0.01, tau)) : 1;
        d[i] *= e;
      }
    }

    // Winziges Rauschen verhindert exakte Wiederholungen bei Varianten.
    if (seed > 1) {
      for (let i = 0; i < len; i++) d[i] += (rand() - 0.5) * 0.00035;
    }

    // Weiche Ausblendung am Ende
    const fade = Math.min(512, Math.floor(len * 0.02));
    for (let i = 0; i < fade; i++) d[len - 1 - i] *= i / fade;

    normalize(d, 0.92);
  }

  return out;
}
