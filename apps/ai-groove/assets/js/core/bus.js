/**
 * AI Groove – winziger Event-Bus.
 *
 * Trennt Audioengine und Oberflaeche: die Engine sendet Ereignisse,
 * die UI hoert zu. Die Engine kennt kein einziges DOM-Element.
 */

class Bus {
  constructor() {
    /** @type {Map<string, Set<Function>>} */
    this.map = new Map();
  }

  /** Registriert einen Zuhoerer und liefert eine Abmeldefunktion zurueck. */
  on(event, handler) {
    let set = this.map.get(event);
    if (!set) {
      set = new Set();
      this.map.set(event, set);
    }
    set.add(handler);
    return () => this.off(event, handler);
  }

  once(event, handler) {
    const off = this.on(event, (...args) => {
      off();
      handler(...args);
    });
    return off;
  }

  off(event, handler) {
    const set = this.map.get(event);
    if (set) {
      set.delete(handler);
      if (set.size === 0) this.map.delete(event);
    }
  }

  emit(event, payload) {
    const set = this.map.get(event);
    if (!set) return;
    // Kopie, damit sich Zuhoerer waehrend des Durchlaufs abmelden duerfen.
    for (const handler of Array.from(set)) {
      try {
        handler(payload);
      } catch (err) {
        console.error(`[bus] Fehler im Handler für "${event}"`, err);
      }
    }
  }

  clear() {
    this.map.clear();
  }
}

export const bus = new Bus();

/** Bekannte Ereignisnamen – zentral, damit Tippfehler auffallen. */
export const EV = {
  PROJECT_LOADED: 'project:loaded',
  PROJECT_CHANGED: 'project:changed',
  PROJECT_SAVED: 'project:saved',
  PROJECT_DIRTY: 'project:dirty',

  SAMPLES_CHANGED: 'samples:changed',
  SAMPLE_EDITED: 'sample:edited',
  PATTERNS_CHANGED: 'patterns:changed',
  PATTERN_SELECTED: 'pattern:selected',
  CLIPS_CHANGED: 'clips:changed',
  PADS_CHANGED: 'pads:changed',
  MIXER_CHANGED: 'mixer:changed',
  TRANSPORT_CHANGED: 'transport:changed',
  SELECTION_CHANGED: 'selection:changed',

  TRANSPORT_TICK: 'transport:tick',
  TRANSPORT_PLAY: 'transport:play',
  TRANSPORT_STOP: 'transport:stop',
  NOTE_TRIGGERED: 'note:triggered',
  PAD_TRIGGERED: 'pad:triggered',

  HISTORY_CHANGED: 'history:changed',
  SETTINGS_CHANGED: 'settings:changed',
  AI_STATUS: 'ai:status',
  VIEW_CHANGED: 'view:changed',
  STORAGE_WARNING: 'storage:warning',
};
