/*
 * Reymond CMS – Skript innerhalb der bearbeiteten Seite.
 *
 * Es läuft im Rahmen (iframe) und kümmert sich um alles, was direkt an der
 * Seite passiert: Abschnitte auswählen, verschieben, Text bearbeiten,
 * neue Elemente aufnehmen. Gespeichert wird nichts – das erledigt die
 * Werkzeugleiste aussen. Verständigt wird sich über postMessage.
 */

(function () {
  'use strict';

  var parentWin = window.parent;
  if (parentWin === window) return; // Ohne Editor drumherum tun wir nichts.

  var selected = null;
  var dropLine = null;

  /* ----------------------------------------------------------------------
   * Nachrichten nach aussen
   * -------------------------------------------------------------------- */

  function send(type, payload) {
    parentWin.postMessage(Object.assign({ rc: true, type: type }, payload || {}), '*');
  }

  /* ----------------------------------------------------------------------
   * Werkzeuge an jedem Abschnitt
   * -------------------------------------------------------------------- */

  var ICONS = {
    move: '<svg viewBox="0 0 24 24"><path d="M9 6h.01M9 12h.01M9 18h.01M15 6h.01M15 12h.01M15 18h.01"/></svg>',
    up: '<svg viewBox="0 0 24 24"><path d="M12 19V5M5 12l7-7 7 7"/></svg>',
    down: '<svg viewBox="0 0 24 24"><path d="M12 5v14M19 12l-7 7-7-7"/></svg>',
    copy: '<svg viewBox="0 0 24 24"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 012-2h8"/></svg>',
    gear: '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M12 3l1.2 2.2 2.5-.5.5 2.5L18.4 8.4l-1.3 2.2 1.3 2.2-2.2 1.2-.5 2.5-2.5-.5L12 18.4l-1.2-2.2-2.5.5-.5-2.5-2.2-1.2 1.3-2.2L5.6 8.4l2.2-1.2.5-2.5 2.5.5z"/></svg>',
    trash:
      '<svg viewBox="0 0 24 24"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/></svg>',
  };

  function label(section) {
    var names = {
      hero: 'Banner',
      pagehead: 'Seitentitel',
      marquee: 'Laufband',
      about: 'Text mit Titel',
      text: 'Freier Text',
      dates: 'Termine',
      player: 'Musikplayer',
      tracklist: 'Titelliste',
      contact: 'Kontakt',
      cta: 'Schlussaufruf',
    };
    return names[section.getAttribute('data-rc-type')] || 'Abschnitt';
  }

  function addTools(section) {
    if (section.querySelector(':scope > .rc-tools')) return;

    var bar = document.createElement('div');
    bar.className = 'rc-tools';
    bar.setAttribute('contenteditable', 'false');
    bar.innerHTML =
      '<button class="rc-tool rc-tool--grab" type="button" data-act="move" draggable="true" title="Verschieben">' +
      ICONS.move +
      '</button>' +
      '<span class="rc-tool__name">' +
      label(section) +
      '</span>' +
      '<button class="rc-tool" type="button" data-act="up" title="Nach oben">' +
      ICONS.up +
      '</button>' +
      '<button class="rc-tool" type="button" data-act="down" title="Nach unten">' +
      ICONS.down +
      '</button>' +
      '<button class="rc-tool" type="button" data-act="copy" title="Verdoppeln">' +
      ICONS.copy +
      '</button>' +
      '<button class="rc-tool" type="button" data-act="gear" title="Einstellungen">' +
      ICONS.gear +
      '</button>' +
      '<button class="rc-tool" type="button" data-act="trash" title="Löschen">' +
      ICONS.trash +
      '</button>';

    section.appendChild(bar);

    bar.addEventListener('click', function (event) {
      var button = event.target.closest('[data-act]');
      if (!button) return;
      event.preventDefault();
      event.stopPropagation();

      var id = section.getAttribute('data-rc-section');
      var act = button.getAttribute('data-act');

      if (act === 'gear') {
        select(section);
        send('open-settings', { id: id });
      } else if (act === 'trash') {
        send('remove', { id: id });
      } else if (act === 'copy') {
        send('duplicate', { id: id });
      } else if (act === 'up' || act === 'down') {
        send('shift', { id: id, dir: act === 'up' ? -1 : 1 });
      }
    });

    // Abschnitt an seinem Griff verschieben
    var handle = bar.querySelector('[data-act="move"]');

    handle.addEventListener('dragstart', function (event) {
      section.classList.add('is-dragging');
      parentWin.RC_DRAG = { mode: 'move', id: section.getAttribute('data-rc-section') };
      try {
        event.dataTransfer.setData('text/plain', 'move');
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setDragImage(section, 40, 20);
      } catch (error) {
        /* ältere Browser */
      }
    });

    handle.addEventListener('dragend', function () {
      section.classList.remove('is-dragging');
      hideDrop();
      parentWin.RC_DRAG = null;
    });
  }

  function sections() {
    return Array.prototype.slice.call(document.querySelectorAll('[data-rc-section]'));
  }

  function refresh() {
    sections().forEach(addTools);
    send('sections', {
      order: sections().map(function (s) {
        return s.getAttribute('data-rc-section');
      }),
    });
  }

  /* ----------------------------------------------------------------------
   * Auswahl
   * -------------------------------------------------------------------- */

  function select(section) {
    if (selected === section) return;

    if (selected) selected.classList.remove('is-selected');
    selected = section;

    if (section) {
      section.classList.add('is-selected');
      send('select', {
        id: section.getAttribute('data-rc-section'),
        kind: section.getAttribute('data-rc-type'),
      });
    } else {
      send('select', { id: null });
    }
  }

  document.addEventListener('click', function (event) {
    // Verweise führen im Editor nicht weg – ausser in der Navigation.
    var link = event.target.closest('a');

    if (link) {
      event.preventDefault();

      var page = link.getAttribute('data-rc-page');
      if (page) send('navigate', { slug: page });
    }

    var editable = event.target.closest('[data-rc-edit]');
    var section = event.target.closest('[data-rc-section]');

    if (editable && section) {
      startEditing(editable, section);
    }

    select(section || null);
  });

  /* ----------------------------------------------------------------------
   * Text direkt auf der Seite ändern
   * -------------------------------------------------------------------- */

  var editing = null;

  function startEditing(element, section) {
    if (editing === element) return;

    stopEditing();

    editing = element;
    element.setAttribute(
      'contenteditable',
      element.hasAttribute('data-rc-rich') ? 'true' : 'plaintext-only',
    );
    element.focus();

    element.addEventListener('blur', onBlur);
    element.addEventListener('keydown', onKey);

    function onKey(event) {
      if (event.key === 'Escape') {
        element.blur();
      }

      if (event.key === 'Enter' && !element.hasAttribute('data-rc-rich')) {
        event.preventDefault();
        element.blur();
      }
    }

    function onBlur() {
      element.removeEventListener('blur', onBlur);
      element.removeEventListener('keydown', onKey);

      send('inline', {
        id: section.getAttribute('data-rc-section'),
        field: element.getAttribute('data-rc-edit'),
        rich: element.hasAttribute('data-rc-rich'),
        value: element.hasAttribute('data-rc-rich')
          ? element.innerHTML
          : element.textContent.trim(),
      });

      element.removeAttribute('contenteditable');
      editing = null;
    }
  }

  function stopEditing() {
    if (editing) editing.blur();
  }

  /* ----------------------------------------------------------------------
   * Ziehen und Ablegen
   * -------------------------------------------------------------------- */

  function showDrop(target, before) {
    if (!dropLine) {
      dropLine = document.createElement('div');
      dropLine.className = 'rc-drop';
      document.body.appendChild(dropLine);
    }

    var box = target.getBoundingClientRect();
    var y = (before ? box.top : box.bottom) + window.scrollY;

    dropLine.style.top = y - 1 + 'px';
    dropLine.style.display = 'block';
  }

  function hideDrop() {
    if (dropLine) dropLine.style.display = 'none';
  }

  function dropIndex(clientY) {
    var list = sections();
    var index = list.length;

    for (var i = 0; i < list.length; i += 1) {
      var box = list[i].getBoundingClientRect();
      var middle = box.top + box.height / 2;

      if (clientY < middle) {
        showDrop(list[i], true);
        return i;
      }
    }

    if (list.length) showDrop(list[list.length - 1], false);

    return index;
  }

  document.addEventListener('dragover', function (event) {
    if (!parentWin.RC_DRAG) return;
    event.preventDefault();

    if (event.dataTransfer) {
      event.dataTransfer.dropEffect = parentWin.RC_DRAG.mode === 'move' ? 'move' : 'copy';
    }

    window.RC_DROP_INDEX = dropIndex(event.clientY);
  });

  document.addEventListener('dragleave', function (event) {
    if (event.clientX <= 0 || event.clientY <= 0) hideDrop();
  });

  document.addEventListener('drop', function (event) {
    if (!parentWin.RC_DRAG) return;
    event.preventDefault();

    var index = typeof window.RC_DROP_INDEX === 'number' ? window.RC_DROP_INDEX : sections().length;
    var drag = parentWin.RC_DRAG;

    hideDrop();
    parentWin.RC_DRAG = null;

    if (drag.mode === 'move') {
      send('move', { id: drag.id, index: index });
    } else {
      send('insert', { kind: drag.type, index: index });
    }
  });

  /* ----------------------------------------------------------------------
   * Befehle von aussen
   * -------------------------------------------------------------------- */

  window.addEventListener('message', function (event) {
    var data = event.data;
    if (!data || !data.rc) return;

    var target = data.id ? document.querySelector('[data-rc-section="' + data.id + '"]') : null;

    if (data.type === 'replace' && target) {
      target.outerHTML = data.html;
      refresh();
      var fresh = document.querySelector('[data-rc-section="' + data.id + '"]');
      if (fresh) {
        selected = null;
        select(fresh);
      }
    } else if (data.type === 'insert') {
      var list = sections();
      var holder = document.createElement('div');
      holder.innerHTML = data.html;
      var node = holder.firstElementChild;
      var empty = document.querySelector('.rc-empty');

      if (empty) empty.remove();

      if (list[data.index]) {
        list[data.index].parentNode.insertBefore(node, list[data.index]);
      } else {
        (document.querySelector('main') || document.body).appendChild(node);
      }

      refresh();
      select(node);
      node.scrollIntoView({ behavior: 'smooth', block: 'center' });
    } else if (data.type === 'remove' && target) {
      target.remove();
      selected = null;
      refresh();
      showEmptyIfNeeded();
    } else if (data.type === 'move' && target) {
      var all = sections().filter(function (s) {
        return s !== target;
      });
      if (all[data.index]) {
        all[data.index].parentNode.insertBefore(target, all[data.index]);
      } else {
        (document.querySelector('main') || document.body).appendChild(target);
      }
      refresh();
    } else if (data.type === 'select' && target) {
      select(target);
      target.scrollIntoView({ behavior: 'smooth', block: 'center' });
    } else if (data.type === 'deselect') {
      select(null);
    } else if (data.type === 'reload') {
      window.location.reload();
    }
  });

  function showEmptyIfNeeded() {
    if (sections().length) return;

    var main = document.querySelector('main');
    if (!main || main.querySelector('.rc-empty')) return;

    var empty = document.createElement('div');
    empty.className = 'rc-empty';
    empty.innerHTML =
      '<b>Leere Seite</b><span>Oben auf „Elemente“ klicken und einen Baustein hierher ziehen.</span>';
    main.appendChild(empty);
  }

  /* ----------------------------------------------------------------------
   * Start
   * -------------------------------------------------------------------- */

  refresh();
  showEmptyIfNeeded();
  send('ready', { url: window.location.href });
})();
