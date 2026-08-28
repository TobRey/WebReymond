/**
 * AI Groove – gemeinsame Canvas-Rasterfläche.
 *
 * Wird von Step Sequencer, Piano Roll und Arrangement verwendet.
 *
 * Warum Canvas statt DOM? Ein Arrangement mit 512 Takten × 32 Sechzehnteln
 * hätte über 16 000 Zellen. Als DOM-Elemente wäre das auf einem iPhone nicht
 * flüssig. Gezeichnet wird deshalb ausschliesslich der sichtbare Ausschnitt.
 */

import { h, setupCanvas, cssVar, clearCssVarCache } from '../core/dom.js';
import { rafThrottle, clamp } from '../core/util.js';

export class GridSurface {
  /**
   * @param {object} options
   * @param {number} options.gutter       Breite der linken Beschriftungsspalte
   * @param {number} options.header       Höhe der oberen Taktleiste
   * @param {number} options.rowHeight
   * @param {number} options.pxPerTick
   * @param {(ctx:CanvasRenderingContext2D, view:object) => void} options.draw
   */
  constructor(options) {
    this.options = options;
    this.gutter = options.gutter ?? 0;
    this.header = options.header ?? 0;
    this.rowHeight = options.rowHeight ?? 28;
    this.pxPerTick = options.pxPerTick ?? 0.28;
    this.minPxPerTick = options.minPxPerTick ?? 0.02;
    this.maxPxPerTick = options.maxPxPerTick ?? 4;
    this.scrollX = 0;
    this.scrollY = 0;
    this.contentTicks = options.contentTicks ?? 1536;
    this.contentRows = options.contentRows ?? 8;
    this.width = 0;
    this.height = 0;

    this.element = h('div.grid-canvas-wrap');
    this.canvas = h('canvas.grid-canvas');
    this.element.appendChild(this.canvas);
    this.hBar = h('div.grid-scroll-h');
    this.vBar = h('div.grid-scroll-v');
    this.element.appendChild(this.hBar);
    this.element.appendChild(this.vBar);

    this.draw = rafThrottle(() => this._draw());
    this._pointers = new Map();
    this._pinch = null;
    this._panning = false;
    this._scrollTimer = 0;

    this._bind();

    this._observer = new ResizeObserver(() => {
      this._measure();
      this.draw();
    });
    this._observer.observe(this.element);
  }

  destroy() {
    this._observer.disconnect();
    this.draw.cancel?.();
  }

  _measure() {
    const rect = this.element.getBoundingClientRect();
    this.width = rect.width;
    this.height = rect.height;
  }

  // --- Koordinaten -----------------------------------------------------------

  tickToX(tick) {
    return this.gutter + tick * this.pxPerTick - this.scrollX;
  }

  xToTick(x) {
    return (x - this.gutter + this.scrollX) / this.pxPerTick;
  }

  rowToY(row) {
    return this.header + row * this.rowHeight - this.scrollY;
  }

  yToRow(y) {
    return (y - this.header + this.scrollY) / this.rowHeight;
  }

  get viewportW() {
    return Math.max(0, this.width - this.gutter);
  }

  get viewportH() {
    return Math.max(0, this.height - this.header);
  }

  get maxScrollX() {
    return Math.max(0, this.contentTicks * this.pxPerTick - this.viewportW);
  }

  get maxScrollY() {
    return Math.max(0, this.contentRows * this.rowHeight - this.viewportH);
  }

  setScroll(x, y) {
    this.scrollX = clamp(x, 0, this.maxScrollX);
    this.scrollY = clamp(y, 0, this.maxScrollY);
    this._flashScrollbars();
    this.draw();
  }

  scrollBy(dx, dy) {
    this.setScroll(this.scrollX + dx, this.scrollY + dy);
  }

  /** Zoomt um einen Punkt herum (der Tick unter dem Finger bleibt stehen). */
  zoomAt(x, factor) {
    const tickUnder = this.xToTick(x);
    this.pxPerTick = clamp(this.pxPerTick * factor, this.minPxPerTick, this.maxPxPerTick);
    this.scrollX = clamp(tickUnder * this.pxPerTick - (x - this.gutter), 0, this.maxScrollX);
    this.options.onZoom?.(this.pxPerTick);
    this.draw();
  }

  setZoom(pxPerTick) {
    this.pxPerTick = clamp(pxPerTick, this.minPxPerTick, this.maxPxPerTick);
    this.scrollX = clamp(this.scrollX, 0, this.maxScrollX);
    this.draw();
  }

  /** Passt den Zoom so an, dass der Inhalt genau in die Breite passt. */
  fitToContent(marginPx = 8) {
    if (!this.width) this._measure();
    const vw = this.viewportW - marginPx;
    if (vw <= 0 || !this.contentTicks) return;
    this.pxPerTick = clamp(vw / this.contentTicks, this.minPxPerTick, this.maxPxPerTick);
    this.scrollX = 0;
    this.draw();
  }

  /** Sorgt dafür, dass ein Tick sichtbar ist (Playhead-Verfolgung). */
  ensureTickVisible(tick, margin = 80) {
    const x = this.tickToX(tick);
    if (x < this.gutter + margin) {
      this.setScroll(tick * this.pxPerTick - margin, this.scrollY);
    } else if (x > this.width - margin) {
      this.setScroll(tick * this.pxPerTick - this.viewportW + margin, this.scrollY);
    }
  }

  _flashScrollbars() {
    this.element.classList.add('grid-canvas-wrap--scrolling');
    clearTimeout(this._scrollTimer);
    this._scrollTimer = setTimeout(
      () => this.element.classList.remove('grid-canvas-wrap--scrolling'),
      900,
    );
  }

  // --- Eingaben --------------------------------------------------------------

  _bind() {
    const el = this.element;

    el.addEventListener(
      'wheel',
      (event) => {
        event.preventDefault();
        if (event.ctrlKey || event.metaKey) {
          const rect = el.getBoundingClientRect();
          this.zoomAt(event.clientX - rect.left, event.deltaY < 0 ? 1.12 : 1 / 1.12);
          return;
        }
        if (event.shiftKey) this.scrollBy(event.deltaY + event.deltaX, 0);
        else this.scrollBy(event.deltaX, event.deltaY);
      },
      { passive: false },
    );

    el.addEventListener('pointerdown', (event) => {
      el.setPointerCapture?.(event.pointerId);
      this._pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });

      if (this._pointers.size === 2) {
        // Zweiter Finger: laufende Bearbeitung abbrechen, stattdessen Pan/Zoom.
        this.options.onPointerCancel?.();
        const [a, b] = [...this._pointers.values()];
        this._pinch = {
          dist: Math.hypot(a.x - b.x, a.y - b.y),
          cx: (a.x + b.x) / 2,
          cy: (a.y + b.y) / 2,
          pxPerTick: this.pxPerTick,
          scrollX: this.scrollX,
          scrollY: this.scrollY,
        };
        return;
      }

      if (this._pointers.size > 2) return;

      const pt = this._localPoint(event);
      const middleOrSpace = event.button === 1 || event.altKey;
      if (middleOrSpace) {
        this._panning = { x: event.clientX, y: event.clientY, sx: this.scrollX, sy: this.scrollY };
        event.preventDefault();
        return;
      }
      this.options.onPointerDown?.(pt, event);
      event.preventDefault();
    });

    el.addEventListener(
      'pointermove',
      (event) => {
        if (this._pointers.has(event.pointerId)) {
          this._pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
        }

        if (this._pinch && this._pointers.size >= 2) {
          const [a, b] = [...this._pointers.values()];
          const dist = Math.hypot(a.x - b.x, a.y - b.y) || 1;
          const cx = (a.x + b.x) / 2;
          const cy = (a.y + b.y) / 2;
          const rect = el.getBoundingClientRect();

          const factor = dist / this._pinch.dist;
          const newPx = clamp(this._pinch.pxPerTick * factor, this.minPxPerTick, this.maxPxPerTick);
          const anchorTick =
            (this._pinch.cx - rect.left - this.gutter + this._pinch.scrollX) / this._pinch.pxPerTick;
          this.pxPerTick = newPx;
          this.scrollX = clamp(anchorTick * newPx - (cx - rect.left - this.gutter), 0, this.maxScrollX);
          this.scrollY = clamp(this._pinch.scrollY - (cy - this._pinch.cy), 0, this.maxScrollY);
          this.options.onZoom?.(this.pxPerTick);
          this._flashScrollbars();
          this.draw();
          event.preventDefault();
          return;
        }

        if (this._panning) {
          this.setScroll(
            this._panning.sx - (event.clientX - this._panning.x),
            this._panning.sy - (event.clientY - this._panning.y),
          );
          event.preventDefault();
          return;
        }

        this.options.onPointerMove?.(this._localPoint(event), event);
      },
      { passive: false },
    );

    const end = (event) => {
      this._pointers.delete(event.pointerId);
      if (this._pointers.size < 2) this._pinch = null;
      if (this._panning) {
        this._panning = false;
        return;
      }
      this.options.onPointerUp?.(this._localPoint(event), event);
    };

    el.addEventListener('pointerup', end);
    el.addEventListener('pointercancel', (event) => {
      this._pointers.delete(event.pointerId);
      this._pinch = null;
      this._panning = false;
      this.options.onPointerCancel?.(event);
    });

    el.addEventListener('contextmenu', (event) => {
      event.preventDefault();
      this.options.onContextMenu?.(this._localPoint(event), event);
    });

    el.addEventListener('dblclick', (event) => {
      this.options.onDoubleClick?.(this._localPoint(event), event);
    });
  }

  _localPoint(event) {
    const rect = this.element.getBoundingClientRect();
    const x = event.clientX - rect.left;
    const y = event.clientY - rect.top;
    return {
      x,
      y,
      tick: this.xToTick(x),
      row: this.yToRow(y),
      inGutter: x < this.gutter,
      inHeader: y < this.header,
      clientX: event.clientX,
      clientY: event.clientY,
    };
  }

  // --- Zeichnen --------------------------------------------------------------

  _draw() {
    if (!this.element.isConnected) return;
    if (!this.width || !this.height) this._measure();
    const { ctx, width, height } = setupCanvas(this.canvas, 2);
    this.width = width;
    this.height = height;
    if (!width || !height) return;

    ctx.clearRect(0, 0, width, height);
    this.options.draw?.(ctx, this);

    // Scrollbalken
    const hRatio = this.viewportW / Math.max(1, this.contentTicks * this.pxPerTick);
    if (hRatio < 1) {
      const barW = Math.max(28, this.viewportW * hRatio);
      const barX = this.gutter + (this.viewportW - barW) * (this.scrollX / Math.max(1, this.maxScrollX));
      this.hBar.style.left = `${barX}px`;
      this.hBar.style.width = `${barW}px`;
      this.hBar.style.display = 'block';
    } else {
      this.hBar.style.display = 'none';
    }

    const vRatio = this.viewportH / Math.max(1, this.contentRows * this.rowHeight);
    if (vRatio < 1) {
      const barH = Math.max(28, this.viewportH * vRatio);
      const barY = this.header + (this.viewportH - barH) * (this.scrollY / Math.max(1, this.maxScrollY));
      this.vBar.style.top = `${barY}px`;
      this.vBar.style.height = `${barH}px`;
      this.vBar.style.display = 'block';
    } else {
      this.vBar.style.display = 'none';
    }
  }
}

/**
 * Zeichnet das Taktraster (Hintergrundlinien + Taktnummern).
 * Taktgrenzen werden deutlich kräftiger dargestellt als Unterteilungen.
 */
export function drawTimeGrid(ctx, surface, opts) {
  const {
    ticksPerBar,
    subdivision, // Ticks pro feiner Linie
    top = surface.header,
    bottom = surface.height,
    showNumbers = true,
    beatTicks = null,
  } = opts;

  const startTick = Math.max(0, surface.xToTick(surface.gutter));
  const endTick = surface.xToTick(surface.width);

  const cBar = cssVar('--c-border-strong');
  const cBeat = cssVar('--c-border');
  const cSub = cssVar('--c-surface-2');
  const cText = cssVar('--c-text-faint');

  // Feine Unterteilungen nur zeichnen, wenn sie noch unterscheidbar sind.
  if (subdivision && subdivision * surface.pxPerTick > 4) {
    ctx.strokeStyle = cSub;
    ctx.lineWidth = 1;
    ctx.beginPath();
    const first = Math.floor(startTick / subdivision) * subdivision;
    for (let t = first; t <= endTick; t += subdivision) {
      const x = Math.round(surface.tickToX(t)) + 0.5;
      if (x < surface.gutter) continue;
      ctx.moveTo(x, top);
      ctx.lineTo(x, bottom);
    }
    ctx.stroke();
  }

  const beat = beatTicks || ticksPerBar / 4;
  if (beat * surface.pxPerTick > 10) {
    ctx.strokeStyle = cBeat;
    ctx.lineWidth = 1;
    ctx.beginPath();
    const first = Math.floor(startTick / beat) * beat;
    for (let t = first; t <= endTick; t += beat) {
      if (t % ticksPerBar === 0) continue;
      const x = Math.round(surface.tickToX(t)) + 0.5;
      if (x < surface.gutter) continue;
      ctx.moveTo(x, top);
      ctx.lineTo(x, bottom);
    }
    ctx.stroke();
  }

  ctx.strokeStyle = cBar;
  ctx.lineWidth = 1;
  ctx.beginPath();
  const firstBar = Math.floor(startTick / ticksPerBar) * ticksPerBar;
  for (let t = firstBar; t <= endTick; t += ticksPerBar) {
    const x = Math.round(surface.tickToX(t)) + 0.5;
    if (x < surface.gutter) continue;
    ctx.moveTo(x, top - (showNumbers ? surface.header : 0));
    ctx.lineTo(x, bottom);
  }
  ctx.stroke();

  if (showNumbers && surface.header > 0) {
    ctx.fillStyle = cText;
    ctx.font = '10px ui-monospace, SFMono-Regular, Menlo, monospace';
    ctx.textBaseline = 'middle';
    const step = ticksPerBar * Math.max(1, Math.ceil(46 / (ticksPerBar * surface.pxPerTick)));
    const first = Math.floor(startTick / step) * step;
    for (let t = first; t <= endTick; t += step) {
      const x = surface.tickToX(t);
      if (x < surface.gutter) continue;
      ctx.fillText(String(Math.round(t / ticksPerBar) + 1), x + 4, surface.header / 2);
    }
  }
}

/**
 * Dunkelt alles hinter dem Ende des Inhalts ab.
 * Macht auf einen Blick klar, wie lang ein Pattern wirklich ist.
 */
export function drawBeyondEnd(ctx, surface, endTick, top = surface.header) {
  const x = Math.max(surface.gutter, surface.tickToX(endTick));
  if (x >= surface.width) return;
  ctx.fillStyle = 'rgba(2, 3, 8, 0.55)';
  ctx.fillRect(x, top, surface.width - x, surface.height - top);
  ctx.strokeStyle = cssVar('--c-border-strong');
  ctx.lineWidth = 1;
  ctx.beginPath();
  ctx.moveTo(Math.round(x) + 0.5, top);
  ctx.lineTo(Math.round(x) + 0.5, surface.height);
  ctx.stroke();
}

/** Zeichnet den Abspielkopf. */
export function drawPlayhead(ctx, surface, tick, { top = 0, bottom = null } = {}) {
  const x = surface.tickToX(tick);
  if (x < surface.gutter - 2 || x > surface.width + 2) return;
  const b = bottom ?? surface.height;
  ctx.strokeStyle = cssVar('--c-mint');
  ctx.lineWidth = 2;
  ctx.beginPath();
  ctx.moveTo(Math.round(x) + 0.5, top);
  ctx.lineTo(Math.round(x) + 0.5, b);
  ctx.stroke();

  ctx.fillStyle = cssVar('--c-mint');
  ctx.beginPath();
  ctx.moveTo(x - 5, top);
  ctx.lineTo(x + 5, top);
  ctx.lineTo(x, top + 7);
  ctx.closePath();
  ctx.fill();
}

/** Farbcache leeren, wenn das Farbschema wechselt. */
export function refreshGridColors() {
  clearCssVarCache();
}
