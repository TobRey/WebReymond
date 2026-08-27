/**
 * AI Groove – Waveform-Editor.
 *
 * Arbeitet nicht-destruktiv: alle Schritte laufen auf einer Arbeitskopie im
 * Speicher. Erst „Übernehmen“ schreibt das Ergebnis als neue WAV-Datei in den
 * lokalen Speicher. „Abbrechen“ lässt das Original unangetastet.
 */

import { h, icon, setupCanvas, cssVar, trackColorValue, withAlpha, slider } from '../core/dom.js';
import { openModal, confirmModal } from './modal.js';
import { toast, reportError } from './toast.js';
import { store } from '../core/store.js';
import { bus, EV } from '../core/bus.js';
import { engine } from '../audio/engine.js';
import { getContext } from '../audio/context.js';
import { samples as sampleCache } from '../audio/sampler.js';
import { invalidatePeaks } from '../audio/peaks.js';
import { encodeWav } from '../audio/wav.js';
import {
  computePeaks,
  sliceBuffer,
  deleteRange,
  applyFade,
  applyGain,
  normalizeBuffer,
  reverseBuffer,
  trimSilence,
  copyBuffer,
  peakOf,
} from '../audio/dsp.js';
import { formatTime, clamp, gainToDb } from '../core/util.js';

/** Maximale Anzahl an Arbeitskopien im Verlauf (Speicherschutz). */
const HISTORY_LIMIT = 12;

export async function openSampleEditor(sampleId) {
  const sample = store.getSample(sampleId);
  if (!sample) return;

  const ctx = getContext();
  let original;
  try {
    original = await sampleCache.load(ctx, sample.dataId);
  } catch (err) {
    reportError('Sample konnte nicht geladen werden', err);
    return;
  }

  let working = copyBuffer(ctx, original);
  const past = [];
  const future = [];

  // Ansicht
  let viewFrom = 0;
  let viewTo = 1;
  let selFrom = null;
  let selTo = null;
  let playVoice = null;
  let playStart = 0;
  let playOffset = 0;
  let raf = 0;
  let peaks = computePeaks(working, 2048);

  // --- Aufbau ----------------------------------------------------------------
  const body = h('div.sed');

  const canvasWrap = h('div.sed__canvas-wrap');
  const canvas = h('canvas.sed__canvas');
  canvasWrap.appendChild(canvas);
  body.appendChild(canvasWrap);

  const info = h('div.sed__info');
  body.appendChild(info);

  const transport = h('div.sed__tools');
  const playBtn = h('button.btn.btn--sm', { type: 'button' });
  playBtn.appendChild(icon('play', 15));
  playBtn.appendChild(h('span', { text: 'Abspielen' }));
  playBtn.addEventListener('click', togglePlay);
  transport.appendChild(playBtn);

  const selBtn = h('button.btn.btn--sm', { type: 'button', text: 'Auswahl aufheben' });
  selBtn.addEventListener('click', () => {
    selFrom = null;
    selTo = null;
    paint();
  });
  transport.appendChild(selBtn);

  const allBtn = h('button.btn.btn--sm', { type: 'button', text: 'Alles auswählen' });
  allBtn.addEventListener('click', () => {
    selFrom = 0;
    selTo = 1;
    paint();
  });
  transport.appendChild(allBtn);

  const zoomIn = h('button.btn.btn--sm.btn--icon', { type: 'button', text: '+', title: 'Hineinzoomen' });
  const zoomOut = h('button.btn.btn--sm.btn--icon', { type: 'button', text: '−', title: 'Herauszoomen' });
  const zoomFit = h('button.btn.btn--sm', { type: 'button', text: 'Ganzes Sample' });
  zoomIn.addEventListener('click', () => zoom(0.7));
  zoomOut.addEventListener('click', () => zoom(1 / 0.7));
  zoomFit.addEventListener('click', () => {
    viewFrom = 0;
    viewTo = 1;
    paint();
  });
  transport.appendChild(zoomIn);
  transport.appendChild(zoomOut);
  transport.appendChild(zoomFit);
  body.appendChild(transport);

  const tools = h('div.sed__tools');
  body.appendChild(tools);

  function toolButton(label, iconName, onClick, title) {
    const btn = h('button.btn.btn--sm', { type: 'button', title: title || label });
    if (iconName) btn.appendChild(icon(iconName, 15));
    btn.appendChild(h('span', { text: label }));
    btn.addEventListener('click', onClick);
    tools.appendChild(btn);
    return btn;
  }

  toolButton('Trimmen', 'scissors', () => {
    if (!hasSelection()) {
      toast.warn('Keine Auswahl', 'Markiere zuerst einen Bereich in der Wellenform.');
      return;
    }
    const [a, b] = selRange();
    apply(sliceBuffer(ctx, working, a, b), 'Trimmen');
    selFrom = null;
    selTo = null;
    viewFrom = 0;
    viewTo = 1;
  });

  toolButton('Auswahl löschen', 'trash', () => {
    if (!hasSelection()) {
      toast.warn('Keine Auswahl', 'Markiere zuerst einen Bereich.');
      return;
    }
    const [a, b] = selRange();
    apply(deleteRange(ctx, working, a, b), 'Bereich löschen');
    selFrom = null;
    selTo = null;
  });

  toolButton('Fade In', null, () => {
    const [a, b] = hasSelection() ? selRange() : [0, Math.floor(working.length * 0.1)];
    apply(applyFade(ctx, working, a, b, 'in'), 'Fade In');
  });

  toolButton('Fade Out', null, () => {
    const [a, b] = hasSelection() ? selRange() : [Math.floor(working.length * 0.9), working.length];
    apply(applyFade(ctx, working, a, b, 'out'), 'Fade Out');
  });

  toolButton('Normalisieren', null, () => {
    apply(normalizeBuffer(ctx, working, -0.3), 'Normalisieren');
  });

  toolButton('Umkehren', 'undo', () => {
    const [a, b] = hasSelection() ? selRange() : [0, working.length];
    apply(reverseBuffer(ctx, working, a, b), 'Umkehren');
  });

  toolButton('Stille kürzen', null, () => {
    apply(trimSilence(ctx, working, -48), 'Stille kürzen');
  });

  // Gain
  const gainLabel = h('b', { text: '0.0 dB' });
  const gainSlider = slider(-24, 24, 0.5, 0, (v) => {
    gainLabel.textContent = `${v > 0 ? '+' : ''}${v.toFixed(1)} dB`;
  });
  const gainApply = h('button.btn.btn--sm', { type: 'button', text: 'Gain anwenden' });
  gainApply.addEventListener('click', () => {
    const db = gainSlider.valueAsNumber;
    if (Math.abs(db) < 0.05) return;
    const factor = 10 ** (db / 20);
    const [a, b] = hasSelection() ? selRange() : [0, working.length];
    apply(applyGain(ctx, working, factor, a, b), `Gain ${db.toFixed(1)} dB`);
    gainSlider.setValue(0);
    gainLabel.textContent = '0.0 dB';
  });

  body.appendChild(
    h('div.field', null, [
      h('label.field__label', null, [document.createTextNode('Verstärkung '), gainLabel]),
      gainSlider,
      gainApply,
    ]),
  );

  // Undo/Redo
  const historyRow = h('div.sed__tools');
  const undoBtn = h('button.btn.btn--sm', { type: 'button', disabled: true });
  undoBtn.appendChild(icon('undo', 15));
  undoBtn.appendChild(h('span', { text: 'Rückgängig' }));
  undoBtn.addEventListener('click', undo);
  const redoBtn = h('button.btn.btn--sm', { type: 'button', disabled: true });
  redoBtn.appendChild(icon('redo', 15));
  redoBtn.appendChild(h('span', { text: 'Wiederholen' }));
  redoBtn.addEventListener('click', redo);
  historyRow.appendChild(undoBtn);
  historyRow.appendChild(redoBtn);
  body.appendChild(historyRow);

  // --- Bearbeitung -----------------------------------------------------------

  function hasSelection() {
    return selFrom != null && selTo != null && Math.abs(selTo - selFrom) > 0.0005;
  }

  /** Ausgewählter Bereich in Frames [von, bis]. */
  function selRange() {
    const lo = clamp(Math.min(selFrom, selTo), 0, 1);
    const hi = clamp(Math.max(selFrom, selTo), 0, 1);
    return [Math.round(lo * working.length), Math.round(hi * working.length)];
  }

  function apply(newBuffer, label) {
    past.push(working);
    if (past.length > HISTORY_LIMIT) past.shift();
    future.length = 0;
    working = newBuffer;
    peaks = computePeaks(working, 2048);
    stopPlayback();
    updateHistoryButtons();
    paint();
    toast.info(label, `Länge: ${formatTime(working.duration)}`);
  }

  function undo() {
    if (!past.length) return;
    future.push(working);
    working = past.pop();
    peaks = computePeaks(working, 2048);
    stopPlayback();
    updateHistoryButtons();
    paint();
  }

  function redo() {
    if (!future.length) return;
    past.push(working);
    working = future.pop();
    peaks = computePeaks(working, 2048);
    stopPlayback();
    updateHistoryButtons();
    paint();
  }

  function updateHistoryButtons() {
    undoBtn.disabled = past.length === 0;
    redoBtn.disabled = future.length === 0;
  }

  // --- Wiedergabe ------------------------------------------------------------

  async function togglePlay() {
    if (playVoice) {
      stopPlayback();
      return;
    }
    const from = hasSelection() ? Math.min(selFrom, selTo) : 0;
    const to = hasSelection() ? Math.max(selFrom, selTo) : 1;
    playOffset = from * working.duration;
    const duration = (to - from) * working.duration;

    try {
      playVoice = await engine.previewBuffer(working, { gain: 0.9, offset: playOffset, duration });
      playStart = ctx.currentTime;
      playBtn.replaceChildren(icon('pause', 15), h('span', { text: 'Stopp' }));
      cancelAnimationFrame(raf);
      raf = requestAnimationFrame(playLoop);
      setTimeout(() => stopPlayback(), duration * 1000 + 120);
    } catch (err) {
      reportError('Wiedergabe nicht möglich', err);
    }
  }

  function stopPlayback() {
    if (playVoice) {
      engine.stopPreview();
      playVoice = null;
    }
    cancelAnimationFrame(raf);
    raf = 0;
    playBtn.replaceChildren(icon('play', 15), h('span', { text: 'Abspielen' }));
    paint();
  }

  function playLoop() {
    paint();
    raf = requestAnimationFrame(playLoop);
  }

  function playPosition() {
    if (!playVoice) return null;
    const elapsed = ctx.currentTime - playStart;
    return clamp((playOffset + elapsed) / working.duration, 0, 1);
  }

  // --- Zeichnen --------------------------------------------------------------

  function zoom(factor) {
    const center = hasSelection() ? (selFrom + selTo) / 2 : (viewFrom + viewTo) / 2;
    const span = clamp((viewTo - viewFrom) * factor, 0.001, 1);
    viewFrom = clamp(center - span / 2, 0, 1 - span);
    viewTo = viewFrom + span;
    paint();
  }

  function paint() {
    const { ctx: c, width, height } = setupCanvas(canvas, 2);
    if (!width || !height) return;

    c.fillStyle = cssVar('--c-bg-deep');
    c.fillRect(0, 0, width, height);

    // Mittellinie
    c.strokeStyle = cssVar('--c-border');
    c.beginPath();
    c.moveTo(0, height / 2 + 0.5);
    c.lineTo(width, height / 2 + 0.5);
    c.stroke();

    // Auswahl
    if (hasSelection()) {
      const a = posToX(Math.min(selFrom, selTo), width);
      const b = posToX(Math.max(selFrom, selTo), width);
      c.fillStyle = withAlpha(cssVar('--c-accent'), 0.2);
      c.fillRect(a, 0, b - a, height);
      c.strokeStyle = cssVar('--c-accent');
      c.lineWidth = 1;
      c.beginPath();
      c.moveTo(a + 0.5, 0);
      c.lineTo(a + 0.5, height);
      c.moveTo(b + 0.5, 0);
      c.lineTo(b + 0.5, height);
      c.stroke();
    }

    // Wellenform
    const color = trackColorValue(sample.id);
    const total = peaks.buckets;
    const startBucket = viewFrom * total;
    const per = ((viewTo - viewFrom) * total) / width;
    c.fillStyle = withAlpha(color, 0.9);
    for (let x = 0; x < width; x++) {
      const s0 = Math.floor(startBucket + x * per);
      const s1 = Math.max(s0 + 1, Math.floor(startBucket + (x + 1) * per));
      let lo = 1;
      let hi = -1;
      for (let b = s0; b < s1 && b < total; b++) {
        if (b < 0) continue;
        if (peaks.min[b] < lo) lo = peaks.min[b];
        if (peaks.max[b] > hi) hi = peaks.max[b];
      }
      if (lo > hi) {
        lo = 0;
        hi = 0;
      }
      const top = height / 2 - hi * (height / 2 - 4);
      const bottom = height / 2 - lo * (height / 2 - 4);
      c.fillRect(x, top, 1, Math.max(1, bottom - top));
    }

    // Abspielposition
    const pos = playPosition();
    if (pos != null) {
      const x = posToX(pos, width);
      c.strokeStyle = cssVar('--c-mint');
      c.lineWidth = 2;
      c.beginPath();
      c.moveTo(x, 0);
      c.lineTo(x, height);
      c.stroke();
    }

    // Zeitmarken
    c.fillStyle = cssVar('--c-text-faint');
    c.font = '10px ui-monospace, SFMono-Regular, Menlo, monospace';
    c.fillText(formatTime(viewFrom * working.duration), 4, 12);
    c.textAlign = 'right';
    c.fillText(formatTime(viewTo * working.duration), width - 4, 12);
    c.textAlign = 'left';

    updateInfo();
  }

  function posToX(pos, width) {
    return ((pos - viewFrom) / (viewTo - viewFrom)) * width;
  }

  function xToPos(x, width) {
    return clamp(viewFrom + (x / width) * (viewTo - viewFrom), 0, 1);
  }

  function updateInfo() {
    const peak = peakOf(working);
    const selText = hasSelection()
      ? `${formatTime(Math.min(selFrom, selTo) * working.duration)} – ${formatTime(Math.max(selFrom, selTo) * working.duration)}`
      : 'keine';
    info.replaceChildren(
      h('span', { text: `Länge: ${formatTime(working.duration)}` }),
      h('span', { text: `Kanäle: ${working.numberOfChannels}` }),
      h('span', { text: `${working.sampleRate} Hz` }),
      h('span', { text: `Spitze: ${gainToDb(peak).toFixed(1)} dBFS` }),
      h('span', { text: `Auswahl: ${selText}` }),
    );
  }

  // --- Zeigerbedienung -------------------------------------------------------
  let dragging = false;

  canvasWrap.addEventListener('pointerdown', (event) => {
    const rect = canvasWrap.getBoundingClientRect();
    dragging = true;
    canvasWrap.setPointerCapture?.(event.pointerId);
    selFrom = xToPos(event.clientX - rect.left, rect.width);
    selTo = selFrom;
    paint();
    event.preventDefault();
  });

  canvasWrap.addEventListener('pointermove', (event) => {
    if (!dragging) return;
    const rect = canvasWrap.getBoundingClientRect();
    selTo = xToPos(event.clientX - rect.left, rect.width);
    paint();
  });

  const endDrag = () => {
    if (!dragging) return;
    dragging = false;
    if (Math.abs((selTo ?? 0) - (selFrom ?? 0)) < 0.002) {
      selFrom = null;
      selTo = null;
    }
    paint();
  };
  canvasWrap.addEventListener('pointerup', endDrag);
  canvasWrap.addEventListener('pointercancel', endDrag);

  canvasWrap.addEventListener(
    'wheel',
    (event) => {
      event.preventDefault();
      if (event.ctrlKey || event.metaKey || Math.abs(event.deltaY) > Math.abs(event.deltaX)) {
        zoom(event.deltaY > 0 ? 1.15 : 1 / 1.15);
      } else {
        const span = viewTo - viewFrom;
        const shift = (event.deltaX / 400) * span;
        viewFrom = clamp(viewFrom + shift, 0, 1 - span);
        viewTo = viewFrom + span;
        paint();
      }
    },
    { passive: false },
  );

  // --- Dialog ----------------------------------------------------------------
  const modal = openModal({
    title: `Sample bearbeiten – ${sample.name}`,
    size: 'wide',
    body,
    actions: [
      {
        label: 'Abbrechen',
        variant: 'ghost',
        onClick: async () => {
          if (past.length) {
            const ok = await confirmModal({
              title: 'Änderungen verwerfen?',
              message: 'Die Bearbeitung wurde noch nicht übernommen.',
              confirmLabel: 'Verwerfen',
              danger: true,
            });
            if (!ok) return false;
          }
          stopPlayback();
          return true;
        },
      },
      {
        label: 'Als neues Sample',
        onClick: async ({ setBusy }) => {
          setBusy(true, 'Wird gespeichert …');
          try {
            const bytes = encodeWav(working, { bitDepth: 16 });
            const created = await store.addSample({
              bytes,
              mime: 'audio/wav',
              name: `${sample.name} (bearbeitet)`,
              duration: working.duration,
              sampleRate: working.sampleRate,
              channels: working.numberOfChannels,
              source: 'edit',
              prompt: sample.prompt,
              gain: sample.gain,
            });
            sampleCache.set(created.dataId, working);
            engine.invalidate();
            stopPlayback();
            toast.ok('Als neues Sample gespeichert', created.name);
          } catch (err) {
            reportError('Speichern fehlgeschlagen', err);
            setBusy(false);
            return false;
          }
          return true;
        },
      },
      {
        label: 'Übernehmen',
        variant: 'primary',
        onClick: async ({ setBusy }) => {
          if (!past.length) {
            stopPlayback();
            return true;
          }
          setBusy(true, 'Wird gespeichert …');
          try {
            const bytes = encodeWav(working, { bitDepth: 16 });
            const newDataId = await store.replaceSampleAudio(sample.id, {
              bytes,
              mime: 'audio/wav',
              duration: working.duration,
              sampleRate: working.sampleRate,
              channels: working.numberOfChannels,
            });
            invalidatePeaks(sample.dataId);
            sampleCache.set(newDataId, working);
            engine.invalidate();
            bus.emit(EV.SAMPLE_EDITED, sample.id);
            stopPlayback();
            toast.ok('Sample aktualisiert', sample.name);
          } catch (err) {
            reportError('Speichern fehlgeschlagen', err);
            setBusy(false);
            return false;
          }
          return true;
        },
      },
    ],
    onClose: () => {
      stopPlayback();
      window.removeEventListener('resize', paint);
    },
  });

  window.addEventListener('resize', paint);
  requestAnimationFrame(() => {
    paint();
    updateHistoryButtons();
  });

  return modal;
}
