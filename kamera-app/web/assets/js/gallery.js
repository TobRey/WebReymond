// Galerie: schnelles Raster/Liste mit nachgeladenen Vorschaubildern der Kamera.

import { camUrl } from './api.js';
import { db } from './db.js';
import { fileKind, fmtBytes, fmtDate, h, icon, parseCamDate } from './util.js';

/** Begrenzte Parallelität: die Kamera ist ein kleiner, langsamer Webserver. */
class Loader {
  constructor(limit) {
    this.limit = limit;
    this.running = 0;
    this.wait = [];
    this.keys = new Set();
  }
  push(key, job, front = false) {
    if (this.keys.has(key)) return;
    this.keys.add(key);
    front ? this.wait.unshift({ key, job }) : this.wait.push({ key, job });
    this.pump();
  }
  drop(key) {
    const i = this.wait.findIndex((w) => w.key === key);
    if (i >= 0) { this.wait.splice(i, 1); this.keys.delete(key); }
  }
  clear() { this.wait = []; this.keys.clear(); }
  pump() {
    while (this.running < this.limit() && this.wait.length) {
      const { key, job } = this.wait.shift();
      this.running++;
      Promise.resolve().then(job).catch(() => {}).finally(() => {
        this.running--;
        this.keys.delete(key);
        this.pump();
      });
    }
  }
}

/** Neueste zuerst: Ordner absteigend, Dateinummer absteigend (JPEG vor RAW derselben Aufnahme). */
const fileNo = (f) => Number((/(\d+)\.[^.]+$/.exec(f.name) || [])[1] || 0);
const typeRank = { jpeg: 0, heif: 0, raw: 1, video: 2, other: 3 };
export function newestFirst(a, b) {
  if (a.folder !== b.folder) return b.folder.localeCompare(a.folder);
  return fileNo(b) - fileNo(a) || typeRank[a.type] - typeRank[b.type] || a.name.localeCompare(b.name);
}

export function toFile(path, idx = 0) {
  const parts = path.split('/');
  const name = parts.pop();
  const k = fileKind(name);
  return { path, name, folder: parts.pop() || '', idx, ...k };
}

export class Gallery {
  constructor({ root, ccapi, queue, onOpen, onSelect }) {
    this.root = root;
    this.ccapi = ccapi;
    this.queue = queue;
    this.onOpen = onOpen;
    this.onSelect = onSelect;
    this.files = [];
    this.filter = 'all';
    this.view = 'grid';
    this.selecting = false;
    this.selected = new Set();
    this.infos = new Map();
    this.tiles = new Map();
    this.thumbs = new Loader(() => (queue.active ? 2 : 4));
    this.infoLoader = new Loader(() => 2);
    this.io = new IntersectionObserver((entries) => this.onIntersect(entries), { rootMargin: '600px 0px' });
  }

  setCcapi(c) { this.ccapi = c; }

  /** Pfade in Kamerareihenfolge -> neueste zuerst (Ordner absteigend, Datei absteigend). */
  setPaths(paths) {
    const files = paths.map((p, i) => toFile(p, i));
    files.sort(newestFirst);
    this.files = files;
    this.selected = new Set([...this.selected].filter((p) => files.some((f) => f.path === p)));
    this.render();
  }

  addNew(paths) {
    const known = new Set(this.files.map((f) => f.path));
    const fresh = paths.filter((p) => !known.has(p)).map((p) => toFile(p));
    if (!fresh.length) return [];
    this.files = [...fresh, ...this.files].sort(newestFirst);
    this.render();
    return fresh;
  }

  visible() {
    return this.filter === 'all' ? this.files : this.files.filter((f) => f.type === this.filter || (this.filter === 'jpeg' && f.type === 'heif'));
  }

  counts() {
    const c = { all: this.files.length, jpeg: 0, raw: 0, video: 0 };
    for (const f of this.files) {
      if (f.type === 'jpeg' || f.type === 'heif') c.jpeg++;
      else if (f.type in c) c[f.type]++;
    }
    return c;
  }

  render() {
    this.io.disconnect();
    this.thumbs.clear();
    this.infoLoader.clear();
    this.tiles.clear();
    const list = this.visible();
    this.root.className = `grid ${this.view === 'list' ? 'is-list' : ''} ${this.selecting ? 'is-selecting' : ''}`;
    const frag = document.createDocumentFragment();
    for (const f of list) frag.append(this.tile(f));
    this.root.replaceChildren(frag);
  }

  tile(f) {
    const info = this.infos.get(f.path);
    const el = h('button', {
      class: `tile t-${f.type}`, type: 'button', dataset: { path: f.path },
      'aria-label': `${f.name}, ${f.label}`,
    },
    h('span', { class: 'thumb' }, h('img', { alt: '', decoding: 'async', draggable: 'false' })),
    h('span', { class: `badge b-${f.type}` }, f.type === 'jpeg' ? 'JPG' : f.label === 'Video' ? f.ext : f.label),
    h('span', { class: 'meta' },
      h('b', {}, f.name),
      h('small', {}, info ? `${fmtDate(info.date)} · ${fmtBytes(info.size)}` : f.folder)),
    h('span', { class: 'state', html: '' }),
    h('span', { class: 'check', html: icon('check') }));
    if (f.type === 'video') el.querySelector('.thumb').insertAdjacentHTML('beforeend', `<span class="play">${icon('play')}</span>`);
    this.paintState(el, f.path);
    if (this.selected.has(f.path)) el.classList.add('sel');
    this.bindPress(el, f);
    this.tiles.set(f.path, el);
    this.io.observe(el);
    return el;
  }

  bindPress(el, f) {
    let timer = null, long = false, sx = 0, sy = 0;
    el.addEventListener('pointerdown', (e) => {
      long = false; sx = e.clientX; sy = e.clientY;
      timer = setTimeout(() => {
        long = true;
        if (!this.selecting) this.setSelecting(true);
        this.toggle(f.path);
        navigator.vibrate?.(10);
      }, 450);
    });
    const stop = () => clearTimeout(timer);
    el.addEventListener('pointermove', (e) => { if (Math.hypot(e.clientX - sx, e.clientY - sy) > 10) stop(); });
    el.addEventListener('pointerup', stop);
    el.addEventListener('pointercancel', stop);
    el.addEventListener('contextmenu', (e) => e.preventDefault());
    el.addEventListener('click', () => {
      if (long) return;
      if (this.selecting) this.toggle(f.path);
      else this.onOpen(f, this.visible());
    });
  }

  onIntersect(entries) {
    for (const en of entries) {
      const el = en.target;
      const path = el.dataset.path;
      if (en.isIntersecting) {
        const img = el.querySelector('img');
        if (!img.src) this.thumbs.push(path, () => this.loadThumb(el, img, path));
        if (this.view === 'list' && !this.infos.has(path)) this.infoLoader.push(path, () => this.loadInfo(path));
      } else {
        this.thumbs.drop(path);
        this.infoLoader.drop(path);
      }
    }
  }

  loadThumb(el, img, path) {
    return new Promise((resolve) => {
      img.onload = () => { el.classList.add('ok'); resolve(); };
      img.onerror = () => { el.classList.add('noimg'); resolve(); };
      img.src = camUrl(path, 'thumbnail');
    });
  }

  infoKey(path) { return `${this.queue.camId}|${path}`; }

  /** Grösse und Datum (Kamera-Info, in IndexedDB zwischengespeichert). */
  async loadInfo(path, force = false) {
    if (!force && this.infos.has(path)) return this.infos.get(path);
    let rec = force ? null : await db.get('info', this.infoKey(path)).catch(() => null);
    if (!rec) {
      const raw = await this.ccapi.info(path);
      rec = { key: this.infoKey(path), size: Number(raw.filesize) || null, dateText: raw.lastmodifieddate || '', raw };
      db.put('info', rec).catch(() => {});
    }
    const info = { size: rec.size, date: parseCamDate(rec.dateText), dateText: rec.dateText };
    this.infos.set(path, info);
    const el = this.tiles.get(path);
    if (el) el.querySelector('.meta small').textContent = `${info.date ? fmtDate(info.date) : rec.dateText || '–'} · ${fmtBytes(info.size)}`;
    return info;
  }

  paintState(el, path) {
    const it = this.queue.find(path);
    const st = !it ? '' : it.status === 'done' ? (it.saved ? 'saved' : 'done') : it.status;
    el.dataset.state = st;
    const s = el.querySelector('.state');
    s.innerHTML = st === 'saved' ? icon('check2') : st === 'done' ? icon('check') : st === 'running' || st === 'queued' ? icon('down') : st === 'error' ? icon('alert') : '';
  }

  refreshState(path) {
    const el = this.tiles.get(path);
    if (el) this.paintState(el, path);
  }

  setFilter(f) { this.filter = f; this.render(); }
  setView(v) { this.view = v; this.render(); }

  setSelecting(on) {
    this.selecting = on;
    if (!on) {
      this.selected.clear();
      for (const el of this.tiles.values()) el.classList.remove('sel');
    }
    this.root.classList.toggle('is-selecting', on);
    this.onSelect?.(this.selected, on);
  }

  toggle(path) {
    const el = this.tiles.get(path);
    if (this.selected.has(path)) { this.selected.delete(path); el?.classList.remove('sel'); }
    else { this.selected.add(path); el?.classList.add('sel'); }
    this.onSelect?.(this.selected, this.selecting);
  }

  selectAll() {
    const vis = this.visible();
    const all = vis.every((f) => this.selected.has(f.path));
    for (const f of vis) {
      if (all) this.selected.delete(f.path); else this.selected.add(f.path);
      this.tiles.get(f.path)?.classList.toggle('sel', !all);
    }
    this.onSelect?.(this.selected, this.selecting);
  }
}
