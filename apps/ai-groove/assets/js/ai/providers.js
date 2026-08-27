/**
 * AI Groove – KI-Anbieter.
 *
 * Alles laeuft ueber eine gemeinsame Schnittstelle (AIProviderInterface),
 * damit sich Anbieter austauschen lassen, ohne den Rest der App anzufassen.
 *
 * Sicherheit:
 *  - Es liegt KEIN Key im Quelltext.
 *  - Der Key des Nutzers wird pro Anfrage aus dem lokalen Speicher gelesen und
 *    danach nicht weiterverwendet.
 *  - Fehlermeldungen werden bereinigt, bevor sie angezeigt werden.
 */

import { keystore } from './keystore.js';
import { generateVariants, transformBuffer, analysePrompt } from './localsynth.js';
import { encodeWav } from '../audio/wav.js';
import { bufferToBase64, clamp } from '../core/util.js';

const PROXY_URL = 'api/ai-proxy.php';

export class AIError extends Error {
  constructor(code, message, detail = '') {
    super(message);
    this.name = 'AIError';
    this.code = code;
    this.detail = detail;
  }
}

/** Erzeugt WAV-Bytes aus rohen Kanaldaten (ohne AudioContext). */
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
 * Gemeinsame Schnittstelle aller Anbieter.
 * Neue Anbieter muessen nur diese Klasse erweitern und in PROVIDERS eintragen.
 */
export class AIProviderInterface {
  constructor(config) {
    this.id = config.id;
    this.label = config.label;
    this.description = config.description || '';
    this.website = config.website || '';
    this.needsKey = config.needsKey !== false;
    this.capabilities = {
      textToAudio: true,
      audioToAudio: false,
      maxDuration: 30,
      ...config.capabilities,
    };
  }

  /**
   * Erzeugt Varianten.
   * @param {object} request
   * @param {string} request.prompt
   * @param {number} request.duration Sekunden
   * @param {number} request.count
   * @param {number} [request.seed]
   * @param {{channels: Float32Array[], sampleRate:number, bytes?:ArrayBuffer, mime?:string}} [request.input]
   * @param {(p:number, label:string) => void} [request.onProgress]
   * @param {AbortSignal} [request.signal]
   * @returns {Promise<Array<{bytes:ArrayBuffer, mime:string, label:string}>>}
   */
  async generate() {
    throw new AIError('not_implemented', 'Dieser Anbieter unterstützt keine Erzeugung.');
  }
}

// --- Lokaler Synthesizer -----------------------------------------------------

class LocalSynthProvider extends AIProviderInterface {
  constructor() {
    super({
      id: 'local',
      label: 'Lokaler Synth-Generator',
      description:
        'Erzeugt Drums, Bass, Stabs, Riser und FX direkt im Browser – ohne Internet, ohne Key und ohne Kosten.',
      needsKey: false,
      capabilities: { textToAudio: true, audioToAudio: true, maxDuration: 30 },
    });
  }

  async generate({ prompt, duration, count = 4, seed, input, onProgress = () => {} }) {
    onProgress(0.1, 'Klang wird berechnet …');
    const sampleRate = input?.sampleRate || 44100;
    const results = [];

    if (input && input.channels) {
      // Variation eines vorhandenen Samples.
      for (let i = 0; i < count; i++) {
        const channels = transformBuffer(input.channels, sampleRate, prompt, (seed || 1) + i * 131);
        results.push({
          bytes: wavFromChannels(channels, sampleRate),
          mime: 'audio/wav',
          label: `Variante ${i + 1}`,
        });
        onProgress(0.1 + ((i + 1) / count) * 0.85, 'Klang wird berechnet …');
        await new Promise((r) => setTimeout(r, 0)); // Oberflaeche atmen lassen
      }
    } else {
      const variants = generateVariants({ prompt, duration, count, sampleRate, seed });
      for (let i = 0; i < variants.length; i++) {
        const v = variants[i];
        results.push({
          bytes: wavFromChannels(v.channels, v.sampleRate),
          mime: 'audio/wav',
          label: `Variante ${i + 1}`,
        });
        onProgress(0.1 + ((i + 1) / variants.length) * 0.85, 'Klang wird berechnet …');
        await new Promise((r) => setTimeout(r, 0));
      }
    }

    onProgress(1, 'Fertig');
    return results;
  }
}

// --- Anbieter ueber HTTP -----------------------------------------------------

/**
 * Fuehrt einen Aufruf ueber den eigenen PHP-Proxy aus.
 * Der Key wird ausschliesslich als Header dieser einen Anfrage gesendet.
 */
async function callProxy(payload, key, signal) {
  let response;
  try {
    response = await fetch(PROXY_URL, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-AIG-Key': key,
      },
      body: JSON.stringify(payload),
      signal,
      cache: 'no-store',
      credentials: 'omit',
    });
  } catch (err) {
    if (err.name === 'AbortError') throw err;
    throw new AIError('offline', 'Keine Verbindung zum Server. Bist du online?');
  }

  const type = response.headers.get('Content-Type') || '';
  if (response.ok && type.startsWith('audio/')) {
    return { bytes: await response.arrayBuffer(), mime: type.split(';')[0].trim() };
  }

  let message = 'Die KI-Anfrage ist fehlgeschlagen.';
  let detail = '';
  let code = 'provider_error';
  try {
    const json = await response.json();
    message = json?.error?.message || message;
    detail = json?.error?.detail || '';
    code = json?.error?.code || code;
  } catch (_) {
    if (response.status === 404) {
      message = 'Der KI-Proxy (api/ai-proxy.php) wurde auf dem Server nicht gefunden.';
      code = 'proxy_missing';
    } else if (response.status === 500) {
      message = 'Der Server meldet einen internen Fehler beim KI-Proxy. Bitte PHP-Version und Fehlerlog prüfen.';
    }
  }
  throw new AIError(code, message, detail);
}

/** Direktaufruf aus dem Browser (nur wenn der Anbieter CORS erlaubt). */
async function callDirect(url, init, signal) {
  let response;
  try {
    response = await fetch(url, { ...init, signal, cache: 'no-store', credentials: 'omit' });
  } catch (err) {
    if (err.name === 'AbortError') throw err;
    throw new AIError(
      'cors',
      'Der Anbieter erlaubt keine direkte Verbindung aus dem Browser. Bitte in „KI verbinden“ auf „Über eigenen Server“ umstellen.',
    );
  }
  const type = response.headers.get('Content-Type') || '';
  if (response.ok && type.startsWith('audio/')) {
    return { bytes: await response.arrayBuffer(), mime: type.split(';')[0].trim() };
  }
  let message = 'Die KI-Anfrage wurde abgelehnt.';
  try {
    const json = await response.json();
    message = json?.errors?.[0] || json?.message || json?.detail?.message || json?.error?.message || message;
  } catch (_) {
    /* Antwort war kein JSON */
  }
  if (response.status === 401 || response.status === 403) {
    throw new AIError('bad_key', 'Der API-Key wurde vom Anbieter abgelehnt.');
  }
  throw new AIError('provider_error', String(message).slice(0, 300));
}

class StabilityProvider extends AIProviderInterface {
  constructor() {
    super({
      id: 'stability',
      label: 'Stability AI — Stable Audio 2',
      description: 'Text-zu-Audio und Audio-zu-Audio. Eigener API-Key von platform.stability.ai erforderlich.',
      website: 'https://platform.stability.ai/',
      capabilities: { textToAudio: true, audioToAudio: true, maxDuration: 190 },
    });
  }

  async generate({ prompt, duration, count = 4, seed, input, onProgress = () => {}, signal }) {
    const key = keystore.peekKey();
    if (!key) throw new AIError('no_key', 'Es ist kein API-Key hinterlegt. Bitte unter „KI verbinden“ eintragen.');

    const op = input?.bytes ? 'audio-to-audio' : 'text-to-audio';
    const results = [];
    const baseSeed = seed || Math.floor(Math.random() * 1e9);

    for (let i = 0; i < count; i++) {
      onProgress(i / count, `Variante ${i + 1} von ${count} …`);
      const payload = {
        provider: 'stability',
        op,
        prompt,
        duration: clamp(duration, 1, this.capabilities.maxDuration),
        format: 'mp3',
        seed: (baseSeed + i * 1013) % 4294967290,
      };
      if (op === 'audio-to-audio') {
        payload.audio = bufferToBase64(input.bytes);
        payload.audioMime = input.mime || 'audio/wav';
        payload.strength = input.strength ?? 0.65;
      }

      let res;
      if (keystore.transport === 'direct') {
        const form = new FormData();
        form.append('prompt', prompt);
        form.append('output_format', 'mp3');
        form.append('duration', String(payload.duration));
        form.append('seed', String(payload.seed));
        if (op === 'audio-to-audio') {
          form.append('audio', new Blob([input.bytes], { type: input.mime || 'audio/wav' }), 'input.wav');
          form.append('strength', String(payload.strength));
        }
        res = await callDirect(
          `https://api.stability.ai/v2beta/audio/stable-audio-2/${op}`,
          {
            method: 'POST',
            headers: { Authorization: `Bearer ${key}`, Accept: 'audio/*' },
            body: form,
          },
          signal,
        );
      } else {
        res = await callProxy(payload, key, signal);
      }

      results.push({ ...res, label: `Variante ${i + 1}` });
      onProgress((i + 1) / count, `Variante ${i + 1} von ${count} …`);
    }
    return results;
  }
}

class ElevenLabsProvider extends AIProviderInterface {
  constructor() {
    super({
      id: 'elevenlabs',
      label: 'ElevenLabs — Sound Generation',
      description: 'Text-zu-Audio für Effekte, Percussion und Atmosphären. Eigener API-Key erforderlich.',
      website: 'https://elevenlabs.io/',
      capabilities: { textToAudio: true, audioToAudio: false, maxDuration: 22 },
    });
  }

  async generate({ prompt, duration, count = 4, onProgress = () => {}, signal }) {
    const key = keystore.peekKey();
    if (!key) throw new AIError('no_key', 'Es ist kein API-Key hinterlegt. Bitte unter „KI verbinden“ eintragen.');

    const results = [];
    for (let i = 0; i < count; i++) {
      onProgress(i / count, `Variante ${i + 1} von ${count} …`);
      const payload = {
        provider: 'elevenlabs',
        op: 'text-to-audio',
        prompt,
        duration: clamp(duration, 0.5, this.capabilities.maxDuration),
      };

      let res;
      if (keystore.transport === 'direct') {
        res = await callDirect(
          'https://api.elevenlabs.io/v1/sound-generation',
          {
            method: 'POST',
            headers: { 'xi-api-key': key, 'Content-Type': 'application/json', Accept: 'audio/mpeg' },
            body: JSON.stringify({
              text: prompt,
              duration_seconds: payload.duration,
              prompt_influence: 0.4,
            }),
          },
          signal,
        );
      } else {
        res = await callProxy(payload, key, signal);
      }

      results.push({ ...res, label: `Variante ${i + 1}` });
      onProgress((i + 1) / count, `Variante ${i + 1} von ${count} …`);
    }
    return results;
  }
}

// --- Registry ----------------------------------------------------------------

const PROVIDERS = new Map();
for (const p of [new LocalSynthProvider(), new StabilityProvider(), new ElevenLabsProvider()]) {
  PROVIDERS.set(p.id, p);
}

export function listProviders() {
  return [...PROVIDERS.values()];
}

export function getProvider(id) {
  return PROVIDERS.get(id) || PROVIDERS.get('local');
}

/** Der Anbieter, der laut Nutzereinstellung gerade aktiv ist. */
export function activeProvider() {
  const id = keystore.isConnected ? keystore.providerId : 'local';
  const provider = PROVIDERS.get(id);
  if (!provider) return PROVIDERS.get('local');
  if (provider.needsKey && !keystore.isConnected) return PROVIDERS.get('local');
  return provider;
}

/** Registriert einen zusaetzlichen Anbieter zur Laufzeit. */
export function registerProvider(provider) {
  if (!(provider instanceof AIProviderInterface)) {
    throw new Error('Anbieter muss AIProviderInterface erweitern.');
  }
  PROVIDERS.set(provider.id, provider);
}

/** Prueft die Erreichbarkeit des eigenen Proxys (fuer die Diagnose). */
export async function checkProxy() {
  try {
    const res = await fetch(PROXY_URL, { method: 'GET', cache: 'no-store' });
    if (!res.ok) return { ok: false, message: `Proxy antwortet mit HTTP ${res.status}.` };
    const json = await res.json();
    return { ok: true, php: json.php, curl: json.curl };
  } catch (_) {
    return { ok: false, message: 'Der KI-Proxy ist nicht erreichbar.' };
  }
}

export { analysePrompt };
