/**
 * GrooveNet – Klangerzeugung.
 *
 * Baut aus einem Genom (genome.js) das eigentliche Audiosignal. Der Aufbau
 * entspricht einem vollstaendigen Synthesizer:
 *
 *   Anregung   Oszillatoren (mit Unisono und FM), Sub-Ton, gefaerbtes Rauschen
 *   Koerper    Modaler Resonator – bildet Fell, Metall oder Holz nach
 *   Formung    Zustandsvariablen-Filter mit Huellkurve und LFO
 *   Charakter  Saettigung, Wellenfalten, Bit- und Ratenreduktion
 *   Dynamik    Huellkurve und Transientenformer
 *   Raum       Nachhall und Stereobreite, tiefe Anteile bleiben mono
 *
 * Wichtig fuer die KI: Der Renderer ist deterministisch. Gleiches Genom und
 * gleicher Seed ergeben exakt dasselbe Signal – sonst koennte der Optimierer
 * keine Verbesserung messen.
 */

import { decode, rng } from './genome.js';

/** Teiltonverhaeltnisse: harmonisch (Saite) und metallisch (Becken/Glocke). */
const HARMONIC_RATIOS = [1, 2, 3, 4, 5, 6, 7, 8];
const METAL_RATIOS = [1, 1.4471, 1.617, 1.9265, 2.5028, 2.6637, 3.4453, 4.1132];

/** Zustandsvariablen-Filter (topologiegetreu, stabil auch bei Modulation). */
class SVF {
  constructor() {
    this.ic1 = 0;
    this.ic2 = 0;
    this.a1 = 0;
    this.a2 = 0;
    this.a3 = 0;
    this.k = 1;
  }

  setCoefficients(cutoffHz, q, sampleRate) {
    const fc = Math.min(sampleRate * 0.45, Math.max(15, cutoffHz));
    const g = Math.tan((Math.PI * fc) / sampleRate);
    const k = 1 / Math.max(0.35, q);
    const a1 = 1 / (1 + g * (g + k));
    this.a1 = a1;
    this.a2 = g * a1;
    this.a3 = g * this.a2;
    this.k = k;
  }

  /** Liefert Tief-, Band- und Hochpass gleichzeitig. */
  process(x) {
    const v3 = x - this.ic2;
    const v1 = this.a1 * this.ic1 + this.a2 * v3;
    const v2 = this.ic2 + this.a2 * this.ic1 + this.a3 * v3;
    this.ic1 = 2 * v1 - this.ic1;
    this.ic2 = 2 * v2 - this.ic2;
    this.lp = v2;
    this.bp = v1;
    this.hp = x - this.k * v1 - v2;
    return v2;
  }
}

/** Zwei-Pol-Resonator fuer einen einzelnen Teilton. */
class Mode {
  constructor(freq, decaySeconds, sampleRate) {
    const w = (2 * Math.PI * Math.min(freq, sampleRate * 0.48)) / sampleRate;
    const r = Math.exp(-1 / Math.max(1, decaySeconds * sampleRate));
    this.a1 = 2 * r * Math.cos(w);
    this.a2 = -r * r;
    this.gain = (1 - r) * Math.sin(w);
    this.y1 = 0;
    this.y2 = 0;
  }

  process(x) {
    const y = this.gain * x + this.a1 * this.y1 + this.a2 * this.y2;
    this.y2 = this.y1;
    this.y1 = y;
    return y;
  }
}

/** Einpol-Tiefpass, fuer Rauschfaerbung, Tilt-EQ und Huellkurvenverfolgung. */
function onePoleCoeff(hz, sampleRate) {
  return 1 - Math.exp((-2 * Math.PI * Math.min(hz, sampleRate * 0.45)) / sampleRate);
}

/**
 * Bandbegrenzte Saegezahn-Korrektur (PolyBLEP).
 * Ohne diese Korrektur entstehen bei hohen Toenen falsche, schrille
 * Nebenfrequenzen (Aliasing).
 */
function polyBlep(t, dt) {
  if (t < dt) {
    const x = t / dt;
    return x + x - x * x - 1;
  }
  if (t > 1 - dt) {
    const x = (t - 1) / dt;
    return x * x + x + x + 1;
  }
  return 0;
}

/** Wellenform-Morphing: Sinus -> Dreieck -> Saegezahn -> Rechteck. */
function waveform(phase, morph, dt) {
  if (morph < 0.34) {
    const t = morph / 0.34;
    const sine = Math.sin(2 * Math.PI * phase);
    const tri = 4 * Math.abs(phase - 0.5) - 1;
    return sine * (1 - t) + tri * t;
  }
  if (morph < 0.67) {
    const t = (morph - 0.34) / 0.33;
    const tri = 4 * Math.abs(phase - 0.5) - 1;
    const saw = 2 * phase - 1 - polyBlep(phase, dt);
    return tri * (1 - t) + saw * t;
  }
  const t = (morph - 0.67) / 0.33;
  const saw = 2 * phase - 1 - polyBlep(phase, dt);
  let square = phase < 0.5 ? 1 : -1;
  square += polyBlep(phase, dt);
  square -= polyBlep((phase + 0.5) % 1, dt);
  return saw * (1 - t) + square * t;
}

/**
 * Saettigungskennlinien: weich, gefaltet, hart begrenzt.
 *
 * Die Verstaerkung ist bewusst zurueckhaltend. Mit dem frueheren Faktor
 * (bis zu 12-fach) wurde jedes Signal oberhalb eines Zwoelftels der
 * Vollaussteuerung an die Grenze gedrueckt: Aus dem Abklingen einer Kick
 * wurde ein flaches Plateau ueber 200 ms – gemessen der Unterschied zwischen
 * einem Schlag und einer Explosion. Bis zum Sechsfachen bleibt die Dynamik
 * erhalten und es klingt trotzdem satt.
 */
function shape(x, drive, type) {
  const gain = 1 + drive * 6;
  const soft = Math.tanh(x * gain) / Math.tanh(gain);
  if (type < 0.5) {
    const t = type / 0.5;
    const folded = Math.sin(x * gain * 1.1);
    return soft * (1 - t) + folded * t;
  }
  const t = (type - 0.5) / 0.5;
  const folded = Math.sin(x * gain * 1.1);
  const hard = Math.max(-1, Math.min(1, x * gain * 0.8));
  return folded * (1 - t) + hard * t;
}

/**
 * Erzeugt das Mono-Signal eines Genoms.
 *
 * @param {Float32Array} gene
 * @param {object} opts
 * @param {number} opts.seconds
 * @param {number} opts.sampleRate
 * @param {number} [opts.seed]
 * @param {number} [opts.quality] 0.4 = schnell (Optimierung), 1 = voll
 * @param {number} [opts.pitchHz] Ueberschreibt die Tonhoehe (fuer Melodien)
 * @param {number} [opts.velocity] Anschlagstaerke 0..1
 * @returns {Float32Array}
 */
export function renderMono(gene, opts) {
  const p = decode(gene);
  const sr = opts.sampleRate;
  const quality = opts.quality ?? 1;
  const n = Math.max(16, Math.floor(opts.seconds * sr));
  const out = new Float32Array(n);
  const random = rng(opts.seed ?? 12345);
  const velocity = opts.velocity ?? 1;

  const basePitch = opts.pitchHz || p.pitch;

  // --- Stimmen ---------------------------------------------------------------
  const voices = Math.max(1, Math.min(quality < 0.7 ? 3 : 7, Math.round(p.unison)));
  const phases = new Float64Array(voices);
  const detunes = new Float64Array(voices);
  for (let v = 0; v < voices; v++) {
    phases[v] = voices > 1 ? random() : 0;
    // Symmetrische Verstimmung um den Grundton herum.
    const spread = voices > 1 ? (v / (voices - 1)) * 2 - 1 : 0;
    detunes[v] = 2 ** ((spread * p.detune) / 1200);
  }
  const voiceGain = 1 / Math.sqrt(voices);

  let phase2 = 0;
  let subPhase = 0;
  // Uebergang zwischen "Oktave tiefer" und "Grundton verstaerken",
  // weich zwischen 55 und 110 Hz.
  const subRatio = 0.5 + 0.5 * Math.min(1, Math.max(0, (110 - basePitch) / 55));

  // --- Modaler Koerper -------------------------------------------------------
  const modeCount = Math.max(1, Math.min(quality < 0.7 ? 4 : 8, Math.round(p.modalCount)));
  const modes = [];
  if (p.modalMix > 0.01) {
    const blend = Math.min(1, Math.max(0, (p.modalSpread - 1) / 2.2));
    for (let k = 0; k < modeCount; k++) {
      const ratio = HARMONIC_RATIOS[k] * (1 - blend) + METAL_RATIOS[k] * blend;
      const freq = p.modalFreq * ratio;
      if (freq > sr * 0.47) continue;
      // Hohe Teiltoene klingen schneller aus – so verhalten sich echte Koerper.
      modes.push(new Mode(freq, p.modalDecay / (1 + k * 0.35), sr));
    }
  }
  const modeGain = modes.length ? 1.6 / Math.sqrt(modes.length) : 0;

  // --- Filter und Rauschfaerbung ---------------------------------------------
  const filter = new SVF();
  const noiseBand = new SVF();
  noiseBand.setCoefficients(p.noiseBandFreq, p.noiseBandQ, sr);
  const noiseTiltCoeff = onePoleCoeff(200 + p.noiseColor * 6000, sr);
  let noiseLp = 0;

  const lpType = Math.min(1, Math.max(0, 1 - p.filterType * 2));
  const bpType = 1 - Math.abs(p.filterType - 0.5) * 2;
  const hpType = Math.min(1, Math.max(0, p.filterType * 2 - 1));
  const typeSum = lpType + bpType + hpType || 1;

  // --- Zeitkonstanten --------------------------------------------------------
  const attack = Math.max(1, p.attack * sr);
  const hold = p.hold * sr;
  const decay = Math.max(1, p.decay * sr);
  // Ausblendung am Ende.
  //
  // Sie darf nie den ganzen Klang umfassen: Ist die Release-Zeit laenger als
  // die Datei, wuerde aus ihr eine lineare Ausblendung von der ersten bis zur
  // letzten Probe – und die ueberdeckt jede Huellkurve. Gemessen wurden
  // Release-Zeiten von sechs Sekunden bei einem Kick von 0,77 Sekunden; das
  // Ergebnis war ein Klang, der gleichmaessig leiser wird statt abzuklingen.
  // Sie beginnt deshalb fruehestens nach Anschlag und Haltephase und deckt
  // hoechstens die hintere Haelfte ab.
  const releaseSamples = Math.min(p.release * sr, n * 0.55);
  const releaseStart = Math.max(attack + hold, n - releaseSamples);
  const releaseLen = Math.max(1, n - releaseStart);
  const noiseDecay = Math.max(1, p.noiseDecay * sr);
  const pitchEnvTime = Math.max(1, p.pitchEnvTime * sr);
  const filterEnvTime = Math.max(1, p.filterEnvTime * sr);
  const curve = p.envCurve;

  // Bit- und Ratenreduktion
  const levels = 2 ** Math.round(p.bitDepth);
  const holdSamples = Math.max(1, Math.round(1 + p.crush * 40));
  let held = 0;

  // LFO
  const lfoStep = p.lfoRate / sr;
  let lfoPhase = random() * 0.25;

  // Koeffizienten des Filters nur alle 32 Abtastwerte neu berechnen:
  // hoerbar identisch, aber deutlich schneller.
  const controlInterval = quality < 0.7 ? 64 : 32;

  for (let i = 0; i < n; i++) {
    const t = i / sr;

    // --- Huellkurven ---------------------------------------------------------
    let amp;
    if (i < attack) {
      const x = i / attack;
      amp = curve < 0.5 ? x * x * (1 - curve * 2) + x * curve * 2 : x;
    } else if (i < attack + hold) {
      amp = 1;
    } else {
      const d = (i - attack - hold) / decay;
      const expPart = Math.exp(-d * 3);
      const linPart = Math.max(0, 1 - d);
      const shaped = expPart * (1 - curve) + linPart * curve;
      amp = p.sustain + (1 - p.sustain) * shaped;
    }

    // --- LFO -----------------------------------------------------------------
    lfoPhase += lfoStep;
    if (lfoPhase >= 1) lfoPhase -= 1;
    const lfoSine = Math.sin(2 * Math.PI * lfoPhase);
    const lfoSquare = lfoPhase < 0.5 ? 1 : -1;
    const lfoRaw = lfoSine * (1 - p.lfoShape) + lfoSquare * p.lfoShape;
    // Optional nimmt die Modulation ueber die Zeit zu.
    const lfo = lfoRaw * (1 - p.lfoRamp + p.lfoRamp * (i / n));

    // --- Tonhoehe ------------------------------------------------------------
    const pitchEnv = 2 ** ((p.pitchEnvAmount / 12) * Math.exp(-i / pitchEnvTime));
    const vibrato = 2 ** ((lfo * p.lfoToPitch * 2) / 12);
    const freq = Math.min(sr * 0.48, basePitch * pitchEnv * vibrato);

    // --- Oszillatoren --------------------------------------------------------
    let osc = 0;
    if (p.oscLevel > 0.001) {
      // Zweiter Oszillator moduliert den ersten (Frequenzmodulation).
      const f2 = Math.min(sr * 0.48, freq * p.osc2Ratio);
      const dt2 = f2 / sr;
      phase2 += dt2;
      if (phase2 >= 1) phase2 -= 1;
      const mod = Math.sin(2 * Math.PI * phase2);

      for (let v = 0; v < voices; v++) {
        const fv = freq * detunes[v];
        const dt = fv / sr;
        phases[v] += dt;
        if (phases[v] >= 1) phases[v] -= 1;
        let ph = phases[v] + mod * p.fmIndex * 0.08;
        ph -= Math.floor(ph);
        osc += waveform(ph, p.wave, dt);
      }
      osc *= voiceGain * p.oscLevel;
      if (p.osc2Level > 0.001) osc += mod * p.osc2Level * 0.6;
    }

    if (p.subLevel > 0.001) {
      // Der Sub-Ton geht nur dann eine Oktave tiefer, wenn der Grundton hoch
      // genug liegt. Bei einer Kick auf 54 Hz laege die Oktave darunter bei
      // 27 Hz – das hoert niemand, es frisst nur Aussteuerungsreserve und
      // laesst den Klang duenn wirken. Unterhalb von 80 Hz verstaerkt der
      // Sub-Ton deshalb den Grundton selbst.
      subPhase += (freq * subRatio) / sr;
      if (subPhase >= 1) subPhase -= 1;
      osc += Math.sin(2 * Math.PI * subPhase) * p.subLevel;
    }

    // --- Rauschen ------------------------------------------------------------
    let noise = 0;
    if (p.noiseLevel > 0.001) {
      const white = random() * 2 - 1;
      noiseLp += noiseTiltCoeff * (white - noiseLp);
      // noiseColor blendet zwischen dumpfem und hellem Rauschen.
      const colored = noiseLp * (1 - p.noiseColor) + (white - noiseLp) * p.noiseColor;
      noiseBand.process(colored);
      const banded = noiseBand.bp * Math.min(4, p.noiseBandQ * 0.5) + colored * 0.5;
      const nEnv = p.noiseBurst * Math.exp(-i / noiseDecay) + (1 - p.noiseBurst);
      noise = banded * nEnv * p.noiseLevel;
    }

    // Die Huellkurve formt immer die Anregung, nie den Ausgang.
    //
    // Das entspricht dem Vorbild: Ein Instrument wird angeregt – geschlagen,
    // gezupft, angeblasen – und Koerper wie Filter klingen danach von selbst
    // aus. Legt man die Huellkurve stattdessen hinter den Resonator, klingt
    // jede Glocke wie ein langsam aufziehendes Pad.
    //
    // Frueher wurde hier nur anteilig gehuellt (nach Koerperanteil). Bei einer
    // Kick mit 25 % Koerper lief dadurch drei Viertel des Signals voellig
    // ungehuellt weiter: gemessen eine flache Huellkurve ueber 160 ms statt
    // eines Abklingens – der Klang schlug nicht, er knallte.
    // --- Charakter -----------------------------------------------------------
    // Saettigung wirkt auf das ungehuellte Signal.
    //
    // Sie zusammenzudruecken ist ihr Wesen: Alles oberhalb einer Schwelle
    // wandert an die Grenze. Steht sie hinter der Huellkurve, verschwindet
    // damit das Abklingen – gemessen wurde aus einem Kick-Abfall von 32 dB
    // ein Plateau von 4 dB. Vor der Huellkurve faerbt sie den Klang, ohne die
    // Dynamik anzutasten. Zusaetzlich bleibt der Oberton-Gehalt ueber die
    // ganze Dauer gleich, statt mit der Lautstaerke zu schwanken.
    let raw = osc + noise;
    if (p.drive > 0.01) raw = shape(raw, p.drive, p.driveType);
    if (p.filterDrive > 0.01) raw = Math.tanh(raw * (1 + p.filterDrive * 3));
    if (levels < 60000) raw = Math.round(raw * levels) / levels;
    if (holdSamples > 1) {
      if (i % holdSamples === 0) held = raw;
      raw = held;
    }

    const excitation = raw * amp;

    // --- Koerper -------------------------------------------------------------
    let body = 0;
    if (modes.length) {
      for (let k = 0; k < modes.length; k++) body += modes[k].process(excitation);
      body *= modeGain;
    }
    let sig = excitation * (1 - p.modalMix) + body * p.modalMix;

    // --- Filter --------------------------------------------------------------
    if (i % controlInterval === 0) {
      const envShape = Math.exp(-i / filterEnvTime);
      // Positive Huellkurve oeffnet das Filter, negative schliesst es.
      const envAmount = p.filterEnvAmount >= 0 ? 1 - envShape : envShape;
      const mod = envAmount * Math.abs(p.filterEnvAmount) * 5 + lfo * p.lfoToFilter * 2.5;
      filter.setCoefficients(p.filterCutoff * 2 ** mod, p.filterQ, sr);
    }
    filter.process(sig);
    sig = (filter.lp * lpType + filter.bp * bpType + filter.hp * hpType) / typeSum;

    // --- Dynamik -------------------------------------------------------------
    // Gehuellt wurde bereits die Anregung. Hier folgt nur noch die
    // Ausblendung zum Ende und die Modulation der Lautstaerke.
    const ampLfo = 1 - p.lfoToAmp * 0.5 * (1 - lfo);
    const releaseGain = i >= releaseStart ? Math.max(0, 1 - (i - releaseStart) / releaseLen) : 1;
    out[i] = sig * releaseGain * ampLfo * velocity;
  }

  applyTransientShaper(out, sr, p.transient);
  applyTilt(out, sr, p.tilt);
  removeDc(out, sr);
  applyFades(out, sr, opts.seconds);
  return out;
}

/**
 * Transientenformer: hebt den Anschlag an oder glaettet ihn.
 * Vergleicht eine schnelle mit einer langsamen Huellkurve – die Differenz
 * ist genau der Einschwingvorgang.
 */
function applyTransientShaper(data, sampleRate, amount) {
  if (Math.abs(amount) < 0.02) return;
  const fast = onePoleCoeff(400, sampleRate);
  const slow = onePoleCoeff(12, sampleRate);
  let envFast = 0;
  let envSlow = 0;
  for (let i = 0; i < data.length; i++) {
    const level = Math.abs(data[i]);
    envFast += fast * (level - envFast);
    envSlow += slow * (level - envSlow);
    const diff = envFast - envSlow;
    // Maßvoll: Der Formen soll den Anschlag betonen, nicht den Koerper
    // wegschneiden. Mit dem frueheren Bereich (bis 4-fach) fiel eine Kick
    // schon nach 60 ms um 22 dB ab und klang duenn statt satt.
    const gain = 1 + amount * diff * 4;
    data[i] *= Math.min(2.5, Math.max(0.3, gain));
  }
}

/** Neigungsentzerrer: verschiebt Energie zwischen Baessen und Hoehen. */
function applyTilt(data, sampleRate, amount) {
  if (Math.abs(amount) < 0.02) return;
  const c = onePoleCoeff(650, sampleRate);
  let lp = 0;
  for (let i = 0; i < data.length; i++) {
    lp += c * (data[i] - lp);
    const high = data[i] - lp;
    data[i] = lp * (1 - amount * 0.7) + high * (1 + amount * 0.9);
  }
}

/** Entfernt Gleichspannungsanteile, die sonst Pegel kosten. */
function removeDc(data, sampleRate) {
  const r = 1 - 20 / sampleRate;
  let x1 = 0;
  let y1 = 0;
  for (let i = 0; i < data.length; i++) {
    const x = data[i];
    y1 = x - x1 + r * y1;
    x1 = x;
    data[i] = y1;
  }
}

/**
 * Kurze Ein- und Ausblendung: verhindert Knacken an den Raendern.
 *
 * Die Einblendung ist bewusst sehr kurz (rund 0,2 ms). Alle Huellkurven
 * starten ohnehin bei null; noetig sind nur wenige Abtastwerte gegen einen
 * Sprung am Anfang. Frueher waren es 2 ms – und genau darin liegt der Klick
 * einer Kick oder Snare. Gemessen wanderte die Spitze dadurch von 2 ms auf
 * 100 ms, der Klang schwoll an, statt zu schlagen.
 */
function applyFades(data, sampleRate, seconds) {
  const inLen = Math.min(data.length >> 1, Math.max(4, Math.floor(sampleRate * 0.0002)));
  const outLen = Math.min(data.length >> 1, Math.floor(sampleRate * Math.min(0.02, seconds * 0.05)));
  for (let i = 0; i < inLen; i++) data[i] *= i / inLen;
  for (let i = 0; i < outLen; i++) data[data.length - 1 - i] *= i / outLen;
}

// --- Raum und Stereo ---------------------------------------------------------

/** Kammfilter mit Daempfung – Grundbaustein des Nachhalls. */
class Comb {
  constructor(length, feedback, damp) {
    this.buf = new Float32Array(length);
    this.pos = 0;
    this.feedback = feedback;
    this.damp = damp;
    this.store = 0;
  }

  process(x) {
    const y = this.buf[this.pos];
    this.store = y * (1 - this.damp) + this.store * this.damp;
    this.buf[this.pos] = x + this.store * this.feedback;
    this.pos = (this.pos + 1) % this.buf.length;
    return y;
  }
}

/** Allpass – verdichtet die Reflexionen, ohne den Klang zu faerben. */
class Allpass {
  constructor(length, feedback = 0.5) {
    this.buf = new Float32Array(length);
    this.pos = 0;
    this.feedback = feedback;
  }

  process(x) {
    const buffered = this.buf[this.pos];
    const y = -x + buffered;
    this.buf[this.pos] = x + buffered * this.feedback;
    this.pos = (this.pos + 1) % this.buf.length;
    return y;
  }
}

const COMB_TUNING = [1116, 1188, 1277, 1356, 1422, 1491];
const ALLPASS_TUNING = [556, 441, 341, 225];

/**
 * Nachhall und Stereobild.
 *
 * Tiefe Anteile bleiben bewusst in der Mitte: nur so bleibt der Klang auf
 * Mono-Anlagen und in Clubs stabil.
 *
 * @param {Float32Array} mono
 * @param {object} p Dekodierte Genom-Parameter
 * @param {number} sampleRate
 * @returns {[Float32Array, Float32Array]}
 */
export function spatialize(mono, p, sampleRate) {
  const n = mono.length;
  const left = new Float32Array(n);
  const right = new Float32Array(n);
  const scale = sampleRate / 44100;

  const wet = p.reverbAmount;
  const width = p.width;

  if (wet < 0.005 && width < 0.02) {
    left.set(mono);
    right.set(mono);
    return [left, right];
  }

  // --- Nachhall --------------------------------------------------------------
  let combsL = null;
  let combsR = null;
  let apL = null;
  let apR = null;
  let preDelayBuf = null;
  let preDelayPos = 0;
  let wetScale = 1;

  if (wet >= 0.005) {
    const feedback = 0.72 + p.reverbSize * 0.26;
    // Pegelausgleich fuer den Nachhall.
    //
    // Ein Kammfilter mit Rueckkopplung f verstaerkt im eingeschwungenen
    // Zustand um 1/(1-f); bei sechs davon summiert sich das auf ein
    // Vielfaches. Ohne Ausgleich uebertoent der Hall selbst bei 12 %
    // Hallanteil das Direktsignal – gemessen wanderte die Lautstaerkespitze
    // einer Kick dadurch von 0 ms auf 100 ms, der Schlag ging unter.
    wetScale = ((1 - feedback) * 1.7) / Math.sqrt(COMB_TUNING.length);
    const damp = 0.15 + p.reverbDamp * 0.7;
    combsL = COMB_TUNING.map((l) => new Comb(Math.max(8, Math.round(l * scale * (0.5 + p.reverbSize))), feedback, damp));
    combsR = COMB_TUNING.map((l) => new Comb(Math.max(8, Math.round((l + 23) * scale * (0.5 + p.reverbSize))), feedback, damp));
    apL = ALLPASS_TUNING.map((l) => new Allpass(Math.max(4, Math.round(l * scale))));
    apR = ALLPASS_TUNING.map((l) => new Allpass(Math.max(4, Math.round((l + 11) * scale))));
    const pre = Math.max(1, Math.round(p.preDelay * sampleRate));
    preDelayBuf = new Float32Array(pre);
  }

  // Haas-Verzoegerung fuer die Breite des Direktsignals.
  const haas = Math.max(0, Math.round(width * 0.0009 * sampleRate));
  const hpCoeff = onePoleCoeff(180, sampleRate);
  let lowL = 0;
  let lowR = 0;

  for (let i = 0; i < n; i++) {
    const dry = mono[i];

    let wetL = 0;
    let wetR = 0;
    if (combsL) {
      preDelayBuf[preDelayPos] = dry;
      preDelayPos = (preDelayPos + 1) % preDelayBuf.length;
      const input = preDelayBuf[preDelayPos];
      for (let c = 0; c < combsL.length; c++) {
        wetL += combsL[c].process(input);
        wetR += combsR[c].process(input);
      }
      for (let a = 0; a < apL.length; a++) {
        wetL = apL[a].process(wetL);
        wetR = apR[a].process(wetR);
      }
      wetL *= wetScale;
      wetR *= wetScale;
    }

    // Direktsignal breiter machen: rechter Kanal minimal versetzt.
    const delayed = i - haas >= 0 ? mono[i - haas] : 0;
    let l = dry;
    let r = dry * (1 - width * 0.35) + delayed * width * 0.35;

    l = l * (1 - wet * 0.45) + wetL * wet;
    r = r * (1 - wet * 0.45) + wetR * wet;

    // Tiefen zurueck in die Mitte holen.
    lowL += hpCoeff * (l - lowL);
    lowR += hpCoeff * (r - lowR);
    const lowMid = (lowL + lowR) * 0.5;
    left[i] = l - lowL + lowMid;
    right[i] = r - lowR + lowMid;
  }

  return [left, right];
}

/** Normiert auf einen Spitzenwert. Gibt den angewandten Faktor zurueck. */
export function normalize(channels, target = 0.9) {
  let peak = 0;
  for (const ch of channels) {
    for (let i = 0; i < ch.length; i++) {
      const v = Math.abs(ch[i]);
      if (v > peak) peak = v;
    }
  }
  if (peak < 1e-7) return 0;
  const f = target / peak;
  for (const ch of channels) for (let i = 0; i < ch.length; i++) ch[i] *= f;
  return f;
}

/**
 * Vollstaendiger Klang: Mono erzeugen, raeumlich aufbereiten, normieren.
 *
 * @returns {{channels: Float32Array[], sampleRate:number}}
 */
export function render(gene, opts) {
  const mono = renderMono(gene, opts);
  const p = decode(gene);
  const channels = opts.stereo === false ? [mono] : spatialize(mono, p, opts.sampleRate);
  normalize(channels, opts.target ?? 0.9);
  return { channels, sampleRate: opts.sampleRate };
}
