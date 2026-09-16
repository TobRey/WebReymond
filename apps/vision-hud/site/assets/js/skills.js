/**
 * Skills und Aktionen – das, was ReyRey „dazulernen“ kann, ohne dass jemand
 * Code schreibt.
 *
 * Es gibt drei Arten:
 *
 *   gesture   ein Handzeichen (eingebaut oder selbst aufgenommen) löst eine
 *             Aktion aus                               → { gestureId, action }
 *   group     eine Gruppe von Klassen wird hervorgehoben und angesagt
 *             („Bildschirme und elektrische Geräte“)   → { classes, color }
 *   command   ein eigener Sprachbefehl für eine Aktion → { phrase, action }
 *
 * Alle drei sind Daten in IndexedDB. Sie wirken sofort und überleben den
 * Neustart. Nichts davon muss hochgeladen werden.
 *
 * Die Aktionsliste ist die gemeinsame Sprache: Handzeichen, Sprachbefehle und
 * der KI-Dienst zeigen alle auf dieselben Kennungen.
 */

import { makeId } from './store.js';

/* ==========================================================================
 * Aktionen
 * ========================================================================== */

/**
 * Jede Funktion der Seite, die sich auslösen lässt.
 * `words` sind Stichwörter für die Zuordnung gesprochener Beschreibungen.
 */
export const ACTIONS = [
  {
    id: 'objects.toggle',
    name: 'Objekterkennung umschalten',
    words: ['objekt', 'umschalt', 'wechsel'],
  },
  { id: 'objects.on', name: 'Objekterkennung einschalten', words: ['objekt', 'an', 'ein'] },
  { id: 'objects.off', name: 'Objekterkennung ausschalten', words: ['objekt', 'aus'] },
  { id: 'faces.toggle', name: 'Gesichtserkennung umschalten', words: ['gesicht', 'umschalt'] },
  { id: 'faces.on', name: 'Gesichtserkennung einschalten', words: ['gesicht', 'an', 'ein'] },
  { id: 'faces.off', name: 'Gesichtserkennung ausschalten', words: ['gesicht', 'aus'] },
  { id: 'scan', name: 'Umgebung scannen', words: ['scan', 'analys', 'umgebung'] },
  { id: 'describe', name: 'Umgebung beschreiben', words: ['beschreib', 'was siehst', 'erzähl'] },
  { id: 'readText', name: 'Text vorlesen', words: ['text', 'vorles', 'lies', 'lesen'] },
  { id: 'magnify', name: 'Lupe öffnen', words: ['lupe', 'vergröss', 'zoom', 'näher'] },
  { id: 'translate', name: 'Text übersetzen', words: ['übersetz', 'translate'] },
  { id: 'hud.toggle', name: 'Anzeige ein/aus', words: ['hud', 'anzeige', 'rahmen', 'overlay'] },
  { id: 'camera.flip', name: 'Kamera wechseln', words: ['kamera', 'wechsel', 'selfie', 'front'] },
  {
    id: 'registerFace',
    name: 'Gesicht erfassen',
    words: ['gesicht erfass', 'person erfass', 'registrier'],
  },
  {
    id: 'rememberObject',
    name: 'Gegenstand merken',
    words: ['gegenstand', 'merk', 'objekt speicher'],
  },
  { id: 'privacy.toggle', name: 'Privatmodus umschalten', words: ['privat', 'inkognito'] },
  { id: 'listen.toggle', name: 'Zuhören ein/aus', words: ['zuhör', 'mikro', 'hören'] },
  {
    id: 'silence',
    name: 'ReyRey verstummen lassen',
    words: ['still', 'ruhe', 'stopp', 'schweig', 'leise'],
  },
  { id: 'people', name: 'Personen nennen', words: ['wer ist', 'personen', 'leute'] },
  { id: 'time', name: 'Uhrzeit sagen', words: ['uhr', 'zeit', 'spät', 'datum'] },
  { id: 'snapshot', name: 'Bild festhalten', words: ['foto', 'schnappschuss', 'bild', 'aufnahme'] },
  { id: 'none', name: 'nichts', words: ['nichts', 'keine'] },
];

/** Wörter, die allein noch keine Aktion bestimmen („umschalten“, „an“, „aus“). */
const GENERIC_WORDS = new Set(['umschalt', 'wechsel', 'an', 'ein', 'aus']);

/** Findet die Aktion, die zu einer gesprochenen Beschreibung passt. */
export function matchAction(text) {
  const lower = text.toLowerCase();
  let best = null;
  let bestScore = 0;

  for (const action of ACTIONS) {
    // Erst ein eigenes Stichwort („objekt“, „kamera“, „text“) qualifiziert die
    // Aktion – sonst würde „wechseln“ in „Kamera wechseln“ auch die
    // Objekterkennung treffen.
    let score = 0;
    for (const word of action.words) {
      if (!GENERIC_WORDS.has(word) && lower.includes(word)) score += word.length;
    }
    if (score === 0) continue;

    // „umschalten“ ohne an/aus → die Umschalt-Variante bevorzugen.
    if (
      action.id.endsWith('.toggle') &&
      /umschalt|wechsel|an oder aus|ein oder aus|an und aus/.test(lower)
    ) {
      score += 6;
    }
    if (action.id.endsWith('.on') && /\b(an|ein)\b/.test(lower) && !/\baus\b/.test(lower))
      score += 3;
    if (action.id.endsWith('.off') && /\baus\b/.test(lower) && !/\b(an|ein)\b/.test(lower))
      score += 3;
    if (score > bestScore) {
      bestScore = score;
      best = action;
    }
  }

  // Nur „Objekt“ ohne Richtung ist mehrdeutig – dann nachfragen lassen.
  const ambiguous =
    best &&
    /\.(on|off|toggle)$/.test(best.id) &&
    !/umschalt|wechsel|\ban\b|\bein\b|\baus\b/.test(lower);
  return best ? { action: best, ambiguous: Boolean(ambiguous), score: bestScore } : null;
}

export function actionName(id) {
  return ACTIONS.find((action) => action.id === id)?.name ?? id;
}

/* ==========================================================================
 * Gruppen: gesprochene Beschreibung → Klassen
 *
 * Die Klassen selbst kommen aus labels-oiv7.js (601, mit `group`) und
 * labels-imagenet.js (1000, mit `group`). Hier stehen nur die Stichwörter,
 * die auf Gruppen oder einzelne Klassen zeigen.
 * ========================================================================== */

/** Gesprochenes Wort → Gruppenname aus den Label-Tabellen. */
const GROUP_WORDS = {
  bildschirm: ['screen'],
  monitor: ['screen'],
  fernseher: ['screen'],
  display: ['screen'],
  computer: ['computer'],
  laptop: ['computer'],
  handy: ['phone'],
  telefon: ['phone'],
  smartphone: ['phone'],
  elektro: ['appliance', 'electronics', 'screen', 'computer', 'phone', 'audio', 'light'],
  elektrisch: ['appliance', 'electronics', 'screen', 'computer', 'phone', 'audio', 'light'],
  strom: ['appliance', 'electronics', 'screen', 'computer', 'phone', 'audio', 'light'],
  gerät: ['appliance', 'electronics', 'screen', 'computer', 'phone', 'audio'],
  elektronik: ['electronics', 'screen', 'computer', 'phone', 'audio'],
  kamera: ['electronics'],
  drucker: ['electronics'],
  haushalt: ['appliance', 'kitchen'],
  küche: ['kitchen', 'appliance', 'food'],
  essen: ['food', 'drink'],
  lebensmittel: ['food', 'drink'],
  getränk: ['drink'],
  trinken: ['drink'],
  möbel: ['furniture'],
  sitz: ['furniture'],
  fahrzeug: ['vehicle'],
  auto: ['vehicle'],
  verkehr: ['vehicle', 'traffic'],
  strasse: ['vehicle', 'traffic'],
  tier: ['animal'],
  hund: ['dog'],
  katze: ['cat'],
  vogel: ['bird'],
  pflanze: ['plant'],
  blume: ['plant'],
  kleidung: ['clothing'],
  klamotten: ['clothing'],
  schuh: ['footwear'],
  werkzeug: ['tool'],
  spielzeug: ['toy'],
  sport: ['sports'],
  ball: ['sports'],
  musik: ['instrument', 'audio'],
  instrument: ['instrument'],
  verpackung: ['container'],
  paket: ['container'],
  karton: ['container'],
  flasche: ['container'],
  tasche: ['bag'],
  rucksack: ['bag'],
  koffer: ['bag'],
  waffe: ['weapon'],
  gefahr: ['weapon', 'vehicle'],
  licht: ['light'],
  lampe: ['light'],
  schreibtisch: ['office', 'furniture'],
  büro: ['office'],
  papier: ['office'],
  buch: ['office'],
  bad: ['bathroom'],
  toilette: ['bathroom'],
  medizin: ['medical'],
  kosmetik: ['cosmetics'],
  schmuck: ['accessory'],
  brille: ['accessory'],
  uhr: ['accessory'],
  gebäude: ['building'],
  natur: ['nature', 'plant'],
  landschaft: ['nature'],
  teppich: ['furniture'],
  haus: ['building'],
  fenster: ['building'],
  tür: ['building'],
  feuerzeug: ['misc'],
  alkohol: ['drink'],
  baby: ['baby'],
  kind: ['baby'],
  person: ['person'],
  mensch: ['person'],
  gesicht: ['person'],
};

/**
 * Sucht Klassen zu einer Beschreibung.
 *
 * @param {string} description  z. B. "Bildschirme und elektrische Geräte"
 * @param {Array<{id: string, de: string, group: string}>} catalogue  alle Klassen
 * @returns {{classes: string[], groups: string[], unmatched: string[]}}
 */
export function classesFor(description, catalogue) {
  const lower = description.toLowerCase();
  const groups = new Set();
  const classes = new Set();
  const unmatched = [];

  // Wörter der Beschreibung – „und“, „alle“, „ab jetzt“ sind Füllwörter.
  const tokens = lower
    .replace(/[^\p{L}\p{N}\s-]/gu, ' ')
    .split(/\s+/)
    .filter((t) => t.length > 2 && !FILLERS.has(t));

  for (const token of tokens) {
    const stem = token.replace(/(en|er|es|e|s|n)$/u, '');
    let hit = false;

    // 1 Gruppenstichwort
    for (const [word, targets] of Object.entries(GROUP_WORDS)) {
      if (token.startsWith(word) || word.startsWith(stem) || stem.startsWith(word)) {
        targets.forEach((g) => groups.add(g));
        hit = true;
      }
    }

    // 2 Direkter Klassenname (deutsch oder englisch)
    for (const entry of catalogue) {
      const de = entry.de.toLowerCase();
      const en = entry.id.toLowerCase();
      if (de === token || de.startsWith(stem) || en === token || en.startsWith(stem)) {
        classes.add(entry.id);
        hit = true;
      }
    }

    if (!hit) unmatched.push(token);
  }

  for (const entry of catalogue) {
    if (groups.has(entry.group)) classes.add(entry.id);
  }

  return { classes: [...classes], groups: [...groups], unmatched };
}

const FILLERS = new Set([
  'und',
  'oder',
  'alle',
  'alles',
  'jetzt',
  'jetz',
  'erkenn',
  'erkennst',
  'erkenne',
  'zeig',
  'zeige',
  'bitte',
  'auch',
  'den',
  'die',
  'das',
  'der',
  'ein',
  'eine',
  'mir',
  'mich',
  'sowie',
  'skill',
  'hinzu',
  'hinzufügen',
  'neuer',
  'neue',
  'neuen',
  'soll',
  'sollst',
  'kannst',
  'ab',
  'immer',
  'ausserdem',
  'außerdem',
  'dazu',
  'noch',
  'nur',
  'also',
  'quasi',
  'halt',
]);

/** Farben für Gruppen-Skills, der Reihe nach vergeben. */
export const SKILL_COLORS = ['#ff7ad9', '#7ad9ff', '#ffd166', '#a3ff7a', '#ff9f7a', '#c9a0ff'];

/* ==========================================================================
 * Ablage
 * ========================================================================== */

const DB_NAME = 'visionhud-skills';
const STORE = 'skills';

export class SkillStore {
  constructor() {
    this.db = null;
    this.items = [];
  }

  async open() {
    if (this.db || typeof indexedDB === 'undefined') return;
    this.db = await new Promise((resolve, reject) => {
      const request = indexedDB.open(DB_NAME, 1);
      request.onupgradeneeded = () => {
        const db = request.result;
        if (!db.objectStoreNames.contains(STORE)) db.createObjectStore(STORE, { keyPath: 'id' });
      };
      request.onsuccess = () => resolve(request.result);
      request.onerror = () => reject(request.error ?? new Error('Speicher nicht verfügbar'));
      setTimeout(() => reject(new Error('Zeitüberschreitung')), 4000);
    }).catch(() => null);
    await this.reload();
  }

  async reload() {
    if (!this.db) {
      this.items = [];
      return this.items;
    }
    const tx = this.db.transaction(STORE, 'readonly');
    this.items = await new Promise((resolve) => {
      const request = tx.objectStore(STORE).getAll();
      request.onsuccess = () => resolve(request.result ?? []);
      request.onerror = () => resolve([]);
    });
    return this.items;
  }

  get gestures() {
    return this.items.filter((s) => s.kind === 'gesture' && s.enabled !== false);
  }

  get groups() {
    return this.items.filter((s) => s.kind === 'group' && s.enabled !== false);
  }

  get commands() {
    return this.items.filter((s) => s.kind === 'command' && s.enabled !== false);
  }

  /** Eigene Handzeichen (mit Vektor) für hands.js. */
  get customGestures() {
    return this.items
      .filter((s) => s.kind === 'gesture' && s.vector)
      .map((s) => ({ id: s.id, name: s.name, vector: s.vector }));
  }

  findGroupByName(name) {
    const needle = name.toLowerCase().trim();
    return (
      this.groups.find((s) => s.name.toLowerCase() === needle) ??
      this.groups.find((s) => s.name.toLowerCase().includes(needle)) ??
      null
    );
  }

  /** Welcher aktive Gruppen-Skill enthält diese Klasse? */
  groupForClass(classId) {
    for (const skill of this.groups) {
      if (skill.classes.includes(classId)) return skill;
    }
    return null;
  }

  async add(skill) {
    const item = {
      id: makeId(),
      enabled: true,
      createdAt: new Date().toISOString(),
      ...skill,
    };
    await this.#put(item);
    await this.reload();
    return item;
  }

  async update(id, patch) {
    const item = this.items.find((s) => s.id === id);
    if (!item) return null;
    Object.assign(item, patch);
    await this.#put(item);
    await this.reload();
    return item;
  }

  async remove(id) {
    if (!this.db) return;
    const tx = this.db.transaction(STORE, 'readwrite');
    tx.objectStore(STORE).delete(id);
    await new Promise((resolve) => {
      tx.oncomplete = resolve;
    });
    await this.reload();
  }

  async clear() {
    if (!this.db) return;
    const tx = this.db.transaction(STORE, 'readwrite');
    tx.objectStore(STORE).clear();
    await new Promise((resolve) => {
      tx.oncomplete = resolve;
    });
    await this.reload();
  }

  /** Nächste freie Farbe für einen Gruppen-Skill. */
  nextColor() {
    const used = new Set(this.groups.map((s) => s.color));
    return (
      SKILL_COLORS.find((c) => !used.has(c)) ??
      SKILL_COLORS[this.groups.length % SKILL_COLORS.length]
    );
  }

  async #put(item) {
    if (!this.db) return;
    const tx = this.db.transaction(STORE, 'readwrite');
    tx.objectStore(STORE).put(item);
    await new Promise((resolve) => {
      tx.oncomplete = resolve;
    });
  }
}
