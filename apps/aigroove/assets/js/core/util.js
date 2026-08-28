/**
 * AI Groove – allgemeine Hilfsfunktionen.
 * Bewusst frei von DOM- und Audio-Abhaengigkeiten.
 */

/** Ticks pro Viertelnote. 96 ist durch 2, 3, 4, 6, 8, 12, 16, 24, 32 teilbar
 *  und deckt damit 1/32-Raster und Triolen exakt ab. */
export const PPQ = 96;

/** Ticks pro Takt bei 4/4. */
export const TICKS_PER_BAR = PPQ * 4;

export const clamp = (v, min, max) => (v < min ? min : v > max ? max : v);

export const lerp = (a, b, t) => a + (b - a) * t;

/** Ganzzahl-Anteil ohne Rundungsfehler bei negativen Werten. */
export const floorDiv = (a, b) => Math.floor(a / b);

let idCounter = 0;
/** Kurze, kollisionsarme ID. */
export function uid(prefix = 'id') {
  idCounter = (idCounter + 1) % 0xffff;
  const rnd =
    typeof crypto !== 'undefined' && crypto.getRandomValues
      ? crypto.getRandomValues(new Uint32Array(1))[0].toString(36)
      : Math.floor(Math.random() * 0xffffffff).toString(36);
  return `${prefix}_${Date.now().toString(36)}${idCounter.toString(36)}${rnd}`;
}

/** Sekunden -> "m:ss.mmm" */
export function formatTime(sec, withMs = true) {
  if (!isFinite(sec) || sec < 0) sec = 0;
  const m = Math.floor(sec / 60);
  const s = Math.floor(sec % 60);
  if (!withMs) return `${m}:${String(s).padStart(2, '0')}`;
  const ms = Math.floor((sec % 1) * 1000);
  return `${m}:${String(s).padStart(2, '0')}.${String(ms).padStart(3, '0')}`;
}

/** Ticks -> "Takt.Beat.Sechzehntel" (1-basiert, wie in DAWs ueblich) */
export function formatBBT(ticks, beatsPerBar = 4) {
  const t = Math.max(0, Math.floor(ticks));
  const bar = Math.floor(t / (PPQ * beatsPerBar)) + 1;
  const beat = Math.floor((t % (PPQ * beatsPerBar)) / PPQ) + 1;
  const sub = Math.floor((t % PPQ) / (PPQ / 4)) + 1;
  return `${bar}.${beat}.${sub}`;
}

export function humanBytes(n) {
  if (!isFinite(n) || n <= 0) return '0 B';
  const u = ['B', 'KB', 'MB', 'GB', 'TB'];
  const i = Math.min(u.length - 1, Math.floor(Math.log(n) / Math.log(1024)));
  return `${(n / 1024 ** i).toFixed(i === 0 ? 0 : 1)} ${u[i]}`;
}

export function relativeTime(ts) {
  const diff = Date.now() - ts;
  const min = Math.round(diff / 60000);
  if (min < 1) return 'gerade eben';
  if (min < 60) return `vor ${min} Min.`;
  const h = Math.round(min / 60);
  if (h < 24) return `vor ${h} Std.`;
  const d = Math.round(h / 24);
  if (d < 30) return `vor ${d} Tg.`;
  return new Date(ts).toLocaleDateString('de-DE');
}

export function debounce(fn, wait = 150) {
  let t = 0;
  const wrapped = (...args) => {
    clearTimeout(t);
    t = setTimeout(() => fn(...args), wait);
  };
  wrapped.cancel = () => clearTimeout(t);
  wrapped.flush = (...args) => {
    clearTimeout(t);
    fn(...args);
  };
  return wrapped;
}

export function throttle(fn, wait = 100) {
  let last = 0;
  let timer = 0;
  return (...args) => {
    const now = Date.now();
    const remaining = wait - (now - last);
    if (remaining <= 0) {
      clearTimeout(timer);
      last = now;
      fn(...args);
    } else if (!timer) {
      timer = setTimeout(() => {
        timer = 0;
        last = Date.now();
        fn(...args);
      }, remaining);
    }
  };
}

/**
 * Fasst Aufrufe zu genau einem Aufruf pro Frame zusammen.
 * Wichtig fuer Canvas-Repaints: verhindert, dass Zeichnen den Hauptthread flutet.
 */
export function rafThrottle(fn) {
  let queued = false;
  let lastArgs = null;
  const run = () => {
    queued = false;
    fn(...(lastArgs || []));
  };
  const wrapped = (...args) => {
    lastArgs = args;
    if (queued) return;
    queued = true;
    requestAnimationFrame(run);
  };
  wrapped.cancel = () => {
    queued = false;
  };
  return wrapped;
}

/** Strukturierte Kopie mit Fallback fuer aeltere Safari-Versionen. */
export function clone(obj) {
  if (typeof structuredClone === 'function') {
    try {
      return structuredClone(obj);
    } catch (_) {
      /* faellt unten durch */
    }
  }
  return JSON.parse(JSON.stringify(obj));
}

/** Linearer Verstaerkungsfaktor -> Dezibel. */
export const gainToDb = (g) => (g <= 0.000016 ? -96 : 20 * Math.log10(g));

/** Dezibel -> linearer Verstaerkungsfaktor. */
export const dbToGain = (db) => (db <= -96 ? 0 : 10 ** (db / 20));

/**
 * Fader-Position (0..1) -> Verstaerkung.
 * Bewusst nicht linear: 1.0 klingt deutlich lauter als der Standardwert 0.3.
 * Kurve x^2 * 4 ergibt bei 0.3 ca. 0.36 und bei 1.0 den Faktor 4 (+12 dB).
 */
export const faderToGain = (x) => {
  const v = clamp(x, 0, 1);
  return v * v * 4;
};

export const gainToFader = (g) => clamp(Math.sqrt(Math.max(0, g) / 4), 0, 1);

/** Halbtoene -> Abspielrate (gleichstufige Stimmung). */
export const semitonesToRate = (st) => 2 ** (st / 12);

const NOTE_NAMES = ['C', 'C#', 'D', 'D#', 'E', 'F', 'F#', 'G', 'G#', 'A', 'A#', 'B'];

/** MIDI-Notennummer -> "C4". 60 = C4. */
export function noteName(midi) {
  const n = Math.round(midi);
  return `${NOTE_NAMES[((n % 12) + 12) % 12]}${Math.floor(n / 12) - 1}`;
}

export function isBlackKey(midi) {
  return [1, 3, 6, 8, 10].includes(((Math.round(midi) % 12) + 12) % 12);
}

/** BPM + Ticks -> Sekunden. */
export const ticksToSeconds = (ticks, bpm) => (ticks / PPQ) * (60 / bpm);

/** Sekunden -> Ticks. */
export const secondsToTicks = (sec, bpm) => (sec * bpm * PPQ) / 60;

/** Rastert einen Tick auf das naechste Raster. */
export function quantizeTick(tick, gridTicks, mode = 'nearest') {
  if (gridTicks <= 0) return Math.round(tick);
  if (mode === 'floor') return Math.floor(tick / gridTicks) * gridTicks;
  if (mode === 'ceil') return Math.ceil(tick / gridTicks) * gridTicks;
  return Math.round(tick / gridTicks) * gridTicks;
}

/** Loest einen Datei-Download aus, ohne Object-URLs zu lecken. */
export function downloadBlob(blob, filename) {
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  a.rel = 'noopener';
  document.body.appendChild(a);
  a.click();
  a.remove();
  // Safari braucht einen Moment, bevor die URL freigegeben werden darf.
  setTimeout(() => URL.revokeObjectURL(url), 20000);
}

/** Dateinamen von gefaehrlichen Zeichen befreien. */
export function safeFilename(name, fallback = 'AI-Groove') {
  const clean = String(name || '')
    .replace(/[\u0000-\u001f\u007f\\/:*?"<>|]/g, '')
    .replace(/\s+/g, ' ')
    .trim()
    .slice(0, 80);
  return clean || fallback;
}

/** Vibration, wenn das Geraet es unterstuetzt (iOS ignoriert dies stillschweigend). */
export function haptic(pattern = 8) {
  try {
    if (navigator.vibrate) navigator.vibrate(pattern);
  } catch (_) {
    /* egal */
  }
}

/** ArrayBuffer -> Base64 (blockweise, damit der Stack nicht ueberlaeuft). */
export function bufferToBase64(buffer) {
  const bytes = buffer instanceof Uint8Array ? buffer : new Uint8Array(buffer);
  const chunk = 0x8000;
  let binary = '';
  for (let i = 0; i < bytes.length; i += chunk) {
    binary += String.fromCharCode.apply(null, bytes.subarray(i, i + chunk));
  }
  return btoa(binary);
}

export function base64ToBuffer(b64) {
  const bin = atob(b64);
  const out = new Uint8Array(bin.length);
  for (let i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
  return out;
}

/** Erkennt iOS/iPadOS – dort gelten Sonderregeln fuer Audio und Layout. */
export const isIOS = (() => {
  const ua = navigator.userAgent || '';
  return (
    /iPad|iPhone|iPod/.test(ua) ||
    (navigator.platform === 'MacIntel' && (navigator.maxTouchPoints || 0) > 1)
  );
})();

export const isSafari = (() => {
  const ua = navigator.userAgent || '';
  return /^((?!chrome|android|crios|fxios).)*safari/i.test(ua);
})();

export const isTouch = (() => {
  return (navigator.maxTouchPoints || 0) > 0 || 'ontouchstart' in window;
})();

/** Wartet n Millisekunden. */
export const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

/**
 * Leitet aus einem Prompt oder Dateinamen einen sprechenden Instrumentnamen ab.
 * Wird fuer die automatische Pattern-Benennung verwendet.
 */
const NAME_RULES = [
  [/\b(kick|bassdrum|bass\s?drum|bd|808\s?kick)\b/i, 'Kick'],
  [/\b(sub\s?bass|subbass)\b/i, 'Sub Bass'],
  [/\b(bass|bassline|reese)\b/i, 'Bass'],
  [/\b(snare|sd|rimshot|rim)\b/i, 'Snare'],
  [/\b(clap|handclap)\b/i, 'Clap'],
  [/\b(closed\s?hi[- ]?hat|closed\s?hat|chh|hi[- ]?hat|hihat|hh|hat)\b/i, 'Hi-Hat'],
  [/\b(open\s?hat|ohh)\b/i, 'Open Hat'],
  [/\b(ride|crash|cymbal)\b/i, 'Cymbal'],
  [/\b(tom|floor\s?tom)\b/i, 'Tom'],
  [/\b(perc|percussion|shaker|conga|bongo|rim)\b/i, 'Perc'],
  [/\b(vocal|vox|voice|chop|acapella)\b/i, 'Vocal'],
  [/\b(lead|melody|arp|arpeggio)\b/i, 'Lead'],
  [/\b(pad|atmo|atmosphere|drone|ambient)\b/i, 'Pad'],
  [/\b(stab|chord|hit)\b/i, 'Stab'],
  [/\b(riser|uplifter|sweep|build)\b/i, 'Riser'],
  [/\b(impact|downlifter|boom)\b/i, 'Impact'],
  [/\b(fx|effect|noise|texture)\b/i, 'FX'],
  [/\b(loop|groove|beat)\b/i, 'Loop'],
];

export function guessInstrumentName(text) {
  const s = String(text || '');
  for (const [re, name] of NAME_RULES) {
    if (re.test(s)) return name;
  }
  return null;
}

/** Farbindex 1..8 aus einer beliebigen ID ableiten (stabil). */
export function colorIndexFor(id) {
  let h = 0;
  const s = String(id);
  for (let i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) >>> 0;
  return (h % 8) + 1;
}

export function trackColor(id) {
  return `var(--t${colorIndexFor(id)})`;
}
