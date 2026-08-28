/**
 * AI Groove – Transportleiste.
 *
 * Wiedergabe, Aufnahme, Tempo, Swing, Metronom, Loop, Undo/Redo und Export.
 * Die Anzeige der Position läuft über requestAnimationFrame und liest die Zeit
 * aus der Audioengine – sie beeinflusst das Timing selbst nicht.
 */

import { h, icon, slider, makeDraggableValue, segmented } from '../core/dom.js';
import { store } from '../core/store.js';
import { bus, EV } from '../core/bus.js';
import { engine } from '../audio/engine.js';
import { toast } from './toast.js';
import { openExportDialog } from './exportui.js';
import { formatBBT, clamp } from '../core/util.js';

export function createTransport() {
  const element = h('div.transport', { 'data-tour': 'transport' });

  // --- Wiedergabe ------------------------------------------------------------
  const group1 = h('div.transport__group');

  const playBtn = h('button.play-btn', {
    type: 'button',
    'aria-pressed': 'false',
    'aria-label': 'Abspielen',
    title: 'Abspielen / Stopp (Leertaste)',
  });
  playBtn.appendChild(icon('play', 20));
  playBtn.addEventListener('click', () => togglePlay());
  group1.appendChild(playBtn);

  const stopBtn = h('button.btn.btn--icon', { type: 'button', title: 'Stopp und an den Anfang' });
  stopBtn.appendChild(icon('stop', 15));
  stopBtn.addEventListener('click', () => {
    engine.stop();
    engine.seek(0);
    updatePosition();
  });
  group1.appendChild(stopBtn);

  const recBtn = h('button.btn.btn--icon.btn--rec', {
    type: 'button',
    'aria-pressed': 'false',
    title: 'Live-Aufnahme der Pads',
  });
  recBtn.appendChild(icon('record', 15));
  recBtn.addEventListener('click', () => toggleRecord());
  group1.appendChild(recBtn);

  element.appendChild(group1);
  element.appendChild(h('div.transport__sep'));

  // --- Position & Modus ------------------------------------------------------
  const group2 = h('div.transport__group');
  const posEl = h('div.pos', { title: 'Takt.Schlag.Sechzehntel' });
  posEl.appendChild(h('span', { text: '1.1.1' }));
  group2.appendChild(posEl);

  const modeSeg = segmented(
    [
      { label: 'Pattern', value: 'pattern' },
      { label: 'Song', value: 'song' },
    ],
    engine.mode,
    (value) => engine.setMode(value),
  );
  group2.appendChild(modeSeg);

  // --- Tempo -----------------------------------------------------------------
  const group3 = h('div.transport__group');
  const bpmWrap = h('div.bpm', { 'data-tour': 'bpm' });
  const bpmMinus = h('button.bpm__step', { type: 'button', text: '−', title: 'Langsamer' });
  const bpmInput = h('input.bpm__val', {
    type: 'text',
    inputMode: 'decimal',
    value: '128',
    'aria-label': 'Tempo in BPM',
    title: 'Tippen zum Eingeben, vertikal ziehen zum Ändern',
  });
  const bpmPlus = h('button.bpm__step', { type: 'button', text: '+', title: 'Schneller' });
  bpmWrap.appendChild(bpmMinus);
  bpmWrap.appendChild(bpmInput);
  bpmWrap.appendChild(bpmPlus);
  bpmWrap.appendChild(h('span.bpm__unit', { text: 'BPM' }));
  group3.appendChild(bpmWrap);

  bpmMinus.addEventListener('click', () => store.setBpm(store.project.bpm - 1));
  bpmPlus.addEventListener('click', () => store.setBpm(store.project.bpm + 1));

  bpmInput.addEventListener('change', () => {
    const value = parseFloat(bpmInput.value.replace(',', '.'));
    if (Number.isFinite(value)) store.setBpm(value);
    else bpmInput.value = String(store.project.bpm);
  });
  bpmInput.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') bpmInput.blur();
    if (event.key === 'ArrowUp') {
      store.setBpm(store.project.bpm + (event.shiftKey ? 10 : 1));
      event.preventDefault();
    }
    if (event.key === 'ArrowDown') {
      store.setBpm(store.project.bpm - (event.shiftKey ? 10 : 1));
      event.preventDefault();
    }
  });

  // Vertikales Ziehen ändert das Tempo.
  let dragStartBpm = 128;
  makeDraggableValue(bpmInput, {
    onStart: () => {
      dragStartBpm = store.project.bpm;
      bpmInput.blur();
    },
    onMove: (dy) => {
      store.setBpm(dragStartBpm + Math.round(dy / 3));
    },
  });

  // Tap Tempo
  const tapBtn = h('button.btn.btn--sm', { type: 'button', text: 'TAP', title: 'Tempo eintippen' });
  const taps = [];
  tapBtn.addEventListener('click', () => {
    const now = performance.now();
    if (taps.length && now - taps[taps.length - 1] > 2200) taps.length = 0;
    taps.push(now);
    if (taps.length > 6) taps.shift();
    if (taps.length >= 2) {
      let sum = 0;
      for (let i = 1; i < taps.length; i++) sum += taps[i] - taps[i - 1];
      const avg = sum / (taps.length - 1);
      const bpm = clamp(Math.round((60000 / avg) * 10) / 10, 40, 250);
      store.setBpm(bpm);
      tapBtn.textContent = String(Math.round(bpm));
      setTimeout(() => {
        tapBtn.textContent = 'TAP';
      }, 900);
    }
  });
  group3.appendChild(tapBtn);
  // Reihenfolge: Transport → Tempo → Position/Modus. So bleibt das Tempo auf
  // schmalen Bildschirmen ohne Scrollen erreichbar.
  element.appendChild(group3);
  element.appendChild(h('div.transport__sep'));
  element.appendChild(group2);
  element.appendChild(h('div.transport__sep'));

  // --- Swing / Metronom / Loop ----------------------------------------------
  const group4 = h('div.transport__group');

  const swingKnob = h('div.mini-knob');
  const swingLabel = h('div.mini-knob__label', null, [
    h('span', { text: 'SWING' }),
    h('b', { text: '0 %' }),
  ]);
  const swingSlider = slider(0, 100, 1, 0, (v) => {
    swingLabel.lastChild.textContent = `${v} %`;
    store.setSwing(v / 100);
  });
  swingKnob.appendChild(swingLabel);
  swingKnob.appendChild(swingSlider);
  group4.appendChild(swingKnob);

  const metroBtn = h('button.btn.btn--icon', {
    type: 'button',
    'aria-pressed': 'false',
    title: 'Metronom',
  });
  metroBtn.appendChild(icon('metronome', 16));
  metroBtn.addEventListener('click', () => {
    const enabled = !store.project.metronome.enabled;
    store.setMetronome({ enabled });
    metroBtn.setAttribute('aria-pressed', String(enabled));
  });
  group4.appendChild(metroBtn);

  const loopBtn = h('button.btn.btn--icon', { type: 'button', 'aria-pressed': 'false', title: 'Loop' });
  loopBtn.appendChild(icon('loop', 16));
  loopBtn.addEventListener('click', () => {
    const enabled = !store.project.loop.enabled;
    store.setLoop({ enabled });
    loopBtn.setAttribute('aria-pressed', String(enabled));
    toast.info('Loop', enabled ? 'Loop-Bereich aktiv' : 'Loop aus');
  });
  group4.appendChild(loopBtn);
  element.appendChild(group4);

  element.appendChild(h('div.transport__spacer'));

  // --- Master, Undo/Redo, Export --------------------------------------------
  const group5 = h('div.transport__group');

  const masterKnob = h('div.mini-knob');
  const masterLabel = h('div.mini-knob__label', null, [
    h('span', { text: 'MASTER' }),
    h('b', { text: '80 %' }),
  ]);
  const masterSlider = slider(0, 130, 1, 80, (v) => {
    masterLabel.lastChild.textContent = `${v} %`;
    store.project.master.gain = v / 100;
    engine.graph?.sync(store.project);
    store.markDirty();
  });
  masterSlider.addEventListener('change', () => {
    const value = masterSlider.valueAsNumber / 100;
    store.history.run('Master-Lautstärke', ['master'], () => {
      store.project.master.gain = value;
    });
    bus.emit(EV.MIXER_CHANGED);
  });
  masterKnob.appendChild(masterLabel);
  masterKnob.appendChild(masterSlider);
  group5.appendChild(masterKnob);

  const undoBtn = h('button.btn.btn--icon', { type: 'button', title: 'Rückgängig (Ctrl/Cmd+Z)', disabled: true });
  undoBtn.appendChild(icon('undo', 16));
  undoBtn.addEventListener('click', () => {
    const label = store.history.undo();
    if (label) toast.info('Rückgängig', label);
  });
  group5.appendChild(undoBtn);

  const redoBtn = h('button.btn.btn--icon', {
    type: 'button',
    title: 'Wiederholen (Ctrl/Cmd+Shift+Z)',
    disabled: true,
  });
  redoBtn.appendChild(icon('redo', 16));
  redoBtn.addEventListener('click', () => {
    const label = store.history.redo();
    if (label) toast.info('Wiederholt', label);
  });
  group5.appendChild(redoBtn);

  const exportBtn = h('button.btn', { type: 'button', title: 'Track exportieren', 'data-tour': 'export' });
  exportBtn.appendChild(icon('download', 16));
  exportBtn.appendChild(h('span', { text: 'Export' }));
  exportBtn.addEventListener('click', () => openExportDialog());
  group5.appendChild(exportBtn);

  element.appendChild(group5);

  // --- Verhalten -------------------------------------------------------------

  async function togglePlay() {
    if (engine.playing) {
      engine.stop({ keepPosition: true });
    } else {
      await engine.play({ record: recBtn.getAttribute('aria-pressed') === 'true' });
    }
    syncPlayButton();
  }

  async function toggleRecord() {
    const armed = recBtn.getAttribute('aria-pressed') === 'true';
    if (engine.playing && engine.recording) {
      engine.stop();
      recBtn.setAttribute('aria-pressed', 'false');
      syncPlayButton();
      return;
    }
    const next = !armed;
    recBtn.setAttribute('aria-pressed', String(next));
    if (next) {
      if (!store.selectedPattern) {
        store.addPattern({});
      }
      toast.info(
        'Aufnahme scharf',
        store.project.metronome.countIn > 0
          ? `Start mit ${store.project.metronome.countIn} Takt(en) Count-In.`
          : 'Beim Start werden Pad-Anschläge aufgezeichnet.',
      );
    }
  }

  function syncPlayButton() {
    const playing = engine.playing;
    playBtn.replaceChildren(icon(playing ? 'pause' : 'play', 20));
    playBtn.setAttribute('aria-pressed', String(playing));
    playBtn.setAttribute('aria-label', playing ? 'Pause' : 'Abspielen');
    if (!playing && recBtn.getAttribute('aria-pressed') === 'true' && !engine.recording) {
      recBtn.setAttribute('aria-pressed', 'false');
    }
  }

  function updatePosition() {
    const ticks = engine.position;
    const label = engine.inCountIn ? 'Count-In …' : formatBBT(ticks, store.project.beatsPerBar);
    posEl.firstChild.textContent = label;
  }

  function syncFromProject() {
    const p = store.project;
    if (document.activeElement !== bpmInput) bpmInput.value = String(p.bpm);
    swingSlider.setValue(Math.round(p.swing * 100));
    swingLabel.lastChild.textContent = `${Math.round(p.swing * 100)} %`;
    masterSlider.setValue(Math.round(p.master.gain * 100));
    masterLabel.lastChild.textContent = `${Math.round(p.master.gain * 100)} %`;
    metroBtn.setAttribute('aria-pressed', String(p.metronome.enabled));
    loopBtn.setAttribute('aria-pressed', String(p.loop.enabled));
    modeSeg.setValue(engine.mode);
    syncPlayButton();
  }

  function syncHistory() {
    undoBtn.disabled = !store.history.canUndo;
    redoBtn.disabled = !store.history.canRedo;
    undoBtn.title = store.history.canUndo
      ? `Rückgängig: ${store.history.undoLabel} (Ctrl/Cmd+Z)`
      : 'Rückgängig (Ctrl/Cmd+Z)';
    redoBtn.title = store.history.canRedo
      ? `Wiederholen: ${store.history.redoLabel}`
      : 'Wiederholen (Ctrl/Cmd+Shift+Z)';
  }

  let raf = 0;
  function loop() {
    updatePosition();
    raf = requestAnimationFrame(loop);
  }

  const offTransport = bus.on(EV.TRANSPORT_CHANGED, syncFromProject);
  const offHistory = bus.on(EV.HISTORY_CHANGED, syncHistory);
  const offLoaded = bus.on(EV.PROJECT_LOADED, () => {
    syncFromProject();
    syncHistory();
  });
  const offPlay = bus.on(EV.TRANSPORT_PLAY, syncPlayButton);
  const offStop = bus.on(EV.TRANSPORT_STOP, syncPlayButton);

  syncFromProject();
  syncHistory();
  raf = requestAnimationFrame(loop);

  return {
    element,
    togglePlay,
    toggleRecord,
    destroy() {
      cancelAnimationFrame(raf);
      offTransport();
      offHistory();
      offLoaded();
      offPlay();
      offStop();
    },
  };
}
