// Zugriff auf Brücke und Kamera.
// Alle Pfade sind relativ: Die App läuft unter der Adresse der Brücke (oder der
// nativen Hülle) und ruft dieselbe Adresse auf – dadurch gibt es kein CORS-Problem.

import { fetchT } from './util.js';

export class CamError extends Error {
  constructor(status, message, code = '') {
    super(message || `HTTP ${status}`);
    this.status = status;
    this.code = code;
  }
}

async function readError(res) {
  let msg = '', code = '';
  try {
    const j = await res.json();
    msg = j.message || '';
    code = j.error || '';
  } catch { /* kein JSON */ }
  return new CamError(res.status, msg, code);
}

/** '/ccapi/…' aus einem Pfad oder einer vollen URL der Kamera. */
export function normPath(p) {
  const s = String(p || '');
  const i = s.indexOf('/ccapi');
  return i >= 0 ? s.slice(i) : s;
}

export function camUrl(path, kind) {
  const p = normPath(path).split('/').map(encodeURIComponent).join('/');
  return `cam${p}${kind ? `?kind=${kind}` : ''}`;
}

// ---- Brücke ---------------------------------------------------------------
export const bridge = {
  async status() {
    const res = await fetchT('bridge/status', { cache: 'no-store' }, 6000);
    if (!res.ok) throw await readError(res);
    return res.json();
  },
  async setCamera(address) {
    const res = await fetchT(`bridge/camera?address=${encodeURIComponent(address)}`, { method: 'POST' }, 10000);
    if (!res.ok) throw await readError(res);
    return res.json();
  },
  async discover(port) {
    const q = port ? `?port=${encodeURIComponent(port)}` : '';
    const res = await fetchT(`bridge/discover${q}`, { method: 'POST' }, 60000);
    if (!res.ok) throw await readError(res);
    return res.json();
  },
  async liveview(size) {
    const res = await fetchT(`bridge/liveview?size=${size}`, { method: 'POST' }, 10000);
    if (!res.ok) throw await readError(res);
    return res.json();
  },
};

// ---- CCAPI ----------------------------------------------------------------
export class Ccapi {
  /** features: Pfade aus dem Manifest der Kamera (von der Brücke ermittelt) */
  constructor(features = {}) {
    this.f = features;
    this.listVariant = null; // 'page' | 'kindlist' | 'plain'
  }

  async json(path, query = '', ms = 15000) {
    const url = camUrl(path) + (query ? `?${query}` : '');
    let res;
    try {
      res = await fetchT(url, { cache: 'no-store' }, ms);
    } catch {
      throw new CamError(0, 'Keine Verbindung zur Brücke', 'network');
    }
    if (!res.ok) throw await readError(res);
    return res.json();
  }

  get contentsRoot() {
    return this.f.contents || '/ccapi/ver100/contents';
  }

  /** Alle Dateien aller Speicherkarten. onProgress(anzahlBisher) */
  async listAll(onProgress) {
    const out = [];
    const storages = (await this.json(this.contentsRoot)).path || [];
    for (const storage of storages) {
      const dirs = (await this.json(normPath(storage))).path || [];
      for (const dir of dirs) {
        const files = await this.listDir(normPath(dir));
        for (const p of files) out.push(normPath(p));
        onProgress?.(out.length);
      }
    }
    return out;
  }

  async listDir(dir) {
    let pages = 1;
    try {
      const n = await this.json(dir, 'kind=number');
      pages = Number(n.pagenumber) || Math.max(1, Math.ceil((Number(n.contentsnumber) || 0) / 100));
      if (!Number(n.contentsnumber) && n.contentsnumber !== undefined) return [];
    } catch (e) {
      if (e.status !== 400 && e.status !== 404) throw e;
      this.listVariant = 'plain';
    }
    if (this.listVariant === 'plain') return (await this.json(dir)).path || [];
    const files = [];
    for (let p = 1; p <= pages; p++) files.push(...(await this.page(dir, p)));
    return files;
  }

  async page(dir, p) {
    // Belegte Varianten in öffentlichen Clients: ?page=N bzw. ?kind=list&page=N
    const variants = this.listVariant ? [this.listVariant] : ['page', 'kindlist'];
    let lastErr;
    for (const v of variants) {
      try {
        const q = v === 'page' ? `page=${p}` : `kind=list&page=${p}`;
        const res = await this.json(dir, q);
        this.listVariant = v;
        return res.path || [];
      } catch (e) {
        lastErr = e;
        if (e.status !== 400) throw e;
      }
    }
    throw lastErr;
  }

  info(path) {
    return this.json(path, 'kind=info');
  }

  /** Ereignisse seit der letzten Abfrage (u. a. 'addedcontents'). */
  poll() {
    if (!this.f.polling) throw new CamError(501, 'Event-Polling nicht verfügbar');
    return this.json(this.f.polling, 'continue=off', 10000);
  }

  async liveFrame(signal) {
    const res = await fetchT(camUrl(this.f.flip), { cache: 'no-store', signal }, 8000);
    if (!res.ok) throw await readError(res);
    return res.blob();
  }

  async battery() {
    if (!this.f.battery) return null;
    try { return await this.json(this.f.battery, '', 5000); } catch { return null; }
  }
}
