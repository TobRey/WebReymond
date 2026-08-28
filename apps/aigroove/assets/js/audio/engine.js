/**
 * AI Groove – Audioengine.
 *
 * Die Engine verbindet Projektzustand, Mixer-Graph und Scheduler.
 * Sie kennt KEIN DOM-Element. Die Oberflaeche erfaehrt alles ueber den Bus.
 *
 * Zwei Wiedergabemodi:
 *   'pattern' – das ausgewaehlte Pattern laeuft in Schleife (wie ein Drumcomputer)
 *   'song'    – das komplette Arrangement der Playlist
 */

import { bus, EV } from '../core/bus.js';
import { store } from '../core/store.js';
import { settings } from '../core/settings.js';
import { getContext, isUnlocked, unlockAudio } from './context.js';
import { MixerGraph } from './graph.js';
import { Scheduler } from './scheduler.js';
import { samples, playVoice, VoicePool } from './sampler.js';
import { buildTimeline, swingOffset, lowerBound } from './timeline.js';
import { PPQ, TICKS_PER_BAR, clamp, ticksToSeconds } from '../core/util.js';
import { createEvent } from '../core/project.js';

class Engine {
  constructor() {
    this.ctx = null;
    this.graph = null;
    this.scheduler = null;
    this.voices = new VoicePool();

    this.ready = false;
    this.mode = 'pattern';
    this.playing = false;
    this.recording = false;
    this.recordBuffer = [];
    this.countInUntil = 0;

    /** Sortierte Ereignisliste des aktuellen Modus. */
    this.timeline = [];
    this.timelineDirty = true;
    this.timelineEnd = TICKS_PER_BAR;

    this.metroBuffers = null;
    this.activePads = new Map(); // padIndex -> voice(s) key
    this.lastScheduledDiag = { events: 0, at: 0, drift: 0 };
  }

  // --- Aufbau ----------------------------------------------------------------

  async init() {
    if (this.ready) return;
    await unlockAudio();
    this.ctx = getContext();

    this.graph = new MixerGraph(this.ctx, { getBpm: () => store.project.bpm });
    this.graph.sync(store.project);

    this.scheduler = new Scheduler(() => this.ctx, {
      onSchedule: (from, to, tickToTime) => this._schedule(from, to, tickToTime),
      onLoop: () => bus.emit(EV.TRANSPORT_TICK, { loop: true }),
      onEnd: () => this.stop(),
    });
    this.scheduler.setLookahead(settings.lookahead());
    this.scheduler.setBpm(store.project.bpm);

    this._buildMetronome();
    this._bind();
    this.ready = true;
  }

  _bind() {
    bus.on(EV.MIXER_CHANGED, () => this.graph?.sync(store.project));
    bus.on(EV.SAMPLES_CHANGED, () => {
      this.graph?.sync(store.project);
      this.invalidate();
      this.preloadAll();
    });
    bus.on(EV.PATTERNS_CHANGED, () => this.invalidate());
    bus.on(EV.CLIPS_CHANGED, () => this.invalidate());
    bus.on(EV.PATTERN_SELECTED, () => this.invalidate());
    bus.on(EV.PROJECT_LOADED, () => {
      this.graph?.sync(store.project);
      this.invalidate();
      this.scheduler?.setBpm(store.project.bpm);
      this.preloadAll();
    });
    bus.on(EV.TRANSPORT_CHANGED, () => {
      this.scheduler?.setBpm(store.project.bpm);
      this._applyLoop();
    });
    bus.on(EV.SETTINGS_CHANGED, () => this.scheduler?.setLookahead(settings.lookahead()));
  }

  /** Erzeugt zwei kurze Klick-Klaenge fuer das Metronom. */
  _buildMetronome() {
    const ctx = this.ctx;
    const make = (freq) => {
      const len = Math.floor(ctx.sampleRate * 0.05);
      const buf = ctx.createBuffer(1, len, ctx.sampleRate);
      const d = buf.getChannelData(0);
      for (let i = 0; i < len; i++) {
        const t = i / ctx.sampleRate;
        const env = Math.exp(-t * 90);
        d[i] = Math.sin(2 * Math.PI * freq * t) * env * 0.8;
      }
      return buf;
    };
    this.metroBuffers = { accent: make(1600), normal: make(1050) };
  }

  async preloadAll() {
    if (!this.ctx) return;
    const ids = store.project.samples.map((s) => s.dataId);
    await samples.loadAll(this.ctx, ids, (dataId, err) => {
      console.warn('[engine] Sample konnte nicht geladen werden', dataId, err.message);
    });
  }

  invalidate() {
    this.timelineDirty = true;
  }

  // --- Zeitachse aufbauen ----------------------------------------------------

  _rebuildTimeline() {
    const { events, end } = buildTimeline(
      store.project,
      this.mode,
      store.project.ui.selectedPatternId,
    );
    this.timeline = events;
    this.timelineEnd = end;
    this.timelineDirty = false;
    this._applyLoop();
  }

  _applyLoop() {
    if (!this.scheduler) return;
    const project = store.project;
    if (this.mode === 'pattern') {
      const len = this.timelineEnd || TICKS_PER_BAR;
      this.scheduler.setLoop(true, 0, len);
      this.scheduler.setEnd(null);
    } else if (project.loop.enabled) {
      this.scheduler.setLoop(true, project.loop.start, project.loop.end);
      this.scheduler.setEnd(null);
    } else {
      this.scheduler.setLoop(false, 0, 0);
      // Etwas Nachlauf, damit das letzte Sample ausklingen darf.
      this.scheduler.setEnd(this.timelineEnd + TICKS_PER_BAR);
    }
  }

  /** Swing-Versatz in Sekunden fuer einen Tick (musikalisch korrekt). */
  _swingOffset(tick) {
    return swingOffset(tick, store.project.swing, store.project.bpm);
  }

  // --- Planung ---------------------------------------------------------------

  _schedule(from, to, tickToTime) {
    if (this.timelineDirty) this._rebuildTimeline();
    const project = store.project;
    let count = 0;

    // Metronom
    if (project.metronome.enabled) {
      const beat = PPQ;
      const first = Math.ceil(from / beat) * beat;
      for (let t = first; t < to; t += beat) {
        const isBarStart = t % (project.beatsPerBar * PPQ) === 0;
        this._playMetronome(tickToTime(t), isBarStart);
      }
    }

    // Ereignisse dieses Fensters
    const list = this.timeline;
    let i = lowerBound(list, from);
    for (; i < list.length && list[i].tick < to; i++) {
      const ev = list[i];
      const time = tickToTime(ev.tick) + this._swingOffset(ev.tick);
      this._playEvent(ev, time);
      count++;
    }

    this.lastScheduledDiag = {
      events: count,
      at: this.ctx.currentTime,
      drift: tickToTime(from) - this.ctx.currentTime,
    };
  }

  _playEvent(ev, time) {
    const sample = store.getSample(ev.sampleId);
    if (!sample) return;
    const buffer = samples.peek(sample.dataId);
    if (!buffer) {
      // Noch nicht dekodiert – im Hintergrund nachladen, dieser Ton entfaellt.
      samples.load(this.ctx, sample.dataId).catch(() => {});
      return;
    }

    const dest = this.graph.inputFor(sample.id);
    const gain = sample.gain * (ev.vel ?? 1) * (ev.gain ?? 1);
    const pitch = (sample.pitch || 0) + (ev.pitch || 0);

    const opts = {
      time,
      gain,
      pitch,
      offset: ev.offsetTicks ? ticksToSeconds(ev.offsetTicks, store.project.bpm) : 0,
    };

    // Bei Audio-Clips begrenzt die Clip-Laenge die Wiedergabe.
    if (ev.audioClip && ev.dur) {
      opts.duration = ticksToSeconds(ev.dur, store.project.bpm);
      opts.fade = 0.01;
    }

    playVoice(this.ctx, buffer, dest, opts);
  }

  _playMetronome(time, accent) {
    const buf = accent ? this.metroBuffers.accent : this.metroBuffers.normal;
    const src = this.ctx.createBufferSource();
    const g = this.ctx.createGain();
    g.gain.value = store.project.metronome.gain * (accent ? 1 : 0.7);
    src.buffer = buf;
    src.connect(g);
    g.connect(this.graph.previewBus);
    src.start(time);
    src.onended = () => {
      src.disconnect();
      g.disconnect();
    };
  }

  // --- Transport -------------------------------------------------------------

  setMode(mode) {
    if (this.mode === mode) return;
    const wasPlaying = this.playing;
    if (wasPlaying) this.stop();
    this.mode = mode;
    this.invalidate();
    bus.emit(EV.TRANSPORT_CHANGED);
    if (wasPlaying) this.play();
  }

  async play(options = {}) {
    await this.init();
    if (!isUnlocked()) await unlockAudio();
    if (this.timelineDirty) this._rebuildTimeline();

    this.scheduler.setBpm(store.project.bpm);
    this._applyLoop();

    const from = options.from ?? this.scheduler.startTick ?? 0;
    let startAt = null;

    // Count-In: Klicks laufen VOR dem eigentlichen Start.
    const bars = options.countIn ?? (options.record ? store.project.metronome.countIn : 0);
    if (bars > 0) {
      const beatSec = 60 / store.project.bpm;
      const beats = bars * store.project.beatsPerBar;
      startAt = this.ctx.currentTime + 0.08 + beats * beatSec;
      for (let b = 0; b < beats; b++) {
        this._playMetronome(this.ctx.currentTime + 0.08 + b * beatSec, b % store.project.beatsPerBar === 0);
      }
      this.countInUntil = startAt;
    } else {
      this.countInUntil = 0;
    }

    this.playing = true;
    this.recording = !!options.record;
    this.recordBuffer = [];
    this.scheduler.start(from, startAt);
    bus.emit(EV.TRANSPORT_PLAY, { mode: this.mode, recording: this.recording });
    bus.emit(EV.TRANSPORT_CHANGED);
  }

  stop({ keepPosition = false } = {}) {
    if (!this.scheduler) return;
    const wasRecording = this.recording;
    this.playing = false;
    this.recording = false;
    this.countInUntil = 0;
    this.scheduler.stop();
    this.voices.stopAll();
    if (!keepPosition) this.scheduler.seek(this.scheduler.startTick);

    if (wasRecording) this._commitRecording();
    bus.emit(EV.TRANSPORT_STOP);
    bus.emit(EV.TRANSPORT_CHANGED);
  }

  toggle(options) {
    if (this.playing) this.stop();
    else this.play(options);
  }

  seek(tick) {
    if (!this.scheduler) return;
    this.scheduler.seek(clamp(tick, 0, Number.MAX_SAFE_INTEGER));
    bus.emit(EV.TRANSPORT_CHANGED);
  }

  get position() {
    if (!this.scheduler) return 0;
    if (this.countInUntil && this.ctx.currentTime < this.countInUntil) return this.scheduler.startTick;
    return this.scheduler.positionTicks;
  }

  get inCountIn() {
    return this.countInUntil > 0 && this.ctx && this.ctx.currentTime < this.countInUntil;
  }

  // --- Live-Aufnahme von Pad-Anschlaegen --------------------------------------

  _recordHit(sampleId, pitch = 0, vel = 1) {
    if (!this.recording || this.inCountIn) return;
    const tick = this.position;
    const len = this.mode === 'pattern' ? this.timelineEnd : Infinity;
    const wrapped = len === Infinity ? tick : ((tick % len) + len) % len;
    this.recordBuffer.push({ tick: wrapped, sampleId, pitch, vel });
  }

  _commitRecording() {
    if (!this.recordBuffer.length) return;
    const pattern = store.selectedPattern;
    if (!pattern) {
      this.recordBuffer = [];
      return;
    }

    const quantize = settings.get('quantizeRecording');
    const grid = TICKS_PER_BAR / (settings.get('quantizeGrid') || 16);
    const events = this.recordBuffer.map((hit) => {
      const tick = quantize ? Math.round(hit.tick / grid) * grid : Math.round(hit.tick);
      const max = pattern.lengthBars * TICKS_PER_BAR;
      return createEvent(hit.sampleId, ((tick % max) + max) % max, {
        dur: grid,
        pitch: hit.pitch,
        vel: hit.vel,
      });
    });

    // Doppelte Anschlaege auf demselben Raster zusammenfassen.
    const seen = new Set();
    const unique = events.filter((e) => {
      const key = `${e.sampleId}|${e.tick}|${e.pitch}`;
      if (seen.has(key)) return false;
      seen.add(key);
      return true;
    });

    this.recordBuffer = [];
    store.addEvents(pattern.id, unique, 'Live-Aufnahme');
  }

  // --- Pads ------------------------------------------------------------------

  /**
   * Loest ein Pad aus. Wird direkt aus dem Zeigerereignis aufgerufen und
   * startet den Ton SOFORT – ohne auf Animationen oder Layout zu warten.
   */
  triggerPad(index, { velocity = 1 } = {}) {
    if (!this.ready) {
      // Erste Beruehrung: entsperren und danach ausloesen.
      this.init().then(() => this.triggerPad(index, { velocity }));
      return null;
    }
    const bank = store.activeBank;
    const pad = bank?.pads?.[index];
    if (!pad) return null;

    const sample = store.getSample(pad.sampleId);
    if (!sample) return null;
    const buffer = samples.peek(sample.dataId);
    if (!buffer) {
      samples.load(this.ctx, sample.dataId).catch(() => {});
      return null;
    }

    const key = `pad:${index}`;

    if (pad.mode === 'toggle' && this.voices.has(key)) {
      this.voices.stop(key);
      bus.emit(EV.PAD_TRIGGERED, { index, on: false });
      return null;
    }

    // One-Shot: laufende Stimme desselben Pads abwuergen (typisch fuer Drums).
    if (pad.mode !== 'hold') this.voices.stop(key);

    const voice = playVoice(this.ctx, buffer, this.graph.inputFor(sample.id), {
      time: this.ctx.currentTime,
      gain: sample.gain * pad.gain * velocity,
      pitch: (sample.pitch || 0) + pad.pitch,
    });

    if (pad.mode !== 'oneshot') this.voices.add(key, voice);
    this._recordHit(sample.id, pad.pitch, velocity);
    bus.emit(EV.PAD_TRIGGERED, { index, on: true, sampleId: sample.id });
    return voice;
  }

  releasePad(index) {
    const bank = store.activeBank;
    const pad = bank?.pads?.[index];
    if (!pad) return;
    if (pad.mode === 'hold') {
      this.voices.stop(`pad:${index}`);
      bus.emit(EV.PAD_TRIGGERED, { index, on: false });
    }
  }

  padIsOn(index) {
    return this.voices.has(`pad:${index}`);
  }

  // --- Vorhoeren -------------------------------------------------------------

  /** Spielt ein Sample einmal ab (durch den eigenen Mixerkanal). */
  async preview(sampleId, { pitch = 0, throughChannel = true } = {}) {
    await this.init();
    const sample = store.getSample(sampleId);
    if (!sample) return null;
    const buffer = await samples.load(this.ctx, sample.dataId);
    this.voices.stop('preview');
    const dest = throughChannel ? this.graph.inputFor(sample.id) : this.graph.previewBus;
    const voice = playVoice(this.ctx, buffer, dest, {
      time: this.ctx.currentTime,
      gain: sample.gain,
      pitch: (sample.pitch || 0) + pitch,
    });
    this.voices.add('preview', voice);
    return voice;
  }

  /** Spielt einen beliebigen AudioBuffer (z. B. KI-Varianten vor dem Speichern). */
  async previewBuffer(buffer, { gain = 0.6, offset = 0, duration = null } = {}) {
    await this.init();
    this.voices.stop('preview');
    const voice = playVoice(this.ctx, buffer, this.graph.previewBus, {
      time: this.ctx.currentTime,
      gain,
      offset,
      duration,
    });
    this.voices.add('preview', voice);
    return voice;
  }

  stopPreview() {
    this.voices.stop('preview');
  }

  panic() {
    this.voices.stopAll();
  }

  // --- Diagnose --------------------------------------------------------------

  diagnostics() {
    const ctx = this.ctx;
    return {
      sampleRate: ctx?.sampleRate || 0,
      state: ctx?.state || 'none',
      baseLatency: ((ctx?.baseLatency || 0) * 1000).toFixed(2),
      outputLatency: ((ctx?.outputLatency || 0) * 1000).toFixed(2),
      lookahead: (this.scheduler?.lookahead * 1000).toFixed(0),
      voices: this.voices.count(),
      buffers: samples.count(),
      bufferMemory: samples.memoryUsage(),
      timelineEvents: this.timeline.length,
      lastWindow: this.lastScheduledDiag.events,
      drift: (this.lastScheduledDiag.drift * 1000).toFixed(2),
      position: Math.round(this.position),
      mode: this.mode,
    };
  }
}

export const engine = new Engine();
