// Töne werden mit WebAudio erzeugt, nicht abgespielt: keine Audiodateien im
// ZIP, keine Ladezeit, keine Lizenzfragen.
//
// Wichtig für iOS: der AudioContext muss INNERHALB einer Nutzergeste erzeugt
// UND fortgesetzt werden. Ein beim Laden erzeugter Context lässt sich später
// nicht mehr aufwecken – das Spiel bliebe still, ohne dass ein Fehler
// erscheint. Deshalb passiert beides in unlock().

import { readString, writeString } from './storage.js';

const MUTE_KEY = 'mogli.muted';

export function createAudio() {
  /** @type {AudioContext|null} */
  let ctx = null;
  let master = null;
  let muted = readString(MUTE_KEY, '0') === '1';

  function unlock() {
    if (ctx !== null) {
      if (ctx.state === 'suspended') ctx.resume();
      return;
    }
    const Ctor = window.AudioContext || window.webkitAudioContext;
    if (!Ctor) return;
    try {
      ctx = new Ctor();
      master = ctx.createGain();
      master.gain.value = muted ? 0 : 0.28;
      master.connect(ctx.destination);
      if (ctx.state === 'suspended') ctx.resume();
    } catch {
      ctx = null;
    }
  }

  /**
   * Ein Ton mit Frequenzverlauf und kurzer Hüllkurve.
   * @param {{type?: OscillatorType, from: number, to?: number, duration: number,
   *   gain?: number, delay?: number}} spec
   */
  function tone(spec) {
    if (ctx === null || muted) return;
    const now = ctx.currentTime + (spec.delay ?? 0);
    const osc = ctx.createOscillator();
    const gain = ctx.createGain();
    osc.type = spec.type ?? 'square';
    osc.frequency.setValueAtTime(spec.from, now);
    if (spec.to !== undefined)
      osc.frequency.exponentialRampToValueAtTime(Math.max(1, spec.to), now + spec.duration);
    const peak = spec.gain ?? 0.6;
    gain.gain.setValueAtTime(0.0001, now);
    gain.gain.exponentialRampToValueAtTime(peak, now + 0.008);
    gain.gain.exponentialRampToValueAtTime(0.0001, now + spec.duration);
    osc.connect(gain);
    gain.connect(master);
    osc.start(now);
    osc.stop(now + spec.duration + 0.02);
  }

  /** Kurzes Rauschen für Landung und Wandreibung. */
  function noise(duration, gainValue) {
    if (ctx === null || muted) return;
    const frames = Math.floor(ctx.sampleRate * duration);
    const buffer = ctx.createBuffer(1, frames, ctx.sampleRate);
    const data = buffer.getChannelData(0);
    for (let i = 0; i < frames; i += 1) {
      data[i] = (Math.random() * 2 - 1) * (1 - i / frames);
    }
    const source = ctx.createBufferSource();
    source.buffer = buffer;
    const gain = ctx.createGain();
    gain.gain.value = gainValue;
    source.connect(gain);
    gain.connect(master);
    source.start();
  }

  const sounds = {
    jump: () => tone({ type: 'square', from: 300, to: 620, duration: 0.08, gain: 0.32 }),
    wallJump: () => {
      tone({ type: 'triangle', from: 340, to: 700, duration: 0.1, gain: 0.3 });
      noise(0.05, 0.12);
    },
    land: () => noise(0.06, 0.15),
    turn: () => tone({ type: 'triangle', from: 220, to: 180, duration: 0.05, gain: 0.14 }),
    emerald: () => {
      tone({ type: 'sine', from: 940, duration: 0.06, gain: 0.28 });
      tone({ type: 'sine', from: 1410, duration: 0.09, gain: 0.24, delay: 0.05 });
    },
    // Die Ranke soll sich anfuehlen wie ein Ruck nach oben: ein steiler
    // Aufwaertsschleifer plus ein Blaetterrascheln.
    vine: () => {
      tone({ type: 'sawtooth', from: 260, to: 1200, duration: 0.22, gain: 0.3 });
      noise(0.18, 0.14);
    },
    death: () => {
      tone({ type: 'sawtooth', from: 420, to: 60, duration: 0.7, gain: 0.35 });
      noise(0.25, 0.18);
    },
    ui: () => tone({ type: 'square', from: 520, to: 660, duration: 0.05, gain: 0.18 }),
  };

  return {
    unlock,
    /** @param {keyof typeof sounds} name */
    play(name) {
      const fn = sounds[name];
      if (fn !== undefined) fn();
    },
    toggleMute() {
      muted = !muted;
      writeString(MUTE_KEY, muted ? '1' : '0');
      if (master !== null) master.gain.value = muted ? 0 : 0.28;
      return muted;
    },
    get muted() {
      return muted;
    },
  };
}
