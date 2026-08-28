/**
 * AI Groove – Samples hinzufügen und mit KI verändern.
 *
 * Drei Wege: Datei hochladen, Mikrofon aufnehmen, mit KI erzeugen.
 * Die KI erzeugt immer vier Varianten zum Anhören; das Original wird
 * niemals ungefragt überschrieben.
 */

import { h, icon, clear, setupCanvas, cssVar, trackColorValue, withAlpha, slider, segmented } from '../core/dom.js';
import { openModal, confirmModal } from './modal.js';
import { toast, reportError } from './toast.js';
import { store } from '../core/store.js';
import { engine } from '../audio/engine.js';
import { getContext, unlockAudio } from '../audio/context.js';
import { samples as sampleCache, decodeAudio } from '../audio/sampler.js';
import { computePeaks, normalizeBuffer } from '../audio/dsp.js';
import { encodeWav } from '../audio/wav.js';
import { Recorder, listMicrophones, MicError } from '../audio/recorder.js';
import {
  pickFiles,
  validateAudioFile,
  readArrayBuffer,
  decodeErrorMessage,
  sniffAudioFormat,
  isProjectFile,
  AUDIO_ACCEPT,
  FileError,
} from '../core/file.js';
import { settings } from '../core/settings.js';
import { activeProvider } from '../ai/providers.js';
import { generateVariants, addVariantAsSample, replaceSampleWithVariant, resolveDuration, LENGTH_PRESETS } from '../ai/generate.js';
import { guessInstrumentName, formatTime, humanBytes, clamp } from '../core/util.js';
import { navigate } from './router.js';

/* =============================================================================
   Gemeinsame Bausteine
   ========================================================================== */

/** Kleine Wellenform-Vorschau eines AudioBuffers. */
function waveThumb(buffer, color, height = 52) {
  const canvas = h('canvas.variant__wave', { style: { height: `${height}px` } });
  requestAnimationFrame(() => {
    const { ctx, width, height: hgt } = setupCanvas(canvas, 2);
    if (!width) return;
    ctx.fillStyle = cssVar('--c-bg-deep');
    ctx.fillRect(0, 0, width, hgt);
    const peaks = computePeaks(buffer, Math.max(32, Math.floor(width)));
    ctx.fillStyle = withAlpha(color, 0.9);
    for (let x = 0; x < peaks.buckets && x < width; x++) {
      const top = hgt / 2 - peaks.max[x] * (hgt / 2 - 2);
      const bottom = hgt / 2 - peaks.min[x] * (hgt / 2 - 2);
      ctx.fillRect(x, top, 1, Math.max(1, bottom - top));
    }
  });
  return canvas;
}

/**
 * Importiert Audiodateien: prüfen, dekodieren, ablegen.
 * Neue Samples starten bei 30 % Lautstärke (Vorgabe des Projekts).
 */
export async function importAudioFiles(files) {
  const audioFiles = [];
  for (const file of files) {
    if (isProjectFile(file)) {
      toast.warn(
        'Projektdatei erkannt',
        'Projektdateien werden über das Dashboard („Projektdatei importieren“) geöffnet.',
      );
      continue;
    }
    audioFiles.push(file);
  }
  if (!audioFiles.length) return [];

  const ctx = getContext();
  const created = [];
  const stop = toast.sticky('Samples werden geladen …', `${audioFiles.length} Datei(en)`);

  for (const file of audioFiles) {
    try {
      const { ext } = validateAudioFile(file);
      const buffer = await readArrayBuffer(file);

      // Zusätzliche Prüfung anhand der ersten Bytes – der MIME-Typ des
      // Browsers ist nicht verlässlich.
      const sniffed = sniffAudioFormat(buffer);
      if (!sniffed) {
        throw new FileError(
          'unsupported',
          `„${file.name}“ scheint keine Audiodatei zu sein (unbekanntes Format).`,
        );
      }

      let decoded;
      try {
        decoded = await decodeAudio(ctx, buffer.slice(0));
      } catch (_) {
        throw new FileError('decode', decodeErrorMessage(ext));
      }

      if (decoded.duration > 600) {
        throw new FileError('too_long', `„${file.name}“ ist länger als 10 Minuten.`);
      }

      const name = file.name.replace(/\.[^.]+$/, '').slice(0, 60) || 'Sample';
      const sample = await store.addSample({
        bytes: buffer,
        mime: sniffed,
        name,
        duration: decoded.duration,
        sampleRate: decoded.sampleRate,
        channels: decoded.numberOfChannels,
        source: 'file',
      });
      sampleCache.set(sample.dataId, decoded);
      created.push(sample);
    } catch (err) {
      if (err instanceof FileError) toast.error(file.name, err.message);
      else reportError(`Import von „${file.name}“`, err);
    }
  }

  stop();
  engine.invalidate();
  if (created.length) {
    toast.ok(
      created.length === 1 ? 'Sample hinzugefügt' : `${created.length} Samples hinzugefügt`,
      created.map((s) => s.name).join(', ').slice(0, 120),
    );
  }
  return created;
}

/* =============================================================================
   Dialog: Neues Sample
   ========================================================================== */

export function openAddSampleDialog() {
  const body = h('div', { style: { display: 'flex', flexDirection: 'column', gap: '12px' } });
  const tiles = h('div.tiles');

  const makeTile = (iconName, title, text, onClick, primary = false) => {
    const btn = h(`button.tile${primary ? '.tile--primary' : ''}`, { type: 'button' });
    const ic = h('div.tile__icon');
    ic.appendChild(icon(iconName, 22));
    btn.appendChild(ic);
    btn.appendChild(h('div.tile__title', { text: title }));
    btn.appendChild(h('div.tile__text', { text }));
    btn.addEventListener('click', () => {
      modal.close();
      onClick();
    });
    return btn;
  };

  tiles.appendChild(
    makeTile('upload', 'Datei hochladen', 'WAV, MP3, M4A/AAC, OGG oder FLAC vom Gerät.', async () => {
      const files = await pickFiles({ accept: AUDIO_ACCEPT, multiple: true });
      if (files.length) importAudioFiles(files);
    }),
  );
  tiles.appendChild(
    makeTile('mic', 'Mikrofon aufnehmen', 'Direkt im Browser aufnehmen und anhören.', () =>
      openRecordDialog(),
    ),
  );
  tiles.appendChild(
    makeTile(
      'wand',
      'Mit KI erstellen',
      'Prompt eingeben, vier Varianten anhören, eine übernehmen.',
      () => openAiGenerateDialog(),
      true,
    ),
  );

  body.appendChild(tiles);
  body.appendChild(
    h('p.field__hint', {
      text: 'Neue Samples starten bei 30 % Lautstärke. Im Mixer lässt sich das jederzeit ändern – 100 % ist deutlich lauter.',
    }),
  );

  const modal = openModal({
    title: 'Neues Sample',
    body,
    actions: [{ label: 'Abbrechen', variant: 'ghost' }],
  });
  return modal;
}

/* =============================================================================
   Dialog: Mikrofonaufnahme
   ========================================================================== */

export function openRecordDialog() {
  const recorder = new Recorder();
  let recorded = null;
  let timer = 0;
  let raf = 0;
  let state = 'idle'; // idle | armed | recording | done

  const body = h('div', { style: { display: 'flex', flexDirection: 'column', gap: '14px' } });

  const deviceSelect = h('select.select');
  body.appendChild(
    h('div.field', null, [h('label.field__label', { text: 'Eingabegerät' }), deviceSelect]),
  );

  const visual = h('div.rec-visual');
  const visCanvas = h('canvas');
  visual.appendChild(visCanvas);
  body.appendChild(visual);

  const timeEl = h('div.rec-time', { text: '0:00.000' });
  body.appendChild(timeEl);

  const hint = h('p.field__hint', {
    text: 'Tipp: Auf dem iPhone muss der Mikrofonzugriff für diese Website erlaubt sein (Safari-Einstellungen → Mikrofon).',
  });
  body.appendChild(hint);

  const controls = h('div.sed__tools');
  const recBtn = h('button.btn.btn--primary', { type: 'button' });
  recBtn.appendChild(icon('record', 15));
  recBtn.appendChild(h('span', { text: 'Aufnahme starten' }));
  const stopBtn = h('button.btn', { type: 'button', disabled: true });
  stopBtn.appendChild(icon('stop', 14));
  stopBtn.appendChild(h('span', { text: 'Stopp' }));
  const playBtn = h('button.btn', { type: 'button', disabled: true });
  playBtn.appendChild(icon('play', 14));
  playBtn.appendChild(h('span', { text: 'Anhören' }));
  const againBtn = h('button.btn', { type: 'button', disabled: true, text: 'Neu aufnehmen' });

  controls.appendChild(recBtn);
  controls.appendChild(stopBtn);
  controls.appendChild(playBtn);
  controls.appendChild(againBtn);
  body.appendChild(controls);

  const nameInput = h('input.input', { type: 'text', value: 'Aufnahme', maxLength: 60 });
  body.appendChild(h('div.field', null, [h('label.field__label', { text: 'Name' }), nameInput]));

  const normalizeToggle = h('input', { type: 'checkbox', checked: true });
  body.appendChild(
    h('label.switch', null, [
      normalizeToggle,
      h('span.switch__track'),
      h('span', { text: 'Nach der Aufnahme normalisieren' }),
    ]),
  );

  // --- Pegelanzeige ----------------------------------------------------------
  const levelHistory = new Float32Array(160);
  let levelIndex = 0;

  function drawVisual() {
    const { ctx, width, height } = setupCanvas(visCanvas, 2);
    if (!width) return;
    ctx.fillStyle = cssVar('--c-bg-deep');
    ctx.fillRect(0, 0, width, height);

    const step = width / levelHistory.length;
    ctx.fillStyle = state === 'recording' ? cssVar('--c-rose') : cssVar('--c-accent');
    for (let i = 0; i < levelHistory.length; i++) {
      const idx = (levelIndex + i) % levelHistory.length;
      const v = levelHistory[idx];
      const hgt = Math.max(1, v * (height - 8));
      ctx.fillRect(i * step, (height - hgt) / 2, Math.max(1, step - 1), hgt);
    }

    if (state === 'recording') {
      timeEl.textContent = formatTime(recorder.duration);
    }
    raf = requestAnimationFrame(drawVisual);
  }

  recorder.onLevel = (level) => {
    levelHistory[levelIndex] = clamp(level * 1.6, 0, 1);
    levelIndex = (levelIndex + 1) % levelHistory.length;
  };

  // --- Geräte ----------------------------------------------------------------
  async function loadDevices() {
    const devices = await listMicrophones();
    clear(deviceSelect);
    deviceSelect.appendChild(h('option', { value: '', text: 'Standardmikrofon' }));
    for (const d of devices) {
      deviceSelect.appendChild(h('option', { value: d.id, text: d.label }));
    }
    const saved = settings.get('micDeviceId');
    if (saved && devices.some((d) => d.id === saved)) deviceSelect.value = saved;
  }

  deviceSelect.addEventListener('change', async () => {
    settings.set('micDeviceId', deviceSelect.value);
    if (recorder.armed) {
      recorder.release();
      await arm();
    }
  });

  async function arm() {
    try {
      await unlockAudio();
      await recorder.arm(deviceSelect.value || settings.get('micDeviceId'), {
        echoCancellation: settings.get('micEchoCancellation'),
        noiseSuppression: settings.get('micNoiseSuppression'),
        autoGain: settings.get('micAutoGain'),
      });
      state = 'armed';
      await loadDevices();
      return true;
    } catch (err) {
      if (err instanceof MicError) toast.error('Mikrofon', err.message);
      else reportError('Mikrofon', err);
      return false;
    }
  }

  recBtn.addEventListener('click', async () => {
    if (state === 'recording') return;
    if (!recorder.armed && !(await arm())) return;

    recorded = null;
    recorder.start();
    state = 'recording';
    recBtn.disabled = true;
    stopBtn.disabled = false;
    playBtn.disabled = true;
    againBtn.disabled = true;
    timeEl.textContent = '0:00.000';

    // Sicherheitsnetz: nach 10 Minuten automatisch stoppen.
    timer = setTimeout(() => stopRecording(), 10 * 60 * 1000);
  });

  function stopRecording() {
    if (state !== 'recording') return;
    clearTimeout(timer);
    recorded = recorder.stop();
    state = 'done';
    recBtn.disabled = false;
    stopBtn.disabled = true;
    playBtn.disabled = !recorded;
    againBtn.disabled = false;
    if (recorded) {
      timeEl.textContent = formatTime(recorded.duration);
      toast.ok('Aufnahme beendet', `${formatTime(recorded.duration)} aufgenommen`);
    } else {
      toast.warn('Nichts aufgenommen', 'Die Aufnahme war zu kurz.');
    }
  }

  stopBtn.addEventListener('click', stopRecording);

  playBtn.addEventListener('click', () => {
    if (!recorded) return;
    engine.previewBuffer(recorded, { gain: 0.9 }).catch((err) => reportError('Wiedergabe', err));
  });

  againBtn.addEventListener('click', () => {
    recorded = null;
    playBtn.disabled = true;
    timeEl.textContent = '0:00.000';
  });

  const modal = openModal({
    title: 'Mikrofonaufnahme',
    body,
    actions: [
      { label: 'Abbrechen', variant: 'ghost' },
      {
        label: 'Speichern',
        variant: 'primary',
        onClick: async ({ setBusy }) => {
          if (state === 'recording') stopRecording();
          if (!recorded) {
            toast.warn('Keine Aufnahme', 'Nimm zuerst etwas auf.');
            return false;
          }
          setBusy(true, 'Wird gespeichert …');
          try {
            const ctx = getContext();
            const final = normalizeToggle.checked ? normalizeBuffer(ctx, recorded, -1) : recorded;
            const bytes = encodeWav(final, { bitDepth: 16 });
            const sample = await store.addSample({
              bytes,
              mime: 'audio/wav',
              name: nameInput.value.trim() || 'Aufnahme',
              duration: final.duration,
              sampleRate: final.sampleRate,
              channels: final.numberOfChannels,
              source: 'mic',
            });
            sampleCache.set(sample.dataId, final);
            engine.invalidate();
            toast.ok('Aufnahme gespeichert', `${sample.name} · ${humanBytes(bytes.byteLength)}`);
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
      clearTimeout(timer);
      cancelAnimationFrame(raf);
      recorder.release();
    },
  });

  loadDevices();
  raf = requestAnimationFrame(drawVisual);
  // Mikrofon direkt vorbereiten, damit der Berechtigungsdialog früh erscheint.
  arm();

  return modal;
}

/* =============================================================================
   Dialog: Mit KI erstellen
   ========================================================================== */

const EXAMPLE_PROMPTS = [
  'Extremely punchy hard techno kick, deep clean sub, short tail, 155 BPM club production',
  'Crisp closed hi hat, very short, bright, techno',
  'Layered analog clap with room, punchy',
  'Deep rolling sub bass, clean sine, dark',
  'Industrial metallic percussion hit, distorted',
  'Long noise riser, building tension, 8 bars',
];

export function openAiGenerateDialog(prefill = '') {
  const body = h('div', { style: { display: 'flex', flexDirection: 'column', gap: '14px' } });

  // --- Anbieterhinweis -------------------------------------------------------
  const provider = activeProvider();
  const providerRow = h('div.settings-row');
  providerRow.appendChild(
    h('div.settings-row__label', null, [
      h('b', { text: provider.label }),
      h('span', { text: provider.description }),
    ]),
  );
  const connectBtn = h('button.btn.btn--sm', { type: 'button', text: 'KI-Einstellungen' });
  connectBtn.addEventListener('click', () => {
    modal.close();
    navigate('/ai');
  });
  providerRow.appendChild(h('div.settings-row__control', null, [connectBtn]));
  body.appendChild(providerRow);

  // --- Prompt ----------------------------------------------------------------
  const promptInput = h('textarea.textarea', {
    placeholder: EXAMPLE_PROMPTS[0],
    maxLength: 800,
    'data-autofocus': '',
  });
  promptInput.value = prefill;
  body.appendChild(
    h('div.field', null, [
      h('label.field__label', { text: 'Beschreibe den Klang' }),
      promptInput,
      h('p.field__hint', {
        text: 'Je konkreter, desto besser: Instrument, Charakter, Länge, Tempo. Deutsch und Englisch werden gleichermaßen verstanden.',
      }),
    ]),
  );

  const chips = h('div.chat__chips', { style: { padding: '0' } });
  for (const example of EXAMPLE_PROMPTS) {
    const chip = h('button.chip', { type: 'button', text: example.slice(0, 34) + (example.length > 34 ? '…' : ''), title: example });
    chip.addEventListener('click', () => {
      promptInput.value = example;
      promptInput.focus();
    });
    chips.appendChild(chip);
  }
  body.appendChild(chips);

  // --- Länge -----------------------------------------------------------------
  let lengthPreset = 'oneshot';
  let customSeconds = 4;

  const lengthSeg = segmented(
    LENGTH_PRESETS.map((p) => ({ label: p.label, value: p.id })),
    lengthPreset,
    (value) => {
      lengthPreset = value;
      customWrap.hidden = value !== 'custom';
      updateDurationHint();
    },
  );

  const customLabel = h('b', { text: '4.0 s' });
  const customSlider = slider(0.2, 30, 0.1, 4, (v) => {
    customSeconds = v;
    customLabel.textContent = `${v.toFixed(1)} s`;
    updateDurationHint();
  });
  const customWrap = h('div.field', { hidden: true }, [
    h('label.field__label', null, [document.createTextNode('Eigene Länge '), customLabel]),
    customSlider,
  ]);

  const durationHint = h('p.field__hint');
  body.appendChild(
    h('div.field', null, [h('label.field__label', { text: 'Länge' }), lengthSeg, customWrap, durationHint]),
  );

  function currentDuration() {
    return resolveDuration({
      preset: lengthPreset,
      customSeconds,
      bpm: store.project.bpm,
      prompt: promptInput.value,
    });
  }

  function updateDurationHint() {
    durationHint.textContent = `Ergibt ca. ${currentDuration().toFixed(2)} Sekunden bei ${store.project.bpm} BPM.`;
  }
  updateDurationHint();
  promptInput.addEventListener('input', updateDurationHint);

  // --- Fortschritt & Varianten ----------------------------------------------
  const progressWrap = h('div', { hidden: true });
  const progressBar = h('div.progress__bar');
  progressWrap.appendChild(h('div.progress', null, [progressBar]));
  const progressText = h('p.field__hint', { text: '' });
  progressWrap.appendChild(progressText);
  body.appendChild(progressWrap);

  const variantsWrap = h('div.variants');
  body.appendChild(variantsWrap);

  let variants = [];
  let chosen = -1;
  let abort = null;

  function renderVariants() {
    clear(variantsWrap);
    variants.forEach((variant, index) => {
      const card = h('button.variant', {
        type: 'button',
        'aria-pressed': String(chosen === index),
      });
      const top = h('div.variant__top');
      const playIcon = h('span.sitem__play');
      playIcon.appendChild(icon('play', 12));
      top.appendChild(playIcon);
      top.appendChild(h('div.variant__name', { text: variant.label }));
      top.appendChild(h('span.chip', { text: formatTime(variant.buffer.duration, false) }));
      card.appendChild(top);
      card.appendChild(waveThumb(variant.buffer, cssVar(`--t${(index % 8) + 1}`)));
      card.addEventListener('click', () => {
        chosen = index;
        engine.previewBuffer(variant.buffer, { gain: 0.9 }).catch(() => {});
        renderVariants();
      });
      variantsWrap.appendChild(card);
    });
  }

  async function generate() {
    const prompt = promptInput.value.trim();
    if (prompt.length < 3) {
      toast.warn('Prompt fehlt', 'Beschreibe kurz, welchen Klang du möchtest.');
      return false;
    }

    variants = [];
    chosen = -1;
    renderVariants();
    progressWrap.hidden = false;
    progressBar.style.width = '2%';
    progressText.textContent = 'Verbindung wird aufgebaut …';

    abort = new AbortController();
    try {
      variants = await generateVariants({
        prompt,
        duration: currentDuration(),
        count: 4,
        signal: abort.signal,
        onProgress: (value, label) => {
          progressBar.style.width = `${Math.round(clamp(value, 0, 1) * 100)}%`;
          if (label) progressText.textContent = label;
        },
      });
      chosen = 0;
      renderVariants();
      progressWrap.hidden = true;
      toast.ok('Varianten erzeugt', 'Tippe eine an, um sie zu hören.');
      return true;
    } catch (err) {
      progressWrap.hidden = true;
      if (err.name === 'AbortError') return false;
      reportError('KI-Erzeugung fehlgeschlagen', err);
      return false;
    } finally {
      abort = null;
    }
  }

  const modal = openModal({
    title: 'Sample mit KI erstellen',
    size: 'wide',
    body,
    actions: [
      {
        label: 'Abbrechen',
        variant: 'ghost',
        onClick: () => {
          abort?.abort();
          return true;
        },
      },
      {
        label: 'Erzeugen',
        onClick: async ({ setBusy }) => {
          setBusy(true, 'Klänge werden erzeugt …');
          const ok = await generate();
          setBusy(false);
          void ok;
          return false; // Dialog bleibt offen
        },
        keepOpen: true,
      },
      {
        label: 'Übernehmen',
        variant: 'primary',
        onClick: async ({ setBusy }) => {
          if (chosen < 0 || !variants[chosen]) {
            toast.warn('Keine Variante gewählt', 'Erzeuge zuerst Varianten und wähle eine aus.');
            return false;
          }
          setBusy(true, 'Wird gespeichert …');
          try {
            const prompt = promptInput.value.trim();
            const sample = await addVariantAsSample(variants[chosen], {
              prompt,
              name: guessInstrumentName(prompt) || 'KI-Sample',
            });
            toast.ok('Sample hinzugefügt', sample.name);
          } catch (err) {
            reportError('Speichern fehlgeschlagen', err);
            setBusy(false);
            return false;
          }
          return true;
        },
      },
    ],
    onClose: () => abort?.abort(),
  });

  return modal;
}

/* =============================================================================
   Dialog: Sample mit KI verändern
   ========================================================================== */

const EDIT_EXAMPLES = [
  'make the kick much harder',
  'more sub bass',
  'shorter tail',
  'make it darker',
  'turn this vocal into a techno vocal chop',
  'make it distorted and industrial',
];

export function openAiEditDialog(sampleId) {
  const sample = store.getSample(sampleId);
  if (!sample) return null;

  const provider = activeProvider();
  const body = h('div', { style: { display: 'flex', flexDirection: 'column', gap: '14px' } });

  body.appendChild(
    h('div.settings-row', null, [
      h('div.settings-row__label', null, [
        h('b', { text: provider.label }),
        h('span', {
          text: 'Das vorhandene Sample wird auf diesem Gerät umgeformt – nichts wird hochgeladen.',
        }),
      ]),
    ]),
  );

  const promptInput = h('textarea.textarea', {
    placeholder: EDIT_EXAMPLES[0],
    maxLength: 500,
    'data-autofocus': '',
  });
  body.appendChild(
    h('div.field', null, [
      h('label.field__label', { text: `Was soll mit „${sample.name}“ passieren?` }),
      promptInput,
    ]),
  );

  const chips = h('div.chat__chips', { style: { padding: '0' } });
  for (const example of EDIT_EXAMPLES) {
    const chip = h('button.chip', { type: 'button', text: example });
    chip.addEventListener('click', () => {
      promptInput.value = example;
      promptInput.focus();
    });
    chips.appendChild(chip);
  }
  body.appendChild(chips);

  let strength = 0.65;
  const strengthLabel = h('b', { text: '65 %' });
  const strengthSlider = slider(10, 95, 1, 65, (v) => {
    strength = v / 100;
    strengthLabel.textContent = `${v} %`;
  });
  body.appendChild(
    h('div.field', null, [
      h('label.field__label', null, [document.createTextNode('Stärke der Veränderung '), strengthLabel]),
      strengthSlider,
      h('p.field__hint', { text: 'Niedrig bleibt näher am Original, hoch verändert stärker.' }),
    ]),
  );

  const progressWrap = h('div', { hidden: true });
  const progressBar = h('div.progress__bar');
  progressWrap.appendChild(h('div.progress', null, [progressBar]));
  const progressText = h('p.field__hint');
  progressWrap.appendChild(progressText);
  body.appendChild(progressWrap);

  const variantsWrap = h('div.variants');
  body.appendChild(variantsWrap);

  let variants = [];
  let chosen = -1;
  let abort = null;

  function renderVariants() {
    clear(variantsWrap);

    // Original zum Vergleich
    const orig = sampleCache.peek(sample.dataId);
    if (orig) {
      const card = h('button.variant', { type: 'button' });
      const top = h('div.variant__top');
      const playIcon = h('span.sitem__play');
      playIcon.appendChild(icon('play', 12));
      top.appendChild(playIcon);
      top.appendChild(h('div.variant__name', { text: 'Original' }));
      card.appendChild(top);
      card.appendChild(waveThumb(orig, trackColorValue(sample.id)));
      card.addEventListener('click', () => engine.previewBuffer(orig, { gain: 0.9 }).catch(() => {}));
      variantsWrap.appendChild(card);
    }

    variants.forEach((variant, index) => {
      const card = h('button.variant', { type: 'button', 'aria-pressed': String(chosen === index) });
      const top = h('div.variant__top');
      const playIcon = h('span.sitem__play');
      playIcon.appendChild(icon('play', 12));
      top.appendChild(playIcon);
      top.appendChild(h('div.variant__name', { text: variant.label }));
      card.appendChild(top);
      card.appendChild(waveThumb(variant.buffer, cssVar(`--t${(index % 8) + 1}`)));
      card.addEventListener('click', () => {
        chosen = index;
        engine.previewBuffer(variant.buffer, { gain: 0.9 }).catch(() => {});
        renderVariants();
      });
      variantsWrap.appendChild(card);
    });
  }

  async function generate() {
    const prompt = promptInput.value.trim();
    if (prompt.length < 3) {
      toast.warn('Prompt fehlt', 'Beschreibe kurz die gewünschte Änderung.');
      return;
    }
    variants = [];
    chosen = -1;
    renderVariants();
    progressWrap.hidden = false;
    progressBar.style.width = '2%';
    abort = new AbortController();

    try {
      variants = await generateVariants({
        prompt,
        duration: sample.duration,
        count: 4,
        inputSampleId: sample.id,
        strength,
        signal: abort.signal,
        providerId: provider.capabilities.audioToAudio ? null : 'local',
        onProgress: (value, label) => {
          progressBar.style.width = `${Math.round(clamp(value, 0, 1) * 100)}%`;
          if (label) progressText.textContent = label;
        },
      });
      chosen = 0;
      renderVariants();
      progressWrap.hidden = true;
    } catch (err) {
      progressWrap.hidden = true;
      if (err.name !== 'AbortError') reportError('KI-Bearbeitung fehlgeschlagen', err);
    } finally {
      abort = null;
    }
  }

  renderVariants();

  const modal = openModal({
    title: `Mit KI verändern – ${sample.name}`,
    size: 'wide',
    body,
    actions: [
      {
        label: 'Abbrechen',
        variant: 'ghost',
        onClick: () => {
          abort?.abort();
          return true;
        },
      },
      {
        label: 'Erzeugen',
        keepOpen: true,
        onClick: async ({ setBusy }) => {
          setBusy(true, 'Varianten werden erzeugt …');
          await generate();
          setBusy(false);
          return false;
        },
      },
      {
        label: 'Als neue Variante',
        onClick: async ({ setBusy }) => {
          if (chosen < 0) {
            toast.warn('Keine Variante gewählt');
            return false;
          }
          setBusy(true, 'Wird gespeichert …');
          try {
            const created = await addVariantAsSample(variants[chosen], {
              prompt: promptInput.value.trim(),
              name: `${sample.name} (KI)`,
            });
            toast.ok('Als neue Variante gespeichert', created.name);
          } catch (err) {
            reportError('Speichern fehlgeschlagen', err);
            setBusy(false);
            return false;
          }
          return true;
        },
      },
      {
        label: 'Original ersetzen',
        variant: 'primary',
        onClick: async ({ setBusy }) => {
          if (chosen < 0) {
            toast.warn('Keine Variante gewählt');
            return false;
          }
          const ok = await confirmModal({
            title: 'Original ersetzen?',
            message: `„${sample.name}“ wird durch die gewählte Variante ersetzt. Mit Rückgängig lässt sich das widerrufen.`,
            confirmLabel: 'Ersetzen',
            danger: true,
          });
          if (!ok) return false;

          setBusy(true, 'Wird gespeichert …');
          try {
            await replaceSampleWithVariant(sample.id, variants[chosen]);
            toast.ok('Sample ersetzt', sample.name);
          } catch (err) {
            reportError('Ersetzen fehlgeschlagen', err);
            setBusy(false);
            return false;
          }
          return true;
        },
      },
    ],
    onClose: () => abort?.abort(),
  });

  return modal;
}
