/**
 * AI Groove – Aktionsschicht fuer den Assistenten.
 *
 * Der Assistent veraendert NIEMALS direkt das DOM oder den Projektzustand.
 * Er erzeugt ausschliesslich Aktionen aus dieser Liste. Jede Aktion wird
 * geprueft, bevor sie ausgefuehrt wird, und ist ueber Undo umkehrbar.
 */

import { store } from '../core/store.js';
import { engine } from '../audio/engine.js';
import { createEvent, createClip, createPattern } from '../core/project.js';
import { TICKS_PER_BAR, PPQ, clamp, guessInstrumentName } from '../core/util.js';
import { createEffectState, FX_DEFS } from '../audio/effects.js';
import { generateVariants, addVariantAsSample, resolveDuration } from './generate.js';

export class ActionError extends Error {
  constructor(message) {
    super(message);
    this.name = 'ActionError';
  }
}

// --- Hilfsfunktionen zur Aufloesung von Namen --------------------------------

function norm(s) {
  return String(s || '')
    .toLowerCase()
    .replace(/[^a-z0-9äöüß ]/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();
}

/** Findet ein Sample anhand eines ungefaehren Namens. */
export function findSample(name) {
  const project = store.project;
  if (!name) return null;
  const q = norm(name);
  if (!q) return null;

  let best = null;
  let bestScore = 0;
  for (const s of project.samples) {
    const candidates = [norm(s.name), norm(guessInstrumentName(s.prompt) || ''), norm(s.prompt)];
    let score = 0;
    for (const c of candidates) {
      if (!c) continue;
      if (c === q) score = Math.max(score, 100);
      else if (c.startsWith(q) || q.startsWith(c)) score = Math.max(score, 70);
      else if (c.includes(q) || q.includes(c)) score = Math.max(score, 45);
    }
    if (score > bestScore) {
      bestScore = score;
      best = s;
    }
  }
  return bestScore >= 45 ? best : null;
}

export function findPattern(name) {
  const project = store.project;
  if (!name) return store.selectedPattern;
  const q = norm(name);
  return (
    project.patterns.find((p) => norm(p.name) === q) ||
    project.patterns.find((p) => norm(p.name).includes(q) || q.includes(norm(p.name))) ||
    null
  );
}

function resolveChannelId(target) {
  if (!target || norm(target) === 'master' || norm(target) === 'summe') return 'master';
  const sample = findSample(target);
  if (!sample) throw new ActionError(`Kein Instrument mit dem Namen „${target}“ gefunden.`);
  return sample.id;
}

/** Rasterweite in Ticks aus "Schritte pro Takt". */
function gridTicks(stepsPerBar) {
  return TICKS_PER_BAR / clamp(stepsPerBar || 16, 1, 64);
}

function requirePattern(name) {
  const pattern = findPattern(name);
  if (!pattern) throw new ActionError('Es ist kein Pattern ausgewählt. Bitte zuerst ein Pattern anlegen.');
  return pattern;
}

function requireNumber(value, min, max, label) {
  const n = Number(value);
  if (!Number.isFinite(n)) throw new ActionError(`${label} ist keine gültige Zahl.`);
  if (n < min || n > max) throw new ActionError(`${label} muss zwischen ${min} und ${max} liegen.`);
  return n;
}

// --- Aktionsdefinitionen -----------------------------------------------------

export const ACTIONS = {
  setBpm: {
    label: (p) => `Tempo auf ${p.value} BPM setzen`,
    run(p) {
      const v = requireNumber(p.value, 40, 250, 'Das Tempo');
      store.setBpm(v);
    },
  },

  setSwing: {
    label: (p) => `Swing auf ${Math.round(p.value * 100)} % setzen`,
    run(p) {
      store.setSwing(requireNumber(p.value, 0, 1, 'Der Swing-Wert'));
    },
  },

  setVolume: {
    label: (p) => `Lautstärke von ${p.target} auf ${Math.round(p.value * 100)} %`,
    run(p) {
      const id = resolveChannelId(p.target);
      const v = requireNumber(p.value, 0, 2, 'Die Lautstärke');
      if (id === 'master') {
        store.edit('Master-Lautstärke', ['master'], () => {
          store.project.master.gain = clamp(v, 0, 2);
        });
      } else {
        store.setChannelValue(id, 'gain', v);
      }
    },
  },

  setPan: {
    label: (p) => `Panorama von ${p.target} auf ${p.value > 0 ? 'rechts' : p.value < 0 ? 'links' : 'Mitte'}`,
    run(p) {
      store.setChannelValue(resolveChannelId(p.target), 'pan', requireNumber(p.value, -1, 1, 'Das Panorama'));
    },
  },

  setMute: {
    label: (p) => `${p.target} ${p.value ? 'stumm schalten' : 'hörbar machen'}`,
    run(p) {
      store.setChannelValue(resolveChannelId(p.target), 'mute', !!p.value);
    },
  },

  addEffect: {
    label: (p) => `${FX_DEFS[p.type]?.label || p.type} auf ${p.target} legen`,
    run(p) {
      if (!FX_DEFS[p.type]) throw new ActionError(`Unbekannter Effekt: ${p.type}`);
      const id = resolveChannelId(p.target);
      const fx = createEffectState(p.type);
      if (p.params && typeof p.params === 'object') {
        for (const [k, v] of Object.entries(p.params)) {
          const def = FX_DEFS[p.type].params.find((x) => x.key === k);
          if (def) fx.params[k] = clamp(Number(v), def.min, def.max);
        }
      }
      store.addEffect(id, fx);
    },
  },

  setEffectParam: {
    label: (p) => `${p.type}: ${p.key} auf ${p.value} (${p.target})`,
    run(p) {
      const id = resolveChannelId(p.target);
      const ch = store.getChannel(id);
      const fx = ch.effects.find((f) => f.type === p.type);
      if (!fx) throw new ActionError(`Auf ${p.target} liegt kein ${p.type}-Effekt.`);
      const def = FX_DEFS[p.type].params.find((x) => x.key === p.key);
      if (!def) throw new ActionError(`Unbekannter Parameter: ${p.key}`);
      store.edit('Effektwert (KI)', ['channels', 'master'], () => {
        const target = store.getChannel(id).effects.find((f) => f.id === fx.id);
        target.params[p.key] = clamp(Number(p.value), def.min, def.max);
      });
    },
  },

  createPattern: {
    label: (p) => `Pattern „${p.name || 'Neu'}“ anlegen`,
    run(p) {
      return store.addPattern({ name: p.name, lengthBars: clamp(Math.round(p.lengthBars || 1), 1, 64) });
    },
  },

  selectPattern: {
    label: (p) => `Pattern „${p.name}“ auswählen`,
    run(p) {
      const pattern = findPattern(p.name);
      if (!pattern) throw new ActionError(`Pattern „${p.name}“ nicht gefunden.`);
      store.selectPattern(pattern.id);
    },
  },

  setPatternLength: {
    label: (p) => `Patternlänge auf ${p.bars} Takte`,
    run(p) {
      const pattern = requirePattern(p.pattern);
      store.setPatternLength(pattern.id, requireNumber(p.bars, 1, 64, 'Die Länge'));
    },
  },

  /** Setzt eine Schrittfolge (0/1) fuer ein Instrument. */
  setSteps: {
    label: (p) => `Schritte für ${p.target} setzen`,
    run(p) {
      const pattern = requirePattern(p.pattern);
      const sample = findSample(p.target);
      if (!sample) throw new ActionError(`Kein Instrument mit dem Namen „${p.target}“ gefunden.`);
      if (!Array.isArray(p.steps)) throw new ActionError('Die Schrittfolge fehlt.');

      const g = gridTicks(p.grid || 16);
      const maxTick = pattern.lengthBars * TICKS_PER_BAR;
      const events = [];
      for (let i = 0; i < p.steps.length; i++) {
        if (!p.steps[i]) continue;
        const tick = i * g;
        if (tick >= maxTick) break;
        events.push(createEvent(sample.id, tick, { dur: g, vel: p.velocity ?? 1 }));
      }

      store.edit('Schritte setzen (KI)', ['patterns'], () => {
        const pat = store.getPattern(pattern.id);
        pat.events = pat.events.filter((e) => e.sampleId !== sample.id);
        pat.events.push(...events);
        if (!pat.rows.includes(sample.id)) pat.rows.push(sample.id);
      });
    },
  },

  /** Ergaenzt Schritte, ohne vorhandene zu loeschen. */
  addSteps: {
    label: (p) => `Schritte für ${p.target} ergänzen`,
    run(p) {
      const pattern = requirePattern(p.pattern);
      const sample = findSample(p.target);
      if (!sample) throw new ActionError(`Kein Instrument mit dem Namen „${p.target}“ gefunden.`);
      const g = gridTicks(p.grid || 16);
      const maxTick = pattern.lengthBars * TICKS_PER_BAR;
      const events = (p.indices || [])
        .map((i) => i * g)
        .filter((t) => t >= 0 && t < maxTick)
        .map((t) => createEvent(sample.id, t, { dur: g, vel: p.velocity ?? 1 }));
      if (!events.length) throw new ActionError('Es gibt keine gültigen Schritte zum Setzen.');
      store.addEvents(pattern.id, events, 'Schritte ergänzen (KI)');
    },
  },

  clearSteps: {
    label: (p) => `${p.target} im Pattern leeren`,
    run(p) {
      const pattern = requirePattern(p.pattern);
      const sample = findSample(p.target);
      if (!sample) throw new ActionError(`Kein Instrument mit dem Namen „${p.target}“ gefunden.`);
      store.edit('Spur leeren (KI)', ['patterns'], () => {
        const pat = store.getPattern(pattern.id);
        pat.events = pat.events.filter((e) => e.sampleId !== sample.id);
      });
    },
  },

  /** Entfernt jede n-te Note eines Instruments. */
  removeEveryNth: {
    label: (p) => `Jede ${p.n}. Note von ${p.target} entfernen`,
    run(p) {
      const pattern = requirePattern(p.pattern);
      const sample = findSample(p.target);
      if (!sample) throw new ActionError(`Kein Instrument mit dem Namen „${p.target}“ gefunden.`);
      const n = clamp(Math.round(p.n || 2), 2, 16);
      const offset = clamp(Math.round(p.offset ?? 1), 0, n - 1);

      store.edit('Noten ausdünnen (KI)', ['patterns'], () => {
        const pat = store.getPattern(pattern.id);
        const own = pat.events.filter((e) => e.sampleId === sample.id).sort((a, b) => a.tick - b.tick);
        const remove = new Set(own.filter((_, i) => i % n === offset).map((e) => e.id));
        pat.events = pat.events.filter((e) => !remove.has(e.id));
      });
    },
  },

  /** Verdoppelt oder halbiert die Notendichte (z. B. „Hats schneller“). */
  changeDensity: {
    label: (p) => `${p.target} ${p.factor >= 2 ? 'schneller' : 'langsamer'} machen`,
    run(p) {
      const pattern = requirePattern(p.pattern);
      const sample = findSample(p.target);
      if (!sample) throw new ActionError(`Kein Instrument mit dem Namen „${p.target}“ gefunden.`);
      const factor = p.factor >= 2 ? 2 : 0.5;

      store.edit('Dichte ändern (KI)', ['patterns'], () => {
        const pat = store.getPattern(pattern.id);
        const own = pat.events.filter((e) => e.sampleId === sample.id).sort((a, b) => a.tick - b.tick);
        if (!own.length) throw new ActionError(`${sample.name} hat in diesem Pattern noch keine Noten.`);

        if (factor === 2) {
          // Zwischen je zwei Noten eine weitere setzen.
          const spacing = own.length > 1 ? own[1].tick - own[0].tick : PPQ;
          const half = Math.max(PPQ / 8, Math.round(spacing / 2));
          const maxTick = pat.lengthBars * TICKS_PER_BAR;
          const existing = new Set(own.map((e) => e.tick));
          const added = [];
          for (const e of own) {
            const t = e.tick + half;
            if (t < maxTick && !existing.has(t)) {
              added.push(createEvent(sample.id, t, { dur: half, vel: e.vel * 0.85 }));
              existing.add(t);
            }
          }
          pat.events.push(...added);
        } else {
          const remove = new Set(own.filter((_, i) => i % 2 === 1).map((e) => e.id));
          pat.events = pat.events.filter((e) => !remove.has(e.id));
        }
      });
    },
  },

  assignPad: {
    label: (p) => `${p.target} auf Pad ${p.index + 1} legen`,
    run(p) {
      const sample = findSample(p.target);
      if (!sample) throw new ActionError(`Kein Instrument mit dem Namen „${p.target}“ gefunden.`);
      store.setPad(clamp(Math.round(p.index), 0, 15), sample.id);
    },
  },

  createClip: {
    label: (p) => `„${p.pattern}“ ab Takt ${p.startBar} ins Arrangement`,
    run(p) {
      const pattern = findPattern(p.pattern);
      if (!pattern) throw new ActionError(`Pattern „${p.pattern}“ nicht gefunden.`);
      const lanes = store.project.lanes;
      let laneId = p.laneId;
      if (!laneId) {
        const idx = clamp(Math.round(p.laneIndex ?? 0), 0, Math.max(0, lanes.length - 1));
        laneId = lanes[idx]?.id;
      }
      if (!laneId) throw new ActionError('Es gibt keine Spur im Arrangement.');

      const startBar = clamp(Math.round(p.startBar || 1), 1, 4096);
      const lengthBars = clamp(Math.round(p.lengthBars || pattern.lengthBars), 1, 512);
      const clip = createClip(
        laneId,
        'pattern',
        pattern.id,
        (startBar - 1) * TICKS_PER_BAR,
        lengthBars * TICKS_PER_BAR,
        { loop: true },
      );
      store.addClip(clip, 'Clip einfügen (KI)');
      return clip;
    },
  },

  moveClipsOfPattern: {
    label: (p) => `„${p.pattern}“ auf Takt ${p.toBar} verschieben`,
    run(p) {
      const pattern = findPattern(p.pattern);
      if (!pattern) throw new ActionError(`Pattern „${p.pattern}“ nicht gefunden.`);
      const toTick = (clamp(Math.round(p.toBar || 1), 1, 4096) - 1) * TICKS_PER_BAR;

      store.updateClips((clips) => {
        const own = clips.filter((c) => c.type === 'pattern' && c.refId === pattern.id);
        if (!own.length) throw new ActionError(`„${pattern.name}“ liegt noch nicht im Arrangement.`);
        const minStart = Math.min(...own.map((c) => c.start));
        const delta = toTick - minStart;
        for (const c of own) c.start = Math.max(0, c.start + delta);
      }, 'Clips verschieben (KI)');
    },
  },

  deleteClipsOfPattern: {
    label: (p) => `Clips von „${p.pattern}“ entfernen`,
    run(p) {
      const pattern = findPattern(p.pattern);
      if (!pattern) throw new ActionError(`Pattern „${p.pattern}“ nicht gefunden.`);
      const ids = store.project.clips.filter((c) => c.type === 'pattern' && c.refId === pattern.id).map((c) => c.id);
      if (!ids.length) throw new ActionError('Es gibt dazu keine Clips.');
      store.removeClips(ids, 'Clips entfernen (KI)');
    },
  },

  setLoop: {
    label: (p) => `Loop von Takt ${p.startBar} bis ${p.endBar}`,
    run(p) {
      const s = clamp(Math.round(p.startBar || 1), 1, 4096);
      const e = clamp(Math.round(p.endBar || s + 4), s + 1, 4097);
      store.setLoop({ enabled: true, start: (s - 1) * TICKS_PER_BAR, end: (e - 1) * TICKS_PER_BAR });
    },
  },

  addLane: {
    label: (p) => `Spur „${p.name}“ anlegen`,
    run(p) {
      store.addLane(String(p.name || 'Spur').slice(0, 40));
    },
  },

  /** Erzeugt ein Sample mit dem aktiven KI-Anbieter (asynchron). */
  generateSample: {
    async: true,
    label: (p) => `Sample erzeugen: „${p.prompt}“`,
    async run(p, ctx) {
      const prompt = String(p.prompt || '').trim();
      if (!prompt) throw new ActionError('Für die Klangerzeugung wird ein Prompt benötigt.');
      const duration = resolveDuration({
        preset: p.lengthPreset || 'oneshot',
        bars: p.bars,
        customSeconds: p.seconds,
        bpm: store.project.bpm,
        prompt,
      });
      const variants = await generateVariants({
        prompt,
        duration,
        count: 1,
        onProgress: (v, label) => ctx?.onProgress?.(v, label),
      });
      const sample = await addVariantAsSample(variants[0], { prompt, name: p.name });
      return sample;
    },
  },
};

// --- Pruefung und Ausfuehrung ------------------------------------------------

export function validateAction(action) {
  if (!action || typeof action !== 'object') return 'Ungültige Aktion.';
  const def = ACTIONS[action.type];
  if (!def) return `Unbekannte Aktion: ${action.type}`;
  if (action.params && typeof action.params !== 'object') return 'Ungültige Parameter.';
  return null;
}

export function describeAction(action) {
  const def = ACTIONS[action.type];
  if (!def) return action.type;
  try {
    return def.label(action.params || {});
  } catch (_) {
    return action.type;
  }
}

/**
 * Fuehrt einen Plan aus. Jede Aktion ist einzeln rueckgaengig zu machen.
 * @param {Array<{type:string, params:object}>} actions
 */
export async function executePlan(actions, { onStep = () => {}, onProgress = () => {} } = {}) {
  const applied = [];
  const errors = [];

  for (const action of actions) {
    const problem = validateAction(action);
    if (problem) {
      errors.push(problem);
      continue;
    }
    const def = ACTIONS[action.type];
    try {
      onStep(describeAction(action));
      if (def.async) await def.run(action.params || {}, { onProgress });
      else def.run(action.params || {});
      applied.push(describeAction(action));
    } catch (err) {
      errors.push(err instanceof ActionError ? err.message : `„${describeAction(action)}“ ist fehlgeschlagen.`);
    }
  }

  engine.invalidate();
  return { applied, errors };
}
