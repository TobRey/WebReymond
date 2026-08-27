/**
 * AI Groove – Aufbau der abspielbaren Ereignisliste.
 *
 * Wird von der Live-Engine UND vom Offline-Export verwendet, damit der Export
 * exakt so klingt wie die Wiedergabe.
 */

import { TICKS_PER_BAR, PPQ, ticksToSeconds } from '../core/util.js';

const SIXTEENTH = PPQ / 4;

/**
 * @param {object} project
 * @param {'pattern'|'song'} mode
 * @param {string|null} patternId  nur im Pattern-Modus
 * @returns {{events: Array, end: number}}
 */
export function buildTimeline(project, mode, patternId = null) {
  const events = [];

  if (mode === 'pattern') {
    const pattern = project.patterns.find((p) => p.id === patternId) || null;
    if (!pattern) return { events, end: TICKS_PER_BAR };
    for (const e of pattern.events) {
      events.push({ tick: e.tick, sampleId: e.sampleId, pitch: e.pitch, vel: e.vel, dur: e.dur, gain: 1 });
    }
    events.sort((a, b) => a.tick - b.tick);
    return { events, end: pattern.lengthBars * TICKS_PER_BAR };
  }

  let end = TICKS_PER_BAR;
  for (const clip of project.clips) {
    end = Math.max(end, clip.start + clip.length);

    if (clip.type === 'pattern') {
      const pattern = project.patterns.find((p) => p.id === clip.refId);
      if (!pattern) continue;
      const patLen = Math.max(1, pattern.lengthBars * TICKS_PER_BAR);
      const repeats = clip.loop ? Math.ceil((clip.length + clip.offset) / patLen) : 1;
      for (let r = 0; r < repeats; r++) {
        const base = clip.start + r * patLen - clip.offset;
        for (const e of pattern.events) {
          const tick = base + e.tick;
          if (tick < clip.start || tick >= clip.start + clip.length) continue;
          events.push({
            tick,
            sampleId: e.sampleId,
            pitch: e.pitch + clip.pitch,
            vel: e.vel,
            dur: e.dur,
            gain: clip.gain,
          });
        }
      }
    } else {
      events.push({
        tick: clip.start,
        sampleId: clip.refId,
        pitch: clip.pitch,
        vel: 1,
        dur: clip.length,
        gain: clip.gain,
        offsetTicks: clip.offset,
        audioClip: true,
      });
    }
  }

  events.sort((a, b) => a.tick - b.tick);
  return { events, end };
}

/**
 * Swing-Versatz in Sekunden.
 * Verschoben wird jeder zweite Sechzehntelschritt – das ist der musikalisch
 * uebliche Shuffle (50 % = gerade, 75 % = starker Swing).
 */
export function swingOffset(tick, swing, bpm) {
  if (swing <= 0) return 0;
  if (tick % (SIXTEENTH * 2) !== SIXTEENTH) return 0;
  return swing * 0.5 * ticksToSeconds(SIXTEENTH, bpm);
}

/** Erster Index mit tick >= value (binaere Suche). */
export function lowerBound(list, value) {
  let lo = 0;
  let hi = list.length;
  while (lo < hi) {
    const mid = (lo + hi) >> 1;
    if (list[mid].tick < value) lo = mid + 1;
    else hi = mid;
  }
  return lo;
}
