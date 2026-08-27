/**
 * AI Groove – zentraler Projektzustand.
 *
 * Der Store kennt weder DOM noch Web Audio. Er haelt die Daten, kuemmert sich um
 * Undo/Redo, Autosave in IndexedDB und meldet Aenderungen ueber den Event-Bus.
 */

import { bus, EV } from './bus.js';
import { History } from './history.js';
import {
  audioStore,
  projectStore,
  meta,
  collectGarbage,
  estimateStorage,
  StorageError,
} from './idb.js';
import {
  createProject,
  migrateProject,
  createSample,
  createChannel,
  createPattern,
  createEvent,
  createClip,
  createLane,
  createPad,
  createPadBank,
  PADS_PER_BANK,
  EXPIRY_MS,
  packProjectFile,
  unpackProjectFile,
  PROJECT_VERSION,
} from './project.js';
import { uid, clamp, clone, debounce, guessInstrumentName, TICKS_PER_BAR, PPQ } from './util.js';

const LAST_PROJECT_KEY = 'lastProjectId';
const LAST_ACTIVITY_KEY = 'lastActivity';
const AUTOSAVE_INTERVAL = 15000;

class Store {
  constructor() {
    /** @type {ReturnType<typeof createProject>} */
    this.project = createProject();
    this.dirty = false;
    this.saving = false;
    this.lastSavedAt = 0;
    this.storageBlocked = false;

    this.history = new History({
      getState: () => this.project,
      onApply: (scopes, label) => {
        this.#emitForScopes(scopes);
        this.markDirty();
        bus.emit(EV.HISTORY_CHANGED, { label });
      },
    });

    this.autosave = debounce(() => this.save().catch(() => {}), 2500);
    this._timer = 0;
  }

  // --- Lebenszyklus ----------------------------------------------------------

  startAutosave() {
    if (this._timer) return;
    this._timer = setInterval(() => {
      if (this.dirty) this.save().catch(() => {});
      meta.set(LAST_ACTIVITY_KEY, Date.now()).catch(() => {});
    }, AUTOSAVE_INTERVAL);

    // Beim Verlassen/Verstecken der Seite sofort sichern (wichtig auf iOS,
    // wo Safari Tabs jederzeit einfrieren darf).
    const flush = () => {
      if (this.dirty) this.save().catch(() => {});
    };
    document.addEventListener('visibilitychange', () => {
      if (document.visibilityState === 'hidden') flush();
    });
    window.addEventListener('pagehide', flush);
    window.addEventListener('beforeunload', flush);
  }

  markDirty() {
    this.dirty = true;
    bus.emit(EV.PROJECT_DIRTY);
    this.autosave();
  }

  #emitForScopes(scopes) {
    const set = new Set(scopes);
    if (set.has('samples')) bus.emit(EV.SAMPLES_CHANGED);
    if (set.has('patterns')) bus.emit(EV.PATTERNS_CHANGED);
    if (set.has('clips') || set.has('lanes')) bus.emit(EV.CLIPS_CHANGED);
    if (set.has('pads')) bus.emit(EV.PADS_CHANGED);
    if (set.has('channels') || set.has('master')) bus.emit(EV.MIXER_CHANGED);
    if (set.has('bpm') || set.has('swing') || set.has('loop') || set.has('metronome')) {
      bus.emit(EV.TRANSPORT_CHANGED);
    }
    bus.emit(EV.PROJECT_CHANGED);
  }

  /** Fuehrt eine Aenderung mit Undo-Eintrag aus. */
  edit(label, scopes, fn) {
    const result = this.history.run(label, scopes, fn);
    this.project.updatedAt = Date.now();
    this.#emitForScopes(scopes);
    this.markDirty();
    bus.emit(EV.HISTORY_CHANGED, { label });
    return result;
  }

  /** Aenderung ohne Undo-Eintrag (z. B. Auswahl, Zoom). */
  quiet(scopes, fn) {
    this.history.silent(fn);
    this.#emitForScopes(scopes);
    this.markDirty();
  }

  // --- Laden / Speichern -----------------------------------------------------

  async save() {
    if (this.saving) return;
    this.saving = true;
    try {
      this.project.updatedAt = Date.now();
      await projectStore.save(this.project);
      await meta.set(LAST_PROJECT_KEY, this.project.id);
      await meta.set(LAST_ACTIVITY_KEY, Date.now());
      this.dirty = false;
      this.lastSavedAt = Date.now();
      this.storageBlocked = false;
      bus.emit(EV.PROJECT_SAVED, { at: this.lastSavedAt });
    } catch (err) {
      this.storageBlocked = true;
      const code = err instanceof StorageError ? err.code : 'io';
      bus.emit(EV.STORAGE_WARNING, {
        code,
        message:
          code === 'quota'
            ? 'Der lokale Speicher ist voll. Exportiere das Projekt als .aigroove-Datei und lösche nicht mehr benötigte Projekte.'
            : 'Automatisches Speichern ist derzeit nicht möglich. Bitte exportiere dein Projekt zur Sicherheit.',
      });
      throw err;
    } finally {
      this.saving = false;
    }
  }

  async setProject(project, { fresh = false } = {}) {
    this.project = migrateProject(project);
    this.history.clear();
    this.dirty = fresh;
    bus.emit(EV.PROJECT_LOADED, this.project);
    bus.emit(EV.PROJECT_CHANGED);
    bus.emit(EV.SAMPLES_CHANGED);
    bus.emit(EV.PATTERNS_CHANGED);
    bus.emit(EV.CLIPS_CHANGED);
    bus.emit(EV.PADS_CHANGED);
    bus.emit(EV.MIXER_CHANGED);
    bus.emit(EV.TRANSPORT_CHANGED);
    if (fresh) await this.save().catch(() => {});
  }

  async newProject(name = 'Neues Projekt') {
    await this.setProject(createProject(name), { fresh: true });
    return this.project;
  }

  async loadProjectById(id) {
    const row = await projectStore.get(id);
    if (!row) throw new Error('Dieses Projekt ist nicht mehr im lokalen Speicher vorhanden.');
    await this.setProject(row.data);
    await meta.set(LAST_PROJECT_KEY, id);
    return this.project;
  }

  async listProjects() {
    const rows = await projectStore.list();
    return rows.map((r) => ({
      id: r.id,
      name: r.name,
      updatedAt: r.updatedAt,
      createdAt: r.createdAt,
      samples: r.data?.samples?.length || 0,
      patterns: r.data?.patterns?.length || 0,
      bpm: r.data?.bpm || 120,
      expired: Date.now() - (r.updatedAt || 0) > EXPIRY_MS,
    }));
  }

  async deleteProject(id) {
    await projectStore.del(id);
    await collectGarbage(this.project.id === id ? null : this.project);
  }

  async getLastProjectInfo() {
    const id = await meta.get(LAST_PROJECT_KEY);
    if (!id) return null;
    const row = await projectStore.get(id);
    if (!row) return null;
    const age = Date.now() - (row.updatedAt || 0);
    return {
      id: row.id,
      name: row.name,
      updatedAt: row.updatedAt,
      expired: age > EXPIRY_MS,
      ageMs: age,
      samples: row.data?.samples?.length || 0,
    };
  }

  // --- Samples ---------------------------------------------------------------

  getSample(id) {
    return this.project.samples.find((s) => s.id === id) || null;
  }

  /**
   * Legt ein neues Sample an: Audiodaten in IndexedDB, Metadaten ins Projekt.
   * @param {{bytes:ArrayBuffer, mime:string, name:string, duration:number,
   *          sampleRate:number, channels:number, source?:string, prompt?:string, gain?:number}} data
   */
  async addSample(data) {
    const dataId = uid('aud');
    await audioStore.put(dataId, data.bytes, data.mime);

    const sample = createSample({
      name: data.name,
      dataId,
      mime: data.mime,
      duration: data.duration,
      sampleRate: data.sampleRate,
      channels: data.channels,
      sizeBytes: data.bytes.byteLength,
      source: data.source,
      prompt: data.prompt,
      gain: data.gain,
    });

    this.edit('Sample hinzufügen', ['samples', 'channels', 'ui'], () => {
      this.project.samples.push(sample);
      this.project.channels[sample.id] = createChannel();
      this.project.ui.selectedSampleId = sample.id;
    });
    return sample;
  }

  /** Ersetzt die Audiodaten eines bestehenden Samples (z. B. nach dem Editor). */
  async replaceSampleAudio(sampleId, { bytes, mime, duration, sampleRate, channels }) {
    const sample = this.getSample(sampleId);
    if (!sample) throw new Error('Sample nicht gefunden.');
    const newId = uid('aud');
    await audioStore.put(newId, bytes, mime);
    const oldId = sample.dataId;

    this.edit('Sample bearbeiten', ['samples'], () => {
      const s = this.getSample(sampleId);
      s.dataId = newId;
      s.mime = mime;
      s.duration = duration;
      s.sampleRate = sampleRate;
      s.channels = channels;
      s.sizeBytes = bytes.byteLength;
    });

    // Alte Daten erst nach dem Speichern entfernen (Undo braucht sie noch).
    void oldId;
    return newId;
  }

  renameSample(id, name) {
    this.edit('Sample umbenennen', ['samples'], () => {
      const s = this.getSample(id);
      if (s) s.name = String(name).slice(0, 80) || s.name;
    });
  }

  setSampleGain(id, gain) {
    this.edit('Sample-Lautstärke', ['samples'], () => {
      const s = this.getSample(id);
      if (s) s.gain = clamp(gain, 0, 1);
    });
  }

  setSamplePitch(id, semis) {
    this.edit('Sample-Tonhöhe', ['samples'], () => {
      const s = this.getSample(id);
      if (s) s.pitch = clamp(Math.round(semis), -36, 36);
    });
  }

  async duplicateSample(id) {
    const src = this.getSample(id);
    if (!src) return null;
    const row = await audioStore.get(src.dataId);
    if (!row) throw new Error('Die Audiodaten dieses Samples fehlen.');
    const newDataId = uid('aud');
    await audioStore.put(newDataId, row.bytes, row.mime);

    const copy = createSample({
      ...src,
      id: uid('smp'),
      dataId: newDataId,
      name: `${src.name} Kopie`,
      createdAt: Date.now(),
    });
    this.edit('Sample duplizieren', ['samples', 'channels'], () => {
      const idx = this.project.samples.findIndex((s) => s.id === id);
      this.project.samples.splice(idx + 1, 0, copy);
      this.project.channels[copy.id] = clone(this.project.channels[id] || createChannel());
    });
    return copy;
  }

  deleteSample(id) {
    this.edit('Sample löschen', ['samples', 'channels', 'patterns', 'clips', 'pads', 'ui'], () => {
      const p = this.project;
      p.samples = p.samples.filter((s) => s.id !== id);
      delete p.channels[id];
      for (const pat of p.patterns) {
        pat.events = pat.events.filter((e) => e.sampleId !== id);
        pat.rows = pat.rows.filter((r) => r !== id);
      }
      p.clips = p.clips.filter((c) => !(c.type === 'audio' && c.refId === id));
      for (const bank of p.pads.banks) {
        bank.pads = bank.pads.map((pad) => (pad && pad.sampleId === id ? null : pad));
      }
      if (p.ui.selectedSampleId === id) p.ui.selectedSampleId = p.samples[0]?.id || null;
    });
  }

  moveSample(id, toIndex) {
    this.edit('Sample verschieben', ['samples'], () => {
      const list = this.project.samples;
      const from = list.findIndex((s) => s.id === id);
      if (from < 0) return;
      const [item] = list.splice(from, 1);
      list.splice(clamp(toIndex, 0, list.length), 0, item);
    });
  }

  // --- Kanaele / Mixer -------------------------------------------------------

  getChannel(id) {
    if (id === 'master') return this.project.master;
    if (!this.project.channels[id]) this.project.channels[id] = createChannel();
    return this.project.channels[id];
  }

  setChannelValue(id, key, value) {
    this.edit(`Mixer: ${key}`, ['channels', 'master'], () => {
      const ch = this.getChannel(id);
      if (key === 'gain') ch.gain = clamp(value, 0, 2);
      else if (key === 'pan') ch.pan = clamp(value, -1, 1);
      else ch[key] = value;
    });
  }

  anySolo() {
    return Object.values(this.project.channels).some((c) => c.solo);
  }

  addEffect(channelId, state) {
    this.edit('Effekt hinzufügen', ['channels', 'master'], () => {
      this.getChannel(channelId).effects.push(state);
    });
  }

  removeEffect(channelId, fxId) {
    this.edit('Effekt entfernen', ['channels', 'master'], () => {
      const ch = this.getChannel(channelId);
      ch.effects = ch.effects.filter((f) => f.id !== fxId);
    });
  }

  moveEffect(channelId, fxId, delta) {
    this.edit('Effektreihenfolge', ['channels', 'master'], () => {
      const ch = this.getChannel(channelId);
      const i = ch.effects.findIndex((f) => f.id === fxId);
      const j = i + delta;
      if (i < 0 || j < 0 || j >= ch.effects.length) return;
      const [fx] = ch.effects.splice(i, 1);
      ch.effects.splice(j, 0, fx);
    });
  }

  setEffectParam(channelId, fxId, key, value) {
    // Reglerbewegungen erzeugen bewusst keinen Undo-Eintrag pro Frame –
    // die UI ruft beforeParamChange()/afterParamChange() um die Geste herum auf.
    const ch = this.getChannel(channelId);
    const fx = ch.effects.find((f) => f.id === fxId);
    if (!fx) return;
    fx.params[key] = value;
    bus.emit(EV.MIXER_CHANGED, { channelId, fxId, key, value, live: true });
    this.markDirty();
  }

  setEffectEnabled(channelId, fxId, enabled) {
    this.edit('Effekt schalten', ['channels', 'master'], () => {
      const fx = this.getChannel(channelId).effects.find((f) => f.id === fxId);
      if (fx) fx.enabled = !!enabled;
    });
  }

  // --- Pads ------------------------------------------------------------------

  get activeBank() {
    const p = this.project.pads;
    return p.banks[clamp(p.activeBank, 0, p.banks.length - 1)];
  }

  setPad(index, sampleId) {
    this.edit('Pad belegen', ['pads'], () => {
      const bank = this.activeBank;
      bank.pads[index] = sampleId ? createPad(sampleId) : null;
    });
  }

  updatePad(index, patch) {
    this.edit('Pad-Einstellung', ['pads'], () => {
      const bank = this.activeBank;
      if (bank.pads[index]) Object.assign(bank.pads[index], patch);
    });
  }

  addPadBank() {
    this.edit('Pad-Bank hinzufügen', ['pads'], () => {
      const p = this.project.pads;
      p.banks.push(createPadBank(p.banks.length));
      p.activeBank = p.banks.length - 1;
    });
  }

  removePadBank(index) {
    if (this.project.pads.banks.length <= 1) return;
    this.edit('Pad-Bank entfernen', ['pads'], () => {
      const p = this.project.pads;
      p.banks.splice(index, 1);
      p.activeBank = clamp(p.activeBank, 0, p.banks.length - 1);
    });
  }

  setActiveBank(index) {
    this.quiet(['pads'], () => {
      this.project.pads.activeBank = clamp(index, 0, this.project.pads.banks.length - 1);
    });
  }

  setPadKeys(keys) {
    this.edit('Tastenbelegung', ['pads'], () => {
      this.project.pads.keys = keys.slice(0, PADS_PER_BANK).map((k) => String(k).slice(0, 12));
    });
  }

  // --- Patterns --------------------------------------------------------------

  getPattern(id) {
    return this.project.patterns.find((p) => p.id === id) || null;
  }

  get selectedPattern() {
    return this.getPattern(this.project.ui.selectedPatternId);
  }

  /** Erzeugt einen sinnvollen Namen aus den enthaltenen Samples. */
  #patternNameFor(sampleIds) {
    for (const id of sampleIds) {
      const s = this.getSample(id);
      if (!s) continue;
      const guess = guessInstrumentName(s.prompt) || guessInstrumentName(s.name);
      if (guess && !this.project.patterns.some((p) => p.name === guess)) return guess;
      if (guess) {
        let n = 2;
        while (this.project.patterns.some((p) => p.name === `${guess} ${n}`)) n++;
        return `${guess} ${n}`;
      }
    }
    let n = this.project.patterns.length + 1;
    while (this.project.patterns.some((p) => p.name === `Pattern ${n}`)) n++;
    return `Pattern ${n}`;
  }

  addPattern(opts = {}) {
    const rows = opts.rows || [];
    const pattern = createPattern(opts.name || this.#patternNameFor(rows), {
      lengthBars: opts.lengthBars || 1,
      grid: opts.grid || 16,
      rows,
    });
    this.edit('Pattern erstellen', ['patterns', 'ui'], () => {
      this.project.patterns.push(pattern);
      this.project.ui.selectedPatternId = pattern.id;
    });
    bus.emit(EV.PATTERN_SELECTED, pattern.id);
    return pattern;
  }

  renamePattern(id, name) {
    this.edit('Pattern umbenennen', ['patterns'], () => {
      const p = this.getPattern(id);
      if (p) p.name = String(name).slice(0, 60) || p.name;
    });
  }

  deletePattern(id) {
    this.edit('Pattern löschen', ['patterns', 'clips', 'ui'], () => {
      const p = this.project;
      p.patterns = p.patterns.filter((x) => x.id !== id);
      p.clips = p.clips.filter((c) => !(c.type === 'pattern' && c.refId === id));
      if (p.ui.selectedPatternId === id) p.ui.selectedPatternId = p.patterns[0]?.id || null;
    });
  }

  duplicatePattern(id) {
    const src = this.getPattern(id);
    if (!src) return null;
    const copy = clone(src);
    copy.id = uid('pat');
    copy.name = `${src.name} Kopie`;
    copy.events = copy.events.map((e) => ({ ...e, id: uid('ev') }));
    this.edit('Pattern duplizieren', ['patterns', 'ui'], () => {
      const idx = this.project.patterns.findIndex((p) => p.id === id);
      this.project.patterns.splice(idx + 1, 0, copy);
      this.project.ui.selectedPatternId = copy.id;
    });
    return copy;
  }

  selectPattern(id) {
    if (this.project.ui.selectedPatternId === id) return;
    this.quiet(['ui'], () => {
      this.project.ui.selectedPatternId = id;
    });
    bus.emit(EV.PATTERN_SELECTED, id);
  }

  ensurePatternRow(patternId, sampleId) {
    const pat = this.getPattern(patternId);
    if (!pat || pat.rows.includes(sampleId)) return;
    this.edit('Instrument in Pattern', ['patterns'], () => {
      const p = this.getPattern(patternId);
      p.rows.push(sampleId);
      // Automatische Benennung, wenn das Pattern noch den Standardnamen traegt.
      if (/^Pattern \d+$/.test(p.name)) {
        const s = this.getSample(sampleId);
        const guess = guessInstrumentName(s?.prompt) || guessInstrumentName(s?.name);
        if (guess && !this.project.patterns.some((x) => x.id !== p.id && x.name === guess)) {
          p.name = guess;
        }
      }
    });
  }

  removePatternRow(patternId, sampleId) {
    this.edit('Instrument entfernen', ['patterns'], () => {
      const p = this.getPattern(patternId);
      if (!p) return;
      p.rows = p.rows.filter((r) => r !== sampleId);
      p.events = p.events.filter((e) => e.sampleId !== sampleId);
    });
  }

  setPatternLength(patternId, bars) {
    this.edit('Patternlänge', ['patterns'], () => {
      const p = this.getPattern(patternId);
      if (!p) return;
      p.lengthBars = clamp(Math.round(bars), 1, 64);
      const max = p.lengthBars * TICKS_PER_BAR;
      p.events = p.events.filter((e) => e.tick < max);
    });
  }

  setPatternGrid(patternId, grid) {
    this.quiet(['patterns'], () => {
      const p = this.getPattern(patternId);
      if (p) p.grid = grid;
    });
  }

  // --- Events innerhalb eines Patterns ---------------------------------------

  addEvents(patternId, events, label = 'Noten setzen') {
    this.edit(label, ['patterns'], () => {
      const p = this.getPattern(patternId);
      if (!p) return;
      for (const e of events) {
        p.events.push(e);
        if (!p.rows.includes(e.sampleId)) p.rows.push(e.sampleId);
      }
    });
  }

  removeEvents(patternId, eventIds, label = 'Noten löschen') {
    const set = new Set(eventIds);
    this.edit(label, ['patterns'], () => {
      const p = this.getPattern(patternId);
      if (p) p.events = p.events.filter((e) => !set.has(e.id));
    });
  }

  updateEvents(patternId, updater, label = 'Noten ändern') {
    this.edit(label, ['patterns'], () => {
      const p = this.getPattern(patternId);
      if (p) updater(p);
    });
  }

  // --- Spuren & Clips --------------------------------------------------------

  addLane(name) {
    this.edit('Spur hinzufügen', ['lanes'], () => {
      this.project.lanes.push(createLane(name, this.project.lanes.length));
    });
  }

  removeLane(id) {
    if (this.project.lanes.length <= 1) return;
    this.edit('Spur entfernen', ['lanes', 'clips'], () => {
      this.project.lanes = this.project.lanes.filter((l) => l.id !== id);
      this.project.clips = this.project.clips.filter((c) => c.laneId !== id);
    });
  }

  renameLane(id, name) {
    this.edit('Spur umbenennen', ['lanes'], () => {
      const l = this.project.lanes.find((x) => x.id === id);
      if (l) l.name = String(name).slice(0, 40) || l.name;
    });
  }

  addClip(clip, label = 'Clip einfügen') {
    this.edit(label, ['clips'], () => {
      this.project.clips.push(clip);
    });
    return clip;
  }

  updateClips(updater, label = 'Clips ändern') {
    this.edit(label, ['clips'], () => updater(this.project.clips));
  }

  removeClips(ids, label = 'Clips löschen') {
    const set = new Set(ids);
    this.edit(label, ['clips'], () => {
      this.project.clips = this.project.clips.filter((c) => !set.has(c.id));
    });
  }

  getClip(id) {
    return this.project.clips.find((c) => c.id === id) || null;
  }

  // --- Transport -------------------------------------------------------------

  setBpm(bpm) {
    const v = clamp(Math.round(bpm * 10) / 10, 40, 250);
    if (v === this.project.bpm) return;
    this.edit('Tempo ändern', ['bpm'], () => {
      this.project.bpm = v;
    });
  }

  setSwing(v) {
    this.quiet(['swing'], () => {
      this.project.swing = clamp(v, 0, 1);
    });
    bus.emit(EV.TRANSPORT_CHANGED);
  }

  setLoop(patch) {
    this.quiet(['loop'], () => {
      Object.assign(this.project.loop, patch);
      if (this.project.loop.end <= this.project.loop.start) {
        this.project.loop.end = this.project.loop.start + TICKS_PER_BAR;
      }
    });
    bus.emit(EV.TRANSPORT_CHANGED);
  }

  setMetronome(patch) {
    this.quiet(['metronome'], () => Object.assign(this.project.metronome, patch));
    bus.emit(EV.TRANSPORT_CHANGED);
  }

  setName(name) {
    this.edit('Projekt umbenennen', ['name'], () => {
      this.project.name = String(name).slice(0, 120) || 'Projekt';
    });
  }

  setUi(patch) {
    this.quiet(['ui'], () => Object.assign(this.project.ui, patch));
  }

  // --- Export / Import der Projektdatei --------------------------------------

  async exportProjectFile() {
    const assets = [];
    for (const s of this.project.samples) {
      const row = await audioStore.get(s.dataId);
      if (row) assets.push({ id: s.dataId, mime: row.mime, bytes: row.bytes });
    }
    // Keine API-Keys, keine persoenlichen Daten – nur Projekt und Audio.
    const clean = clone(this.project);
    return packProjectFile(clean, assets);
  }

  async importProjectFile(arrayBuffer) {
    const { project, assets } = unpackProjectFile(arrayBuffer);
    for (const a of assets) {
      await audioStore.put(a.id, a.bytes, a.mime);
    }
    const imported = migrateProject(project);
    imported.id = uid('prj'); // eigenes lokales Projekt, kein Ueberschreiben
    imported.updatedAt = Date.now();
    await this.setProject(imported, { fresh: true });
    return imported;
  }

  // --- Speicherinfo ----------------------------------------------------------

  async storageInfo() {
    const [est, used] = await Promise.all([estimateStorage(), audioStore.totalSize()]);
    return { ...est, audioBytes: used };
  }

  async cleanup() {
    return collectGarbage(this.project);
  }
}

export const store = new Store();

// Praktische Re-Exporte fuer die Oberflaeche.
export { createEvent, createClip, createPattern, PROJECT_VERSION, PPQ, TICKS_PER_BAR };
