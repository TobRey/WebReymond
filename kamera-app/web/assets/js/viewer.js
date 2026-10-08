// Vollbild mit Pinch-/Doppeltipp-Zoom, Wischen zum Blättern und nach unten zum Schliessen.

import { camUrl } from './api.js';
import { $, fmtBytes, fmtDate, icon, reduceMotion } from './util.js';

export class Viewer {
  constructor({ el, gallery, queue, onEnqueue, onSave, saveMode }) {
    this.el = el;
    this.gallery = gallery;
    this.queue = queue;
    this.onEnqueue = onEnqueue;
    this.onSave = onSave;
    this.saveMode = saveMode;
    this.list = [];
    this.index = 0;
    this.stage = $('.v-stage', el);
    this.img = $('.v-img', el);
    this.video = $('.v-video', el);
    this.objectUrl = null;
    this.reset();
    this.bindGestures();
    $('.v-close', el).addEventListener('click', () => this.close());
    $('.v-prev', el).addEventListener('click', () => this.go(-1));
    $('.v-next', el).addEventListener('click', () => this.go(1));
    document.addEventListener('keydown', (e) => {
      if (this.el.hidden) return;
      if (e.key === 'Escape') this.close();
      if (e.key === 'ArrowLeft') this.go(-1);
      if (e.key === 'ArrowRight') this.go(1);
    });
    queue.addEventListener('change', (e) => { if (this.current?.path === e.detail.path) this.renderMeta(); });
    queue.addEventListener('progress', (e) => { if (this.current?.path === e.detail.path) this.renderMeta(); });
  }

  get current() { return this.list[this.index]; }

  open(file, list) {
    this.list = list;
    this.index = Math.max(0, list.findIndex((f) => f.path === file.path));
    this.el.hidden = false;
    document.documentElement.classList.add('no-scroll');
    requestAnimationFrame(() => this.el.classList.add('open'));
    this.show();
  }

  close() {
    this.el.classList.remove('open');
    document.documentElement.classList.remove('no-scroll');
    this.video.pause();
    const done = () => {
      this.el.hidden = true;
      this.video.removeAttribute('src');
      this.video.load();
      this.img.removeAttribute('src');
      this.freeUrl();
    };
    reduceMotion.matches ? done() : setTimeout(done, 220);
  }

  go(d) {
    const n = this.index + d;
    if (n < 0 || n >= this.list.length) return;
    this.index = n;
    this.show();
  }

  freeUrl() {
    if (this.objectUrl) URL.revokeObjectURL(this.objectUrl);
    this.objectUrl = null;
  }

  async show() {
    const f = this.current;
    if (!f) return;
    this.reset();
    this.freeUrl();
    this.el.dataset.type = f.type;
    $('.v-title', this.el).textContent = f.name;
    $('.v-count', this.el).textContent = `${this.index + 1} / ${this.list.length}`;
    $('.v-prev', this.el).disabled = this.index === 0;
    $('.v-next', this.el).disabled = this.index === this.list.length - 1;
    this.stage.classList.add('loading');
    const it = this.queue.find(f.path);
    const local = it?.status === 'done';
    if (f.type === 'video') {
      this.img.hidden = true;
      this.video.hidden = false;
      this.video.poster = camUrl(f.path, 'thumbnail');
      if (local) {
        this.objectUrl = URL.createObjectURL(await this.queue.blob(it.id));
        this.video.src = this.objectUrl;
      } else {
        this.video.src = camUrl(f.path); // Stream über die Brücke (Range wird unterstützt)
      }
      this.stage.classList.remove('loading');
    } else {
      this.video.hidden = true;
      this.video.removeAttribute('src');
      this.img.hidden = false;
      this.img.classList.remove('ok');
      this.img.dataset.res = '';
      if (local && f.type === 'jpeg') {
        this.objectUrl = URL.createObjectURL(await this.queue.blob(it.id));
        this.setImg(this.objectUrl, 'original');
      } else {
        this.setImg(camUrl(f.path, 'display'), 'display', () => this.setImg(camUrl(f.path, 'thumbnail'), 'thumbnail'));
      }
    }
    this.renderMeta();
    this.gallery.loadInfo(f.path).then(() => this.renderMeta()).catch(() => {});
    // Nachbarn vorladen (nur Vorschaugrösse)
    for (const d of [1, -1]) {
      const n = this.list[this.index + d];
      if (n && n.type !== 'video') { const pre = new Image(); pre.src = camUrl(n.path, 'display'); }
    }
  }

  setImg(src, res, onFail) {
    const f = this.current;
    this.img.onload = () => {
      if (this.current !== f) return;
      this.img.classList.add('ok');
      this.stage.classList.remove('loading');
      this.img.dataset.res = res;
      this.renderMeta();
    };
    this.img.onerror = () => {
      if (this.current !== f) return;
      if (onFail) onFail();
      else { this.stage.classList.remove('loading'); this.stage.classList.add('failed'); }
    };
    this.img.src = src;
  }

  renderMeta() {
    const f = this.current;
    if (!f) return;
    const info = this.gallery.infos.get(f.path);
    const it = this.queue.find(f.path);
    const pct = it?.size ? Math.floor(((it.bytes + (it.live || 0)) / it.size) * 100) : 0;
    const res = this.img.dataset.res;
    const resText = f.type === 'video' ? '' : res === 'original' ? 'Originalauflösung' : res === 'display' ? 'Vorschau' : res === 'thumbnail' ? 'Mini-Vorschau' : '';
    $('.v-meta', this.el).innerHTML = `
      <span class="chip b-${f.type}">${f.type === 'jpeg' ? 'JPEG' : f.label} · ${f.ext}</span>
      <span class="chip">${info ? fmtBytes(info.size) : '…'}</span>
      <span class="chip">${info ? (info.date ? fmtDate(info.date) : info.dateText || '–') : '…'}</span>
      ${resText ? `<span class="chip dim">${resText}</span>` : ''}`;
    const st = !it ? 'none' : it.status;
    const act = $('.v-actions', this.el);
    act.replaceChildren();
    const btn = (label, ic, cls, fn) => {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = `btn ${cls}`;
      b.innerHTML = `${icon(ic)}<span>${label}</span>`;
      b.addEventListener('click', fn);
      act.append(b);
      return b;
    };
    if (st === 'done') {
      btn(it.saved ? 'Erneut sichern' : this.saveMode.title, 'share', 'primary', () => this.onSave([it]));
    } else if (st === 'running' || st === 'queued') {
      btn(st === 'running' ? `Lädt … ${pct} %` : 'In Warteschlange', 'down', 'ghost', () => {}).disabled = true;
    } else {
      btn('Original laden', 'down', 'primary', () => this.onEnqueue([f]));
    }
    if (f.type === 'jpeg' && res !== 'original') {
      btn('Volle Auflösung', 'zoom', 'ghost', () => { this.stage.classList.add('loading'); this.setImg(camUrl(f.path), 'original'); });
    }
    if (this.saveMode.mode !== 'native') {
      const a = document.createElement('a');
      a.className = 'btn ghost';
      a.href = camUrl(f.path);
      a.download = f.name;
      a.innerHTML = `${icon('file')}<span>Direkt in Dateien</span>`;
      act.append(a);
    }
  }

  // ---- Zoom & Gesten ------------------------------------------------------
  reset() {
    this.s = 1; this.x = 0; this.y = 0;
    this.swipe = 0; this.drop = 0;
    this.apply(false);
  }

  apply(animate) {
    const t = this.stage.querySelector('.v-zoom');
    t.style.transition = animate && !reduceMotion.matches ? 'transform .22s cubic-bezier(.2,.8,.2,1)' : 'none';
    t.style.transform = `translate3d(${this.x + this.swipe}px, ${this.y + this.drop}px, 0) scale(${this.s})`;
    this.el.style.setProperty('--drop', Math.min(1, Math.abs(this.drop) / 300));
  }

  clamp() {
    const r = this.stage.getBoundingClientRect();
    const mx = (r.width * (this.s - 1)) / 2, my = (r.height * (this.s - 1)) / 2;
    this.x = Math.max(-mx, Math.min(mx, this.x));
    this.y = Math.max(-my, Math.min(my, this.y));
  }

  bindGestures() {
    const pts = new Map();
    let start = null, lastTap = 0, moved = false;
    const st = this.stage;
    st.addEventListener('pointerdown', (e) => {
      if (e.target.closest('video')) return;
      st.setPointerCapture(e.pointerId);
      pts.set(e.pointerId, { x: e.clientX, y: e.clientY });
      moved = false;
      start = this.snapshot(pts);
    });
    st.addEventListener('pointermove', (e) => {
      if (!pts.has(e.pointerId)) return;
      pts.set(e.pointerId, { x: e.clientX, y: e.clientY });
      const now = this.snapshot(pts);
      if (!start || now.n !== start.n) { start = now; return; }
      const dx = now.cx - start.cx, dy = now.cy - start.cy;
      if (Math.hypot(dx, dy) > 6) moved = true;
      if (now.n === 2) {
        const ns = Math.max(1, Math.min(6, start.s * (now.d / start.d)));
        const r = st.getBoundingClientRect();
        const ox = start.cx - r.left - r.width / 2, oy = start.cy - r.top - r.height / 2;
        this.x = ox - ((ox - start.x) * ns) / start.s + dx;
        this.y = oy - ((oy - start.y) * ns) / start.s + dy;
        this.s = ns;
        this.clamp();
      } else if (this.s > 1.01) {
        this.x = start.x + dx; this.y = start.y + dy;
        this.clamp();
      } else if (Math.abs(dx) > Math.abs(dy)) {
        this.swipe = dx; this.drop = 0;
      } else if (dy > 0) {
        this.drop = dy; this.swipe = 0;
      }
      this.apply(false);
    });
    const end = (e) => {
      if (!pts.has(e.pointerId)) return;
      pts.delete(e.pointerId);
      if (pts.size) { start = this.snapshot(pts); return; }
      const now = Date.now();
      if (!moved && e.type === 'pointerup') {
        if (now - lastTap < 280) {
          // Doppeltipp: an der Tipp-Stelle vergrössern bzw. zurücksetzen
          if (this.s > 1.01) { this.s = 1; this.x = 0; this.y = 0; }
          else {
            const r = st.getBoundingClientRect();
            const ox = e.clientX - r.left - r.width / 2, oy = e.clientY - r.top - r.height / 2;
            this.s = 2.5; this.x = -ox * 1.5; this.y = -oy * 1.5; this.clamp();
          }
          this.apply(true);
          lastTap = 0;
          return;
        }
        lastTap = now;
        this.el.classList.toggle('chrome-off');
        return;
      }
      if (this.swipe) {
        const d = this.swipe < -70 ? 1 : this.swipe > 70 ? -1 : 0;
        this.swipe = 0;
        this.apply(true);
        if (d) this.go(d);
      } else if (this.drop) {
        if (this.drop > 120) { this.close(); return; }
        this.drop = 0;
        this.apply(true);
      } else if (this.s <= 1.01) { this.reset(); this.apply(true); }
    };
    st.addEventListener('pointerup', end);
    st.addEventListener('pointercancel', end);
    st.addEventListener('wheel', (e) => {
      e.preventDefault();
      this.s = Math.max(1, Math.min(6, this.s * (e.deltaY < 0 ? 1.15 : 1 / 1.15)));
      if (this.s === 1) { this.x = 0; this.y = 0; }
      this.clamp();
      this.apply(false);
    }, { passive: false });
  }

  snapshot(pts) {
    const p = [...pts.values()];
    const cx = p.reduce((a, q) => a + q.x, 0) / p.length;
    const cy = p.reduce((a, q) => a + q.y, 0) / p.length;
    const d = p.length > 1 ? Math.hypot(p[0].x - p[1].x, p[0].y - p[1].y) : 1;
    return { n: p.length, cx, cy, d, s: this.s, x: this.x, y: this.y };
  }
}
