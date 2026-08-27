/**
 * AI Groove – Launchpad (4 × 4, mehrere Bänke).
 *
 * Wichtig für die Latenz: beim Berühren wird ZUERST der Ton gestartet und
 * erst danach die Optik verändert. Die Animation läuft ausschliesslich über
 * transform/opacity und damit auf dem Compositor – sie kann den Audio-Thread
 * nicht ausbremsen.
 */

import { h, icon, clear, onLongPress, trackColorValue, slider, segmented } from '../core/dom.js';
import { store } from '../core/store.js';
import { bus, EV } from '../core/bus.js';
import { engine } from '../audio/engine.js';
import { makeDropTarget } from './dragdrop.js';
import { openContextMenu } from './contextmenu.js';
import { toast } from './toast.js';
import { openModal } from './modal.js';
import { settings } from '../core/settings.js';
import { haptic } from '../core/util.js';
import { PADS_PER_BANK } from '../core/project.js';

const MODE_LABEL = { oneshot: 'One Shot', hold: 'Hold', toggle: 'Loop' };

export function createPadsView() {
  const element = h('div.view.view--pads');
  const wrap = h('div.pads-wrap');
  element.appendChild(wrap);

  // --- Kopfleiste ------------------------------------------------------------
  const bar = h('div.pads-bar');
  const bankStrip = h('div.pat-strip', { style: { border: 'none', padding: '0', flex: '1' } });
  bar.appendChild(bankStrip);

  const addBankBtn = h('button.btn.btn--sm.btn--icon', { type: 'button', title: 'Neue Pad-Bank' });
  addBankBtn.appendChild(icon('plus', 16));
  addBankBtn.addEventListener('click', () => {
    store.addPadBank();
    toast.ok('Neue Pad-Bank angelegt');
  });
  bar.appendChild(addBankBtn);
  wrap.appendChild(bar);

  // --- Pad-Raster ------------------------------------------------------------
  const grid = h('div.pads', { 'data-tour': 'pads' });
  wrap.appendChild(grid);

  /** @type {HTMLElement[]} */
  const padEls = [];
  const cleanups = [];

  function padSettingsDialog(index) {
    const pad = store.activeBank.pads[index];
    if (!pad) return;
    const sample = store.getSample(pad.sampleId);

    const body = h('div', { style: { display: 'flex', flexDirection: 'column', gap: '16px' } });

    const modeSeg = segmented(
      [
        { label: 'One Shot', value: 'oneshot' },
        { label: 'Hold', value: 'hold' },
        { label: 'Loop', value: 'toggle' },
      ],
      pad.mode,
      (value) => store.updatePad(index, { mode: value }),
    );
    body.appendChild(h('div.field', null, [h('label.field__label', { text: 'Verhalten' }), modeSeg]));
    body.appendChild(
      h('p.field__hint', {
        text: 'One Shot: spielt einmal ab. Hold: klingt nur, solange gedrückt wird. Loop: an-/ausschalten durch Antippen.',
      }),
    );

    const gainLabel = h('b', { text: `${Math.round(pad.gain * 100)} %` });
    const gainSlider = slider(0, 200, 1, Math.round(pad.gain * 100), (v) => {
      gainLabel.textContent = `${v} %`;
      store.updatePad(index, { gain: v / 100 });
    });
    body.appendChild(
      h('div.field', null, [
        h('label.field__label', null, [document.createTextNode('Pad-Lautstärke '), gainLabel]),
        gainSlider,
      ]),
    );

    const pitchLabel = h('b', { text: `${pad.pitch > 0 ? '+' : ''}${pad.pitch} HT` });
    const pitchSlider = slider(-24, 24, 1, pad.pitch, (v) => {
      pitchLabel.textContent = `${v > 0 ? '+' : ''}${v} HT`;
      store.updatePad(index, { pitch: v });
    });
    body.appendChild(
      h('div.field', null, [
        h('label.field__label', null, [document.createTextNode('Tonhöhe '), pitchLabel]),
        pitchSlider,
      ]),
    );

    const keyInput = h('input.input.input--mono', {
      type: 'text',
      value: store.project.pads.keys[index] || '',
      maxLength: 12,
      placeholder: 'z. B. q',
    });
    keyInput.addEventListener('change', () => {
      const keys = [...store.project.pads.keys];
      keys[index] = keyInput.value.trim().slice(0, 12);
      store.setPadKeys(keys);
      render();
    });
    body.appendChild(
      h('div.field', null, [
        h('label.field__label', { text: 'Taste' }),
        keyInput,
        h('p.field__hint', { text: 'Ein Zeichen genügt, z. B. „q“. Leer lassen deaktiviert die Taste.' }),
      ]),
    );

    openModal({
      title: `Pad ${index + 1}${sample ? ` – ${sample.name}` : ''}`,
      size: 'narrow',
      body,
      actions: [{ label: 'Fertig', variant: 'primary' }],
    });
  }

  function padMenu(index, position) {
    const pad = store.activeBank.pads[index];
    const sample = pad ? store.getSample(pad.sampleId) : null;

    const items = [];
    if (sample) {
      items.push({ heading: `Pad ${index + 1} – ${sample.name}` });
      items.push({
        label: 'Pad-Einstellungen',
        icon: 'settings',
        onClick: () => padSettingsDialog(index),
      });
      items.push({
        label: `Modus: ${MODE_LABEL[pad.mode]}`,
        icon: 'loop',
        onClick: () => {
          const order = ['oneshot', 'hold', 'toggle'];
          const next = order[(order.indexOf(pad.mode) + 1) % order.length];
          store.updatePad(index, { mode: next });
          toast.info('Pad-Modus', MODE_LABEL[next]);
        },
      });
      items.push({ separator: true });
      items.push({
        label: 'Sample ersetzen …',
        icon: 'copy',
        onClick: () => replaceDialog(index),
      });
      items.push({
        label: 'Pad leeren',
        icon: 'trash',
        danger: true,
        onClick: () => store.setPad(index, null),
      });
    } else {
      items.push({ heading: `Pad ${index + 1} (leer)` });
      items.push({ label: 'Sample zuweisen …', icon: 'plus', onClick: () => replaceDialog(index) });
    }
    openContextMenu(position, items);
  }

  function replaceDialog(index) {
    const samples = store.project.samples;
    if (!samples.length) {
      toast.warn('Keine Sounds vorhanden', 'Füge zuerst ein Sample hinzu.');
      return;
    }
    const list = h('div.slist', { style: { padding: '0', maxHeight: '50vh' } });
    const modal = openModal({
      title: `Sample für Pad ${index + 1}`,
      size: 'narrow',
      body: list,
      actions: [{ label: 'Abbrechen', variant: 'ghost' }],
    });
    for (const s of samples) {
      const row = h('button.sitem', { type: 'button', style: { width: '100%' } });
      row.appendChild(h('div.sitem__name', { text: s.name, style: { flex: '1' } }));
      row.addEventListener('click', () => {
        store.setPad(index, s.id);
        modal.close();
      });
      list.appendChild(row);
    }
  }

  function buildPad(index) {
    const pad = store.activeBank.pads[index];
    const sample = pad ? store.getSample(pad.sampleId) : null;
    const key = store.project.pads.keys[index];

    const el = h(`button.pad${sample ? '.pad--filled' : '.pad--empty'}`, {
      type: 'button',
      dataset: { pad: String(index) },
      'aria-label': sample ? `Pad ${index + 1}: ${sample.name}` : `Pad ${index + 1}, leer`,
      style: sample ? { '--pad-color': trackColorValue(sample.id) } : null,
    });

    el.appendChild(h('span.pad__glow'));
    if (key && settings.get('showKeyLabels')) el.appendChild(h('span.pad__key', { text: key.toUpperCase() }));
    if (pad && pad.mode !== 'oneshot') el.appendChild(h('span.pad__mode', { text: MODE_LABEL[pad.mode] }));

    if (sample) {
      el.appendChild(h('span.pad__label', { text: sample.name }));
    } else {
      el.appendChild(icon('plus', 20));
    }

    // --- Auslösen ------------------------------------------------------------
    const down = (event) => {
      if (event.pointerType === 'mouse' && event.button !== 0) return;
      if (!sample) {
        replaceDialog(index);
        return;
      }
      // 1. Ton (sofort)
      engine.triggerPad(index, { velocity: 1 });
      // 2. Optik
      el.classList.add('pad--down');
      el.classList.remove('pad--hit');
      // Neustart der Animation erzwingen
      void el.offsetWidth;
      el.classList.add('pad--hit');
      haptic(9);
      try {
        el.setPointerCapture(event.pointerId);
      } catch (_) {
        /* egal */
      }
      event.preventDefault();
    };

    const up = () => {
      el.classList.remove('pad--down');
      if (sample) {
        engine.releasePad(index);
        el.classList.toggle('pad--on', engine.padIsOn(index));
      }
    };

    el.addEventListener('pointerdown', down);
    el.addEventListener('pointerup', up);
    el.addEventListener('pointercancel', up);
    el.addEventListener('pointerleave', (event) => {
      if (event.buttons) up();
    });
    el.addEventListener('animationend', () => el.classList.remove('pad--hit'));

    onLongPress(el, ({ x, y }) => padMenu(index, { x, y }));

    cleanups.push(
      makeDropTarget(el, {
        accepts: (payload) => payload?.type === 'sample',
        onDrop: (payload) => {
          store.setPad(index, payload.sampleId);
          toast.ok('Pad belegt', `Pad ${index + 1}: ${payload.name}`);
        },
      }),
    );

    return el;
  }

  function renderBanks() {
    clear(bankStrip);
    store.project.pads.banks.forEach((bank, i) => {
      const chip = h('button.pat-chip', {
        type: 'button',
        'aria-selected': String(i === store.project.pads.activeBank),
        text: bank.name,
      });
      chip.addEventListener('click', () => {
        store.setActiveBank(i);
        render();
      });
      onLongPress(chip, ({ x, y }) =>
        openContextMenu(
          { x, y },
          [
            { heading: bank.name },
            {
              label: 'Bank entfernen',
              icon: 'trash',
              danger: true,
              disabled: store.project.pads.banks.length <= 1,
              onClick: () => {
                store.removePadBank(i);
                render();
              },
            },
          ],
        ),
      );
      bankStrip.appendChild(chip);
    });
  }

  function render() {
    for (const off of cleanups.splice(0)) off();
    renderBanks();
    clear(grid);
    padEls.length = 0;
    for (let i = 0; i < PADS_PER_BANK; i++) {
      const el = buildPad(i);
      padEls.push(el);
      grid.appendChild(el);
    }
  }

  // --- Tastatur --------------------------------------------------------------
  const pressedKeys = new Set();

  function onKeyDown(event) {
    if (event.metaKey || event.ctrlKey || event.altKey) return;
    const target = event.target;
    if (target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.isContentEditable)) return;
    const key = event.key.toLowerCase();
    if (pressedKeys.has(key)) return;
    const index = store.project.pads.keys.findIndex((k) => (k || '').toLowerCase() === key);
    if (index < 0) return;
    pressedKeys.add(key);
    engine.triggerPad(index, { velocity: 1 });
    const el = padEls[index];
    if (el) {
      el.classList.add('pad--down', 'pad--hit');
    }
    event.preventDefault();
  }

  function onKeyUp(event) {
    const key = event.key.toLowerCase();
    if (!pressedKeys.delete(key)) return;
    const index = store.project.pads.keys.findIndex((k) => (k || '').toLowerCase() === key);
    if (index < 0) return;
    engine.releasePad(index);
    const el = padEls[index];
    if (el) {
      el.classList.remove('pad--down', 'pad--hit');
      el.classList.toggle('pad--on', engine.padIsOn(index));
    }
  }

  const offPads = bus.on(EV.PADS_CHANGED, render);
  const offSamples = bus.on(EV.SAMPLES_CHANGED, render);
  const offLoaded = bus.on(EV.PROJECT_LOADED, render);
  const offTrigger = bus.on(EV.PAD_TRIGGERED, ({ index, on }) => {
    const el = padEls[index];
    if (el) el.classList.toggle('pad--on', !!on && engine.padIsOn(index));
  });

  render();

  return {
    element,
    onShow() {
      window.addEventListener('keydown', onKeyDown);
      window.addEventListener('keyup', onKeyUp);
      render();
    },
    onHide() {
      window.removeEventListener('keydown', onKeyDown);
      window.removeEventListener('keyup', onKeyUp);
    },
    destroy() {
      this.onHide();
      offPads();
      offSamples();
      offLoaded();
      offTrigger();
      for (const off of cleanups.splice(0)) off();
    },
  };
}
