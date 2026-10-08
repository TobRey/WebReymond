// Live-Modus: (1) neue Aufnahmen erkennen und übertragen, (2) optional Kamera-Livebild.
// Beides ist bewusst getrennt: Das Livebild ist nur eine Sucher-Vorschau und wird nie gespeichert.

import { bridge, camUrl, normPath } from './api.js';
import { $, fmtBytes, fmtTime, h, icon, keepAwake, reduceMotion, sleep, toast } from './util.js';

export class Live {
  constructor({ root, app }) {
    this.root = root;
    this.app = app; // { ccapi, gallery, queue, openViewer, enqueue, features }
    this.capturing = false;
    this.captures = [];
    this.lv = false;
    this.lvToken = 0;
    this.lastUrl = null;
    this.hidden = false;
    this.settings = app.settings;
    this.bind();
    this.renderCaptures();
    document.addEventListener('visibilitychange', () => (this.hidden = document.visibilityState === 'hidden'));
    app.queue.addEventListener('change', (e) => {
      if (this.captures.some((c) => c.file.path === e.detail.path)) this.renderCaptures();
    });
    app.queue.addEventListener('progress', (e) => {
      const c = this.captures[0];
      if (c && c.file.path === e.detail.path) this.paintHeroProgress(e.detail);
    });
    if (!reduceMotion.matches) this.parallax();
  }

  bind() {
    $('#cap-toggle').addEventListener('change', (e) => (e.target.checked ? this.startCaptures() : this.stopCaptures()));
    $('#lv-toggle').addEventListener('change', (e) => (e.target.checked ? this.startLiveview() : this.stopLiveview()));
    const auto = $('#auto-toggle');
    auto.checked = !!this.settings.get('autoTransfer');
    auto.addEventListener('change', () => this.settings.set('autoTransfer', auto.checked));
    for (const b of this.root.querySelectorAll('[data-autokind]')) {
      b.classList.toggle('on', b.dataset.autokind === (this.settings.get('autoKind') || 'jpeg'));
      b.addEventListener('click', () => {
        this.settings.set('autoKind', b.dataset.autokind);
        for (const o of this.root.querySelectorAll('[data-autokind]')) o.classList.toggle('on', o === b);
      });
    }
  }

  setCapStatus(text, kind = '') {
    const el = $('#cap-status');
    el.textContent = text;
    el.className = `status-line ${kind}`;
  }

  // ---- Neue Aufnahmen ---------------------------------------------------
  async startCaptures() {
    if (this.capturing) return;
    const { ccapi, features } = this.app;
    if (!ccapi) { $('#cap-toggle').checked = false; return toast('Kamera nicht verbunden', 'err'); }
    this.capturing = true;
    $('#cap-card').classList.add('active');
    keepAwake(true);
    const usePolling = !!features().polling;
    this.setCapStatus('Wird vorbereitet …');
    let fails = 0;
    let known = null;
    let lastScan = 0;
    // Sicherheitsnetz zusätzlich zum Event-Polling: letzte Seite des neuesten Ordners abgleichen
    const scan = async () => {
      lastScan = Date.now();
      const paths = await this.newestFolder();
      const added = known ? paths.filter((p) => !known.has(p)) : [];
      known = new Set([...(known || []), ...paths]);
      return added;
    };
    if (usePolling) {
      try { await ccapi.poll(); } catch { /* erste Abfrage liefert nur den Ausgangszustand */ }
    }
    try { await scan(); } catch { /* wird in der Schleife wiederholt */ }
    if (this.capturing) this.setCapStatus(usePolling ? 'Wartet auf neue Aufnahmen …' : 'Prüft den Kartenordner regelmässig …', 'ok');
    while (this.capturing) {
      try {
        let added = [];
        if (usePolling) {
          const ev = await this.app.ccapi.poll();
          added = (ev.addedcontents || []).map(normPath);
          for (const p of added) known?.add(p);
        }
        if (!usePolling || Date.now() - lastScan > 10000) added = [...new Set([...added, ...(await scan())])];
        if (added.length) this.handle(added);
        if (fails) this.setCapStatus('Wartet auf neue Aufnahmen …', 'ok');
        fails = 0;
      } catch {
        fails++;
        this.setCapStatus(`Verbindung gestört – neuer Versuch (${fails}) …`, 'warn');
        await sleep(Math.min(8000, 1000 * fails));
      }
      await sleep(this.hidden ? 4000 : usePolling ? 1500 : 4000);
    }
  }

  /** Dateien der letzten Listenseite im neuesten Ordner (neue Aufnahmen landen dort). */
  async newestFolder() {
    const c = this.app.ccapi;
    const storages = (await c.json(c.contentsRoot)).path || [];
    const dirs = [];
    for (const s of storages) dirs.push(...((await c.json(normPath(s))).path || []).map(normPath));
    dirs.sort((a, b) => a.split('/').pop().localeCompare(b.split('/').pop()));
    const last = dirs.pop();
    if (!last) return [];
    try {
      const n = await c.json(last, 'kind=number');
      const pages = Number(n.pagenumber) || 1;
      const files = [...(await c.page(last, pages))];
      if (pages > 1) files.push(...(await c.page(last, pages - 1)));
      return files.map(normPath);
    } catch {
      return (await c.listDir(last)).map(normPath);
    }
  }

  stopCaptures() {
    if (!this.capturing) return;
    this.capturing = false;
    keepAwake(false);
    $('#cap-card').classList.remove('active');
    this.setCapStatus('Aus');
  }

  handle(paths) {
    const { gallery, queue } = this.app;
    const fresh = gallery.addNew(paths);
    const list = fresh.length ? fresh : paths.map((p) => gallery.files.find((f) => f.path === p)).filter(Boolean);
    const now = new Date();
    // Neueste zuerst; bei RAW+JPEG derselben Aufnahme steht das JPEG vorne (gross angezeigt)
    const base = (f) => f.name.replace(/\.[^.]+$/, '');
    const rank = (f) => (f.type === 'jpeg' || f.type === 'heif' ? 0 : f.type === 'raw' ? 1 : 2);
    list.sort((a, b) => (base(a) === base(b) ? rank(a) - rank(b) : b.name.localeCompare(a.name, 'en', { numeric: true })));
    for (const f of [...list].reverse()) {
      if (!this.captures.some((c) => c.file.path === f.path)) this.captures.unshift({ file: f, at: now });
    }
    this.captures = this.captures.slice(0, 60);
    navigator.vibrate?.(15);
    if (this.settings.get('autoTransfer')) {
      const kind = this.settings.get('autoKind') || 'jpeg';
      const want = list.filter((f) => kind === 'all' || f.type === 'jpeg' || f.type === 'heif' || (kind === 'jpeg+raw' && f.type === 'raw'));
      if (want.length) this.app.enqueue(want, { quiet: true });
    }
    this.renderCaptures();
    const q = queue.stats();
    this.setCapStatus(`${list.length} neue Aufnahme${list.length === 1 ? '' : 'n'} · ${fmtTime(now)}${q.open ? ` · ${q.open} in Übertragung` : ''}`, 'ok');
  }

  renderCaptures() {
    const hero = $('#cap-hero');
    const strip = $('#cap-older');
    if (!this.captures.length) {
      hero.replaceChildren(h('div', { class: 'empty', html: `${icon('aperture', 'big')}<p>Neue Fotos erscheinen hier, sobald du auslöst.</p>` }));
      strip.replaceChildren();
      $('#cap-older-title').hidden = true;
      return;
    }
    // Neueste gross – bevorzugt bereits übertragene Originale
    const [first, ...older] = this.captures;
    const { queue } = this.app;
    const it = queue.find(first.file.path);
    const done = it?.status === 'done';
    const card = h('button', { class: `hero t-${first.file.type}`, type: 'button', onclick: () => this.app.openViewer(first.file, this.captures.map((c) => c.file)) },
      h('span', { class: 'hero-img' }, h('img', { alt: first.file.name, decoding: 'async' })),
      h('span', { class: 'hero-tags' },
        h('span', { class: 'tag new' }, `Neu · ${fmtTime(first.at)}`),
        h('span', { class: `tag ${done ? 'ok' : ''}` }, done ? 'Original übertragen' : it ? 'Wird übertragen …' : 'Vorschau von der Kamera')),
      h('span', { class: 'hero-name' }, first.file.name, h('small', {}, first.file.label)),
      h('span', { class: 'hero-bar' }, h('i')));
    const img = card.querySelector('img');
    if (done && first.file.type === 'jpeg') {
      queue.blob(it.id).then((b) => {
        if (this.heroUrl) URL.revokeObjectURL(this.heroUrl);
        this.heroUrl = URL.createObjectURL(b);
        img.src = this.heroUrl;
      });
    } else {
      img.src = camUrl(first.file.path, first.file.type === 'video' ? 'thumbnail' : 'display');
      img.onerror = () => (img.src = camUrl(first.file.path, 'thumbnail'));
    }
    hero.replaceChildren(card);
    if (it && !done) this.paintHeroProgress(it);
    $('#cap-older-title').hidden = !older.length;
    strip.replaceChildren(...older.map((c) => {
      const st = queue.find(c.file.path);
      return h('button', { class: `mini t-${c.file.type}`, type: 'button', dataset: { state: st?.status || '' }, onclick: () => this.app.openViewer(c.file, this.captures.map((x) => x.file)) },
        h('img', { alt: '', src: camUrl(c.file.path, 'thumbnail'), loading: 'lazy' }),
        h('span', { class: `badge b-${c.file.type}` }, c.file.type === 'jpeg' ? 'JPG' : c.file.label),
        h('small', {}, fmtTime(c.at)));
    }));
  }

  paintHeroProgress(it) {
    const bar = document.querySelector('#cap-hero .hero-bar i');
    if (bar && it.size) bar.style.width = `${Math.min(100, ((it.bytes + (it.live || 0)) / it.size) * 100)}%`;
    const tag = document.querySelector('#cap-hero .hero-tags .tag:nth-child(2)');
    if (tag && it.status === 'running' && it.size) tag.textContent = `Überträgt … ${fmtBytes(it.bytes + (it.live || 0))} / ${fmtBytes(it.size)}`;
  }

  parallax() {
    let ticking = false;
    window.addEventListener('scroll', () => {
      if (ticking || this.app.currentView() !== 'live') return;
      ticking = true;
      requestAnimationFrame(() => {
        const img = document.querySelector('#cap-hero .hero-img img');
        if (img) img.style.transform = `translate3d(0, ${Math.min(60, window.scrollY * 0.12)}px, 0) scale(1.06)`;
        ticking = false;
      });
    }, { passive: true });
  }

  // ---- Livebild ---------------------------------------------------------
  async startLiveview() {
    const { ccapi, features } = this.app;
    const box = $('#lv-box');
    if (!ccapi || !features().flip || !features().liveview) {
      $('#lv-toggle').checked = false;
      return toast('Diese Kamera bietet über CCAPI kein Livebild an.', 'err');
    }
    const token = ++this.lvToken;
    this.lv = true;
    box.hidden = false;
    box.classList.add('loading');
    $('#lv-card').classList.add('active');
    $('#lv-fps').textContent = 'startet …';
    try {
      try { await bridge.liveview('medium'); }
      catch (e) { if (e.status === 400) await bridge.liveview('small'); else throw e; }
    } catch (e) {
      this.stopLiveview(false);
      return toast(`Livebild nicht möglich: ${e.message}`, 'err');
    }
    keepAwake(true);
    const img = $('#lv-img');
    let frames = 0, t0 = performance.now(), fails = 0;
    while (this.lv && token === this.lvToken) {
      if (this.hidden || this.app.currentView() !== 'live') { await sleep(500); continue; }
      try {
        const blob = await ccapi.liveFrame();
        const url = URL.createObjectURL(blob);
        img.src = url;
        await img.decode().catch(() => {});
        if (this.lastUrl) URL.revokeObjectURL(this.lastUrl);
        this.lastUrl = url;
        box.classList.remove('loading');
        frames++;
        fails = 0;
        const dt = performance.now() - t0;
        if (dt > 1000) {
          $('#lv-fps').textContent = `${(frames * 1000 / dt).toFixed(1)} Bilder/s`;
          frames = 0; t0 = performance.now();
        }
      } catch (e) {
        fails++;
        $('#lv-fps').textContent = e.status === 503 ? 'Kamera beschäftigt …' : 'Verbindung …';
        if (fails > 20) { toast('Livebild abgebrochen', 'err'); this.stopLiveview(); break; }
        await sleep(300 * Math.min(fails, 5));
      }
    }
  }

  async stopLiveview(send = true) {
    const was = this.lv;
    this.lv = false;
    this.lvToken++;
    $('#lv-toggle').checked = false;
    $('#lv-card').classList.remove('active');
    $('#lv-box').hidden = true;
    if (was) keepAwake(false);
    if (send) { try { await bridge.liveview('off'); } catch { /* Kamera evtl. schon weg */ } }
  }
}
