// Deterministischer Zufall. Math.random() wäre hier unbrauchbar: ohne
// reproduzierbare Türme liesse sich die Levelerzeugung nicht testen, und ein
// Fehler "manchmal ist die nächste Plattform unerreichbar" wäre nicht
// nachstellbar.
//
// mulberry32: 32-Bit-Zustand, sehr kurz, statistisch für ein Spiel mehr als
// ausreichend. Kein Kryptozufall – und wird auch nirgends dafür benutzt.

/**
 * @param {number} seed Ganzzahliger Startwert.
 * @returns {{ next: () => number, int: (min: number, max: number) => number,
 *   pick: <T>(items: T[]) => T, chance: (p: number) => boolean, fork: () => any }}
 */
export function createRng(seed) {
  let state = seed >>> 0;

  /** Gleichverteilt in [0, 1). */
  function next() {
    state = (state + 0x6d2b79f5) >>> 0;
    let t = state;
    t = Math.imul(t ^ (t >>> 15), t | 1);
    t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  }

  /** Ganzzahlig in [min, max], beide Grenzen eingeschlossen. */
  function int(min, max) {
    if (max < min) return min;
    return min + Math.floor(next() * (max - min + 1));
  }

  function pick(items) {
    return items[int(0, items.length - 1)];
  }

  function chance(p) {
    return next() < p;
  }

  /** Neuer Generator mit abgeleitetem Startwert – für unabhängige Ströme. */
  function fork() {
    return createRng((state ^ 0x9e3779b9) >>> 0);
  }

  return { next, int, pick, chance, fork };
}

/** Startwert aus einer beliebigen Zeichenkette (FNV-1a). */
export function seedFromString(text) {
  let h = 0x811c9dc5;
  for (let i = 0; i < text.length; i += 1) {
    h ^= text.charCodeAt(i);
    h = Math.imul(h, 0x01000193);
  }
  return h >>> 0;
}
