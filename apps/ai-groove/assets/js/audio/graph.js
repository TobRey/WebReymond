/**
 * AI Groove – Mixer-Signalweg.
 *
 * Aufbau je Kanal:
 *   input → [Effektkette] → volume → pan → analyser → Master
 *
 * Master:
 *   masterIn → [Master-Effekte] → masterGain → masterAnalyser → destination
 *
 * Der Graph wird aus dem Projektzustand abgeleitet. Struktur-Aenderungen
 * (Effekt hinzufuegen/entfernen/umsortieren) bauen nur die betroffene Kette neu.
 */

import { createEffectNode } from './effects.js';
import { clamp } from '../core/util.js';

function makePanner(ctx) {
  if (typeof ctx.createStereoPanner === 'function') {
    const node = ctx.createStereoPanner();
    return {
      node,
      setPan(v, time) {
        try {
          node.pan.setTargetAtTime(clamp(v, -1, 1), time ?? ctx.currentTime, 0.01);
        } catch (_) {
          node.pan.value = clamp(v, -1, 1);
        }
      },
    };
  }
  // Fallback fuer sehr alte Safari-Versionen.
  const node = ctx.createPanner();
  node.panningModel = 'equalpower';
  node.distanceModel = 'linear';
  return {
    node,
    setPan(v) {
      const x = clamp(v, -1, 1);
      const z = 1 - Math.abs(x);
      if (node.positionX) {
        node.positionX.value = x;
        node.positionY.value = 0;
        node.positionZ.value = z;
      } else {
        node.setPosition(x, 0, z);
      }
    },
  };
}

class Channel {
  constructor(ctx, id, env) {
    this.ctx = ctx;
    this.id = id;
    this.env = env;

    this.input = ctx.createGain();
    this.volume = ctx.createGain();
    this.panner = makePanner(ctx);
    this.analyser = ctx.createAnalyser();
    this.analyser.fftSize = 256;
    this.analyser.smoothingTimeConstant = 0;

    this.effects = [];
    this.signature = '';
    this.peak = 0;
    this.peakHold = 0;
    this.clipped = false;
    this._meterBuf = new Float32Array(this.analyser.fftSize);

    this.volume.connect(this.panner.node);
    this.panner.node.connect(this.analyser);
  }

  connectTo(destination) {
    this.analyser.connect(destination);
  }

  /** Baut die Effektkette neu, wenn sich die Struktur geaendert hat. */
  syncEffects(states) {
    const signature = states.map((s) => `${s.id}:${s.type}`).join('|');
    if (signature === this.signature) {
      for (const fx of this.effects) {
        const state = states.find((s) => s.id === fx.id);
        if (!state) continue;
        fx.update(state.params);
        fx.setEnabled(state.enabled);
      }
      return;
    }
    this.signature = signature;

    for (const fx of this.effects) fx.dispose();
    this.effects = [];

    try {
      this.input.disconnect();
    } catch (_) {
      /* egal */
    }

    let prev = this.input;
    for (const state of states) {
      const fx = createEffectNode(this.ctx, state, this.env);
      prev.connect(fx.input);
      prev = fx.output;
      this.effects.push(fx);
    }
    prev.connect(this.volume);
  }

  setGain(value, time) {
    const v = Math.max(0, value);
    try {
      this.volume.gain.setTargetAtTime(v, time ?? this.ctx.currentTime, 0.008);
    } catch (_) {
      this.volume.gain.value = v;
    }
  }

  setPan(value, time) {
    this.panner.setPan(value, time);
  }

  /** Spitzenpegel seit dem letzten Aufruf (0…>1). */
  readMeter() {
    const buf = this._meterBuf;
    try {
      this.analyser.getFloatTimeDomainData(buf);
    } catch (_) {
      return this.peak;
    }
    let peak = 0;
    for (let i = 0; i < buf.length; i++) {
      const v = Math.abs(buf[i]);
      if (v > peak) peak = v;
    }
    // Schnelles Ansteigen, langsames Abfallen – wie bei echten Pegelanzeigen.
    this.peak = peak > this.peak ? peak : this.peak * 0.82 + peak * 0.18;
    if (peak >= 0.999) this.clipped = true;
    if (this.peak > this.peakHold) this.peakHold = this.peak;
    else this.peakHold *= 0.995;
    return this.peak;
  }

  dispose() {
    for (const fx of this.effects) fx.dispose();
    this.effects = [];
    for (const n of [this.input, this.volume, this.panner.node, this.analyser]) {
      try {
        n.disconnect();
      } catch (_) {
        /* egal */
      }
    }
  }
}

export class MixerGraph {
  /**
   * @param {AudioContext|OfflineAudioContext} ctx
   * @param {{getBpm:() => number}} env
   */
  constructor(ctx, env) {
    this.ctx = ctx;
    this.env = env;
    /** @type {Map<string, Channel>} */
    this.channels = new Map();

    this.masterIn = ctx.createGain();
    this.masterGain = ctx.createGain();
    this.masterAnalyser = ctx.createAnalyser();
    this.masterAnalyser.fftSize = 512;
    this.masterAnalyser.smoothingTimeConstant = 0;
    this.masterEffects = [];
    this.masterSignature = '';
    this.masterPeak = 0;
    this.masterClipped = false;
    this._masterBuf = new Float32Array(this.masterAnalyser.fftSize);

    this.masterGain.connect(this.masterAnalyser);
    this.masterAnalyser.connect(ctx.destination);

    // Kanal fuer Vorhoeren/Metronom: umgeht die Kanaleffekte, nicht aber den Master.
    this.previewBus = ctx.createGain();
    this.previewBus.connect(this.masterIn);
  }

  channel(id) {
    let ch = this.channels.get(id);
    if (!ch) {
      ch = new Channel(this.ctx, id, this.env);
      ch.connectTo(this.masterIn);
      this.channels.set(id, ch);
    }
    return ch;
  }

  /** Eingangsknoten fuer einen Kanal – hier werden Stimmen angehaengt. */
  inputFor(id) {
    return this.channel(id).input;
  }

  /**
   * Gleicht den kompletten Graph mit dem Projekt ab.
   * Wird nach jeder Mixer-Aenderung aufgerufen (guenstig, da nur Vergleiche).
   */
  sync(project) {
    const anySolo = Object.values(project.channels || {}).some((c) => c.solo);

    // Kanaele, die es nicht mehr gibt, entfernen.
    const valid = new Set(project.samples.map((s) => s.id));
    for (const [id, ch] of this.channels) {
      if (!valid.has(id)) {
        ch.dispose();
        this.channels.delete(id);
      }
    }

    for (const sample of project.samples) {
      const state = project.channels[sample.id];
      if (!state) continue;
      const ch = this.channel(sample.id);
      ch.syncEffects(state.effects || []);
      const audible = !state.mute && (!anySolo || state.solo);
      ch.setGain(audible ? state.gain : 0);
      ch.setPan(state.pan);
    }

    // Master
    const signature = (project.master.effects || []).map((s) => `${s.id}:${s.type}`).join('|');
    if (signature !== this.masterSignature) {
      this.masterSignature = signature;
      for (const fx of this.masterEffects) fx.dispose();
      this.masterEffects = [];
      try {
        this.masterIn.disconnect();
      } catch (_) {
        /* egal */
      }
      let prev = this.masterIn;
      for (const state of project.master.effects || []) {
        const fx = createEffectNode(this.ctx, state, this.env);
        prev.connect(fx.input);
        prev = fx.output;
        this.masterEffects.push(fx);
      }
      prev.connect(this.masterGain);
    } else {
      for (const fx of this.masterEffects) {
        const state = (project.master.effects || []).find((s) => s.id === fx.id);
        if (!state) continue;
        fx.update(state.params);
        fx.setEnabled(state.enabled);
      }
    }

    try {
      this.masterGain.gain.setTargetAtTime(project.master.gain, this.ctx.currentTime, 0.01);
    } catch (_) {
      this.masterGain.gain.value = project.master.gain;
    }
  }

  readMeter(id) {
    if (id === 'master') {
      const buf = this._masterBuf;
      try {
        this.masterAnalyser.getFloatTimeDomainData(buf);
      } catch (_) {
        return this.masterPeak;
      }
      let peak = 0;
      for (let i = 0; i < buf.length; i++) {
        const v = Math.abs(buf[i]);
        if (v > peak) peak = v;
      }
      this.masterPeak = peak > this.masterPeak ? peak : this.masterPeak * 0.82 + peak * 0.18;
      if (peak >= 0.999) this.masterClipped = true;
      return this.masterPeak;
    }
    const ch = this.channels.get(id);
    return ch ? ch.readMeter() : 0;
  }

  isClipped(id) {
    if (id === 'master') return this.masterClipped;
    return this.channels.get(id)?.clipped || false;
  }

  clearClip(id) {
    if (id === 'master') this.masterClipped = false;
    else {
      const ch = this.channels.get(id);
      if (ch) ch.clipped = false;
    }
  }

  dispose() {
    for (const ch of this.channels.values()) ch.dispose();
    this.channels.clear();
    for (const fx of this.masterEffects) fx.dispose();
    this.masterEffects = [];
    for (const n of [this.masterIn, this.masterGain, this.masterAnalyser, this.previewBus]) {
      try {
        n.disconnect();
      } catch (_) {
        /* egal */
      }
    }
  }
}
