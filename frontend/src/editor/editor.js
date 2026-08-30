/**
 * Der Bearbeitungsbalken in der Vorschau.
 *
 * Er wird nur eingesetzt, wenn man angemeldet ist. Für alle anderen ist
 * die Vorschau eine gewöhnliche Website.
 *
 * Was er kann:
 *   - jeden Abschnitt anklicken und auswählen
 *   - per Textbefehl ändern lassen ("Knopf grösser", "Text kürzen")
 *   - zwischen 20 Vorlagen wechseln – sofort und ohne Kosten
 *   - Bild austauschen
 *   - Abschnitt aus-/einblenden und verschieben
 *   - Desktop, Tablet und Handy getrennt betrachten
 *   - jede Änderung im Protokoll sehen und zurücknehmen
 */

import './editor.css';

const dataElement = document.getElementById('wa-editor-data');
if (dataElement) {
  boot(JSON.parse(dataElement.textContent || '{}'));
}

function boot(config) {
  const root = document.documentElement;
  root.classList.add('wae-active', 'wae-picking');

  const state = { section: null, mode: 'desktop' };

  const bar = buildBar(config, state);
  const panel = buildPanel(config, state);
  document.body.append(bar, panel);

  markSections();
  wireSectionClicks(state, panel, config);
}

/* ------------------------------------------------------------------ */
/* Leiste                                                              */
/* ------------------------------------------------------------------ */

function buildBar(config, state) {
  const bar = el('div', 'wae wae-bar');

  bar.append(
    el('span', 'wae-bar__brand', [el('span', 'wae-bar__dot'), text('Vorschau')]),
    el('span', 'wae-bar__name', [text(config.projectName || '')]),
    el('span', 'wae-bar__spacer'),
    el('span', 'wae-bar__hint', [text('Auf einen Abschnitt klicken, um ihn zu ändern')])
  );

  // Desktop / Tablet / Handy
  const modes = el('div', 'wae-modes');
  for (const [key, label] of [['desktop', 'Desktop'], ['tablet', 'Tablet'], ['mobile', 'Handy']]) {
    const button = el('button', 'wae-mode' + (key === 'desktop' ? ' is-active' : ''), [text(label)]);
    button.type = 'button';
    button.addEventListener('click', () => {
      state.mode = key;
      document.documentElement.classList.remove('wae-mode-tablet', 'wae-mode-mobile');
      if (key !== 'desktop') document.documentElement.classList.add('wae-mode-' + key);
      modes.querySelectorAll('.wae-mode').forEach((m) => m.classList.toggle('is-active', m === button));
    });
    modes.append(button);
  }
  bar.append(modes);

  const toProject = el('a', 'wae-btn', [text('Zum Projekt')]);
  toProject.href = config.base + '/projekt/' + config.projectId;
  bar.append(toProject);

  if (config.expiresAt) {
    const left = hoursLeft(config.expiresAt);
    if (left !== null) {
      bar.append(el('span', 'wae-bar__hint', [text(`Vorschau läuft in ${left} h ab`)]));
    }
  }

  return bar;
}

function hoursLeft(expiresAt) {
  const end = new Date(expiresAt.replace(' ', 'T')).getTime();
  if (Number.isNaN(end)) return null;
  return Math.max(0, Math.round((end - Date.now()) / 3600000));
}

/* ------------------------------------------------------------------ */
/* Abschnitte anklickbar machen                                        */
/* ------------------------------------------------------------------ */

function markSections() {
  document.querySelectorAll('[data-section-id]').forEach((section) => {
    if (section.dataset.sectionId === '0') return;

    // position:relative, damit die Markierung sitzt – aber nur, wenn der
    // Abschnitt nicht ohnehin schon positioniert ist.
    if (getComputedStyle(section).position === 'static') {
      section.style.position = 'relative';
    }

    const tag = el('span', 'wae wae-tag', [text(section.dataset.section || '')]);
    section.prepend(tag);
  });
}

function wireSectionClicks(state, panel, config) {
  document.addEventListener('click', (event) => {
    // Klicks im Balken und in der Tafel gehen nicht an die Seite.
    if (event.target.closest('.wae')) return;

    const section = event.target.closest('[data-section-id]');
    if (!section || section.dataset.sectionId === '0') return;

    event.preventDefault();
    event.stopPropagation();

    document.querySelectorAll('.wae-selected').forEach((s) => s.classList.remove('wae-selected'));
    section.classList.add('wae-selected');

    state.section = section;
    panel.open(Number(section.dataset.sectionId));
  }, true);

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') panel.close();
  });
}

/* ------------------------------------------------------------------ */
/* Werkzeugtafel                                                       */
/* ------------------------------------------------------------------ */

function buildPanel(config, state) {
  const panel = el('aside', 'wae wae-panel');

  const title = el('h2', 'wae-panel__title', [text('Abschnitt')]);
  const subtitle = el('p', 'wae-panel__sub');
  const close = el('button', 'wae-btn wae-btn--sm wae-panel__close', [text('Schliessen')]);
  close.type = 'button';

  const head = el('div', 'wae-panel__head', [
    el('div', '', [title, subtitle]),
    close,
  ]);

  const body = el('div', 'wae-panel__body');
  panel.append(head, body);

  close.addEventListener('click', () => panel.close());

  panel.close = () => {
    panel.classList.remove('is-open');
    document.querySelectorAll('.wae-selected').forEach((s) => s.classList.remove('wae-selected'));
  };

  panel.open = async (sectionId) => {
    panel.classList.add('is-open');
    body.replaceChildren(el('p', 'wae-note', [text('Wird geladen …')]));

    try {
      const data = await api(config, `/api/sections/${sectionId}`, { method: 'GET' });
      title.textContent = data.section.type_label;
      subtitle.textContent = data.section.template_label;
      renderPanel(body, config, data, sectionId, panel);
    } catch (error) {
      body.replaceChildren(note('error', 'Fehler', error.message));
    }
  };

  return panel;
}

function renderPanel(body, config, data, sectionId, panel) {
  const parts = [];
  const section = data.section;

  // --- Textbefehl -------------------------------------------------
  if (data.ai_available) {
    const textarea = el('textarea', 'wae-textarea');
    textarea.placeholder =
      'Was soll anders sein?\n\nBeispiele:\n– Den Knopf grösser und auffälliger machen\n'
      + '– Den Einleitungstext auf zwei Sätze kürzen\n– Eine vierte Leistung ergänzen';

    const submit = el('button', 'wae-btn wae-btn--primary', [text('Ändern lassen')]);
    submit.type = 'button';

    const status = el('div');

    submit.addEventListener('click', async () => {
      const instruction = textarea.value.trim();
      if (!instruction) {
        status.replaceChildren(note('error', 'Fehlt noch', 'Bitte beschreiben, was geändert werden soll.'));
        return;
      }

      submit.disabled = true;
      submit.replaceChildren(el('span', 'wae-spin'), text(' Wird geändert …'));
      status.replaceChildren(note('', 'Einen Moment', 'Die Änderung wird vorbereitet.'));

      try {
        const result = await api(config, `/api/sections/${sectionId}/anweisung`, {
          data: { instruction },
        });
        status.replaceChildren(note('ok', result.label, result.summary));
        textarea.value = '';
        setTimeout(() => window.location.reload(), 900);
      } catch (error) {
        status.replaceChildren(note('error', 'Das hat nicht geklappt', error.message));
        submit.disabled = false;
        submit.replaceChildren(text('Ändern lassen'));
      }
    });

    parts.push(group('Ändern per Anweisung', [textarea, el('div', 'wae-row', [submit]), status]));
  } else {
    parts.push(group('Ändern per Anweisung', [
      note('', 'Noch nicht verfügbar',
        'Dafür wird ein Anthropic-Schlüssel gebraucht. Er gehört in app/config.php.'),
    ]));
  }

  // --- Vorlage wechseln -------------------------------------------
  const variantsBox = el('div', 'wae-variants');
  const loadVariants = async () => {
    try {
      const data = await api(config, `/api/sections/${sectionId}/templates`, { method: 'GET' });
      variantsBox.replaceChildren(...data.variants.map((variant) => {
        const button = el('button', 'wae-variant' + (variant.current ? ' is-current' : ''), [text(variant.label)]);
        button.type = 'button';
        button.addEventListener('click', async () => {
          try {
            await api(config, `/api/sections/${sectionId}/template`, { data: { template: variant.key } });
            window.location.reload();
          } catch (error) {
            variantsBox.append(note('error', 'Fehler', error.message));
          }
        });
        return button;
      }));
    } catch (error) {
      variantsBox.replaceChildren(note('error', 'Fehler', error.message));
    }
  };
  loadVariants();

  parts.push(group('Vorlage wechseln (20 Varianten)', [variantsBox]));

  // --- Bild austauschen -------------------------------------------
  const fileInput = el('input', 'wae-input');
  fileInput.type = 'file';
  fileInput.accept = 'image/png,image/jpeg,image/webp,image/svg+xml,image/avif';

  const fieldSelect = el('select', 'wae-select');
  const fields = imageFields(section.content);
  fields.forEach((field) => {
    const option = el('option', '', [text(field.label)]);
    option.value = field.path;
    fieldSelect.append(option);
  });

  const uploadButton = el('button', 'wae-btn', [text('Bild hochladen')]);
  uploadButton.type = 'button';
  const uploadStatus = el('div');

  uploadButton.addEventListener('click', async () => {
    const file = fileInput.files?.[0];
    if (!file) {
      uploadStatus.replaceChildren(note('error', 'Fehlt noch', 'Bitte zuerst eine Datei wählen.'));
      return;
    }

    const form = new FormData();
    form.append('image', file);
    form.append('field', fieldSelect.value || 'image');

    uploadButton.disabled = true;
    try {
      await api(config, `/api/sections/${sectionId}/bild`, { data: form });
      window.location.reload();
    } catch (error) {
      uploadStatus.replaceChildren(note('error', 'Fehler', error.message));
      uploadButton.disabled = false;
    }
  });

  if (fields.length > 0) {
    parts.push(group('Bild austauschen', [
      fields.length > 1 ? fieldSelect : el('span'),
      fileInput,
      el('div', 'wae-row', [uploadButton]),
      uploadStatus,
    ]));
  }

  // --- Anordnung ---------------------------------------------------
  const up = actionButton('Nach oben', () => api(config, `/api/sections/${sectionId}/reihenfolge`, { data: { direction: 'up' } }));
  const down = actionButton('Nach unten', () => api(config, `/api/sections/${sectionId}/reihenfolge`, { data: { direction: 'down' } }));
  const toggle = actionButton(section.hidden ? 'Einblenden' : 'Ausblenden',
    () => api(config, `/api/sections/${sectionId}/sichtbar`, { data: {} }));

  parts.push(group('Abschnitt', [el('div', 'wae-row', [up, down, toggle])]));

  // --- Protokoll ---------------------------------------------------
  if (data.changes.length > 0) {
    const log = el('div', 'wae-log');
    data.changes.forEach((change) => {
      const item = el('div', 'wae-log__item', [
        el('strong', 'wae-log__who', [text(change.label)]),
        el('span', 'wae-log__what', [text(change.summary)]),
        el('span', 'wae-log__when', [text(formatDate(change.created_at))]),
      ]);

      const undo = el('button', 'wae-btn wae-btn--sm wae-btn--danger', [text('Zurücknehmen')]);
      undo.type = 'button';
      undo.style.marginTop = '0.45rem';
      undo.addEventListener('click', async () => {
        try {
          await api(config, `/api/sections/${sectionId}/zuruecksetzen`, { data: { change: change.id } });
          window.location.reload();
        } catch (error) {
          item.append(note('error', 'Fehler', error.message));
        }
      });

      item.append(undo);
      log.append(item);
    });
    parts.push(group('Was bisher geändert wurde', [log]));
  }

  body.replaceChildren(...parts);
}

/* ------------------------------------------------------------------ */
/* Helfer                                                              */
/* ------------------------------------------------------------------ */

function imageFields(content) {
  const fields = [];

  if (content && typeof content.image === 'object') {
    fields.push({ path: 'image', label: 'Hauptbild' });
  }
  if (Array.isArray(content?.items)) {
    content.items.forEach((item, index) => {
      if (item && typeof item.image === 'object') {
        const name = item.title || item.name || `Eintrag ${index + 1}`;
        fields.push({ path: `items.${index}.image`, label: `Bild: ${name}` });
      }
    });
  }

  return fields;
}

function actionButton(label, action) {
  const button = el('button', 'wae-btn', [text(label)]);
  button.type = 'button';
  button.addEventListener('click', async () => {
    button.disabled = true;
    try {
      await action();
      window.location.reload();
    } catch (error) {
      button.disabled = false;
      button.after(note('error', 'Fehler', error.message));
    }
  });
  return button;
}

async function api(config, url, { method = 'POST', data = null } = {}) {
  const options = {
    method,
    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': config.token },
  };

  if (data instanceof FormData) {
    data.append('_token', config.token);
    options.body = data;
  } else if (data !== null) {
    options.headers['Content-Type'] = 'application/json';
    options.body = JSON.stringify({ ...data, _token: config.token });
  }

  const response = await fetch(url, options);
  const payload = await response.json().catch(() => ({}));

  if (!response.ok || payload.ok === false) {
    throw new Error(payload.error || `Fehlgeschlagen (${response.status})`);
  }
  return payload;
}

function el(tag, className = '', children = []) {
  const node = document.createElement(tag);
  if (className) node.className = className;
  children.forEach((child) => node.append(child));
  return node;
}

function text(value) {
  return document.createTextNode(String(value ?? ''));
}

function group(title, children) {
  return el('div', 'wae-group', [el('h3', 'wae-group__title', [text(title)]), ...children]);
}

function note(kind, title, message) {
  const box = el('div', 'wae-note' + (kind ? ' wae-note--' + kind : ''));
  if (title) box.append(el('strong', '', [text(title)]));
  box.append(text(message));
  return box;
}

function formatDate(value) {
  const date = new Date(String(value).replace(' ', 'T'));
  if (Number.isNaN(date.getTime())) return value;
  return date.toLocaleString('de-CH', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
}
