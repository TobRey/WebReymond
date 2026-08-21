/*
 * Backend – Dashboard.
 *
 * Reiter umschalten, Einstellungen und Musik speichern, Seiten verwalten,
 * Dateien hochladen. Gespeichert wird über dieselben Schnittstellen wie
 * im Baukasten.
 */

(function () {
  'use strict';

  var toast = document.getElementById('rc-toast');

  function say(text) {
    toast.textContent = text;
    toast.classList.add('is-on');
    window.setTimeout(function () {
      toast.classList.remove('is-on');
    }, 2600);
  }

  function post(path, body) {
    return fetch(window.RC.base + path, {
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

  function fail(error) {
    say(error.message);
    window.alert(error.message);
  }

  /* ----------------------------------------------------------------------
   * Reiter
   * -------------------------------------------------------------------- */

  document.getElementById('rc-dash-nav').addEventListener('click', function (event) {
    var button = event.target.closest('[data-panel]');
    if (!button) return;

    var name = button.getAttribute('data-panel');

    Array.prototype.forEach.call(document.querySelectorAll('#rc-dash-nav button'), function (b) {
      b.classList.toggle('is-active', b === button);
    });

    Array.prototype.forEach.call(document.querySelectorAll('.rc-tab-panel'), function (panel) {
      panel.hidden = panel.getAttribute('data-panel') !== name;
    });

    window.location.hash = name;
  });

  if (window.location.hash) {
    var start = document.querySelector(
      '#rc-dash-nav [data-panel="' + window.location.hash.slice(1) + '"]',
    );
    if (start) start.click();
  }

  /* ----------------------------------------------------------------------
   * Einstellungen
   * -------------------------------------------------------------------- */

  function collectSettings() {
    var settings = { socials: {}, effects: {} };

    Array.prototype.forEach.call(document.querySelectorAll('[data-setting]'), function (input) {
      settings[input.getAttribute('data-setting')] = input.value;
    });

    Array.prototype.forEach.call(document.querySelectorAll('[data-social]'), function (input) {
      settings.socials[input.getAttribute('data-social')] = input.value;
    });

    Array.prototype.forEach.call(document.querySelectorAll('[data-effect]'), function (input) {
      settings.effects[input.getAttribute('data-effect')] = input.checked;
    });

    return settings;
  }

  var saveSettings = document.getElementById('rc-save-settings');

  if (saveSettings) {
    saveSettings.addEventListener('click', function () {
      post('api/settings', { settings: collectSettings() })
        .then(function () {
          say('Einstellungen gespeichert');
        })
        .catch(fail);
    });
  }

  var saveEffects = document.getElementById('rc-save-effects');

  if (saveEffects) {
    saveEffects.addEventListener('click', function () {
      post('api/settings', { settings: collectSettings() })
        .then(function () {
          say('Darstellung gespeichert');
        })
        .catch(fail);
    });
  }

  /* ----------------------------------------------------------------------
   * Musik
   * -------------------------------------------------------------------- */

  var tracks = (window.RC.tracks || []).slice();
  var tableBody = document.querySelector('#rc-tracks tbody');

  function drawTracks() {
    if (!tableBody) return;

    tableBody.innerHTML = '';

    tracks.forEach(function (track, index) {
      var row = document.createElement('tr');

      ['title', 'tag', 'duration'].forEach(function (key) {
        var cell = document.createElement('td');
        var input = document.createElement('input');
        input.type = 'text';
        input.value = track[key] || '';
        input.addEventListener('input', function () {
          track[key] = input.value;
        });
        cell.appendChild(input);
        row.appendChild(cell);
      });

      row.appendChild(fileCell(track, 'audio', 'audio/*', 'Audio wählen'));
      row.appendChild(fileCell(track, 'cover', 'image/*', 'Cover'));

      var actions = document.createElement('td');
      var box = document.createElement('div');
      box.className = 'rc-row-actions';

      [
        ['↑', -1],
        ['↓', 1],
      ].forEach(function (move) {
        var button = document.createElement('button');
        button.className = 'rc-btn rc-btn--icon';
        button.type = 'button';
        button.textContent = move[0];
        button.addEventListener('click', function () {
          var to = index + move[1];
          if (to < 0 || to >= tracks.length) return;
          tracks.splice(to, 0, tracks.splice(index, 1)[0]);
          drawTracks();
        });
        box.appendChild(button);
      });

      var remove = document.createElement('button');
      remove.className = 'rc-btn rc-btn--icon';
      remove.type = 'button';
      remove.textContent = '×';
      remove.title = 'Titel entfernen';
      remove.addEventListener('click', function () {
        if (!window.confirm('„' + (track.title || 'Titel') + '“ entfernen?')) return;
        tracks.splice(index, 1);
        drawTracks();
      });

      box.appendChild(remove);
      actions.appendChild(box);
      row.appendChild(actions);

      tableBody.appendChild(row);
    });
  }

  function fileCell(track, key, accept, label) {
    var cell = document.createElement('td');

    var name = document.createElement('div');
    name.className = 'rc-note';
    name.style.marginBottom = '0.3rem';
    name.textContent = track[key] ? track[key].split('/').pop() : '—';

    var button = document.createElement('button');
    button.className = 'rc-btn';
    button.type = 'button';
    button.textContent = label;

    var input = document.createElement('input');
    input.type = 'file';
    input.accept = accept;
    input.hidden = true;

    button.addEventListener('click', function () {
      input.click();
    });

    input.addEventListener('change', function () {
      if (!input.files || !input.files[0]) return;
      say('Wird hochgeladen …');

      upload(input.files[0])
        .then(function (data) {
          track[key] = data.path;
          name.textContent = data.name;
          say('Hochgeladen – jetzt noch „Musik speichern“');
        })
        .catch(fail);
    });

    cell.appendChild(name);
    cell.appendChild(button);
    cell.appendChild(input);

    return cell;
  }

  function upload(file) {
    var form = new FormData();
    form.append('datei', file);
    form.append('csrf', window.RC.token);

    return fetch(window.RC.base + 'api/upload', {
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

  var addTrack = document.getElementById('rc-track-add');

  if (addTrack) {
    addTrack.addEventListener('click', function () {
      tracks.push({ title: 'Neuer Titel', tag: '', duration: '', audio: '', cover: '' });
      drawTracks();
    });
  }

  var saveTracks = document.getElementById('rc-tracks-save');

  if (saveTracks) {
    saveTracks.addEventListener('click', function () {
      post('api/tracks', { tracks: tracks })
        .then(function () {
          say('Musik gespeichert');
        })
        .catch(fail);
    });
  }

  drawTracks();

  /* ----------------------------------------------------------------------
   * Seiten
   * -------------------------------------------------------------------- */

  document.addEventListener('click', function (event) {
    var row = event.target.closest('tr[data-slug]');

    if (event.target.matches('[data-page-rename]') && row) {
      post('api/pages', {
        op: 'rename',
        slug: row.getAttribute('data-slug'),
        title: row.querySelector('[data-page-title]').value,
      })
        .then(function () {
          say('Umbenannt');
        })
        .catch(fail);
    }

    if (event.target.matches('[data-page-delete]') && row) {
      if (!window.confirm('Seite wirklich löschen? Alle Abschnitte darauf gehen verloren.')) return;

      post('api/pages', { op: 'delete', slug: row.getAttribute('data-slug') })
        .then(function () {
          say('Gelöscht');
          window.location.reload();
        })
        .catch(fail);
    }
  });

  var addPage = document.getElementById('rc-page-add');

  if (addPage) {
    addPage.addEventListener('click', function () {
      var title = document.getElementById('rc-new-page').value.trim();
      if (!title) return;

      post('api/pages', { op: 'add', title: title })
        .then(function () {
          say('Seite angelegt');
          window.location.reload();
        })
        .catch(fail);
    });
  }

  /* ----------------------------------------------------------------------
   * Dateien
   * -------------------------------------------------------------------- */

  var fileUpload = document.getElementById('rc-file-upload');

  if (fileUpload) {
    fileUpload.addEventListener('click', function () {
      var input = document.getElementById('rc-file');
      if (!input.files || !input.files[0]) return;

      upload(input.files[0])
        .then(function () {
          say('Hochgeladen');
          window.location.reload();
        })
        .catch(fail);
    });
  }

  /* ----------------------------------------------------------------------
   * Konto
   * -------------------------------------------------------------------- */

  var saveAccount = document.getElementById('rc-save-account');

  if (saveAccount) {
    saveAccount.addEventListener('click', function () {
      post('api/account', {
        current: document.getElementById('a-current').value,
        user: document.getElementById('a-user').value,
        password: document.getElementById('a-password').value,
      })
        .then(function () {
          say('Zugang geändert');
          document.getElementById('a-current').value = '';
          document.getElementById('a-password').value = '';
        })
        .catch(fail);
    });
  }

  /* ----------------------------------------------------------------------
   * Zurücksetzen
   * -------------------------------------------------------------------- */

  var reset = document.getElementById('rc-reset');

  if (reset) {
    reset.addEventListener('click', function () {
      if (!window.confirm('Alle Seiten auf den Auslieferungszustand zurücksetzen?')) return;

      post('api/reset', {})
        .then(function () {
          say('Zurückgesetzt');
        })
        .catch(fail);
    });
  }
})();
