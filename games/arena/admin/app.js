/* ==========================================================================
   Arena Admin - Dashboard für Maps, Gegner, Waffen, Upgrades und Balancing.
   Alle Änderungen gehen über die serverseitige API und landen in
   data/content.json - genau der Datei, aus der das Spiel liest.
   ========================================================================== */

const state = {
  content: window.ADMIN.content,
  csrf: window.ADMIN.csrf,
  sprites: window.ADMIN.sprites,
  uploadLimit: window.ADMIN.uploadLimit || '?',
  view: 'dashboard',
};

const $ = (id) => document.getElementById(id);
const el = (tag, cls, text) => {
  const node = document.createElement(tag);
  if (cls) node.className = cls;
  if (text != null) node.textContent = text;
  return node;
};

/* ------------------------------------------------------------------- API */
async function api(action, body = {}) {
  const res = await fetch('../api.php?action=' + action, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF': state.csrf },
    body: JSON.stringify({ ...body, csrf: state.csrf }),
  });
  const data = await res.json().catch(() => ({ ok: false, error: 'Ungültige Antwort' }));
  if (!data.ok) throw new Error(data.error || 'Fehler');
  if (data.content) state.content = data.content;
  return data;
}

async function uploadFile(file, name) {
  const form = new FormData();
  form.append('file', file);
  form.append('name', name || file.name.replace(/\.[^.]+$/, ''));
  form.append('csrf', state.csrf);
  const res = await fetch('../api.php?action=upload', {
    method: 'POST',
    headers: { 'X-CSRF': state.csrf },
    body: form,
  });
  const data = await res.json().catch(() => ({ ok: false, error: 'Upload fehlgeschlagen' }));
  if (!data.ok) throw new Error(data.error || 'Upload fehlgeschlagen');
  return data;
}

function toast(message, kind = 'good') {
  const node = el('div', 'toast toast--' + kind, message);
  $('toasts').appendChild(node);
  setTimeout(() => node.remove(), 3200);
}

/* ----------------------------------------------------------------- Modal */
function openModal(title, body, buttons = [], wide = false) {
  $('modal-title').textContent = title;
  const host = $('modal-body');
  host.textContent = '';
  host.appendChild(body);
  const foot = $('modal-foot');
  foot.textContent = '';
  for (const b of buttons) {
    const btn = el('button', 'btn ' + (b.class || ''), b.label);
    btn.addEventListener('click', () => b.onClick(closeModal));
    foot.appendChild(btn);
  }
  $('modal').classList.toggle('modal--wide', wide);
  $('modal').hidden = false;
}

function closeModal() {
  $('modal').hidden = true;
  $('modal-body').textContent = '';
}

document.querySelectorAll('[data-close-modal]').forEach((n) => n.addEventListener('click', closeModal));
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape' && !$('modal').hidden) closeModal();
});

function confirmDialog(text, onYes) {
  const body = el('p', 'muted', text);
  openModal('Bitte bestätigen', body, [
    { label: 'Abbrechen', class: 'btn--ghost', onClick: (close) => close() },
    { label: 'Löschen', class: 'btn--danger', onClick: (close) => { close(); onYes(); } },
  ]);
}

/* ------------------------------------------------------------ Formfelder */
function field(label, input, hint) {
  const wrap = el('div', 'field');
  const lbl = el('label', null, label);
  wrap.appendChild(lbl);
  wrap.appendChild(input);
  if (hint) wrap.appendChild(el('small', null, hint));
  return wrap;
}

function textInput(value, { type = 'text', step, min, max, placeholder } = {}) {
  const input = el('input', 'input');
  input.type = type;
  if (step != null) input.step = step;
  if (min != null) input.min = min;
  if (max != null) input.max = max;
  if (placeholder) input.placeholder = placeholder;
  input.value = value ?? '';
  return input;
}

function selectInput(value, options) {
  const select = el('select');
  for (const opt of options) {
    const o = el('option', null, opt.label);
    o.value = opt.value;
    if (String(opt.value) === String(value)) o.selected = true;
    select.appendChild(o);
  }
  return select;
}

function checkInput(label, checked) {
  const wrap = el('label', 'check');
  const input = el('input');
  input.type = 'checkbox';
  input.checked = !!checked;
  wrap.appendChild(input);
  wrap.appendChild(el('span', null, label));
  wrap.input = input;
  return wrap;
}

/** Sprite-Auswahl mit Vorschau und direktem Upload. */
function spriteField(label, value, onChange) {
  const wrap = el('div', 'field');
  wrap.appendChild(el('label', null, label));
  const row = el('div', 'preview');
  const img = el('img');
  img.src = '../' + (value || '');
  img.alt = '';
  const select = selectInput(value, [
    { value: '', label: '- kein Sprite -' },
    ...state.sprites.map((s) => ({ value: s, label: s.replace('assets/sprites/', '') })),
    ...(value && !state.sprites.includes(value) ? [{ value, label: value.replace('assets/uploads/', '') }] : []),
  ]);
  const upload = el('label', 'btn btn--sm', 'Hochladen');
  const fileInput = el('input');
  fileInput.type = 'file';
  fileInput.accept = 'image/png,image/gif,image/jpeg,image/webp';
  fileInput.style.display = 'none';
  upload.appendChild(fileInput);

  const set = (path) => {
    img.src = '../' + path;
    if (![...select.options].some((o) => o.value === path)) {
      const o = el('option', null, path.replace(/^assets\/(sprites|uploads)\//, ''));
      o.value = path;
      select.appendChild(o);
    }
    select.value = path;
    onChange(path);
  };

  select.addEventListener('change', () => set(select.value));
  fileInput.addEventListener('change', async () => {
    if (!fileInput.files.length) return;
    try {
      const data = await uploadFile(fileInput.files[0]);
      set(data.path);
      toast('Sprite hochgeladen');
    } catch (err) {
      toast(err.message, 'error');
    }
    fileInput.value = '';
  });

  const controls = el('div', 'field');
  controls.appendChild(select);
  controls.appendChild(upload);
  row.appendChild(img);
  row.appendChild(controls);
  wrap.appendChild(row);
  return wrap;
}

/* ---------------------------------------------------------- Ton-Editor */
/**
 * Editor für einen Ton mit bis zu vier Dateien.
 *
 * Beim Abspielen wählt das Spiel zufällig eine davon. "Häufigkeit" legt
 * fest, wie oft der Ton überhaupt kommt - praktisch für Treffergeräusche,
 * die sonst bei jedem Schuss hämmern.
 *
 * @returns Element mit .read() für den aktuellen Stand
 */
function soundSetEditor(label, set, { compact = false } = {}) {
  const data = {
    enabled: set?.enabled !== false,
    chance: typeof set?.chance === 'number' ? set.chance : 100,
    volume: typeof set?.volume === 'number' ? set.volume : 0.8,
    variants: Array.isArray(set?.variants) ? set.variants.map((v) => ({ ...v })) : [],
  };

  const box = el('div', 'soundset');
  const head = el('div', 'soundset__head');
  head.appendChild(el('div', 'soundset__label', label));

  const enabled = el('input');
  enabled.type = 'checkbox';
  enabled.checked = data.enabled;
  const enabledLabel = el('label', 'check');
  enabledLabel.appendChild(enabled);
  enabledLabel.appendChild(el('span', null, 'an'));
  head.appendChild(enabledLabel);
  box.appendChild(head);

  /* Vier Plätze für Dateien */
  const slots = el('div', 'soundset__slots');
  const render = () => {
    slots.textContent = '';
    for (let i = 0; i < 4; i++) {
      const variant = data.variants[i];
      const slot = el('div', 'soundslot' + (variant ? '' : ' is-empty'));

      if (variant) {
        slot.appendChild(el('div', 'soundslot__name', variant.src.split('/').pop()));

        const vol = el('input');
        vol.type = 'range';
        vol.min = '0';
        vol.max = '1';
        vol.step = '0.05';
        vol.value = String(variant.volume ?? 1);
        vol.title = 'Lautstärke dieser Datei';
        vol.addEventListener('input', () => { variant.volume = +vol.value; });
        slot.appendChild(vol);

        const play = el('button', 'btn btn--sm btn--ghost', '▶');
        play.title = 'Anhören';
        play.addEventListener('click', () => {
          const audio = new window.Audio('../' + variant.src);
          audio.volume = Math.min(1, (variant.volume ?? 1) * data.volume);
          audio.play().catch(() => toast('Wiedergabe blockiert - bitte nochmal tippen.', 'error'));
        });
        slot.appendChild(play);

        const remove = el('button', 'btn btn--sm btn--ghost', '✕');
        remove.title = 'Entfernen';
        remove.addEventListener('click', () => {
          data.variants.splice(i, 1);
          render();
        });
        slot.appendChild(remove);
      } else {
        slot.appendChild(el('div', 'soundslot__name muted', 'Datei ' + (i + 1)));
        const upload = el('input');
        upload.type = 'file';
        upload.accept = 'audio/mpeg,audio/ogg,audio/wav,audio/mp4,.mp3,.ogg,.wav,.m4a';
        upload.addEventListener('change', async () => {
          if (!upload.files.length) return;
          slot.classList.add('is-busy');
          try {
            const form = new FormData();
            form.append('file', upload.files[0]);
            form.append('kind', 'audio');
            form.append('name', upload.files[0].name.replace(/\.[^.]+$/, ''));
            form.append('csrf', state.csrf);
            const res = await fetch('../api.php?action=upload', {
              method: 'POST', headers: { 'X-CSRF': state.csrf }, body: form,
            });
            const result = await res.json();
            if (!result.ok) throw new Error(result.error || 'Upload fehlgeschlagen');
            data.variants[i] = { src: result.path, volume: 1 };
            data.variants = data.variants.filter(Boolean);
            render();
          } catch (err) {
            toast(err.message, 'error');
            slot.classList.remove('is-busy');
          }
        });
        slot.appendChild(upload);
      }
      slots.appendChild(slot);
    }
  };
  render();
  box.appendChild(slots);

  /* Lautstärke und Häufigkeit */
  const controls = el('div', 'soundset__controls');
  const volume = el('input');
  volume.type = 'range';
  volume.min = '0';
  volume.max = '1';
  volume.step = '0.05';
  volume.value = String(data.volume);
  const volumeText = el('span', 'muted', Math.round(data.volume * 100) + '%');
  volume.addEventListener('input', () => {
    data.volume = +volume.value;
    volumeText.textContent = Math.round(data.volume * 100) + '%';
  });

  const chance = el('input');
  chance.type = 'range';
  chance.min = '0';
  chance.max = '100';
  chance.step = '5';
  chance.value = String(data.chance);
  const chanceText = el('span', 'muted', data.chance + '%');
  chance.addEventListener('input', () => {
    data.chance = +chance.value;
    chanceText.textContent = data.chance + '%';
  });

  const volWrap = el('label', 'slider');
  volWrap.appendChild(el('span', null, 'Lautstärke'));
  volWrap.appendChild(volume);
  volWrap.appendChild(volumeText);
  const chanceWrap = el('label', 'slider');
  chanceWrap.appendChild(el('span', null, 'Häufigkeit'));
  chanceWrap.appendChild(chance);
  chanceWrap.appendChild(chanceText);
  controls.appendChild(volWrap);
  controls.appendChild(chanceWrap);
  box.appendChild(controls);

  if (!compact) {
    box.appendChild(el('p', 'muted',
      'Mehrere Dateien werden zufällig abgewechselt. Häufigkeit 100 % heißt: bei jedem Mal.'));
  }

  box.read = () => ({
    enabled: enabled.checked,
    chance: data.chance,
    volume: data.volume,
    variants: data.variants.filter(Boolean).slice(0, 4),
  });
  return box;
}

/* ------------------------------------------------------------ Navigation */
const views = {};

function setView(name) {
  state.view = name;
  document.querySelectorAll('.nav__item').forEach((b) => b.classList.toggle('is-active', b.dataset.view === name));
  $('view-title').textContent = {
    dashboard: 'Dashboard', maps: 'Maps', enemies: 'Gegner', weapons: 'Waffen',
    upgrades: 'Upgrades', player: 'Spieler', balance: 'Balancing', audio: 'Audio',
    characters: 'Charaktere',
  }[name];
  $('view-actions').textContent = '';
  const host = $('view');
  host.textContent = '';
  views[name](host);
  $('sidebar')?.classList.remove('is-open');
  document.querySelector('.sidebar').classList.remove('is-open');
}

document.querySelectorAll('.nav__item').forEach((b) =>
  b.addEventListener('click', () => setView(b.dataset.view)));
$('btn-menu').addEventListener('click', () => document.querySelector('.sidebar').classList.toggle('is-open'));
$('btn-logout').addEventListener('click', async () => {
  await fetch('../api.php?action=logout', { method: 'POST' });
  location.reload();
});

function addAction(label, onClick, cls = 'btn--primary') {
  const btn = el('button', 'btn ' + cls, label);
  btn.addEventListener('click', onClick);
  $('view-actions').appendChild(btn);
  return btn;
}

/* ------------------------------------------------------------- Dashboard */
views.dashboard = (host) => {
  const c = state.content;
  const cards = el('div', 'cards');
  const entries = [
    ['Maps', c.maps.length, c.maps.filter((m) => m.active).length + ' aktiv'],
    ['Gegner', c.enemies.length, c.enemies.filter((e) => e.boss).length + ' Boss'],
    ['Waffen', c.weapons.length, c.weapons.filter((w) => w.active).length + ' aktiv'],
    ['Upgrades', c.upgrades.length, c.upgrades.filter((u) => u.active).length + ' aktiv'],
  ];
  for (const [label, value, sub] of entries) {
    const card = el('div', 'card stat');
    card.appendChild(el('div', 'stat__label', label));
    card.appendChild(el('div', 'stat__value', String(value)));
    card.appendChild(el('div', 'muted', sub));
    cards.appendChild(card);
  }
  host.appendChild(cards);

  const info = el('div', 'card');
  info.appendChild(el('h3', null, 'Kurzübersicht'));
  const list = el('div', 'form');
  list.appendChild(el('p', 'muted',
    `Wellendauer ${c.balance.waveDuration}s · Bossdauer ${c.balance.bossDuration}s · ` +
    `max. ${c.balance.maxEnemies} Gegner · Lebensskalierung ×${c.balance.healthScaling} pro Zyklus.`));
  list.appendChild(el('p', 'muted',
    `Spieler: ${c.player.maxHealth} HP, Tempo ${c.player.moveSpeed}, ` +
    `${c.player.critChance}% Krit.`));
  info.appendChild(list);
  host.appendChild(info);

  const health = el('div', 'card');
  health.appendChild(el('h3', null, 'Server'));
  health.appendChild(el('p', 'muted',
    'Uploadgrenze: ' + state.uploadLimit + '. Ist das weniger als deine grösste Datei, '
    + 'muss upload_max_filesize im Hosting-Panel erhöht werden - viele FTP-Programme '
    + 'überspringen die mitgelieferte .user.ini und .htaccess, weil es Punkt-Dateien sind. '
    + 'Die Spieldaten schützen sich unabhängig davon selbst.'));
  host.appendChild(health);

  const actions = el('div', 'card');
  actions.appendChild(el('h3', null, 'Wartung'));
  const row = el('div', 'editor__tools');
  const resetBtn = el('button', 'btn btn--danger', 'Alles auf Standard zurücksetzen');
  resetBtn.addEventListener('click', () => confirmDialog(
    'Setzt Maps, Gegner, Waffen, Upgrades und Balancing auf die Startwerte zurück. Hochgeladene Dateien bleiben erhalten.',
    async () => {
      try {
        await api('reset', { section: 'all' });
        toast('Zuruckgesetzt');
        setView(state.view);
      } catch (e) { toast(e.message, 'error'); }
    },
  ));
  row.appendChild(resetBtn);
  actions.appendChild(row);
  host.appendChild(actions);
};

/* ------------------------------------------------------------------ Maps */
views.maps = (host) => {
  addAction('Neue Map', () => newMapFlow());

  const wrap = el('div', 'tablewrap');
  const table = el('table', 'table');
  table.innerHTML = `<thead><tr>
    <th class="keep">Vorschau</th><th class="keep">Name</th><th>Auflösung</th>
    <th>Collision</th><th>Erstellt</th><th class="keep">Status</th><th class="keep"></th>
  </tr></thead>`;
  const body = el('tbody');

  for (const map of state.content.maps) {
    const tr = el('tr');
    const preview = el('td', 'keep');
    const img = el('img', 'thumb thumb--map');
    img.src = '../' + map.image;
    img.alt = '';
    preview.appendChild(img);
    tr.appendChild(preview);

    tr.appendChild(el('td', 'keep', map.name));
    tr.appendChild(el('td', null, `${map.width} × ${map.height}`));

    const painted = countMask(map.collision);
    tr.appendChild(el('td', null, painted > 0 ? `${painted} Zellen` : 'keine'));
    tr.appendChild(el('td', null, new Date((map.createdAt || 0) * 1000).toLocaleDateString('de-DE')));

    const status = el('td', 'keep');
    status.appendChild(el('span', 'badge ' + (map.active ? 'badge--on' : 'badge--off'), map.active ? 'aktiv' : 'inaktiv'));
    tr.appendChild(status);

    const actions = el('td', 'actions keep');
    actions.appendChild(actionBtn('Vorschau', () => window.open('../' + map.image, '_blank', 'noopener')));
    actions.appendChild(actionBtn('Collision', () => openCollisionEditor(map)));
    actions.appendChild(actionBtn('Bearbeiten', () => editMap(map)));
    actions.appendChild(actionBtn('Löschen', () => confirmDialog(
      `Map "${map.name}" wirklich löschen?`,
      async () => {
        try {
          await api('delete', { section: 'maps', id: map.id });
          toast('Map gelöscht');
          setView('maps');
        } catch (e) { toast(e.message, 'error'); }
      },
    ), 'btn--danger'));
    tr.appendChild(actions);
    body.appendChild(tr);
  }

  table.appendChild(body);
  wrap.appendChild(table);
  host.appendChild(wrap);
};

function actionBtn(label, onClick, cls = 'btn--ghost') {
  const btn = el('button', 'btn btn--sm ' + cls, label);
  btn.style.marginLeft = '6px';
  btn.addEventListener('click', onClick);
  return btn;
}

function countMask(collision) {
  if (!collision || !collision.data) return 0;
  try {
    const bin = atob(collision.data);
    let count = 0;
    for (let i = 0; i < bin.length; i++) {
      let b = bin.charCodeAt(i);
      while (b) { count += b & 1; b >>= 1; }
    }
    return count;
  } catch {
    return 0;
  }
}

function newMapFlow() {
  const body = el('div', 'form');
  const drop = el('label', 'filedrop');
  drop.textContent = 'Kartenbild wählen (PNG, GIF, JPG, WEBP - max. 12 MB)';
  const input = el('input');
  input.type = 'file';
  input.accept = 'image/png,image/gif,image/jpeg,image/webp';
  drop.appendChild(input);
  const nameInput = textInput('Neue Welt', { placeholder: 'Name der Welt' });
  const preview = el('div', 'preview');
  body.appendChild(field('Name', nameInput));
  body.appendChild(drop);
  body.appendChild(preview);

  let uploaded = null;
  input.addEventListener('change', async () => {
    if (!input.files.length) return;
    drop.textContent = 'Lädt hoch ...';
    try {
      uploaded = await uploadFile(input.files[0], nameInput.value);
      preview.textContent = '';
      const img = el('img');
      img.src = '../' + uploaded.path;
      preview.appendChild(img);
      preview.appendChild(el('span', 'muted', `${uploaded.width} × ${uploaded.height}`));
      drop.textContent = 'Anderes Bild wählen';
    } catch (err) {
      toast(err.message, 'error');
      drop.textContent = 'Kartenbild wählen';
    }
    drop.appendChild(input);
  });

  openModal('Neue Map', body, [
    { label: 'Abbrechen', class: 'btn--ghost', onClick: (close) => close() },
    {
      label: 'Anlegen',
      class: 'btn--primary',
      onClick: async (close) => {
        if (!uploaded) return toast('Bitte zuerst ein Bild hochladen.', 'error');
        const map = {
          id: '', name: nameInput.value || 'Neue Welt', image: uploaded.path,
          width: uploaded.width, height: uploaded.height, active: true,
          spawn: { x: uploaded.width / 2, y: uploaded.height / 2 },
          collision: { cols: 128, rows: 128, data: '' },
          enemySpawnAreas: [],
        };
        try {
          const data = await api('put', { section: 'maps', item: map });
          close();
          toast('Map angelegt - jetzt Hindernisse malen');
          setView('maps');
          openCollisionEditor(data.item);
        } catch (e) { toast(e.message, 'error'); }
      },
    },
  ]);
}

function editMap(map) {
  const body = el('div', 'form');
  const name = textInput(map.name);
  const active = checkInput('Map ist im Spiel auswählbar', map.active);
  const spawnX = textInput(Math.round(map.spawn.x), { type: 'number' });
  const spawnY = textInput(Math.round(map.spawn.y), { type: 'number' });

  body.appendChild(field('Name', name));
  const grid = el('div', 'grid2');
  grid.appendChild(field('Spawn X', spawnX));
  grid.appendChild(field('Spawn Y', spawnY));
  body.appendChild(grid);
  body.appendChild(active);
  body.appendChild(el('p', 'muted', `Bild: ${map.image} (${map.width} × ${map.height})`));

  openModal('Map bearbeiten', body, [
    { label: 'Abbrechen', class: 'btn--ghost', onClick: (close) => close() },
    {
      label: 'Speichern',
      class: 'btn--primary',
      onClick: async (close) => {
        try {
          await api('put', {
            section: 'maps',
            item: { ...map, name: name.value, active: active.input.checked,
                    spawn: { x: +spawnX.value, y: +spawnY.value } },
          });
          close();
          toast('Gespeichert');
          setView('maps');
        } catch (e) { toast(e.message, 'error'); }
      },
    },
  ]);
}

/* --------------------------------------------------- Collision-Editor */
/**
 * Malt Hindernisse auf eine Karte.
 *
 * Gespeichert wird ein Bitraster in Kartenkoordinaten (Standard 128x128),
 * nicht in Bildschirmpixeln - dadurch passt die Maske unabhängig von
 * Zoom, Gerät und Auflösung.
 */
function openCollisionEditor(map) {
  const cols = map.collision?.cols || 128;
  const rows = map.collision?.rows || 128;
  const bits = decodeMask(map.collision?.data, cols * rows);
  const cellW = map.width / cols;
  const cellH = map.height / rows;

  let tool = 'brush';
  let brush = 3;
  let scale = 1;
  let panX = 0;
  let panY = 0;
  let spawn = { ...map.spawn };
  let zones = (map.enemySpawnAreas || []).map((z) => ({ ...z }));
  const undo = [];
  const redo = [];

  const root = el('div', 'editor');

  /* Werkzeugleiste */
  const tools = el('div', 'editor__tools');
  const brushBtn = el('button', 'btn btn--sm is-active', 'Malen');
  const eraseBtn = el('button', 'btn btn--sm', 'Radieren');
  const panBtn = el('button', 'btn btn--sm', 'Verschieben');
  const spawnBtn = el('button', 'btn btn--sm', 'Startpunkt');
  const zoneBtn = el('button', 'btn btn--sm', 'Gegnerzone');
  const zoneClearBtn = el('button', 'btn btn--sm', 'Zonen leeren');
  const undoBtn = el('button', 'btn btn--sm', 'Rückgängig');
  const redoBtn = el('button', 'btn btn--sm', 'Wiederholen');
  const clearBtn = el('button', 'btn btn--sm btn--danger', 'Alles löschen');
  const fitBtn = el('button', 'btn btn--sm', 'Einpassen');

  const sizeWrap = el('label', 'slider');
  const sizeInput = el('input');
  sizeInput.type = 'range';
  sizeInput.min = '1';
  sizeInput.max = '14';
  sizeInput.value = String(brush);
  const sizeLabel = el('span', null, 'Pinsel ' + brush);
  sizeWrap.appendChild(sizeLabel);
  sizeWrap.appendChild(sizeInput);

  const zoomWrap = el('label', 'slider');
  const zoomInput = el('input');
  zoomInput.type = 'range';
  zoomInput.min = '20';
  zoomInput.max = '400';
  zoomInput.value = '100';
  zoomWrap.appendChild(el('span', null, 'Zoom'));
  zoomWrap.appendChild(zoomInput);

  for (const b of [brushBtn, eraseBtn, panBtn, spawnBtn, zoneBtn, undoBtn, redoBtn, clearBtn, zoneClearBtn, fitBtn]) {
    tools.appendChild(b);
  }
  tools.appendChild(sizeWrap);
  tools.appendChild(zoomWrap);
  root.appendChild(tools);

  /* Bühne */
  const stage = el('div', 'editor__stage');
  const wrap = el('div');
  wrap.style.position = 'absolute';
  wrap.style.transformOrigin = '0 0';
  const img = el('img');
  img.src = '../' + map.image;
  img.style.width = map.width + 'px';
  img.style.height = map.height + 'px';
  img.style.display = 'block';
  img.style.imageRendering = 'pixelated';
  img.draggable = false;

  const maskCanvas = el('canvas');
  maskCanvas.width = cols;
  maskCanvas.height = rows;
  maskCanvas.style.position = 'absolute';
  maskCanvas.style.left = '0';
  maskCanvas.style.top = '0';
  maskCanvas.style.width = map.width + 'px';
  maskCanvas.style.height = map.height + 'px';
  maskCanvas.style.opacity = '0.5';
  maskCanvas.style.imageRendering = 'pixelated';
  maskCanvas.style.pointerEvents = 'none';
  const mctx = maskCanvas.getContext('2d');

  const marker = el('div');
  marker.style.cssText = 'position:absolute;width:26px;height:26px;margin:-13px 0 0 -13px;border-radius:50%;'
    + 'border:3px solid #43d39e;box-shadow:0 0 0 3px rgba(0,0,0,.5);pointer-events:none;';
  const zoneLayer = el('div');
  zoneLayer.style.cssText = 'position:absolute;left:0;top:0;pointer-events:none;';
  wrap.appendChild(img);
  wrap.appendChild(maskCanvas);
  wrap.appendChild(zoneLayer);
  wrap.appendChild(marker);
  stage.appendChild(wrap);
  root.appendChild(stage);
  root.appendChild(el('p', 'editor__hint',
    'Ein Finger malt, zwei Finger verschieben und zoomen. Rote Flächen sind im Spiel blockiert und dort unsichtbar. '
    + 'Der gruene Kreis ist der Startpunkt, blaue Kreise sind optionale Gegner-Spawnzonen (ohne Zonen spawnen Gegner rund um den Bildausschnitt).'));

  /* Zeichnen */
  function redrawMask() {
    const image = mctx.createImageData(cols, rows);
    const data = image.data;
    for (let i = 0; i < cols * rows; i++) {
      const on = (bits[i >> 3] & (1 << (i & 7))) !== 0;
      const o = i * 4;
      data[o] = 255;
      data[o + 1] = 60;
      data[o + 2] = 80;
      data[o + 3] = on ? 210 : 0;
    }
    mctx.putImageData(image, 0, 0);
  }

  function drawZones() {
    zoneLayer.textContent = '';
    for (const zone of zones) {
      const dot = el('div');
      dot.style.cssText = `position:absolute;left:${zone.x - zone.r}px;top:${zone.y - zone.r}px;`
        + `width:${zone.r * 2}px;height:${zone.r * 2}px;border-radius:50%;`
        + 'border:2px dashed rgba(108,140,255,.9);background:rgba(108,140,255,.14);';
      zoneLayer.appendChild(dot);
    }
  }

  function applyTransform() {
    wrap.style.transform = `translate(${panX}px, ${panY}px) scale(${scale})`;
    marker.style.left = spawn.x + 'px';
    marker.style.top = spawn.y + 'px';
    marker.style.transform = `scale(${1 / scale})`;
  }

  function fit() {
    const rect = stage.getBoundingClientRect();
    scale = Math.min(rect.width / map.width, rect.height / map.height);
    panX = (rect.width - map.width * scale) / 2;
    panY = (rect.height - map.height * scale) / 2;
    zoomInput.value = String(Math.round(scale * 100));
    applyTransform();
  }

  function snapshot() {
    undo.push(bits.slice());
    if (undo.length > 40) undo.shift();
    redo.length = 0;
  }

  function setCell(cx, cy, on) {
    if (cx < 0 || cy < 0 || cx >= cols || cy >= rows) return;
    const index = cy * cols + cx;
    if (on) bits[index >> 3] |= 1 << (index & 7);
    else bits[index >> 3] &= ~(1 << (index & 7));
  }

  function paintAt(clientX, clientY, on) {
    const rect = stage.getBoundingClientRect();
    const localX = (clientX - rect.left - panX) / scale;
    const localY = (clientY - rect.top - panY) / scale;
    const cx = Math.floor(localX / cellW);
    const cy = Math.floor(localY / cellH);
    const r = Math.max(0, brush - 1);
    for (let y = cy - r; y <= cy + r; y++) {
      for (let x = cx - r; x <= cx + r; x++) {
        if ((x - cx) * (x - cx) + (y - cy) * (y - cy) <= r * r + r) setCell(x, y, on);
      }
    }
    redrawMask();
  }

  function setSpawnAt(clientX, clientY) {
    const rect = stage.getBoundingClientRect();
    spawn = {
      x: Math.max(0, Math.min(map.width, (clientX - rect.left - panX) / scale)),
      y: Math.max(0, Math.min(map.height, (clientY - rect.top - panY) / scale)),
    };
    applyTransform();
  }

  /* Eingabe: ein Zeiger malt, zwei Zeiger verschieben und zoomen */
  const pointers = new Map();
  let pinchStart = null;
  let painting = false;

  stage.addEventListener('pointerdown', (e) => {
    stage.setPointerCapture(e.pointerId);
    pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });

    if (pointers.size === 2) {
      painting = false;
      const [a, b] = [...pointers.values()];
      pinchStart = {
        dist: Math.hypot(a.x - b.x, a.y - b.y),
        scale,
        cx: (a.x + b.x) / 2,
        cy: (a.y + b.y) / 2,
        panX, panY,
      };
      return;
    }
    if (pointers.size > 2) return;

    if (tool === 'spawn') {
      setSpawnAt(e.clientX, e.clientY);
      return;
    }
    if (tool === 'zone') {
      const rect = stage.getBoundingClientRect();
      zones.push({
        x: (e.clientX - rect.left - panX) / scale,
        y: (e.clientY - rect.top - panY) / scale,
        r: Math.max(60, brush * cellW * 4),
      });
      drawZones();
      return;
    }
    if (tool === 'pan') {
      pinchStart = { pan: true, x: e.clientX, y: e.clientY, panX, panY };
      return;
    }
    snapshot();
    painting = true;
    paintAt(e.clientX, e.clientY, tool === 'brush');
  });

  stage.addEventListener('pointermove', (e) => {
    if (!pointers.has(e.pointerId)) return;
    pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });

    if (pointers.size === 2 && pinchStart && !pinchStart.pan) {
      const [a, b] = [...pointers.values()];
      const dist = Math.hypot(a.x - b.x, a.y - b.y);
      const next = Math.max(0.15, Math.min(6, pinchStart.scale * (dist / pinchStart.dist)));
      const cx = (a.x + b.x) / 2;
      const cy = (a.y + b.y) / 2;
      const rect = stage.getBoundingClientRect();
      // Um den Fingermittelpunkt zoomen.
      const worldX = (pinchStart.cx - rect.left - pinchStart.panX) / pinchStart.scale;
      const worldY = (pinchStart.cy - rect.top - pinchStart.panY) / pinchStart.scale;
      scale = next;
      panX = cx - rect.left - worldX * scale;
      panY = cy - rect.top - worldY * scale;
      zoomInput.value = String(Math.round(scale * 100));
      applyTransform();
      return;
    }

    if (pinchStart && pinchStart.pan) {
      panX = pinchStart.panX + (e.clientX - pinchStart.x);
      panY = pinchStart.panY + (e.clientY - pinchStart.y);
      applyTransform();
      return;
    }

    if (painting) paintAt(e.clientX, e.clientY, tool === 'brush');
  });

  const endPointer = (e) => {
    pointers.delete(e.pointerId);
    if (pointers.size < 2) pinchStart = null;
    if (pointers.size === 0) painting = false;
  };
  stage.addEventListener('pointerup', endPointer);
  stage.addEventListener('pointercancel', endPointer);
  stage.addEventListener('wheel', (e) => {
    e.preventDefault();
    const rect = stage.getBoundingClientRect();
    const worldX = (e.clientX - rect.left - panX) / scale;
    const worldY = (e.clientY - rect.top - panY) / scale;
    scale = Math.max(0.15, Math.min(6, scale * (e.deltaY < 0 ? 1.12 : 0.89)));
    panX = e.clientX - rect.left - worldX * scale;
    panY = e.clientY - rect.top - worldY * scale;
    zoomInput.value = String(Math.round(scale * 100));
    applyTransform();
  }, { passive: false });

  /* Werkzeuge verdrahten */
  function setTool(next, button) {
    tool = next;
      [brushBtn, eraseBtn, panBtn, spawnBtn, zoneBtn].forEach((b) => b.classList.toggle('is-active', b === button));
  }
  brushBtn.addEventListener('click', () => setTool('brush', brushBtn));
  eraseBtn.addEventListener('click', () => setTool('erase', eraseBtn));
  panBtn.addEventListener('click', () => setTool('pan', panBtn));
  spawnBtn.addEventListener('click', () => setTool('spawn', spawnBtn));
  zoneBtn.addEventListener('click', () => setTool('zone', zoneBtn));
  zoneClearBtn.addEventListener('click', () => {
    zones = [];
    drawZones();
  });
  sizeInput.addEventListener('input', () => {
    brush = +sizeInput.value;
    sizeLabel.textContent = 'Pinsel ' + brush;
  });
  zoomInput.addEventListener('input', () => {
    const rect = stage.getBoundingClientRect();
    const worldX = (rect.width / 2 - panX) / scale;
    const worldY = (rect.height / 2 - panY) / scale;
    scale = +zoomInput.value / 100;
    panX = rect.width / 2 - worldX * scale;
    panY = rect.height / 2 - worldY * scale;
    applyTransform();
  });
  undoBtn.addEventListener('click', () => {
    if (!undo.length) return;
    redo.push(bits.slice());
    bits.set(undo.pop());
    redrawMask();
  });
  redoBtn.addEventListener('click', () => {
    if (!redo.length) return;
    undo.push(bits.slice());
    bits.set(redo.pop());
    redrawMask();
  });
  clearBtn.addEventListener('click', () => {
    snapshot();
    bits.fill(0);
    redrawMask();
  });
  fitBtn.addEventListener('click', fit);

  redrawMask();
  drawZones();
  openModal('Hindernisse malen - ' + map.name, root, [
    { label: 'Abbrechen', class: 'btn--ghost', onClick: (close) => close() },
    {
      label: 'Speichern',
      class: 'btn--primary',
      onClick: async (close) => {
        try {
          await api('put', {
            section: 'maps',
            item: { ...map, spawn, enemySpawnAreas: zones, collision: { cols, rows, data: encodeMask(bits) } },
          });
          close();
          toast('Collision gespeichert');
          setView('maps');
        } catch (e) { toast(e.message, 'error'); }
      },
    },
  ], true);

  requestAnimationFrame(fit);
}

function decodeMask(base64, cellCount) {
  const bytes = new Uint8Array(Math.ceil(cellCount / 8));
  if (!base64) return bytes;
  try {
    const bin = atob(base64);
    for (let i = 0; i < bytes.length && i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
  } catch { /* leere Maske */ }
  return bytes;
}

function encodeMask(bits) {
  let bin = '';
  for (let i = 0; i < bits.length; i++) bin += String.fromCharCode(bits[i]);
  return btoa(bin);
}

/* ---------------------------------------------------------------- Gegner */
views.enemies = (host) => {
  addAction('Neuer Gegner', () => editEnemy(null));

  const wrap = el('div', 'tablewrap');
  const table = el('table', 'table');
  table.innerHTML = `<thead><tr>
    <th class="keep">Sprite</th><th class="keep">Name</th><th>Leben</th><th>Schaden</th>
    <th>Tempo</th><th>Geld</th><th>Welle</th><th class="keep">Typ</th><th class="keep"></th>
  </tr></thead>`;
  const body = el('tbody');

  for (const enemy of state.content.enemies) {
    const tr = el('tr');
    const spriteCell = el('td', 'keep');
    const img = el('img', 'thumb');
    img.src = '../' + enemy.sprite;
    img.alt = '';
    spriteCell.appendChild(img);
    tr.appendChild(spriteCell);
    tr.appendChild(el('td', 'keep', enemy.name));
    tr.appendChild(el('td', null, String(Math.round(enemy.health))));
    tr.appendChild(el('td', null, String(Math.round(enemy.damage))));
    tr.appendChild(el('td', null, String(Math.round(enemy.speed))));
    tr.appendChild(el('td', null, String(Math.round(enemy.reward))));
    tr.appendChild(el('td', null, enemy.wave ? 'Welle ' + enemy.wave : '-'));
    const type = el('td', 'keep');
    type.appendChild(el('span', 'badge ' + (enemy.boss ? 'badge--boss' : ''), enemy.boss ? 'Boss' : 'Standard'));
    tr.appendChild(type);

    const actions = el('td', 'actions keep');
    actions.appendChild(actionBtn('Bearbeiten', () => editEnemy(enemy)));
    actions.appendChild(actionBtn('Duplizieren', () => duplicate('enemies', enemy)));
    actions.appendChild(actionBtn('Löschen', () => removeItem('enemies', enemy, enemy.name), 'btn--danger'));
    tr.appendChild(actions);
    body.appendChild(tr);
  }
  table.appendChild(body);
  wrap.appendChild(table);
  host.appendChild(wrap);
};

function editEnemy(enemy) {
  const isNew = !enemy;
  const data = enemy ? JSON.parse(JSON.stringify(enemy)) : {
    id: '', name: 'Neuer Gegner', sprite: 'assets/sprites/enemy1.gif',
    health: 40, damage: 8, speed: 70, reward: 4, spawnWeight: 100, boss: false,
    contactCooldown: 0.8, scale: 64, wave: 0,
    soundHit: { enabled: true, chance: 40, volume: 0.35, variants: [] },
    soundDeath: { enabled: true, chance: 100, volume: 0.5, variants: [] },
    hitbox: { shape: 'circle', r: 20, w: 40, h: 40, ox: 0, oy: 0 },
  };

  const body = el('div', 'form');
  const name = textInput(data.name);
  body.appendChild(field('Name', name));

  let spritePath = data.sprite;
  const hitboxHost = el('div');
  body.appendChild(spriteField('Sprite / GIF', data.sprite, (path) => {
    spritePath = path;
    renderHitboxEditor();
  }));

  const grid = el('div', 'grid2');
  const health = textInput(data.health, { type: 'number', min: 1, step: 1 });
  const damage = textInput(data.damage, { type: 'number', min: 0, step: 1 });
  const speed = textInput(data.speed, { type: 'number', min: 0, step: 1 });
  const reward = textInput(data.reward, { type: 'number', min: 0, step: 1 });
  const weight = textInput(data.spawnWeight, { type: 'number', min: 0, step: 5 });
  const cooldown = textInput(data.contactCooldown, { type: 'number', min: 0.05, step: 0.05 });
  const scale = textInput(data.scale, { type: 'number', min: 8, step: 2 });
  const wave = selectInput(data.wave, [
    { value: 0, label: 'Alle Wellen' },
    { value: 1, label: 'Welle 1' }, { value: 2, label: 'Welle 2' },
    { value: 3, label: 'Welle 3' }, { value: 4, label: 'Bosswelle' },
  ]);
  grid.appendChild(field('Basisleben', health));
  grid.appendChild(field('Basisschaden', damage));
  grid.appendChild(field('Tempo (px/s)', speed));
  grid.appendChild(field('Geld beim Tod', reward));
  grid.appendChild(field('Spawn-Gewicht', weight, '0 = spawnt nicht zufällig'));
  grid.appendChild(field('Kontaktschaden-Pause (s)', cooldown));
  grid.appendChild(field('Sprite-Höhe (px)', scale));
  grid.appendChild(field('Bevorzugte Welle', wave));
  body.appendChild(grid);

  const boss = checkInput('Ist ein Boss (erscheint in Welle 4)', data.boss);
  body.appendChild(boss);

  body.appendChild(el('h3', null, 'Töne'));
  const hitSound = soundSetEditor('Treffer', data.soundHit, { compact: true });
  const deathSound = soundSetEditor('Tod', data.soundDeath, { compact: true });
  body.appendChild(hitSound);
  body.appendChild(deathSound);

  body.appendChild(el('h3', null, 'Hitbox'));
  body.appendChild(hitboxHost);

  /* Hitbox-Editor: Kreis oder Rechteck, direkt auf dem Sprite. */
  function renderHitboxEditor() {
    hitboxHost.textContent = '';
    const shapeSel = selectInput(data.hitbox.shape, [
      { value: 'circle', label: 'Kreis' },
      { value: 'rect', label: 'Rechteck' },
    ]);
    const size = textInput(data.hitbox.shape === 'rect' ? data.hitbox.w : data.hitbox.r,
      { type: 'number', min: 2, step: 1 });
    const sizeH = textInput(data.hitbox.h, { type: 'number', min: 2, step: 1 });
    const offX = textInput(data.hitbox.ox, { type: 'number', step: 1 });
    const offY = textInput(data.hitbox.oy, { type: 'number', step: 1 });

    const stage = el('div', 'hitbox-stage');
    const canvas = el('canvas');
    canvas.width = 260;
    canvas.height = 260;
    stage.appendChild(canvas);
    const ctx = canvas.getContext('2d');
    const image = new Image();
    image.src = '../' + spritePath;

    function draw() {
      ctx.clearRect(0, 0, canvas.width, canvas.height);
      ctx.imageSmoothingEnabled = false;
      const height = +scale.value || 64;
      let w = height;
      let h = height;
      if (image.naturalWidth) {
        w = (image.naturalWidth / image.naturalHeight) * height;
        h = height;
      }
      const cx = canvas.width / 2;
      const groundY = canvas.height / 2 + h / 2;
      if (image.complete && image.naturalWidth) ctx.drawImage(image, cx - w / 2, groundY - h, w, h);

      ctx.strokeStyle = '#43d39e';
      ctx.lineWidth = 2;
      const ox = +offX.value || 0;
      const oy = +offY.value || 0;
      ctx.beginPath();
      if (shapeSel.value === 'rect') {
        ctx.rect(cx + ox - (+size.value) / 2, canvas.height / 2 + oy - (+sizeH.value) / 2, +size.value, +sizeH.value);
      } else {
        ctx.arc(cx + ox, canvas.height / 2 + oy, +size.value || 2, 0, Math.PI * 2);
      }
      ctx.stroke();
      ctx.fillStyle = 'rgba(67,211,158,0.16)';
      ctx.fill();
    }

    image.onload = draw;
    [shapeSel, size, sizeH, offX, offY, scale].forEach((i) => i.addEventListener('input', () => {
      hRow.querySelector('[data-h]').hidden = shapeSel.value !== 'rect';
      draw();
    }));

    // Ziehen verschiebt den Mittelpunkt, damit man die Hitbox direkt setzen kann.
    let dragging = false;
    const toLocal = (e) => {
      const rect = canvas.getBoundingClientRect();
      return {
        x: (e.clientX - rect.left) * (canvas.width / rect.width) - canvas.width / 2,
        y: (e.clientY - rect.top) * (canvas.height / rect.height) - canvas.height / 2,
      };
    };
    canvas.addEventListener('pointerdown', (e) => {
      dragging = true;
      canvas.setPointerCapture(e.pointerId);
      const p = toLocal(e);
      offX.value = Math.round(p.x);
      offY.value = Math.round(p.y);
      draw();
    });
    canvas.addEventListener('pointermove', (e) => {
      if (!dragging) return;
      const p = toLocal(e);
      offX.value = Math.round(p.x);
      offY.value = Math.round(p.y);
      draw();
    });
    canvas.addEventListener('pointerup', () => { dragging = false; });

    const hRow = el('div', 'grid2');
    hRow.appendChild(field('Form', shapeSel));
    hRow.appendChild(field(shapeSel.value === 'rect' ? 'Breite' : 'Radius', size));
    const hField = field('Höhe', sizeH);
    hField.dataset.h = '1';
    hField.hidden = data.hitbox.shape !== 'rect';
    hRow.appendChild(hField);
    hRow.appendChild(field('Versatz X', offX));
    hRow.appendChild(field('Versatz Y', offY));

    hitboxHost.appendChild(stage);
    hitboxHost.appendChild(hRow);
    hitboxHost.appendChild(el('p', 'muted', 'Ziehen setzt den Mittelpunkt. Die Hitbox wird relativ zum Sprite gespeichert.'));
    hitboxHost._read = () => ({
      shape: shapeSel.value,
      r: shapeSel.value === 'circle' ? +size.value : +size.value / 2,
      w: +size.value,
      h: +sizeH.value,
      ox: +offX.value,
      oy: +offY.value,
    });
    draw();
  }
  renderHitboxEditor();

  openModal(isNew ? 'Gegner anlegen' : 'Gegner bearbeiten', body, [
    { label: 'Abbrechen', class: 'btn--ghost', onClick: (close) => close() },
    {
      label: 'Speichern',
      class: 'btn--primary',
      onClick: async (close) => {
        if (+health.value <= 0) return toast('Leben muss größer als 0 sein.', 'error');
        if (!spritePath) return toast('Ein Gegner braucht ein Sprite.', 'error');
        try {
          await api('put', {
            section: 'enemies',
            item: {
              ...data,
              id: data.id || slug(name.value),
              name: name.value,
              sprite: spritePath,
              health: +health.value, damage: +damage.value, speed: +speed.value,
              reward: +reward.value, spawnWeight: +weight.value, boss: boss.input.checked,
              contactCooldown: +cooldown.value, scale: +scale.value, wave: +wave.value,
              soundHit: hitSound.read(), soundDeath: deathSound.read(),
              hitbox: hitboxHost._read(),
            },
          });
          close();
          toast('Gespeichert');
          setView('enemies');
        } catch (e) { toast(e.message, 'error'); }
      },
    },
  ]);
}

/* ---------------------------------------------------------------- Waffen */
views.weapons = (host) => {
  addAction('Neue Waffe', () => editWeapon(null));

  const wrap = el('div', 'tablewrap');
  const table = el('table', 'table');
  table.innerHTML = `<thead><tr>
    <th class="keep">Sprite</th><th class="keep">Name</th><th>Typ</th><th>Schaden</th>
    <th>Cooldown</th><th>Reichweite</th><th>Größe</th><th class="keep">Status</th><th class="keep"></th>
  </tr></thead>`;
  const body = el('tbody');

  for (const weapon of state.content.weapons) {
    const tr = el('tr');
    const spriteCell = el('td', 'keep');
    const img = el('img', 'thumb');
    img.src = '../' + weapon.sprite;
    img.alt = '';
    spriteCell.appendChild(img);
    tr.appendChild(spriteCell);
    tr.appendChild(el('td', 'keep', weapon.name + (weapon.starter ? ' ★' : '')));
    tr.appendChild(el('td', null, weapon.type));
    tr.appendChild(el('td', null, String(Math.round(weapon.damage))));
    tr.appendChild(el('td', null, weapon.cooldown.toFixed(2) + ' s'));
    tr.appendChild(el('td', null, String(Math.round(weapon.range))));
    tr.appendChild(el('td', null, `${Math.round(weapon.spriteScale || 46)} / ${Math.round(weapon.projectileSize || 16)} px`));
    const status = el('td', 'keep');
    status.appendChild(el('span', 'badge ' + (weapon.active ? 'badge--on' : 'badge--off'), weapon.active ? 'aktiv' : 'aus'));
    tr.appendChild(status);
    const actions = el('td', 'actions keep');
    actions.appendChild(actionBtn('Bearbeiten', () => editWeapon(weapon)));
    actions.appendChild(actionBtn('Duplizieren', () => duplicate('weapons', weapon)));
    actions.appendChild(actionBtn('Löschen', () => removeItem('weapons', weapon, weapon.name), 'btn--danger'));
    tr.appendChild(actions);
    body.appendChild(tr);
  }
  table.appendChild(body);
  wrap.appendChild(table);
  host.appendChild(wrap);
};

function editWeapon(weapon) {
  const isNew = !weapon;
  const data = weapon ? { ...weapon } : {
    id: '', name: 'Neue Waffe', sprite: 'assets/sprites/pistole.png', type: 'PROJECTILE',
    projectile: 'schuss', damage: 20, cooldown: 0.5, range: 400, projectileSpeed: 600,
    knockback: 80, critChance: 8, critDamage: 60, aoeRadius: 0, arc: 360, pierce: 0,
    spread: 0, recoil: 6, spriteScale: 46, projectileSize: 16,
    holdOffsetY: -6, holdDistance: 20,
    sound: { enabled: true, chance: 100, volume: 0.6, variants: [] },
    description: '', active: true, starter: false, damageType: 'physical',
  };

  const body = el('div', 'form');
  const name = textInput(data.name);
  const desc = el('textarea');
  desc.className = 'input';
  desc.value = data.description || '';
  let spritePath = data.sprite;

  body.appendChild(field('Name', name));
  body.appendChild(spriteField('Sprite', data.sprite, (p) => {
    spritePath = p;
    if (typeof weaponImg !== 'undefined') weaponImg.src = '../' + p;
  }));

  const type = selectInput(data.type, [
    { value: 'PROJECTILE', label: 'PROJECTILE - Schuss' },
    { value: 'MAGIC', label: 'MAGIC - Zielsuchendes Geschoss' },
    { value: 'MELEE_ARC', label: 'MELEE_ARC - Schwung vor dem Spieler' },
    { value: 'MELEE_360', label: 'MELEE_360 - volle Drehung' },
    { value: 'THRUST', label: 'THRUST - Stoß nach vorne' },
    { value: 'GRENADE', label: 'GRENADE - Wurf mit Explosion' },
  ]);
  const projectile = selectInput(data.projectile, [
    { value: '', label: '- keins (Nahkampf) -' },
    { value: 'schuss', label: 'schuss (Kugel)' },
    { value: 'pfeil', label: 'pfeil (gezeichnet)' },
    { value: 'magic', label: 'magic (Magiekugel)' },
    { value: 'granate', label: 'granate (Wurfobjekt)' },
  ]);

  const grid = el('div', 'grid2');
  const damage = textInput(data.damage, { type: 'number', min: 0.1, step: 1 });
  const cooldown = textInput(data.cooldown, { type: 'number', min: 0.03, step: 0.01 });
  const range = textInput(data.range, { type: 'number', min: 20, step: 10 });
  const pspeed = textInput(data.projectileSpeed, { type: 'number', min: 0, step: 20 });
  const knock = textInput(data.knockback, { type: 'number', min: 0, step: 10 });
  const critC = textInput(data.critChance, { type: 'number', min: 0, max: 100, step: 1 });
  const critD = textInput(data.critDamage, { type: 'number', min: 0, step: 5 });
  const aoe = textInput(data.aoeRadius, { type: 'number', min: 0, step: 10 });
  const arc = textInput(data.arc, { type: 'number', min: 5, max: 360, step: 5 });
  const pierce = textInput(data.pierce, { type: 'number', min: 0, max: 20, step: 1 });
  const spread = textInput(data.spread, { type: 'number', min: 0, max: 60, step: 1 });
  const recoil = textInput(data.recoil, { type: 'number', min: 0, max: 60, step: 1 });
  const spriteScale = textInput(data.spriteScale ?? 46, { type: 'number', min: 6, max: 400, step: 2 });
  const projectileSize = textInput(data.projectileSize ?? 16, { type: 'number', min: 3, max: 200, step: 1 });
  const holdOffsetY = textInput(data.holdOffsetY ?? -6, { type: 'number', min: -120, max: 120, step: 1 });
  const holdDistance = textInput(data.holdDistance ?? 20, { type: 'number', min: -60, max: 200, step: 1 });

  grid.appendChild(field('Typ', type));
  grid.appendChild(field('Projektil', projectile));
  grid.appendChild(field('Schaden', damage));
  grid.appendChild(field('Cooldown (s)', cooldown, 'kleiner = schneller'));
  grid.appendChild(field('Reichweite', range));
  grid.appendChild(field('Projektiltempo', pspeed));
  grid.appendChild(field('Rückstoß', knock));
  grid.appendChild(field('Krit. Chance (%)', critC));
  grid.appendChild(field('Krit. Schaden (%)', critD));
  grid.appendChild(field('AoE-Radius', aoe, 'nur für Granate / Explosionen'));
  grid.appendChild(field('Schwungwinkel', arc, 'nur MELEE_ARC'));
  grid.appendChild(field('Durchschläge', pierce));
  grid.appendChild(field('Streuung (Grad)', spread));
  grid.appendChild(field('Rückstoß-Animation', recoil));
  grid.appendChild(field('Waffensprite-Größe (px)', spriteScale, 'Länge in der Hand bzw. beim Schwung'));
  grid.appendChild(field('Projektil-Größe (px)', projectileSize, 'Höhe des Schusses / Pfeils'));
  grid.appendChild(field('Waffenhöhe (px)', holdOffsetY, 'negativ = höher, positiv = tiefer'));
  grid.appendChild(field('Abstand zum Körper (px)', holdDistance));
  body.appendChild(grid);

  // Live-Vorschau: zeigt Waffe und Projektil in echter Spielgröße.
  const previewCard = el('div', 'field');
  previewCard.appendChild(el('label', null, 'Vorschau (Spielgröße)'));
  const previewCanvas = el('canvas');
  previewCanvas.width = 420;
  previewCanvas.height = 130;
  previewCanvas.style.cssText = 'width:100%;max-width:420px;background:#0d1119;border-radius:10px;image-rendering:pixelated;';
  previewCard.appendChild(previewCanvas);
  body.appendChild(previewCard);

  const pctx = previewCanvas.getContext('2d');
  const weaponImg = new Image();
  const shotImg = new Image();
  shotImg.src = '../assets/sprites/schuss.png';

  function drawPreview() {
    pctx.clearRect(0, 0, previewCanvas.width, previewCanvas.height);
    pctx.imageSmoothingEnabled = false;
    // Maßstab: der Spieler ist 78 px hoch - als Größenvergleich daneben.
    pctx.fillStyle = 'rgba(255,255,255,.07)';
    pctx.fillRect(24, 130 - 78, 34, 78);
    pctx.fillStyle = '#8b95ad';
    pctx.font = '11px Inter, sans-serif';
    pctx.fillText('Spieler', 20, 128);

    const len = +spriteScale.value || 46;
    const offY = +holdOffsetY.value || 0;
    const dist = +holdDistance.value || 0;
    if (weaponImg.complete && weaponImg.naturalWidth) {
      const h = (weaponImg.naturalHeight / weaponImg.naturalWidth) * len;
      // Waffe relativ zur Spielerfigur (grauer Balken) zeichnen.
      pctx.drawImage(weaponImg, 41 + dist, 130 - 78 / 2 + offY - h / 2, len, h);
    }
    pctx.strokeStyle = 'rgba(108,140,255,.5)';
    pctx.beginPath();
    pctx.moveTo(24, 130 - 78 / 2);
    pctx.lineTo(400, 130 - 78 / 2);
    pctx.stroke();
    const size = +projectileSize.value || 16;
    if (type.value === 'PROJECTILE' && projectile.value === 'schuss' && shotImg.complete && shotImg.naturalWidth) {
      const w = (shotImg.naturalWidth / shotImg.naturalHeight) * size;
      pctx.drawImage(shotImg, 300, 65 - size / 2, w, size);
    } else if (projectile.value === 'pfeil') {
      const sc = size / 16;
      pctx.save();
      pctx.translate(310, 65);
      pctx.scale(sc, sc);
      pctx.fillStyle = '#d9c8a3';
      pctx.fillRect(-13, -1.5, 22, 3);
      pctx.fillStyle = '#e8eef7';
      pctx.beginPath();
      pctx.moveTo(15, 0); pctx.lineTo(7, -4.5); pctx.lineTo(7, 4.5); pctx.closePath(); pctx.fill();
      pctx.fillStyle = '#7fd6c2';
      pctx.fillRect(-14, -4, 4, 8);
      pctx.restore();
    } else if (projectile.value === 'magic') {
      pctx.fillStyle = '#8b7bff';
      pctx.beginPath(); pctx.arc(310, 65, size * 0.7, 0, Math.PI * 2); pctx.fill();
      pctx.fillStyle = '#d9d2ff';
      pctx.beginPath(); pctx.arc(310, 65, size * 0.34, 0, Math.PI * 2); pctx.fill();
    }
    pctx.fillStyle = '#8b95ad';
    pctx.fillText('Waffe ' + len + ' px', 110, 118);
    if (projectile.value) pctx.fillText('Projektil ' + size + ' px', 290, 118);
  }

  weaponImg.onload = drawPreview;
  shotImg.onload = drawPreview;
  weaponImg.src = '../' + spritePath;
  [spriteScale, projectileSize, holdOffsetY, holdDistance, type, projectile]
    .forEach((i) => i.addEventListener('input', drawPreview));
  drawPreview();
  body.appendChild(field('Beschreibung', desc));

  body.appendChild(el('h3', null, 'Angriffston'));
  const soundEditor = soundSetEditor('Ton beim Angriff', data.sound, { compact: true });
  body.appendChild(soundEditor);
  body.appendChild(el('p', 'muted',
    'Ohne eigene Datei nimmt das Spiel den allgemeinen Schuss- bzw. Schlagton.'));

  const active = checkInput('Waffe ist im Spiel verfügbar', data.active);
  const starter = checkInput('Als Starterwaffe anbieten', data.starter);
  body.appendChild(active);
  body.appendChild(starter);

  openModal(isNew ? 'Waffe anlegen' : 'Waffe bearbeiten', body, [
    { label: 'Abbrechen', class: 'btn--ghost', onClick: (close) => close() },
    {
      label: 'Speichern',
      class: 'btn--primary',
      onClick: async (close) => {
        if (+cooldown.value <= 0) return toast('Cooldown muss größer als 0 sein.', 'error');
        try {
          await api('put', {
            section: 'weapons',
            item: {
              ...data, id: data.id || slug(name.value), name: name.value, sprite: spritePath,
              type: type.value, projectile: projectile.value, damage: +damage.value,
              cooldown: +cooldown.value, range: +range.value, projectileSpeed: +pspeed.value,
              knockback: +knock.value, critChance: +critC.value, critDamage: +critD.value,
              aoeRadius: +aoe.value, arc: +arc.value, pierce: +pierce.value, spread: +spread.value,
              recoil: +recoil.value, sound: soundEditor.read(), spriteScale: +spriteScale.value,
              projectileSize: +projectileSize.value, holdOffsetY: +holdOffsetY.value,
              holdDistance: +holdDistance.value, description: desc.value,
              active: active.input.checked, starter: starter.input.checked,
            },
          });
          close();
          toast('Gespeichert');
          setView('weapons');
        } catch (e) { toast(e.message, 'error'); }
      },
    },
  ]);
}

/* -------------------------------------------------------------- Upgrades */
const STATS = [
  ['damage', 'Schaden'], ['attackSpeed', 'Angriffstempo'], ['moveSpeed', 'Bewegungstempo'],
  ['maxHealth', 'Max. Leben'], ['armor', 'Rüstung'], ['shield', 'Schild'],
  ['critChance', 'Krit. Chance'], ['critDamage', 'Krit. Schaden'],
  ['projectileSpeed', 'Projektiltempo'], ['range', 'Reichweite'],
  ['knockback', 'Rückstoß'], ['dodge', 'Ausweichen'], ['regen', 'Regeneration'],
];

views.upgrades = (host) => {
  addAction('Neues Upgrade', () => editUpgrade(null));

  const wrap = el('div', 'tablewrap');
  const table = el('table', 'table');
  table.innerHTML = `<thead><tr>
    <th class="keep">Name</th><th class="keep">Effekt</th><th>Stat</th><th>Typ</th>
    <th>Gewicht</th><th>Max. Stapel</th><th class="keep">Seltenheit</th><th class="keep"></th>
  </tr></thead>`;
  const body = el('tbody');

  for (const up of state.content.upgrades) {
    const tr = el('tr');
    tr.appendChild(el('td', 'keep', up.name));
    const sign = up.value >= 0 ? '+' : '';
    tr.appendChild(el('td', 'keep', sign + up.value + (up.modType === 'percent' ? '%' : '')));
    tr.appendChild(el('td', null, (STATS.find((s) => s[0] === up.stat) || [])[1] || up.stat));
    tr.appendChild(el('td', null, up.modType === 'percent' ? 'Prozent' : 'Flach'));
    tr.appendChild(el('td', null, String(up.weight)));
    tr.appendChild(el('td', null, String(up.maxStack)));
    const rar = el('td', 'keep');
    rar.appendChild(el('span', 'badge badge--' + up.rarity, up.rarity));
    tr.appendChild(rar);
    const actions = el('td', 'actions keep');
    actions.appendChild(actionBtn('Bearbeiten', () => editUpgrade(up)));
    actions.appendChild(actionBtn('Duplizieren', () => duplicate('upgrades', up)));
    actions.appendChild(actionBtn('Löschen', () => removeItem('upgrades', up, up.name), 'btn--danger'));
    tr.appendChild(actions);
    body.appendChild(tr);
  }
  table.appendChild(body);
  wrap.appendChild(table);
  host.appendChild(wrap);
};

function editUpgrade(up) {
  const isNew = !up;
  const data = up ? { ...up } : {
    id: '', name: 'Neues Upgrade', description: '', icon: '', stat: 'damage',
    modType: 'percent', value: 10, rarity: 'common', weight: 100, maxStack: 10, active: true,
  };

  const body = el('div', 'form');
  const name = textInput(data.name);
  const desc = el('textarea');
  desc.className = 'input';
  desc.value = data.description || '';
  const stat = selectInput(data.stat, STATS.map(([value, label]) => ({ value, label })));
  const modType = selectInput(data.modType, [
    { value: 'percent', label: 'Prozent (+10%)' },
    { value: 'flat', label: 'Flach (+10)' },
  ]);
  const value = textInput(data.value, { type: 'number', step: 1 });
  const rarity = selectInput(data.rarity, [
    { value: 'common', label: 'Common' }, { value: 'rare', label: 'Rare' },
    { value: 'epic', label: 'Epic' }, { value: 'legendary', label: 'Legendary' },
  ]);
  const weight = textInput(data.weight, { type: 'number', min: 0, step: 5 });
  const maxStack = textInput(data.maxStack, { type: 'number', min: 1, step: 1 });
  const active = checkInput('Upgrade kann gezogen werden', data.active);
  let iconPath = data.icon;

  body.appendChild(field('Name', name));
  body.appendChild(field('Beschreibung', desc));
  const grid = el('div', 'grid2');
  grid.appendChild(field('Stat', stat));
  grid.appendChild(field('Modifikator', modType));
  grid.appendChild(field('Wert', value));
  grid.appendChild(field('Seltenheit', rarity));
  grid.appendChild(field('Gewicht', weight, 'höher = häufiger'));
  grid.appendChild(field('Max. Stapel', maxStack));
  body.appendChild(grid);
  body.appendChild(spriteField('Icon (optional)', data.icon, (p) => { iconPath = p; }));
  body.appendChild(active);

  openModal(isNew ? 'Upgrade anlegen' : 'Upgrade bearbeiten', body, [
    { label: 'Abbrechen', class: 'btn--ghost', onClick: (close) => close() },
    {
      label: 'Speichern',
      class: 'btn--primary',
      onClick: async (close) => {
        try {
          await api('put', {
            section: 'upgrades',
            item: {
              ...data, id: data.id || slug(name.value), name: name.value,
              description: desc.value, stat: stat.value, modType: modType.value,
              value: +value.value, rarity: rarity.value, weight: +weight.value,
              maxStack: +maxStack.value, icon: iconPath, active: active.input.checked,
            },
          });
          close();
          toast('Gespeichert');
          setView('upgrades');
        } catch (e) { toast(e.message, 'error'); }
      },
    },
  ]);
}

/* ------------------------------------------------------ Spieler und Balance */
const PLAYER_FIELDS = [
  ['maxHealth', 'Max. Leben', 1, 1],
  ['moveSpeed', 'Bewegungstempo (px/s)', 10, 1],
  ['armor', 'Rüstung', 0, 0.5],
  ['damageMult', 'Basis-Schadensfaktor', 0.01, 0.05],
  ['critChance', 'Krit. Chance (%)', 0, 1],
  ['critDamage', 'Krit. Schaden (%)', 0, 5],
  ['dodge', 'Ausweichen (%)', 0, 1],
  ['regen', 'Regeneration (HP/s)', 0, 0.1],
  ['scale', 'Sprite-Höhe (px)', 8, 2],
];

views.player = (host) => {
  const p = state.content.player;
  const card = el('div', 'card');
  const form = el('div', 'form');
  const inputs = {};
  const grid = el('div', 'grid2');
  for (const [key, label, min, step] of PLAYER_FIELDS) {
    inputs[key] = textInput(p[key], { type: 'number', min, step });
    grid.appendChild(field(label, inputs[key]));
  }
  form.appendChild(grid);

  form.appendChild(el('h3', null, 'Hitbox (Füße)'));
  const hb = el('div', 'grid2');
  const rx = textInput(p.hitbox.rx, { type: 'number', min: 2, step: 1 });
  const ry = textInput(p.hitbox.ry, { type: 'number', min: 2, step: 1 });
  const oy = textInput(p.hitbox.oy, { type: 'number', step: 1 });
  hb.appendChild(field('Radius X', rx));
  hb.appendChild(field('Radius Y', ry));
  hb.appendChild(field('Versatz Y', oy));
  form.appendChild(hb);

  form.appendChild(el('h3', null, 'Sprites'));
  const sprites = {};
  for (const [key, label] of [
    ['spriteFront', 'Nach vorne (unten)'], ['spriteBack', 'Nach hinten (oben)'],
    ['spriteSide', 'Seitlich'], ['spriteDust', 'Staub beim Laufen'],
  ]) {
    sprites[key] = p[key];
    form.appendChild(spriteField(label, p[key], (path) => { sprites[key] = path; }));
  }

  const save = el('button', 'btn btn--primary', 'Spielerwerte speichern');
  save.addEventListener('click', async () => {
    const player = { ...p, ...sprites, hitbox: { rx: +rx.value, ry: +ry.value, oy: +oy.value } };
    for (const [key] of PLAYER_FIELDS) player[key] = +inputs[key].value;
    if (player.maxHealth <= 0) return toast('Leben muss größer als 0 sein.', 'error');
    if (player.moveSpeed <= 0) return toast('Tempo muss größer als 0 sein.', 'error');
    try {
      await api('settings', { player });
      toast('Spielerwerte gespeichert');
      setView('player');
    } catch (e) { toast(e.message, 'error'); }
  });
  form.appendChild(save);
  card.appendChild(form);
  host.appendChild(card);
};

const BALANCE_FIELDS = [
  ['waveDuration', 'Wellendauer (s)', 5, 1],
  ['bossDuration', 'Bossdauer (s)', 10, 5],
  ['enemySpawnRate', 'Spawnrate (Gegner/s)', 0.05, 0.1],
  ['maxEnemies', 'Max. Gegner gleichzeitig', 5, 5],
  ['healthScaling', 'Lebensskalierung je Zyklus', 1, 0.05],
  ['damageScaling', 'Schadensskalierung je Zyklus', 1, 0.02],
  ['speedScaling', 'Temposkalierung je Zyklus', 1, 0.01],
  ['spawnRateScaling', 'Spawnrate-Skalierung je Zyklus', 1, 0.02],
  ['rewardScaling', 'Geldskalierung je Zyklus', 1, 0.05],
  ['moneyMultiplier', 'Geld-Multiplikator', 0.1, 0.1],
  ['contactDamageCooldown', 'Kontaktschaden-Pause (s)', 0.1, 0.05],
  ['bossBombCooldown', 'Bossbomben-Cooldown (s)', 0.5, 0.1],
  ['bossBombMinCooldown', 'Minimaler Bomben-Cooldown (s)', 0.3, 0.1],
  ['bossBombRadius', 'Bombenradius', 20, 5],
  ['bossBombDelay', 'Bombenverzögerung (s)', 0.1, 0.1],
  ['bossBombFlightTime', 'Bomben-Flugzeit (s)', 0.1, 0.05],
  ['upgradeChoices', 'Upgrade-Karten pro Welle', 1, 1],
  ['rarityRareBase', 'Chance Rare (%)', 0, 1],
  ['rarityEpicBase', 'Chance Epic (%)', 0, 1],
  ['rarityLegendaryBase', 'Chance Legendary (%)', 0, 1],
  ['rarityCycleBonus', 'Seltenheitsbonus je Zyklus', 1, 0.05],
  ['weaponOfferChance', 'Chance auf Waffenkarte (0-1)', 0, 0.05],
];

views.balance = (host) => {
  const b = state.content.balance;
  const card = el('div', 'card');
  const form = el('div', 'form');
  const grid = el('div', 'grid2');
  const inputs = {};
  for (const [key, label, min, step] of BALANCE_FIELDS) {
    inputs[key] = textInput(b[key], { type: 'number', min, step });
    grid.appendChild(field(label, inputs[key]));
  }
  form.appendChild(grid);

  const save = el('button', 'btn btn--primary', 'Balancing speichern');
  save.addEventListener('click', async () => {
    const balance = { ...b };
    for (const [key] of BALANCE_FIELDS) balance[key] = +inputs[key].value;
    try {
      await api('settings', { balance });
      toast('Balancing gespeichert');
      setView('balance');
    } catch (e) { toast(e.message, 'error'); }
  });
  form.appendChild(save);
  card.appendChild(form);
  host.appendChild(card);

  const help = el('div', 'card');
  help.appendChild(el('h3', null, 'So wirkt die Skalierung'));
  help.appendChild(el('p', 'muted',
    'Gegnerleben = Basisleben × Lebensskalierung^(Zyklus-1). Bei 1.45 hat Zyklus 3 also das ' +
    (1.45 ** 2).toFixed(2) + '-fache. Schaden, Tempo, Spawnrate und Geld laufen nach derselben Formel.'));
  host.appendChild(help);
};

/* ------------------------------------------------------------- Charaktere */
views.characters = (host) => {
  addAction('Neuer Charakter', () => editCharacter(null));

  const wrap = el('div', 'tablewrap');
  const table = el('table', 'table');
  table.innerHTML = `<thead><tr>
    <th class="keep">Sprite</th><th class="keep">Name</th><th>Fähigkeit</th><th>Bilder</th>
    <th>Tempo Frames</th><th>Kosten</th><th class="keep">Status</th><th class="keep"></th>
  </tr></thead>`;
  const body = el('tbody');

  for (const character of state.content.characters || []) {
    const tr = el('tr');
    const cell = el('td', 'keep');
    const img = el('img', 'thumb');
    const front = character.sprites?.front || {};
    img.src = '../' + ((front.frames && front.frames[0]) || front.gif || '');
    if (character.tint) img.style.filter = `hue-rotate(${character.tint}deg) saturate(1.15)`;
    img.alt = '';
    cell.appendChild(img);
    tr.appendChild(cell);

    tr.appendChild(el('td', 'keep', character.name));
    tr.appendChild(el('td', null, character.title || '-'));
    const counts = ['front', 'back', 'side']
      .map((d) => (character.sprites?.[d]?.frames?.length) || (character.sprites?.[d]?.gif ? 'GIF' : 0));
    tr.appendChild(el('td', null, counts.join(' / ')));
    tr.appendChild(el('td', null, Math.round(character.frameDuration || 130) + ' ms'));
    tr.appendChild(el('td', null, character.starter ? 'frei' : character.unlockCost + ' XP'));

    const status = el('td', 'keep');
    status.appendChild(el('span', 'badge ' + (character.active ? 'badge--on' : 'badge--off'),
      character.active ? 'aktiv' : 'aus'));
    tr.appendChild(status);

    const actions = el('td', 'actions keep');
    actions.appendChild(actionBtn('Bearbeiten', () => editCharacter(character)));
    actions.appendChild(actionBtn('Duplizieren', () => duplicate('characters', character)));
    actions.appendChild(actionBtn('Löschen', () => removeItem('characters', character, character.name), 'btn--danger'));
    tr.appendChild(actions);
    body.appendChild(tr);
  }
  table.appendChild(body);
  wrap.appendChild(table);
  host.appendChild(wrap);
};

function editCharacter(character) {
  const isNew = !character;
  const data = character ? JSON.parse(JSON.stringify(character)) : {
    id: '', name: 'Neuer Charakter', title: '', description: '', perk: '', tint: 0,
    sprites: {
      front: { gif: 'assets/sprites/playerfront.gif', frames: [] },
      back: { gif: 'assets/sprites/playerback.gif', frames: [] },
      side: { gif: 'assets/sprites/playerside.gif', frames: [] },
    },
    frameDuration: 130, dustSprite: 'assets/sprites/staub.gif', scale: 78,
    hitbox: { rx: 14, ry: 9, oy: 24 },
    mods: { maxHealth: 1, moveSpeed: 1, damageMult: 1, attackSpeed: 1, range: 1,
            projectileSpeed: 1, armor: 0, critChance: 0, critDamage: 0, dodge: 0, regen: 0, shield: 0 },
    starter: false, unlockCost: 20, active: true, order: 99,
  };

  const body = el('div', 'form');
  const name = textInput(data.name);
  const title = textInput(data.title, { placeholder: 'z. B. Späherin' });
  const desc = el('textarea');
  desc.className = 'input';
  desc.value = data.description || '';

  body.appendChild(field('Name', name));
  body.appendChild(field('Kurzbezeichnung', title));
  body.appendChild(field('Beschreibung', desc));

  /* --- Sprites je Richtung: GIF oder bis zu fünf Einzelbilder ---------- */
  body.appendChild(el('h3', null, 'Sprites'));
  body.appendChild(el('p', 'muted',
    'Pro Richtung entweder ein animiertes GIF oder bis zu fünf Einzelbilder. '
    + 'Liegen Einzelbilder vor, baut das Spiel daraus die Animation.'));

  const spriteState = JSON.parse(JSON.stringify(data.sprites));
  for (const [dir, label] of [['front', 'Nach vorne (unten)'], ['back', 'Nach hinten (oben)'], ['side', 'Seitlich']]) {
    body.appendChild(directionEditor(dir, label, spriteState));
  }

  const grid = el('div', 'grid2');
  const frameDuration = textInput(data.frameDuration ?? 130, { type: 'number', min: 20, max: 2000, step: 10 });
  const tint = textInput(data.tint ?? 0, { type: 'number', min: 0, max: 360, step: 5 });
  const scale = textInput(data.scale ?? 78, { type: 'number', min: 8, max: 600, step: 2 });
  grid.appendChild(field('Bildwechsel (ms)', frameDuration, 'gilt für Einzelbilder'));
  grid.appendChild(field('Farbdrehung (Grad)', tint, '0 = Originalfarben'));
  grid.appendChild(field('Sprite-Höhe (px)', scale));
  body.appendChild(grid);

  let dustPath = data.dustSprite;
  body.appendChild(spriteField('Staub beim Laufen', data.dustSprite, (p) => { dustPath = p; }));

  /* --- Fähigkeiten ----------------------------------------------------- */
  body.appendChild(el('h3', null, 'Fähigkeiten'));
  const perk = selectInput(data.perk || '', [
    { value: '', label: 'keine besondere Fähigkeit' },
    { value: 'lifesteal', label: 'Lebensraub - jeder Kill heilt' },
    { value: 'thorns', label: 'Dornen - Nahkampfschaden zurück' },
    { value: 'luckyCards', label: 'Glückskarten - bessere Upgrades' },
  ]);
  body.appendChild(field('Sonderfähigkeit', perk));

  const modFields = {};
  const modGrid = el('div', 'grid2');
  for (const [key, label, step] of [
    ['maxHealth', 'Leben (Faktor)', 0.05], ['moveSpeed', 'Tempo (Faktor)', 0.02],
    ['damageMult', 'Schaden (Faktor)', 0.02], ['attackSpeed', 'Angriffstempo (Faktor)', 0.02],
    ['range', 'Reichweite (Faktor)', 0.05], ['projectileSpeed', 'Projektiltempo (Faktor)', 0.05],
    ['armor', 'Rüstung (+)', 1], ['critChance', 'Krit-Chance (+%)', 1],
    ['critDamage', 'Krit-Schaden (+%)', 5], ['dodge', 'Ausweichen (+%)', 1],
    ['regen', 'Regeneration (+HP/s)', 0.1], ['shield', 'Startschild (+)', 5],
  ]) {
    modFields[key] = textInput(data.mods?.[key] ?? (step < 1 && key.includes('Speed') ? 1 : 0), { type: 'number', step });
    modGrid.appendChild(field(label, modFields[key]));
  }
  body.appendChild(modGrid);

  const hb = el('div', 'grid2');
  const rx = textInput(data.hitbox?.rx ?? 14, { type: 'number', min: 2, step: 1 });
  const ry = textInput(data.hitbox?.ry ?? 9, { type: 'number', min: 2, step: 1 });
  const oy = textInput(data.hitbox?.oy ?? 24, { type: 'number', step: 1 });
  hb.appendChild(field('Hitbox Radius X', rx));
  hb.appendChild(field('Hitbox Radius Y', ry));
  hb.appendChild(field('Hitbox Versatz Y', oy));
  body.appendChild(hb);

  const starter = checkInput('Von Anfang an spielbar', data.starter);
  const active = checkInput('Charakter ist auswählbar', data.active);
  const cost = textInput(data.unlockCost ?? 20, { type: 'number', min: 0, step: 5 });
  body.appendChild(starter);
  body.appendChild(field('Freischaltkosten (Erfahrungspunkte)', cost));
  body.appendChild(active);

  openModal(isNew ? 'Charakter anlegen' : 'Charakter bearbeiten', body, [
    { label: 'Abbrechen', class: 'btn--ghost', onClick: (close) => close() },
    {
      label: 'Speichern',
      class: 'btn--primary',
      onClick: async (close) => {
        const mods = {};
        for (const [key, input] of Object.entries(modFields)) mods[key] = +input.value;
        try {
          await api('put', {
            section: 'characters',
            item: {
              ...data, id: data.id || slug(name.value), name: name.value, title: title.value,
              description: desc.value, perk: perk.value, tint: +tint.value,
              sprites: spriteState, frameDuration: +frameDuration.value, dustSprite: dustPath,
              scale: +scale.value, hitbox: { rx: +rx.value, ry: +ry.value, oy: +oy.value },
              mods, starter: starter.input.checked, unlockCost: +cost.value, active: active.input.checked,
            },
          });
          close();
          toast('Gespeichert');
          setView('characters');
        } catch (e) { toast(e.message, 'error'); }
      },
    },
  ], true);
}

/** Ein GIF-Feld plus fünf Einzelbild-Plätze für eine Blickrichtung. */
function directionEditor(dir, label, spriteState) {
  const box = el('div', 'card');
  box.appendChild(el('h4', null, label));
  const entry = spriteState[dir] || (spriteState[dir] = { gif: '', frames: [] });

  box.appendChild(spriteField('Animiertes GIF (optional)', entry.gif, (p) => { entry.gif = p; }));

  const frames = el('div', 'framerow');
  const render = () => {
    frames.textContent = '';
    for (let i = 0; i < 5; i++) {
      const slot = el('div', 'frameslot');
      const path = entry.frames[i];
      if (path) {
        const img = el('img');
        img.src = '../' + path;
        img.alt = '';
        slot.appendChild(img);
        const remove = el('button', 'frameslot__x', '✕');
        remove.title = 'Bild entfernen';
        remove.addEventListener('click', () => {
          entry.frames.splice(i, 1);
          render();
        });
        slot.appendChild(remove);
      } else {
        slot.classList.add('is-empty');
        slot.appendChild(el('span', null, 'Bild ' + (i + 1)));
      }
      const upload = el('input');
      upload.type = 'file';
      upload.accept = 'image/png,image/gif,image/jpeg,image/webp';
      upload.addEventListener('change', async () => {
        if (!upload.files.length) return;
        try {
          const data = await uploadFile(upload.files[0]);
          entry.frames[i] = data.path;
          entry.frames = entry.frames.filter(Boolean);
          render();
          toast('Bild ' + (i + 1) + ' gesetzt');
        } catch (err) { toast(err.message, 'error'); }
      });
      slot.appendChild(upload);
      frames.appendChild(slot);
    }
  };
  render();
  box.appendChild(el('div', 'muted', 'Einzelbilder (überschreiben das GIF):'));
  box.appendChild(frames);
  return box;
}

/* ------------------------------------------------------------------ Audio */
views.audio = (host) => {
  const a = state.content.audio || {};
  const card = el('div', 'card');
  const form = el('div', 'form');

  let track = a.musicTrack || '';
  const preview = el('audio');
  preview.controls = true;
  preview.preload = 'none';
  preview.style.cssText = 'width:100%;max-width:420px;';
  if (track) preview.src = '../' + track;

  const trackRow = el('div', 'field');
  trackRow.appendChild(el('label', null, 'Musiktitel'));
  const trackName = el('div', 'muted', track || 'kein Titel gesetzt');
  const upload = el('label', 'btn btn--sm', 'Musik hochladen (MP3, OGG, WAV, M4A)');
  const fileInput = el('input');
  fileInput.type = 'file';
  fileInput.accept = 'audio/mpeg,audio/ogg,audio/wav,audio/mp4,.mp3,.ogg,.wav,.m4a';
  fileInput.style.display = 'none';
  upload.appendChild(fileInput);
  fileInput.addEventListener('change', async () => {
    if (!fileInput.files.length) return;
    upload.textContent = 'Lädt hoch ...';
    try {
      const form = new FormData();
      form.append('file', fileInput.files[0]);
      form.append('kind', 'audio');
      form.append('name', fileInput.files[0].name.replace(/\.[^.]+$/, ''));
      form.append('csrf', state.csrf);
      const res = await fetch('../api.php?action=upload', {
        method: 'POST', headers: { 'X-CSRF': state.csrf }, body: form,
      });
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'Upload fehlgeschlagen');
      track = data.path;
      trackName.textContent = track;
      preview.src = '../' + track;
      toast('Musik hochgeladen');
    } catch (err) {
      toast(err.message, 'error');
    }
    upload.textContent = 'Musik hochladen (MP3, OGG, WAV, M4A)';
    upload.appendChild(fileInput);
    fileInput.value = '';
  });
  trackRow.appendChild(trackName);
  trackRow.appendChild(preview);
  trackRow.appendChild(upload);
  form.appendChild(trackRow);

  const grid = el('div', 'grid2');
  const musicVol = textInput(a.musicVolume ?? 0.5, { type: 'number', min: 0, max: 1, step: 0.05 });
  const sfxVol = textInput(a.sfxVolume ?? 0.8, { type: 'number', min: 0, max: 1, step: 0.05 });
  grid.appendChild(field('Musiklautstärke (0-1)', musicVol));
  grid.appendChild(field('Effektlautstärke (0-1)', sfxVol, 'Grundlautstärke über allen Effekten'));
  form.appendChild(grid);

  const enabled = checkInput('Musik startet automatisch', a.musicEnabled !== false);
  form.appendChild(enabled);
  form.appendChild(el('p', 'muted',
    'Spieler können die Musik im Spiel jederzeit über den Notenknopf abschalten; '
    + 'diese Wahl wird auf dem Gerät gemerkt. Browser starten Ton erst nach der ersten Berührung.'));

  const save = el('button', 'btn btn--primary', 'Audio speichern');
  save.addEventListener('click', async () => {
    try {
      await api('settings', {
        audio: {
          musicTrack: track,
          musicVolume: +musicVol.value,
          sfxVolume: +sfxVol.value,
          musicEnabled: enabled.input.checked,
          sounds: a.sounds,
        },
      });
      toast('Audio gespeichert');
      setView('audio');
    } catch (e) { toast(e.message, 'error'); }
  });
  form.appendChild(save);
  card.appendChild(form);
  host.appendChild(card);

  /* --- Einzelne Soundeffekte ------------------------------------------ */
  const soundCard = el('div', 'card');
  soundCard.appendChild(el('h3', null, 'Soundeffekte'));
  soundCard.appendChild(el('p', 'muted',
    'Uploadgrenze dieses Servers: ' + state.uploadLimit + '. '
    + 'Je Ereignis bis zu vier Dateien - das Spiel wählt zufällig eine davon. '
    + 'Lautstärke und Häufigkeit gelten für das ganze Ereignis, jede Datei hat '
    + 'zusätzlich einen eigenen Regler.'));

  const editors = {};
  for (const [id, slot] of Object.entries(a.sounds || {})) {
    const editor = soundSetEditor(slot.label || id, slot);
    editors[id] = editor;
    soundCard.appendChild(editor);
  }

  const saveSounds = el('button', 'btn btn--primary', 'Soundeffekte speichern');
  saveSounds.addEventListener('click', async () => {
    const sounds = {};
    for (const [id, editor] of Object.entries(editors)) sounds[id] = editor.read();
    try {
      await api('settings', {
        audio: {
          musicTrack: track,
          musicVolume: +musicVol.value,
          sfxVolume: +sfxVol.value,
          musicEnabled: enabled.input.checked,
          sounds,
        },
      });
      toast('Soundeffekte gespeichert');
      setView('audio');
    } catch (e) { toast(e.message, 'error'); }
  });
  soundCard.appendChild(saveSounds);
  host.appendChild(soundCard);
};

/* ----------------------------------------------------------------- Helfer */
function slug(text) {
  return (text || '')
    .toLowerCase()
    .replace(/[äÄ]/g, 'ae').replace(/[öÖ]/g, 'oe').replace(/[üÜ]/g, 'ue').replace(/ß/g, 'ss')
    .replace(/[^a-z0-9]+/g, '_')
    .replace(/^_+|_+$/g, '')
    .slice(0, 30) || 'item_' + Math.random().toString(36).slice(2, 7);
}

async function duplicate(section, item) {
  try {
    await api('put', {
      section,
      item: { ...item, id: slug(item.name + '_kopie_' + Math.random().toString(36).slice(2, 5)), name: item.name + ' (Kopie)' },
    });
    toast('Dupliziert');
    setView(state.view);
  } catch (e) { toast(e.message, 'error'); }
}

async function removeItem(section, item, label) {
  // Vor dem Löschen prüfen, ob das Sprite noch woanders hängt.
  let extra = '';
  if (section === 'enemies' && item.boss) extra = ' Ohne Boss fällt die Bosswelle aus.';
  if (section === 'weapons' && item.starter) extra = ' Diese Waffe wird aktuell als Starterwaffe angeboten.';
  confirmDialog(`"${label}" wirklich löschen?${extra}`, async () => {
    try {
      await api('delete', { section, id: item.id });
      toast('Gelöscht');
      setView(state.view);
    } catch (e) { toast(e.message, 'error'); }
  });
}

setView('dashboard');
window.ADMIN_DEBUG = { state, api, setView };
