// Download-Warteschlange: überträgt Originaldateien Byte für Byte (keine Umwandlung),
// speichert sie stückweise in IndexedDB und setzt Abbrüche per HTTP-Range fort.

import { camUrl, CamError } from './api.js';
import { db } from './db.js';
import { fileKind, sleep } from './util.js';

const CHUNK = 4 * 1024 * 1024; // so viel wird je IndexedDB-Eintrag gesammelt
const MAX_AUTO_RETRY = 4;
const BACKOFF = [2000, 5000, 10000, 20000];

export class Queue extends EventTarget {
  constructor() {
    super();
    this.items = new Map();
    this.camId = '';
    this.active = null;
    this.ctl = null;
    this.halted = false; // "Alle pausieren"
    this.timer = null;
  }

  async load() {
    for (const it of await db.all('transfers')) {
      if (it.status === 'running') it.status = 'queued';
      it.live = 0;
      this.items.set(it.id, it);
    }
  }

  emit(type, item) {
    this.dispatchEvent(new CustomEvent(type, { detail: item }));
  }

  async save(item) {
    const { live, speed, ...rec } = item;
    await db.put('transfers', rec);
  }

  list() {
    return [...this.items.values()].sort((a, b) => b.addedAt - a.addedAt);
  }

  /** Neuester Eintrag für eine Datei der aktuellen Kamera. */
  find(path) {
    let best = null;
    for (const it of this.items.values()) {
      if (it.camId === this.camId && it.path === path && (!best || it.addedAt > best.addedAt)) best = it;
    }
    return best;
  }

  /**
   * file: {path, name}; size: Dateigrösse laut Kamera (falls bekannt).
   * Rückgabe: 'added' | 'duplicate' | 'exists' | 'requeued'
   */
  async add(file, size = null) {
    const id = `${this.camId}|${file.path}|${size ?? '?'}`;
    const known = this.items.get(id) || (size == null ? null : this.items.get(`${this.camId}|${file.path}|?`));
    if (known) {
      if (known.status === 'done') return 'duplicate';
      if (known.status === 'queued' || known.status === 'running') return 'exists';
      known.status = 'queued';
      known.retries = 0;
      known.retryAt = 0;
      known.error = '';
      await this.save(known);
      this.emit('change', known);
      this.kick();
      return 'requeued';
    }
    const k = fileKind(file.name);
    const item = {
      id, camId: this.camId, path: file.path, name: file.name,
      folder: file.path.split('/').slice(-2, -1)[0] || '',
      type: k.type, mime: k.mime, size, bytes: 0, chunkCount: 0,
      status: 'queued', saved: false, error: '', retries: 0, retryAt: 0,
      addedAt: Date.now(), doneAt: 0, live: 0,
    };
    this.items.set(id, item);
    await this.save(item);
    this.emit('change', item);
    this.kick();
    return 'added';
  }

  next() {
    const now = Date.now();
    const waiting = [...this.items.values()]
      .filter((i) => i.status === 'queued')
      .sort((a, b) => a.addedAt - b.addedAt);
    const ready = waiting.find((i) => !i.retryAt || i.retryAt <= now);
    if (!ready && waiting.length) {
      const soon = Math.min(...waiting.map((i) => i.retryAt));
      clearTimeout(this.timer);
      this.timer = setTimeout(() => this.kick(), Math.max(200, soon - now));
    }
    return ready;
  }

  kick() {
    if (this.active || this.halted || !this.camId) return;
    const item = this.next();
    if (!item) {
      this.emit('idle');
      return;
    }
    this.active = item;
    this.run(item).finally(() => {
      this.active = null;
      this.ctl = null;
      setTimeout(() => this.kick(), 0);
    });
  }

  async run(item) {
    item.status = 'running';
    item.error = '';
    item.live = 0;
    await this.save(item);
    this.emit('change', item);
    const ctl = new AbortController();
    this.ctl = ctl;

    let offset = item.bytes || 0;
    if (offset > 0) {
      const chunks = await db.chunks(item.id);
      const sum = chunks.reduce((a, c) => a + c.size, 0);
      if (sum !== offset || chunks.length !== item.chunkCount) await this.reset(item);
      offset = item.bytes;
    }

    const parts = [];
    let partBytes = 0;
    const flush = async () => {
      if (!partBytes) return;
      const blob = new Blob(parts, { type: 'application/octet-stream' });
      await db.put('chunks', { tid: item.id, idx: item.chunkCount, blob, size: partBytes });
      item.chunkCount++;
      item.bytes += partBytes;
      parts.length = 0;
      partBytes = 0;
      item.live = 0;
      await this.save(item);
    };

    try {
      const headers = { 'X-KL-Transfer': '1', ...(offset > 0 ? { Range: `bytes=${offset}-` } : {}) };
      let res;
      try {
        res = await fetch(camUrl(item.path), { headers, signal: ctl.signal, cache: 'no-store' });
      } catch (e) {
        if (ctl.signal.aborted) throw e;
        throw new CamError(0, 'Verbindung unterbrochen', 'network');
      }
      if (res.status === 416 && item.size && offset === item.size) {
        return this.finish(item);
      }
      if (!res.ok) {
        let msg = '', code = '';
        try { const j = await res.json(); msg = j.message || ''; code = j.error || ''; } catch { /* leer */ }
        throw new CamError(res.status, msg, code);
      }
      if (offset > 0 && res.status === 200) {
        // Server hat die Fortsetzung nicht angenommen: sauber von vorne.
        await this.reset(item);
        offset = 0;
      }
      let total = null;
      const cr = res.headers.get('Content-Range');
      if (cr && /\/(\d+)$/.test(cr)) total = Number(/\/(\d+)$/.exec(cr)[1]);
      else if (res.headers.get('Content-Length')) total = offset + Number(res.headers.get('Content-Length'));
      if (total != null) item.size = total;

      const reader = res.body.getReader();
      let lastEmit = 0, winStart = performance.now(), winBytes = 0;
      for (;;) {
        let chunk;
        try {
          chunk = await reader.read();
        } catch (e) {
          if (ctl.signal.aborted) throw e;
          throw new CamError(0, 'Verbindung unterbrochen', 'network');
        }
        if (chunk.done) break;
        parts.push(chunk.value);
        partBytes += chunk.value.byteLength;
        winBytes += chunk.value.byteLength;
        item.live = partBytes;
        if (partBytes >= CHUNK) await flush();
        const now = performance.now();
        if (now - lastEmit > 200) {
          lastEmit = now;
          const dt = (now - winStart) / 1000;
          if (dt > 1) {
            item.speed = winBytes / dt;
            winStart = now;
            winBytes = 0;
          }
          this.emit('progress', item);
        }
      }
      await flush();
      if (item.size != null && item.bytes !== item.size) {
        throw new CamError(0, `Unvollständig (${item.bytes} von ${item.size} Bytes)`, 'network');
      }
      return this.finish(item);
    } catch (e) {
      const why = ctl.signal.reason;
      if (why === 'pause' || why === 'halt') {
        try { await flush(); } catch { /* Teilstück verwerfen */ }
        item.status = why === 'halt' ? 'queued' : 'paused';
        item.live = 0;
        await this.save(item);
        this.emit('change', item);
        return;
      }
      if (why === 'cancel') {
        await this.reset(item);
        item.status = 'cancelled';
        await this.save(item);
        this.emit('change', item);
        return;
      }
      if (e?.name === 'QuotaExceededError') {
        item.status = 'error';
        item.error = 'Speicher voll – Fertiges sichern und aus der App entfernen.';
        this.halted = true;
        await this.save(item);
        this.emit('change', item);
        this.emit('halted');
        return;
      }
      try { await flush(); } catch { /* ignorieren */ }
      const status = e?.status ?? 0;
      const transient = status === 0 || status === 502 || status === 503 || status === 504;
      if (transient && item.retries < MAX_AUTO_RETRY) {
        item.retryAt = Date.now() + BACKOFF[item.retries];
        item.retries++;
        item.status = 'queued';
        item.error = `Neuer Versuch ${item.retries}/${MAX_AUTO_RETRY} …`;
      } else {
        item.status = 'error';
        item.error = status === 404 ? 'Datei nicht mehr auf der Kamera.' : (e?.message || 'Fehler');
      }
      item.live = 0;
      await this.save(item);
      this.emit('change', item);
    }
  }

  async finish(item) {
    item.status = 'done';
    item.doneAt = Date.now();
    item.error = '';
    item.live = 0;
    item.retries = 0;
    await this.save(item);
    this.emit('change', item);
    this.emit('done', item);
  }

  async reset(item) {
    await db.deleteChunks(item.id);
    item.bytes = 0;
    item.chunkCount = 0;
    item.live = 0;
  }

  // ---- Bedienung -------------------------------------------------------
  async pause(id) {
    const it = this.items.get(id);
    if (!it) return;
    if (this.active === it) this.ctl?.abort('pause');
    else if (it.status === 'queued') {
      it.status = 'paused';
      await this.save(it);
      this.emit('change', it);
    }
  }

  async resume(id) {
    const it = this.items.get(id);
    if (!it || !['paused', 'error', 'cancelled'].includes(it.status)) return;
    it.status = 'queued';
    it.retries = 0;
    it.retryAt = 0;
    it.error = '';
    await this.save(it);
    this.emit('change', it);
    this.kick();
  }

  async cancel(id) {
    const it = this.items.get(id);
    if (!it) return;
    if (this.active === it) return this.ctl?.abort('cancel');
    await this.reset(it);
    it.status = 'cancelled';
    await this.save(it);
    this.emit('change', it);
  }

  /** Entfernt nur die Kopie in der App – nie etwas auf der Kamera. */
  async remove(id) {
    const it = this.items.get(id);
    if (!it) return;
    if (this.active === it) {
      this.ctl?.abort('cancel');
      while (this.active === it) await sleep(50);
    }
    await db.deleteChunks(id);
    await db.del('transfers', id);
    this.items.delete(id);
    this.emit('change', { ...it, removed: true });
  }

  haltAll() {
    this.halted = true;
    if (this.active) this.ctl?.abort('halt');
    this.emit('halted');
  }

  resumeAll() {
    this.halted = false;
    for (const it of this.items.values()) {
      if (it.status === 'paused') { it.status = 'queued'; this.save(it); this.emit('change', it); }
    }
    this.kick();
  }

  async markSaved(ids) {
    for (const id of ids) {
      const it = this.items.get(id);
      if (!it) continue;
      it.saved = true;
      it.savedAt = Date.now();
      await this.save(it);
      this.emit('change', it);
    }
  }

  async blob(id) {
    const it = this.items.get(id);
    const chunks = await db.chunks(id);
    chunks.sort((a, b) => a.idx - b.idx);
    return new Blob(chunks.map((c) => c.blob), { type: it?.mime || 'application/octet-stream' });
  }

  stats() {
    let total = 0, done = 0, bytes = 0, size = 0, open = 0;
    for (const it of this.items.values()) {
      if (it.camId !== this.camId && it.status !== 'done') continue;
      total++;
      if (it.status === 'done') done++;
      if (['queued', 'running', 'paused'].includes(it.status)) {
        open++;
        size += it.size || 0;
        bytes += (it.bytes || 0) + (it.live || 0);
      }
    }
    return { total, done, open, bytes, size };
  }
}
