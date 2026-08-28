/**
 * AI Groove – Einstellungen.
 */

import { h, icon, clear, toggle, segmented, slider } from '../core/dom.js';
import { settings } from '../core/settings.js';
import { bus, EV } from '../core/bus.js';
import { store } from '../core/store.js';
import { navigate } from './router.js';
import { toast, reportError } from './toast.js';
import { confirmModal } from './modal.js';
import { listMicrophones, listOutputs } from '../audio/recorder.js';
import { setOutputDevice, unlockAudio } from '../audio/context.js';
import { estimateStorage, wipeAll, requestPersistence } from '../core/idb.js';
import { engine } from '../audio/engine.js';
import { samples as sampleCache } from '../audio/sampler.js';
import { clearPeaks } from '../audio/peaks.js';
import { humanBytes } from '../core/util.js';
import { openTutorial } from './tutorial.js';
import { DEFAULT_PAD_KEYS } from '../core/project.js';

const SHORTCUTS = [
  ['Leertaste', 'Wiedergabe starten / anhalten'],
  ['Ctrl/Cmd + Z', 'Rückgängig'],
  ['Ctrl/Cmd + Shift + Z', 'Wiederholen'],
  ['Ctrl/Cmd + E', 'Track exportieren'],
  ['Ctrl/Cmd + S', 'Sofort speichern'],
  ['Ctrl/Cmd + 1 … 5', 'Ansicht wechseln'],
  ['Ctrl/Cmd + A', 'Alles auswählen (Piano Roll / Arrangement)'],
  ['Ctrl/Cmd + C / V', 'Kopieren / Einfügen'],
  ['Ctrl/Cmd + D', 'Duplizieren'],
  ['Entf / Backspace', 'Auswahl löschen'],
  ['Zwei Finger', 'Raster verschieben und zoomen'],
  ['Langes Antippen', 'Kontextmenü'],
];

function group(title, rows) {
  const box = h('div.settings-group');
  box.appendChild(h('h2.section-title', { text: title }));
  for (const r of rows) if (r) box.appendChild(r);
  return box;
}

function settingRow(label, hint, control) {
  return h('div.settings-row', null, [
    h('div.settings-row__label', null, [h('b', { text: label }), hint ? h('span', { text: hint }) : null]),
    h('div.settings-row__control', null, [control]),
  ]);
}

export function createSettingsView() {
  const element = h('div.page');
  const inner = h('div.page__inner');
  element.appendChild(inner);

  const storageLine = h('p.field__hint');
  const micSelect = h('select.select');
  const outSelect = h('select.select');

  async function loadDevices() {
    // Ohne erteilte Berechtigung liefert der Browser keine Gerätenamen.
    const [mics, outs] = await Promise.all([listMicrophones(), listOutputs()]);

    clear(micSelect);
    micSelect.appendChild(h('option', { value: '', text: 'Standardmikrofon' }));
    for (const d of mics) micSelect.appendChild(h('option', { value: d.id, text: d.label }));
    micSelect.value = settings.get('micDeviceId') || '';

    clear(outSelect);
    outSelect.appendChild(h('option', { value: '', text: 'Standardausgang' }));
    for (const d of outs) outSelect.appendChild(h('option', { value: d.id, text: d.label }));
    outSelect.value = settings.get('outputDeviceId') || '';
  }

  async function refreshStorage() {
    const est = await estimateStorage();
    const projects = await store.listProjects().catch(() => []);
    storageLine.textContent = est.supported
      ? `${humanBytes(est.usage)} von ca. ${humanBytes(est.quota)} belegt · ${projects.length} Projekt(e) lokal gespeichert.`
      : `${projects.length} Projekt(e) lokal gespeichert.`;
  }

  function build() {
    clear(inner);

    // --- Kopf --------------------------------------------------------------
    const head = h('div.page-head');
    const backBtn = h('button.btn.btn--ghost.btn--sm', { type: 'button' });
    const backIcon = icon('chevron', 16);
    backIcon.style.transform = 'rotate(180deg)';
    backBtn.appendChild(backIcon);
    backBtn.appendChild(h('span', { text: 'Zurück' }));
    backBtn.addEventListener('click', () => history.back());
    head.appendChild(backBtn);
    head.appendChild(h('h1', { text: 'Einstellungen', style: { fontSize: '22px' } }));
    inner.appendChild(head);

    // --- Darstellung -------------------------------------------------------
    const themeSeg = segmented(
      [
        { label: 'Dunkel', value: 'dark' },
        { label: 'Hell', value: 'light' },
        { label: 'System', value: 'auto' },
      ],
      settings.get('theme'),
      (value) => settings.set('theme', value),
    );

    const motionToggle = toggle('', settings.get('reduceMotion'), (v) => settings.set('reduceMotion', v));
    const keyLabelToggle = toggle('', settings.get('showKeyLabels'), (v) => {
      settings.set('showKeyLabels', v);
      bus.emit(EV.PADS_CHANGED);
    });

    inner.appendChild(
      group('Darstellung', [
        settingRow('Farbschema', 'Dunkel, hell oder wie das Betriebssystem.', themeSeg),
        settingRow('Animationen reduzieren', 'Weniger Bewegung – schont Akku und hilft bei Bewegungsempfindlichkeit.', motionToggle),
        settingRow('Tastenkürzel auf Pads anzeigen', 'Blendet die zugewiesene Taste in der Pad-Ecke ein.', keyLabelToggle),
      ]),
    );

    // --- Audio -------------------------------------------------------------
    const perfSeg = segmented(
      [
        { label: 'Niedrigste Latenz', value: 'low-latency' },
        { label: 'Ausgewogen', value: 'balanced' },
        { label: 'Akku schonen', value: 'battery' },
      ],
      settings.get('performanceMode'),
      (value) => {
        settings.set('performanceMode', value);
        toast.info('Leistungsmodus', 'Wird beim nächsten Start des Audiosystems vollständig wirksam.');
      },
    );

    const bpmInput = h('input.input', {
      type: 'number',
      min: 40,
      max: 250,
      value: settings.get('defaultBpm'),
      style: { maxWidth: '110px' },
    });
    bpmInput.addEventListener('change', () => {
      settings.set('defaultBpm', Math.max(40, Math.min(250, Number(bpmInput.value) || 128)));
    });

    outSelect.addEventListener('change', async () => {
      settings.set('outputDeviceId', outSelect.value);
      const result = await setOutputDevice(outSelect.value);
      if (!result.ok) toast.warn('Audioausgang', result.reason);
      else toast.ok('Audioausgang geändert');
    });

    inner.appendChild(
      group('Audio', [
        settingRow('Leistungsmodus', 'Beeinflusst Puffergrösse und Vorausschau des Schedulers.', perfSeg),
        settingRow('Standard-Tempo', 'Wird für neue Projekte verwendet.', bpmInput),
        settingRow(
          'Audioausgang',
          'Nur Chromium-Browser erlauben die Auswahl. Safari und Firefox nutzen immer den Systemausgang.',
          outSelect,
        ),
      ]),
    );

    // --- Mikrofon ----------------------------------------------------------
    const micTestBtn = h('button.btn.btn--sm', { type: 'button' });
    micTestBtn.appendChild(icon('mic', 15));
    micTestBtn.appendChild(h('span', { text: 'Zugriff prüfen' }));
    micTestBtn.addEventListener('click', async () => {
      try {
        await unlockAudio();
        const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        for (const track of stream.getTracks()) track.stop();
        await loadDevices();
        toast.ok('Mikrofon bereit', 'Der Zugriff wurde erlaubt.');
      } catch (err) {
        toast.error(
          'Mikrofon nicht verfügbar',
          'Bitte den Zugriff für diese Website erlauben (Browser- bzw. iOS-Einstellungen).',
        );
      }
    });

    micSelect.addEventListener('change', () => settings.set('micDeviceId', micSelect.value));

    const ecToggle = toggle('', settings.get('micEchoCancellation'), (v) => settings.set('micEchoCancellation', v));
    const nsToggle = toggle('', settings.get('micNoiseSuppression'), (v) => settings.set('micNoiseSuppression', v));
    const agcToggle = toggle('', settings.get('micAutoGain'), (v) => settings.set('micAutoGain', v));

    inner.appendChild(
      group('Mikrofon', [
        settingRow('Eingabegerät', 'Gerätenamen erscheinen erst nach erteilter Berechtigung.', micSelect),
        settingRow('Berechtigung', 'Prüft den Zugriff und lädt die Geräteliste neu.', micTestBtn),
        settingRow('Echounterdrückung', 'Für Musikaufnahmen meist besser ausgeschaltet.', ecToggle),
        settingRow('Rauschunterdrückung', 'Kann Klangdetails entfernen – für Instrumente aus lassen.', nsToggle),
        settingRow('Automatische Aussteuerung', 'Verändert die Lautstärke während der Aufnahme.', agcToggle),
      ]),
    );

    // --- Metronom / Aufnahme ----------------------------------------------
    const metroToggle = toggle('', store.project.metronome.enabled, (v) => store.setMetronome({ enabled: v }));

    const metroVolLabel = h('b', { text: `${Math.round(store.project.metronome.gain * 100)} %` });
    const metroVol = slider(0, 100, 1, Math.round(store.project.metronome.gain * 100), (v) => {
      metroVolLabel.textContent = `${v} %`;
      store.setMetronome({ gain: v / 100 });
    });

    const countInSeg = segmented(
      [
        { label: 'Aus', value: 0 },
        { label: '1 Takt', value: 1 },
        { label: '2 Takte', value: 2 },
      ],
      store.project.metronome.countIn,
      (value) => store.setMetronome({ countIn: value }),
    );

    const quantToggle = toggle('', settings.get('quantizeRecording'), (v) => settings.set('quantizeRecording', v));
    const quantSeg = segmented(
      [
        { label: '1/4', value: 4 },
        { label: '1/8', value: 8 },
        { label: '1/16', value: 16 },
        { label: '1/32', value: 32 },
      ],
      settings.get('quantizeGrid'),
      (value) => settings.set('quantizeGrid', value),
    );

    inner.appendChild(
      group('Metronom & Live-Aufnahme', [
        settingRow('Metronom', 'Klick zum Takt.', metroToggle),
        settingRow('Metronom-Lautstärke', '', h('div', { style: { minWidth: '160px' } }, [metroVolLabel, metroVol])),
        settingRow('Count-In', 'Vorzähler vor der Aufnahme.', countInSeg),
        settingRow('Aufnahme quantisieren', 'Setzt Pad-Anschläge auf das Raster.', quantToggle),
        settingRow('Quantisierungsraster', '', quantSeg),
      ]),
    );

    // --- Tastenbelegung ----------------------------------------------------
    const keyTable = h('table.kbd-table');
    store.project.pads.keys.forEach((key, index) => {
      const tr = h('tr');
      tr.appendChild(h('td', { text: `Pad ${index + 1}` }));
      const td = h('td');
      const input = h('input.input.input--mono', {
        type: 'text',
        value: key,
        maxLength: 12,
        style: { maxWidth: '90px', textAlign: 'center', minHeight: '34px' },
      });
      input.addEventListener('change', () => {
        const keys = [...store.project.pads.keys];
        keys[index] = input.value.trim().slice(0, 12);
        store.setPadKeys(keys);
      });
      td.appendChild(input);
      tr.appendChild(td);
      keyTable.appendChild(tr);
    });

    const resetKeys = h('button.btn.btn--sm', { type: 'button', text: 'Standardbelegung wiederherstellen' });
    resetKeys.addEventListener('click', () => {
      store.setPadKeys([...DEFAULT_PAD_KEYS]);
      build();
      toast.ok('Tastenbelegung zurückgesetzt');
    });

    const shortcutTable = h('table.kbd-table');
    for (const [key, description] of SHORTCUTS) {
      const tr = h('tr');
      tr.appendChild(h('td', { text: description }));
      const td = h('td');
      td.appendChild(h('kbd', { text: key }));
      tr.appendChild(td);
      shortcutTable.appendChild(tr);
    }

    inner.appendChild(group('Tastenbelegung der Pads', [keyTable, resetKeys]));
    inner.appendChild(group('Tastenkürzel', [shortcutTable]));

    // --- KI ----------------------------------------------------------------
    const aiBtn = h('button.btn.btn--sm', { type: 'button' });
    aiBtn.appendChild(icon('key', 15));
    aiBtn.appendChild(h('span', { text: 'KI-Einstellungen' }));
    aiBtn.addEventListener('click', () => navigate('/ai'));

    inner.appendChild(
      group('KI', [
        settingRow(
          'GrooveNet',
          'Die eigene KI von AI Groove. Läuft im Browser: ohne Key, ohne Kosten, ohne Internet. Hier lässt sich die Rechenqualität einstellen.',
          aiBtn,
        ),
      ]),
    );

    // --- Speicher ----------------------------------------------------------
    const cleanupBtn = h('button.btn.btn--sm', { type: 'button', text: 'Ungenutzte Audiodaten entfernen' });
    cleanupBtn.addEventListener('click', async () => {
      const removed = await store.cleanup();
      await refreshStorage();
      toast.ok('Aufgeräumt', removed ? `${removed} verwaiste Audiodatei(en) entfernt.` : 'Nichts zu entfernen.');
    });

    const persistBtn = h('button.btn.btn--sm', { type: 'button', text: 'Speicher dauerhaft anfordern' });
    persistBtn.addEventListener('click', async () => {
      const ok = await requestPersistence();
      toast.info(
        'Dauerhafter Speicher',
        ok
          ? 'Der Browser behält deine Projekte auch bei knappem Speicher.'
          : 'Der Browser hat das abgelehnt – Projekte können bei Speichermangel gelöscht werden. Bitte wichtige Projekte exportieren.',
      );
    });

    const wipeBtn = h('button.btn.btn--sm.btn--danger', { type: 'button', text: 'Temporären Speicher löschen' });
    wipeBtn.addEventListener('click', async () => {
      const ok = await confirmModal({
        title: 'Alle lokalen Daten löschen?',
        message:
          'Alle Projekte, Samples und Aufnahmen in diesem Browser werden entfernt. Exportierte .aigroove-Dateien bleiben erhalten. Das lässt sich nicht rückgängig machen.',
        confirmLabel: 'Endgültig löschen',
        danger: true,
      });
      if (!ok) return;
      try {
        engine.stop();
        await wipeAll();
        sampleCache.clear();
        clearPeaks();
        await store.newProject('Neues Projekt');
        await refreshStorage();
        toast.ok('Lokaler Speicher geleert');
      } catch (err) {
        reportError('Löschen fehlgeschlagen', err);
      }
    });

    inner.appendChild(
      group('Lokaler Speicher', [
        storageLine,
        h('div.sed__tools', null, [cleanupBtn, persistBtn, wipeBtn]),
        h('p.field__hint', {
          text: 'Projekte liegen ausschliesslich in diesem Browser (IndexedDB) und werden nach etwa 12 Stunden Inaktivität als temporär abgelaufen markiert. Für dauerhafte Sicherung: als .aigroove-Datei exportieren.',
        }),
      ]),
    );

    // --- Einführung --------------------------------------------------------
    const tutorialBtn = h('button.btn.btn--sm', { type: 'button', text: 'Einführung erneut starten' });
    tutorialBtn.addEventListener('click', () => {
      settings.set('tutorialDone', false);
      navigate('/studio');
      setTimeout(() => openTutorial(), 400);
    });

    inner.appendChild(
      group('Einführung', [
        settingRow('Kurze Einführung', 'Zeigt die fünf Schritte vom Sound bis zum Export erneut.', tutorialBtn),
      ]),
    );

    inner.appendChild(
      h('p.field__hint', {
        text: 'AI Groove 1.2.1 · Läuft vollständig in deinem Browser. Eigene KI, keine Konten, keine Projektdaten auf dem Server.',
      }),
    );

    loadDevices();
    refreshStorage();
  }

  return {
    element,
    onShow(params) {
      build();
      if (params?.get('section') === 'mic') {
        setTimeout(() => micSelect.scrollIntoView({ block: 'center', behavior: 'smooth' }), 120);
      }
    },
  };
}
