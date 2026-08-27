/**
 * AI Groove – Undo/Redo.
 *
 * Arbeitsweise: pro Aktion wird nur der betroffene Teil des Projekts
 * (z. B. nur "patterns" oder nur "clips") als JSON-Schnappschuss gesichert.
 * Audiodaten liegen NICHT im Projekt, sondern in IndexedDB – es werden also
 * niemals Megabytes an AudioBuffern kopiert.
 */

import { clone } from './util.js';

export class History {
  /**
   * @param {object} options
   * @param {() => object} options.getState  Liefert das aktuelle Projekt.
   * @param {(changedScopes: string[], label: string) => void} options.onApply
   * @param {number} [options.limit]
   */
  constructor({ getState, onApply, limit = 150 }) {
    this.getState = getState;
    this.onApply = onApply;
    this.limit = limit;
    this.past = [];
    this.future = [];
    this.pending = null;
    this.enabled = true;
  }

  get canUndo() {
    return this.past.length > 0;
  }

  get canRedo() {
    return this.future.length > 0;
  }

  get undoLabel() {
    return this.past.length ? this.past[this.past.length - 1].label : '';
  }

  get redoLabel() {
    return this.future.length ? this.future[this.future.length - 1].label : '';
  }

  #snapshot(scopes) {
    const state = this.getState();
    const out = {};
    for (const key of scopes) out[key] = clone(state[key]);
    return out;
  }

  #apply(snapshot) {
    const state = this.getState();
    for (const [key, value] of Object.entries(snapshot)) {
      state[key] = clone(value);
    }
  }

  /**
   * Beginnt eine Aktion. Muss mit commit() oder cancel() beendet werden.
   * Verschachtelte Aufrufe werden zusammengefasst (nur der aeusserste zaehlt).
   */
  begin(label, scopes) {
    if (!this.enabled) return false;
    if (this.pending) {
      // Bereits offene Transaktion: Scopes ergaenzen.
      for (const s of scopes) {
        if (!this.pending.scopes.includes(s)) {
          this.pending.scopes.push(s);
          this.pending.before[s] = clone(this.getState()[s]);
        }
      }
      this.pending.depth++;
      return false;
    }
    this.pending = {
      label,
      scopes: [...scopes],
      before: this.#snapshot(scopes),
      depth: 1,
    };
    return true;
  }

  commit() {
    if (!this.pending) return;
    this.pending.depth--;
    if (this.pending.depth > 0) return;

    const { label, scopes, before } = this.pending;
    this.pending = null;
    const after = this.#snapshot(scopes);

    // Nichts veraendert? Dann keinen Eintrag erzeugen.
    if (JSON.stringify(before) === JSON.stringify(after)) return;

    this.past.push({ label, scopes, before, after });
    if (this.past.length > this.limit) this.past.shift();
    this.future.length = 0;
  }

  cancel() {
    if (!this.pending) return;
    this.pending.depth--;
    if (this.pending.depth > 0) return;
    this.#apply(this.pending.before);
    this.pending = null;
  }

  /** Bequeme Kurzform: run('Note setzen', ['patterns'], () => { … }) */
  run(label, scopes, fn) {
    this.begin(label, scopes);
    let result;
    try {
      result = fn();
    } catch (err) {
      this.cancel();
      throw err;
    }
    this.commit();
    return result;
  }

  undo() {
    const entry = this.past.pop();
    if (!entry) return null;
    this.#apply(entry.before);
    this.future.push(entry);
    this.onApply?.(entry.scopes, entry.label);
    return entry.label;
  }

  redo() {
    const entry = this.future.pop();
    if (!entry) return null;
    this.#apply(entry.after);
    this.past.push(entry);
    this.onApply?.(entry.scopes, entry.label);
    return entry.label;
  }

  clear() {
    this.past.length = 0;
    this.future.length = 0;
    this.pending = null;
  }

  /** Fuehrt eine Aktion ohne Undo-Eintrag aus (z. B. beim Laden). */
  silent(fn) {
    const prev = this.enabled;
    this.enabled = false;
    try {
      return fn();
    } finally {
      this.enabled = prev;
    }
  }
}
