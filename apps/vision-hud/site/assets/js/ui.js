/**
 * Alles, was mit dem DOM spricht: Meldungen, Schubladen, Einstellungen, Kartei.
 * Die Datei kennt weder Modelle noch Kamera – sie bekommt fertige Werte und
 * meldet Klicks zurück. Dadurch bleibt app.js die einzige Stelle, an der
 * Erkennung und Oberfläche zusammenkommen.
 */

import { CONFIG, DEFAULT_SETTINGS } from './config.js';
import { BUILTIN_GESTURES } from './hands.js';
import { ACTIONS } from './skills.js';

/** Voreinstellung für die eingebauten Zeichen. */
export const DEFAULT_BINDINGS = {
  Open_Palm: 'hud.toggle',
  Closed_Fist: 'silence',
  Victory: 'scan',
  Thumb_Up: 'readText',
  Thumb_Down: 'objects.toggle',
  Pointing_Up: 'magnify',
  ILoveYou: 'describe',
};

export const $ = (id) => document.getElementById(id);

/**
 * Beschreibung der Einstellungen – die Oberfläche wird daraus erzeugt.
 *
 * `type` bestimmt das Bedienelement:
 *   group   Überschrift
 *   switch  Schalter
 *   slider  Schieberegler
 *   text    Eingabefeld
 *   select  Auswahlliste
 *   action  Knopf, der eine Funktion auslöst
 *
 * `when` blendet einen Eintrag aus, solange die Bedingung nicht erfüllt ist –
 * so taucht das Feld für den API-Schlüssel erst auf, wenn der Dienst an ist.
 */
const SETTINGS_SCHEMA = [
  { type: 'group', name: 'Erkennung' },
  {
    key: 'objects',
    type: 'switch',
    name: 'Objekterkennung',
    desc: '601 Klassen (Open Images), dazu die Zweitstufe mit 1000 weiteren.',
  },
  {
    key: 'faces',
    type: 'switch',
    name: 'Gesichtserkennung',
    desc: 'Findet Gesichter und gleicht sie mit der Kartei ab.',
  },
  {
    key: 'showFaint',
    type: 'switch',
    name: 'Unsicheres zeigen',
    desc: 'Blasse „?“-Rahmen für Dinge, die das Netz nur vermutet.',
  },
  {
    key: 'classifierEnabled',
    type: 'switch',
    name: 'Zweitstufe',
    desc: 'Bestimmt Ausschnitte genauer: Karton → Paket, Gerät → Ventilator.',
  },
  {
    key: 'motionArrows',
    type: 'switch',
    name: 'Bewegungspfeile',
    desc: 'Zeigt, wohin sich ein Ziel bewegt.',
  },
  {
    key: 'hazardWarnings',
    type: 'switch',
    name: 'Gefahrenhinweise',
    desc: 'Warnt vor Fahrzeugen und Hindernissen. Kein Sicherheitssystem.',
  },
  {
    key: 'hazardSpeak',
    type: 'switch',
    name: 'Gefahren ansagen',
    desc: 'Spricht dringende Hinweise laut aus.',
    when: (s) => s.hazardWarnings,
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

  { type: 'group', name: 'ReyRey' },
  {
    key: 'assistantName',
    type: 'text',
    name: 'Name',
    desc: 'So nennt sich der Assistent.',
    placeholder: 'ReyRey',
    maxLength: 24,
  },
  {
    key: 'assistantWakeWord',
    type: 'text',
    name: 'Aktivierungswort',
    desc: 'Darauf hört er. Zwei Silben funktionieren am besten.',
    placeholder: 'ReyRey',
    maxLength: 24,
  },
  {
    key: 'assistantListening',
    type: 'switch',
    name: 'Dauerhaft zuhören',
    desc: 'Reagiert auf das Aktivierungswort. Die Spracherkennung läuft über Apple bzw. Google.',
  },
  {
    key: 'assistantSpeak',
    type: 'switch',
    name: 'Antworten vorlesen',
    desc: 'Sprachausgabe läuft vollständig auf dem Gerät.',
  },
  {
    key: 'assistantOverlay',
    type: 'switch',
    name: 'Antworten einblenden',
    desc: 'Zeigt die Antwort als Sprechblase im Bild.',
  },

  { type: 'group', name: 'Handzeichen' },
  {
    key: 'gesturesEnabled',
    type: 'switch',
    name: 'Handzeichen erkennen',
    desc: 'Erkennt Fingerposen: Faust, offene Hand, Peace, Daumen hoch … und eigene Zeichen.',
  },
  { key: 'gestureBindings', type: 'gestures', when: (s) => s.gesturesEnabled },
  {
    key: '__gestures',
    type: 'action',
    name: 'Eigene Handzeichen',
    desc: 'Aufnehmen, ansehen, löschen. Oder per Sprache: „erfasse neues Handzeichen“.',
    label: 'Verwalten',
  },

  { type: 'group', name: 'Skills' },
  {
    key: '__skills',
    type: 'action',
    name: 'Skills',
    desc: 'Gruppen wie „Bildschirme und Elektrogeräte“ hervorheben. Oder per Sprache: „ab jetzt erkennst du …“.',
    label: 'Verwalten',
  },

  { type: 'group', name: 'Text' },
  {
    key: 'ocrBackground',
    type: 'switch',
    name: 'Text im Hintergrund lesen',
    desc: 'Alle paar Sekunden bei ruhiger Kamera. Tipp auf einen Textblock öffnet die Lupe.',
  },

  { type: 'group', name: 'Anzeige' },
  {
    key: 'hudVisible',
    type: 'switch',
    name: 'Anzeige einblenden',
    desc: 'Rahmen und Beschriftungen.',
  },
  { key: 'showScores', type: 'switch', name: 'Prozentwerte', desc: 'Sicherheit neben dem Namen.' },
  {
    key: 'showTrackIds',
    type: 'switch',
    name: 'Zielnummern',
    desc: 'Fortlaufende Nummer je Ziel.',
  },
  { key: 'showEffects', type: 'switch', name: 'Effekte', desc: 'Raster, Rauschen, Suchstrahl.' },
  {
    key: 'mirrorFront',
    type: 'switch',
    name: 'Selfiekamera spiegeln',
    desc: 'Zeigt die Frontkamera wie einen Spiegel.',
  },
  {
    key: 'keepAwake',
    type: 'switch',
    name: 'Bildschirm anlassen',
    desc: 'Verhindert das Abschalten des Displays.',
  },

  { type: 'group', name: 'Privatsphäre' },
  {
    key: 'privacy',
    type: 'switch',
    name: 'Privatmodus',
    desc: 'Keine Namen im Bild, nichts wird gespeichert.',
  },
  {
    key: 'rememberPlaces',
    type: 'switch',
    name: 'Orte merken',
    desc: 'Speichert beim Merken eines Gegenstands den Standort. Fragt beim ersten Mal.',
  },
  {
    key: '__vault',
    type: 'action',
    name: 'Verschlüsselung',
    desc: 'Schützt die Kartei mit einem Kennwort.',
    label: 'Einrichten',
  },
  {
    key: '__wipe',
    type: 'action',
    name: 'Alles löschen',
    desc: 'Personen, Gegenstände, Einstellungen und Zwischenspeicher.',
    label: 'Löschen',
    danger: true,
  },

  { type: 'group', name: 'KI-Dienst (freiwillig, überträgt Text)' },
  {
    key: 'cloudEnabled',
    type: 'switch',
    name: 'Allgemeine Fragen erlauben',
    desc: 'Nur dafür verlässt Text das Gerät. Kamerabild und Gesichtsmerkmale nie.',
    badge: 'aus',
    badgeTone: 'warn',
  },
  {
    key: 'cloudMode',
    type: 'select',
    name: 'Betriebsart',
    desc: 'Proxy hält den Schlüssel auf dem Server – deutlich sicherer.',
    options: [
      { value: 'proxy', name: 'Proxy auf dem Webspace' },
      { value: 'direct', name: 'Direkt (Schlüssel im Gerät)' },
    ],
    when: (s) => s.cloudEnabled,
  },
  {
    key: 'cloudProxyUrl',
    type: 'text',
    name: 'Proxy-Adresse',
    desc: 'Pfad zu reyrey-proxy.php.',
    placeholder: 'reyrey-proxy.php',
    when: (s) => s.cloudEnabled && s.cloudMode === 'proxy',
  },
  {
    key: 'cloudApiKey',
    type: 'text',
    name: 'API-Schlüssel',
    desc: 'Liegt dann unverschlüsselt im Browser. Nur für Tests.',
    password: true,
    placeholder: 'sk-ant-…',
    when: (s) => s.cloudEnabled && s.cloudMode === 'direct',
  },
  {
    key: 'translateMode',
    type: 'select',
    name: 'Übersetzung über',
    desc: 'Übersetzen braucht immer eine Verbindung nach draussen.',
    options: [
      { value: 'cloud', name: 'den KI-Dienst oben' },
      { value: 'libre', name: 'eigenen LibreTranslate-Server' },
    ],
  },
  {
    key: 'translateUrl',
    type: 'text',
    name: 'LibreTranslate-Adresse',
    desc: 'Vollständige Adresse des /translate-Endpunkts.',
    placeholder: 'https://libretranslate.example/translate',
    when: (s) => s.translateMode === 'libre',
  },
  {
    key: 'translateTarget',
    type: 'select',
    name: 'Zielsprache',
    desc: 'Wohin übersetzt wird.',
    options: [
      { value: 'de', name: 'Deutsch' },
      { value: 'en', name: 'Englisch' },
      { value: 'fr', name: 'Französisch' },
      { value: 'es', name: 'Spanisch' },
      { value: 'it', name: 'Italienisch' },
      { value: 'tr', name: 'Türkisch' },
    ],
  },
];

export class UI {
  constructor() {
    this.settings = loadSettings();
    this.onSettingChange = () => {};
    /** Wird für Knöpfe in den Einstellungen aufgerufen (Kennwort, Löschen). */
    this.onAction = () => {};
    /**
     * Gibt einem Schalter die Gelegenheit, vorher eine Zustimmung einzuholen.
     * Liefert false, bricht das Umschalten ab.
     */
    this.confirmSetting = async () => true;
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
      // `when` blendet Einträge aus, die gerade keinen Sinn ergeben.
      if (item.when && !item.when(this.settings)) continue;

      if (item.type === 'group') {
        const heading = document.createElement('div');
        heading.className = 'settings__group';
        heading.textContent = item.name;
        host.appendChild(heading);
        continue;
      }

      if (item.type === 'gestures') {
        host.appendChild(this.#gestureTable());
        continue;
      }

      const row = document.createElement('div');
      row.className = 'setting';

      const text = document.createElement('div');
      text.className = 'setting__text';
      const name = document.createElement('div');
      name.className = 'setting__name';
      name.textContent = item.name;
      if (item.badge) {
        const badge = document.createElement('span');
        badge.className = 'setting__badge';
        if (item.badgeTone) badge.dataset.tone = item.badgeTone;
        badge.dataset.badgeFor = item.key;
        badge.textContent = item.badge;
        name.appendChild(badge);
      }
      const desc = document.createElement('div');
      desc.className = 'setting__desc';
      desc.textContent = item.desc ?? '';
      text.append(name, desc);

      const control = document.createElement('div');
      control.className = 'setting__control';

      if (item.type === 'switch') {
        control.appendChild(this.#switch(item));
      } else if (item.type === 'slider') {
        control.append(...this.#slider(item));
      } else if (item.type === 'select') {
        control.appendChild(this.#select(item));
      } else if (item.type === 'action') {
        control.appendChild(this.#action(item));
      } else if (item.type === 'text') {
        // Textfelder bekommen die ganze Breite unter der Beschreibung.
        row.classList.add('setting--stack');
        text.appendChild(this.#textField(item));
      }

      row.append(text);
      if (control.childElementCount > 0) row.append(control);
      host.appendChild(row);
    }
  }

  #switch(item) {
    const toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'switch';
    toggle.setAttribute('role', 'switch');
    toggle.setAttribute('aria-label', item.name);
    toggle.dataset.settingKey = item.key;
    toggle.setAttribute('aria-checked', String(Boolean(this.settings[item.key])));
    toggle.addEventListener('click', async () => {
      const value = !this.settings[item.key];
      // Manche Schalter brauchen erst eine Zustimmung – die darf ablehnen.
      const allowed = await this.confirmSetting(item.key, value);
      if (!allowed) return;
      toggle.setAttribute('aria-checked', String(value));
      this.#change(item.key, value);
      this.renderSettings();
    });
    return toggle;
  }

  #slider(item) {
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
    return [slider, value];
  }

  #select(item) {
    const select = document.createElement('select');
    select.setAttribute('aria-label', item.name);
    for (const option of item.options) {
      const el = document.createElement('option');
      el.value = option.value;
      el.textContent = option.name;
      if (this.settings[item.key] === option.value) el.selected = true;
      select.appendChild(el);
    }
    select.addEventListener('change', () => {
      this.#change(item.key, select.value);
      this.renderSettings();
    });
    return select;
  }

  #action(item) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = `btn btn--ghost${item.danger ? ' btn--danger' : ''}`;
    button.textContent = item.label;
    button.addEventListener('click', () => this.onAction(item.key));
    return button;
  }

  #textField(item) {
    const input = document.createElement('input');
    input.className = 'setting__field';
    input.type = item.password ? 'password' : 'text';
    input.value = this.settings[item.key] ?? '';
    input.placeholder = item.placeholder ?? '';
    input.maxLength = item.maxLength ?? 400;
    input.autocomplete = 'off';
    input.setAttribute('aria-label', item.name);
    // Erst beim Verlassen speichern – sonst greift jede Taste in die Logik ein.
    input.addEventListener('change', () => this.#change(item.key, input.value.trim()));
    input.addEventListener('blur', () => this.#change(item.key, input.value.trim()));
    return input;
  }

  /** Zuordnung Geste → Aktion. */
  #gestureTable() {
    const box = document.createElement('div');
    const bindings = { ...DEFAULT_BINDINGS, ...(this.settings.gestureBindings ?? {}) };

    for (const [id, label] of Object.entries(BUILTIN_GESTURES)) {
      const row = document.createElement('div');
      row.className = 'gesture';

      const name = document.createElement('span');
      name.className = 'gesture__name';
      name.textContent = label;

      const select = document.createElement('select');
      select.setAttribute('aria-label', label);
      for (const action of ACTIONS) {
        const option = document.createElement('option');
        option.value = action.id;
        option.textContent = action.name;
        if ((bindings[id] ?? 'none') === action.id) option.selected = true;
        select.appendChild(option);
      }
      select.addEventListener('change', () => {
        const next = { ...bindings, [id]: select.value };
        this.#change('gestureBindings', next);
      });

      row.append(name, select);
      box.appendChild(row);
    }
    return box;
  }

  #change(key, value) {
    this.settings[key] = value;
    saveSettings(this.settings);
    this.onSettingChange(key, value);
  }

  /** Von aussen gesetzte Änderung (z. B. über die Bedienleiste oder ReyRey). */
  applySetting(key, value) {
    this.settings[key] = value;
    saveSettings(this.settings);
    const toggle = document.querySelector(`[data-setting-key="${key}"]`);
    toggle?.setAttribute('aria-checked', String(Boolean(value)));
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

  /* ---------------- ReyRey ---------------- */

  /** Zustand der Kugel: aus · hört · wach · denkt · spricht */
  setReyState(state) {
    $('rey').dataset.state = state;
  }

  setReyName(name) {
    $('reyName').textContent = (name || 'ReyRey').toUpperCase();
  }

  /** Zeigt, was gerade verstanden wurde – auch bevor eine Antwort da ist. */
  setHeard(text) {
    $('reyHeard').textContent = text ? `„${text}“` : '';
    if (text) $('reyBubble').hidden = false;
  }

  /**
   * Blendet eine Antwort ein.
   * @param {string} text
   * @param {number} [ms] Anzeigedauer; 0 lässt sie stehen
   */
  say(text, ms = CONFIG.assistant.answerMs) {
    const bubble = $('reyBubble');
    $('reyText').textContent = text;
    bubble.hidden = false;

    clearTimeout(this.sayTimer);
    if (ms > 0) {
      // Längere Antworten dürfen länger stehen bleiben.
      const shown = Math.max(ms, Math.min(24000, text.length * 65));
      this.sayTimer = setTimeout(() => this.hideSay(), shown);
    }
  }

  hideSay() {
    $('reyBubble').hidden = true;
    $('reyHeard').textContent = '';
  }

  /** Kleiner Hinweis an der Kugel („Tippen für Ton“, „Tippen zum Zuhören“). */
  setHint(text) {
    const hint = $('reyHint');
    hint.textContent = text ?? '';
    hint.hidden = !text;
  }

  /** „Tippen für Ton“ – solange iOS die Sprachausgabe noch sperrt. */
  setNeedsUnlock(show) {
    this.setHint(show ? 'Tippen für Ton' : null);
  }

  /* ---------------- Gefahrenband ---------------- */

  showAlarm(text) {
    $('alarmText').textContent = text;
    $('alarm').hidden = false;
  }

  hideAlarm() {
    $('alarm').hidden = true;
  }

  /* ---------------- Gespräch (getippt) ---------------- */

  pushChat(role, text) {
    const log = $('chatLog');
    const li = document.createElement('li');
    li.dataset.role = role;
    li.textContent = text;
    log.appendChild(li);
    log.scrollTop = log.scrollHeight;
    while (log.children.length > 40) log.firstElementChild.remove();
  }

  /* ---------------- Gegenstände ---------------- */

  renderThings(items, onDelete) {
    const list = $('thingsList');
    const empty = $('thingsEmpty');
    list.innerHTML = '';
    empty.hidden = items.length > 0;

    for (const item of items) {
      const li = document.createElement('li');

      const img = document.createElement('img');
      img.className = 'roster__face';
      img.alt = '';
      if (item.thumb) img.src = item.thumb;

      const info = document.createElement('div');
      info.className = 'roster__info';
      const name = document.createElement('div');
      name.className = 'roster__name';
      name.textContent = item.name;
      const meta = document.createElement('div');
      meta.className = 'roster__meta';
      const bits = [];
      if (item.note) bits.push(item.note);
      bits.push(`zuletzt ${formatDate(item.lastSeenAt)}`);
      if (item.lastPlace) bits.push('mit Ort');
      meta.textContent = bits.join(' · ');
      info.append(name, meta);

      const del = document.createElement('button');
      del.type = 'button';
      del.className = 'roster__del';
      del.textContent = 'Löschen';
      del.addEventListener('click', () => onDelete(item));

      li.append(img, info, del);
      list.appendChild(li);
    }
  }

  /* ---------------- Uhr ---------------- */

  /** Uhr und Datum im Sekundentakt. */
  startClock() {
    const days = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
    const tick = () => {
      const now = new Date();
      const two = (n) => String(n).padStart(2, '0');
      $('clockDate').textContent =
        `${days[now.getDay()]} ${two(now.getDate())}.${two(now.getMonth() + 1)}.`;
      $('clockTime').textContent =
        `${two(now.getHours())}:${two(now.getMinutes())}:${two(now.getSeconds())}`;
    };
    tick();
    clearInterval(this.clockTimer);
    this.clockTimer = setInterval(tick, 1000);
  }

  /* ---------------- Lupe ---------------- */

  /**
   * Zeigt einen vergrösserten Ausschnitt mit erkanntem Text.
   * @param {{canvas: HTMLCanvasElement|null, text: string, confidence: number, factor: number}} result
   */
  showMagnifier(result) {
    const view = $('magnifierView');
    const ctx = view.getContext('2d');
    ctx.fillStyle = '#04121a';
    ctx.fillRect(0, 0, view.width, view.height);
    if (result.canvas) {
      // Einpassen, Seitenverhältnis erhalten.
      const scale = Math.min(view.width / result.canvas.width, view.height / result.canvas.height);
      const w = result.canvas.width * scale;
      const h = result.canvas.height * scale;
      ctx.imageSmoothingQuality = 'high';
      ctx.drawImage(result.canvas, (view.width - w) / 2, (view.height - h) / 2, w, h);
    }
    $('magnifierZoom').textContent = `${(result.factor ?? 1).toFixed(1).replace('.0', '')}×`;
    $('magnifierText').textContent = result.text ?? '';
    $('magnifierMeta').textContent = result.text
      ? `Sicherheit ${Math.round((result.confidence ?? 0) * 100)} % · ${result.text.length} Zeichen`
      : 'Kein Text erkannt – Kamera ruhig halten und näher heran.';
    this.openSheet('sheetMagnifier');
  }

  setMagnifierText(text, meta = '') {
    $('magnifierText').textContent = text;
    if (meta) $('magnifierMeta').textContent = meta;
  }

  /* ---------------- Handzeichen und Skills ---------------- */

  /**
   * Liste eigener Handzeichen.
   * @param {Array<{id, name, action, samples}>} items
   */
  renderGestures(items, { onDelete, onRecord, actionName }) {
    const list = $('gesturesList');
    const empty = $('gesturesEmpty');
    list.innerHTML = '';
    empty.hidden = items.length > 0;

    for (const item of items) {
      const li = document.createElement('li');
      const swatch = document.createElement('span');
      swatch.className = 'roster__swatch';
      swatch.style.color = '#2bf5dd';
      swatch.style.background = '#2bf5dd';

      const info = document.createElement('div');
      info.className = 'roster__info';
      const name = document.createElement('div');
      name.className = 'roster__name';
      name.textContent = item.name;
      const meta = document.createElement('div');
      meta.className = 'roster__meta';
      meta.textContent = `${actionName(item.action)} · ${item.samples ?? '?'} Aufnahmen`;
      info.append(name, meta);

      const del = document.createElement('button');
      del.type = 'button';
      del.className = 'roster__del';
      del.textContent = 'Löschen';
      del.addEventListener('click', () => onDelete(item));

      li.append(swatch, info, del);
      list.appendChild(li);
    }
    $('btnGestureRecord').onclick = () => onRecord();
  }

  /** Liste der Gruppen-Skills. */
  renderSkills(items, { onDelete, onToggle, onCreate }) {
    const list = $('skillsList');
    const empty = $('skillsEmpty');
    list.innerHTML = '';
    empty.hidden = items.length > 0;

    for (const item of items) {
      const li = document.createElement('li');
      const swatch = document.createElement('span');
      swatch.className = 'roster__swatch';
      swatch.style.color = item.color;
      swatch.style.background = item.color;

      const info = document.createElement('div');
      info.className = 'roster__info';
      const name = document.createElement('div');
      name.className = 'roster__name';
      name.textContent = item.name;
      const meta = document.createElement('div');
      meta.className = 'roster__meta';
      meta.textContent = `${item.classes.length} Klassen · ${item.enabled !== false ? 'an' : 'aus'}`;
      info.append(name, meta);

      const toggle = document.createElement('button');
      toggle.type = 'button';
      toggle.className = 'switch';
      toggle.setAttribute('role', 'switch');
      toggle.setAttribute('aria-checked', String(item.enabled !== false));
      toggle.setAttribute('aria-label', `${item.name} ein/aus`);
      toggle.addEventListener('click', () => onToggle(item, item.enabled === false));

      const del = document.createElement('button');
      del.type = 'button';
      del.className = 'roster__del';
      del.textContent = 'Löschen';
      del.addEventListener('click', () => onDelete(item));

      li.append(swatch, info, toggle, del);
      list.appendChild(li);
    }
    $('btnSkillCreate').onclick = () => onCreate();
  }

  /** Aufnahmefortschritt eines Handzeichens in der Sprechblase. */
  setGestureProgress(fraction) {
    const bubble = $('reyBubble');
    if (fraction <= 0 || fraction >= 1) return;
    bubble.hidden = false;
    $('reyText').textContent = `Aufnahme … ${Math.round(fraction * 100)} %`;
  }

  /* ---------------- Rückfragen ---------------- */

  /**
   * Holt eine ausdrückliche Zustimmung ein.
   * @param {{title: string, body: string, confirm?: string}} options
   * @returns {Promise<boolean>}
   */
  askConsent({ title, body, confirm = 'Ja, einverstanden' }) {
    return new Promise((resolve) => {
      $('consentTitle').textContent = title;
      $('consentBody').innerHTML = body;
      $('btnConsentYes').textContent = confirm;

      const finish = (answer) => {
        $('btnConsentYes').removeEventListener('click', yes);
        this.onSheetClose = previousClose;
        this.closeSheet('sheetConsent');
        resolve(answer);
      };
      const yes = () => finish(true);

      const previousClose = this.onSheetClose;
      this.onSheetClose = (id) => {
        // Schliessen ohne Zusage gilt als Ablehnung.
        if (id === 'sheetConsent') finish(false);
        else previousClose?.(id);
      };

      $('btnConsentYes').addEventListener('click', yes);
      this.openSheet('sheetConsent');
    });
  }

  /**
   * Fragt nach dem Kennwort.
   * @param {'einrichten'|'öffnen'} mode
   * @returns {Promise<string|null>}
   */
  askPassphrase(mode) {
    return new Promise((resolve) => {
      const form = $('vaultForm');
      const input = $('vaultPass');
      const error = $('vaultError');

      $('vaultTitle').textContent =
        mode === 'einrichten' ? 'Kennwort festlegen' : 'Kennwort eingeben';
      $('vaultHint').textContent =
        mode === 'einrichten'
          ? 'Ab jetzt werden Namen, Alter, Notizen und Gesichtsmerkmale verschlüsselt gespeichert. Vergisst du das Kennwort, ist die Kartei verloren – auch diese Seite kann sie dann nicht mehr öffnen.'
          : 'Die Kartei ist verschlüsselt. Ohne Kennwort bleiben die Einträge unlesbar.';
      $('btnVaultGo').textContent = mode === 'einrichten' ? 'Verschlüsseln' : 'Öffnen';
      input.value = '';
      input.autocomplete = mode === 'einrichten' ? 'new-password' : 'current-password';
      error.hidden = true;

      const finish = (value) => {
        form.removeEventListener('submit', submit);
        this.onSheetClose = previousClose;
        this.closeSheet('sheetVault');
        resolve(value);
      };
      const submit = (event) => {
        event.preventDefault();
        if (input.value.length < 8) {
          error.textContent = 'Mindestens 8 Zeichen.';
          error.hidden = false;
          return;
        }
        finish(input.value);
      };

      const previousClose = this.onSheetClose;
      this.onSheetClose = (id) => {
        if (id === 'sheetVault') finish(null);
        else previousClose?.(id);
      };

      form.addEventListener('submit', submit);
      this.openSheet('sheetVault');
      setTimeout(() => input.focus(), 120);
    });
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
