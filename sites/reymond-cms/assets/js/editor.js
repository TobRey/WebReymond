/*
 * Reymond CMS – Werkzeugleiste und Steuerung.
 *
 * Hier liegt das Modell der Seite (die JSON-Daten). Der Rahmen zeigt die
 * Seite, dieses Skript hält die Daten, zeichnet die Einstellungen und
 * speichert. Neu gezeichnet wird immer auf dem Server – deshalb sieht der
 * Editor exakt aus wie die fertige Seite.
 */

(function () {
  'use strict';

  var state = {
    slug: window.RC.slug,
    page: null,
    types: {},
    styleFields: [],
    advancedFields: [],
    selected: null,
    dirty: false,
    history: [],
    saving: false,
  };

  var canvas = document.getElementById('rc-canvas');
  var statusEl = document.getElementById('rc-status');
  var saveBtn = document.getElementById('rc-save');
  var inspector = document.getElementById('rc-inspector');
  var fieldsEl = document.getElementById('rc-fields');
  var elementsPanel = document.getElementById('rc-elements');
  var currentTab = 'inhalt';

  /* ----------------------------------------------------------------------
   * Hilfen
   * -------------------------------------------------------------------- */

  function url(path) {
    return window.RC.base + path;
  }

  function status(text, warn) {
    statusEl.textContent = text;
    statusEl.classList.toggle('is-warn', !!warn);
  }

  function post(path, body) {
    return fetch(url(path), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-RC-Token': window.RC.token },
      body: JSON.stringify(Object.assign({ csrf: window.RC.token }, body || {})),
    }).then(function (response) {
      return response.json().then(function (data) {
        if (!response.ok) throw new Error(data.error || 'Fehler ' + response.status);
        return data;
      });
    });
  }

  function toCanvas(type, payload) {
    canvas.contentWindow.postMessage(Object.assign({ rc: true, type: type }, payload || {}), '*');
  }

  function markDirty(dirty) {
    state.dirty = dirty;
    saveBtn.classList.toggle('is-dirty', dirty);
    status(dirty ? 'Nicht gespeichert' : 'Gespeichert', dirty);
  }

  function snapshot() {
    state.history.push(JSON.stringify(state.page.sections));
    if (state.history.length > 40) state.history.shift();
  }

  function findSection(id) {
    return (
      state.page.sections.filter(function (s) {
        return s.id === id;
      })[0] || null
    );
  }

  function indexOf(id) {
    for (var i = 0; i < state.page.sections.length; i += 1) {
      if (state.page.sections[i].id === id) return i;
    }
    return -1;
  }

  /* ----------------------------------------------------------------------
   * Seite laden
   * -------------------------------------------------------------------- */

  function loadPage(slug) {
    return fetch(url('api/page?slug=' + encodeURIComponent(slug)), {
      headers: { 'X-RC-Token': window.RC.token },
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        state.slug = data.slug;
        state.page = data.page;
        state.types = data.types;
        state.styleFields = data.style;
        state.advancedFields = data.advanced;
        state.history = [];
        markDirty(false);
        document.getElementById('rc-page-select').value = data.slug;
        return data;
      });
  }

  /* ----------------------------------------------------------------------
   * Einen Abschnitt neu zeichnen lassen
   * -------------------------------------------------------------------- */

  function rerender(section) {
    return post('api/render', { section: section }).then(function (data) {
      toCanvas('replace', { id: section.id, html: data.html });
    });
  }

  /* ----------------------------------------------------------------------
   * Nachrichten aus dem Rahmen
   * -------------------------------------------------------------------- */

  window.addEventListener('message', function (event) {
    var data = event.data;
    if (!data || !data.rc) return;

    switch (data.type) {
      case 'ready':
        status('Bereit');
        break;

      case 'select':
        state.selected = data.id;
        if (data.id) {
          openInspector(data.id);
        } else {
          closeInspector();
        }
        break;

      case 'open-settings':
        state.selected = data.id;
        openInspector(data.id);
        break;

      case 'inline':
        applyInline(data);
        break;

      case 'insert':
        insertSection(data.kind, data.index);
        break;

      case 'move':
        moveSection(data.id, data.index);
        break;

      case 'shift':
        shiftSection(data.id, data.dir);
        break;

      case 'duplicate':
        duplicateSection(data.id);
        break;

      case 'remove':
        removeSection(data.id);
        break;

      case 'navigate':
        switchPage(data.slug);
        break;
    }
  });

  /* ----------------------------------------------------------------------
   * Änderungen am Modell
   * -------------------------------------------------------------------- */

  function applyInline(data) {
    var section = findSection(data.id);
    if (!section) return;

    snapshot();
    section.props[data.field] = data.value;
    markDirty(true);

    // Der Text steht schon richtig auf der Seite – kein neues Zeichnen nötig.
    if (inspector.hidden === false && state.selected === data.id) {
      openInspector(data.id, true);
    }
  }

  function insertSection(kind, index) {
    if (!state.types[kind]) return;

    snapshot();

    var section = {
      id: 's' + Math.random().toString(16).slice(2, 12),
      type: kind,
      props: Object.assign(
        {
          bg: 'schwarz',
          space: 'normal',
          borderTop: false,
          borderBottom: false,
          anchor: '',
          hideMobile: false,
        },
        JSON.parse(JSON.stringify(state.types[kind].defaults)),
      ),
    };

    state.page.sections.splice(index, 0, section);
    markDirty(true);

    post('api/render', { section: section }).then(function (data) {
      toCanvas('insert', { html: data.html, index: index });
      status('„' + state.types[kind].label + '“ eingefügt');
    });
  }

  function moveSection(id, index) {
    var from = indexOf(id);
    if (from < 0) return;

    snapshot();

    var section = state.page.sections.splice(from, 1)[0];
    var target = index > from ? index - 1 : index;

    state.page.sections.splice(target, 0, section);
    markDirty(true);
    toCanvas('move', { id: id, index: target });
  }

  function shiftSection(id, dir) {
    var from = indexOf(id);
    var to = from + dir;

    if (from < 0 || to < 0 || to >= state.page.sections.length) return;

    snapshot();

    var section = state.page.sections.splice(from, 1)[0];
    state.page.sections.splice(to, 0, section);
    markDirty(true);
    toCanvas('move', { id: id, index: to });
  }

  function duplicateSection(id) {
    var index = indexOf(id);
    if (index < 0) return;

    snapshot();

    var copy = JSON.parse(JSON.stringify(state.page.sections[index]));
    copy.id = 's' + Math.random().toString(16).slice(2, 12);

    state.page.sections.splice(index + 1, 0, copy);
    markDirty(true);

    post('api/render', { section: copy }).then(function (data) {
      toCanvas('insert', { html: data.html, index: index + 1 });
      status('Verdoppelt');
    });
  }

  function removeSection(id) {
    var index = indexOf(id);
    if (index < 0) return;

    var name = state.types[state.page.sections[index].type].label;

    if (!window.confirm('„' + name + '“ wirklich löschen?')) return;

    snapshot();
    state.page.sections.splice(index, 1);
    markDirty(true);
    toCanvas('remove', { id: id });
    closeInspector();
    status('Gelöscht');
  }

  function undo() {
    if (!state.history.length) {
      status('Nichts rückgängig zu machen');
      return;
    }

    state.page.sections = JSON.parse(state.history.pop());
    markDirty(true);
    reloadCanvasFromModel();
  }

  // Nach dem Rückgängigmachen wird die Seite einmal gespeichert und neu
  // geladen – so ist das Bild garantiert identisch mit den Daten.
  function reloadCanvasFromModel() {
    save(true).then(function () {
      toCanvas('reload', {});
    });
  }

  /* ----------------------------------------------------------------------
   * Speichern
   * -------------------------------------------------------------------- */

  function save(silent) {
    if (state.saving) return Promise.resolve();

    state.saving = true;
    status('Speichern …');

    return post('api/save', { slug: state.slug, sections: state.page.sections })
      .then(function (data) {
        markDirty(false);
        if (!silent) status('Gespeichert ' + data.saved);
        return data;
      })
      .catch(function (error) {
        status(error.message, true);
        window.alert('Speichern nicht möglich: ' + error.message);
      })
      .then(function (result) {
        state.saving = false;
        return result;
      });
  }

  saveBtn.addEventListener('click', function () {
    save(false);
  });

  document.addEventListener('keydown', function (event) {
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') {
      event.preventDefault();
      save(false);
    }

    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'z') {
      event.preventDefault();
      undo();
    }
  });

  document.getElementById('rc-undo').addEventListener('click', undo);

  window.addEventListener('beforeunload', function (event) {
    if (!state.dirty) return;
    event.preventDefault();
    event.returnValue = '';
  });

  /* ----------------------------------------------------------------------
   * Seitenwechsel – vorher immer speichern
   * -------------------------------------------------------------------- */

  function switchPage(slug) {
    var go = function () {
      loadPage(slug).then(function () {
        canvas.src = url(slug === firstSlug() ? '' : slug) + '?rc_edit=1';
        closeInspector();
        status('Seite „' + slug + '“');
      });
    };

    if (state.dirty) {
      save(true).then(go);
    } else {
      go();
    }
  }

  function firstSlug() {
    var select = document.getElementById('rc-page-select');
    return select.options.length ? select.options[0].value : 'start';
  }

  document.getElementById('rc-page-select').addEventListener('change', function (event) {
    switchPage(event.target.value);
  });

  /* ----------------------------------------------------------------------
   * Elemente einfügen
   * -------------------------------------------------------------------- */

  document.getElementById('rc-add').addEventListener('click', function () {
    elementsPanel.hidden = !elementsPanel.hidden;
  });

  elementsPanel.addEventListener('click', function (event) {
    if (event.target.closest('[data-close-elements]')) {
      elementsPanel.hidden = true;
      return;
    }

    var card = event.target.closest('.rc-element');
    if (!card) return;

    insertSection(card.getAttribute('data-type'), state.page.sections.length);
    elementsPanel.hidden = true;
  });

  Array.prototype.forEach.call(document.querySelectorAll('.rc-element'), function (card) {
    card.addEventListener('dragstart', function (event) {
      window.RC_DRAG = { mode: 'new', type: card.getAttribute('data-type') };
      try {
        event.dataTransfer.setData('text/plain', card.getAttribute('data-type'));
        event.dataTransfer.effectAllowed = 'copy';
      } catch (error) {
        /* egal */
      }
      elementsPanel.hidden = true;
    });

    card.addEventListener('dragend', function () {
      window.RC_DRAG = null;
    });
  });

  /* ----------------------------------------------------------------------
   * Einstellungen eines Abschnitts
   * -------------------------------------------------------------------- */

  function openInspector(id, keepScroll) {
    var section = findSection(id);
    if (!section) return;

    var scroll = keepScroll ? fieldsEl.scrollTop : 0;

    inspector.hidden = false;
    document.getElementById('rc-inspector-title').textContent = state.types[section.type].label;

    var fields =
      currentTab === 'inhalt'
        ? state.types[section.type].fields
        : currentTab === 'stil'
          ? state.styleFields
          : state.advancedFields;

    fieldsEl.innerHTML = '';

    fields.forEach(function (field) {
      var node = buildField(field, section);
      if (node) fieldsEl.appendChild(node);
    });

    fieldsEl.scrollTop = scroll;
  }

  function closeInspector() {
    inspector.hidden = true;
    toCanvas('deselect', {});
  }

  document.getElementById('rc-inspector-close').addEventListener('click', closeInspector);

  Array.prototype.forEach.call(document.querySelectorAll('.rc-tab'), function (tab) {
    tab.addEventListener('click', function () {
      Array.prototype.forEach.call(document.querySelectorAll('.rc-tab'), function (t) {
        t.classList.toggle('is-active', t === tab);
      });
      currentTab = tab.getAttribute('data-tab');
      if (state.selected) openInspector(state.selected);
    });
  });

  /* ----------------------------------------------------------------------
   * Ein einzelnes Eingabefeld bauen
   * -------------------------------------------------------------------- */

  function change(section, key, value, redraw) {
    snapshot();
    section.props[key] = value;
    markDirty(true);

    if (redraw !== false) rerender(section);
  }

  function visible(field, section) {
    if (!field.show) return true;

    var parts = field.show.split('=');
    return String(section.props[parts[0]]) === parts[1];
  }

  function buildField(field, section) {
    if (!visible(field, section)) return null;

    var wrap = document.createElement('div');
    wrap.className = 'rc-field';

    var value = section.props[field.key];

    if (field.type === 'info') {
      wrap.className = 'rc-field rc-field--info';
      wrap.textContent = field.label;
      return wrap;
    }

    if (field.type === 'toggle') {
      var sw = document.createElement('label');
      sw.className = 'rc-switch';
      sw.innerHTML = '<span class="rc-label" style="margin:0">' + field.label + '</span>';

      var input = document.createElement('input');
      input.type = 'checkbox';
      input.checked = !!value;

      var track = document.createElement('span');
      track.className = 'rc-switch__track';

      input.addEventListener('change', function () {
        change(section, field.key, input.checked);
      });

      sw.appendChild(input);
      sw.appendChild(track);
      wrap.appendChild(sw);
      return wrap;
    }

    var labelEl = document.createElement('label');
    labelEl.textContent = field.label;
    wrap.appendChild(labelEl);

    if (field.type === 'textarea' || field.type === 'richtext') {
      var area = document.createElement('textarea');
      area.value = field.type === 'richtext' ? htmlToText(value) : value || '';
      area.addEventListener('change', function () {
        change(section, field.key, field.type === 'richtext' ? textToHtml(area.value) : area.value);
      });
      wrap.appendChild(area);

      if (field.type === 'richtext') {
        wrap.appendChild(hint('Leerzeile trennt Absätze. Fett geht mit **Sternchen**.'));
      }
    } else if (field.type === 'select') {
      var select = document.createElement('select');

      Object.keys(field.options).forEach(function (key) {
        var option = document.createElement('option');
        option.value = key;
        option.textContent = field.options[key];
        option.selected = String(value) === key;
        select.appendChild(option);
      });

      select.addEventListener('change', function () {
        change(section, field.key, select.value);
        openInspector(section.id, true); // abhängige Felder auffrischen
      });

      wrap.appendChild(select);
    } else if (field.type === 'range') {
      var row = document.createElement('div');
      row.className = 'rc-range';

      var range = document.createElement('input');
      range.type = 'range';
      range.min = field.min;
      range.max = field.max;
      range.step = field.step || 1;
      range.value = value === undefined || value === '' ? field.min : value;

      var out = document.createElement('output');
      out.textContent = range.value + (field.unit || '');

      range.addEventListener('input', function () {
        out.textContent = range.value + (field.unit || '');
      });

      range.addEventListener('change', function () {
        change(section, field.key, Number(range.value));
      });

      row.appendChild(range);
      row.appendChild(out);
      wrap.appendChild(row);
    } else if (field.type === 'image') {
      wrap.appendChild(imageField(section, field, value));
    } else if (field.type === 'link') {
      wrap.appendChild(linkField(section, field, value || {}));
    } else if (field.type === 'repeater') {
      wrap.appendChild(repeaterField(section, field, Array.isArray(value) ? value : []));
    } else {
      var text = document.createElement('input');
      text.type = 'text';
      text.value = value === undefined ? '' : value;
      text.addEventListener('change', function () {
        change(section, field.key, text.value);
      });
      wrap.appendChild(text);
    }

    if (field.hint) wrap.appendChild(hint(field.hint));

    return wrap;
  }

  function hint(text) {
    var span = document.createElement('span');
    span.className = 'rc-hint';
    span.textContent = text;
    return span;
  }

  /* Bildfeld mit Hochladen ------------------------------------------------ */

  function imageField(section, field, value) {
    var box = document.createElement('div');
    box.className = 'rc-image';

    var preview = document.createElement('div');
    preview.className = 'rc-image__preview';
    preview.style.backgroundImage = value ? 'url(' + url(value) + ')' : 'none';

    var buttons = document.createElement('div');
    buttons.className = 'rc-image__buttons';

    var pick = document.createElement('button');
    pick.type = 'button';
    pick.className = 'rc-btn';
    pick.textContent = 'Bild wählen';

    var clear = document.createElement('button');
    clear.type = 'button';
    clear.className = 'rc-btn';
    clear.textContent = 'Entfernen';

    var input = document.createElement('input');
    input.type = 'file';
    input.accept = 'image/*';
    input.hidden = true;

    pick.addEventListener('click', function () {
      input.click();
    });

    input.addEventListener('change', function () {
      if (!input.files || !input.files[0]) return;
      status('Bild wird geladen …');

      upload(input.files[0])
        .then(function (data) {
          preview.style.backgroundImage = 'url(' + data.url + ')';
          change(section, field.key, data.path);
          status('Bild ersetzt');
        })
        .catch(function (error) {
          status(error.message, true);
          window.alert(error.message);
        });
    });

    clear.addEventListener('click', function () {
      preview.style.backgroundImage = 'none';
      change(section, field.key, '');
    });

    buttons.appendChild(pick);
    buttons.appendChild(clear);
    box.appendChild(preview);
    box.appendChild(buttons);
    box.appendChild(input);

    return box;
  }

  function upload(file) {
    var form = new FormData();
    form.append('datei', file);
    form.append('csrf', window.RC.token);

    return fetch(url('api/upload'), {
      method: 'POST',
      headers: { 'X-RC-Token': window.RC.token },
      body: form,
    })
      .then(function (response) {
        return response.json();
      })
      .then(function (data) {
        if (data.error) throw new Error(data.error);
        return data;
      });
  }

  /* Verweisfeld ---------------------------------------------------------- */

  function linkField(section, field, value) {
    var box = document.createElement('div');
    box.style.display = 'grid';
    box.style.gap = '0.4rem';

    var label = document.createElement('input');
    label.type = 'text';
    label.value = value.label || '';
    label.placeholder = 'Beschriftung';

    var target = document.createElement('input');
    target.type = 'text';
    target.value = value.url || '';
    target.placeholder = 'Ziel, z. B. kontakt';

    var style = document.createElement('select');
    [
      ['voll', 'Gefüllt'],
      ['umriss', 'Umriss'],
    ].forEach(function (option) {
      var node = document.createElement('option');
      node.value = option[0];
      node.textContent = option[1];
      node.selected = (value.style || 'voll') === option[0];
      style.appendChild(node);
    });

    function update() {
      change(section, field.key, { label: label.value, url: target.value, style: style.value });
    }

    label.addEventListener('change', update);
    target.addEventListener('change', update);
    style.addEventListener('change', update);

    box.appendChild(label);
    box.appendChild(target);
    box.appendChild(style);

    return box;
  }

  /* Wiederholfeld -------------------------------------------------------- */

  function repeaterField(section, field, rows) {
    var box = document.createElement('div');
    box.className = 'rc-repeater';

    function commit() {
      change(section, field.key, rows);
    }

    function draw() {
      box.innerHTML = '';

      rows.forEach(function (row, index) {
        var item = document.createElement('div');
        item.className = 'rc-rep-item';

        var head = document.createElement('div');
        head.className = 'rc-rep-item__head';
        head.innerHTML = '<span class="rc-grow">' + (index + 1) + '</span>';

        [
          ['↑', -1],
          ['↓', 1],
        ].forEach(function (move) {
          var button = document.createElement('button');
          button.type = 'button';
          button.textContent = move[0];
          button.title = move[1] < 0 ? 'Nach oben' : 'Nach unten';
          button.addEventListener('click', function () {
            var to = index + move[1];
            if (to < 0 || to >= rows.length) return;
            rows.splice(to, 0, rows.splice(index, 1)[0]);
            draw();
            commit();
          });
          head.appendChild(button);
        });

        var remove = document.createElement('button');
        remove.type = 'button';
        remove.textContent = '×';
        remove.title = 'Entfernen';
        remove.addEventListener('click', function () {
          rows.splice(index, 1);
          draw();
          commit();
        });
        head.appendChild(remove);

        item.appendChild(head);

        field.item.forEach(function (sub) {
          var input = document.createElement('input');
          input.type = 'text';
          input.placeholder = sub.label;
          input.value = row[sub.key] || '';
          input.addEventListener('change', function () {
            row[sub.key] = input.value;
            commit();
          });
          item.appendChild(input);
        });

        box.appendChild(item);
      });

      var add = document.createElement('button');
      add.type = 'button';
      add.className = 'rc-rep-add';
      add.textContent = '+ Eintrag';
      add.addEventListener('click', function () {
        var row = {};
        field.item.forEach(function (sub) {
          row[sub.key] = '';
        });
        rows.push(row);
        draw();
        commit();
      });

      box.appendChild(add);
    }

    draw();

    return box;
  }

  /* Einfacher Fliesstext <-> HTML ---------------------------------------- */

  function htmlToText(html) {
    return String(html || '')
      .replace(/<\/p>\s*<p>/gi, '\n\n')
      .replace(/<br\s*\/?>/gi, '\n')
      .replace(/<strong>|<b>/gi, '**')
      .replace(/<\/strong>|<\/b>/gi, '**')
      .replace(/<[^>]+>/g, '')
      .trim();
  }

  function textToHtml(text) {
    return String(text || '')
      .split(/\n{2,}/)
      .map(function (block) {
        var safe = block
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
          .replace(/\n/g, '<br />');
        return '<p>' + safe + '</p>';
      })
      .join('');
  }

  /* ----------------------------------------------------------------------
   * Ansicht: Desktop, iPad, Handy
   * -------------------------------------------------------------------- */

  var frame = document.getElementById('rc-frame');

  Array.prototype.forEach.call(document.querySelectorAll('.rc-device'), function (button) {
    button.addEventListener('click', function () {
      var device = button.getAttribute('data-device');

      Array.prototype.forEach.call(document.querySelectorAll('.rc-device'), function (b) {
        b.classList.toggle('is-active', b === button);
      });

      frame.classList.toggle('is-device', device !== 'desktop');
      frame.classList.toggle('is-tablet', device === 'tablet');
      status(device === 'desktop' ? 'Desktop' : device === 'tablet' ? 'iPad' : 'Handy');
    });
  });

  /* ----------------------------------------------------------------------
   * Leiste ein- und ausblenden
   * -------------------------------------------------------------------- */

  var bar = document.getElementById('rc-bar');

  document.getElementById('rc-hide').addEventListener('click', function () {
    bar.classList.add('is-hidden');
    document.body.classList.add('rc-bar-hidden');
    elementsPanel.hidden = true;
  });

  document.getElementById('rc-show').addEventListener('click', function () {
    bar.classList.remove('is-hidden');
    document.body.classList.remove('rc-bar-hidden');
  });

  /* Vor dem Gang ins Dashboard speichern */
  document.getElementById('rc-gear').addEventListener('click', function (event) {
    if (!state.dirty) return;
    event.preventDefault();
    save(true).then(function () {
      window.location.href = event.target.closest('a').href;
    });
  });

  /* ----------------------------------------------------------------------
   * Start
   * -------------------------------------------------------------------- */

  loadPage(state.slug).then(function () {
    status('Bereit');
  });
})();
