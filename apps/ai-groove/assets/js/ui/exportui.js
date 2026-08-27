/**
 * AI Groove – Export-Dialog.
 *
 * Rendert das Projekt offline (OfflineAudioContext) und lädt das Ergebnis
 * als WAV oder MP3 herunter. Es wird nichts an einen Server gesendet.
 */

import { h, segmented } from '../core/dom.js';
import { openModal } from './modal.js';
import { toast, reportError } from './toast.js';
import { store } from '../core/store.js';
import { engine } from '../audio/engine.js';
import { renderProject, toWavBlob, toMp3Blob, estimateDuration } from '../audio/render.js';
import { downloadBlob, safeFilename, formatTime, humanBytes, TICKS_PER_BAR } from '../core/util.js';

const FORMATS = [
  { label: 'WAV 24 Bit', value: 'wav24' },
  { label: 'WAV 16 Bit', value: 'wav16' },
  { label: 'MP3 320', value: 'mp3' },
];

export function openExportDialog() {
  const project = store.project;

  if (!project.clips.length && !project.patterns.some((p) => p.events.length)) {
    toast.warn('Nichts zu exportieren', 'Baue zuerst ein Pattern oder ein Arrangement.');
    return null;
  }

  const body = h('div', { style: { display: 'flex', flexDirection: 'column', gap: '16px' } });

  // --- Bereich ---------------------------------------------------------------
  let range = project.clips.length ? 'song' : 'pattern';
  const rangeOptions = [
    { label: 'Ganzes Arrangement', value: 'song' },
    { label: 'Loop-Bereich', value: 'loop' },
    { label: 'Aktuelles Pattern', value: 'pattern' },
  ];
  const rangeSeg = segmented(rangeOptions, range, (value) => {
    range = value;
    updateInfo();
  });
  body.appendChild(h('div.field', null, [h('label.field__label', { text: 'Was soll exportiert werden?' }), rangeSeg]));

  // --- Format ----------------------------------------------------------------
  let format = 'wav24';
  const formatSeg = segmented(FORMATS, format, (value) => {
    format = value;
    updateInfo();
  });
  body.appendChild(h('div.field', null, [h('label.field__label', { text: 'Format' }), formatSeg]));

  // --- Abtastrate ------------------------------------------------------------
  let sampleRate = 44100;
  const srSeg = segmented(
    [
      { label: '44,1 kHz', value: 44100 },
      { label: '48 kHz', value: 48000 },
    ],
    sampleRate,
    (value) => {
      sampleRate = value;
      updateInfo();
    },
  );
  body.appendChild(h('div.field', null, [h('label.field__label', { text: 'Abtastrate' }), srSeg]));

  // --- Name ------------------------------------------------------------------
  const nameInput = h('input.input', { type: 'text', value: safeFilename(project.name), maxLength: 80 });
  body.appendChild(h('div.field', null, [h('label.field__label', { text: 'Dateiname' }), nameInput]));

  const info = h('p.field__hint');
  body.appendChild(info);

  const progressWrap = h('div', { hidden: true });
  const progressBar = h('div.progress__bar');
  progressWrap.appendChild(h('div.progress', null, [progressBar]));
  const progressText = h('p.field__hint', { text: '' });
  progressWrap.appendChild(progressText);
  body.appendChild(progressWrap);

  body.appendChild(
    h('p.field__hint', {
      text: 'Der Export berechnet das Projekt neu (Samples, Tonhöhe, Lautstärke, Panorama, alle Effekte und den Master). Es wird kein Mikrofon und kein Systemton aufgenommen.',
    }),
  );

  function rangeInfo() {
    if (range === 'pattern') {
      const pattern = store.selectedPattern;
      if (!pattern) return { seconds: 0, label: 'Kein Pattern ausgewählt' };
      const seconds = (pattern.lengthBars * 4 * 60) / project.bpm;
      return { seconds, label: `Pattern „${pattern.name}“ (${pattern.lengthBars} Takt(e))` };
    }
    if (range === 'loop') {
      const ticks = Math.max(1, project.loop.end - project.loop.start);
      const seconds = (ticks / TICKS_PER_BAR) * ((4 * 60) / project.bpm);
      return {
        seconds,
        label: `Loop Takt ${Math.floor(project.loop.start / TICKS_PER_BAR) + 1}–${Math.floor(project.loop.end / TICKS_PER_BAR) + 1}`,
      };
    }
    return { seconds: estimateDuration(project, 'song'), label: 'Komplettes Arrangement' };
  }

  function updateInfo() {
    const { seconds, label } = rangeInfo();
    const channels = 2;
    const bytesPerSample = format === 'wav24' ? 3 : format === 'wav16' ? 2 : 0.04;
    const size = seconds * sampleRate * channels * bytesPerSample;
    info.textContent = `${label} · ca. ${formatTime(seconds, false)} · geschätzt ${humanBytes(size)}`;
  }
  updateInfo();

  async function doExport({ setBusy }) {
    const wasPlaying = engine.playing;
    if (wasPlaying) engine.stop();

    progressWrap.hidden = false;
    progressBar.style.width = '1%';
    progressText.textContent = 'Vorbereiten …';
    setBusy(true, 'Export läuft …');

    try {
      const options = {
        sampleRate,
        onProgress: (value, label) => {
          progressBar.style.width = `${Math.round(value * 100)}%`;
          if (label) progressText.textContent = label;
        },
      };

      if (range === 'pattern') {
        const pattern = store.selectedPattern;
        if (!pattern) throw new Error('Es ist kein Pattern ausgewählt.');
        options.mode = 'pattern';
        options.patternId = pattern.id;
      } else if (range === 'loop') {
        options.mode = 'song';
        options.range = { from: project.loop.start, to: project.loop.end };
      } else {
        options.mode = 'song';
      }

      const buffer = await renderProject(project, options);

      let blob;
      let extension;
      if (format === 'mp3') {
        progressText.textContent = 'MP3 wird kodiert …';
        blob = await toMp3Blob(buffer, {
          bitrate: 320,
          onProgress: (value) => {
            progressBar.style.width = `${Math.round(90 + value * 10)}%`;
          },
        });
        extension = 'mp3';
      } else {
        blob = toWavBlob(buffer, format === 'wav24' ? 24 : 16);
        extension = 'wav';
      }

      progressBar.style.width = '100%';
      progressText.textContent = 'Fertig';

      const filename = `${safeFilename(nameInput.value || project.name)}.${extension}`;
      downloadBlob(blob, filename);
      toast.ok('Export fertig', `${filename} · ${humanBytes(blob.size)}`);
      return true;
    } catch (err) {
      progressWrap.hidden = true;
      reportError('Export fehlgeschlagen', err);
      setBusy(false);
      return false;
    }
  }

  const modal = openModal({
    title: 'Track exportieren',
    body,
    actions: [
      { label: 'Abbrechen', variant: 'ghost' },
      {
        label: 'Exportieren',
        variant: 'primary',
        onClick: doExport,
      },
    ],
  });

  return modal;
}
