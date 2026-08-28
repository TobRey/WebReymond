/**
 * AI Groove – AudioContext-Verwaltung.
 *
 * Besonderheiten:
 *  - Browser (vor allem Safari auf dem iPhone) starten Audio nur nach einer
 *    echten Nutzergeste. Deshalb wird der Context beim ersten Tippen erzeugt
 *    bzw. fortgesetzt und mit einem stummen Buffer "entsperrt".
 *  - iOS unterbricht Audio bei Anrufen o. Ae.; wir reagieren auf statechange.
 */

import { bus } from '../core/bus.js';
import { settings } from '../core/settings.js';
import { isIOS } from '../core/util.js';

let ctx = null;
let unlocked = false;
let unlockHandlersAttached = false;
const readyWaiters = [];

export const AUDIO_EVENTS = {
  READY: 'audio:ready',
  SUSPENDED: 'audio:suspended',
  BLOCKED: 'audio:blocked',
};

function createContext() {
  const Ctor = window.AudioContext || window.webkitAudioContext;
  if (!Ctor) {
    bus.emit(AUDIO_EVENTS.BLOCKED, {
      message: 'Dieser Browser unterstützt die Web Audio API nicht. Bitte einen aktuellen Browser verwenden.',
    });
    throw new Error('Web Audio API nicht verfügbar');
  }

  const options = { latencyHint: settings.latencyHint() };
  // Auf iOS ist 44100 Hz die native Rate; eine abweichende Rate erzwingt
  // teures Resampling und erhoeht die Latenz. Daher nicht setzen.
  let instance;
  try {
    instance = new Ctor(options);
  } catch (_) {
    instance = new Ctor();
  }

  instance.addEventListener?.('statechange', () => {
    if (instance.state === 'running') bus.emit(AUDIO_EVENTS.READY, instance);
    else bus.emit(AUDIO_EVENTS.SUSPENDED, instance);
  });

  return instance;
}

/** Liefert den AudioContext (erzeugt ihn beim ersten Aufruf). */
export function getContext() {
  if (!ctx) ctx = createContext();
  return ctx;
}

export function contextState() {
  return ctx ? ctx.state : 'none';
}

export function isUnlocked() {
  return unlocked && ctx && ctx.state === 'running';
}

/**
 * Entsperrt Audio. Muss synchron aus einem Nutzerereignis heraus aufgerufen
 * werden, sonst blockiert Safari die Wiedergabe.
 */
export async function unlockAudio() {
  const c = getContext();
  try {
    if (c.state !== 'running') await c.resume();
  } catch (_) {
    /* wird unten geprueft */
  }

  if (!unlocked) {
    // Ein extrem kurzer, stummer Buffer beendet Safaris "silent mode".
    try {
      const buf = c.createBuffer(1, 1, c.sampleRate);
      const src = c.createBufferSource();
      src.buffer = buf;
      src.connect(c.destination);
      src.start(0);
      src.onended = () => src.disconnect();
    } catch (_) {
      /* egal */
    }
  }

  if (c.state === 'running') {
    unlocked = true;
    while (readyWaiters.length) readyWaiters.shift()(c);
    bus.emit(AUDIO_EVENTS.READY, c);
  }
  return c;
}

/** Wartet, bis Audio laeuft. */
export function whenReady() {
  if (isUnlocked()) return Promise.resolve(ctx);
  return new Promise((resolve) => readyWaiters.push(resolve));
}

/**
 * Haengt einmalige Entsperr-Handler an das Dokument.
 * Wird beim Start aufgerufen; die Handler entfernen sich nach Erfolg selbst.
 */
export function attachUnlockHandlers() {
  if (unlockHandlersAttached) return;
  unlockHandlersAttached = true;

  const events = ['pointerdown', 'touchend', 'keydown'];
  const handler = () => {
    unlockAudio().then(() => {
      if (isUnlocked()) {
        for (const ev of events) document.removeEventListener(ev, handler, true);
      }
    });
  };
  for (const ev of events) document.addEventListener(ev, handler, true);

  // Nach einer Unterbrechung (Anruf, Sperrbildschirm) wieder aufnehmen.
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible' && ctx && ctx.state === 'suspended') {
      ctx.resume().catch(() => {});
    }
  });
}

/**
 * Aendert das Ausgabegeraet, sofern der Browser es erlaubt (Chrome/Edge).
 * Safari und Firefox unterstuetzen setSinkId fuer AudioContext nicht.
 */
export async function setOutputDevice(deviceId) {
  const c = getContext();
  if (typeof c.setSinkId !== 'function') {
    return { ok: false, reason: 'Dieser Browser erlaubt keine Auswahl des Audioausgangs.' };
  }
  try {
    await c.setSinkId(deviceId || '');
    return { ok: true };
  } catch (err) {
    return { ok: false, reason: 'Das Ausgabegerät konnte nicht gesetzt werden.' };
  }
}

/** Gemessene Ausgabelatenz in Millisekunden (fuer die Diagnose). */
export function latencyInfo() {
  const c = ctx;
  if (!c) return { base: 0, output: 0, sampleRate: 0 };
  return {
    base: (c.baseLatency || 0) * 1000,
    output: (c.outputLatency || 0) * 1000,
    sampleRate: c.sampleRate,
    state: c.state,
    ios: isIOS,
  };
}

/** Schliesst den Context vollstaendig (nur beim Zuruecksetzen der App). */
export async function closeContext() {
  if (!ctx) return;
  try {
    await ctx.close();
  } catch (_) {
    /* egal */
  }
  ctx = null;
  unlocked = false;
}
