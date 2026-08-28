/**
 * AI Groove – Effekte.
 *
 * Alle Effekte bestehen aus echten Web-Audio-Knoten. Sie funktionieren daher
 * identisch im Live-Betrieb und beim Offline-Rendering (Export).
 *
 * Jeder Effekt stellt `input` und `output` bereit. Beim Bypass wird `input`
 * direkt mit `output` verbunden – kein Rest-Signal, keine Phasenprobleme.
 */

import { uid, clamp, dbToGain } from '../core/util.js';

/** Beschreibung aller Effekte inkl. Standardwerten und Reglergrenzen. */
export const FX_DEFS = {
  gain: {
    label: 'Gain / Boost',
    params: [{ key: 'db', label: 'Pegel', min: -24, max: 24, step: 0.1, def: 0, unit: 'dB' }],
  },
  eq: {
    label: 'EQ (3-Band)',
    params: [
      { key: 'lowGain', label: 'Low', min: -18, max: 18, step: 0.1, def: 0, unit: 'dB' },
      { key: 'lowFreq', label: 'Low Freq', min: 40, max: 500, step: 1, def: 160, unit: 'Hz', log: true },
      { key: 'midGain', label: 'Mid', min: -18, max: 18, step: 0.1, def: 0, unit: 'dB' },
      { key: 'midFreq', label: 'Mid Freq', min: 200, max: 6000, step: 10, def: 1200, unit: 'Hz', log: true },
      { key: 'midQ', label: 'Mid Q', min: 0.2, max: 6, step: 0.05, def: 0.9, unit: '' },
      { key: 'highGain', label: 'High', min: -18, max: 18, step: 0.1, def: 0, unit: 'dB' },
      { key: 'highFreq', label: 'High Freq', min: 1500, max: 16000, step: 50, def: 6000, unit: 'Hz', log: true },
    ],
  },
  compressor: {
    label: 'Compressor',
    params: [
      { key: 'threshold', label: 'Threshold', min: -60, max: 0, step: 0.5, def: -22, unit: 'dB' },
      { key: 'ratio', label: 'Ratio', min: 1, max: 20, step: 0.1, def: 4, unit: ':1' },
      { key: 'attack', label: 'Attack', min: 0.0005, max: 0.2, step: 0.0005, def: 0.006, unit: 's' },
      { key: 'release', label: 'Release', min: 0.02, max: 1.2, step: 0.01, def: 0.18, unit: 's' },
      { key: 'knee', label: 'Knee', min: 0, max: 40, step: 0.5, def: 8, unit: 'dB' },
      { key: 'makeup', label: 'Makeup', min: -12, max: 18, step: 0.1, def: 0, unit: 'dB' },
    ],
  },
  limiter: {
    label: 'Limiter',
    params: [
      { key: 'ceiling', label: 'Ceiling', min: -12, max: 0, step: 0.1, def: -0.6, unit: 'dB' },
      { key: 'release', label: 'Release', min: 0.01, max: 0.5, step: 0.005, def: 0.06, unit: 's' },
    ],
  },
  distortion: {
    label: 'Distortion',
    params: [
      { key: 'drive', label: 'Drive', min: 0, max: 100, step: 1, def: 30, unit: '%' },
      { key: 'tone', label: 'Tone', min: 400, max: 18000, step: 50, def: 9000, unit: 'Hz', log: true },
      { key: 'mix', label: 'Mix', min: 0, max: 100, step: 1, def: 100, unit: '%' },
      { key: 'out', label: 'Ausgang', min: -24, max: 6, step: 0.1, def: -3, unit: 'dB' },
    ],
  },
  reverb: {
    label: 'Reverb',
    params: [
      { key: 'size', label: 'Size', min: 0.2, max: 6, step: 0.05, def: 1.8, unit: 's' },
      { key: 'damp', label: 'Damping', min: 500, max: 18000, step: 100, def: 6500, unit: 'Hz', log: true },
      { key: 'predelay', label: 'Pre-Delay', min: 0, max: 200, step: 1, def: 12, unit: 'ms' },
      { key: 'mix', label: 'Mix', min: 0, max: 100, step: 1, def: 25, unit: '%' },
    ],
  },
  delay: {
    label: 'Delay',
    params: [
      { key: 'time', label: 'Zeit', min: 10, max: 2000, step: 1, def: 375, unit: 'ms' },
      { key: 'feedback', label: 'Feedback', min: 0, max: 92, step: 1, def: 38, unit: '%' },
      { key: 'damp', label: 'Damping', min: 500, max: 18000, step: 100, def: 5200, unit: 'Hz', log: true },
      { key: 'mix', label: 'Mix', min: 0, max: 100, step: 1, def: 26, unit: '%' },
      { key: 'sync', label: 'Zum Takt', min: 0, max: 1, step: 1, def: 0, unit: '', bool: true },
      { key: 'div', label: 'Notenwert', min: 0, max: 5, step: 1, def: 2, unit: '', choices: ['1/1', '1/2', '1/4', '1/8', '1/8.', '1/16'] },
    ],
  },
  lowpass: {
    label: 'Low-Pass Filter',
    params: [
      { key: 'freq', label: 'Cutoff', min: 60, max: 20000, step: 10, def: 20000, unit: 'Hz', log: true },
      { key: 'q', label: 'Resonanz', min: 0.1, max: 18, step: 0.1, def: 0.9, unit: '' },
    ],
  },
  highpass: {
    label: 'High-Pass Filter',
    params: [
      { key: 'freq', label: 'Cutoff', min: 20, max: 12000, step: 5, def: 20, unit: 'Hz', log: true },
      { key: 'q', label: 'Resonanz', min: 0.1, max: 18, step: 0.1, def: 0.9, unit: '' },
    ],
  },
};

/** Reihenfolge im Hinzufuegen-Menue. */
export const FX_ORDER = [
  'gain',
  'eq',
  'compressor',
  'distortion',
  'lowpass',
  'highpass',
  'delay',
  'reverb',
  'limiter',
];

/** Erzeugt den Zustand eines neuen Effekts (reines Datenobjekt, gehoert ins Projekt). */
export function createEffectState(type) {
  const def = FX_DEFS[type];
  if (!def) throw new Error(`Unbekannter Effekt: ${type}`);
  const params = {};
  for (const p of def.params) params[p.key] = p.def;
  return { id: uid('fx'), type, enabled: true, params };
}

/** Stellt sicher, dass ein geladener Effekt alle Parameter besitzt. */
export function normalizeEffectState(state) {
  const def = FX_DEFS[state?.type];
  if (!def) return null;
  const params = {};
  for (const p of def.params) {
    const v = Number(state.params?.[p.key]);
    params[p.key] = Number.isFinite(v) ? clamp(v, p.min, p.max) : p.def;
  }
  return { id: state.id || uid('fx'), type: state.type, enabled: state.enabled !== false, params };
}

// --- Hilfsknoten -------------------------------------------------------------

/** Deterministischer Zufallsgenerator: Offline-Render klingt exakt wie live. */
function mulberry32(seed) {
  let a = seed >>> 0;
  return function () {
    a |= 0;
    a = (a + 0x6d2b79f5) | 0;
    let t = Math.imul(a ^ (a >>> 15), 1 | a);
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}

const irCache = new Map();

/**
 * Erzeugt eine Impulsantwort fuer den Hall.
 * Exponentiell abfallendes Rauschen mit leichter Stereodekorrelation.
 */
export function makeImpulseResponse(ctx, seconds, damping) {
  const len = Math.max(1, Math.floor(ctx.sampleRate * clamp(seconds, 0.05, 8)));
  const key = `${ctx.sampleRate}|${len}|${Math.round(damping)}`;
  const cached = irCache.get(key);
  if (cached && cached.length === len) return cached;

  const buffer = ctx.createBuffer(2, len, ctx.sampleRate);
  const rnd = mulberry32(0x9e3779b9);
  // Einfacher Ein-Pol-Tiefpass fuer die Daempfung hoher Frequenzen.
  const coeff = Math.exp((-2 * Math.PI * clamp(damping, 200, 20000)) / ctx.sampleRate);

  for (let ch = 0; ch < 2; ch++) {
    const data = buffer.getChannelData(ch);
    let last = 0;
    for (let i = 0; i < len; i++) {
      const white = rnd() * 2 - 1;
      last = white * (1 - coeff) + last * coeff;
      // Abklingkurve; die ersten Millisekunden werden leicht eingeblendet.
      const t = i / len;
      const env = (1 - t) ** 2.6;
      const attack = Math.min(1, i / (ctx.sampleRate * 0.004));
      data[i] = last * env * attack * 0.9;
    }
  }
  irCache.set(key, buffer);
  if (irCache.size > 12) irCache.delete(irCache.keys().next().value);
  return buffer;
}

/** Verzerrungskennlinie (weiche Saettigung). */
const curveCache = new Map();
function makeDistortionCurve(amount) {
  const k = Math.round(clamp(amount, 0, 100));
  const cached = curveCache.get(k);
  if (cached) return cached;
  const n = 2048;
  const curve = new Float32Array(n);
  const drive = 1 + k * 0.6;
  for (let i = 0; i < n; i++) {
    const x = (i / (n - 1)) * 2 - 1;
    curve[i] = Math.tanh(x * drive) / Math.tanh(drive);
  }
  curveCache.set(k, curve);
  return curve;
}

/** Notenwert des Delays in Sekunden. */
function syncDelayTime(divIndex, bpm) {
  const beat = 60 / Math.max(1, bpm);
  switch (Math.round(divIndex)) {
    case 0:
      return beat * 4;
    case 1:
      return beat * 2;
    case 2:
      return beat;
    case 3:
      return beat / 2;
    case 4:
      return (beat / 2) * 1.5;
    default:
      return beat / 4;
  }
}

// --- Effekt-Instanzen --------------------------------------------------------

/**
 * Baut einen Effektknoten.
 * @returns {{input:AudioNode, output:AudioNode, update:Function, setEnabled:Function, dispose:Function, type:string, id:string}}
 */
export function createEffectNode(ctx, state, env = {}) {
  const type = state.type;
  const input = ctx.createGain();
  const output = ctx.createGain();
  const nodes = [];
  let chainHead = null;
  let chainTail = null;
  let enabled = state.enabled !== false;
  let update = () => {};

  const track = (n) => {
    nodes.push(n);
    return n;
  };
  const setAt = (param, value, time) => {
    const v = Number.isFinite(value) ? value : 0;
    if (ctx.state === 'running' || ctx.currentTime > 0) {
      // Kleine Rampe verhindert Knackser beim Reglerbewegen.
      try {
        param.setTargetAtTime(v, time ?? ctx.currentTime, 0.01);
        return;
      } catch (_) {
        /* faellt durch */
      }
    }
    param.value = v;
  };

  switch (type) {
    case 'gain': {
      const g = track(ctx.createGain());
      chainHead = chainTail = g;
      update = (p) => setAt(g.gain, dbToGain(p.db));
      break;
    }

    case 'eq': {
      const low = track(ctx.createBiquadFilter());
      low.type = 'lowshelf';
      const mid = track(ctx.createBiquadFilter());
      mid.type = 'peaking';
      const high = track(ctx.createBiquadFilter());
      high.type = 'highshelf';
      low.connect(mid).connect(high);
      chainHead = low;
      chainTail = high;
      update = (p) => {
        setAt(low.frequency, p.lowFreq);
        setAt(low.gain, p.lowGain);
        setAt(mid.frequency, p.midFreq);
        setAt(mid.Q, p.midQ);
        setAt(mid.gain, p.midGain);
        setAt(high.frequency, p.highFreq);
        setAt(high.gain, p.highGain);
      };
      break;
    }

    case 'compressor': {
      const comp = track(ctx.createDynamicsCompressor());
      const makeup = track(ctx.createGain());
      comp.connect(makeup);
      chainHead = comp;
      chainTail = makeup;
      update = (p) => {
        setAt(comp.threshold, p.threshold);
        setAt(comp.knee, p.knee);
        setAt(comp.ratio, p.ratio);
        setAt(comp.attack, p.attack);
        setAt(comp.release, p.release);
        setAt(makeup.gain, dbToGain(p.makeup));
      };
      // Fuer die Gain-Reduction-Anzeige im Mixer.
      output.__compressor = comp;
      break;
    }

    case 'limiter': {
      // Ein Kompressor mit sehr hohem Verhaeltnis und kurzer Attack wirkt als Limiter.
      const comp = track(ctx.createDynamicsCompressor());
      comp.ratio.value = 20;
      comp.knee.value = 0;
      comp.attack.value = 0.001;
      chainHead = chainTail = comp;
      update = (p) => {
        setAt(comp.threshold, p.ceiling);
        setAt(comp.release, p.release);
      };
      output.__compressor = comp;
      break;
    }

    case 'distortion': {
      const pre = track(ctx.createGain());
      const shaper = track(ctx.createWaveShaper());
      shaper.oversample = '4x';
      const tone = track(ctx.createBiquadFilter());
      tone.type = 'lowpass';
      const wet = track(ctx.createGain());
      const dry = track(ctx.createGain());
      const out = track(ctx.createGain());

      pre.connect(shaper).connect(tone).connect(wet).connect(out);
      chainHead = ctx.createGain();
      track(chainHead);
      chainHead.connect(pre);
      chainHead.connect(dry).connect(out);
      chainTail = out;

      update = (p) => {
        shaper.curve = makeDistortionCurve(p.drive);
        setAt(pre.gain, 1 + p.drive / 50);
        setAt(tone.frequency, p.tone);
        const m = clamp(p.mix / 100, 0, 1);
        setAt(wet.gain, m);
        setAt(dry.gain, 1 - m);
        setAt(out.gain, dbToGain(p.out));
      };
      break;
    }

    case 'reverb': {
      const split = track(ctx.createGain());
      const pre = track(ctx.createDelay(0.5));
      const conv = track(ctx.createConvolver());
      conv.normalize = true;
      const wet = track(ctx.createGain());
      const dry = track(ctx.createGain());
      const out = track(ctx.createGain());

      split.connect(pre).connect(conv).connect(wet).connect(out);
      split.connect(dry).connect(out);
      chainHead = split;
      chainTail = out;

      let lastIrKey = '';
      update = (p) => {
        const key = `${p.size.toFixed(2)}|${Math.round(p.damp)}`;
        if (key !== lastIrKey) {
          lastIrKey = key;
          conv.buffer = makeImpulseResponse(ctx, p.size, p.damp);
        }
        setAt(pre.delayTime, clamp(p.predelay / 1000, 0, 0.45));
        const m = clamp(p.mix / 100, 0, 1);
        setAt(wet.gain, m);
        setAt(dry.gain, 1 - m * 0.35);
        setAt(out.gain, 1);
      };
      break;
    }

    case 'delay': {
      const split = track(ctx.createGain());
      const delayL = track(ctx.createDelay(3));
      const fb = track(ctx.createGain());
      const damp = track(ctx.createBiquadFilter());
      damp.type = 'lowpass';
      const wet = track(ctx.createGain());
      const dry = track(ctx.createGain());
      const out = track(ctx.createGain());

      split.connect(delayL);
      delayL.connect(damp).connect(fb).connect(delayL); // Rueckkopplung
      delayL.connect(wet).connect(out);
      split.connect(dry).connect(out);
      chainHead = split;
      chainTail = out;

      update = (p) => {
        const bpm = env.getBpm ? env.getBpm() : 120;
        const time = p.sync >= 0.5 ? syncDelayTime(p.div, bpm) : p.time / 1000;
        setAt(delayL.delayTime, clamp(time, 0.001, 2.9));
        setAt(fb.gain, clamp(p.feedback / 100, 0, 0.94));
        setAt(damp.frequency, p.damp);
        const m = clamp(p.mix / 100, 0, 1);
        setAt(wet.gain, m);
        setAt(dry.gain, 1);
        setAt(out.gain, 1);
      };
      break;
    }

    case 'lowpass':
    case 'highpass': {
      const f = track(ctx.createBiquadFilter());
      f.type = type;
      chainHead = chainTail = f;
      update = (p) => {
        setAt(f.frequency, p.freq);
        setAt(f.Q, p.q);
      };
      break;
    }

    default: {
      const pass = track(ctx.createGain());
      chainHead = chainTail = pass;
      break;
    }
  }

  const wire = () => {
    try {
      input.disconnect();
    } catch (_) {
      /* egal */
    }
    if (enabled) {
      input.connect(chainHead);
      try {
        chainTail.disconnect();
      } catch (_) {
        /* egal */
      }
      chainTail.connect(output);
    } else {
      try {
        chainTail.disconnect();
      } catch (_) {
        /* egal */
      }
      input.connect(output);
    }
  };

  update(state.params);
  wire();

  return {
    id: state.id,
    type,
    input,
    output,
    update(params) {
      update(params);
    },
    setEnabled(v) {
      if (enabled === !!v) return;
      enabled = !!v;
      wire();
    },
    dispose() {
      for (const n of nodes) {
        try {
          n.disconnect();
        } catch (_) {
          /* egal */
        }
      }
      try {
        input.disconnect();
      } catch (_) {
        /* egal */
      }
      try {
        output.disconnect();
      } catch (_) {
        /* egal */
      }
      nodes.length = 0;
    },
  };
}

/**
 * Verkettet mehrere Effekte zwischen zwei Knoten.
 * Gibt eine Verwaltungsstruktur zurueck, die spaeter aktualisiert werden kann.
 */
export function buildChain(ctx, states, source, destination, env) {
  const effects = states.map((s) => createEffectNode(ctx, s, env));
  let prev = source;
  for (const fx of effects) {
    prev.connect(fx.input);
    prev = fx.output;
  }
  prev.connect(destination);
  return effects;
}
