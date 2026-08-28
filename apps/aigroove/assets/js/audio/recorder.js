/**
 * AI Groove – Mikrofonaufnahme.
 *
 * Besonderheiten fuer iPhone/Safari:
 *   - getUserMedia funktioniert nur ueber HTTPS (oder localhost).
 *   - Der AudioContext muss vorher durch eine Nutzergeste entsperrt sein.
 *   - Die Geraeteliste enthaelt erst nach erteilter Berechtigung echte Namen.
 *
 * Aufgenommen wird rohes PCM (AudioWorklet, Fallback ScriptProcessor).
 * Das Ergebnis ist ein AudioBuffer und daraus eine WAV-Datei – verlustfrei
 * und in jedem Browser dekodierbar.
 */

import { getContext, unlockAudio } from './context.js';
import { bufferFromChannels } from './dsp.js';
import { encodeWav } from './wav.js';

export class MicError extends Error {
  constructor(code, message) {
    super(message);
    this.name = 'MicError';
    this.code = code;
  }
}

function mapGetUserMediaError(err) {
  const name = err?.name || '';
  switch (name) {
    case 'NotAllowedError':
    case 'SecurityError':
      return new MicError(
        'denied',
        'Der Zugriff auf das Mikrofon wurde abgelehnt. Bitte in den Browser- bzw. iOS-Einstellungen für diese Website erlauben.',
      );
    case 'NotFoundError':
    case 'OverconstrainedError':
      return new MicError('not_found', 'Es wurde kein passendes Mikrofon gefunden.');
    case 'NotReadableError':
      return new MicError('busy', 'Das Mikrofon wird bereits von einer anderen App verwendet.');
    default:
      if (!window.isSecureContext) {
        return new MicError(
          'insecure',
          'Mikrofonaufnahmen benötigen eine sichere Verbindung (HTTPS). Bitte die Seite über https:// aufrufen.',
        );
      }
      return new MicError('unknown', 'Das Mikrofon konnte nicht gestartet werden.');
  }
}

/** Liste der verfuegbaren Eingabegeraete. */
export async function listMicrophones() {
  if (!navigator.mediaDevices?.enumerateDevices) return [];
  try {
    const devices = await navigator.mediaDevices.enumerateDevices();
    return devices
      .filter((d) => d.kind === 'audioinput')
      .map((d, i) => ({ id: d.deviceId, label: d.label || `Mikrofon ${i + 1}` }));
  } catch (_) {
    return [];
  }
}

/** Liste der Ausgabegeraete (nur Chromium-Browser liefern hier Eintraege). */
export async function listOutputs() {
  if (!navigator.mediaDevices?.enumerateDevices) return [];
  try {
    const devices = await navigator.mediaDevices.enumerateDevices();
    return devices
      .filter((d) => d.kind === 'audiooutput')
      .map((d, i) => ({ id: d.deviceId, label: d.label || `Ausgang ${i + 1}` }));
  } catch (_) {
    return [];
  }
}

export class Recorder {
  constructor() {
    this.ctx = null;
    this.stream = null;
    this.source = null;
    this.node = null;
    this.chunks = [];
    this.frames = 0;
    this.channelCount = 1;
    this.recording = false;
    this.armed = false;
    this.level = 0;
    this.onLevel = null;
    this.startedAt = 0;
    this._workletLoaded = false;
  }

  get duration() {
    return this.ctx ? this.frames / this.ctx.sampleRate : 0;
  }

  /** Fordert Zugriff an und baut den Aufnahmeweg auf (noch ohne Aufnahme). */
  async arm(deviceId, options = {}) {
    if (this.armed) return;
    if (!navigator.mediaDevices?.getUserMedia) {
      throw new MicError('unsupported', 'Dieser Browser unterstützt keine Mikrofonaufnahme.');
    }

    await unlockAudio();
    this.ctx = getContext();

    const constraints = {
      audio: {
        deviceId: deviceId ? { exact: deviceId } : undefined,
        echoCancellation: !!options.echoCancellation,
        noiseSuppression: !!options.noiseSuppression,
        autoGainControl: !!options.autoGain,
        channelCount: options.channelCount || 1,
      },
      video: false,
    };

    try {
      this.stream = await navigator.mediaDevices.getUserMedia(constraints);
    } catch (err) {
      // Manche Geraete lehnen exakte Vorgaben ab – einmal ohne Einschraenkungen versuchen.
      if (deviceId) {
        try {
          this.stream = await navigator.mediaDevices.getUserMedia({ audio: true, video: false });
        } catch (err2) {
          throw mapGetUserMediaError(err2);
        }
      } else {
        throw mapGetUserMediaError(err);
      }
    }

    this.source = this.ctx.createMediaStreamSource(this.stream);
    this.channelCount = Math.min(2, this.source.channelCount || 1);

    await this._createNode();
    this.source.connect(this.node);
    // Der Aufnahmeknoten darf NICHT auf die Lautsprecher gehen (Rueckkopplung).
    // ScriptProcessor braucht dennoch ein Ziel: ein stummer Gain erfuellt das.
    const sink = this.ctx.createGain();
    sink.gain.value = 0;
    this.node.connect(sink);
    sink.connect(this.ctx.destination);
    this._sink = sink;

    this.armed = true;
  }

  async _createNode() {
    const ctx = this.ctx;
    if (ctx.audioWorklet && typeof AudioWorkletNode === 'function') {
      try {
        if (!this._workletLoaded) {
          await ctx.audioWorklet.addModule(new URL('../workers/recorder-processor.js', import.meta.url));
          this._workletLoaded = true;
        }
        const node = new AudioWorkletNode(ctx, 'aig-recorder', {
          numberOfInputs: 1,
          numberOfOutputs: 1,
          outputChannelCount: [1],
        });
        node.port.onmessage = (event) => this._onAudio(event.data);
        this.node = node;
        return;
      } catch (err) {
        console.warn('[recorder] AudioWorklet nicht verfügbar, Fallback aktiv', err);
      }
    }

    // Fallback: ScriptProcessor (veraltet, aber ueberall vorhanden).
    const node = ctx.createScriptProcessor(4096, this.channelCount, this.channelCount);
    node.onaudioprocess = (event) => {
      const input = event.inputBuffer;
      const channels = [];
      let peak = 0;
      for (let c = 0; c < input.numberOfChannels; c++) {
        const data = input.getChannelData(c);
        channels.push(new Float32Array(data));
        if (c === 0) {
          for (let i = 0; i < data.length; i++) {
            const v = Math.abs(data[i]);
            if (v > peak) peak = v;
          }
        }
      }
      this._onAudio({ type: this.recording ? 'data' : 'level', channels, peak });
    };
    this.node = node;
  }

  _onAudio(msg) {
    this.level = this.level * 0.7 + (msg.peak || 0) * 0.3;
    this.onLevel?.(this.level, msg.peak || 0);
    if (msg.type === 'data' && this.recording && msg.channels) {
      this.chunks.push(msg.channels);
      this.frames += msg.channels[0].length;
      this.channelCount = Math.max(this.channelCount, msg.channels.length);
    }
  }

  start() {
    if (!this.armed) throw new MicError('not_armed', 'Das Mikrofon ist noch nicht bereit.');
    this.chunks = [];
    this.frames = 0;
    this.recording = true;
    this.startedAt = this.ctx.currentTime;
    if (this.node.port) this.node.port.postMessage('start');
  }

  pause() {
    this.recording = false;
    if (this.node?.port) this.node.port.postMessage('stop');
  }

  /** Beendet die Aufnahme und liefert einen AudioBuffer. */
  stop() {
    this.recording = false;
    if (this.node?.port) this.node.port.postMessage('stop');
    if (!this.frames) return null;

    const channels = [];
    const chCount = this.channelCount;
    for (let c = 0; c < chCount; c++) channels.push(new Float32Array(this.frames));

    let offset = 0;
    for (const chunk of this.chunks) {
      const len = chunk[0].length;
      for (let c = 0; c < chCount; c++) {
        channels[c].set(chunk[Math.min(c, chunk.length - 1)], offset);
      }
      offset += len;
    }
    this.chunks = [];
    return bufferFromChannels(this.ctx, channels, this.ctx.sampleRate);
  }

  /** Gibt Mikrofon und Knoten frei. */
  release() {
    this.recording = false;
    try {
      this.node?.disconnect();
      this._sink?.disconnect();
      this.source?.disconnect();
    } catch (_) {
      /* egal */
    }
    if (this.node?.port) this.node.port.onmessage = null;
    if (this.node) this.node.onaudioprocess = null;
    for (const track of this.stream?.getTracks() || []) track.stop();
    this.stream = null;
    this.source = null;
    this.node = null;
    this._sink = null;
    this.armed = false;
    this.chunks = [];
    this.frames = 0;
  }
}

/** Praktisch: Aufnahme direkt als WAV-Bytes. */
export function recordingToWav(buffer) {
  return { bytes: encodeWav(buffer, { bitDepth: 16 }), mime: 'audio/wav' };
}
