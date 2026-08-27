/**
 * AI Groove – Arrangement / Playlist.
 *
 * Senkrecht Spuren, waagerecht Zeit. Pattern- und Audio-Clips lassen sich
 * ziehen, kopieren, trimmen, schneiden, loopen und mehrfach auswählen.
 *
 * Die Zeitachse darf sehr lang werden: gezeichnet wird ausschliesslich der
 * sichtbare Ausschnitt, es entstehen keine DOM-Elemente pro Rasterzelle.
 */

import { h, icon, clear, cssVar, trackColorValue, withAlpha, segmented } from '../core/dom.js';
import { store } from '../core/store.js';
import { bus, EV } from '../core/bus.js';
import { engine } from '../audio/engine.js';
import { GridSurface, drawTimeGrid, drawPlayhead } from './gridbase.js';
import { makeDropTarget } from './dragdrop.js';
import { openContextMenu } from './contextmenu.js';
import { promptModal, confirmModal } from './modal.js';
import { toast } from './toast.js';
import { createClip } from '../core/project.js';
import { peekPeaks, getPeaks, resamplePeaks } from '../audio/peaks.js';
import { samples as sampleCache } from '../audio/sampler.js';
import { getContext } from '../audio/context.js';
import { TICKS_PER_BAR, clamp, quantizeTick, haptic, secondsToTicks } from '../core/util.js';

const SNAP_OPTIONS = [
  { label: '1 Takt', value: TICKS_PER_BAR },
  { label: '1/2', value: TICKS_PER_BAR / 2 },
  { label: '1/4', value: TICKS_PER_BAR / 4 },
  { label: '1/8', value: TICKS_PER_BAR / 8 },
  { label: 'frei', value: 0 },
];

export function createArrangementView() {
  const element = h('div.view.view--arrange');
  const holder = h('div.view__body');
  element.appendChild(holder);

  const root = h('div.grid-view');
  holder.appendChild(root);

  const toolbar = h('div.grid-toolbar');
  root.appendChild(toolbar);

  let snapTicks = TICKS_PER_BAR;
  const snapSeg = segmented(SNAP_OPTIONS, snapTicks, (value) => {
    snapTicks = value;
  });
  toolbar.appendChild(snapSeg);

  const patSelect = h('select.select', { style: { maxWidth: '160px', minHeight: '34px' } });
  toolbar.appendChild(patSelect);

  const insertBtn = h('button.btn.btn--sm', { type: 'button', title: 'Pattern an der Abspielposition einfügen' });
  insertBtn.appendChild(icon('plus', 15));
  insertBtn.appendChild(h('span', { text: 'Einfügen' }));
  insertBtn.addEventListener('click', () => insertPatternAtPlayhead());
  toolbar.appendChild(insertBtn);

  toolbar.appendChild(h('div.grid-toolbar__spacer'));

  const laneBtn = h('button.btn.btn--sm.btn--icon', { type: 'button', title: 'Spur hinzufügen' });
  laneBtn.appendChild(icon('plus', 15));
  laneBtn.addEventListener('click', () => store.addLane());
  toolbar.appendChild(laneBtn);

  const splitBtn = h('button.btn.btn--sm.btn--icon', { type: 'button', title: 'An Abspielposition schneiden' });
  splitBtn.appendChild(icon('scissors', 15));
  splitBtn.addEventListener('click', () => splitAtPlayhead());
  toolbar.appendChild(splitBtn);

  const delBtn = h('button.btn.btn--sm.btn--icon', { type: 'button', title: 'Auswahl löschen' });
  delBtn.appendChild(icon('trash', 15));
  delBtn.addEventListener('click', () => deleteSelection());
  toolbar.appendChild(delBtn);

  // --- Fläche ----------------------------------------------------------------
  const surface = new GridSurface({
    gutter: 132,
    header: 28,
    rowHeight: 64,
    pxPerTick: 0.09,
    minPxPerTick: 0.006,
    maxPxPerTick: 1.2,
    draw: (ctx, s) => paint(ctx, s),
    onPointerDown: (pt, event) => onDown(pt, event),
    onPointerMove: (pt, event) => onMove(pt, event),
    onPointerUp: (pt) => onUp(pt),
    onPointerCancel: () => {
      drag = null;
      surface.draw();
    },
    onContextMenu: (pt) => onContext(pt),
    onDoubleClick: (pt) => onDoubleClick(pt),
  });
  root.appendChild(surface.element);
  if (window.matchMedia('(max-width: 760px)').matches) surface.gutter = 96;

  makeDropTarget(surface.element, {
    accepts: (payload) => payload?.type === 'sample' || payload?.type === 'pattern',
    onDrop: (payload, x, y) => {
      const rect = surface.element.getBoundingClientRect();
      const local = { x: x - rect.left, y: y - rect.top };
      const laneIndex = clamp(Math.floor(surface.yToRow(local.y)), 0, store.project.lanes.length - 1);
      const lane = store.project.lanes[laneIndex];
      if (!lane) return;
      const tick = Math.max(0, snapValue(surface.xToTick(local.x)));

      if (payload.type === 'pattern') {
        const pattern = store.getPattern(payload.patternId);
        if (!pattern) return;
        store.addClip(
          createClip(lane.id, 'pattern', pattern.id, tick, pattern.lengthBars * TICKS_PER_BAR, { loop: true }),
        );
        toast.ok('Pattern eingefügt', pattern.name);
      } else {
        const sample = store.getSample(payload.sampleId);
        if (!sample) return;
        const lengthTicks = Math.max(
          24,
          Math.round(secondsToTicks(sample.duration, store.project.bpm)),
        );
        store.addClip(createClip(lane.id, 'audio', sample.id, tick, lengthTicks, { loop: false }));
        toast.ok('Audio-Clip eingefügt', sample.name);
      }
      surface.draw();
    },
  });

  // --- Zustand ---------------------------------------------------------------
  const selection = new Set();
  let drag = null;
  let clipboard = [];
  let raf = 0;

  function snapValue(tick, mode = 'nearest') {
    if (!snapTicks) return Math.round(tick);
    return quantizeTick(tick, snapTicks, mode);
  }

  function lanes() {
    return store.project.lanes;
  }

  function clipAt(tick, laneIndex) {
    const lane = lanes()[laneIndex];
    if (!lane) return null;
    return (
      store.project.clips.find(
        (c) => c.laneId === lane.id && tick >= c.start && tick < c.start + c.length,
      ) || null
    );
  }

  function clipLabel(clip) {
    if (clip.type === 'pattern') return store.getPattern(clip.refId)?.name || 'Pattern';
    return store.getSample(clip.refId)?.name || 'Audio';
  }

  function clipColor(clip) {
    if (clip.type === 'pattern') {
      const pattern = store.getPattern(clip.refId);
      return cssVar(`--t${pattern?.colorIdx || 1}`) || '#7d5cff';
    }
    return trackColorValue(clip.refId);
  }

  // --- Eingaben --------------------------------------------------------------

  function onDown(pt, event) {
    if (pt.inHeader) {
      if (event.shiftKey) {
        drag = { mode: 'loop', start: Math.max(0, snapValue(pt.tick, 'floor')) };
        return;
      }
      engine.seek(Math.max(0, snapValue(pt.tick)));
      surface.draw();
      return;
    }

    if (pt.inGutter) {
      const laneIndex = Math.floor(pt.row);
      const lane = lanes()[laneIndex];
      if (lane) laneMenu(lane, { x: pt.clientX, y: pt.clientY });
      return;
    }

    const laneIndex = Math.floor(pt.row);
    const clip = clipAt(pt.tick, laneIndex);

    if (clip) {
      const rightEdge = surface.tickToX(clip.start + clip.length);
      const leftEdge = surface.tickToX(clip.start);
      const mode =
        Math.abs(pt.x - rightEdge) < 12 ? 'resize-r' : Math.abs(pt.x - leftEdge) < 12 ? 'resize-l' : 'move';

      if (!selection.has(clip.id) && !event.shiftKey) selection.clear();
      selection.add(clip.id);

      // Alt/Option beim Ziehen erzeugt eine Kopie.
      if (event.altKey && mode === 'move') {
        const copies = store.project.clips
          .filter((c) => selection.has(c.id))
          .map((c) => createClip(c.laneId, c.type, c.refId, c.start, c.length, {
            offset: c.offset,
            loop: c.loop,
            gain: c.gain,
            pitch: c.pitch,
          }));
        store.updateClips((clips) => clips.push(...copies), 'Clips kopieren');
        selection.clear();
        for (const c of copies) selection.add(c.id);
      }

      drag = {
        mode,
        startTick: pt.tick,
        startRow: pt.row,
        origin: store.project.clips
          .filter((c) => selection.has(c.id))
          .map((c) => ({ id: c.id, start: c.start, length: c.length, laneId: c.laneId, offset: c.offset })),
      };
      haptic(7);
      surface.draw();
      return;
    }

    selection.clear();
    drag = { mode: 'rect', x0: pt.x, y0: pt.y, x1: pt.x, y1: pt.y };
    surface.draw();
  }

  function onMove(pt) {
    if (!drag) return;

    if (drag.mode === 'rect') {
      drag.x1 = pt.x;
      drag.y1 = pt.y;
      surface.draw();
      return;
    }

    if (drag.mode === 'loop') {
      const end = Math.max(drag.start + TICKS_PER_BAR, snapValue(pt.tick, 'ceil'));
      store.setLoop({ enabled: true, start: drag.start, end });
      surface.draw();
      return;
    }

    const deltaTick = pt.tick - drag.startTick;
    const deltaRow = Math.round(pt.row - drag.startRow);
    const laneList = lanes();

    store.updateClips((clips) => {
      for (const o of drag.origin) {
        const clip = clips.find((c) => c.id === o.id);
        if (!clip) continue;

        if (drag.mode === 'move') {
          clip.start = Math.max(0, snapValue(o.start + deltaTick, snapTicks ? 'nearest' : 'nearest'));
          const fromIndex = laneList.findIndex((l) => l.id === o.laneId);
          const toIndex = clamp(fromIndex + deltaRow, 0, laneList.length - 1);
          clip.laneId = laneList[toIndex].id;
        } else if (drag.mode === 'resize-r') {
          const end = snapValue(o.start + o.length + deltaTick, 'nearest');
          clip.length = Math.max(snapTicks || 24, end - clip.start);
        } else if (drag.mode === 'resize-l') {
          const newStart = clamp(snapValue(o.start + deltaTick), 0, o.start + o.length - (snapTicks || 24));
          const diff = newStart - o.start;
          clip.start = newStart;
          clip.length = o.length - diff;
          if (clip.type === 'audio') clip.offset = Math.max(0, o.offset + diff);
        }
      }
    }, drag.mode === 'move' ? 'Clips verschieben' : 'Clip trimmen');
  }

  function onUp(pt) {
    if (!drag) return;
    if (drag.mode === 'rect') {
      const x0 = Math.min(drag.x0, drag.x1);
      const x1 = Math.max(drag.x0, drag.x1);
      const y0 = Math.min(drag.y0, drag.y1);
      const y1 = Math.max(drag.y0, drag.y1);
      const laneList = lanes();
      selection.clear();
      for (const clip of store.project.clips) {
        const laneIndex = laneList.findIndex((l) => l.id === clip.laneId);
        if (laneIndex < 0) continue;
        const cx = surface.tickToX(clip.start);
        const cw = clip.length * surface.pxPerTick;
        const cy = surface.rowToY(laneIndex);
        if (cx + cw >= x0 && cx <= x1 && cy + surface.rowHeight >= y0 && cy <= y1) selection.add(clip.id);
      }
    }
    drag = null;
    surface.draw();
    void pt;
  }

  function onDoubleClick(pt) {
    if (pt.inGutter || pt.inHeader) return;
    const clip = clipAt(pt.tick, Math.floor(pt.row));
    if (!clip) return;
    if (clip.type === 'pattern') {
      store.selectPattern(clip.refId);
      bus.emit(EV.SELECTION_CHANGED, { view: 'seq' });
    } else {
      store.setUi({ selectedSampleId: clip.refId });
      bus.emit(EV.SELECTION_CHANGED, { sampleId: clip.refId });
    }
  }

  function onContext(pt) {
    if (pt.inHeader) {
      openContextMenu({ x: pt.clientX, y: pt.clientY }, [
        { heading: 'Zeitleiste' },
        {
          label: store.project.loop.enabled ? 'Loop ausschalten' : 'Loop einschalten',
          icon: 'loop',
          onClick: () => store.setLoop({ enabled: !store.project.loop.enabled }),
        },
        {
          label: 'Loop auf 8 Takte ab hier',
          icon: 'loop',
          onClick: () => {
            const start = Math.max(0, snapValue(pt.tick, 'floor'));
            store.setLoop({ enabled: true, start, end: start + TICKS_PER_BAR * 8 });
            surface.draw();
          },
        },
      ]);
      return;
    }
    if (pt.inGutter) return;

    const clip = clipAt(pt.tick, Math.floor(pt.row));
    if (!clip) {
      openContextMenu({ x: pt.clientX, y: pt.clientY }, [
        {
          label: 'Einfügen',
          icon: 'copy',
          disabled: !clipboard.length,
          onClick: () => pasteAt(snapValue(pt.tick, 'floor'), Math.floor(pt.row)),
        },
      ]);
      return;
    }

    if (!selection.has(clip.id)) {
      selection.clear();
      selection.add(clip.id);
      surface.draw();
    }

    openContextMenu({ x: pt.clientX, y: pt.clientY }, [
      { heading: clipLabel(clip) },
      { label: 'Kopieren', icon: 'copy', kbd: 'Ctrl+C', onClick: copySelection },
      { label: 'Duplizieren', icon: 'copy', kbd: 'Ctrl+D', onClick: duplicateSelection },
      {
        label: 'Hier schneiden',
        icon: 'scissors',
        onClick: () => splitClip(clip.id, Math.round(pt.tick)),
      },
      {
        label: clip.loop ? 'Loop aus' : 'Loop an',
        icon: 'loop',
        onClick: () =>
          store.updateClips((clips) => {
            const c = clips.find((x) => x.id === clip.id);
            if (c) c.loop = !c.loop;
          }, 'Clip-Loop'),
      },
      { separator: true },
      { label: 'Löschen', icon: 'trash', danger: true, kbd: 'Entf', onClick: deleteSelection },
    ]);
  }

  function laneMenu(lane, position) {
    openContextMenu(position, [
      { heading: lane.name },
      {
        label: 'Umbenennen',
        icon: 'edit',
        onClick: async () => {
          const name = await promptModal({ title: 'Spur umbenennen', label: 'Name', value: lane.name });
          if (name) store.renameLane(lane.id, name);
        },
      },
      {
        label: 'Spur leeren',
        icon: 'trash',
        onClick: () => {
          const ids = store.project.clips.filter((c) => c.laneId === lane.id).map((c) => c.id);
          if (ids.length) store.removeClips(ids, 'Spur leeren');
        },
      },
      { separator: true },
      {
        label: 'Spur entfernen',
        icon: 'close',
        danger: true,
        disabled: lanes().length <= 1,
        onClick: async () => {
          const ok = await confirmModal({
            title: 'Spur entfernen?',
            message: `„${lane.name}“ und alle Clips darauf werden gelöscht.`,
            confirmLabel: 'Entfernen',
            danger: true,
          });
          if (ok) store.removeLane(lane.id);
        },
      },
    ]);
  }

  // --- Aktionen --------------------------------------------------------------

  function insertPatternAtPlayhead() {
    const patternId = patSelect.value;
    const pattern = store.getPattern(patternId);
    if (!pattern) {
      toast.warn('Kein Pattern', 'Lege zuerst im Sequencer ein Pattern an.');
      return;
    }
    const lane = lanes()[0];
    const start = Math.max(0, snapValue(engine.position, 'floor'));
    store.addClip(createClip(lane.id, 'pattern', pattern.id, start, pattern.lengthBars * TICKS_PER_BAR, { loop: true }));
    surface.draw();
    toast.ok('Eingefügt', `${pattern.name} ab Takt ${Math.floor(start / TICKS_PER_BAR) + 1}`);
  }

  function splitAtPlayhead() {
    const tick = Math.round(engine.position);
    const targets = store.project.clips.filter(
      (c) => tick > c.start && tick < c.start + c.length && (!selection.size || selection.has(c.id)),
    );
    if (!targets.length) {
      toast.warn('Nichts zu schneiden', 'Der Abspielkopf liegt auf keinem Clip.');
      return;
    }
    for (const clip of targets) splitClip(clip.id, tick);
  }

  function splitClip(clipId, tick) {
    store.updateClips((clips) => {
      const clip = clips.find((c) => c.id === clipId);
      if (!clip || tick <= clip.start || tick >= clip.start + clip.length) return;
      const rightLength = clip.start + clip.length - tick;
      const right = createClip(clip.laneId, clip.type, clip.refId, tick, rightLength, {
        offset: clip.type === 'audio' ? clip.offset + (tick - clip.start) : (tick - clip.start) % Math.max(1, clip.length),
        loop: clip.loop,
        gain: clip.gain,
        pitch: clip.pitch,
      });
      clip.length = tick - clip.start;
      clips.push(right);
    }, 'Clip schneiden');
    surface.draw();
  }

  function copySelection() {
    clipboard = store.project.clips
      .filter((c) => selection.has(c.id))
      .map((c) => ({ ...c }));
    if (clipboard.length) toast.info('Kopiert', `${clipboard.length} Clip(s)`);
  }

  function pasteAt(tick, laneIndex) {
    if (!clipboard.length) return;
    const laneList = lanes();
    const minStart = Math.min(...clipboard.map((c) => c.start));
    const baseLane = laneList.findIndex((l) => l.id === clipboard[0].laneId);
    const created = clipboard.map((c) => {
      const offsetLane = laneList.findIndex((l) => l.id === c.laneId) - (baseLane < 0 ? 0 : baseLane);
      const target = laneList[clamp(laneIndex + offsetLane, 0, laneList.length - 1)];
      return createClip(target.id, c.type, c.refId, tick + (c.start - minStart), c.length, {
        offset: c.offset,
        loop: c.loop,
        gain: c.gain,
        pitch: c.pitch,
      });
    });
    store.updateClips((clips) => clips.push(...created), 'Clips einfügen');
    selection.clear();
    for (const c of created) selection.add(c.id);
    surface.draw();
  }

  function duplicateSelection() {
    const sel = store.project.clips.filter((c) => selection.has(c.id));
    if (!sel.length) return;
    const minStart = Math.min(...sel.map((c) => c.start));
    const maxEnd = Math.max(...sel.map((c) => c.start + c.length));
    const span = maxEnd - minStart;
    const created = sel.map((c) =>
      createClip(c.laneId, c.type, c.refId, c.start + span, c.length, {
        offset: c.offset,
        loop: c.loop,
        gain: c.gain,
        pitch: c.pitch,
      }),
    );
    store.updateClips((clips) => clips.push(...created), 'Clips duplizieren');
    selection.clear();
    for (const c of created) selection.add(c.id);
    surface.draw();
  }

  function deleteSelection() {
    if (!selection.size) return;
    store.removeClips([...selection]);
    selection.clear();
    surface.draw();
  }

  // --- Zeichnen --------------------------------------------------------------

  function paint(ctx, s) {
    const project = store.project;
    const laneList = lanes();
    let endTick = TICKS_PER_BAR * 16;
    for (const c of project.clips) endTick = Math.max(endTick, c.start + c.length);
    s.contentTicks = endTick + TICKS_PER_BAR * 8;
    s.contentRows = Math.max(laneList.length, 1);

    ctx.fillStyle = cssVar('--c-bg-deep');
    ctx.fillRect(0, 0, s.width, s.height);

    // Spurhintergrund
    for (let i = 0; i < laneList.length; i++) {
      const y = s.rowToY(i);
      if (y + s.rowHeight < s.header || y > s.height) continue;
      ctx.fillStyle = i % 2 === 0 ? cssVar('--c-surface') : 'transparent';
      ctx.fillRect(s.gutter, y, s.width - s.gutter, s.rowHeight);
      ctx.strokeStyle = cssVar('--c-border');
      ctx.beginPath();
      ctx.moveTo(s.gutter, Math.round(y + s.rowHeight) + 0.5);
      ctx.lineTo(s.width, Math.round(y + s.rowHeight) + 0.5);
      ctx.stroke();
    }

    drawTimeGrid(ctx, s, {
      ticksPerBar: TICKS_PER_BAR,
      subdivision: snapTicks || TICKS_PER_BAR / 4,
      top: s.header,
    });

    // Loop-Bereich
    if (project.loop.enabled) {
      const x0 = s.tickToX(project.loop.start);
      const x1 = s.tickToX(project.loop.end);
      ctx.fillStyle = withAlpha(cssVar('--c-mint'), 0.1);
      ctx.fillRect(Math.max(s.gutter, x0), s.header, Math.max(0, x1 - Math.max(s.gutter, x0)), s.height);
      ctx.fillStyle = cssVar('--c-mint');
      ctx.fillRect(Math.max(s.gutter, x0), 0, Math.max(0, x1 - Math.max(s.gutter, x0)), 3);
    }

    // Clips
    for (const clip of project.clips) {
      const laneIndex = laneList.findIndex((l) => l.id === clip.laneId);
      if (laneIndex < 0) continue;
      const y = s.rowToY(laneIndex);
      if (y + s.rowHeight < s.header || y > s.height) continue;
      const x = s.tickToX(clip.start);
      const w = clip.length * s.pxPerTick;
      if (x + w < s.gutter || x > s.width) continue;

      const color = clipColor(clip);
      const selected = selection.has(clip.id);
      const cx = Math.max(x, s.gutter);
      const cw = Math.max(2, x + w - cx);

      ctx.save();
      ctx.beginPath();
      ctx.rect(s.gutter, s.header, s.width - s.gutter, s.height - s.header);
      ctx.clip();

      roundRect(ctx, cx, y + 3, cw, s.rowHeight - 8, 5);
      ctx.fillStyle = withAlpha(color, selected ? 0.5 : 0.32);
      ctx.fill();
      ctx.strokeStyle = selected ? cssVar('--c-text') : withAlpha(color, 0.85);
      ctx.lineWidth = selected ? 2 : 1;
      ctx.stroke();

      // Farbstreifen links
      ctx.fillStyle = withAlpha(color, 1);
      ctx.fillRect(cx, y + 3, 3, s.rowHeight - 8);

      // Inhalt
      if (clip.type === 'audio') {
        drawClipWave(ctx, clip, cx, y + 12, cw, s.rowHeight - 26, color);
      } else {
        drawClipPattern(ctx, clip, cx, y + 14, cw, s.rowHeight - 28, color);
      }

      // Beschriftung
      ctx.fillStyle = cssVar('--c-text');
      ctx.font = '11px system-ui, sans-serif';
      ctx.textBaseline = 'top';
      const label = fitText(ctx, clipLabel(clip), cw - 12);
      ctx.fillText(label, cx + 7, y + 6);

      ctx.restore();
    }

    // Auswahlrechteck
    if (drag?.mode === 'rect') {
      const x = Math.min(drag.x0, drag.x1);
      const y = Math.min(drag.y0, drag.y1);
      const w = Math.abs(drag.x1 - drag.x0);
      const hh = Math.abs(drag.y1 - drag.y0);
      ctx.fillStyle = withAlpha(cssVar('--c-accent'), 0.16);
      ctx.fillRect(x, y, w, hh);
      ctx.strokeStyle = cssVar('--c-accent');
      ctx.lineWidth = 1;
      ctx.strokeRect(x + 0.5, y + 0.5, w, hh);
    }

    // Spuren-Spalte
    ctx.fillStyle = cssVar('--c-bg-elev');
    ctx.fillRect(0, 0, s.gutter, s.height);
    ctx.strokeStyle = cssVar('--c-border-strong');
    ctx.beginPath();
    ctx.moveTo(s.gutter + 0.5, 0);
    ctx.lineTo(s.gutter + 0.5, s.height);
    ctx.stroke();

    ctx.font = '12px system-ui, sans-serif';
    ctx.textBaseline = 'middle';
    for (let i = 0; i < laneList.length; i++) {
      const lane = laneList[i];
      const y = s.rowToY(i);
      if (y + s.rowHeight < s.header || y > s.height) continue;
      ctx.fillStyle = cssVar(`--t${lane.colorIdx}`);
      ctx.fillRect(5, y + 10, 3, s.rowHeight - 20);
      ctx.fillStyle = cssVar('--c-text');
      ctx.fillText(fitText(ctx, lane.name, s.gutter - 22), 15, y + s.rowHeight / 2);
    }

    ctx.fillStyle = cssVar('--c-bg-elev');
    ctx.fillRect(0, 0, s.gutter, s.header);
    ctx.fillStyle = cssVar('--c-text-faint');
    ctx.font = '10px system-ui, sans-serif';
    ctx.fillText('TAKT', 10, s.header / 2);

    if (!project.clips.length) {
      ctx.fillStyle = cssVar('--c-text-faint');
      ctx.font = '13px system-ui, sans-serif';
      ctx.textAlign = 'center';
      ctx.fillText(
        'Ziehe ein Pattern oder Sample hierher – oder nutze „Einfügen“.',
        s.gutter + (s.width - s.gutter) / 2,
        s.height / 2,
      );
      ctx.textAlign = 'left';
    }

    if (engine.mode === 'song') drawPlayhead(ctx, s, engine.position, { top: 0 });
  }

  function drawClipWave(ctx, clip, x, y, w, hgt, color) {
    const sample = store.getSample(clip.refId);
    if (!sample || w < 8) return;
    let peaks = peekPeaks(sample.dataId);
    if (!peaks) {
      sampleCache
        .load(getContext(), sample.dataId)
        .then((buffer) => getPeaks(sample.dataId, buffer))
        .then(() => surface.draw())
        .catch(() => {});
      return;
    }
    const data = resamplePeaks(peaks, Math.floor(w), 0, 1);
    ctx.fillStyle = withAlpha(color, 0.75);
    const mid = y + hgt / 2;
    for (let i = 0; i < data.min.length; i++) {
      const hi = clamp(data.max[i], -1, 1);
      const lo = clamp(data.min[i], -1, 1);
      const top = mid - hi * (hgt / 2);
      const bottom = mid - lo * (hgt / 2);
      ctx.fillRect(x + i, top, 1, Math.max(1, bottom - top));
    }
  }

  function drawClipPattern(ctx, clip, x, y, w, hgt, color) {
    const pattern = store.getPattern(clip.refId);
    if (!pattern || !pattern.events.length || w < 10) return;
    const patLen = pattern.lengthBars * TICKS_PER_BAR;
    const rowsCount = Math.max(1, pattern.rows.length);
    const repeats = clip.loop ? Math.ceil(clip.length / patLen) : 1;

    ctx.fillStyle = withAlpha(color, 0.85);
    for (let r = 0; r < repeats; r++) {
      for (const ev of pattern.events) {
        const tick = r * patLen + ev.tick - clip.offset;
        if (tick < 0 || tick >= clip.length) continue;
        const rowIndex = Math.max(0, pattern.rows.indexOf(ev.sampleId));
        const px = x + tick * surface.pxPerTick;
        if (px < x || px > x + w) continue;
        const py = y + (rowIndex / rowsCount) * hgt;
        ctx.fillRect(px, py, 2, Math.max(1.5, hgt / rowsCount - 1));
      }
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
    if (maxWidth <= 6) return '';
    if (ctx.measureText(text).width <= maxWidth) return text;
    let cut = text;
    while (cut.length > 1 && ctx.measureText(`${cut}…`).width > maxWidth) cut = cut.slice(0, -1);
    return `${cut}…`;
  }

  // --- Tastatur --------------------------------------------------------------

  function onKey(event) {
    const target = event.target;
    if (target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.tagName === 'SELECT')) return;
    const mod = event.metaKey || event.ctrlKey;

    if (event.key === 'Delete' || event.key === 'Backspace') {
      if (selection.size) {
        deleteSelection();
        event.preventDefault();
      }
    } else if (mod && event.key.toLowerCase() === 'c') copySelection();
    else if (mod && event.key.toLowerCase() === 'v') {
      const laneIndex = 0;
      pasteAt(Math.max(0, snapValue(engine.position, 'floor')), laneIndex);
    } else if (mod && event.key.toLowerCase() === 'd') {
      duplicateSelection();
      event.preventDefault();
    } else if (mod && event.key.toLowerCase() === 'a') {
      selection.clear();
      for (const c of store.project.clips) selection.add(c.id);
      surface.draw();
      event.preventDefault();
    }
  }

  function syncPatterns() {
    clear(patSelect);
    for (const p of store.project.patterns) {
      patSelect.appendChild(h('option', { value: p.id, text: p.name }));
    }
    if (store.project.ui.selectedPatternId) patSelect.value = store.project.ui.selectedPatternId;
  }

  function loop() {
    if (engine.playing && engine.mode === 'song') {
      surface.draw();
      surface.ensureTickVisible(engine.position);
    }
    raf = requestAnimationFrame(loop);
  }

  const offClips = bus.on(EV.CLIPS_CHANGED, () => surface.draw());
  const offPatterns = bus.on(EV.PATTERNS_CHANGED, () => {
    syncPatterns();
    surface.draw();
  });
  const offSamples = bus.on(EV.SAMPLES_CHANGED, () => surface.draw());
  const offTransport = bus.on(EV.TRANSPORT_CHANGED, () => surface.draw());
  const offLoaded = bus.on(EV.PROJECT_LOADED, () => {
    syncPatterns();
    surface.draw();
  });

  syncPatterns();

  return {
    element,
    onShow() {
      syncPatterns();
      surface.draw();
      window.addEventListener('keydown', onKey);
      cancelAnimationFrame(raf);
      raf = requestAnimationFrame(loop);
    },
    onHide() {
      window.removeEventListener('keydown', onKey);
      cancelAnimationFrame(raf);
    },
    destroy() {
      this.onHide();
      offClips();
      offPatterns();
      offSamples();
      offTransport();
      offLoaded();
      surface.destroy();
    },
  };
}
