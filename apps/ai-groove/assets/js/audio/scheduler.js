/**
 * AI Groove – Look-ahead-Scheduler.
 *
 * Prinzip (nach dem bewaehrten "A Tale of Two Clocks"-Muster):
 *   1. Ein Worker weckt uns alle ~20 ms auf (nur ein Wecker, kein Taktgeber).
 *   2. Wir schauen ein Stueck in die Zukunft (Look-ahead, ~120 ms).
 *   3. Alle Ereignisse in diesem Fenster werden mit EXAKTEN AudioContext-Zeiten
 *      vorab geplant (source.start(time)).
 *
 * Dadurch ist das Timing sample-genau und unabhaengig davon, wie beschaeftigt
 * der Haupt-Thread mit Animationen oder Layout ist.
 */

import { PPQ } from '../core/util.js';

export class Scheduler {
  /**
   * @param {() => AudioContext} getContext
   * @param {object} handlers
   * @param {(from:number, to:number, tickToTime:(t:number)=>number) => void} handlers.onSchedule
   * @param {() => void} [handlers.onLoop]
   * @param {() => void} [handlers.onEnd]
   */
  constructor(getContext, handlers = {}) {
    this.getContext = getContext;
    this.handlers = handlers;

    this.bpm = 128;
    this.tickDur = 60 / this.bpm / PPQ;
    this.lookahead = 0.12;
    this.interval = 20;

    this.playing = false;
    this.startTime = 0;
    this.startTick = 0;
    this.nextTick = 0;

    /** Grenzen der Wiedergabe (Ticks). */
    this.loopEnabled = false;
    this.loopStart = 0;
    this.loopEnd = 0;
    this.endTick = Infinity;

    this.worker = null;
    this.fallbackTimer = 0;

    this._onTick = this._onTick.bind(this);
  }

  // --- Zeitrechnung ----------------------------------------------------------

  /** AudioContext-Zeit eines Ticks. */
  timeOf(tick) {
    return this.startTime + (tick - this.startTick) * this.tickDur;
  }

  /** Tick zu einer AudioContext-Zeit. */
  tickAt(time) {
    return this.startTick + (time - this.startTime) / this.tickDur;
  }

  /** Aktuelle Abspielposition in Ticks (fuer die Anzeige). */
  get positionTicks() {
    if (!this.playing) return this.startTick;
    const ctx = this.getContext();
    return Math.max(this.startTick, this.tickAt(ctx.currentTime));
  }

  setBpm(bpm) {
    const next = Math.max(20, Math.min(300, bpm));
    if (Math.abs(next - this.bpm) < 1e-6) return;

    if (this.playing) {
      // Neu verankern beim naechsten noch nicht geplanten Tick:
      // bereits geplante Ereignisse behalten ihre exakte Zeit, ab dort gilt das neue Tempo.
      const anchorTime = this.timeOf(this.nextTick);
      this.startTime = anchorTime;
      this.startTick = this.nextTick;
    }
    this.bpm = next;
    this.tickDur = 60 / next / PPQ;
  }

  setLookahead(seconds) {
    this.lookahead = Math.max(0.05, Math.min(0.5, seconds));
  }

  setLoop(enabled, start, end) {
    this.loopEnabled = !!enabled;
    this.loopStart = Math.max(0, Math.round(start));
    this.loopEnd = Math.max(this.loopStart + 1, Math.round(end));
  }

  setEnd(tick) {
    this.endTick = tick == null ? Infinity : Math.max(1, Math.round(tick));
  }

  // --- Steuerung -------------------------------------------------------------

  /**
   * @param {number} fromTick Startposition
   * @param {number} [atTime] AudioContext-Zeit des Starts (fuer Count-In)
   */
  start(fromTick = 0, atTime = null) {
    const ctx = this.getContext();
    this.playing = true;
    this.startTick = Math.max(0, Math.round(fromTick));
    // Kleiner Vorlauf, damit der erste Ton nicht "zu spaet" geplant wird.
    this.startTime = atTime != null ? Math.max(ctx.currentTime, atTime) : ctx.currentTime + 0.03;
    this.nextTick = this.startTick;
    this._startClock();
    // Sofort einmal planen, damit der Einsatz punktgenau sitzt.
    this._onTick();
  }

  stop() {
    this.playing = false;
    this._stopClock();
  }

  /** Springt zu einer Position, ohne die Wiedergabe zu beenden. */
  seek(tick) {
    const t = Math.max(0, Math.round(tick));
    if (!this.playing) {
      this.startTick = t;
      this.nextTick = t;
      return;
    }
    const ctx = this.getContext();
    this.startTick = t;
    this.startTime = ctx.currentTime + 0.02;
    this.nextTick = t;
  }

  // --- Interner Wecker -------------------------------------------------------

  _startClock() {
    if (this.worker) {
      this.worker.postMessage({ cmd: 'start', interval: this.interval });
      return;
    }
    try {
      this.worker = new Worker(new URL('../workers/ticker.js', import.meta.url), { type: 'classic' });
      this.worker.onmessage = this._onTick;
      this.worker.postMessage({ cmd: 'start', interval: this.interval });
    } catch (err) {
      // Sollte der Worker nicht erlaubt sein (sehr alte Browser, strenge CSP),
      // faellt der Wecker auf einen normalen Timer zurueck. Das Timing der
      // Toene bleibt trotzdem sample-genau, weil es aus dem AudioContext kommt.
      console.warn('[scheduler] Worker nicht verfügbar, Fallback aktiv', err);
      this.worker = null;
      clearInterval(this.fallbackTimer);
      this.fallbackTimer = setInterval(this._onTick, this.interval);
    }
  }

  _stopClock() {
    if (this.worker) this.worker.postMessage({ cmd: 'stop' });
    if (this.fallbackTimer) {
      clearInterval(this.fallbackTimer);
      this.fallbackTimer = 0;
    }
  }

  dispose() {
    this._stopClock();
    if (this.worker) {
      this.worker.terminate();
      this.worker = null;
    }
  }

  _onTick() {
    if (!this.playing) return;
    const ctx = this.getContext();
    const horizonTime = ctx.currentTime + this.lookahead;
    const horizonTick = this.tickAt(horizonTime);

    const tickToTime = (t) => this.timeOf(t);

    let guard = 0;
    while (this.nextTick < horizonTick && guard++ < 32) {
      let windowEnd = Math.ceil(horizonTick);

      if (this.loopEnabled && this.loopEnd > this.loopStart) {
        windowEnd = Math.min(windowEnd, this.loopEnd);
      } else if (this.endTick !== Infinity) {
        windowEnd = Math.min(windowEnd, this.endTick);
      }

      if (windowEnd > this.nextTick) {
        this.handlers.onSchedule?.(this.nextTick, windowEnd, tickToTime);
        this.nextTick = windowEnd;
      }

      if (this.loopEnabled && this.nextTick >= this.loopEnd) {
        // Zeitachse so verschieben, dass loopStart genau dort beginnt,
        // wo loopEnd geendet haette – nahtlos und ohne Drift.
        this.startTime = this.timeOf(this.loopEnd);
        this.startTick = this.loopStart;
        this.nextTick = this.loopStart;
        this.handlers.onLoop?.();
      } else if (!this.loopEnabled && this.nextTick >= this.endTick) {
        this.handlers.onEnd?.();
        return;
      }
    }
  }
}
