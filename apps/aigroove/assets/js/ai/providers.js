/**
 * AI Groove – KI-Anbieter.
 *
 * AI Groove nutzt ausschliesslich die eigene KI **GrooveNet**. Sie laeuft
 * vollstaendig im Browser des Nutzers:
 *
 *   - kein Konto, kein API-Key, keine Registrierung
 *   - keine laufenden Kosten, kein Guthaben, keine Nutzungsgrenzen
 *   - keine Uebertragung: Prompt und Audio verlassen das Geraet nie
 *   - funktioniert offline, auch als installierte App
 *
 * Die Schnittstelle AIProviderInterface bleibt bestehen, damit sich spaeter
 * weitere Erzeuger ergaenzen lassen, ohne den Rest der App anzufassen.
 */

import { encode, suggestedSeconds } from './groovenet/encoder.js';
import { encodeWav } from '../audio/wav.js';

export class AIError extends Error {
  constructor(code, message, detail = '') {
    super(message);
    this.name = 'AIError';
    this.code = code;
    this.detail = detail;
  }
}

/**
 * Erzeugt WAV-Bytes aus rohen Kanaldaten (ohne AudioContext).
 *
 * Bewusst 16 Bit: Safari - und damit jedes iPhone und iPad - dekodiert
 * 24-Bit-WAV nicht zuverlaessig. Der Unterschied ist bei erzeugten Samples
 * ohnehin nicht hoerbar, ein stummes Sample dagegen sehr wohl.
 */
function wavFromChannels(channels, sampleRate) {
  const fake = {
    numberOfChannels: channels.length,
    length: channels[0].length,
    sampleRate,
    getChannelData: (i) => channels[i],
  };
  return encodeWav(fake, { bitDepth: 16 });
}

/**
 * Gemeinsame Schnittstelle aller Erzeuger.
 * Neue Anbieter muessen nur diese Klasse erweitern und registriert werden.
 */
export class AIProviderInterface {
  constructor(config) {
    this.id = config.id;
    this.label = config.label;
    this.description = config.description || '';
    this.website = config.website || '';
    this.needsKey = config.needsKey === true;
    this.local = config.local !== false;
    this.capabilities = {
      textToAudio: true,
      audioToAudio: false,
      maxDuration: 30,
      ...config.capabilities,
    };
  }

  async generate() {
    throw new AIError('not_implemented', 'Dieser Erzeuger unterstützt keine Erzeugung.');
  }
}

// --- Arbeitsstrang -----------------------------------------------------------

let worker = null;
let workerBroken = false;
let nextJobId = 1;
const jobs = new Map();

/**
 * Startet den Arbeitsstrang beim ersten Bedarf.
 * Schlaegt das fehl (sehr alte Browser, strenge Richtlinien), wird still auf
 * Berechnung im Hauptstrang umgeschaltet.
 */
function ensureWorker() {
  if (worker || workerBroken) return worker;
  try {
    worker = new Worker(new URL('../workers/groovenet-worker.js', import.meta.url), { type: 'module' });
    worker.addEventListener('message', (event) => {
      const { id, type } = event.data || {};
      const job = jobs.get(id);
      if (!job) return;
      if (type === 'progress') {
        job.onProgress(event.data.progress, event.data.label);
      } else if (type === 'done') {
        jobs.delete(id);
        job.resolve(event.data.results);
      } else if (type === 'error') {
        jobs.delete(id);
        job.reject(new AIError('engine_error', event.data.message || 'Die Klangerzeugung ist fehlgeschlagen.'));
      }
    });
    worker.addEventListener('error', () => {
      // Ab hier im Hauptstrang weiterrechnen, damit nichts stehen bleibt.
      workerBroken = true;
      for (const [, job] of jobs) job.reject(new AIError('worker_failed', 'Der Rechenstrang wurde beendet.'));
      jobs.clear();
      worker = null;
    });
  } catch (_) {
    workerBroken = true;
    worker = null;
  }
  return worker;
}

/** Fuehrt einen Auftrag im Arbeitsstrang aus. */
function runInWorker(op, payload, onProgress, signal) {
  const active = ensureWorker();
  if (!active) return null;

  const id = nextJobId++;
  return new Promise((resolve, reject) => {
    jobs.set(id, { resolve, reject, onProgress });
    if (signal) {
      signal.addEventListener(
        'abort',
        () => {
          active.postMessage({ id, op: 'cancel' });
          jobs.delete(id);
          const err = new Error('Abgebrochen');
          err.name = 'AbortError';
          reject(err);
        },
        { once: true },
      );
    }
    active.postMessage({ id, op, payload });
  });
}

/** Rueckfallebene: dieselbe Engine, nur ohne eigenen Arbeitsstrang. */
async function runInline(op, payload, onProgress) {
  const groovenet = await import('./groovenet/index.js');
  return op === 'transform'
    ? groovenet.transform({ ...payload, onProgress })
    : groovenet.generate({ ...payload, onProgress });
}

async function run(op, payload, onProgress, signal) {
  try {
    const viaWorker = runInWorker(op, payload, onProgress, signal);
    if (viaWorker) return await viaWorker;
  } catch (error) {
    if (error.name === 'AbortError') throw error;
    if (error.code !== 'worker_failed') throw error;
    // Der Arbeitsstrang ist ausgefallen – unten wird es ohne ihn versucht.
  }
  return runInline(op, payload, onProgress);
}

// --- GrooveNet ---------------------------------------------------------------

/** Vorgabe fuer die Rechenzeit. Wird aus den Einstellungen gesetzt. */
let qualityPreset = 'balanced';

export function setQuality(preset) {
  qualityPreset = ['fast', 'balanced', 'best'].includes(preset) ? preset : 'balanced';
}

export function getQuality() {
  return qualityPreset;
}

class GrooveNetProvider extends AIProviderInterface {
  constructor() {
    super({
      id: 'groovenet',
      label: 'GrooveNet – eigene KI',
      description:
        'Versteht den Prompt, stellt ein Zielspektrum auf und sucht per Evolution den passenden Klang. Läuft im Browser: ohne Key, ohne Kosten, ohne Internet.',
      needsKey: false,
      local: true,
      capabilities: { textToAudio: true, audioToAudio: true, maxDuration: 190 },
    });
  }

  async generate({ prompt, duration, count = 4, seed, input, onProgress = () => {}, signal, quality }) {
    const chosen = quality || qualityPreset;
    const payload = input?.channels
      ? {
          op: 'transform',
          channels: input.channels.map((ch) => new Float32Array(ch)),
          sampleRate: input.sampleRate,
          prompt,
          strength: input.strength ?? 0.65,
          count,
          seed,
        }
      : {
          prompt,
          duration,
          count,
          seed,
          quality: chosen,
          sampleRate: 44100,
        };

    const op = input?.channels ? 'transform' : 'generate';
    const results = await run(op, payload, onProgress, signal);

    return results.map((item, index) => ({
      bytes: wavFromChannels(item.channels, item.sampleRate),
      mime: 'audio/wav',
      label: item.label || `Variante ${index + 1}`,
      meta: item.meta,
    }));
  }
}

// --- Registry ----------------------------------------------------------------

const PROVIDERS = new Map();
for (const p of [new GrooveNetProvider()]) PROVIDERS.set(p.id, p);

export function listProviders() {
  return [...PROVIDERS.values()];
}

export function getProvider(id) {
  return PROVIDERS.get(id) || PROVIDERS.get('groovenet');
}

/** Der Erzeuger, der gerade benutzt wird. */
export function activeProvider() {
  return PROVIDERS.get('groovenet');
}

/** Registriert einen zusaetzlichen Erzeuger zur Laufzeit. */
export function registerProvider(provider) {
  if (!(provider instanceof AIProviderInterface)) {
    throw new Error('Anbieter muss AIProviderInterface erweitern.');
  }
  PROVIDERS.set(provider.id, provider);
}

/** Fuer die Diagnose: laeuft die KI im eigenen Arbeitsstrang? */
export function engineStatus() {
  ensureWorker();
  return {
    engine: 'GrooveNet 1.0',
    worker: worker ? 'eigener Rechenstrang' : 'Hauptstrang (Rückfallebene)',
    quality: qualityPreset,
    online: false,
  };
}

/** Prompt-Deutung fuer die Oberflaeche (Klangart, Tempo, Tonart, Länge). */
export function analysePrompt(prompt) {
  const intent = encode(prompt);
  return {
    kind: intent.archetype,
    mode: intent.mode,
    genre: intent.genre,
    bpm: intent.bpm,
    scale: intent.scale,
    root: intent.root,
    bars: intent.bars,
    seconds: suggestedSeconds(intent),
    matched: intent.matched,
    text: intent.text,
  };
}
