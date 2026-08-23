/* ==========================================================================
   Arena Admin - Dashboard fuer Maps, Gegner, Waffen, Upgrades und Balancing.
   Alle Aenderungen gehen ueber die serverseitige API und landen in
   data/content.json - genau der Datei, aus der das Spiel liest.
   ========================================================================== */

const state = {
  content: window.ADMIN.content,
  csrf: window.ADMIN.csrf,
  sprites: window.ADMIN.sprites,
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
  const data = await res.json().catch(() => ({ ok: false, error: 'Ungueltige Antwort' }));
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
  openModal('Bitte bestaetigen', body, [
    { label: 'Abbrechen', class: 'btn--ghost', onClick: (close) => close() },
    { label: 'Loeschen', class: 'btn--danger', onClick: (close) => { close(); onYes(); } },
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

/* ------------------------------------------------------------ Navigation */
const views = {};

function setView(name) {
  state.view = name;
  document.querySelectorAll('.nav__item').forEach((b) => b.classList.toggle('is-active', b.dataset.view === name));
  $('view-title').textContent = {
    dashboard: 'Dashboard', maps: 'Maps', enemies: 'Gegner', weapons: 'Waffen',
    upgrades: 'Upgrades', player: 'Spieler', balance: 'Balancing',
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
  info.appendChild(el('h3', null, 'Kurzuebersicht'));
  const list = el('div', 'form');
  list.appendChild(el('p', 'muted',
    `Wellendauer ${c.balance.waveDuration}s · Bossdauer ${c.balance.bossDuration}s · ` +
    `max. ${c.balance.maxEnemies} Gegner · Lebensskalierung ×${c.balance.healthScaling} pro Zyklus.`));
  list.appendChild(el('p', 'muted',
    `Spieler: ${c.player.maxHealth} HP, Tempo ${c.player.moveSpeed}, ` +
    `${c.player.critChance}% Krit.`));
  info.appendChild(list);
  host.appendChild(info);

  const actions = el('div', 'card');
  actions.appendChild(el('h3', null, 'Wartung'));
  const row = el('div', 'editor__tools');
  const resetBtn = el('button', 'btn btn--danger', 'Alles auf Standard zuruecksetzen');
  resetBtn.addEventListener('click', () => confirmDialog(
    'Setzt Maps, Gegner, Waffen, Upgrades und Balancing auf die Startwerte zurueck. Hochgeladene Dateien bleiben erhalten.',
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
    <th class="keep">Vorschau</th><th class="keep">Name</th><th>Aufloesung</th>
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
    actions.appendChild(actionBtn('Loeschen', () => confirmDialog(
      `Map "${map.name}" wirklich loeschen?`,
      async () => {
        try {
          await api('delete', { section: 'maps', id: map.id });
          toast('Map geloescht');
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
  drop.textContent = 'Kartenbild waehlen (PNG, GIF, JPG, WEBP - max. 12 MB)';
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
    drop.textContent = 'Laedt hoch ...';
    try {
      uploaded = await uploadFile(input.files[0], nameInput.value);
      preview.textContent = '';
      const img = el('img');
      img.src = '../' + uploaded.path;
      preview.appendChild(img);
      preview.appendChild(el('span', 'muted', `${uploaded.width} × ${uploaded.height}`));
      drop.textContent = 'Anderes Bild waehlen';
    } catch (err) {
      toast(err.message, 'error');
      drop.textContent = 'Kartenbild waehlen';
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
  const active = checkInput('Map ist im Spiel auswaehlbar', map.active);
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
 * nicht in Bildschirmpixeln - dadurch passt die Maske unabhaengig von
 * Zoom, Geraet und Aufloesung.
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
  const undoBtn = el('button', 'btn btn--sm', 'Rueckgaengig');
  const redoBtn = el('button', 'btn btn--sm', 'Wiederholen');
  const clearBtn = el('button', 'btn btn--sm btn--danger', 'Alles loeschen');
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

  /* Buehne */
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
    'Ein Finger malt, zwei Finger verschieben und zoomen. Rote Flaechen sind im Spiel blockiert und dort unsichtbar. '
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
    actions.appendChild(actionBtn('Loeschen', () => removeItem('enemies', enemy, enemy.name), 'btn--danger'));
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
  grid.appendChild(field('Spawn-Gewicht', weight, '0 = spawnt nicht zufaellig'));
  grid.appendChild(field('Kontaktschaden-Pause (s)', cooldown));
  grid.appendChild(field('Sprite-Hoehe (px)', scale));
  grid.appendChild(field('Bevorzugte Welle', wave));
  body.appendChild(grid);

  const boss = checkInput('Ist ein Boss (erscheint in Welle 4)', data.boss);
  body.appendChild(boss);

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
    const hField = field('Hoehe', sizeH);
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
        if (+health.value <= 0) return toast('Leben muss groesser als 0 sein.', 'error');
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
    <th>Cooldown</th><th>Reichweite</th><th>AoE</th><th class="keep">Status</th><th class="keep"></th>
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
    tr.appendChild(el('td', null, weapon.aoeRadius ? String(Math.round(weapon.aoeRadius)) : '-'));
    const status = el('td', 'keep');
    status.appendChild(el('span', 'badge ' + (weapon.active ? 'badge--on' : 'badge--off'), weapon.active ? 'aktiv' : 'aus'));
    tr.appendChild(status);
    const actions = el('td', 'actions keep');
    actions.appendChild(actionBtn('Bearbeiten', () => editWeapon(weapon)));
    actions.appendChild(actionBtn('Duplizieren', () => duplicate('weapons', weapon)));
    actions.appendChild(actionBtn('Loeschen', () => removeItem('weapons', weapon, weapon.name), 'btn--danger'));
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
    spread: 0, recoil: 6, description: '', active: true, starter: false, damageType: 'physical',
  };

  const body = el('div', 'form');
  const name = textInput(data.name);
  const desc = el('textarea');
  desc.className = 'input';
  desc.value = data.description || '';
  let spritePath = data.sprite;

  body.appendChild(field('Name', name));
  body.appendChild(spriteField('Sprite', data.sprite, (p) => { spritePath = p; }));

  const type = selectInput(data.type, [
    { value: 'PROJECTILE', label: 'PROJECTILE - Schuss' },
    { value: 'MAGIC', label: 'MAGIC - Zielsuchendes Geschoss' },
    { value: 'MELEE_ARC', label: 'MELEE_ARC - Schwung vor dem Spieler' },
    { value: 'MELEE_360', label: 'MELEE_360 - volle Drehung' },
    { value: 'THRUST', label: 'THRUST - Stoss nach vorne' },
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

  grid.appendChild(field('Typ', type));
  grid.appendChild(field('Projektil', projectile));
  grid.appendChild(field('Schaden', damage));
  grid.appendChild(field('Cooldown (s)', cooldown, 'kleiner = schneller'));
  grid.appendChild(field('Reichweite', range));
  grid.appendChild(field('Projektiltempo', pspeed));
  grid.appendChild(field('Rueckstoss', knock));
  grid.appendChild(field('Krit. Chance (%)', critC));
  grid.appendChild(field('Krit. Schaden (%)', critD));
  grid.appendChild(field('AoE-Radius', aoe, 'nur fuer Granate / Explosionen'));
  grid.appendChild(field('Schwungwinkel', arc, 'nur MELEE_ARC'));
  grid.appendChild(field('Durchschlaege', pierce));
  grid.appendChild(field('Streuung (Grad)', spread));
  grid.appendChild(field('Rueckstoss-Animation', recoil));
  body.appendChild(grid);
  body.appendChild(field('Beschreibung', desc));

  const active = checkInput('Waffe ist im Spiel verfuegbar', data.active);
  const starter = checkInput('Als Starterwaffe anbieten', data.starter);
  body.appendChild(active);
  body.appendChild(starter);

  openModal(isNew ? 'Waffe anlegen' : 'Waffe bearbeiten', body, [
    { label: 'Abbrechen', class: 'btn--ghost', onClick: (close) => close() },
    {
      label: 'Speichern',
      class: 'btn--primary',
      onClick: async (close) => {
        if (+cooldown.value <= 0) return toast('Cooldown muss groesser als 0 sein.', 'error');
        try {
          await api('put', {
            section: 'weapons',
            item: {
              ...data, id: data.id || slug(name.value), name: name.value, sprite: spritePath,
              type: type.value, projectile: projectile.value, damage: +damage.value,
              cooldown: +cooldown.value, range: +range.value, projectileSpeed: +pspeed.value,
              knockback: +knock.value, critChance: +critC.value, critDamage: +critD.value,
              aoeRadius: +aoe.value, arc: +arc.value, pierce: +pierce.value, spread: +spread.value,
              recoil: +recoil.value, description: desc.value,
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
  ['maxHealth', 'Max. Leben'], ['armor', 'Ruestung'], ['shield', 'Schild'],
  ['critChance', 'Krit. Chance'], ['critDamage', 'Krit. Schaden'],
  ['projectileSpeed', 'Projektiltempo'], ['range', 'Reichweite'],
  ['knockback', 'Rueckstoss'], ['dodge', 'Ausweichen'], ['regen', 'Regeneration'],
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
    actions.appendChild(actionBtn('Loeschen', () => removeItem('upgrades', up, up.name), 'btn--danger'));
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
  grid.appendChild(field('Gewicht', weight, 'hoeher = haeufiger'));
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
  ['armor', 'Ruestung', 0, 0.5],
  ['damageMult', 'Basis-Schadensfaktor', 0.01, 0.05],
  ['critChance', 'Krit. Chance (%)', 0, 1],
  ['critDamage', 'Krit. Schaden (%)', 0, 5],
  ['dodge', 'Ausweichen (%)', 0, 1],
  ['regen', 'Regeneration (HP/s)', 0, 0.1],
  ['scale', 'Sprite-Hoehe (px)', 8, 2],
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

  form.appendChild(el('h3', null, 'Hitbox (Fuesse)'));
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
    if (player.maxHealth <= 0) return toast('Leben muss groesser als 0 sein.', 'error');
    if (player.moveSpeed <= 0) return toast('Tempo muss groesser als 0 sein.', 'error');
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
  ['bossBombDelay', 'Bombenverzoegerung (s)', 0.1, 0.1],
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
  // Vor dem Loeschen pruefen, ob das Sprite noch woanders haengt.
  let extra = '';
  if (section === 'enemies' && item.boss) extra = ' Ohne Boss faellt die Bosswelle aus.';
  if (section === 'weapons' && item.starter) extra = ' Diese Waffe wird aktuell als Starterwaffe angeboten.';
  confirmDialog(`"${label}" wirklich loeschen?${extra}`, async () => {
    try {
      await api('delete', { section, id: item.id });
      toast('Geloescht');
      setView(state.view);
    } catch (e) { toast(e.message, 'error'); }
  });
}

setView('dashboard');
window.ADMIN_DEBUG = { state, api, setView };
