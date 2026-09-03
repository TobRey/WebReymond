/* Kleine Helfer im Bearbeitungsbereich. Keine externen Bibliotheken. */
(function () {
  'use strict';

  function $$(sel) { return Array.prototype.slice.call(document.querySelectorAll(sel)); }

  /* Passwort anzeigen und verbergen ---------------------------------- */
  $$('[data-reveal-target]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var out = document.getElementById(btn.getAttribute('data-reveal-target'));
      if (!out) return;
      var shown = btn.getAttribute('aria-pressed') === 'true';
      if (shown) {
        out.textContent = '••••••••••••';
        btn.setAttribute('aria-pressed', 'false');
        btn.textContent = 'Anzeigen';
      } else {
        out.textContent = out.getAttribute('data-value') || '';
        btn.setAttribute('aria-pressed', 'true');
        btn.textContent = 'Verbergen';
      }
    });
  });

  /* In die Zwischenablage kopieren ----------------------------------- */
  $$('[data-copy]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var value = btn.getAttribute('data-copy') || '';
      var done = function () {
        var old = btn.textContent;
        btn.textContent = 'Kopiert';
        window.setTimeout(function () { btn.textContent = old; }, 1400);
      };
      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(value).then(done, fallback);
      } else {
        fallback();
      }
      function fallback() {
        var ta = document.createElement('textarea');
        ta.value = value;
        ta.setAttribute('readonly', '');
        ta.style.position = 'fixed';
        ta.style.left = '-9999px';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); done(); } catch (e) { /* still */ }
        document.body.removeChild(ta);
      }
    });
  });

  /* Vor dem Löschen nachfragen --------------------------------------- */
  $$('form[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      if (!window.confirm(form.getAttribute('data-confirm'))) {
        e.preventDefault();
      }
    });
  });

  /* Vorschlag für ein starkes Passwort -------------------------------- */
  $$('[data-generate]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var field = document.getElementById(btn.getAttribute('data-generate'));
      if (!field) return;
      var alphabet = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!?%+-=@#';
      var out = '';
      var bytes = new Uint32Array(20);
      window.crypto.getRandomValues(bytes);
      for (var i = 0; i < 20; i++) { out += alphabet[bytes[i] % alphabet.length]; }
      field.type = 'text';
      field.value = out;
      field.dispatchEvent(new Event('input'));
      field.focus();
      field.select();
    });
  });

  /* Grobe Einschätzung der Passwortstärke ----------------------------- */
  $$('[data-strength]').forEach(function (field) {
    var bar = document.getElementById(field.getAttribute('data-strength'));
    if (!bar) return;
    var fill = bar.querySelector('i');
    field.addEventListener('input', function () {
      var v = field.value || '';
      var score = 0;
      if (v.length >= 12) score += 1;
      if (v.length >= 16) score += 1;
      if (v.length >= 24) score += 1;
      if (/[a-z]/.test(v) && /[A-Z]/.test(v)) score += 1;
      if (/[0-9]/.test(v)) score += 1;
      if (/[^A-Za-z0-9]/.test(v)) score += 1;
      var pct = Math.min(100, Math.round((score / 6) * 100));
      fill.style.width = pct + '%';
      fill.style.background = pct < 50 ? 'var(--bad)' : (pct < 84 ? 'var(--warn)' : 'var(--ok)');
    });
  });

  /* Listen: Eintraege hinzufuegen, verschieben, entfernen ---------------
     Jeder Eintrag traegt seine Nummer im Namen der Felder, zum Beispiel
     werdegang[3][titel]. Die Reihenfolge auf der Website ergibt sich aus
     der Reihenfolge hier – deshalb wird beim Verschieben der ganze Block
     verschoben und nicht nur die Nummer getauscht. */
  $$('[data-list]').forEach(function (list) {
    var slots = list.querySelector('[data-slots]');
    var tpl = list.querySelector('[data-template]');
    var addBtn = list.querySelector('[data-add]');
    var empty = list.querySelector('.a-list__empty');
    var max = parseInt(list.getAttribute('data-max'), 10) || 20;
    var next = slots ? slots.querySelectorAll('[data-slot]').length : 0;

    if (!slots || !tpl || !addBtn) return;

    function all() {
      return Array.prototype.slice.call(slots.querySelectorAll('[data-slot]'));
    }

    function refresh() {
      var items = all();
      items.forEach(function (slot, i) {
        var nr = slot.querySelector('[data-nr]');
        if (nr) nr.textContent = String(i + 1);
        var up = slot.querySelector('[data-move="up"]');
        var down = slot.querySelector('[data-move="down"]');
        if (up) up.disabled = (i === 0);
        if (down) down.disabled = (i === items.length - 1);
      });
      if (empty) empty.hidden = items.length > 0;
      addBtn.disabled = items.length >= max;
      addBtn.title = addBtn.disabled ? 'Mehr als ' + max + ' Einträge sind hier nicht vorgesehen.' : '';
    }

    function hasText(slot) {
      return Array.prototype.slice.call(slot.querySelectorAll('input, textarea'))
        .some(function (f) { return (f.value || '').trim() !== ''; });
    }

    addBtn.addEventListener('click', function () {
      if (all().length >= max) return;
      var html = tpl.innerHTML.split('__I__').join(String(next++));
      var box = document.createElement('div');
      box.innerHTML = html;
      var slot = box.firstElementChild;
      if (!slot) return;
      slot.classList.add('a-slot--neu');
      slots.appendChild(slot);
      refresh();
      var first = slot.querySelector('input, textarea');
      if (first) first.focus();
    });

    list.addEventListener('click', function (e) {
      var btn = e.target.closest ? e.target.closest('button') : null;
      if (!btn || !list.contains(btn)) return;
      var slot = btn.closest('[data-slot]');
      if (!slot) return;

      if (btn.hasAttribute('data-remove')) {
        if (hasText(slot) && !window.confirm('Diesen Eintrag wirklich entfernen? Gespeichert wird das erst, wenn du unten auf Speichern klickst.')) {
          return;
        }
        slot.parentNode.removeChild(slot);
        refresh();
        return;
      }

      var dir = btn.getAttribute('data-move');
      if (dir === 'up' && slot.previousElementSibling) {
        slots.insertBefore(slot, slot.previousElementSibling);
        refresh();
        btn.focus();
      } else if (dir === 'down' && slot.nextElementSibling) {
        slots.insertBefore(slot.nextElementSibling, slot);
        refresh();
        btn.focus();
      }
    });

    refresh();
  });

  /* Sitzung läuft ab: rechtzeitig warnen ------------------------------ */
  var idle = document.body.getAttribute('data-idle');
  if (idle) {
    var seconds = parseInt(idle, 10);
    if (seconds > 120) {
      window.setTimeout(function () {
        window.alert('Deine Anmeldung läuft in etwa zwei Minuten ab. Speichere, was du offen hast.');
      }, (seconds - 120) * 1000);
    }
  }
})();
