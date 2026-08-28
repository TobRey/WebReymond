/**
 * AI Groove – Piano Roll.
 *
 * Links eine echte Klaviatur, rechts das Notenraster.
 * Eine Zeile entspricht genau einem Halbton: oben höher, unten tiefer.
 * Die Tonhöhe entsteht durch Änderung der Abspielrate des Samples
 * (2^(Halbtöne/12)) – die in Samplern übliche und klanglich sauberste Methode.
 */

import { h, icon, clear, cssVar, trackColorValue, withAlpha, segmented } from '../core/dom.js';
import { store } from '../core/store.js';
import { bus, EV } from '../core/bus.js';
import { engine } from '../audio/engine.js';
import { GridSurface, drawTimeGrid, drawPlayhead, drawBeyondEnd } from './gridbase.js';
import { makeDropTarget } from './dragdrop.js';
import { openContextMenu } from './contextmenu.js';
import { toast } from './toast.js';
import { createEvent } from '../core/project.js';
import { TICKS_PER_BAR, PPQ, clamp, noteName, isBlackKey, quantizeTick, haptic } from '../core/util.js';

/** Dargestellter Tonumfang in Halbtönen um den Originalton. */
const PITCH_MAX = 24;
const PITCH_MIN = -24;
const ROW_COUNT = PITCH_MAX - PITCH_MIN + 1;
/** MIDI-Note, die dem Originalton entspricht (C4). */
const ROOT_MIDI = 60;

const GRID_OPTIONS = [
  { label: '1/4', value: PPQ },
  { label: '1/8', value: PPQ / 2 },
  { label: '1/16', value: PPQ / 4 },
  { label: '1/32', value: PPQ / 8 },
];

export function createPianoRollView() {
  const element = h('div.view.view--piano');
  const holder = h('div.view__body');
  element.appendChild(holder);

  const root = h('div.grid-view');
  holder.appendChild(root);

  const toolbar = h('div.grid-toolbar');
  root.appendChild(toolbar);

  const sampleSelect = h('select.select', { style: { maxWidth: '190px', minHeight: '34px' } });
  sampleSelect.addEventListener('change', () => {
    store.setUi({ selectedSampleId: sampleSelect.value });
    surface.draw();
  });
  toolbar.appendChild(sampleSelect);

  let gridTicks = PPQ / 4;
  const gridSeg = segmented(GRID_OPTIONS, gridTicks, (value) => {
    gridTicks = value;
    surface.draw();
  });
  toolbar.appendChild(gridSeg);

  let snap = true;
  const snapBtn = h('button.btn.btn--sm', { type: 'button', 'aria-pressed': 'true' });
  snapBtn.appendChild(icon('grid', 15));
  snapBtn.appendChild(h('span', { text: 'Raster' }));
  snapBtn.addEventListener('click', () => {
    snap = !snap;
    snapBtn.setAttribute('aria-pressed', String(snap));
  });
  toolbar.appendChild(snapBtn);

  toolbar.appendChild(h('div.grid-toolbar__spacer'));

  const quantBtn = h('button.btn.btn--sm', { type: 'button', title: 'Auswahl quantisieren' });
  quantBtn.appendChild(h('span', { text: 'Quantisieren' }));
  quantBtn.addEventListener('click', () => quantizeSelection());
  toolbar.appendChild(quantBtn);

  const delBtn = h('button.btn.btn--sm.btn--icon', { type: 'button', title: 'Auswahl löschen' });
  delBtn.appendChild(icon('trash', 15));
  delBtn.addEventListener('click', () => deleteSelection());
  toolbar.appendChild(delBtn);

  // --- Rasterfläche ----------------------------------------------------------
  const surface = new GridSurface({
    gutter: 62,
    header: 24,
    rowHeight: 18,
    pxPerTick: 0.5,
    minPxPerTick: 0.08,
    maxPxPerTick: 4,
    contentRows: ROW_COUNT,
    draw: (ctx, s) => paint(ctx, s),
    onPointerDown: (pt, event) => onDown(pt, event),
    onPointerMove: (pt) => onMove(pt),
    onPointerUp: (pt) => onUp(pt),
    onPointerCancel: () => cancelDrag(),
    onContextMenu: (pt) => onContext(pt),
  });
  root.appendChild(surface.element);
  surface.contentRows = ROW_COUNT;
  // Startansicht auf den Originalton zentrieren.
  surface.scrollY = Math.max(0, (PITCH_MAX - 6) * surface.rowHeight - 120);

  makeDropTarget(surface.element, {
    accepts: (payload) => payload?.type === 'sample',
    onDrop: (payload) => {
      const pattern = ensurePattern();
      store.ensurePatternRow(pattern.id, payload.sampleId);
      store.setUi({ selectedSampleId: payload.sampleId });
      syncSamples();
      toast.ok('Instrument gewählt', payload.name);
    },
  });

  // --- Zustand ---------------------------------------------------------------
  /** @type {Set<string>} */
  const selection = new Set();
  let drag = null;
  let clipboard = [];
  let playheadRaf = 0;

  function ensurePattern() {
    return store.selectedPattern || store.addPattern({});
  }

  function currentSample() {
    const id = store.project.ui.selectedSampleId;
    return store.getSample(id) || store.project.samples[0] || null;
  }

  function notes() {
    const pattern = store.selectedPattern;
    const sample = currentSample();
    if (!pattern || !sample) return [];
    return pattern.events.filter((e) => e.sampleId === sample.id);
  }

  function rowToPitch(row) {
    return PITCH_MAX - Math.floor(row);
  }

  function pitchToRow(pitch) {
    return PITCH_MAX - pitch;
  }

  function noteAt(tick, pitch) {
    return notes().find((n) => n.pitch === pitch && tick >= n.tick && tick < n.tick + n.dur) || null;
  }

  function snapTick(tick, mode = 'floor') {
    return snap ? quantizeTick(tick, gridTicks, mode) : Math.round(tick);
  }

  // --- Eingaben --------------------------------------------------------------

  function onDown(pt, event) {
    const pattern = store.selectedPattern;
    const sample = currentSample();

    if (pt.inHeader) {
      engine.seek(Math.max(0, snapTick(pt.tick, 'nearest')));
      return;
    }

    if (pt.inGutter) {
      // Klaviatur: Ton vorhören.
      const pitch = rowToPitch(pt.row);
      if (sample) engine.preview(sample.id, { pitch }).catch(() => {});
      return;
    }

    if (!pattern || !sample) {
      toast.warn('Kein Instrument', 'Wähle links ein Sample oder ziehe eines hierher.');
      return;
    }

    const pitch = rowToPitch(pt.row);
    const hit = noteAt(pt.tick, pitch);

    if (hit) {
      const rightEdge = surface.tickToX(hit.tick + hit.dur);
      const isResize = Math.abs(pt.x - rightEdge) < 10;

      if (!selection.has(hit.id) && !event.shiftKey) selection.clear();
      selection.add(hit.id);

      drag = {
        mode: isResize ? 'resize' : 'move',
        startTick: pt.tick,
        startPitch: pitch,
        origin: notes()
          .filter((n) => selection.has(n.id))
          .map((n) => ({ id: n.id, tick: n.tick, pitch: n.pitch, dur: n.dur })),
        moved: false,
      };
      haptic(6);
      surface.draw();
      return;
    }

    // Leere Fläche: neue Note oder Auswahlrechteck.
    if (event.shiftKey || event.pointerType === 'mouse' && event.button === 0 && event.altKey) {
      drag = { mode: 'rect', x0: pt.x, y0: pt.y, x1: pt.x, y1: pt.y };
      return;
    }

    const tick = clamp(snapTick(pt.tick, 'floor'), 0, pattern.lengthBars * TICKS_PER_BAR - 1);
    const note = createEvent(sample.id, tick, { dur: gridTicks, pitch, vel: 0.9 });
    store.addEvents(pattern.id, [note], 'Note setzen');
    selection.clear();
    selection.add(note.id);
    engine.preview(sample.id, { pitch }).catch(() => {});
    haptic(7);

    drag = {
      mode: 'resize',
      startTick: pt.tick,
      startPitch: pitch,
      origin: [{ id: note.id, tick: note.tick, pitch: note.pitch, dur: note.dur }],
      moved: false,
    };
  }

  function onMove(pt) {
    if (!drag) return;
    const pattern = store.selectedPattern;
    if (!pattern) return;

    if (drag.mode === 'rect') {
      drag.x1 = pt.x;
      drag.y1 = pt.y;
      surface.draw();
      return;
    }

    const maxTick = pattern.lengthBars * TICKS_PER_BAR;
    const deltaTick = pt.tick - drag.startTick;
    const deltaPitch = rowToPitch(pt.row) - drag.startPitch;
    if (Math.abs(deltaTick) > 2 || deltaPitch !== 0) drag.moved = true;

    store.updateEvents(
      pattern.id,
      (p) => {
        for (const o of drag.origin) {
          const note = p.events.find((e) => e.id === o.id);
          if (!note) continue;
          if (drag.mode === 'move') {
            const raw = o.tick + deltaTick;
            note.tick = clamp(snap ? quantizeTick(raw, gridTicks) : Math.round(raw), 0, maxTick - 1);
            note.pitch = clamp(o.pitch + deltaPitch, PITCH_MIN, PITCH_MAX);
          } else {
            const rawEnd = o.tick + o.dur + deltaTick;
            const end = snap ? quantizeTick(rawEnd, gridTicks, 'ceil') : Math.round(rawEnd);
            note.dur = clamp(end - note.tick, Math.max(6, snap ? gridTicks : 6), maxTick);
          }
        }
      },
      drag.mode === 'move' ? 'Noten verschieben' : 'Notenlänge',
    );
  }

  function onUp(pt) {
    if (!drag) return;
    if (drag.mode === 'rect') {
      const x0 = Math.min(drag.x0, drag.x1);
      const x1 = Math.max(drag.x0, drag.x1);
      const y0 = Math.min(drag.y0, drag.y1);
      const y1 = Math.max(drag.y0, drag.y1);
      selection.clear();
      for (const n of notes()) {
        const nx = surface.tickToX(n.tick);
        const nw = n.dur * surface.pxPerTick;
        const ny = surface.rowToY(pitchToRow(n.pitch));
        if (nx + nw >= x0 && nx <= x1 && ny + surface.rowHeight >= y0 && ny <= y1) {
          selection.add(n.id);
        }
      }
    }
    drag = null;
    surface.draw();
    void pt;
  }

  function cancelDrag() {
    drag = null;
    surface.draw();
  }

  function onContext(pt) {
    const pattern = store.selectedPattern;
    if (!pattern || pt.inGutter || pt.inHeader) return;
    const pitch = rowToPitch(pt.row);
    const hit = noteAt(pt.tick, pitch);

    const items = [];
    if (hit) {
      if (!selection.has(hit.id)) {
        selection.clear();
        selection.add(hit.id);
        surface.draw();
      }
      items.push({ heading: `${selection.size} Note(n) – ${noteName(ROOT_MIDI + pitch)}` });
      items.push({ label: 'Kopieren', icon: 'copy', kbd: 'Ctrl+C', onClick: copySelection });
      items.push({ label: 'Duplizieren', icon: 'copy', onClick: duplicateSelection });
      items.push({ label: 'Quantisieren', icon: 'grid', onClick: quantizeSelection });
      items.push({ separator: true });
      items.push({ label: 'Löschen', icon: 'trash', danger: true, kbd: 'Entf', onClick: deleteSelection });
    } else {
      items.push({ heading: noteName(ROOT_MIDI + pitch) });
      items.push({
        label: 'Einfügen',
        icon: 'copy',
        kbd: 'Ctrl+V',
        disabled: !clipboard.length,
        onClick: () => pasteAt(snapTick(pt.tick, 'floor'), pitch),
      });
      items.push({
        label: 'Alle Noten dieser Zeile löschen',
        icon: 'trash',
        onClick: () => {
          const ids = notes().filter((n) => n.pitch === pitch).map((n) => n.id);
          if (ids.length) store.removeEvents(pattern.id, ids, 'Noten löschen');
        },
      });
    }
    openContextMenu({ x: pt.clientX, y: pt.clientY }, items);
  }

  // --- Aktionen --------------------------------------------------------------

  function copySelection() {
    clipboard = notes()
      .filter((n) => selection.has(n.id))
      .map((n) => ({ tick: n.tick, pitch: n.pitch, dur: n.dur, vel: n.vel }));
    if (clipboard.length) toast.info('Kopiert', `${clipboard.length} Note(n)`);
  }

  function pasteAt(tick, pitch) {
    if (!clipboard.length) return;
    const pattern = store.selectedPattern;
    const sample = currentSample();
    if (!pattern || !sample) return;
    const minTick = Math.min(...clipboard.map((c) => c.tick));
    const minPitch = Math.min(...clipboard.map((c) => c.pitch));
    const maxTick = pattern.lengthBars * TICKS_PER_BAR;

    const created = clipboard
      .map((c) =>
        createEvent(sample.id, clamp(tick + (c.tick - minTick), 0, maxTick - 1), {
          dur: c.dur,
          pitch: clamp(pitch + (c.pitch - minPitch), PITCH_MIN, PITCH_MAX),
          vel: c.vel,
        }),
      )
      .filter((n) => n.tick < maxTick);
    if (!created.length) return;
    store.addEvents(pattern.id, created, 'Noten einfügen');
    selection.clear();
    for (const n of created) selection.add(n.id);
    surface.draw();
  }

  function duplicateSelection() {
    const pattern = store.selectedPattern;
    const sel = notes().filter((n) => selection.has(n.id));
    if (!pattern || !sel.length) return;
    const span = Math.max(...sel.map((n) => n.tick + n.dur)) - Math.min(...sel.map((n) => n.tick));
    const maxTick = pattern.lengthBars * TICKS_PER_BAR;
    const created = sel
      .map((n) => createEvent(n.sampleId, n.tick + span, { dur: n.dur, pitch: n.pitch, vel: n.vel }))
      .filter((n) => n.tick < maxTick);
    if (!created.length) {
      toast.warn('Kein Platz', 'Das Pattern ist zu kurz für die Verdopplung.');
      return;
    }
    store.addEvents(pattern.id, created, 'Noten duplizieren');
    selection.clear();
    for (const n of created) selection.add(n.id);
    surface.draw();
  }

  function quantizeSelection() {
    const pattern = store.selectedPattern;
    if (!pattern) return;
    const ids = selection.size ? [...selection] : notes().map((n) => n.id);
    if (!ids.length) return;
    const set = new Set(ids);
    store.updateEvents(pattern.id, (p) => {
      for (const note of p.events) {
        if (set.has(note.id)) note.tick = quantizeTick(note.tick, gridTicks);
      }
    }, 'Quantisieren');
    toast.ok('Quantisiert', `${ids.length} Note(n) auf das Raster gesetzt`);
  }

  function deleteSelection() {
    const pattern = store.selectedPattern;
    if (!pattern || !selection.size) return;
    store.removeEvents(pattern.id, [...selection], 'Noten löschen');
    selection.clear();
    surface.draw();
  }

  // --- Zeichnen --------------------------------------------------------------

  function paint(ctx, s) {
    const pattern = store.selectedPattern;
    const sample = currentSample();
    s.contentTicks = (pattern?.lengthBars || 1) * TICKS_PER_BAR;
    s.contentRows = ROW_COUNT;

    ctx.fillStyle = cssVar('--c-bg-deep');
    ctx.fillRect(0, 0, s.width, s.height);

    if (!pattern || !sample) {
      ctx.fillStyle = cssVar('--c-text-faint');
      ctx.font = '13px system-ui, sans-serif';
      ctx.textAlign = 'center';
      ctx.fillText('Wähle oben ein Instrument aus.', s.width / 2, s.height / 2);
      ctx.textAlign = 'left';
      return;
    }

    // Zeilenhintergrund (schwarze Tasten dunkler)
    const firstRow = Math.max(0, Math.floor(s.scrollY / s.rowHeight));
    const lastRow = Math.min(ROW_COUNT, Math.ceil((s.scrollY + s.viewportH) / s.rowHeight) + 1);
    for (let r = firstRow; r < lastRow; r++) {
      const pitch = PITCH_MAX - r;
      const y = s.rowToY(r);
      const black = isBlackKey(ROOT_MIDI + pitch);
      ctx.fillStyle = black ? cssVar('--c-bg') : cssVar('--c-surface');
      ctx.fillRect(s.gutter, y, s.width - s.gutter, s.rowHeight);
      if (pitch === 0) {
        ctx.fillStyle = withAlpha(cssVar('--c-mint'), 0.12);
        ctx.fillRect(s.gutter, y, s.width - s.gutter, s.rowHeight);
      }
    }

    drawTimeGrid(ctx, s, { ticksPerBar: TICKS_PER_BAR, subdivision: gridTicks, top: s.header });

    // Waagerechte Linien
    ctx.strokeStyle = cssVar('--c-border');
    ctx.lineWidth = 1;
    ctx.beginPath();
    for (let r = firstRow; r < lastRow; r++) {
      const pitch = PITCH_MAX - r;
      if ((ROOT_MIDI + pitch) % 12 !== 0) continue;
      const y = Math.round(s.rowToY(r)) + 0.5;
      ctx.moveTo(s.gutter, y);
      ctx.lineTo(s.width, y);
    }
    ctx.stroke();

    // Noten
    const color = trackColorValue(sample.id);
    for (const note of notes()) {
      const x = s.tickToX(note.tick);
      const w = Math.max(4, note.dur * s.pxPerTick);
      const y = s.rowToY(pitchToRow(note.pitch));
      if (x + w < s.gutter || x > s.width || y + s.rowHeight < s.header || y > s.height) continue;

      const selected = selection.has(note.id);
      ctx.fillStyle = withAlpha(color, 0.4 + note.vel * 0.5);
      roundRect(ctx, x, y + 1.5, w, s.rowHeight - 3, 3);
      ctx.fill();

      if (selected) {
        ctx.strokeStyle = cssVar('--c-text');
        ctx.lineWidth = 1.5;
        ctx.stroke();
      }
    }

    // Auswahlrechteck
    if (drag?.mode === 'rect') {
      const x = Math.min(drag.x0, drag.x1);
      const y = Math.min(drag.y0, drag.y1);
      const w = Math.abs(drag.x1 - drag.x0);
      const hh = Math.abs(drag.y1 - drag.y0);
      ctx.fillStyle = withAlpha(cssVar('--c-accent'), 0.18);
      ctx.fillRect(x, y, w, hh);
      ctx.strokeStyle = cssVar('--c-accent');
      ctx.lineWidth = 1;
      ctx.strokeRect(x + 0.5, y + 0.5, w, hh);
    }

    // Bereich hinter dem Patternende abdunkeln
    drawBeyondEnd(ctx, s, pattern.lengthBars * TICKS_PER_BAR, 0);

    // Klaviatur
    drawKeyboard(ctx, s, firstRow, lastRow);

    if (engine.playing && engine.mode === 'pattern') {
      const maxTick = pattern.lengthBars * TICKS_PER_BAR;
      drawPlayhead(ctx, s, engine.position % Math.max(1, maxTick), { top: 0 });
    }
  }

  function drawKeyboard(ctx, s, firstRow, lastRow) {
    ctx.fillStyle = cssVar('--c-bg-elev');
    ctx.fillRect(0, 0, s.gutter, s.height);

    ctx.font = '9.5px ui-monospace, SFMono-Regular, Menlo, monospace';
    ctx.textBaseline = 'middle';

    for (let r = firstRow; r < lastRow; r++) {
      const pitch = PITCH_MAX - r;
      const y = s.rowToY(r);
      if (y + s.rowHeight < 0 || y > s.height) continue;
      const midi = ROOT_MIDI + pitch;
      const black = isBlackKey(midi);

      ctx.fillStyle = black ? '#15161f' : '#e8e9f2';
      ctx.fillRect(0, y, black ? s.gutter * 0.62 : s.gutter - 1, s.rowHeight - 1);

      if (pitch === 0) {
        ctx.fillStyle = cssVar('--c-mint');
        ctx.fillRect(s.gutter - 4, y, 4, s.rowHeight - 1);
      }

      if (!black) {
        ctx.fillStyle = '#2a2c3a';
        ctx.fillText(noteName(midi), s.gutter * 0.66, y + s.rowHeight / 2);
      }
    }

    ctx.fillStyle = cssVar('--c-bg-elev');
    ctx.fillRect(0, 0, s.gutter, s.header);
    ctx.fillStyle = cssVar('--c-text-faint');
    ctx.font = '9px system-ui, sans-serif';
    ctx.fillText('ORIGINAL = C4', 6, s.header / 2);

    ctx.strokeStyle = cssVar('--c-border-strong');
    ctx.beginPath();
    ctx.moveTo(s.gutter + 0.5, 0);
    ctx.lineTo(s.gutter + 0.5, s.height);
    ctx.stroke();
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

  // --- Tastatur --------------------------------------------------------------

  function onKey(event) {
    const target = event.target;
    if (target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA')) return;
    const mod = event.metaKey || event.ctrlKey;

    if (event.key === 'Delete' || event.key === 'Backspace') {
      if (selection.size) {
        deleteSelection();
        event.preventDefault();
      }
    } else if (mod && event.key.toLowerCase() === 'c') {
      copySelection();
    } else if (mod && event.key.toLowerCase() === 'v') {
      const sel = notes().filter((n) => selection.has(n.id));
      const tick = sel.length ? Math.max(...sel.map((n) => n.tick + n.dur)) : 0;
      pasteAt(tick, clipboard[0]?.pitch ?? 0);
    } else if (mod && event.key.toLowerCase() === 'd') {
      duplicateSelection();
      event.preventDefault();
    } else if (mod && event.key.toLowerCase() === 'a') {
      selection.clear();
      for (const n of notes()) selection.add(n.id);
      surface.draw();
      event.preventDefault();
    }
  }

  // --- Auswahlliste ----------------------------------------------------------

  function syncSamples() {
    const pattern = store.selectedPattern;
    const list = pattern
      ? pattern.rows.map((id) => store.getSample(id)).filter(Boolean)
      : store.project.samples;
    const pool = list.length ? list : store.project.samples;

    clear(sampleSelect);
    for (const s of pool) {
      sampleSelect.appendChild(h('option', { value: s.id, text: s.name }));
    }
    const current = currentSample();
    if (current) sampleSelect.value = current.id;
    surface.draw();
  }

  function tickLoop() {
    if (engine.playing && engine.mode === 'pattern') surface.draw();
    playheadRaf = requestAnimationFrame(tickLoop);
  }

  const offPatterns = bus.on(EV.PATTERNS_CHANGED, () => {
    syncSamples();
    surface.draw();
  });
  const offSelected = bus.on(EV.PATTERN_SELECTED, syncSamples);
  const offSamples = bus.on(EV.SAMPLES_CHANGED, syncSamples);
  const offSelection = bus.on(EV.SELECTION_CHANGED, () => {
    const current = currentSample();
    if (current) sampleSelect.value = current.id;
    surface.draw();
  });
  const offLoaded = bus.on(EV.PROJECT_LOADED, syncSamples);

  syncSamples();

  return {
    element,
    onShow() {
      syncSamples();
      window.addEventListener('keydown', onKey);
      cancelAnimationFrame(playheadRaf);
      playheadRaf = requestAnimationFrame(tickLoop);
    },
    onHide() {
      window.removeEventListener('keydown', onKey);
      cancelAnimationFrame(playheadRaf);
    },
    destroy() {
      this.onHide();
      offPatterns();
      offSelected();
      offSamples();
      offSelection();
      offLoaded();
      surface.destroy();
    },
  };
}
