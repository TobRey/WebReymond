/**
 * AI Groove – Step Sequencer.
 *
 * Zeilen = Instrumente des Patterns, Spalten = Schritte.
 * Raster umschaltbar (1/4, 1/8, 1/16, 1/32), beliebig viele Takte,
 * horizontal scroll- und zoombar. Gezeichnet wird nur der sichtbare Bereich.
 */

import { h, icon, clear, cssVar, trackColorValue, withAlpha, segmented, slider, onLongPress } from '../core/dom.js';
import { store } from '../core/store.js';
import { bus, EV } from '../core/bus.js';
import { engine } from '../audio/engine.js';
import { GridSurface, drawTimeGrid, drawPlayhead, drawBeyondEnd } from './gridbase.js';
import { makeDropTarget } from './dragdrop.js';
import { openContextMenu } from './contextmenu.js';
import { promptModal, confirmModal, openModal } from './modal.js';
import { toast } from './toast.js';
import { createEvent } from '../core/project.js';
import { TICKS_PER_BAR, clamp, haptic } from '../core/util.js';

const GRID_OPTIONS = [
  { label: '1/4', value: 4 },
  { label: '1/8', value: 8 },
  { label: '1/16', value: 16 },
  { label: '1/32', value: 32 },
];

export function createSequencerView() {
  const element = h('div.view.view--seq');
  const holder = h('div.view__body');
  element.appendChild(holder);

  const root = h('div.grid-view');
  holder.appendChild(root);

  // --- Patternleiste ---------------------------------------------------------
  const patStrip = h('div.pat-strip', { 'data-tour': 'patterns' });
  root.appendChild(patStrip);

  // --- Werkzeugleiste --------------------------------------------------------
  const toolbar = h('div.grid-toolbar');
  root.appendChild(toolbar);

  const gridSeg = segmented(GRID_OPTIONS, 16, (value) => {
    const pattern = store.selectedPattern;
    if (pattern) {
      store.setPatternGrid(pattern.id, value);
      surface.draw();
    }
  });
  toolbar.appendChild(gridSeg);

  const barsWrap = h('div.bpm');
  const barsMinus = h('button.bpm__step', { type: 'button', text: '−', title: 'Takt entfernen' });
  const barsVal = h('span.bpm__val', { text: '1', style: { cursor: 'default' } });
  const barsPlus = h('button.bpm__step', { type: 'button', text: '+', title: 'Takt hinzufügen' });
  barsWrap.appendChild(barsMinus);
  barsWrap.appendChild(barsVal);
  barsWrap.appendChild(barsPlus);
  barsWrap.appendChild(h('span.bpm__unit', { text: 'TAKTE' }));
  toolbar.appendChild(barsWrap);

  barsMinus.addEventListener('click', () => changeBars(-1));
  barsPlus.addEventListener('click', () => changeBars(1));

  toolbar.appendChild(h('div.grid-toolbar__spacer'));

  const clearBtn = h('button.btn.btn--sm', { type: 'button' });
  clearBtn.appendChild(icon('trash', 15));
  clearBtn.appendChild(h('span', { text: 'Leeren' }));
  clearBtn.addEventListener('click', async () => {
    const pattern = store.selectedPattern;
    if (!pattern || !pattern.events.length) return;
    const ok = await confirmModal({
      title: 'Pattern leeren?',
      message: `Alle Noten in „${pattern.name}“ werden entfernt. Rückgängig ist möglich.`,
      confirmLabel: 'Leeren',
      danger: true,
    });
    if (ok) {
      store.updateEvents(pattern.id, (p) => {
        p.events = [];
      }, 'Pattern leeren');
    }
  });
  toolbar.appendChild(clearBtn);

  const fitBtn = h('button.btn.btn--sm.btn--icon', { type: 'button', title: 'Zoom an Patternlänge anpassen' });
  fitBtn.appendChild(icon('grid', 15));
  fitBtn.addEventListener('click', () => {
    userZoomed = false;
    fitZoom();
  });
  toolbar.appendChild(fitBtn);

  const dupBtn = h('button.btn.btn--sm.btn--icon', { type: 'button', title: 'Pattern duplizieren' });
  dupBtn.appendChild(icon('copy', 15));
  dupBtn.addEventListener('click', () => {
    const pattern = store.selectedPattern;
    if (pattern) store.duplicatePattern(pattern.id);
  });
  toolbar.appendChild(dupBtn);

  // --- Rasterfläche ----------------------------------------------------------
  const surface = new GridSurface({
    gutter: 148,
    header: 26,
    rowHeight: 42,
    pxPerTick: 0.32,
    minPxPerTick: 0.06,
    maxPxPerTick: 2.4,
    draw: (ctx, s) => paint(ctx, s),
    onPointerDown: (pt, event) => onDown(pt, event),
    onPointerMove: (pt) => onMove(pt),
    onPointerUp: () => onUp(),
    onPointerCancel: () => onUp(),
    onContextMenu: (pt) => onContext(pt),
    onZoom: () => {
      // Sobald der Nutzer selbst zoomt, wird nicht mehr automatisch angepasst.
      userZoomed = true;
    },
  });
  root.appendChild(surface.element);

  if (window.matchMedia('(max-width: 760px)').matches) surface.gutter = 104;

  makeDropTarget(surface.element, {
    accepts: (payload) => payload?.type === 'sample',
    onDrop: (payload, x, y) => {
      let pattern = store.selectedPattern;
      if (!pattern) pattern = store.addPattern({ rows: [payload.sampleId] });
      store.ensurePatternRow(pattern.id, payload.sampleId);

      // Wird direkt auf eine Position gezogen, dort gleich einen Schritt setzen.
      const rect = surface.element.getBoundingClientRect();
      const local = { x: x - rect.left, y: y - rect.top };
      if (local.x > surface.gutter) {
        const step = stepTicks();
        const tick = Math.max(0, Math.floor(surface.xToTick(local.x) / step) * step);
        if (tick < pattern.lengthBars * TICKS_PER_BAR) {
          store.addEvents(
            pattern.id,
            [createEvent(payload.sampleId, tick, { dur: step })],
            'Schritt setzen',
          );
        }
      }
      toast.ok('Instrument im Pattern', payload.name);
    },
  });

  // --- Zustand ---------------------------------------------------------------
  let paintMode = null; // 'add' | 'remove'
  let lastPaintedKey = '';
  let playheadRaf = 0;
  let userZoomed = false;

  function stepTicks() {
    const pattern = store.selectedPattern;
    return TICKS_PER_BAR / (pattern?.grid || 16);
  }

  function rows() {
    const pattern = store.selectedPattern;
    if (!pattern) return [];
    return pattern.rows.map((id) => store.getSample(id)).filter(Boolean);
  }

  function changeBars(delta) {
    const pattern = store.selectedPattern;
    if (!pattern) return;
    store.setPatternLength(pattern.id, clamp(pattern.lengthBars + delta, 1, 64));
    syncMeta();
  }

  function eventAt(pattern, sampleId, tick, step) {
    return pattern.events.find(
      (e) => e.sampleId === sampleId && e.tick >= tick && e.tick < tick + step,
    );
  }

  // --- Eingaben --------------------------------------------------------------

  function onDown(pt, event) {
    const pattern = store.selectedPattern;
    if (!pattern) return;

    if (pt.inHeader) {
      const step = stepTicks();
      engine.seek(Math.max(0, Math.round(pt.tick / step) * step));
      return;
    }
    if (pt.inGutter) {
      const row = Math.floor(pt.row);
      const sample = rows()[row];
      if (sample) rowMenu(sample, { x: pt.clientX, y: pt.clientY });
      return;
    }

    const rowIndex = Math.floor(pt.row);
    const list = rows();
    if (rowIndex < 0 || rowIndex >= list.length) return;
    const sample = list[rowIndex];
    const step = stepTicks();
    const tick = Math.floor(pt.tick / step) * step;
    if (tick < 0 || tick >= pattern.lengthBars * TICKS_PER_BAR) return;

    const existing = eventAt(pattern, sample.id, tick, step);
    paintMode = existing ? 'remove' : 'add';
    lastPaintedKey = '';
    applyPaint(sample.id, tick, step, event);
  }

  function onMove(pt) {
    if (!paintMode) return;
    const pattern = store.selectedPattern;
    if (!pattern || pt.inGutter || pt.inHeader) return;
    const list = rows();
    const rowIndex = Math.floor(pt.row);
    if (rowIndex < 0 || rowIndex >= list.length) return;
    const step = stepTicks();
    const tick = Math.floor(pt.tick / step) * step;
    if (tick < 0 || tick >= pattern.lengthBars * TICKS_PER_BAR) return;
    applyPaint(list[rowIndex].id, tick, step);
  }

  function onUp() {
    paintMode = null;
    lastPaintedKey = '';
  }

  function applyPaint(sampleId, tick, step, event) {
    const key = `${sampleId}|${tick}`;
    if (key === lastPaintedKey) return;
    lastPaintedKey = key;

    const pattern = store.selectedPattern;
    const existing = eventAt(pattern, sampleId, tick, step);

    if (paintMode === 'add' && !existing) {
      store.addEvents(pattern.id, [createEvent(sampleId, tick, { dur: step })], 'Schritt setzen');
      haptic(6);
      // Kurzes Vorhören beim Setzen – nur wenn nicht gerade abgespielt wird.
      if (!engine.playing && event) engine.preview(sampleId).catch(() => {});
    } else if (paintMode === 'remove' && existing) {
      store.removeEvents(pattern.id, [existing.id], 'Schritt entfernen');
      haptic(4);
    }
  }

  function onContext(pt) {
    const pattern = store.selectedPattern;
    if (!pattern || pt.inGutter || pt.inHeader) return;
    const list = rows();
    const rowIndex = Math.floor(pt.row);
    if (rowIndex < 0 || rowIndex >= list.length) return;
    const sample = list[rowIndex];
    const step = stepTicks();
    const tick = Math.floor(pt.tick / step) * step;
    const ev = eventAt(pattern, sample.id, tick, step);

    openContextMenu({ x: pt.clientX, y: pt.clientY }, [
      { heading: `${sample.name} – Schritt ${Math.floor(tick / step) + 1}` },
      ev
        ? {
            label: 'Anschlagstärke …',
            icon: 'mixer',
            onClick: () => velocityDialog(pattern.id, ev.id),
          }
        : null,
      ev
        ? {
            label: 'Schritt entfernen',
            icon: 'trash',
            danger: true,
            onClick: () => store.removeEvents(pattern.id, [ev.id], 'Schritt entfernen'),
          }
        : {
            label: 'Schritt setzen',
            icon: 'plus',
            onClick: () =>
              store.addEvents(pattern.id, [createEvent(sample.id, tick, { dur: step })], 'Schritt setzen'),
          },
      { separator: true },
      {
        label: 'Ganze Zeile leeren',
        icon: 'trash',
        onClick: () =>
          store.updateEvents(pattern.id, (p) => {
            p.events = p.events.filter((e) => e.sampleId !== sample.id);
          }, 'Zeile leeren'),
      },
    ]);
  }

  function velocityDialog(patternId, eventId) {
    const pattern = store.getPattern(patternId);
    const ev = pattern?.events.find((e) => e.id === eventId);
    if (!ev) return;
    const label = h('b', { text: `${Math.round(ev.vel * 100)} %` });
    const s = slider(5, 100, 1, Math.round(ev.vel * 100), (v) => {
      label.textContent = `${v} %`;
      store.updateEvents(patternId, (p) => {
        const target = p.events.find((e) => e.id === eventId);
        if (target) target.vel = v / 100;
      }, 'Anschlagstärke');
    });
    openModal({
      title: 'Anschlagstärke',
      size: 'narrow',
      body: h('div.field', null, [h('label.field__label', null, [document.createTextNode('Velocity '), label]), s]),
      actions: [{ label: 'Fertig', variant: 'primary' }],
    });
  }

  function rowMenu(sample, position) {
    const pattern = store.selectedPattern;
    openContextMenu(position, [
      { heading: sample.name },
      {
        label: 'Vorhören',
        icon: 'play',
        onClick: () => engine.preview(sample.id).catch(() => {}),
      },
      {
        label: 'In Piano Roll öffnen',
        icon: 'piano',
        onClick: () => {
          store.setUi({ selectedSampleId: sample.id });
          bus.emit(EV.SELECTION_CHANGED, { sampleId: sample.id, view: 'piano' });
        },
      },
      { separator: true },
      {
        label: 'Zeile leeren',
        icon: 'trash',
        onClick: () =>
          store.updateEvents(pattern.id, (p) => {
            p.events = p.events.filter((e) => e.sampleId !== sample.id);
          }, 'Zeile leeren'),
      },
      {
        label: 'Zeile entfernen',
        icon: 'close',
        danger: true,
        onClick: () => store.removePatternRow(pattern.id, sample.id),
      },
    ]);
  }

  // --- Zeichnen --------------------------------------------------------------

  function paint(ctx, s) {
    const pattern = store.selectedPattern;
    const list = rows();
    s.contentTicks = (pattern?.lengthBars || 1) * TICKS_PER_BAR;
    s.contentRows = Math.max(list.length, 1);

    const bg = cssVar('--c-bg-deep');
    ctx.fillStyle = bg;
    ctx.fillRect(0, 0, s.width, s.height);

    if (!pattern) {
      ctx.fillStyle = cssVar('--c-text-faint');
      ctx.font = '13px system-ui, sans-serif';
      ctx.textAlign = 'center';
      ctx.fillText('Kein Pattern ausgewählt – oben auf „+ Pattern“ tippen.', s.width / 2, s.height / 2);
      ctx.textAlign = 'left';
      return;
    }

    const step = stepTicks();

    // Zeilenhintergrund
    for (let i = 0; i < list.length; i++) {
      const y = s.rowToY(i);
      if (y + s.rowHeight < s.header || y > s.height) continue;
      ctx.fillStyle = i % 2 === 0 ? cssVar('--c-surface') : 'transparent';
      ctx.fillRect(s.gutter, y, s.width - s.gutter, s.rowHeight);
    }

    drawTimeGrid(ctx, s, { ticksPerBar: TICKS_PER_BAR, subdivision: step, top: s.header });

    // Schritte
    const maxTick = pattern.lengthBars * TICKS_PER_BAR;
    for (let i = 0; i < list.length; i++) {
      const sample = list[i];
      const y = s.rowToY(i);
      if (y + s.rowHeight < s.header || y > s.height) continue;
      const color = trackColorValue(sample.id);

      for (const ev of pattern.events) {
        if (ev.sampleId !== sample.id) continue;
        if (ev.tick >= maxTick) continue;
        const x = s.tickToX(ev.tick);
        const w = Math.max(6, step * s.pxPerTick - 2);
        if (x + w < s.gutter || x > s.width) continue;

        const pad = 5;
        const hgt = s.rowHeight - pad * 2;
        ctx.fillStyle = withAlpha(color, 0.35 + ev.vel * 0.55);
        roundRect(ctx, x + 1, y + pad, w, hgt, 4);
        ctx.fill();

        ctx.fillStyle = withAlpha(color, 1);
        ctx.fillRect(x + 1, y + pad, 2.5, hgt);
      }
    }

    // Linke Spalte
    ctx.fillStyle = cssVar('--c-bg-elev');
    ctx.fillRect(0, 0, s.gutter, s.height);
    ctx.strokeStyle = cssVar('--c-border');
    ctx.beginPath();
    ctx.moveTo(s.gutter + 0.5, 0);
    ctx.lineTo(s.gutter + 0.5, s.height);
    ctx.stroke();

    ctx.font = '12px system-ui, sans-serif';
    ctx.textBaseline = 'middle';
    for (let i = 0; i < list.length; i++) {
      const sample = list[i];
      const y = s.rowToY(i);
      if (y + s.rowHeight < s.header || y > s.height) continue;
      ctx.fillStyle = trackColorValue(sample.id);
      ctx.fillRect(4, y + 8, 3, s.rowHeight - 16);
      ctx.fillStyle = cssVar('--c-text');
      const name = fitText(ctx, sample.name, s.gutter - 22);
      ctx.fillText(name, 14, y + s.rowHeight / 2);
    }

    // Kopfzeile über der Spalte
    ctx.fillStyle = cssVar('--c-bg-elev');
    ctx.fillRect(0, 0, s.gutter, s.header);
    ctx.fillStyle = cssVar('--c-text-faint');
    ctx.font = '10px system-ui, sans-serif';
    ctx.fillText(`${pattern.grid} Schritte/Takt`, 10, s.header / 2);

    if (!list.length) {
      ctx.fillStyle = cssVar('--c-text-faint');
      ctx.font = '13px system-ui, sans-serif';
      ctx.textAlign = 'center';
      ctx.fillText(
        'Ziehe ein Sample aus der Liste hierher.',
        s.gutter + (s.width - s.gutter) / 2,
        s.height / 2,
      );
      ctx.textAlign = 'left';
    }

    // Bereich hinter dem Patternende abdunkeln
    drawBeyondEnd(ctx, s, maxTick, 0);

    // Abspielkopf (nur im Pattern-Modus sinnvoll)
    if (engine.playing && engine.mode === 'pattern') {
      const pos = engine.position % Math.max(1, maxTick);
      drawPlayhead(ctx, s, pos, { top: 0 });
    }
  }

  function roundRect(ctx, x, y, w, hgt, r) {
    const rad = Math.min(r, w / 2, hgt / 2);
    ctx.beginPath();
    ctx.moveTo(x + rad, y);
    ctx.arcTo(x + w, y, x + w, y + hgt, rad);
    ctx.arcTo(x + w, y + hgt, x, y + hgt, rad);
    ctx.arcTo(x, y + hgt, x, y, rad);
    ctx.arcTo(x, y, x + w, y, rad);
    ctx.closePath();
  }

  function fitText(ctx, text, maxWidth) {
    if (ctx.measureText(text).width <= maxWidth) return text;
    let cut = text;
    while (cut.length > 1 && ctx.measureText(`${cut}…`).width > maxWidth) cut = cut.slice(0, -1);
    return `${cut}…`;
  }

  // --- Patternleiste ---------------------------------------------------------

  function renderPatterns() {
    clear(patStrip);
    for (const pattern of store.project.patterns) {
      const chip = h('button.pat-chip', {
        type: 'button',
        'aria-selected': String(pattern.id === store.project.ui.selectedPatternId),
        style: { '--pat-color': `var(--t${pattern.colorIdx})` },
      });
      chip.appendChild(h('span.pat-chip__dot'));
      chip.appendChild(h('span', { text: pattern.name }));
      chip.addEventListener('click', () => {
        store.selectPattern(pattern.id);
        renderPatterns();
        syncMeta();
        surface.draw();
      });
      onLongPress(chip, ({ x, y }) => patternMenu(pattern, { x, y }));
      patStrip.appendChild(chip);
    }

    const addChip = h('button.pat-chip', { type: 'button', title: 'Neues Pattern' });
    addChip.appendChild(icon('plus', 14));
    addChip.appendChild(h('span', { text: 'Pattern' }));
    addChip.addEventListener('click', () => {
      store.addPattern({});
      renderPatterns();
      syncMeta();
    });
    patStrip.appendChild(addChip);
  }

  function patternMenu(pattern, position) {
    openContextMenu(position, [
      { heading: pattern.name },
      {
        label: 'Umbenennen',
        icon: 'edit',
        onClick: async () => {
          const name = await promptModal({ title: 'Pattern umbenennen', label: 'Name', value: pattern.name });
          if (name) {
            store.renamePattern(pattern.id, name);
            renderPatterns();
          }
        },
      },
      {
        label: 'Duplizieren',
        icon: 'copy',
        onClick: () => {
          store.duplicatePattern(pattern.id);
          renderPatterns();
        },
      },
      { separator: true },
      {
        label: 'Löschen',
        icon: 'trash',
        danger: true,
        onClick: async () => {
          const ok = await confirmModal({
            title: 'Pattern löschen?',
            message: `„${pattern.name}“ und alle zugehörigen Clips im Arrangement werden entfernt.`,
            confirmLabel: 'Löschen',
            danger: true,
          });
          if (ok) {
            store.deletePattern(pattern.id);
            renderPatterns();
            syncMeta();
          }
        },
      },
    ]);
  }

  /** Passt die Zoomstufe an die Patternlänge an, solange der Nutzer nicht selbst gezoomt hat. */
  function fitZoom() {
    if (userZoomed) return;
    surface.fitToContent(10);
  }

  function syncMeta() {
    const pattern = store.selectedPattern;
    barsVal.textContent = String(pattern?.lengthBars || 1);
    gridSeg.setValue(pattern?.grid || 16);
    surface.contentTicks = (pattern?.lengthBars || 1) * TICKS_PER_BAR;
    fitZoom();
    surface.draw();
  }

  function tickLoop() {
    if (engine.playing && engine.mode === 'pattern') surface.draw();
    playheadRaf = requestAnimationFrame(tickLoop);
  }

  const offPatterns = bus.on(EV.PATTERNS_CHANGED, () => {
    renderPatterns();
    syncMeta();
  });
  const offSelected = bus.on(EV.PATTERN_SELECTED, () => {
    userZoomed = false;
    renderPatterns();
    syncMeta();
  });
  const offSamples = bus.on(EV.SAMPLES_CHANGED, () => surface.draw());
  const offLoaded = bus.on(EV.PROJECT_LOADED, () => {
    renderPatterns();
    syncMeta();
  });

  renderPatterns();
  syncMeta();

  return {
    element,
    onShow() {
      renderPatterns();
      syncMeta();
      // Nach dem Einblenden ist die Breite erst bekannt – deshalb erneut anpassen.
      requestAnimationFrame(() => {
        surface._measure();
        fitZoom();
        surface.draw();
      });
      cancelAnimationFrame(playheadRaf);
      playheadRaf = requestAnimationFrame(tickLoop);
    },
    onHide() {
      cancelAnimationFrame(playheadRaf);
      playheadRaf = 0;
    },
    destroy() {
      cancelAnimationFrame(playheadRaf);
      offPatterns();
      offSelected();
      offSamples();
      offLoaded();
      surface.destroy();
    },
  };
}
