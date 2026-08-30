// Der Element-Katalog: die eine Quelle für alles, was auf eine Karte kann.
//
// Drei Abnehmer lesen hier – und nur hier:
//   1. der Karten-Editor (Palette, Eigenschaften-Felder, Standardgrössen)
//   2. der Admin-Reiter "Elemente" (Grafik-Plätze, Trefferflächen)
//   3. die Engine (entities.js setzt das Verhalten um)
// Ein neues Element heisst: hier ein Eintrag, in entities.js sein Verhalten,
// fertig. Editor und Admin bauen sich aus dem Katalog von selbst.
//
// Kein DOM, keine Abhängigkeiten – Tests laden die Datei direkt in Node.
//
// EIGENSCHAFTEN STATT PROMPT. Je Element gibt es wählbare Einstellungen mit
// festen Grenzen und dazu ein Freitextfeld `note` – eine Notiz für den, der
// die Karte baut. Sie wird gespeichert und angezeigt, aber nicht gedeutet:
// es gibt keinen KI-Server, und ein Textfeld, das nur so tut, als verstünde
// es Sätze, wäre gelogen. Das wurde mit dem Auftraggeber so abgesprochen.

/**
 * Eine Eigenschaft: Art, Grenzen, Voreinstellung.
 * kind: 'number' (min/max/step), 'bool', 'choice' (options), 'target'
 * (Verweis auf ein anderes Element derselben Karte, per Typ gefiltert).
 */
const num = (label, def, min, max, step = 1) => ({
  kind: 'number',
  label,
  def,
  min,
  max,
  step,
});
const bool = (label, def) => ({ kind: 'bool', label, def });
const choice = (label, def, options) => ({ kind: 'choice', label, def, options });
const target = (label, type) => ({ kind: 'target', label, def: '', type });

/**
 * Der Katalog.
 *
 * `cells` ist die Standardgrösse in Rasterzellen (Breite × Höhe).
 * `resizable`: im Editor als Rechteck aufziehbar (Boden, Plattformen, Stacheln).
 * `solid`: wandert ins Kollisionsgitter statt in die Elementliste.
 * `unique`: höchstens einmal je Karte (Start, Flagge).
 * `sprite`: empfohlene Grafik, erscheint in der Wunschliste im Admin.
 */
export const ELEMENTS = {
  ground: {
    name: { de: 'Boden-Block', en: 'Ground block' },
    cells: [1, 1],
    resizable: true,
    solid: 'SOLID',
    props: {},
    sprite: { de: 'kachelbarer Block (z. B. Erde mit Gras)', size: '16×16 oder grösser' },
  },
  platform: {
    name: { de: 'Plattform', en: 'Platform' },
    cells: [3, 1],
    resizable: true,
    solid: 'PLATFORM',
    props: {},
    sprite: { de: 'schwebende Plattform, von unten durchspringbar', size: 'beliebig breit' },
  },
  crumble: {
    name: { de: 'Bröckelblock', en: 'Crumbling block' },
    cells: [1, 1],
    resizable: true,
    solid: 'CRUMBLE',
    props: {
      delay: num({ de: 'Zerfall nach (Zehntel-s)', en: 'Crumbles after (tenths)' }, 4, 1, 30),
    },
    sprite: { de: 'rissiger Block', size: '16×16 oder grösser' },
  },
  mover: {
    name: { de: 'Bewegliche Plattform', en: 'Moving platform' },
    cells: [3, 1],
    resizable: false,
    props: {
      axis: choice({ de: 'Richtung', en: 'Direction' }, 'h', [
        { value: 'h', label: { de: 'waagerecht', en: 'horizontal' } },
        { value: 'v', label: { de: 'senkrecht', en: 'vertical' } },
      ]),
      range: num({ de: 'Strecke (Zellen)', en: 'Distance (cells)' }, 4, 1, 40),
      speed: num({ de: 'Tempo (px/Tick ×10)', en: 'Speed (px/tick ×10)' }, 8, 2, 30),
    },
    sprite: { de: 'Plattform mit erkennbarer Mechanik', size: 'wie Plattform' },
  },
  spring: {
    name: { de: 'Feder', en: 'Spring' },
    cells: [1, 1],
    resizable: false,
    props: {
      power: num({ de: 'Sprungkraft (px/Tick ×10)', en: 'Power (px/tick ×10)' }, 100, 60, 140),
    },
    sprite: { de: 'Sprungfeder oder Pilz', size: '16×16, gern 2–3 GIF-Bilder' },
  },
  spikes: {
    name: { de: 'Stacheln', en: 'Spikes' },
    cells: [2, 1],
    resizable: true,
    deadly: true,
    props: {},
    sprite: { de: 'Stachelreihe', size: '16 px hoch, beliebig breit' },
  },
  emerald: {
    name: { de: 'Smaragd', en: 'Emerald' },
    cells: [1, 1],
    resizable: false,
    props: {
      bonus: num({ de: 'Zeitgutschrift (s)', en: 'Time credit (s)' }, 1, 0, 10),
    },
    sprite: { de: 'funkelnder Smaragd', size: '16×16, gern GIF' },
  },
  key: {
    name: { de: 'Schlüssel', en: 'Key' },
    cells: [1, 1],
    resizable: false,
    props: {},
    sprite: { de: 'Schlüssel', size: '16×16' },
  },
  door: {
    name: { de: 'Tür', en: 'Door' },
    cells: [2, 3],
    resizable: false,
    props: {
      locked: bool({ de: 'braucht Schlüssel', en: 'needs key' }, false),
      mode: choice({ de: 'Wirkung', en: 'Effect' }, 'exit', [
        { value: 'exit', label: { de: 'Levelausgang', en: 'level exit' } },
        { value: 'warp', label: { de: 'Durchgang zu Ziel-Tür', en: 'passage to target door' } },
      ]),
      targetId: target({ de: 'Ziel-Tür', en: 'Target door' }, 'door'),
    },
    sprite: { de: 'Tür zu und offen (zwei Bilder nebeneinander optional)', size: '32×48' },
  },
  portal: {
    name: { de: 'Portal', en: 'Portal' },
    cells: [2, 2],
    resizable: false,
    props: {
      targetId: target({ de: 'Ziel-Portal', en: 'Target portal' }, 'portal'),
    },
    sprite: { de: 'wirbelndes Portal', size: '32×32, gern GIF' },
  },
  walker: {
    name: { de: 'Gegner: Läufer', en: 'Enemy: walker' },
    cells: [1, 1],
    resizable: false,
    deadly: true,
    props: {
      range: num({ de: 'Patrouille (Zellen)', en: 'Patrol (cells)' }, 4, 1, 40),
      speed: num({ de: 'Tempo (px/Tick ×10)', en: 'Speed (px/tick ×10)' }, 6, 2, 25),
      stompable: bool({ de: 'durch Draufspringen besiegbar', en: 'stompable' }, true),
    },
    sprite: { de: 'laufender Gegner', size: '16×16 oder 24×24, gern GIF' },
  },
  flyer: {
    name: { de: 'Gegner: Flieger', en: 'Enemy: flyer' },
    cells: [1, 1],
    resizable: false,
    deadly: true,
    props: {
      axis: choice({ de: 'Flugrichtung', en: 'Flight axis' }, 'v', [
        { value: 'v', label: { de: 'auf und ab', en: 'up and down' } },
        { value: 'h', label: { de: 'hin und her', en: 'back and forth' } },
      ]),
      range: num({ de: 'Strecke (Zellen)', en: 'Distance (cells)' }, 3, 1, 30),
      speed: num({ de: 'Tempo (px/Tick ×10)', en: 'Speed (px/tick ×10)' }, 7, 2, 25),
      stompable: bool({ de: 'durch Draufspringen besiegbar', en: 'stompable' }, true),
    },
    sprite: { de: 'schwebender Gegner', size: '16×16 oder 24×24, gern GIF' },
  },
  checkpoint: {
    name: { de: 'Checkpoint', en: 'Checkpoint' },
    cells: [1, 2],
    resizable: false,
    props: {},
    sprite: { de: 'Fahne oder Laterne, aus und an', size: '16×32' },
  },
  flag: {
    name: { de: 'Zielflagge', en: 'Goal flag' },
    cells: [2, 3],
    resizable: false,
    unique: true,
    props: {},
    sprite: { de: 'Zielflagge', size: '32×48, gern GIF' },
  },
};

/** Die Typnamen in Anzeige-Reihenfolge (Editor-Palette, Admin-Liste). */
export const ELEMENT_TYPES = Object.keys(ELEMENTS);

/** Die Eigenschaften eines Typs mit Voreinstellungen als flaches Objekt. */
export function defaultProps(type) {
  const out = {};
  for (const [key, spec] of Object.entries(ELEMENTS[type]?.props ?? {})) {
    out[key] = spec.def;
  }
  return out;
}

/**
 * Bereinigt die Eigenschaften eines platzierten Elements: unbekannte Felder
 * fliegen raus, Zahlen werden geklemmt, alles Fehlende bekommt die
 * Voreinstellung. Läuft im Editor vor dem Speichern und in mapRules.js noch
 * einmal – gültig ist nur, was hier durchkommt.
 */
export function cleanProps(type, raw) {
  const spec = ELEMENTS[type]?.props ?? {};
  const out = defaultProps(type);
  if (raw === null || typeof raw !== 'object') return out;
  for (const [key, def] of Object.entries(spec)) {
    const value = raw[key];
    if (value === undefined) continue;
    if (def.kind === 'number') {
      const n = Number(value);
      if (Number.isFinite(n)) out[key] = Math.min(def.max, Math.max(def.min, Math.round(n)));
    } else if (def.kind === 'bool') {
      out[key] = value === true;
    } else if (def.kind === 'choice') {
      if (def.options.some((o) => o.value === value)) out[key] = value;
    } else if (def.kind === 'target') {
      if (typeof value === 'string' && /^[a-z0-9-]{0,24}$/.test(value)) out[key] = value;
    }
  }
  return out;
}
