/**
 * AI Groove – Assistent „Groove AI“.
 *
 * Der Assistent versteht Anweisungen in deutscher und englischer Sprache und
 * uebersetzt sie in geprüfte Aktionen (siehe actions.js). Er arbeitet lokal,
 * braucht also weder Internet noch einen Sprachmodell-Zugang; fuer die
 * Klangerzeugung nutzt er den eingestellten Audio-Anbieter.
 *
 * Wichtig: Der Assistent fuehrt nichts heimlich aus. Er liefert einen Plan,
 * den die Oberflaeche anzeigt und der bestaetigt werden muss.
 */

import { store } from '../core/store.js';
import { findSample, findPattern, describeAction } from './actions.js';
import { TICKS_PER_BAR, clamp } from '../core/util.js';

const NUM_WORDS = {
  eins: 1, ein: 1, eine: 1, erste: 1, one: 1, first: 1,
  zwei: 2, zweite: 2, two: 2, second: 2, doppelt: 2, double: 2,
  drei: 3, dritte: 3, three: 3, third: 3,
  vier: 4, vierte: 4, four: 4, fourth: 4,
  acht: 8, eight: 8,
  sechzehn: 16, sixteen: 16,
  dreissig: 32, 'dreißig': 32,
};

function parseNumber(word) {
  if (word == null) return null;
  const s = String(word).toLowerCase().replace('.', '');
  if (/^\d+$/.test(s)) return parseInt(s, 10);
  return NUM_WORDS[s] ?? null;
}

/** Bekannte Instrumentbegriffe -> Suchname. */
const INSTRUMENTS = [
  [/\b(kicks?|bassdrums?|bass ?drums?|bd)\b/i, 'Kick'],
  [/\b(sub ?bass|subbass|sub)\b/i, 'Sub Bass'],
  [/\b(bass(line)?|reese)\b/i, 'Bass'],
  [/\b(snares?)\b/i, 'Snare'],
  [/\b(claps?|handclaps?)\b/i, 'Clap'],
  [/\b(open ?hats?|open ?hi.?hats?)\b/i, 'Open Hat'],
  [/\b(hi.?hats?|hats?|hh)\b/i, 'Hi-Hat'],
  [/\b(rides?|crashe?s?|cymbals?)\b/i, 'Cymbal'],
  [/\b(toms?)\b/i, 'Tom'],
  [/\b(percussions?|percs?|shakers?)\b/i, 'Perc'],
  [/\b(vocals?|vox|stimmen?)\b/i, 'Vocal'],
  [/\b(leads?|melodien?|melody|arps?)\b/i, 'Lead'],
  [/\b(pads?|flächen?|flaechen?)\b/i, 'Pad'],
  [/\b(stabs?|chords?|akkorde?)\b/i, 'Stab'],
  [/\b(risers?|uplifters?)\b/i, 'Riser'],
  [/\b(impacts?|booms?)\b/i, 'Impact'],
];

function detectInstrument(text) {
  for (const [re, name] of INSTRUMENTS) {
    if (re.test(text)) return name;
  }
  return null;
}

/** Passendes Prompt-Fragment fuer die Klangerzeugung. */
function promptFor(instrument, style, bpm) {
  const s = style ? `${style} ` : '';
  const b = bpm ? `, ${bpm} BPM` : '';
  const map = {
    Kick: `${s}kick drum, punchy attack, deep clean sub, short tail${b}`,
    'Sub Bass': `${s}deep sub bass, clean sine, long${b}`,
    Bass: `${s}bassline, driving, filtered saw${b}`,
    Snare: `${s}snare drum, tight body, bright noise${b}`,
    Clap: `${s}clap, layered, roomy${b}`,
    'Hi-Hat': `${s}closed hi hat, crisp, very short${b}`,
    'Open Hat': `${s}open hi hat, metallic, medium tail${b}`,
    Cymbal: `${s}crash cymbal, bright${b}`,
    Tom: `${s}tom drum, deep${b}`,
    Perc: `${s}percussion hit, dry, short${b}`,
    Vocal: `${s}vocal chop, processed${b}`,
    Lead: `${s}lead synth, bright saw pluck${b}`,
    Pad: `${s}atmospheric pad, wide${b}`,
    Stab: `${s}chord stab, short${b}`,
    Riser: `${s}riser, noise sweep, building${b}`,
    Impact: `${s}impact boom, deep${b}`,
  };
  return map[instrument] || `${s}${instrument} sound${b}`;
}

/** Standard-Schrittfolgen fuer den Grundbeat (16 Schritte pro Takt). */
const PATTERNS_16 = {
  Kick: [1, 0, 0, 0, 1, 0, 0, 0, 1, 0, 0, 0, 1, 0, 0, 0],
  Clap: [0, 0, 0, 0, 1, 0, 0, 0, 0, 0, 0, 0, 1, 0, 0, 0],
  Snare: [0, 0, 0, 0, 1, 0, 0, 0, 0, 0, 0, 0, 1, 0, 0, 0],
  'Hi-Hat': [0, 0, 1, 0, 0, 0, 1, 0, 0, 0, 1, 0, 0, 0, 1, 0],
  'Open Hat': [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 0],
  Bass: [1, 0, 0, 1, 0, 0, 1, 0, 0, 1, 0, 0, 1, 0, 0, 0],
  Perc: [0, 0, 0, 1, 0, 0, 0, 0, 0, 0, 0, 1, 0, 0, 0, 0],
};

function stepsFor(instrument) {
  return PATTERNS_16[instrument] || PATTERNS_16.Perc;
}

/** Genre/Stil aus dem Text. */
function detectStyle(text) {
  const styles = [
    [/\bhard ?techno\b/i, 'hard techno, industrial, punchy'],
    [/\btechno\b/i, 'techno, club'],
    [/\bhouse\b/i, 'house, warm'],
    [/\bhip ?hop|trap\b/i, 'hip hop, 808 style'],
    [/\bdrum ?(&|and) ?bass|dnb\b/i, 'drum and bass'],
    [/\bdubstep\b/i, 'dubstep, heavy'],
    [/\bambient\b/i, 'ambient, soft'],
    [/\blo-?fi\b/i, 'lofi, warm, vintage'],
    [/\bindustrial\b/i, 'industrial, distorted'],
  ];
  for (const [re, label] of styles) if (re.test(text)) return label;
  return '';
}

// --- Einzelne Absichten ------------------------------------------------------

function intentFullBeat(text, ctx) {
  if (!/\b(grundbeat|basic beat|beat|groove|drum ?(beat|track)|drums|schlagzeug|rhythmus)\b/i.test(text)) return null;
  if (/\b(härter|harder|schneller|faster|lauter|leiser)\b/i.test(text)) return null;

  const style = detectStyle(text) || 'club';
  const bpm = ctx.bpm;
  const instruments = ['Kick', 'Clap', 'Hi-Hat'];
  if (/\bbass\b/i.test(text)) instruments.push('Bass');
  if (/\b(open ?hat|open ?hi.?hat)\b/i.test(text)) instruments.push('Open Hat');

  const actions = [];
  if (bpm) actions.push({ type: 'setBpm', params: { value: bpm } });

  const patternName = style.split(',')[0].replace(/\b\w/g, (c) => c.toUpperCase()).trim() || 'Beat';
  actions.push({ type: 'createPattern', params: { name: `${patternName} Beat`, lengthBars: 1 } });

  for (const inst of instruments) {
    const existing = findSample(inst);
    if (!existing) {
      actions.push({
        type: 'generateSample',
        params: { prompt: promptFor(inst, style, bpm || store.project.bpm), name: inst, lengthPreset: 'oneshot' },
      });
    }
    actions.push({ type: 'setSteps', params: { target: inst, steps: stepsFor(inst), grid: 16 } });
  }

  for (let i = 0; i < instruments.length && i < 16; i++) {
    actions.push({ type: 'assignPad', params: { index: i, target: instruments[i] } });
  }

  actions.push({ type: 'createClip', params: { pattern: `${patternName} Beat`, startBar: 1, lengthBars: 8, laneIndex: 0 } });

  return {
    reply: `Ich baue einen ${style}-Grundbeat${bpm ? ` mit ${bpm} BPM` : ''}: ${instruments.join(', ')}, ein Pattern, Pads belegt und 8 Takte im Arrangement.`,
    actions,
  };
}

function intentBpm(text, ctx) {
  if (ctx.bpm == null) return null;
  if (/\b(grundbeat|beat|groove|drums)\b/i.test(text)) return null;
  return {
    reply: `Tempo auf ${ctx.bpm} BPM.`,
    actions: [{ type: 'setBpm', params: { value: ctx.bpm } }],
  };
}

function intentSwing(text) {
  const m = text.match(/\bswing\b[^0-9]{0,12}(\d{1,3})\s*%?/i);
  if (!m) return null;
  const value = clamp(parseInt(m[1], 10) / 100, 0, 1);
  return { reply: `Swing auf ${Math.round(value * 100)} %.`, actions: [{ type: 'setSwing', params: { value } }] };
}

function intentDensity(text) {
  const faster = /\b(schneller|faster|doppelt|double|dichter|mehr)\b/i.test(text);
  const slower = /\b(langsamer|slower|halb|halve|weniger|ausdünnen|ausduennen)\b/i.test(text);
  if (!faster && !slower) return null;
  const inst = detectInstrument(text);
  if (!inst) return null;
  return {
    reply: `${inst} wird ${faster ? 'dichter' : 'ausgedünnter'}.`,
    actions: [{ type: 'changeDensity', params: { target: inst, factor: faster ? 2 : 0.5 } }],
  };
}

function intentRemoveEveryNth(text) {
  const m = text.match(
    /\b(entferne|lösche|loesche|remove|delete)\b[^.]*?\bjede[nr]?\s+(\w+)\.?\s*(\w+)?/i,
  ) || text.match(/\bremove\s+every\s+(\w+)\s+(\w+)/i);
  if (!m) return null;
  const n = parseNumber(m[2]) ?? parseNumber(m[1]);
  const inst = detectInstrument(text);
  if (!n || n < 2 || !inst) return null;
  return {
    reply: `Ich entferne jede ${n}. ${inst} im aktuellen Pattern.`,
    actions: [{ type: 'removeEveryNth', params: { target: inst, n, offset: 1 } }],
  };
}

function intentFillEveryNBars(text) {
  const m = text.match(/\balle\s+(\d+)\s+takte?\b/i) || text.match(/\bevery\s+(\d+)\s+bars?\b/i);
  if (!m) return null;
  const every = clamp(parseInt(m[1], 10), 1, 64);
  const inst = detectInstrument(text) || 'Clap';
  const style = detectStyle(text);

  const actions = [];
  if (!findSample(inst)) {
    actions.push({
      type: 'generateSample',
      params: { prompt: promptFor(inst, style, store.project.bpm), name: inst, lengthPreset: 'oneshot' },
    });
  }
  const fillName = `${inst} Fill`;
  actions.push({ type: 'createPattern', params: { name: fillName, lengthBars: 1 } });
  // Vier schnelle Schlaege am Taktende – ein klassischer Fill.
  actions.push({
    type: 'setSteps',
    params: { pattern: fillName, target: inst, steps: [0,0,0,0,0,0,0,0,0,0,0,0,1,1,1,1], grid: 16 },
  });

  const bars = Math.max(8, Math.ceil(currentSongBars() / every) * every);
  for (let bar = every; bar <= bars; bar += every) {
    actions.push({
      type: 'createClip',
      params: { pattern: fillName, startBar: bar, lengthBars: 1, laneIndex: 2 },
    });
  }

  return {
    reply: `Ich lege alle ${every} Takte einen ${inst}-Fill ins Arrangement (bis Takt ${bars}).`,
    actions,
  };
}

function intentDelayedEntry(text) {
  const m =
    text.match(/\b(?:erst|first)\s*(?:nach|after)\s*(\d+)\s*takten?\b/i) ||
    text.match(/\b(?:ab|from)\s*takt\s*(\d+)\b/i) ||
    text.match(/\bafter\s+(\d+)\s+bars?\b/i);
  if (!m) return null;
  const inst = detectInstrument(text);
  if (!inst) return null;
  const bar = clamp(parseInt(m[1], 10), 0, 4096) + (/(ab|from)\s*takt/i.test(text) ? 0 : 1);

  const pattern = findPattern(inst);
  const actions = [];
  if (pattern) {
    const hasClips = store.project.clips.some((c) => c.type === 'pattern' && c.refId === pattern.id);
    if (hasClips) {
      actions.push({ type: 'moveClipsOfPattern', params: { pattern: pattern.name, toBar: bar } });
    } else {
      actions.push({
        type: 'createClip',
        params: { pattern: pattern.name, startBar: bar, lengthBars: 8, laneIndex: 1 },
      });
    }
  } else {
    return {
      reply: `Ich finde kein Pattern namens „${inst}“. Lege zuerst ein Pattern mit diesem Namen an – dann verschiebe ich es gern.`,
      actions: [],
    };
  }

  return { reply: `„${inst}“ startet ab Takt ${bar}.`, actions };
}

function intentHarder(text) {
  if (!/\b(härter|haerter|harder|heavier|aggressiver|dreckiger|dirtier|mehr druck|punchier)\b/i.test(text)) return null;
  const inst = detectInstrument(text);
  const actions = [];

  if (inst) {
    actions.push({ type: 'addEffect', params: { target: inst, type: 'distortion', params: { drive: 45, mix: 70, out: -4 } } });
    actions.push({ type: 'addEffect', params: { target: inst, type: 'compressor', params: { threshold: -18, ratio: 6, attack: 0.003, release: 0.12, makeup: 3 } } });
  } else {
    // „Mach den Drop härter“ – ohne konkretes Instrument: Kick + Master.
    const kick = findSample('Kick');
    if (kick) {
      actions.push({ type: 'addEffect', params: { target: 'Kick', type: 'distortion', params: { drive: 50, mix: 75, out: -4 } } });
    }
    actions.push({ type: 'addEffect', params: { target: 'master', type: 'compressor', params: { threshold: -14, ratio: 5, attack: 0.004, release: 0.1, makeup: 2 } } });
    actions.push({ type: 'addEffect', params: { target: 'master', type: 'limiter', params: { ceiling: -0.5, release: 0.05 } } });
  }

  return {
    reply: inst
      ? `${inst} bekommt Sättigung und Kompression – klingt deutlich härter.`
      : 'Ich mache den Drop härter: Sättigung auf der Kick, Kompressor und Limiter auf dem Master.',
    actions,
  };
}

function intentVolume(text) {
  const louder = /\b(lauter|louder|hoch|up)\b/i.test(text);
  const quieter = /\b(leiser|quieter|runter|down)\b/i.test(text);
  if (!louder && !quieter) return null;
  const inst = detectInstrument(text);
  const target = inst || 'master';
  const channelId = target === 'master' ? 'master' : findSample(target)?.id;
  if (!channelId) return null;
  const current = target === 'master' ? store.project.master.gain : store.getChannel(channelId).gain;
  const value = clamp(current * (louder ? 1.35 : 0.7), 0, 2);
  return {
    reply: `${target === 'master' ? 'Master' : target} wird ${louder ? 'lauter' : 'leiser'} (${Math.round(value * 100)} %).`,
    actions: [{ type: 'setVolume', params: { target, value } }],
  };
}

function intentEffect(text) {
  const map = [
    [/\b(reverb|hall)\b/i, 'reverb'],
    [/\b(delay|echo)\b/i, 'delay'],
    [/\b(distortion|verzerrung|drive)\b/i, 'distortion'],
    [/\b(kompressor|compressor)\b/i, 'compressor'],
    [/\b(limiter)\b/i, 'limiter'],
    [/\b(eq|equalizer)\b/i, 'eq'],
    [/\b(low ?pass|tiefpass)\b/i, 'lowpass'],
    [/\b(high ?pass|hochpass)\b/i, 'highpass'],
  ];
  let type = null;
  for (const [re, t] of map) {
    if (re.test(text)) {
      type = t;
      break;
    }
  }
  if (!type) return null;
  if (!/\b(auf|on|add|füge|fuege|leg|put|mach)\b/i.test(text)) return null;
  const inst = detectInstrument(text);
  const target = inst || 'master';
  return {
    reply: `Ich lege einen ${type}-Effekt auf ${target === 'master' ? 'den Master' : target}.`,
    actions: [{ type: 'addEffect', params: { target, type } }],
  };
}

function intentStepPlacement(text) {
  const inst = detectInstrument(text);
  if (!inst) return null;
  let steps = null;
  let label = '';

  if (/\b(jede[nsm]?\s+(viertel|beat)|four on the floor|4 ?on ?the ?floor|auf jede[nsm]? schlag)\b/i.test(text)) {
    steps = [1, 0, 0, 0, 1, 0, 0, 0, 1, 0, 0, 0, 1, 0, 0, 0];
    label = 'auf jedes Viertel';
  } else if (/\b(jede[snm]?\s+achtel|every 8th|offbeat|off ?beat)\b/i.test(text)) {
    steps = /offbeat|off ?beat/i.test(text)
      ? [0, 0, 1, 0, 0, 0, 1, 0, 0, 0, 1, 0, 0, 0, 1, 0]
      : [1, 0, 1, 0, 1, 0, 1, 0, 1, 0, 1, 0, 1, 0, 1, 0];
    label = /offbeat|off ?beat/i.test(text) ? 'auf die Offbeats' : 'auf jedes Achtel';
  } else if (/\b(jede[snm]?\s+sechzehntel|every 16th)\b/i.test(text)) {
    steps = new Array(16).fill(1);
    label = 'auf jedes Sechzehntel';
  } else if (/\b(2 und 4|zwei und vier|backbeat)\b/i.test(text)) {
    steps = [0, 0, 0, 0, 1, 0, 0, 0, 0, 0, 0, 0, 1, 0, 0, 0];
    label = 'auf 2 und 4';
  }
  if (!steps) return null;

  const actions = [];
  if (!findSample(inst)) {
    actions.push({
      type: 'generateSample',
      params: { prompt: promptFor(inst, detectStyle(text), store.project.bpm), name: inst, lengthPreset: 'oneshot' },
    });
  }
  if (!store.selectedPattern) {
    actions.push({ type: 'createPattern', params: { name: inst, lengthBars: 1 } });
  }
  actions.push({ type: 'setSteps', params: { target: inst, steps, grid: 16 } });

  return { reply: `${inst} ${label}.`, actions };
}

function intentCreateSample(text, ctx) {
  if (!/\b(erstelle|erzeuge|generiere|mach|baue|create|generate|make|add|füge|fuege)\b/i.test(text)) return null;
  const inst = detectInstrument(text);
  if (!inst) return null;
  const style = detectStyle(text);
  return {
    reply: `Ich erzeuge einen ${inst}${style ? ` im Stil ${style}` : ''}.`,
    actions: [
      {
        type: 'generateSample',
        params: {
          prompt: promptFor(inst, style, ctx.bpm || store.project.bpm),
          name: inst,
          lengthPreset: 'oneshot',
        },
      },
    ],
  };
}

function intentLoop(text) {
  const m = text.match(/\bloop\b[^0-9]{0,20}(\d+)\s*(?:bis|to|-)\s*(\d+)/i);
  if (!m) return null;
  const startBar = parseInt(m[1], 10);
  const endBar = parseInt(m[2], 10);
  return {
    reply: `Loop von Takt ${startBar} bis ${endBar}.`,
    actions: [{ type: 'setLoop', params: { startBar, endBar } }],
  };
}

function currentSongBars() {
  let end = TICKS_PER_BAR * 8;
  for (const c of store.project.clips) end = Math.max(end, c.start + c.length);
  return Math.ceil(end / TICKS_PER_BAR);
}

const INTENTS = [
  intentFullBeat,
  intentFillEveryNBars,
  intentDelayedEntry,
  intentRemoveEveryNth,
  intentDensity,
  intentHarder,
  intentStepPlacement,
  intentLoop,
  intentSwing,
  intentEffect,
  intentVolume,
  intentCreateSample,
  intentBpm,
];

const HELP_TEXT = `Ich kann unter anderem:
• „Mach mir einen 155 BPM Hard-Techno-Grundbeat“
• „Kick auf jedes Viertel“, „Hi-Hats auf die Offbeats“
• „Mach die Hats schneller“ / „langsamer“
• „Entferne jede zweite Kick in diesem Pattern“
• „Alle 4 Takte ein Clap Fill“
• „Der Bass soll erst nach 16 Takten kommen“
• „Mach den Drop härter“
• „Reverb auf die Snare“, „Snare lauter“
• „Tempo auf 140 BPM“, „Swing auf 25 %“, „Loop 1 bis 8“`;

/**
 * Wandelt eine Eingabe in einen Plan um.
 * @param {string} input
 * @returns {{reply:string, actions:Array, needsConfirm:boolean, steps:string[]}}
 */
export function planFromText(input) {
  const text = String(input || '').trim();
  if (!text) {
    return { reply: 'Sag mir, was ich bauen soll.', actions: [], needsConfirm: false, steps: [] };
  }

  if (/^(hilfe|help|was kannst du|\?)$/i.test(text)) {
    return { reply: HELP_TEXT, actions: [], needsConfirm: false, steps: [] };
  }

  const bpmMatch = text.match(/(\d{2,3})\s*(?:bpm|b\.p\.m)/i) || text.match(/\btempo\D{0,12}(\d{2,3})\b/i);
  const ctx = { bpm: bpmMatch ? clamp(parseInt(bpmMatch[1], 10), 40, 250) : null };

  for (const intent of INTENTS) {
    let result = null;
    try {
      result = intent(text, ctx);
    } catch (err) {
      console.warn('[assistant] Intent-Fehler', err);
    }
    if (result && result.actions) {
      const steps = result.actions.map(describeAction);
      const needsConfirm =
        result.actions.some((a) => a.type === 'generateSample') || result.actions.length > 3;
      return { ...result, needsConfirm, steps };
    }
  }

  return {
    reply: `Das habe ich nicht sicher verstanden.\n\n${HELP_TEXT}`,
    actions: [],
    needsConfirm: false,
    steps: [],
  };
}

/** Kurzbeschreibung des Projekts – die KI „sieht“ damit den Kontext. */
export function projectSummary() {
  const p = store.project;
  const pattern = store.selectedPattern;
  return {
    name: p.name,
    bpm: p.bpm,
    swing: Math.round(p.swing * 100),
    samples: p.samples.map((s) => s.name),
    patterns: p.patterns.map((x) => ({ name: x.name, bars: x.lengthBars, notes: x.events.length })),
    selectedPattern: pattern ? pattern.name : null,
    clips: p.clips.length,
    bars: currentSongBars(),
  };
}

export { HELP_TEXT };
