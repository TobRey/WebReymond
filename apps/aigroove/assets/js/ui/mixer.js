/**
 * AI Groove – Mixer.
 *
 * Ein Kanal pro Instrument plus Master. Bewusst ohne komplizierte
 * Routing-Matrix: Lautstärke, Panorama, Mute, Solo, Pegel und Effektkette –
 * das deckt die Praxis ab und bleibt auf dem Handy bedienbar.
 */

import { h, icon, clear, trackColorValue, cssVar, makeDraggableValue, onLongPress, slider } from '../core/dom.js';
import { store } from '../core/store.js';
import { bus, EV } from '../core/bus.js';
import { engine } from '../audio/engine.js';
import { openContextMenu } from './contextmenu.js';
import { promptModal } from './modal.js';
import { toast } from './toast.js';
import { FX_DEFS, FX_ORDER, createEffectState } from '../audio/effects.js';
import { clamp, gainToDb, faderToGain, gainToFader } from '../core/util.js';

/** Pegel (0…1+) auf eine gut lesbare Höhe abbilden (dB-artig). */
function meterHeight(peak) {
  if (peak <= 0.0005) return 0;
  const db = gainToDb(peak);
  return clamp((db + 60) / 60, 0, 1);
}

export function createMixerView() {
  const element = h('div.view.view--mixer');
  const holder = h('div.view__body');
  element.appendChild(holder);

  const strip = h('div.mixer');
  holder.appendChild(strip);

  const channels = new Map();
  let raf = 0;

  function buildChannel(id, name, color, isMaster = false) {
    const state = store.getChannel(id);
    const el = h(`div.mixch${isMaster ? '.mixch--master' : ''}`, {
      style: { '--ch-color': color },
      dataset: { channel: id },
    });

    el.appendChild(h('div.mixch__color'));

    const nameEl = h('button.mixch__name', { type: 'button', text: name, title: name });
    nameEl.addEventListener('click', () => selectChannel(id));
    el.appendChild(nameEl);

    // --- Pegel und Fader -----------------------------------------------------
    const body = h('div.mixch__body');
    const fader = h('div.fader');
    const fill = h('div.fader__fill');
    const cap = h('div.fader__cap');
    fader.appendChild(h('div.fader__ticks'));
    fader.appendChild(fill);
    fader.appendChild(cap);

    const meter = h('div.meter');
    const meterBar = h('div.meter__bar');
    meter.appendChild(meterBar);

    body.appendChild(fader);
    body.appendChild(meter);
    el.appendChild(body);

    const valueEl = h('div.mixch__val', { text: '' });
    el.appendChild(valueEl);

    function paintFader(gain) {
      const pos = gainToFader(gain);
      fill.style.height = `${pos * 100}%`;
      cap.style.bottom = `${pos * 100}%`;
      const db = gainToDb(gain);
      valueEl.textContent = gain <= 0.001 ? '−∞ dB' : `${db > 0 ? '+' : ''}${db.toFixed(1)} dB`;
    }

    let dragStartGain = state.gain;
    makeDraggableValue(fader, {
      onStart: () => {
        dragStartGain = store.getChannel(id).gain;
      },
      onMove: (dy) => {
        const height = fader.getBoundingClientRect().height || 120;
        const pos = clamp(gainToFader(dragStartGain) + dy / height, 0, 1);
        const gain = faderToGain(pos);
        paintFader(gain);
        if (isMaster) {
          store.project.master.gain = gain;
          engine.graph?.sync(store.project);
          store.markDirty();
        } else {
          store.getChannel(id).gain = gain;
          engine.graph?.sync(store.project);
          store.markDirty();
        }
      },
      onEnd: () => {
        // Erst am Ende der Geste einen Undo-Schritt anlegen.
        const gain = isMaster ? store.project.master.gain : store.getChannel(id).gain;
        store.history.run('Lautstärke', isMaster ? ['master'] : ['channels'], () => {
          if (isMaster) store.project.master.gain = gain;
          else store.getChannel(id).gain = gain;
        });
        bus.emit(EV.MIXER_CHANGED);
      },
    });

    fader.addEventListener('dblclick', () => {
      const gain = isMaster ? 0.8 : 1;
      store.setChannelValue(id, 'gain', gain);
      paintFader(gain);
    });

    paintFader(state.gain);

    // --- Panorama ------------------------------------------------------------
    let panEl = null;
    let panFill = null;
    if (!isMaster) {
      panEl = h('div.pan');
      panFill = h('div.pan__fill');
      panEl.appendChild(panFill);
      panEl.appendChild(h('div.pan__center'));
      el.appendChild(panEl);

      function paintPan(pan) {
        const pct = Math.abs(pan) * 50;
        panFill.style.width = `${pct}%`;
        panFill.style.left = pan < 0 ? `${50 - pct}%` : '50%';
      }
      paintPan(state.pan);

      let startPan = state.pan;
      makeDraggableValue(panEl, {
        axis: 'x',
        onStart: () => {
          startPan = store.getChannel(id).pan;
        },
        onMove: (dx) => {
          const width = panEl.getBoundingClientRect().width || 80;
          const pan = clamp(startPan + (dx / width) * 2, -1, 1);
          paintPan(pan);
          store.getChannel(id).pan = pan;
          engine.graph?.sync(store.project);
          store.markDirty();
        },
        onEnd: () => {
          const pan = store.getChannel(id).pan;
          store.history.run('Panorama', ['channels'], () => {
            store.getChannel(id).pan = pan;
          });
          bus.emit(EV.MIXER_CHANGED);
        },
      });
      panEl.addEventListener('dblclick', () => {
        store.setChannelValue(id, 'pan', 0);
        paintPan(0);
      });
    }

    // --- Mute / Solo ---------------------------------------------------------
    let muteBtn = null;
    let soloBtn = null;
    if (!isMaster) {
      const row = h('div.mixch__row');
      muteBtn = h('button.mixch__btn.mixch__btn--mute', {
        type: 'button',
        text: 'M',
        'aria-pressed': String(state.mute),
        title: 'Stumm',
      });
      soloBtn = h('button.mixch__btn.mixch__btn--solo', {
        type: 'button',
        text: 'S',
        'aria-pressed': String(state.solo),
        title: 'Solo',
      });
      muteBtn.addEventListener('click', () => {
        store.setChannelValue(id, 'mute', !store.getChannel(id).mute);
      });
      soloBtn.addEventListener('click', () => {
        store.setChannelValue(id, 'solo', !store.getChannel(id).solo);
      });
      row.appendChild(muteBtn);
      row.appendChild(soloBtn);
      el.appendChild(row);
    }

    // --- Effekte -------------------------------------------------------------
    const fxBtn = h('button.mixch__btn', { type: 'button' });
    fxBtn.textContent = `FX ${state.effects.length || ''}`.trim();
    fxBtn.addEventListener('click', () => selectChannel(id, true));
    el.appendChild(h('div.mixch__row', null, [fxBtn]));

    onLongPress(el, ({ x, y }) => channelMenu(id, name, { x, y }));

    return {
      element: el,
      update() {
        const s = store.getChannel(id);
        paintFader(s.gain);
        if (panFill) {
          const pct = Math.abs(s.pan) * 50;
          panFill.style.width = `${pct}%`;
          panFill.style.left = s.pan < 0 ? `${50 - pct}%` : '50%';
        }
        muteBtn?.setAttribute('aria-pressed', String(s.mute));
        soloBtn?.setAttribute('aria-pressed', String(s.solo));
        fxBtn.textContent = `FX ${s.effects.length || ''}`.trim();
        el.classList.toggle('mixch--selected', store.project.ui.selectedChannelId === id);
      },
      meter(peak, clipped) {
        meterBar.style.height = `${meterHeight(peak) * 100}%`;
        meter.classList.toggle('meter--clip', clipped);
      },
    };
  }

  function selectChannel(id, openFx = false) {
    store.setUi({ selectedChannelId: id });
    for (const ch of channels.values()) ch.update();
    bus.emit(EV.SELECTION_CHANGED, { channelId: id, openFx });
  }

  function channelMenu(id, name, position) {
    const isMaster = id === 'master';
    openContextMenu(position, [
      { heading: name },
      {
        label: 'Effekte bearbeiten',
        icon: 'settings',
        onClick: () => selectChannel(id, true),
      },
      !isMaster
        ? {
            label: 'Umbenennen',
            icon: 'edit',
            onClick: async () => {
              const newName = await promptModal({ title: 'Instrument umbenennen', label: 'Name', value: name });
              if (newName) store.renameSample(id, newName);
            },
          }
        : null,
      { separator: true },
      {
        label: 'Auf Standard zurücksetzen',
        icon: 'undo',
        onClick: () => {
          store.edit('Kanal zurücksetzen', ['channels', 'master'], () => {
            const ch = store.getChannel(id);
            ch.gain = isMaster ? 0.8 : 1;
            if (!isMaster) {
              ch.pan = 0;
              ch.mute = false;
              ch.solo = false;
            }
          });
          toast.ok('Kanal zurückgesetzt', name);
        },
      },
    ]);
  }

  function render() {
    clear(strip);
    channels.clear();

    for (const sample of store.project.samples) {
      const ch = buildChannel(sample.id, sample.name, trackColorValue(sample.id));
      channels.set(sample.id, ch);
      strip.appendChild(ch.element);
      ch.update();
    }

    if (!store.project.samples.length) {
      strip.appendChild(
        h('div.empty', { style: { flex: '1' } }, [
          h('div.empty__icon', null, icon('mixer', 22)),
          h('span', { text: 'Sobald du Sounds hinzufügst, erscheint hier für jeden ein Kanal.' }),
        ]),
      );
    }

    const master = buildChannel('master', 'Master', cssVar('--c-accent'), true);
    channels.set('master', master);
    strip.appendChild(master.element);
    master.update();
  }

  function meterLoop() {
    if (engine.graph) {
      for (const [id, ch] of channels) {
        const peak = engine.graph.readMeter(id);
        ch.meter(peak, engine.graph.isClipped(id));
      }
    }
    raf = requestAnimationFrame(meterLoop);
  }

  const offSamples = bus.on(EV.SAMPLES_CHANGED, render);
  const offMixer = bus.on(EV.MIXER_CHANGED, (payload) => {
    if (payload?.live) return; // Reglerbewegung: nur Audio, keine DOM-Arbeit
    for (const ch of channels.values()) ch.update();
  });
  const offLoaded = bus.on(EV.PROJECT_LOADED, render);

  render();

  return {
    element,
    onShow() {
      render();
      cancelAnimationFrame(raf);
      raf = requestAnimationFrame(meterLoop);
    },
    onHide() {
      cancelAnimationFrame(raf);
    },
    destroy() {
      cancelAnimationFrame(raf);
      offSamples();
      offMixer();
      offLoaded();
    },
  };
}

/* =============================================================================
   Effektkette (wird im Inspector angezeigt)
   ========================================================================== */

export function createFxPanel() {
  const element = h('div.insp-section');
  const title = h('div.insp-section__title', { text: 'Effekte' });
  element.appendChild(title);

  const list = h('div.fx-list');
  element.appendChild(list);

  const addBtn = h('button.btn.btn--sm.btn--block', { type: 'button' });
  addBtn.appendChild(icon('plus', 15));
  addBtn.appendChild(h('span', { text: 'Effekt hinzufügen' }));
  addBtn.addEventListener('click', (event) => {
    const rect = event.currentTarget.getBoundingClientRect();
    openContextMenu(
      { x: rect.left, y: rect.top - 10 },
      FX_ORDER.map((type) => ({
        label: FX_DEFS[type].label,
        icon: 'power',
        onClick: () => {
          store.addEffect(currentChannelId(), createEffectState(type));
          render();
        },
      })),
    );
  });
  element.appendChild(addBtn);

  function currentChannelId() {
    const id = store.project.ui.selectedChannelId || 'master';
    if (id !== 'master' && !store.getSample(id)) return 'master';
    return id;
  }

  function channelName() {
    const id = currentChannelId();
    return id === 'master' ? 'Master' : store.getSample(id)?.name || 'Kanal';
  }

  function paramControl(channelId, fx, param) {
    const wrap = h('div.fx-param');
    const top = h('div.fx-param__top');
    top.appendChild(h('span', { text: param.label }));
    const valueEl = h('b', { text: formatValue(fx.params[param.key], param) });
    top.appendChild(valueEl);
    wrap.appendChild(top);

    if (param.bool) {
      const btn = h('button.btn.btn--sm.btn--block', {
        type: 'button',
        text: fx.params[param.key] >= 0.5 ? 'An' : 'Aus',
        'aria-pressed': String(fx.params[param.key] >= 0.5),
      });
      btn.addEventListener('click', () => {
        const next = fx.params[param.key] >= 0.5 ? 0 : 1;
        store.setEffectParam(channelId, fx.id, param.key, next);
        btn.textContent = next ? 'An' : 'Aus';
        btn.setAttribute('aria-pressed', String(!!next));
        valueEl.textContent = formatValue(next, param);
        syncAudio();
      });
      wrap.appendChild(btn);
      return wrap;
    }

    const input = slider(param.min, param.max, param.step, fx.params[param.key], (value) => {
      store.setEffectParam(channelId, fx.id, param.key, value);
      valueEl.textContent = formatValue(value, param);
      syncAudio();
    });
    // Am Ende der Geste einen Undo-Punkt setzen.
    input.addEventListener('change', () => {
      const value = input.valueAsNumber;
      store.history.run('Effektwert', ['channels', 'master'], () => {
        const target = store.getChannel(channelId).effects.find((f) => f.id === fx.id);
        if (target) target.params[param.key] = value;
      });
    });
    wrap.appendChild(input);
    return wrap;
  }

  function formatValue(value, param) {
    if (param.choices) return param.choices[clamp(Math.round(value), 0, param.choices.length - 1)];
    if (param.bool) return value >= 0.5 ? 'an' : 'aus';
    const digits = param.step < 0.01 ? 3 : param.step < 1 ? 2 : 0;
    return `${Number(value).toFixed(digits)}${param.unit ? ` ${param.unit}` : ''}`;
  }

  function syncAudio() {
    engine.graph?.sync(store.project);
  }

  function render() {
    const channelId = currentChannelId();
    title.textContent = `Effekte – ${channelName()}`;
    clear(list);

    const chain = store.getChannel(channelId).effects;
    if (!chain.length) {
      list.appendChild(
        h('div.empty', null, [h('span', { text: 'Noch keine Effekte auf diesem Kanal.' })]),
      );
      return;
    }

    chain.forEach((fx, index) => {
      const def = FX_DEFS[fx.type];
      if (!def) return;
      const item = h(`div.fx-item${fx.enabled ? '' : '.fx-item--off'}`);

      const head = h('div.fx-head');
      head.appendChild(h('div.fx-head__name', { text: def.label }));

      const power = h('button.fx-head__btn', {
        type: 'button',
        'aria-pressed': String(fx.enabled),
        title: fx.enabled ? 'Bypass' : 'Aktivieren',
      });
      power.appendChild(icon('power', 13));
      power.addEventListener('click', (event) => {
        event.stopPropagation();
        store.setEffectEnabled(channelId, fx.id, !fx.enabled);
        syncAudio();
        render();
      });
      head.appendChild(power);

      const up = h('button.fx-head__btn', { type: 'button', text: '↑', title: 'Nach vorne' });
      up.disabled = index === 0;
      up.addEventListener('click', (event) => {
        event.stopPropagation();
        store.moveEffect(channelId, fx.id, -1);
        syncAudio();
        render();
      });
      head.appendChild(up);

      const down = h('button.fx-head__btn', { type: 'button', text: '↓', title: 'Nach hinten' });
      down.disabled = index === chain.length - 1;
      down.addEventListener('click', (event) => {
        event.stopPropagation();
        store.moveEffect(channelId, fx.id, 1);
        syncAudio();
        render();
      });
      head.appendChild(down);

      const del = h('button.fx-head__btn', { type: 'button', title: 'Entfernen' });
      del.appendChild(icon('trash', 13));
      del.addEventListener('click', (event) => {
        event.stopPropagation();
        store.removeEffect(channelId, fx.id);
        syncAudio();
        render();
      });
      head.appendChild(del);

      item.appendChild(head);

      const body = h('div.fx-body');
      for (const param of def.params) body.appendChild(paramControl(channelId, fx, param));
      item.appendChild(body);

      list.appendChild(item);
    });
  }

  const offSelection = bus.on(EV.SELECTION_CHANGED, render);
  const offMixer = bus.on(EV.MIXER_CHANGED, (payload) => {
    if (!payload?.live) render();
  });
  const offLoaded = bus.on(EV.PROJECT_LOADED, render);

  render();

  return {
    element,
    render,
    destroy() {
      offSelection();
      offMixer();
      offLoaded();
    },
  };
}
