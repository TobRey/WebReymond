/**
 * GrooveNet – Genom der Klangerzeugung.
 *
 * Ein Klang wird durch 50 Zahlen zwischen 0 und 1 beschrieben – das Genom.
 * Der Synthesizer (synth.js) baut daraus das Audiosignal, der Optimierer
 * (optimizer.js) veraendert die Zahlen so lange, bis das Ergebnis zum Prompt
 * passt. Diese Datei ist die Uebersetzung zwischen beiden Welten:
 *
 *   PARAMS       Bedeutung, Wertebereich und Kennlinie jedes Gens.
 *   decode()     Genom -> echte physikalische Werte (Hertz, Sekunden, …).
 *   seedGenome() Prompt-Deutung -> plausibles Start-Genom.
 *
 * seedGenome ist der wichtigste Teil: Es uebersetzt die 16 Klangachsen des
 * Encoders direkt in Syntheseparameter. Der Optimierer startet dadurch nicht
 * im Zufall, sondern bereits nah am Ziel – das spart sehr viel Rechenzeit.
 */

/** Kennlinien: linear oder exponentiell (fuer Frequenzen und Zeiten). */
const LIN = 'lin';
const EXP = 'exp';

/** @type {Array<{name:string, min:number, max:number, curve:string}>} */
export const PARAMS = [
  // Oszillatoren
  { name: 'pitch', min: 20, max: 9000, curve: EXP },
  { name: 'pitchEnvAmount', min: 0, max: 48, curve: LIN }, // Halbtoene abwaerts
  { name: 'pitchEnvTime', min: 0.002, max: 0.6, curve: EXP },
  { name: 'wave', min: 0, max: 1, curve: LIN }, // Sinus -> Dreieck -> Saege -> Rechteck
  { name: 'oscLevel', min: 0, max: 1, curve: LIN },
  { name: 'osc2Ratio', min: 0.25, max: 12, curve: EXP },
  { name: 'osc2Level', min: 0, max: 1, curve: LIN },
  { name: 'fmIndex', min: 0, max: 14, curve: LIN },
  { name: 'detune', min: 0, max: 60, curve: LIN }, // Cent
  { name: 'unison', min: 1, max: 7, curve: LIN },
  { name: 'subLevel', min: 0, max: 1, curve: LIN },

  // Rauschen
  { name: 'noiseLevel', min: 0, max: 1, curve: LIN },
  { name: 'noiseColor', min: 0, max: 1, curve: LIN }, // dunkel -> hell
  { name: 'noiseBandFreq', min: 80, max: 16000, curve: EXP },
  { name: 'noiseBandQ', min: 0.4, max: 12, curve: EXP },
  { name: 'noiseDecay', min: 0.003, max: 6, curve: EXP },
  { name: 'noiseBurst', min: 0, max: 1, curve: LIN }, // 1 = nur Anschlag

  // Modaler Koerper (Resonanzen wie bei Fell, Metall, Holz)
  { name: 'modalMix', min: 0, max: 1, curve: LIN },
  { name: 'modalFreq', min: 40, max: 9000, curve: EXP },
  { name: 'modalSpread', min: 1, max: 3.2, curve: LIN }, // harmonisch -> metallisch
  { name: 'modalDecay', min: 0.01, max: 4, curve: EXP },
  { name: 'modalCount', min: 2, max: 8, curve: LIN },

  // Filter
  { name: 'filterType', min: 0, max: 1, curve: LIN }, // Tief -> Band -> Hoch
  { name: 'filterCutoff', min: 40, max: 18000, curve: EXP },
  { name: 'filterEnvAmount', min: -1, max: 1, curve: LIN },
  { name: 'filterEnvTime', min: 0.005, max: 2.5, curve: EXP },
  { name: 'filterQ', min: 0.5, max: 14, curve: EXP },
  { name: 'filterDrive', min: 0, max: 1, curve: LIN },

  // Huellkurve
  { name: 'attack', min: 0.0004, max: 2.5, curve: EXP },
  { name: 'hold', min: 0, max: 0.6, curve: LIN },
  { name: 'decay', min: 0.005, max: 8, curve: EXP },
  { name: 'sustain', min: 0, max: 1, curve: LIN },
  { name: 'release', min: 0.005, max: 6, curve: EXP },
  { name: 'envCurve', min: 0, max: 1, curve: LIN }, // exponentiell -> linear

  // Modulation
  { name: 'lfoRate', min: 0.05, max: 30, curve: EXP },
  { name: 'lfoToPitch', min: 0, max: 1, curve: LIN },
  { name: 'lfoToFilter', min: 0, max: 1, curve: LIN },
  { name: 'lfoToAmp', min: 0, max: 1, curve: LIN },
  { name: 'lfoShape', min: 0, max: 1, curve: LIN }, // Sinus -> Rechteck
  { name: 'lfoRamp', min: 0, max: 1, curve: LIN }, // Modulation nimmt zu

  // Saettigung und Charakter
  { name: 'drive', min: 0, max: 1, curve: LIN },
  { name: 'driveType', min: 0, max: 1, curve: LIN }, // weich -> Falten -> hart
  { name: 'bitDepth', min: 2, max: 16, curve: LIN },
  { name: 'crush', min: 0, max: 1, curve: LIN }, // Abtastratenreduktion

  // Raum und Stereo
  { name: 'reverbAmount', min: 0, max: 1, curve: LIN },
  { name: 'reverbSize', min: 0.05, max: 1, curve: LIN },
  { name: 'reverbDamp', min: 0, max: 1, curve: LIN },
  { name: 'preDelay', min: 0, max: 0.12, curve: LIN },
  { name: 'width', min: 0, max: 1, curve: LIN },

  // Nachbearbeitung
  { name: 'transient', min: -1, max: 1, curve: LIN }, // weicher <-> knackiger
  { name: 'tilt', min: -1, max: 1, curve: LIN }, // dunkler <-> heller
];

export const GENE_COUNT = PARAMS.length;
export const PARAM_INDEX = Object.fromEntries(PARAMS.map((p, i) => [p.name, i]));

const clamp01 = (v) => (v < 0 ? 0 : v > 1 ? 1 : v);

for (const p of PARAMS) {
  if (p.curve === EXP && p.min <= 0) {
    throw new Error(`Parameter ${p.name}: exponentielle Kennlinie braucht ein Minimum groesser 0.`);
  }
}

/** Genom (0..1) in physikalische Werte uebersetzen. */
export function decode(gene) {
  // Eine exponentielle Kennlinie ab 0 waere nicht definiert – hier faellt das
  // sofort auf, statt sich spaeter als stummes NaN im Audio zu zeigen.
  const out = {};
  for (let i = 0; i < PARAMS.length; i++) {
    const p = PARAMS[i];
    const x = clamp01(gene[i]);
    out[p.name] = p.curve === EXP ? p.min * (p.max / p.min) ** x : p.min + (p.max - p.min) * x;
  }
  return out;
}

/** Einzelnen Parameter eines Genoms dekodieren. */
export function decodeOne(name, gene) {
  const p = PARAMS[PARAM_INDEX[name]];
  const x = clamp01(gene[PARAM_INDEX[name]]);
  return p.curve === EXP ? p.min * (p.max / p.min) ** x : p.min + (p.max - p.min) * x;
}

/** Physikalischen Wert in die Genom-Skala zurueckrechnen. */
export function encodeParam(name, value) {
  const i = PARAM_INDEX[name];
  const p = PARAMS[i];
  const v = Math.min(p.max, Math.max(p.min, value));
  return p.curve === EXP ? Math.log(v / p.min) / Math.log(p.max / p.min) : (v - p.min) / (p.max - p.min);
}

/** Deterministischer Zufallsgenerator (xorshift32). */
export function rng(seed) {
  let a = (seed >>> 0) || 0x9e3779b9;
  return () => {
    a ^= a << 13;
    a >>>= 0;
    a ^= a >>> 17;
    a ^= a << 5;
    a >>>= 0;
    return a / 4294967296;
  };
}

/** Normalverteilter Zufall (Box-Muller), fuer Streuung um das Start-Genom. */
export function gauss(random) {
  const u = Math.max(1e-9, random());
  const v = random();
  return Math.sqrt(-2 * Math.log(u)) * Math.cos(2 * Math.PI * v);
}

/**
 * Erzeugt ein Start-Genom aus der Prompt-Deutung.
 *
 * Hier steckt das eigentliche Klangwissen: Welche Kick klingt "dunkel und
 * wuchtig"? Welche Hi-Hat ist "metallisch"? Die 16 Klangachsen werden auf
 * konkrete Syntheseparameter abgebildet, danach je nach Klangart nachgeschaerft.
 *
 * @param {object} intent Ergebnis von encoder.encode()
 * @param {() => number} random
 * @param {number} [spread] Streuung fuer Varianten (0 = exakt der Mittelwert)
 * @returns {Float32Array}
 */
export function seedGenome(intent, random, spread = 0) {
  const d = intent.dimsByName;
  const g = new Float32Array(GENE_COUNT);
  const set = (name, value) => {
    g[PARAM_INDEX[name]] = clamp01(value);
  };
  const hz = (name, value) => set(name, encodeParam(name, value));
  const sec = hz;

  const bright = d.brightness;
  const weight = d.weight;
  const dense = d.density;
  const rough = d.roughness;
  const trans = d.transient;
  const sus = d.sustain;
  const noisy = d.noisiness;
  const harm = d.harmonicity;
  const motion = d.motion;
  const width = d.width;
  const space = d.space;
  const drive = d.drive;
  const metal = d.metallic;
  const warm = d.warmth;
  const drop = d.pitchDrop;
  const cx = d.complexity;

  const seconds = intent.durationSeconds || 1;
  const family = intent.family;

  // --- Grundtonhoehe ---------------------------------------------------------
  // Tonale Klaenge folgen der Tonart, Perkussion der Gewichtsachse.
  let baseHz;
  if (intent.root != null && (family === 'tonal' || intent.archetype === 'tom')) {
    baseHz = 440 * 2 ** ((intent.root - 69) / 12);
  } else if (family === 'tonal') {
    baseHz = 55 * 2 ** ((1 - weight) * 4.2);
  } else {
    // Perkussion: von 35 Hz (Kick) bis 9 kHz (Hi-Hat).
    baseHz = 35 * 2 ** ((1 - weight) * 0.6 + bright * 6.6);
  }
  hz('pitch', baseHz);

  // --- Oszillatoren ----------------------------------------------------------
  // Wellenform: dunkel und tonal -> Sinus; hell, dicht und rau -> Saege/Rechteck.
  set('wave', clamp01(bright * 0.55 + rough * 0.3 + dense * 0.25 - harm * 0.2));
  set('oscLevel', clamp01(0.35 + harm * 0.6 - noisy * 0.35));
  set('osc2Level', clamp01(cx * 0.55 + metal * 0.3 - 0.15));
  // Verhaeltnis der zweiten Stimme: harmonisch bei tonalen Klaengen,
  // unharmonisch, sobald es metallisch oder glockig werden soll.
  const ratio = metal > 0.5 ? 1.41 + metal * 2.4 + cx * 0.8 : 1 + Math.round(cx * 3) * (harm > 0.6 ? 1 : 0.5);
  hz('osc2Ratio', ratio);
  set('fmIndex', clamp01(metal * 0.5 + cx * 0.35 + rough * 0.2 - harm * 0.25));
  set('detune', clamp01(width * 0.5 + cx * 0.35 + motion * 0.2));
  set('unison', clamp01(dense * 0.7 + width * 0.4 - 0.15));
  set('subLevel', clamp01(weight * 0.9 - bright * 0.35));

  // --- Rauschanteil ----------------------------------------------------------
  set('noiseLevel', clamp01(noisy * 1.05 - harm * 0.25));
  set('noiseColor', clamp01(bright * 0.85 + 0.1));
  hz('noiseBandFreq', 120 * 2 ** (bright * 6.6 + metal * 0.8));
  hz('noiseBandQ', 0.5 + metal * 5 + (1 - noisy) * 2.5);
  sec('noiseDecay', Math.max(0.004, seconds * (0.05 + sus * 0.75) * (0.4 + noisy * 0.8)));
  set('noiseBurst', clamp01(trans * 0.8 - sus * 0.4 + 0.1));

  // --- Koerper / Resonanzen --------------------------------------------------
  set('modalMix', clamp01(metal * 0.7 + (family === 'drum' ? 0.25 : 0) + cx * 0.2 - harm * 0.15));
  hz('modalFreq', baseHz * (family === 'drum' ? 2.2 + metal * 4 : 1 + metal * 2));
  set('modalSpread', clamp01(metal * 0.8 + (1 - harm) * 0.3));
  sec('modalDecay', Math.max(0.02, seconds * (0.08 + sus * 0.6) * (0.5 + metal)));
  set('modalCount', clamp01(cx * 0.7 + metal * 0.4));

  // --- Filter ----------------------------------------------------------------
  // Tiefpass fuer dunkle und schwere Klaenge, Hochpass fuer luftige.
  set('filterType', clamp01(bright * 0.55 + (1 - weight) * 0.3 - 0.12));
  hz('filterCutoff', 90 * 2 ** (bright * 7.2 + (1 - weight) * 1.4));
  // Fallende Filterhuellkurve bei perkussiven Klaengen: das Filter startet
  // offen und schliesst sich – so entsteht der typische Anschlag. Riser und
  // Sweeps drehen das um und oeffnen ueber die ganze Laenge (Regel unten).
  // Negative Werte schliessen, positive oeffnen.
  set('filterEnvAmount', clamp01(0.5 - trans * 0.45));
  sec('filterEnvTime', Math.max(0.006, seconds * (0.1 + sus * 0.55)));
  hz('filterQ', 0.7 + rough * 3 + metal * 3.5 + motion * 2);
  set('filterDrive', clamp01(drive * 0.7 + rough * 0.3));

  // --- Huellkurve ------------------------------------------------------------
  // Anschlagzeit exponentiell: von 0,8 ms (sehr knackig) bis rund 1,6 s
  // (weich einschwebend). Linear waere der hoerbare Bereich viel zu grob.
  const attack = Math.min(seconds * 0.5, 0.0008 * 2 ** ((1 - trans) * 11));
  sec('attack', Math.max(0.0005, attack));
  sec('hold', Math.max(0, seconds * trans * 0.02));
  sec('decay', Math.max(0.006, seconds * (0.12 + sus * 0.85)));
  set('sustain', clamp01(sus * 1.1 - trans * 0.35));
  sec('release', Math.max(0.006, seconds * (0.08 + sus * 0.5 + space * 0.2)));
  set('envCurve', clamp01(0.15 + sus * 0.6 - trans * 0.25));

  // --- Modulation ------------------------------------------------------------
  // Schnelle Modulation bei kurzen Klaengen, langsame bei Flaechen.
  hz('lfoRate', 0.12 * 2 ** (motion * 5.5 + (1 - sus) * 3.2));
  set('lfoToPitch', clamp01(motion * 0.5 - harm * 0.25));
  set('lfoToFilter', clamp01(motion * 0.85));
  set('lfoToAmp', clamp01(motion * 0.5 - 0.1));
  set('lfoShape', clamp01(rough * 0.5 + (1 - harm) * 0.25));
  set('lfoRamp', clamp01(motion * 0.4 + sus * 0.3));

  // --- Saettigung ------------------------------------------------------------
  set('drive', clamp01(drive * 0.95 + rough * 0.25 - 0.08));
  set('driveType', clamp01(rough * 0.6 + metal * 0.3));
  set('bitDepth', clamp01(1 - rough * 0.55 - metal * 0.15));
  set('crush', clamp01(rough * 0.45 - warm * 0.3));

  // --- Raum ------------------------------------------------------------------
  // Neutral bedeutet fast trocken: Hall entsteht erst, wenn er gewuenscht ist.
  set('reverbAmount', clamp01(space * 1.3 - 0.5 - (family === 'drum' ? 0.12 : 0)));
  set('reverbSize', clamp01(space * 0.75 + sus * 0.25));
  set('reverbDamp', clamp01(0.65 - bright * 0.5 + warm * 0.3));
  set('preDelay', clamp01(space * 0.5));
  set('width', clamp01(width));

  // --- Nachbearbeitung -------------------------------------------------------
  set('transient', clamp01(0.5 + (trans - 0.5) * 0.9));
  set('tilt', clamp01(0.5 + (bright - 0.5) * 0.85 - (warm - 0.5) * 0.25));

  // --- Tonhoehenverlauf ------------------------------------------------------
  set('pitchEnvAmount', clamp01(drop * 0.85));
  sec('pitchEnvTime', Math.max(0.003, seconds * (0.02 + drop * 0.12)));

  applyArchetypeRules(g, intent, set, hz, sec, seconds);

  // --- Koerperbetonte Klaenge -------------------------------------------------
  // Wo ein Resonator den Ausklang traegt (Glocke, Becken, offene Hi-Hat),
  // muss die Anregung kurz bleiben. Sonst schaukelt sich der Resonator ueber
  // die ganze Laenge auf und der Anschlag verschwindet.
  const modalMix = g[PARAM_INDEX.modalMix];
  if (modalMix > 0.45) {
    const shorten = (modalMix - 0.45) / 0.55;
    const decayNow = decodeOne('decay', g);
    // Je koerperbetonter, desto kuerzer der Anschlag: von der urspruenglichen
    // Laenge herunter bis auf wenige Millisekunden bei einer Glocke.
    sec('decay', Math.max(0.006, Math.min(decayNow, 0.01 + (1 - shorten) ** 2 * 1.2)));
    set('sustain', g[PARAM_INDEX.sustain] * (1 - shorten) ** 2);
    // Auch das Rauschen muss ein Anschlag bleiben. Dauerrauschen in einen
    // lang klingenden Resonator schaukelt sich ueber Sekunden auf – der
    // Klang wuerde immer lauter statt auszuklingen.
    set('noiseBurst', Math.max(g[PARAM_INDEX.noiseBurst], 0.8 + shorten * 0.2));
    sec('noiseDecay', Math.min(decodeOne('noiseDecay', g), 0.02 + (1 - shorten) ** 2 * 0.8));
    // Der Nachklang wandert in den Resonator.
    sec('modalDecay', Math.max(decodeOne('modalDecay', g), seconds * (0.25 + d.sustain * 0.7)));
  }

  // --- Streuung fuer Varianten ----------------------------------------------
  if (spread > 0) {
    for (let i = 0; i < GENE_COUNT; i++) {
      g[i] = clamp01(g[i] + gauss(random) * spread);
    }
  }
  return applyBounds(g, intent);
}

/**
 * Feinschliff je Klangart.
 *
 * Manche Klaenge folgen festen physikalischen Regeln, die sich nicht sinnvoll
 * aus allgemeinen Achsen ableiten lassen – etwa der schnelle Tonhoehensturz
 * einer Kick oder die sechs unharmonischen Teiltoene einer 808-Hi-Hat.
 */
function applyArchetypeRules(g, intent, set, hz, sec, seconds) {
  const d = intent.dimsByName;
  switch (intent.archetype) {
    case 'kick':
      hz('pitch', 46 + (1 - d.weight) * 55);
      set('pitchEnvAmount', clamp01(0.45 + d.transient * 0.4));
      sec('pitchEnvTime', 0.012 + (1 - d.transient) * 0.05);
      set('wave', 0.05);
      set('subLevel', clamp01(0.55 + d.weight * 0.4));
      set('noiseBurst', 1);
      sec('noiseDecay', 0.004 + (1 - d.transient) * 0.02);
      set('noiseLevel', clamp01(d.transient * 0.35));
      set('modalMix', clamp01(d.metallic * 0.3));
      // Der Tiefpass darf den Anschlag nicht wegschneiden.
      hz('filterCutoff', 900 * 2 ** (d.brightness * 3.4));
      set('filterType', 0);
      break;

    case 'sub':
      hz('pitch', intent.root != null ? 440 * 2 ** ((intent.root - 69) / 12) : 38 + (1 - d.weight) * 30);
      set('wave', 0.02);
      set('noiseLevel', 0);
      set('modalMix', 0);
      set('subLevel', 0.2);
      set('filterType', 0);
      break;

    case 'snare':
    case 'clap':
      set('noiseLevel', clamp01(0.72 + d.noisiness * 0.28));
      set('noiseBurst', clamp01(0.55 + d.transient * 0.4));
      hz('noiseBandFreq', 900 * 2 ** (d.brightness * 2.6));
      set('modalMix', clamp01(intent.archetype === 'snare' ? 0.45 : 0.12));
      hz('modalFreq', 170 * 2 ** (d.brightness * 1.2));
      set('oscLevel', intent.archetype === 'snare' ? 0.35 : 0.05);
      break;

    case 'hat':
    case 'openhat':
    case 'cymbal':
      // Klassisches Prinzip: sechs unharmonisch gestimmte Rechtecke plus Rauschen.
      set('modalMix', clamp01(0.55 + d.metallic * 0.4));
      set('modalSpread', clamp01(0.75 + d.metallic * 0.25));
      set('modalCount', 1);
      hz('modalFreq', 2600 * 2 ** (d.brightness * 1.6));
      set('oscLevel', 0.12);
      set('noiseLevel', clamp01(0.55 + d.noisiness * 0.35));
      set('filterType', clamp01(0.82 + d.brightness * 0.18));
      hz('filterCutoff', 3200 * 2 ** (d.brightness * 2.2));
      break;

    case 'tom':
      set('pitchEnvAmount', clamp01(0.25 + d.pitchDrop * 0.3));
      sec('pitchEnvTime', 0.05 + (1 - d.transient) * 0.08);
      set('wave', 0.12);
      set('modalMix', 0.4);
      break;

    case 'rim':
    case 'perc':
      set('noiseBurst', 1);
      set('modalMix', clamp01(0.45 + d.metallic * 0.4));
      sec('modalDecay', 0.03 + d.sustain * 0.4);
      break;

    case 'riser':
    case 'sweep':
      // Aufsteigende Bewegung: Filter oeffnet ueber die gesamte Laenge.
      set('filterEnvAmount', 1);
      sec('filterEnvTime', seconds * 0.9);
      sec('attack', seconds * 0.85);
      sec('decay', seconds);
      set('sustain', 1);
      set('noiseLevel', clamp01(0.6 + d.noisiness * 0.4));
      set('filterType', 0.5);
      hz('filterQ', 3 + d.metallic * 6);
      set('pitchEnvAmount', 0);
      break;

    case 'impact':
      set('pitchEnvAmount', clamp01(0.55 + d.pitchDrop * 0.35));
      sec('pitchEnvTime', 0.08 + seconds * 0.05);
      hz('pitch', 60 + (1 - d.weight) * 60);
      set('reverbAmount', clamp01(0.55 + d.space * 0.45));
      set('noiseLevel', clamp01(0.4 + d.noisiness * 0.4));
      break;

    case 'pad':
    case 'drone':
    case 'texture':
      sec('attack', Math.min(seconds * 0.35, Math.max(0.05, seconds * (0.12 + (1 - d.transient) * 0.3))));
      set('sustain', 1);
      sec('decay', seconds);
      sec('release', seconds * 0.5);
      set('unison', clamp01(0.45 + d.density * 0.5));
      set('detune', clamp01(0.25 + d.width * 0.5));
      break;

    case 'bell':
      set('modalMix', 0.85);
      set('modalSpread', clamp01(0.6 + d.metallic * 0.4));
      set('modalCount', 0.8);
      sec('modalDecay', seconds * 0.8);
      set('noiseLevel', clamp01(d.noisiness * 0.2));
      set('fmIndex', clamp01(0.3 + d.metallic * 0.35));
      break;

    case 'pluck':
    case 'lead':
    case 'stab':
    case 'chord':
      set('noiseLevel', clamp01(d.noisiness * 0.3));
      set('oscLevel', 0.85);
      set('unison', clamp01(0.3 + d.density * 0.55));
      break;

    case 'vocal':
      // Formantartiger Klang: schmale Resonanzen auf einem obertonreichen Ton.
      set('modalMix', 0.6);
      set('modalSpread', 0.35);
      set('modalCount', 0.35);
      hz('modalFreq', 620 * 2 ** (d.brightness * 1.1));
      set('wave', 0.72);
      set('lfoToPitch', clamp01(0.15 + d.motion * 0.25));
      hz('lfoRate', 5.2);
      break;

    default:
      break;
  }
}


/**
 * Leitplanken je Klangart, in echten Einheiten (Hertz, Sekunden, Halbtoene).
 *
 * Der Optimierer sucht frei – und findet ohne Grenzen Loesungen, die auf dem
 * Papier gut zum Ziel passen, aber musikalisch unbrauchbar sind. Gemessen
 * wurde genau das: Bei einer Kick landete der Grundton am unteren Anschlag von
 * 20 Hz. Rechnerisch stimmte die Energieverteilung, hoerbar war es ein
 * unhoerbares Rumpeln statt einer Trommel.
 *
 * Diese Grenzen bilden ab, was eine Klangart ausmacht. Eine Kick hat einen
 * Grundton zwischen 42 und 70 Hz und einen kurzen Tonhoehensturz von oben –
 * das ist keine Geschmacksfrage, sondern die Bauart des Instruments.
 *
 * Ausdrueckliche Wuensche im Prompt ("verzerrt", "rauschig") weiten die
 * passende Grenze wieder auf, siehe applyBounds().
 */
export const ARCHETYPE_BOUNDS = {
  kick: {
    sustain: [0, 0.06],
    pitch: [45, 72],
    pitchEnvAmount: [12, 28],
    pitchEnvTime: [0.008, 0.07],
    noiseLevel: [0, 0.3],
    noiseDecay: [0.002, 0.035],
    drive: [0, 0.4],
    subLevel: [0.45, 1],
    wave: [0, 0.3],
    filterCutoff: [2500, 14000],
    modalMix: [0, 0.25],
    attack: [0.0004, 0.005],
    decay: [0.16, 0.6],
    reverbAmount: [0, 0.25],
  },
  sub: {
    pitch: [28, 95],
    pitchEnvAmount: [0, 10],
    noiseLevel: [0, 0.06],
    wave: [0, 0.22],
    drive: [0, 0.45],
    modalMix: [0, 0.1],
    attack: [0.0008, 0.03],
    reverbAmount: [0, 0.25],
  },
  bass: {
    pitch: [30, 170],
    pitchEnvAmount: [0, 12],
    noiseLevel: [0, 0.22],
    subLevel: [0.25, 1],
    modalMix: [0, 0.3],
    attack: [0.0005, 0.05],
    reverbAmount: [0, 0.4],
  },
  snare: {
    sustain: [0, 0.06],
    decay: [0.09, 0.5],
    pitch: [120, 340],
    noiseLevel: [0.4, 0.92],
    // Eine Snare ist Fell UND Teppich. Ohne Untergrenze fuer Klangkoerper und
    // Grundton bleibt nur das Rauschen uebrig – gemessen 99,5 % der Energie
    // oberhalb 1 kHz, also reines Zischen ohne Trommel darunter.
    oscLevel: [0.22, 1],
    modalMix: [0.25, 0.7],
    filterCutoff: [1200, 14000],
    subLevel: [0, 0.25],
    attack: [0.0004, 0.006],
    pitchEnvAmount: [0, 14],
  },
  clap: {
    sustain: [0, 0.06],
    decay: [0.1, 0.5],
    pitch: [200, 900],
    noiseLevel: [0.6, 1],
    // Ein Clap sitzt in den Mitten, nicht in den Hoehen: der Klang entsteht
    // im Raum zwischen 800 Hz und 3 kHz.
    filterCutoff: [900, 7000],
    noiseBandFreq: [700, 3500],
    subLevel: [0, 0.12],
    modalMix: [0, 0.35],
    attack: [0.0004, 0.008],
  },
  rim: { sustain: [0, 0.05], pitch: [200, 1400], noiseLevel: [0.2, 0.8], subLevel: [0, 0.1], decay: [0.01, 0.3] },
  tom: { decay: [0.12, 0.7], sustain: [0, 0.06], pitch: [70, 260], pitchEnvAmount: [3, 16], noiseLevel: [0, 0.3], subLevel: [0.1, 0.7] },
  perc: { decay: [0.04, 0.35], sustain: [0, 0.06], pitch: [150, 3000], noiseLevel: [0.1, 0.8], subLevel: [0, 0.2] },
  hat: {
    sustain: [0, 0.04],
    pitch: [2500, 9000],
    noiseLevel: [0.3, 1],
    subLevel: [0, 0.04],
    filterCutoff: [2500, 18000],
    decay: [0.008, 0.25],
    pitchEnvAmount: [0, 4],
  },
  openhat: {
    sustain: [0, 0.08],
    pitch: [2500, 9000],
    noiseLevel: [0.3, 1],
    subLevel: [0, 0.04],
    filterCutoff: [2000, 18000],
    pitchEnvAmount: [0, 4],
  },
  cymbal: { sustain: [0, 0.08], pitch: [2000, 9000], noiseLevel: [0.3, 1], subLevel: [0, 0.04], filterCutoff: [1500, 18000] },
  bell: { sustain: [0, 0.06], pitch: [200, 4000], noiseLevel: [0, 0.25], subLevel: [0, 0.15], modalMix: [0.6, 1] },
  impact: { sustain: [0, 0.25], pitch: [35, 120], pitchEnvAmount: [8, 30], subLevel: [0.4, 1], noiseLevel: [0.1, 0.7] },
  riser: { noiseLevel: [0.35, 1], subLevel: [0, 0.2], pitchEnvAmount: [0, 4] },
  sweep: { noiseLevel: [0.4, 1], subLevel: [0, 0.2], pitchEnvAmount: [0, 4] },
  pad: { pitch: [90, 900], noiseLevel: [0, 0.3], pitchEnvAmount: [0, 3], attack: [0.03, 2.5] },
  drone: { pitch: [40, 500], noiseLevel: [0, 0.45], pitchEnvAmount: [0, 3] },
  lead: { pitch: [150, 1800], noiseLevel: [0, 0.25], pitchEnvAmount: [0, 6] },
  pluck: { sustain: [0, 0.15], pitch: [100, 1400], noiseLevel: [0, 0.3], pitchEnvAmount: [0, 6] },
  stab: { sustain: [0, 0.3], pitch: [100, 1000], noiseLevel: [0, 0.25], pitchEnvAmount: [0, 6] },
  chord: { pitch: [100, 900], noiseLevel: [0, 0.2], pitchEnvAmount: [0, 4] },
  vocal: { pitch: [90, 500], noiseLevel: [0, 0.35], pitchEnvAmount: [0, 5] },
  texture: { pitchEnvAmount: [0, 4] },
};

/**
 * Beschraenkt ein Genom auf die Leitplanken seiner Klangart.
 *
 * @param {Float32Array} gene wird an Ort und Stelle geaendert
 * @param {object} intent
 * @returns {Float32Array}
 */
export function applyBounds(gene, intent) {
  const table = ARCHETYPE_BOUNDS[intent.archetype];
  const d = intent.dimsByName;

  // Regeln, die fuer eine ganze Familie gelten – unabhaengig davon, ob die
  // Klangart den Parameter selbst auffuehrt. Ohne diesen Zusammenbau griffen
  // sie nur bei den Klangarten, die den Parameter ohnehin schon nannten; eine
  // Snare kam so auf 36 % Hallanteil, obwohl die Regel 12 % vorsah.
  const effektiv = { ...(table || {}) };
  if (intent.family === 'drum') {
    // Geschlagene Klaenge: wenig Saettigung, wenig Hall, kein Haltesegment.
    // Alles drei schiebt sonst die Lautstaerkespitze hinter den Anschlag.
    //
    // "hold" haelt die Huellkurve auf vollem Pegel, bevor sie abklingt.
    // Gemessen wurden 213 ms bei einem Kick – eine Fuenftelsekunde
    // gleichbleibend laut, also genau das Gegenteil eines Schlags. Bei
    // Perkussion sind wenige Millisekunden das Aeusserste.
    effektiv.hold = [0, 0.012];
    // Ebenso die Ausblendung: Sie gehoert ans Ende, nicht ueber den ganzen
    // Klang.
    effektiv.release = [0.005, Math.min(effektiv.release?.[1] ?? 1, 0.25)];
    if (d.drive <= 0.72) effektiv.drive = [0, Math.min(effektiv.drive?.[1] ?? 1, 0.45)];
    if (d.space <= 0.7) effektiv.reverbAmount = [0, Math.min(effektiv.reverbAmount?.[1] ?? 1, 0.12)];
    if (d.sustain <= 0.85) effektiv.sustain = [0, Math.min(effektiv.sustain?.[1] ?? 1, 0.1)];
  }
  if (!Object.keys(effektiv).length) return gene;

  for (const [name, range] of Object.entries(effektiv)) {
    const index = PARAM_INDEX[name];
    if (index == null) continue;

    let [lo, hi] = range;

    // Ausdrueckliche Wuensche heben die passende Grenze auf: Wer "verzerrt"
    // schreibt, soll Verzerrung bekommen, auch bei einer Kick.
    if (name === 'drive' && d.drive > 0.72) hi = 1;
    if (name === 'noiseLevel' && d.noisiness > 0.78) hi = 1;
    if (name === 'reverbAmount' && d.space > 0.75) hi = 1;
    if (name === 'decay' && d.sustain > 0.8) hi = Math.max(hi, PARAMS[index].max);
    // Ein geschlagener Klang hat kein Haltesegment: er klingt vom ersten
    // Moment an aus. Nur ein ausdruecklich langer Klang darf laenger stehen.
    if (name === 'sustain' && d.sustain > 0.85) hi = Math.min(1, hi + 0.25);
    // Eine genannte Tonart schlaegt jede Tonhoehengrenze.
    if (name === 'pitch' && intent.root != null && intent.family !== 'drum') continue;

    const loGene = encodeParam(name, lo);
    const hiGene = encodeParam(name, Math.max(lo, hi));
    if (gene[index] < loGene) gene[index] = loGene;
    else if (gene[index] > hiGene) gene[index] = hiGene;
  }
  return gene;
}

/** Erzeugt einen Nachkommen aus drei Eltern (Differentielle Evolution). */
export function differentialMix(base, a, b, factor, crossRate, random, out) {
  const dst = out || new Float32Array(GENE_COUNT);
  // Mindestens ein Gen wird immer uebernommen, sonst entstuende ein Klon.
  const forced = Math.floor(random() * GENE_COUNT);
  for (let i = 0; i < GENE_COUNT; i++) {
    if (i === forced || random() < crossRate) {
      dst[i] = clamp01(base[i] + factor * (a[i] - b[i]));
    } else {
      dst[i] = base[i];
    }
  }
  return dst;
}
