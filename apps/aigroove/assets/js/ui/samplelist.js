/**
 * AI Groove – Sample- und Instrumentliste.
 *
 * Keine festen Kategorien: eine flache, sortierbare Liste. Jeder Eintrag lässt
 * sich per Drag & Drop auf Pads, Sequencer-Zeilen, Piano Roll und Arrangement ziehen.
 */

import { h, icon, clear, setupCanvas, cssVar, onLongPress, trackColorValue, withAlpha } from '../core/dom.js';
import { store } from '../core/store.js';
import { bus, EV } from '../core/bus.js';
import { engine } from '../audio/engine.js';
import { samples as sampleCache } from '../audio/sampler.js';
import { getPeaks, peekPeaks, drawWaveform, invalidatePeaks } from '../audio/peaks.js';
import { getContext } from '../audio/context.js';
import { makeDraggable, acceptFileDrop } from './dragdrop.js';
import { openContextMenu } from './contextmenu.js';
import { toast, reportError } from './toast.js';
import { promptModal, confirmModal } from './modal.js';
import { openAddSampleDialog, importAudioFiles } from './aisample.js';
import { openSampleEditor } from './sampleeditor.js';
import { openAiEditDialog } from './aisample.js';
import { trackColor } from '../core/util.js';

export function createSampleList() {
  const element = h('div.ws-col.ws-sidebar');

  const head = h('div.panel-head');
  head.appendChild(h('div.panel-head__title', { text: 'Sounds' }));
  const countChip = h('span.chip', { text: '0' });
  head.appendChild(countChip);
  element.appendChild(head);

  const addWrap = h('div', { style: { padding: '12px 12px 0' } });
  const addBtn = h('button.btn-add', { type: 'button', 'data-tour': 'add-sample' });
  addBtn.appendChild(h('span.btn-add__plus', { text: '+' }));
  addBtn.appendChild(h('span', { text: 'Neues Sample' }));
  addBtn.addEventListener('click', () => openAddSampleDialog());
  addWrap.appendChild(addBtn);
  element.appendChild(addWrap);

  const list = h('div.slist', { 'data-tour': 'sample-list' });
  element.appendChild(list);

  acceptFileDrop(element, (files) => importAudioFiles(files));

  /** Zeichnet die kleine Wellenform eines Eintrags. */
  async function paintWave(canvas, sample) {
    const { ctx, width, height } = setupCanvas(canvas, 1.5);
    ctx.clearRect(0, 0, width, height);
    let peaks = peekPeaks(sample.dataId);
    if (!peaks) {
      try {
        const buffer = await sampleCache.load(getContext(), sample.dataId);
        peaks = await getPeaks(sample.dataId, buffer);
      } catch (_) {
        ctx.fillStyle = cssVar('--c-text-faint');
        ctx.font = '10px system-ui';
        ctx.fillText('Audio fehlt', 2, height / 2 + 3);
        return;
      }
      if (!canvas.isConnected) return;
    }
    const { ctx: c2, width: w2, height: h2 } = setupCanvas(canvas, 1.5);
    drawWaveform(c2, peaks, {
      width: w2,
      height: h2,
      color: withAlpha(trackColorValue(sample.id), 0.85),
    });
  }

  function menuFor(sample, position) {
    openContextMenu(position, [
      { heading: sample.name },
      { label: 'Bearbeiten (Waveform)', icon: 'edit', onClick: () => openSampleEditor(sample.id) },
      { label: 'Mit KI verändern', icon: 'wand', onClick: () => openAiEditDialog(sample.id) },
      { separator: true },
      {
        label: 'Auf freies Pad legen',
        icon: 'pads',
        onClick: () => {
          const bank = store.activeBank;
          const free = bank.pads.findIndex((p) => !p);
          if (free < 0) {
            toast.warn('Kein freies Pad', 'Alle 16 Pads dieser Bank sind belegt.');
            return;
          }
          store.setPad(free, sample.id);
          toast.ok('Auf Pad gelegt', `Pad ${free + 1}: ${sample.name}`);
        },
      },
      {
        label: 'Ins aktuelle Pattern',
        icon: 'grid',
        onClick: () => {
          const pattern = store.selectedPattern || store.addPattern({ rows: [sample.id] });
          store.ensurePatternRow(pattern.id, sample.id);
          toast.ok('Zeile hinzugefügt', `${sample.name} in „${pattern.name}“`);
        },
      },
      { separator: true },
      {
        label: 'Umbenennen',
        icon: 'edit',
        onClick: async () => {
          const name = await promptModal({
            title: 'Sample umbenennen',
            label: 'Name',
            value: sample.name,
          });
          if (name) store.renameSample(sample.id, name);
        },
      },
      {
        label: 'Duplizieren',
        icon: 'copy',
        onClick: async () => {
          try {
            await store.duplicateSample(sample.id);
            toast.ok('Sample dupliziert');
          } catch (err) {
            reportError('Duplizieren fehlgeschlagen', err);
          }
        },
      },
      { separator: true },
      {
        label: 'Löschen',
        icon: 'trash',
        danger: true,
        onClick: async () => {
          const ok = await confirmModal({
            title: 'Sample löschen?',
            message: `„${sample.name}“ wird aus Pads, Patterns und dem Arrangement entfernt. Das lässt sich mit Rückgängig widerrufen.`,
            confirmLabel: 'Löschen',
            danger: true,
          });
          if (ok) {
            store.deleteSample(sample.id);
            invalidatePeaks(sample.dataId);
          }
        },
      },
    ]);
  }

  function renderItem(sample, index) {
    const selected = store.project.ui.selectedSampleId === sample.id;
    const row = h(`div.sitem${selected ? '.sitem--selected' : ''}`, {
      dataset: { sampleId: sample.id },
      style: { '--pad-color': trackColor(sample.id) },
    });

    // Ziehgriff
    const grip = h('button.sitem__grip', { type: 'button', 'aria-label': 'Verschieben / ziehen' });
    grip.appendChild(icon('grip', 15));
    row.appendChild(grip);

    // Vorhören
    const play = h('button.sitem__play', { type: 'button', 'aria-label': `${sample.name} anhören` });
    play.appendChild(icon('play', 13));
    play.addEventListener('click', async (event) => {
      event.stopPropagation();
      try {
        await engine.preview(sample.id);
        row.classList.add('sitem--playing');
        setTimeout(() => row.classList.remove('sitem--playing'), Math.min(1200, sample.duration * 1000 + 120));
      } catch (err) {
        reportError('Sample konnte nicht abgespielt werden', err);
      }
    });
    row.appendChild(play);

    // Hauptbereich
    const main = h('button.sitem__main', { type: 'button' });
    main.appendChild(h('div.sitem__name', { text: sample.name }));
    const canvas = h('canvas.sitem__wave');
    main.appendChild(canvas);
    main.addEventListener('click', () => {
      store.setUi({ selectedSampleId: sample.id });
      bus.emit(EV.SELECTION_CHANGED, { sampleId: sample.id });
      render();
    });
    main.addEventListener('dblclick', () => openSampleEditor(sample.id));
    row.appendChild(main);

    if (sample.source === 'ai') row.appendChild(h('span.sitem__badge', { text: 'KI' }));
    if (sample.source === 'mic') row.appendChild(h('span.sitem__badge', { text: 'Mic' }));

    const more = h('button.sitem__more', { type: 'button', 'aria-label': 'Weitere Aktionen' });
    more.appendChild(icon('dots', 16));
    more.addEventListener('click', (event) => {
      event.stopPropagation();
      const rect = more.getBoundingClientRect();
      menuFor(sample, { x: rect.left - 180, y: rect.bottom + 4 });
    });
    row.appendChild(more);

    onLongPress(row, ({ x, y }) => menuFor(sample, { x, y }));

    makeDraggable(row, {
      handle: grip,
      payload: () => ({ type: 'sample', sampleId: sample.id, name: sample.name, index }),
      label: () => sample.name,
    });

    // Auch der Name lässt sich ziehen (grosszügiger auf Touch).
    makeDraggable(row, {
      handle: main,
      payload: () => ({ type: 'sample', sampleId: sample.id, name: sample.name, index }),
      label: () => sample.name,
    });

    paintWave(canvas, sample);
    return row;
  }

  function render() {
    clear(list);
    const items = store.project.samples;
    countChip.textContent = String(items.length);

    if (!items.length) {
      list.appendChild(
        h('div.empty', null, [
          h('div.empty__icon', null, icon('sound', 22)),
          h('span', {
            text: 'Noch keine Sounds. Tippe auf „Neues Sample“ – hochladen, aufnehmen oder mit KI erzeugen.',
          }),
        ]),
      );
      return;
    }

    items.forEach((sample, index) => list.appendChild(renderItem(sample, index)));
  }

  const offSamples = bus.on(EV.SAMPLES_CHANGED, render);
  const offSelection = bus.on(EV.SELECTION_CHANGED, render);
  const offEdited = bus.on(EV.SAMPLE_EDITED, render);
  const offLoaded = bus.on(EV.PROJECT_LOADED, render);

  render();

  return {
    element,
    render,
    destroy() {
      offSamples();
      offSelection();
      offEdited();
      offLoaded();
    },
  };
}
