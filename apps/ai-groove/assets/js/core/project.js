/**
 * AI Groove – Projektmodell, Standardwerte, Migration und Dateiformat.
 *
 * Zeitbasis: Ticks (PPQ = 96). Alles Musikalische wird in Ticks gespeichert,
 * niemals in Sekunden – dadurch bleibt ein Projekt bei BPM-Wechseln korrekt.
 */

import { PPQ, TICKS_PER_BAR, uid, clamp, colorIndexFor } from './util.js';
import { FX_DEFS, normalizeEffectState } from '../audio/effects.js';

/** Aktuelle Version des Projektformats. Wird in die .aigroove-Datei geschrieben. */
export const PROJECT_VERSION = 1;

/** Magische Kennung am Dateianfang. */
export const FILE_MAGIC = 'AIGROOVE';

/** Nach dieser Zeit ohne Aktivitaet gilt ein temporaeres Projekt als abgelaufen. */
export const EXPIRY_MS = 12 * 60 * 60 * 1000;

export const DEFAULT_SAMPLE_GAIN = 0.3;

export const PAD_ROWS = 4;
export const PAD_COLS = 4;
export const PADS_PER_BANK = PAD_ROWS * PAD_COLS;

/** Standard-Tastenbelegung des Launchpads (vier Reihen der Tastatur). */
export const DEFAULT_PAD_KEYS = [
  '1', '2', '3', '4',
  'q', 'w', 'e', 'r',
  'a', 's', 'd', 'f',
  'y', 'x', 'c', 'v',
];

export function createPadBank(index = 0) {
  return {
    id: uid('bank'),
    name: `Bank ${String.fromCharCode(65 + index)}`,
    pads: Array.from({ length: PADS_PER_BANK }, () => null),
  };
}

export function createPad(sampleId) {
  return { sampleId, mode: 'oneshot', gain: 1, pitch: 0 };
}

export function createLane(name, index = 0) {
  return { id: uid('lane'), name: name || `Spur ${index + 1}`, colorIdx: (index % 8) + 1, height: 1 };
}

export function createChannel() {
  return { gain: 1, pan: 0, mute: false, solo: false, effects: [] };
}

export function createPattern(name, opts = {}) {
  const id = uid('pat');
  return {
    id,
    name: name || 'Pattern',
    colorIdx: colorIndexFor(id),
    lengthBars: opts.lengthBars || 1,
    grid: opts.grid || 16, // Anzeige-Raster: Schritte pro Takt
    rows: opts.rows ? [...opts.rows] : [],
    events: [],
  };
}

export function createEvent(sampleId, tick, opts = {}) {
  return {
    id: uid('ev'),
    sampleId,
    tick: Math.max(0, Math.round(tick)),
    dur: opts.dur ?? PPQ / 4,
    pitch: opts.pitch ?? 0,
    vel: opts.vel ?? 1,
  };
}

export function createClip(laneId, type, refId, start, length, opts = {}) {
  return {
    id: uid('clip'),
    laneId,
    type, // 'pattern' | 'audio'
    refId,
    start: Math.max(0, Math.round(start)),
    length: Math.max(1, Math.round(length)),
    offset: opts.offset ?? 0,
    loop: opts.loop ?? type === 'pattern',
    gain: opts.gain ?? 1,
    pitch: opts.pitch ?? 0,
  };
}

export function createSample(data) {
  const id = data.id || uid('smp');
  return {
    id,
    name: data.name || 'Sample',
    dataId: data.dataId,
    mime: data.mime || 'audio/wav',
    duration: data.duration || 0,
    sampleRate: data.sampleRate || 44100,
    channels: data.channels || 1,
    sizeBytes: data.sizeBytes || 0,
    gain: data.gain ?? DEFAULT_SAMPLE_GAIN,
    pitch: data.pitch ?? 0,
    source: data.source || 'file', // file | mic | ai | edit
    prompt: data.prompt || '',
    colorIdx: data.colorIdx || colorIndexFor(id),
    createdAt: data.createdAt || Date.now(),
  };
}

/** Ein frisches, leeres Projekt. */
export function createProject(name = 'Neues Projekt') {
  const now = Date.now();
  const lanes = [createLane('Spur 1', 0), createLane('Spur 2', 1), createLane('Spur 3', 2), createLane('Spur 4', 3)];
  // Ein leeres Pattern ist von Anfang an vorhanden – sonst startet der
  // Sequencer mit einem leeren Bildschirm.
  const firstPattern = createPattern('Pattern 1', { lengthBars: 1, grid: 16 });
  return {
    format: 'aigroove',
    version: PROJECT_VERSION,
    id: uid('prj'),
    name,
    createdAt: now,
    updatedAt: now,

    bpm: 128,
    swing: 0,
    beatsPerBar: 4,

    master: { gain: 0.8, effects: [] },
    metronome: { enabled: false, gain: 0.45, countIn: 0 },
    loop: { enabled: false, start: 0, end: TICKS_PER_BAR * 4 },
    arrangeBars: 32,

    samples: [],
    channels: {},
    pads: { activeBank: 0, keys: [...DEFAULT_PAD_KEYS], banks: [createPadBank(0)] },
    patterns: [firstPattern],
    lanes,
    clips: [],

    ui: {
      view: 'pads',
      selectedPatternId: firstPattern.id,
      selectedSampleId: null,
      selectedChannelId: 'master',
      seqZoom: 1,
      arrZoom: 1,
    },
  };
}

/**
 * Repariert und ergaenzt ein geladenes Projekt.
 * Sorgt dafuer, dass aeltere Dateien mit neueren Programmversionen funktionieren.
 */
export function migrateProject(raw) {
  if (!raw || typeof raw !== 'object') throw new Error('Projektdatei ist beschädigt.');
  const base = createProject(raw.name || 'Projekt');
  const p = { ...base, ...raw };

  p.format = 'aigroove';
  p.version = PROJECT_VERSION;
  p.id = raw.id || base.id;
  p.name = String(raw.name || base.name).slice(0, 120);
  p.createdAt = Number(raw.createdAt) || base.createdAt;
  p.updatedAt = Number(raw.updatedAt) || Date.now();
  p.bpm = clamp(Number(raw.bpm) || 128, 40, 250);
  p.swing = clamp(Number(raw.swing) || 0, 0, 1);
  p.beatsPerBar = clamp(Math.round(Number(raw.beatsPerBar) || 4), 1, 12);
  p.arrangeBars = clamp(Math.round(Number(raw.arrangeBars) || 32), 4, 4096);

  p.master = {
    gain: clamp(Number(raw.master?.gain ?? 0.8), 0, 2),
    effects: (raw.master?.effects || []).map(normalizeEffectState).filter(Boolean),
  };
  p.metronome = {
    enabled: !!raw.metronome?.enabled,
    gain: clamp(Number(raw.metronome?.gain ?? 0.45), 0, 1),
    countIn: clamp(Math.round(Number(raw.metronome?.countIn) || 0), 0, 4),
  };
  p.loop = {
    enabled: !!raw.loop?.enabled,
    start: Math.max(0, Math.round(Number(raw.loop?.start) || 0)),
    end: Math.max(TICKS_PER_BAR, Math.round(Number(raw.loop?.end) || TICKS_PER_BAR * 4)),
  };

  // --- Samples ---------------------------------------------------------------
  p.samples = (Array.isArray(raw.samples) ? raw.samples : [])
    .filter((s) => s && s.id && s.dataId)
    .map((s) =>
      createSample({
        ...s,
        gain: clamp(Number(s.gain ?? DEFAULT_SAMPLE_GAIN), 0, 1),
        pitch: clamp(Math.round(Number(s.pitch) || 0), -36, 36),
      }),
    );
  const sampleIds = new Set(p.samples.map((s) => s.id));

  // --- Mixerkanaele ----------------------------------------------------------
  p.channels = {};
  for (const id of [...sampleIds]) {
    const src = raw.channels?.[id] || {};
    p.channels[id] = {
      gain: clamp(Number(src.gain ?? 1), 0, 2),
      pan: clamp(Number(src.pan ?? 0), -1, 1),
      mute: !!src.mute,
      solo: !!src.solo,
      effects: (src.effects || []).map(normalizeEffectState).filter(Boolean),
    };
  }

  // --- Pads ------------------------------------------------------------------
  const banks = Array.isArray(raw.pads?.banks) && raw.pads.banks.length ? raw.pads.banks : [createPadBank(0)];
  p.pads = {
    activeBank: clamp(Math.round(Number(raw.pads?.activeBank) || 0), 0, banks.length - 1),
    keys:
      Array.isArray(raw.pads?.keys) && raw.pads.keys.length === PADS_PER_BANK
        ? raw.pads.keys.map((k) => String(k).slice(0, 12))
        : [...DEFAULT_PAD_KEYS],
    banks: banks.map((b, i) => ({
      id: b.id || uid('bank'),
      name: String(b.name || `Bank ${String.fromCharCode(65 + i)}`).slice(0, 24),
      pads: Array.from({ length: PADS_PER_BANK }, (_, idx) => {
        const pad = b.pads?.[idx];
        if (!pad || !sampleIds.has(pad.sampleId)) return null;
        return {
          sampleId: pad.sampleId,
          mode: ['oneshot', 'hold', 'toggle'].includes(pad.mode) ? pad.mode : 'oneshot',
          gain: clamp(Number(pad.gain ?? 1), 0, 2),
          pitch: clamp(Math.round(Number(pad.pitch) || 0), -36, 36),
        };
      }),
    })),
  };

  // --- Patterns --------------------------------------------------------------
  p.patterns = (Array.isArray(raw.patterns) ? raw.patterns : []).map((pat) => {
    const id = pat.id || uid('pat');
    const rows = (Array.isArray(pat.rows) ? pat.rows : []).filter((r) => sampleIds.has(r));
    const events = (Array.isArray(pat.events) ? pat.events : [])
      .filter((e) => e && sampleIds.has(e.sampleId))
      .map((e) => ({
        id: e.id || uid('ev'),
        sampleId: e.sampleId,
        tick: Math.max(0, Math.round(Number(e.tick) || 0)),
        dur: clamp(Math.round(Number(e.dur) || PPQ / 4), 1, TICKS_PER_BAR * 64),
        pitch: clamp(Math.round(Number(e.pitch) || 0), -36, 36),
        vel: clamp(Number(e.vel ?? 1), 0, 1),
      }));
    // Zeilen ergaenzen, die nur in Events vorkommen.
    for (const e of events) if (!rows.includes(e.sampleId)) rows.push(e.sampleId);
    return {
      id,
      name: String(pat.name || 'Pattern').slice(0, 60),
      colorIdx: clamp(Math.round(Number(pat.colorIdx) || colorIndexFor(id)), 1, 8),
      lengthBars: clamp(Math.round(Number(pat.lengthBars) || 1), 1, 64),
      grid: [4, 8, 16, 32].includes(Number(pat.grid)) ? Number(pat.grid) : 16,
      rows,
      events,
    };
  });
  const patternIds = new Set(p.patterns.map((x) => x.id));

  // --- Spuren & Clips --------------------------------------------------------
  p.lanes =
    Array.isArray(raw.lanes) && raw.lanes.length
      ? raw.lanes.map((l, i) => ({
          id: l.id || uid('lane'),
          name: String(l.name || `Spur ${i + 1}`).slice(0, 40),
          colorIdx: clamp(Math.round(Number(l.colorIdx) || (i % 8) + 1), 1, 8),
          height: clamp(Number(l.height) || 1, 0.5, 3),
        }))
      : base.lanes;
  const laneIds = new Set(p.lanes.map((l) => l.id));

  p.clips = (Array.isArray(raw.clips) ? raw.clips : [])
    .filter((c) => c && laneIds.has(c.laneId))
    .filter((c) => (c.type === 'pattern' ? patternIds.has(c.refId) : sampleIds.has(c.refId)))
    .map((c) => ({
      id: c.id || uid('clip'),
      laneId: c.laneId,
      type: c.type === 'audio' ? 'audio' : 'pattern',
      refId: c.refId,
      start: Math.max(0, Math.round(Number(c.start) || 0)),
      length: clamp(Math.round(Number(c.length) || TICKS_PER_BAR), 1, TICKS_PER_BAR * 4096),
      offset: Math.max(0, Math.round(Number(c.offset) || 0)),
      loop: c.loop !== false,
      gain: clamp(Number(c.gain ?? 1), 0, 2),
      pitch: clamp(Math.round(Number(c.pitch) || 0), -36, 36),
    }));

  // --- Oberflaechenzustand ---------------------------------------------------
  p.ui = {
    ...base.ui,
    ...(raw.ui || {}),
    selectedPatternId: patternIds.has(raw.ui?.selectedPatternId) ? raw.ui.selectedPatternId : p.patterns[0]?.id || null,
    selectedSampleId: sampleIds.has(raw.ui?.selectedSampleId) ? raw.ui.selectedSampleId : p.samples[0]?.id || null,
  };

  return p;
}

/** Alle im Projekt verwendeten Audio-IDs. */
export function collectDataIds(project) {
  const ids = new Set();
  for (const s of project.samples || []) if (s.dataId) ids.add(s.dataId);
  return [...ids];
}

// =============================================================================
//  .aigroove-Dateiformat
// =============================================================================
//
//  Offset  Groesse  Inhalt
//  0       8        "AIGROOVE"
//  8       4        uint32  Formatversion (little endian)
//  12      4        uint32  Laenge des JSON-Blocks in Bytes
//  16      n        JSON (UTF-8): { project, assets:[{id,mime,size}] }
//  16+n    …        Rohdaten der Assets, in der Reihenfolge des Index
//
//  Das Format ist bewusst simpel und versioniert: kuenftige Versionen koennen
//  weitere Felder ergaenzen, ohne alte Dateien unlesbar zu machen.

/**
 * Baut die Projektdatei.
 * @param {object} project
 * @param {Array<{id:string, mime:string, bytes:ArrayBuffer}>} assets
 * @returns {Blob}
 */
export function packProjectFile(project, assets) {
  const index = assets.map((a) => ({ id: a.id, mime: a.mime || 'audio/wav', size: a.bytes.byteLength }));
  const header = {
    app: 'AI Groove',
    version: PROJECT_VERSION,
    exportedAt: Date.now(),
    project,
    assets: index,
  };
  const json = new TextEncoder().encode(JSON.stringify(header));

  const magic = new TextEncoder().encode(FILE_MAGIC);
  const prefix = new Uint8Array(16);
  prefix.set(magic, 0);
  const dv = new DataView(prefix.buffer);
  dv.setUint32(8, PROJECT_VERSION, true);
  dv.setUint32(12, json.byteLength, true);

  const parts = [prefix, json];
  for (const a of assets) parts.push(a.bytes);
  return new Blob(parts, { type: 'application/octet-stream' });
}

/**
 * Liest eine Projektdatei.
 * @param {ArrayBuffer} buffer
 * @returns {{project:object, assets:Array<{id:string,mime:string,bytes:ArrayBuffer}>, fileVersion:number}}
 */
export function unpackProjectFile(buffer) {
  const bytes = new Uint8Array(buffer);
  if (bytes.byteLength < 20) throw new Error('Die Datei ist zu klein und vermutlich beschädigt.');

  const magic = new TextDecoder().decode(bytes.subarray(0, 8));
  if (magic !== FILE_MAGIC) {
    throw new Error('Das ist keine AI-Groove-Projektdatei.');
  }

  const dv = new DataView(buffer);
  const fileVersion = dv.getUint32(8, true);
  const jsonLen = dv.getUint32(12, true);
  if (jsonLen <= 0 || 16 + jsonLen > bytes.byteLength) {
    throw new Error('Die Projektdatei ist beschädigt (ungültiger Kopfbereich).');
  }
  if (fileVersion > PROJECT_VERSION) {
    throw new Error(
      `Diese Datei wurde mit einer neueren AI-Groove-Version erstellt (Format ${fileVersion}). Bitte AI Groove aktualisieren.`,
    );
  }

  let header;
  try {
    header = JSON.parse(new TextDecoder().decode(bytes.subarray(16, 16 + jsonLen)));
  } catch (_) {
    throw new Error('Die Projektdatei ist beschädigt (JSON konnte nicht gelesen werden).');
  }

  const assets = [];
  let offset = 16 + jsonLen;
  for (const entry of header.assets || []) {
    const size = Number(entry.size) || 0;
    if (offset + size > bytes.byteLength) {
      throw new Error('Die Projektdatei ist unvollständig (Audiodaten fehlen).');
    }
    assets.push({
      id: String(entry.id),
      mime: String(entry.mime || 'audio/wav'),
      bytes: buffer.slice(offset, offset + size),
    });
    offset += size;
  }

  if (!header.project) throw new Error('Die Projektdatei enthält kein Projekt.');
  return { project: header.project, assets, fileVersion };
}

/** Praktisch fuer die Oberflaeche: Anzahl Takte, die das Arrangement belegt. */
export function arrangementLengthTicks(project) {
  let end = 0;
  for (const c of project.clips) end = Math.max(end, c.start + c.length);
  return Math.max(end, TICKS_PER_BAR * 4);
}

export { FX_DEFS };
