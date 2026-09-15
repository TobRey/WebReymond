/**
 * Alles, was mit dem DOM spricht: Meldungen, Schubladen, Einstellungen, Kartei.
 * Die Datei kennt weder Modelle noch Kamera – sie bekommt fertige Werte und
 * meldet Klicks zurück. Dadurch bleibt app.js die einzige Stelle, an der
 * Erkennung und Oberfläche zusammenkommen.
 */

import { CONFIG, DEFAULT_SETTINGS } from './config.js';

export const $ = (id) => document.getElementById(id);

/** Beschreibung der Einstellungen – die Oberfläche wird daraus erzeugt. */
const SETTINGS_SCHEMA = [
  {
    key: 'objects',
    type: 'switch',
    name: 'Objekterkennung',
    desc: '80 Klassen, von Auto bis Zahnbürste.',
  },
  {
    key: 'faces',
    type: 'switch',
    name: 'Gesichtserkennung',
    desc: 'Findet Gesichter und gleicht sie mit der Kartei ab.',
  },
  {
    key: 'mirrorFront',
    type: 'switch',
    name: 'Selfiekamera spiegeln',
    desc: 'Zeigt die Frontkamera wie einen Spiegel.',
  },
  {
    key: 'showScores',
    type: 'switch',
    name: 'Prozentwerte',
    desc: 'Sicherheit der Erkennung neben dem Namen.',
  },
  {
    key: 'showTrackIds',
    type: 'switch',
    name: 'Zielnummern',
    desc: 'Fortlaufende Nummer je verfolgtem Ziel.',
  },
  {
    key: 'showEffects',
    type: 'switch',
    name: 'Effekte',
    desc: 'Raster, Bildrauschen und Suchstrahl.',
  },
  {
    key: 'keepAwake',
    type: 'switch',
    name: 'Bildschirm anlassen',
    desc: 'Verhindert, dass sich das Display abschaltet.',
  },
  {
    key: 'minScore',
    type: 'slider',
    name: 'Mindestsicherheit',
    desc: 'Objekte unter diesem Wert werden verworfen.',
    min: 0.2,
    max: 0.9,
    step: 0.05,
    format: (v) => `${Math.round(v * 100)} %`,
  },
  {
    key: 'matchThreshold',
    type: 'slider',
    name: 'Wiedererkennung',
    desc: 'Kleiner = strenger bei Verwechslungen.',
    min: 0.35,
    max: 0.7,
    step: 0.01,
    format: (v) => v.toFixed(2),
  },
];

export class UI {
  constructor() {
    this.settings = loadSettings();
    this.onSettingChange = () => {};
    this.sheetOpen = null;
    this.#wireSheets();
  }

  /* ---------------- Schubladen ---------------- */

  #wireSheets() {
    document.querySelectorAll('[data-close]').forEach((button) => {
      button.addEventListener('click', () => this.closeSheet(button.dataset.close));
    });

    document.querySelectorAll('.sheet').forEach((sheet) => {
      // Klick auf den abgedunkelten Hintergrund schliesst die Schublade.
      sheet.addEventListener('click', (event) => {
        if (event.target === sheet) this.closeSheet(sheet.id);
      });
    });

    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && this.sheetOpen) this.closeSheet(this.sheetOpen);
    });
  }

  openSheet(id) {
    if (this.sheetOpen && this.sheetOpen !== id) this.closeSheet(this.sheetOpen);
    const sheet = $(id);
    if (!sheet) return;
    sheet.hidden = false;
    this.sheetOpen = id;
  }

  closeSheet(id) {
    const sheet = $(id);
    if (!sheet) return;
    sheet.hidden = true;
    if (this.sheetOpen === id) this.sheetOpen = null;
    this.onSheetClose?.(id);
  }

  /* ---------------- Meldungen ---------------- */

  toast(message, tone = 'info') {
    const host = $('toasts');
    const el = document.createElement('div');
    el.className = 'toast';
    el.dataset.tone = tone;
    el.textContent = message;
    host.appendChild(el);

    setTimeout(() => {
      el.classList.add('is-out');
      setTimeout(() => el.remove(), 320);
    }, CONFIG.ui.toastMs);

    // Mehr als vier Meldungen gleichzeitig verdecken nur das Bild.
    while (host.children.length > 4) host.firstElementChild.remove();
  }

  /* ---------------- Startbildschirm ---------------- */

  bootStep(step, state, detail = '') {
    const log = $('bootLog');
    let row = log.querySelector(`[data-step="${step}"]`);

    if (!row) {
      row = document.createElement('li');
      row.dataset.step = step;
      row.innerHTML = `<span></span><b></b>`;
      log.appendChild(row);
    }

    const labels = { wait: '—', run: '…', ok: detail || 'bereit', fail: detail || 'Fehler' };
    row.dataset.state = state;
    row.querySelector('span').textContent = step;
    row.querySelector('b').textContent = labels[state] ?? '—';
  }

  bootProgress(fraction) {
    $('bootBar').style.width = `${Math.round(fraction * 100)}%`;
  }

  bootHint(text) {
    $('bootHint').textContent = text;
  }

  hideBoot() {
    const boot = $('boot');
    boot.classList.add('is-leaving');
    setTimeout(() => {
      boot.hidden = true;
    }, 560);
  }

  showFault(error) {
    $('boot').hidden = true;
    $('faultTitle').textContent = error.title ?? 'Fehler';
    $('faultText').textContent = error.message ?? '';
    const list = $('faultList');
    list.innerHTML = '';
    for (const hint of error.hints ?? []) {
      const li = document.createElement('li');
      li.textContent = hint;
      list.appendChild(li);
    }
    $('fault').hidden = false;
  }

  /* ---------------- Kopfzeile und Seitenanzeigen ---------------- */

  setMode(text, live = false) {
    $('modeTag').textContent = text;
    $('liveDot').classList.toggle('is-live', live);
  }

  setStats({ fps, objects, faces, known }) {
    $('statFps').textContent = fps;
    $('statObjects').textContent = objects;
    $('statFaces').textContent = faces;
    $('statKnown').textContent = known;
  }

  setResolution(text) {
    $('railRes').textContent = text;
  }

  /** Pegelanzeige links – Zahl der Ziele als Balkenkette. */
  setLevel(count) {
    const host = $('railBars');
    const total = 8;
    if (host.children.length !== total) {
      host.innerHTML = '';
      for (let i = 0; i < total; i += 1) host.appendChild(document.createElement('i'));
    }
    [...host.children].forEach((bar, index) => {
      bar.classList.toggle('is-hot', index < Math.min(total, count));
    });
  }

  /** Liste der aktuell sichtbaren Personen rechts im Bild. */
  setContacts(entries) {
    const host = $('contacts');
    const next = entries.map((entry) => `${entry.kind}|${entry.text}`).join('\n');
    if (host.dataset.signature === next) return;
    host.dataset.signature = next;

    host.innerHTML = '';
    for (const entry of entries.slice(0, 6)) {
      const li = document.createElement('li');
      li.dataset.kind = entry.kind;
      li.textContent = entry.text;
      host.appendChild(li);
    }
  }

  /* ---------------- Einstellungen ---------------- */

  renderSettings() {
    const host = $('settingsBody');
    host.innerHTML = '';

    for (const item of SETTINGS_SCHEMA) {
      const row = document.createElement('div');
      row.className = 'setting';

      const text = document.createElement('div');
      text.className = 'setting__text';
      text.innerHTML = `<div class="setting__name"></div><div class="setting__desc"></div>`;
      text.querySelector('.setting__name').textContent = item.name;
      text.querySelector('.setting__desc').textContent = item.desc;

      const control = document.createElement('div');
      control.className = 'setting__control';

      if (item.type === 'switch') {
        const toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'switch';
        toggle.setAttribute('role', 'switch');
        toggle.setAttribute('aria-label', item.name);
        toggle.setAttribute('aria-checked', String(Boolean(this.settings[item.key])));
        toggle.addEventListener('click', () => {
          const value = !this.settings[item.key];
          toggle.setAttribute('aria-checked', String(value));
          this.#change(item.key, value);
        });
        control.appendChild(toggle);
      } else {
        const value = document.createElement('span');
        value.className = 'setting__value';
        value.textContent = item.format(this.settings[item.key]);

        const slider = document.createElement('input');
        slider.type = 'range';
        slider.className = 'slider';
        slider.min = String(item.min);
        slider.max = String(item.max);
        slider.step = String(item.step);
        slider.value = String(this.settings[item.key]);
        slider.setAttribute('aria-label', item.name);
        slider.addEventListener('input', () => {
          const parsed = Number(slider.value);
          value.textContent = item.format(parsed);
          this.#change(item.key, parsed);
        });

        control.append(slider, value);
      }

      row.append(text, control);
      host.appendChild(row);
    }
  }

  #change(key, value) {
    this.settings[key] = value;
    saveSettings(this.settings);
    this.onSettingChange(key, value);
  }

  /** Von aussen gesetzte Änderung (z. B. über die Bedienleiste). */
  applySetting(key, value) {
    this.settings[key] = value;
    saveSettings(this.settings);
    const host = $('settingsBody');
    const index = SETTINGS_SCHEMA.findIndex((item) => item.key === key);
    const row = host?.children[index];
    row?.querySelector('.switch')?.setAttribute('aria-checked', String(Boolean(value)));
  }

  /* ---------------- Kartei ---------------- */

  renderRoster(people, onDelete) {
    const list = $('rosterList');
    const empty = $('rosterEmpty');
    list.innerHTML = '';
    empty.hidden = people.length > 0;

    for (const person of people) {
      const li = document.createElement('li');

      const img = document.createElement('img');
      img.className = 'roster__face';
      img.alt = '';
      if (person.thumb) img.src = person.thumb;

      const info = document.createElement('div');
      info.className = 'roster__info';
      const name = document.createElement('div');
      name.className = 'roster__name';
      name.textContent = person.name;
      const meta = document.createElement('div');
      meta.className = 'roster__meta';
      meta.textContent = `${person.age} Jahre · ${person.descriptors.length} Aufnahmen · ${formatDate(person.registeredAt)}`;
      info.append(name, meta);

      const del = document.createElement('button');
      del.type = 'button';
      del.className = 'roster__del';
      del.textContent = 'Löschen';
      del.addEventListener('click', () => onDelete(person));

      li.append(img, info, del);
      list.appendChild(li);
    }
  }

  setDiagnostics(lines) {
    $('diagnostics').innerHTML = '';
    for (const line of lines) {
      const div = document.createElement('div');
      div.textContent = line;
      $('diagnostics').appendChild(div);
    }
  }
}

function formatDate(iso) {
  try {
    return new Date(iso).toLocaleDateString('de-CH', {
      day: '2-digit',
      month: '2-digit',
      year: 'numeric',
    });
  } catch {
    return '—';
  }
}

function loadSettings() {
  try {
    const raw = localStorage.getItem(CONFIG.ui.storageKey);
    if (!raw) return { ...DEFAULT_SETTINGS };
    return { ...DEFAULT_SETTINGS, ...JSON.parse(raw) };
  } catch {
    return { ...DEFAULT_SETTINGS };
  }
}

function saveSettings(settings) {
  try {
    localStorage.setItem(CONFIG.ui.storageKey, JSON.stringify(settings));
  } catch {
    /* Privates Fenster: Einstellungen gelten dann nur für diese Sitzung. */
  }
}
