/**
 * AI Groove – App-Einstellungen.
 *
 * Diese Werte gehoeren zum Geraet, nicht zum Projekt, und liegen deshalb im
 * localStorage. Es werden hier ausdruecklich KEINE API-Keys gespeichert
 * (dafuer siehe ai/keystore.js).
 */

import { bus, EV } from './bus.js';
import { clamp } from './util.js';

const KEY = 'aigroove.settings.v1';

const DEFAULTS = {
  theme: 'dark', // dark | light | auto
  reduceMotion: false,
  performanceMode: 'balanced', // low-latency | balanced | battery
  defaultBpm: 128,
  metronomeOn: false,
  metronomeGain: 0.45,
  countIn: 0,
  micDeviceId: '',
  micEchoCancellation: false,
  micNoiseSuppression: false,
  micAutoGain: false,
  outputDeviceId: '',
  tutorialDone: false,
  diagnostics: false,
  quantizeRecording: true,
  quantizeGrid: 16,
  snapEnabled: true,
  showKeyLabels: true,
};

function read() {
  try {
    const raw = localStorage.getItem(KEY);
    if (!raw) return { ...DEFAULTS };
    const parsed = JSON.parse(raw);
    return { ...DEFAULTS, ...(parsed && typeof parsed === 'object' ? parsed : {}) };
  } catch (_) {
    return { ...DEFAULTS };
  }
}

class Settings {
  constructor() {
    this.values = read();
    this.values.defaultBpm = clamp(Number(this.values.defaultBpm) || 128, 40, 250);
  }

  get(key) {
    return this.values[key];
  }

  all() {
    return { ...this.values };
  }

  set(key, value) {
    if (this.values[key] === value) return;
    this.values[key] = value;
    this.persist();
    bus.emit(EV.SETTINGS_CHANGED, { key, value });
    this.apply();
  }

  update(patch) {
    let changed = false;
    for (const [k, v] of Object.entries(patch)) {
      if (this.values[k] !== v) {
        this.values[k] = v;
        changed = true;
      }
    }
    if (!changed) return;
    this.persist();
    bus.emit(EV.SETTINGS_CHANGED, patch);
    this.apply();
  }

  persist() {
    try {
      localStorage.setItem(KEY, JSON.stringify(this.values));
    } catch (_) {
      // Privater Modus o. Ae. – Einstellungen gelten dann nur fuer diese Sitzung.
    }
  }

  reset() {
    this.values = { ...DEFAULTS };
    this.persist();
    this.apply();
    bus.emit(EV.SETTINGS_CHANGED, this.values);
  }

  /** Uebertraegt Darstellungseinstellungen auf das Wurzelelement. */
  apply() {
    const root = document.documentElement;
    root.dataset.theme = this.values.theme === 'auto' ? 'auto' : this.values.theme;
    if (this.values.reduceMotion) root.dataset.motion = 'reduced';
    else delete root.dataset.motion;
  }

  /** latencyHint fuer den AudioContext. */
  latencyHint() {
    switch (this.values.performanceMode) {
      case 'low-latency':
        return 'interactive';
      case 'battery':
        return 'playback';
      default:
        return 'interactive';
    }
  }

  /** Vorausschau des Schedulers in Sekunden. */
  lookahead() {
    switch (this.values.performanceMode) {
      case 'low-latency':
        return 0.08;
      case 'battery':
        return 0.25;
      default:
        return 0.12;
    }
  }
}

export const settings = new Settings();
