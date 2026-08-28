/**
 * GrooveNet – Sprachverstaendnis.
 *
 * Wandelt einen freien Text ("dunkler analoger Techno-Kick, sehr punchy,
 * kein Hall, 132 bpm in F moll") in eine maschinenlesbare Absicht um:
 *
 *   1. Zerlegen in Woerter, Umlaute und Schreibweisen vereinheitlichen.
 *   2. Wortgruppen (bis zu drei Woerter) im Lexikon nachschlagen.
 *   3. Unbekannte Woerter ueber Aehnlichkeit zuordnen – so werden auch
 *      Tippfehler und zusammengesetzte Woerter ("technokick") verstanden.
 *   4. Verstaerker ("sehr") und Verneinungen ("kein") auf die folgenden
 *      Woerter anwenden.
 *   5. Alles zu einem Zielvektor im 16-achsigen Klangraum verrechnen.
 *   6. Tempo, Tonart, Laenge und Schleifenwunsch herauslesen.
 *
 * Das Ergebnis ist die Steuergroesse fuer die gesamte Klangerzeugung.
 */

import {
  DIMENSIONS,
  DIM_INDEX,
  NEUTRAL,
  ARCHETYPES,
  ARCHETYPE_IDS,
  TERMS,
  GENRES,
  INTENSIFIERS,
  NEGATIONS,
  LOOP_TERMS,
  ONESHOT_TERMS,
  STOPWORDS,
} from './lexicon.js';

const NOTE_BASE = { c: 0, d: 2, e: 4, f: 5, g: 7, a: 9, b: 11, h: 11 };

const SCALES = {
  major: [0, 2, 4, 5, 7, 9, 11],
  minor: [0, 2, 3, 5, 7, 8, 10],
  dorian: [0, 2, 3, 5, 7, 9, 10],
  phrygian: [0, 1, 3, 5, 7, 8, 10],
  lydian: [0, 2, 4, 6, 7, 9, 11],
  mixolydian: [0, 2, 4, 5, 7, 9, 10],
  harmonic: [0, 2, 3, 5, 7, 8, 11],
  pentatonic: [0, 3, 5, 7, 10],
};

export { SCALES };

const SCALE_WORDS = {
  minor: 'minor', moll: 'minor', min: 'minor',
  major: 'major', dur: 'major', maj: 'major',
  dorian: 'dorian', dorisch: 'dorian',
  phrygian: 'phrygian', phrygisch: 'phrygian',
  lydian: 'lydian', lydisch: 'lydian',
  mixolydian: 'mixolydian', mixolydisch: 'mixolydian',
  harmonic: 'harmonic', harmonisch: 'harmonic',
  pentatonic: 'pentatonic', pentatonik: 'pentatonic',
};

/** Vereinheitlicht Schreibweisen: Kleinbuchstaben, Umlaute, Satzzeichen. */
export function normalizeText(text) {
  return String(text || '')
    .toLowerCase()
    .replace(/ä/g, 'ae')
    .replace(/ö/g, 'oe')
    .replace(/ü/g, 'ue')
    .replace(/ß/g, 'ss')
    .replace(/[^a-z0-9#\-.,\s]/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();
}

/** Zerlegt in Woerter. Bindestriche bleiben erhalten ("lo-fi", "hi-hat"). */
export function tokenize(text) {
  return normalizeText(text)
    .split(/[\s,.]+/)
    .filter(Boolean);
}

// --- Aehnlichkeitssuche ------------------------------------------------------

function trigrams(word) {
  const padded = `  ${word} `;
  const set = new Set();
  for (let i = 0; i < padded.length - 2; i++) set.add(padded.slice(i, i + 3));
  return set;
}

let trigramIndex = null;

function buildTrigramIndex() {
  if (trigramIndex) return trigramIndex;
  trigramIndex = [];
  for (const key of Object.keys(TERMS)) {
    if (key.includes(' ')) continue; // Wortgruppen laufen ueber die N-Gramm-Suche
    trigramIndex.push({ key, grams: trigrams(key) });
  }
  for (const key of Object.keys(GENRES)) {
    if (key.includes(' ')) continue;
    trigramIndex.push({ key, grams: trigrams(key), genre: true });
  }
  return trigramIndex;
}

/** Dice-Koeffizient zweier Trigramm-Mengen: 1 = gleich, 0 = nichts gemeinsam. */
function dice(a, b) {
  let shared = 0;
  for (const g of a) if (b.has(g)) shared++;
  return (2 * shared) / (a.size + b.size);
}

/** Levenshtein-Abstand mit Abbruch, sobald das Limit ueberschritten ist. */
function levenshtein(a, b, limit = 3) {
  if (Math.abs(a.length - b.length) > limit) return limit + 1;
  const prev = new Uint8Array(b.length + 1);
  const cur = new Uint8Array(b.length + 1);
  for (let j = 0; j <= b.length; j++) prev[j] = j;
  for (let i = 1; i <= a.length; i++) {
    cur[0] = i;
    let rowMin = cur[0];
    for (let j = 1; j <= b.length; j++) {
      const cost = a[i - 1] === b[j - 1] ? 0 : 1;
      cur[j] = Math.min(cur[j - 1] + 1, prev[j] + 1, prev[j - 1] + cost);
      if (cur[j] < rowMin) rowMin = cur[j];
    }
    if (rowMin > limit) return limit + 1;
    prev.set(cur);
  }
  return prev[b.length];
}

/**
 * Ordnet ein unbekanntes Wort dem Lexikon zu.
 *
 * Zwei Wege, in dieser Reihenfolge:
 *   1. Komposita – "technokick" enthaelt "techno" UND "kick". Beide Teile
 *      werden zurueckgegeben, damit nichts verloren geht.
 *   2. Schreibfehler – "snaer" liegt nah genug an "snare". Gemessen wird
 *      ueber Trigramme und zusaetzlich ueber die Editierdistanz, weil
 *      vertauschte Buchstaben sonst durchrutschen.
 *
 * @returns {Array<{key:string, score:number, genre?:boolean}>}
 */
export function nearestTerms(word, threshold = 0.62) {
  if (word.length < 3) return [];
  const index = buildTrigramIndex();

  // Komposita: alle enthaltenen Lexikonwoerter einsammeln, ohne Ueberlappung.
  const parts = [];
  let covered = '';
  for (const entry of index) {
    if (entry.key.length >= 4 && word.includes(entry.key)) parts.push(entry);
  }
  if (parts.length) {
    parts.sort((a, b) => b.key.length - a.key.length);
    const taken = [];
    const used = new Array(word.length).fill(false);
    for (const entry of parts) {
      const at = word.indexOf(entry.key);
      let free = true;
      for (let i = at; i < at + entry.key.length; i++) if (used[i]) free = false;
      if (!free) continue;
      for (let i = at; i < at + entry.key.length; i++) used[i] = true;
      taken.push({ key: entry.key, score: 0.85, genre: entry.genre });
      covered += entry.key;
    }
    if (taken.length) return taken;
  }

  // Schreibfehler: bester Treffer gewinnt.
  const grams = trigrams(word);
  let best = null;
  for (const entry of index) {
    const score = dice(grams, entry.grams);
    const close =
      score >= threshold ||
      (word.length >= 5 && levenshtein(word, entry.key, 2) <= 2) ||
      (word.length >= 4 && levenshtein(word, entry.key, 1) <= 1);
    if (close && (!best || score > best.score)) {
      best = { key: entry.key, score: Math.max(score, 0.6), genre: entry.genre };
    }
  }
  return best ? [best] : [];
}

// --- Zahlen und Musikangaben -------------------------------------------------

function parseTempo(text) {
  const m = text.match(/(\d{2,3})\s*(?:bpm|b\.?p\.?m|schlaege|beats per minute)/);
  if (m) {
    const v = parseInt(m[1], 10);
    if (v >= 40 && v <= 250) return v;
  }
  return null;
}

function parseKey(text) {
  // Nur mit klarem Hinweis, damit "a" als Artikel nicht als Ton gilt.
  const withHint = text.match(
    /\b(?:in|key of|tonart|tuned to|gestimmt auf)\s+([a-h])(#|b|is|es)?\s*(-?\d)?\s*(moll|minor|dur|major|min|maj)?/,
  );
  const withMode = text.match(/\b([a-h])(#|b|is|es)?\s*(?:-|\s)?(moll|minor|dur|major)\b/);
  const m = withHint || withMode;
  if (!m) return null;

  const base = NOTE_BASE[m[1]];
  if (base == null) return null;
  const accToken = m[2] || '';
  const acc = accToken === '#' || accToken === 'is' ? 1 : accToken === 'b' || accToken === 'es' ? -1 : 0;
  const octave = withHint && m[3] != null ? parseInt(m[3], 10) : 2;
  const midi = 12 * (octave + 1) + base + acc;
  const modeWord = (withHint ? m[4] : m[3]) || '';
  return { root: midi, scale: SCALE_WORDS[modeWord] || null };
}

function parseScale(text) {
  for (const [word, scale] of Object.entries(SCALE_WORDS)) {
    if (new RegExp(`\\b${word}\\b`).test(text)) return scale;
  }
  return null;
}

function parseLength(text) {
  const bars = text.match(/(\d{1,2})\s*(?:bars?|takte?n?)\b/);
  if (bars) return { bars: Math.min(32, Math.max(1, parseInt(bars[1], 10))) };
  const secs = text.match(/(\d{1,3}(?:[.,]\d+)?)\s*(?:s|sec|secs|seconds|sek|sekunden)\b/);
  if (secs) return { seconds: Math.min(190, Math.max(0.05, parseFloat(secs[1].replace(',', '.')))) };
  return {};
}

// --- Hauptfunktion -----------------------------------------------------------

/**
 * Analysiert einen Prompt.
 *
 * @param {string} prompt
 * @returns {object} Absicht inklusive Zielvektor
 */
export function encode(prompt) {
  const text = normalizeText(prompt);
  const tokens = tokenize(prompt);

  // Zielvektor: startet neutral, wird von Begriffen verschoben.
  const delta = new Float32Array(DIMENSIONS.length);
  const archVotes = new Map();
  const matched = [];
  let genre = null;
  let genreScore = 0;

  // Verstaerker und Verneinungen wirken auf die naechsten Woerter.
  let pendingScale = 1;
  let pendingSign = 1;
  let pendingLife = 0;

  const applyEntry = (entry, key, factor, source, position = 1) => {
    const weight = (entry.w ?? 1) * factor;
    if (entry.dims) {
      for (const [dim, value] of Object.entries(entry.dims)) {
        const idx = DIM_INDEX[dim];
        if (idx != null) delta[idx] += value * weight;
      }
    }
    if (entry.arch && weight > 0) {
      // Verneinte Klangarten zaehlen nicht als Wunsch.
      archVotes.set(entry.arch, (archVotes.get(entry.arch) || 0) + weight * position);
    }
    matched.push({ term: key, weight: Number(weight.toFixed(3)), source });
  };

  for (let i = 0; i < tokens.length; i++) {
    const token = tokens[i];

    if (INTENSIFIERS[token] != null) {
      pendingScale = INTENSIFIERS[token];
      pendingLife = 3;
      continue;
    }
    if (NEGATIONS.has(token)) {
      pendingSign = -1;
      pendingLife = 3;
      continue;
    }
    // Fuellwoerter und reine Zahlen tragen keine Klanginformation. Sie hier
    // auszuschliessen verhindert, dass die Aehnlichkeitssuche daraus
    // Klangbegriffe macht ("moll" ist kein "voll").
    if (STOPWORDS.has(token) || /^[\d.,#-]+$/.test(token)) continue;

    // Wortgruppen zuerst: "open hat" schlaegt "hat".
    let consumed = 1;
    let entry = null;
    let key = null;
    let isGenre = false;

    for (let len = 3; len >= 1; len--) {
      if (i + len > tokens.length) continue;
      const phrase = tokens.slice(i, i + len).join(' ');
      if (TERMS[phrase]) {
        entry = TERMS[phrase];
        key = phrase;
        consumed = len;
        break;
      }
      if (GENRES[phrase]) {
        entry = GENRES[phrase];
        key = phrase;
        consumed = len;
        isGenre = true;
        break;
      }
    }

    const factor = pendingLife > 0 ? pendingScale * pendingSign : 1;
    const source = 'exact';

    // Spaeter genannte Klangarten wiegen etwas schwerer: im Deutschen wie im
    // Englischen steht das eigentliche Substantiv meist hinten
    // ("noise riser" ist ein Riser, kein Rauschen).
    const position = 1 + (i / Math.max(1, tokens.length)) * 0.5;

    if (entry) {
      applyEntry(entry, key, factor, source, position);
      if (isGenre && factor > genreScore) {
        genre = key;
        genreScore = factor;
      }
    } else {
      for (const near of nearestTerms(token)) {
        const nearEntry = near.genre ? GENRES[near.key] : TERMS[near.key];
        if (!nearEntry) continue;
        // Unsichere Treffer wirken schwaecher.
        applyEntry(nearEntry, near.key, factor * near.score, near.score >= 0.85 ? 'compound' : 'fuzzy', position);
        if (near.genre && factor * near.score > genreScore) {
          genre = near.key;
          genreScore = factor * near.score;
        }
      }
    }

    if (pendingLife > 0) {
      pendingLife -= consumed;
      if (pendingLife <= 0) {
        pendingScale = 1;
        pendingSign = 1;
      }
    }
    i += consumed - 1;
  }

  // --- Klangart bestimmen ----------------------------------------------------
  let archetype = null;
  let archetypeScore = 0;
  for (const [id, score] of archVotes) {
    if (score > archetypeScore) {
      archetype = id;
      archetypeScore = score;
    }
  }
  const namedInstrument = archetype;
  if (!archetype) {
    // Keine Klangart genannt: die zum Eigenschaftsprofil passendste waehlen.
    archetype = nearestArchetype(delta);
    archetypeScore = 0.35;
  }

  // --- Zielvektor: Archetyp-Profil plus Wortwirkung --------------------------
  const proto = ARCHETYPES[archetype].dims;
  const dims = new Float32Array(DIMENSIONS.length);
  for (let i = 0; i < DIMENSIONS.length; i++) {
    const base = proto[DIMENSIONS[i]] ?? NEUTRAL;
    dims[i] = Math.min(1, Math.max(0, base + delta[i] * 0.5));
  }

  // --- Musikalischer Rahmen --------------------------------------------------
  const genreInfo = genre ? GENRES[genre] : null;
  const key = parseKey(text);
  const scale = key?.scale || parseScale(text) || genreInfo?.scale || 'minor';
  const bpm = parseTempo(text) || genreInfo?.bpm || null;
  const length = parseLength(text);

  const wantsLoop =
    tokens.some((t) => LOOP_TERMS.has(t)) ||
    LOOP_TERMS.has(tokens.slice(0, 2).join(' ')) ||
    length.bars != null;
  const wantsOneShot = tokens.some((t) => ONESHOT_TERMS.has(t)) || /one[- ]?shot/.test(text);
  const mode = wantsOneShot ? 'oneshot' : wantsLoop ? 'loop' : 'oneshot';

  // Bei einer Schleife ohne genanntes Instrument entsteht ein vollstaendiges
  // Arrangement (Drums, Bass, Akkorde) statt einer einzelnen Spur.
  const focus = mode === 'loop' ? namedInstrument : archetype;
  if (mode === 'loop' && !namedInstrument) archetype = 'kick';

  // Wie sicher ist die Deutung? Fliesst in die Rechenzeit des Optimierers ein.
  const strongMatches = matched.filter((m) => m.source === 'exact').length;
  const confidence = Math.min(1, 0.25 + strongMatches * 0.15 + (archVotes.size ? 0.25 : 0));

  return {
    prompt: String(prompt || ''),
    text,
    tokens,
    matched,
    archetype,
    archetypeScore,
    family: ARCHETYPES[archetype].family,
    dims,
    dimsByName: Object.fromEntries(DIMENSIONS.map((d, i) => [d, dims[i]])),
    genre,
    genreInfo,
    groove: genreInfo?.groove || 'four',
    swing: genreInfo?.swing ?? 0,
    bpm,
    root: key?.root ?? null,
    scale,
    bars: length.bars ?? null,
    seconds: length.seconds ?? null,
    mode,
    focus,
    confidence,
  };
}

/** Waehlt den Archetyp, dessen Profil am besten zu den Wortwirkungen passt. */
function nearestArchetype(delta) {
  let best = 'perc';
  let bestScore = -Infinity;
  for (const id of ARCHETYPE_IDS) {
    const proto = ARCHETYPES[id].dims;
    let score = 0;
    for (let i = 0; i < DIMENSIONS.length; i++) {
      const p = (proto[DIMENSIONS[i]] ?? NEUTRAL) - NEUTRAL;
      score += p * delta[i];
    }
    if (score > bestScore) {
      bestScore = score;
      best = id;
    }
  }
  return best;
}

/** Vorgeschlagene Laenge in Sekunden, wenn der Nutzer keine angibt. */
export function suggestedSeconds(intent) {
  if (intent.seconds != null) return intent.seconds;
  const base = ARCHETYPES[intent.archetype].seconds;
  const sustain = intent.dimsByName.sustain;
  // Ein langer Ausklang braucht mehr Platz, ein kurzer weniger.
  const factor = Math.min(1.9, Math.max(0.6, 0.55 + sustain * 1.4));
  return Math.min(30, Math.max(0.1, base * factor));
}
