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
    characters: 'Charaktere', shop: 'Laden & Aussehen', items: 'Gegenstände',
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
    actions.appendChild(actionBtn('Hindernisse & Portale', () => openCollisionEditor(map)));
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

  // Zum Start "Touch": erst schauen, dann bewusst ein Werkzeug waehlen.
  let tool = 'touch';
  let brush = 3;
  let scale = 1;
  let panX = 0;
  let panY = 0;
  let spawn = { ...map.spawn };
  let zones = (map.enemySpawnAreas || []).map((z) => ({ ...z }));
  let portals = (map.portals || []).map((p) => ({ ...p }));
  const undo = [];
  const redo = [];

  const root = el('div', 'editor');

  /* Werkzeugleiste */
  const tools = el('div', 'editor__tools');
  const brushBtn = el('button', 'btn btn--sm', 'Malen');
  const eraseBtn = el('button', 'btn btn--sm', 'Radieren');
  const panBtn = el('button', 'btn btn--sm', 'Verschieben');
  const touchBtn = el('button', 'btn btn--sm is-active', 'Touch');
  const spawnBtn = el('button', 'btn btn--sm', 'Startpunkt');
  const zoneBtn = el('button', 'btn btn--sm', 'Gegnerzone');
  const zoneClearBtn = el('button', 'btn btn--sm', 'Zonen leeren');
  const redBtn = el('button', 'btn btn--sm', 'Portal rot');
  const blueBtn = el('button', 'btn btn--sm', 'Portal blau');
  const portalClearBtn = el('button', 'btn btn--sm', 'Portale leeren');
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

  for (const b of [touchBtn, brushBtn, eraseBtn, panBtn, spawnBtn, zoneBtn, redBtn, blueBtn,
                   undoBtn, redoBtn, clearBtn, zoneClearBtn, portalClearBtn, fitBtn]) {
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
  const portalLayer = el('div');
  portalLayer.style.cssText = 'position:absolute;left:0;top:0;pointer-events:none;';
  wrap.appendChild(img);
  wrap.appendChild(maskCanvas);
  wrap.appendChild(zoneLayer);
  wrap.appendChild(portalLayer);
  wrap.appendChild(marker);
  stage.appendChild(wrap);
  root.appendChild(stage);
  root.appendChild(el('p', 'editor__hint',
    'Der Editor startet im Modus "Touch": Da lässt sich gefahrlos schieben und zoomen, '
    + 'ohne dass etwas gemalt wird. Erst mit "Malen" oder "Radieren" verändert ein Finger die Karte. '
    + 'Ein Finger malt, zwei Finger verschieben und zoomen. Rote Flächen sind im Spiel blockiert und dort unsichtbar. '
    + 'Der grüne Kreis ist der Startpunkt, blaue Kreise sind optionale Gegner-Spawnzonen '
    + '(ohne Zonen spawnen Gegner rund um den Bildausschnitt). Portale setzt du mit "Portal rot" '
    + 'und "Portal blau"; sie werden paarweise verbunden - das erste rote führt zum ersten blauen '
    + 'und zurück. Ein Tippen auf ein gesetztes Portal entfernt es wieder.'));

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

  function drawPortals() {
    portalLayer.textContent = '';
    const rot = portals.filter((p) => p.kind === 'red');
    const blau = portals.filter((p) => p.kind === 'blue');
    for (const portal of portals) {
      const farbe = portal.kind === 'red' ? '#ff5a4d' : '#4db4ff';
      // Nummer zeigt, welches Portal mit welchem verbunden ist.
      const liste = portal.kind === 'red' ? rot : blau;
      const nummer = liste.indexOf(portal) + 1;
      const groesse = 46;
      const dot = el('div');
      dot.style.cssText = `position:absolute;left:${portal.x - groesse / 2}px;top:${portal.y - groesse / 2}px;`
        + `width:${groesse}px;height:${groesse}px;border-radius:50%;`
        + `border:3px solid ${farbe};background:${farbe}33;`
        + 'display:grid;place-items:center;font:700 18px sans-serif;color:#fff;'
        + 'text-shadow:0 1px 3px rgba(0,0,0,.9);';
      dot.textContent = String(nummer);
      portalLayer.appendChild(dot);
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
    // Im Touch-Modus wird nichts verändert - nur geschoben und gezoomt.
    if (tool === 'touch') {
      pinchStart = { pan: true, x: e.clientX, y: e.clientY, panX, panY };
      return;
    }
    if (tool === 'portal-red' || tool === 'portal-blue') {
      const rect = stage.getBoundingClientRect();
      const x = (e.clientX - rect.left - panX) / scale;
      const y = (e.clientY - rect.top - panY) / scale;
      // Auf ein vorhandenes Portal getippt: entfernen.
      const treffer = portals.findIndex((p) => Math.hypot(p.x - x, p.y - y) < 30);
      if (treffer >= 0) portals.splice(treffer, 1);
      else if (portals.length < 16) {
        portals.push({
          id: 'portal_' + Math.random().toString(36).slice(2, 8),
          kind: tool === 'portal-red' ? 'red' : 'blue',
          x, y, scale: 130,
        });
      } else {
        toast('Höchstens 16 Portale je Karte.', 'error');
      }
      drawPortals();
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
      [touchBtn, brushBtn, eraseBtn, panBtn, spawnBtn, zoneBtn, redBtn, blueBtn]
        .forEach((b) => b.classList.toggle('is-active', b === button));
      // Sichtbar machen, dass gerade nichts gemalt wird.
      stage.classList.toggle('is-touch', next === 'touch' || next === 'pan');
  }
  touchBtn.addEventListener('click', () => setTool('touch', touchBtn));
  brushBtn.addEventListener('click', () => setTool('brush', brushBtn));
  eraseBtn.addEventListener('click', () => setTool('erase', eraseBtn));
  panBtn.addEventListener('click', () => setTool('pan', panBtn));
  spawnBtn.addEventListener('click', () => setTool('spawn', spawnBtn));
  zoneBtn.addEventListener('click', () => setTool('zone', zoneBtn));
  redBtn.addEventListener('click', () => setTool('portal-red', redBtn));
  blueBtn.addEventListener('click', () => setTool('portal-blue', blueBtn));
  portalClearBtn.addEventListener('click', () => {
    portals = [];
    drawPortals();
  });
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
  drawPortals();
  stage.classList.add('is-touch');
  openModal('Karte bearbeiten - ' + map.name, root, [
    { label: 'Abbrechen', class: 'btn--ghost', onClick: (close) => close() },
    {
      label: 'Speichern',
      class: 'btn--primary',
      onClick: async (close) => {
        try {
          await api('put', {
            section: 'maps',
            item: {
              ...map, spawn, enemySpawnAreas: zones, portals,
              collision: { cols, rows, data: encodeMask(bits) },
            },
          });
          close();
          toast('Karte gespeichert');
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
  const muzzleOffsetY = textInput(data.muzzleOffsetY ?? (data.holdOffsetY ?? -6), { type: 'number', min: -160, max: 160, step: 1 });
  const muzzleDistance = textInput(data.muzzleDistance ?? 0, { type: 'number', min: 0, max: 300, step: 1 });

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
  grid.appendChild(field('Projektil-Starthöhe (px)', muzzleOffsetY, 'negativ = höher, positiv = tiefer'));
  grid.appendChild(field('Projektil-Startabstand (px)', muzzleDistance, '0 = automatisch aus der Waffengröße'));
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
  // Zweite Vorschau: derselbe Schuss aus Spielersicht in alle Richtungen.
  const charaktere = state.content.characters || [];
  const charWahl = selectInput(
    (charaktere.find((c) => c.starter) || charaktere[0] || {}).id || '',
    charaktere.map((c) => ({ value: c.id, label: c.name })),
  );
  body.appendChild(field('Charakter für die Vorschau', charWahl));
  body.appendChild(schussVorschau(() => ({
    charakter: charaktere.find((c) => c.id === charWahl.value) || charaktere[0] || null,
    waffe: {
      sprite: spritePath,
      spriteScale: +spriteScale.value || 46,
      projectileSize: +projectileSize.value || 16,
      holdOffsetY: +holdOffsetY.value || 0,
      holdDistance: +holdDistance.value || 0,
      muzzleOffsetY: +muzzleOffsetY.value,
      muzzleDistance: +muzzleDistance.value,
      projectile: projectile.value,
    },
  })));

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
              holdDistance: +holdDistance.value, muzzleOffsetY: +muzzleOffsetY.value,
              muzzleDistance: +muzzleDistance.value, description: desc.value,
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
  ['burn', 'Feuerschaden (Verbrennung)'], ['potionRate', 'Heilflaschen-Chance'],
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

/* ------------------------------------------------- Schuss-Vorschau */
/**
 * Zeigt eine Figur, die mit einer Waffe in alle Richtungen schiesst.
 *
 * Damit laesst sich die Starthoehe des Projektils beurteilen, ohne das
 * Spiel zu starten: Die Vorschau rechnet genau wie das Spiel und
 * durchlaeuft acht Blickrichtungen.
 *
 * @param lies  () => ({ charakter, waffe }) - liefert die aktuellen Werte
 * @returns {HTMLElement} mit .stop() zum Anhalten
 */
function schussVorschau(lies) {
  const box = el('div', 'field');
  box.appendChild(el('label', null, 'Vorschau: Schuss in alle Richtungen'));

  const canvas = el('canvas');
  canvas.width = 460;
  canvas.height = 260;
  canvas.style.cssText = 'width:100%;max-width:460px;background:'
    + 'radial-gradient(120% 90% at 50% 30%, #16202c 0%, #0d1119 70%);'
    + 'border-radius:12px;image-rendering:pixelated;';
  box.appendChild(canvas);

  const hinweis = el('p', 'muted');
  hinweis.textContent = 'Der Punkt zeigt, wo das Projektil startet.';
  box.appendChild(hinweis);

  const ctx = canvas.getContext('2d');
  const bilder = new Map();
  const hole = (pfad) => {
    if (!pfad) return null;
    if (!bilder.has(pfad)) {
      const img = new Image();
      img.src = '../' + pfad;
      bilder.set(pfad, img);
    }
    const img = bilder.get(pfad);
    return img.complete && img.naturalWidth ? img : null;
  };

  const MITTE_X = 230;
  const MITTE_Y = 168;
  const RICHTUNGEN = 8;
  let laeuft = true;
  let start = performance.now();
  let schuss = [];

  function figurBild(charakter, winkel) {
    const sprites = (charakter && charakter.sprites) || {};
    const grad = ((winkel * 180) / Math.PI + 360) % 360;
    // Nach der dominanten Achse - genau wie im Spiel.
    let richtung = 'side';
    let spiegeln = false;
    if (grad > 45 && grad < 135) richtung = 'front';
    else if (grad > 225 && grad < 315) richtung = 'back';
    else if (grad >= 135 && grad <= 225) { richtung = 'side'; spiegeln = true; }
    const eintrag = sprites[richtung] || {};
    const pfad = (eintrag.frames && eintrag.frames[0]) || eintrag.gif || '';
    return { bild: hole(pfad), spiegeln, richtung };
  }

  function zeichne(jetzt) {
    if (!laeuft) return;
    requestAnimationFrame(zeichne);
    const { charakter, waffe } = lies();
    const t = (jetzt - start) / 1000;

    // Alle 1,1 s eine Richtung weiter, dazwischen ein Schuss.
    const schritt = Math.floor(t / 1.1) % RICHTUNGEN;
    const winkel = (schritt / RICHTUNGEN) * Math.PI * 2;
    const imTakt = (t % 1.1) / 1.1;

    ctx.clearRect(0, 0, canvas.width, canvas.height);
    ctx.imageSmoothingEnabled = false;

    // Boden und Richtungsring
    ctx.save();
    ctx.strokeStyle = 'rgba(108,140,255,.16)';
    ctx.beginPath();
    ctx.arc(MITTE_X, MITTE_Y - 20, 96, 0, Math.PI * 2);
    ctx.stroke();
    ctx.fillStyle = 'rgba(0,0,0,.35)';
    ctx.beginPath();
    ctx.ellipse(MITTE_X, MITTE_Y + 8, 26, 9, 0, 0, Math.PI * 2);
    ctx.fill();
    ctx.restore();

    const hoehe = (charakter && charakter.scale) || 78;
    const fuss = ((charakter && charakter.hitbox) || {}).oy ?? 24;

    // Figur
    const figur = figurBild(charakter, winkel);
    if (figur.bild) {
      const b = (figur.bild.naturalWidth / figur.bild.naturalHeight) * hoehe;
      const x = MITTE_X - b / 2;
      const y = MITTE_Y + fuss * 0.45 - hoehe;
      ctx.save();
      if (charakter && charakter.tint) ctx.filter = `hue-rotate(${charakter.tint}deg) saturate(1.15)`;
      if (figur.spiegeln) {
        ctx.translate(MITTE_X, 0);
        ctx.scale(-1, 1);
        ctx.drawImage(figur.bild, -b / 2, y, b, hoehe);
      } else {
        ctx.drawImage(figur.bild, x, y, b, hoehe);
      }
      ctx.restore();
    }

    // Waffe - dieselbe Rechnung wie im Spiel
    const laenge = (waffe && waffe.spriteScale) || 46;
    const halten = (waffe && waffe.holdDistance) ?? 20;
    const haltenY = (waffe && waffe.holdOffsetY) ?? -6;
    const wbild = hole(waffe && waffe.sprite);
    if (wbild) {
      const h = (wbild.naturalHeight / wbild.naturalWidth) * laenge;
      ctx.save();
      ctx.translate(
        MITTE_X + Math.cos(winkel) * halten,
        MITTE_Y + fuss * 0.1 + haltenY + Math.sin(winkel) * halten * 0.6,
      );
      ctx.rotate(winkel);
      if (Math.abs(winkel) > Math.PI / 2 && Math.abs(winkel) < Math.PI * 1.5) ctx.scale(1, -1);
      ctx.drawImage(wbild, -laenge * 0.3, -h / 2, laenge, h);
      ctx.restore();
    }

    // Mündung: exakt die Formel aus dem Spiel
    const mOffsetY = (waffe && typeof waffe.muzzleOffsetY === 'number') ? waffe.muzzleOffsetY : haltenY;
    const mAbstand = (waffe && waffe.muzzleDistance > 0) ? waffe.muzzleDistance : halten + laenge * 0.5;
    const mx = MITTE_X + Math.cos(winkel) * mAbstand;
    const my = MITTE_Y + fuss * 0.1 + mOffsetY + Math.sin(winkel) * mAbstand * 0.6;

    // Schuss alle 1,1 s neu starten
    if (imTakt < 0.06 && (!schuss.length || schuss[schuss.length - 1].schritt !== schritt)) {
      schuss.push({ schritt, x: mx, y: my, winkel, t: 0 });
      if (schuss.length > 3) schuss.shift();
    }

    // Projektile
    const groesse = (waffe && waffe.projectileSize) || 16;
    const pbild = hole(waffe && waffe.projectile && waffe.projectile !== 'pfeil'
      && waffe.projectile !== 'magic' ? 'assets/sprites/' + waffe.projectile + '.png' : '');
    for (const p of schuss) {
      p.t += 1 / 60;
      const weg = p.t * 210;
      const px = p.x + Math.cos(p.winkel) * weg;
      const py = p.y + Math.sin(p.winkel) * weg * 0.6;
      if (weg > 210) continue;
      ctx.save();
      ctx.globalAlpha = Math.max(0, 1 - weg / 210);
      if (pbild) {
        const w = (pbild.naturalWidth / pbild.naturalHeight) * groesse;
        ctx.translate(px, py);
        ctx.rotate(p.winkel);
        ctx.drawImage(pbild, -w / 2, -groesse / 2, w, groesse);
      } else {
        ctx.fillStyle = '#ffe6a3';
        ctx.beginPath();
        ctx.arc(px, py, groesse / 3, 0, Math.PI * 2);
        ctx.fill();
      }
      ctx.restore();
    }

    // Startpunkt hervorheben
    ctx.save();
    ctx.strokeStyle = '#43d39e';
    ctx.lineWidth = 2;
    ctx.beginPath();
    ctx.arc(mx, my, 5, 0, Math.PI * 2);
    ctx.stroke();
    ctx.fillStyle = 'rgba(67,211,158,.35)';
    ctx.fill();
    ctx.restore();

    // Beschriftung
    ctx.save();
    ctx.fillStyle = '#8b95ad';
    ctx.font = '12px Inter, system-ui, sans-serif';
    ctx.fillText('Richtung ' + (schritt + 1) + ' von ' + RICHTUNGEN, 12, 20);
    ctx.fillText('Starthöhe ' + Math.round(mOffsetY) + ' px · Abstand ' + Math.round(mAbstand) + ' px', 12, 38);
    ctx.restore();
  }

  requestAnimationFrame(zeichne);
  box.stop = () => { laeuft = false; };
  return box;
}

/**
 * Laufvorschau: die Figur laeuft durch alle acht Richtungen.
 *
 * Zeigt Groesse und Spiegelung jeder Richtung so, wie das Spiel sie
 * zeichnet - inklusive der Einzelbild-Animation und des Staubs darunter.
 */
function laufVorschau(lies) {
  const box = el('div', 'field');
  box.appendChild(el('label', null, 'Vorschau: Laufen in alle Richtungen und Stehen'));

  const canvas = el('canvas');
  canvas.width = 460;
  canvas.height = 230;
  canvas.style.cssText = 'width:100%;max-width:460px;background:'
    + 'radial-gradient(120% 90% at 50% 40%, #17222e 0%, #0d1119 70%);'
    + 'border-radius:12px;image-rendering:pixelated;';
  box.appendChild(canvas);
  box.appendChild(el('p', 'muted',
    'Die Figur läuft im Kreis und bleibt danach stehen - dann läuft das Ruhebild. '
    + 'Grösse und Spiegelung je Richtung wirken sofort.'));

  const ctx = canvas.getContext('2d');
  const bilder = new Map();
  const hole = (pfad) => {
    if (!pfad) return null;
    if (!bilder.has(pfad)) {
      const img = new Image();
      img.src = '../' + pfad;
      bilder.set(pfad, img);
    }
    const img = bilder.get(pfad);
    return img.complete && img.naturalWidth ? img : null;
  };

  let laeuft = true;
  const start = performance.now();

  function zeichne(jetzt) {
    if (!laeuft) return;
    requestAnimationFrame(zeichne);
    const daten = lies();
    const sprites = daten.sprites || {};
    const t = (jetzt - start) / 1000;

    ctx.clearRect(0, 0, canvas.width, canvas.height);
    ctx.imageSmoothingEnabled = false;

    // Acht Richtungen, alle 1,1 s eine weiter - danach ein neuntes Feld,
    // in dem die Figur steht und ihr Ruhebild zeigt.
    const schritt = Math.floor(t / 1.1) % 9;
    const steht = schritt === 8;
    const winkel = (schritt / 8) * Math.PI * 2;
    const grad = ((winkel * 180) / Math.PI + 360) % 360;
    let richtung = 'side';
    let spiegeln = false;
    if (grad > 45 && grad < 135) richtung = 'front';
    else if (grad > 225 && grad < 315) richtung = 'back';
    else if (grad >= 135 && grad <= 225) spiegeln = true;
    if (steht) {
      // Ohne eigenes Ruhebild steht die Figur wie bisher nach vorne.
      const idle = sprites.idle || {};
      const hatIdle = !!idle.gif || (idle.frames || []).filter(Boolean).length > 0;
      richtung = hatIdle ? 'idle' : 'front';
      spiegeln = false;
    }

    const eintrag = sprites[richtung] || {};
    const rahmen = (eintrag.frames || []).filter(Boolean);
    const index = rahmen.length ? Math.floor(t * 1000 / (daten.frameDuration || 130)) % rahmen.length : 0;
    const pfad = rahmen.length ? rahmen[index] : eintrag.gif;
    const bild = hole(pfad);
    // Einzelbild-Spiegelung plus Richtungs-Spiegelung plus Laufrichtung.
    const bildSpiegel = rahmen.length ? !!(eintrag.flips || [])[index] : false;
    const gesamtSpiegel = spiegeln !== (!!eintrag.flip !== bildSpiegel);

    const mx = 230;
    const my = 158;
    const hoehe = (daten.scale || 78) * (eintrag.scale || 1);

    // Boden und Laufbahn
    ctx.save();
    ctx.strokeStyle = 'rgba(108,140,255,.14)';
    ctx.beginPath();
    ctx.arc(mx, my - 22, 84, 0, Math.PI * 2);
    ctx.stroke();
    ctx.fillStyle = 'rgba(0,0,0,.35)';
    ctx.beginPath();
    ctx.ellipse(mx, my + 6, 24, 8, 0, 0, Math.PI * 2);
    ctx.fill();
    ctx.restore();

    if (bild) {
      const breite = (bild.naturalWidth / bild.naturalHeight) * hoehe;
      const y = my - hoehe + 8;
      ctx.save();
      if (daten.tint) ctx.filter = `hue-rotate(${daten.tint}deg) saturate(1.15)`;
      if (gesamtSpiegel) {
        ctx.translate(mx, 0);
        ctx.scale(-1, 1);
        ctx.drawImage(bild, -breite / 2, y, breite, hoehe);
      } else {
        ctx.drawImage(bild, mx - breite / 2, y, breite, hoehe);
      }
      ctx.restore();
    }

    // Richtungspfeil - im Stand steht stattdessen nur ein Hinweis da.
    ctx.save();
    if (steht) {
      ctx.fillStyle = '#8b96ad';
      ctx.font = '13px system-ui, sans-serif';
      ctx.textAlign = 'center';
      ctx.fillText('steht still (Ruhebild)', mx, my + 44);
    } else {
      ctx.translate(mx, my - 22);
      ctx.rotate(winkel);
      ctx.fillStyle = '#43d39e';
      ctx.beginPath();
      ctx.moveTo(96, 0);
      ctx.lineTo(80, -7);
      ctx.lineTo(80, 7);
      ctx.closePath();
      ctx.fill();
    }
    ctx.restore();

    ctx.save();
    ctx.fillStyle = '#8b95ad';
    ctx.font = '12px Inter, system-ui, sans-serif';
    ctx.fillText(
      richtung + (gesamtSpiegel ? ' (gespiegelt)' : '')
      + ' · Größe ' + (eintrag.scale || 1).toFixed(2)
      + ' · ' + Math.round(hoehe) + ' px',
      12, 20,
    );
    ctx.fillText('Bild ' + (rahmen.length ? index + 1 + ' von ' + rahmen.length : 'aus GIF'), 12, 38);
    ctx.restore();
  }

  requestAnimationFrame(zeichne);
  box.stop = () => { laeuft = false; };
  return box;
}

/* ----------------------------------------------------- Gegenstände */
const ITEM_EFFECTS = [
  ['heal', 'Heilen (Prozent vom Maximalleben)'],
  ['money', 'Geld (zufällig zwischen Wert und Wert 2)'],
  ['shield', 'Schild auffüllen (Punkte)'],
  ['speed', 'Temposchub (Prozent für Wert 2 Sekunden)'],
  ['magnet', 'Magnet - zieht alles Herumliegende an'],
];

const ITEM_MODES = [
  ['pickup', 'Aufsammeln - fliegt zu und verschwindet sofort'],
  ['chest', 'Truhe - öffnet sich und löst sich danach auf'],
];

function itemEffectText(item) {
  switch (item.effect) {
    case 'heal': return '+' + item.value + ' % Leben';
    case 'money': return item.value + '-' + (item.value2 || item.value) + ' $';
    case 'shield': return '+' + item.value + ' Schild';
    case 'speed': return '+' + item.value + ' % Tempo für ' + (item.value2 || 6) + ' s';
    case 'magnet': return 'Zieht alles an';
    default: return item.effect;
  }
}

views.items = (host) => {
  addAction('Neuer Gegenstand', () => editItem(null));

  const info = el('div', 'card');
  info.appendChild(el('h3', null, 'Was hier passiert'));
  info.appendChild(el('p', 'muted',
    'Alle paar Sekunden würfelt das Spiel je Gegenstand einmal. Fällt der Wurf, erscheint er '
    + 'in Laufweite des Spielers - nie direkt vor den Füßen und nie in einer Wand. '
    + 'Heilende Gegenstände profitieren zusätzlich vom Upgrade "Alchemie" und der Fähigkeit "Magnet".'));
  host.appendChild(info);

  const wrap = el('div', 'tablewrap');
  const table = el('table', 'table');
  table.innerHTML = `<thead><tr>
    <th class="keep">Bild</th><th class="keep">Name</th><th class="keep">Wirkung</th><th>Art</th>
    <th>Alle</th><th>Chance</th><th>Max.</th><th>Lebensdauer</th><th class="keep">Status</th><th class="keep"></th>
  </tr></thead>`;
  const body = el('tbody');

  for (const item of state.content.items || []) {
    const tr = el('tr');
    const bild = el('td', 'keep');
    if (item.sprite) {
      const img = el('img');
      img.src = '../' + item.sprite;
      img.style.cssText = 'width:38px;height:38px;object-fit:contain;image-rendering:pixelated;';
      bild.appendChild(img);
    }
    tr.appendChild(bild);
    tr.appendChild(el('td', 'keep', item.name));
    tr.appendChild(el('td', 'keep', itemEffectText(item)));
    tr.appendChild(el('td', null, item.mode === 'chest' ? 'Truhe' : 'Aufsammeln'));
    tr.appendChild(el('td', null, item.interval + ' s'));
    tr.appendChild(el('td', null, Math.round(item.chance * 100) + ' %'));
    tr.appendChild(el('td', null, String(item.maxOnMap)));
    tr.appendChild(el('td', null, item.lifetime + ' s'));
    const status = el('td', 'keep');
    status.appendChild(el('span', 'badge badge--' + (item.active ? 'common' : 'rare'),
      item.active ? 'aktiv' : 'aus'));
    tr.appendChild(status);
    const actions = el('td', 'actions keep');
    actions.appendChild(actionBtn('Bearbeiten', () => editItem(item)));
    actions.appendChild(actionBtn('Duplizieren', () => duplicate('items', item)));
    actions.appendChild(actionBtn('Löschen', () => removeItem('items', item, item.name), 'btn--danger'));
    tr.appendChild(actions);
    body.appendChild(tr);
  }
  table.appendChild(body);
  wrap.appendChild(table);
  host.appendChild(wrap);

  const hinweis = el('div', 'card');
  hinweis.appendChild(el('h3', null, 'Wie oft erscheint etwas?'));
  const zeilen = el('ul', 'muted');
  for (const item of state.content.items || []) {
    if (!item.active) continue;
    const proMinute = (60 / Math.max(0.5, item.interval)) * item.chance;
    const li = el('li', null,
      `${item.name}: rund ${proMinute.toFixed(1)}x pro Minute, höchstens ${item.maxOnMap} gleichzeitig`);
    zeilen.appendChild(li);
  }
  hinweis.appendChild(zeilen);
  host.appendChild(hinweis);
};

function editItem(item) {
  const isNew = !item;
  const data = item ? { ...item } : {
    id: '', name: 'Neuer Gegenstand', description: '',
    sprite: 'assets/sprites/heiltrank.png', openSprite: '', scale: 34,
    mode: 'pickup', openTime: 2, effect: 'heal', value: 10, value2: 0,
    onlyWhenNeeded: false, interval: 20, chance: 0.3, maxOnMap: 2, lifetime: 26,
    minDistance: 200, maxDistance: 620, particle: '#ffd166', sound: '', active: true,
  };

  const body = el('div', 'form');
  const name = textInput(data.name);
  const desc = el('textarea');
  desc.className = 'input';
  desc.value = data.description || '';
  body.appendChild(field('Name', name));
  body.appendChild(field('Beschreibung', desc));

  let spritePath = data.sprite;
  let openPath = data.openSprite;
  body.appendChild(spriteField('Bild', data.sprite, (p) => { spritePath = p; }));
  body.appendChild(spriteField('Bild geöffnet (nur für Truhen)', data.openSprite, (p) => { openPath = p; }));

  body.appendChild(el('h3', null, 'Wirkung'));
  const effect = selectInput(data.effect, ITEM_EFFECTS.map(([value, label]) => ({ value, label })));
  const mode = selectInput(data.mode, ITEM_MODES.map(([value, label]) => ({ value, label })));
  const value = textInput(data.value, { type: 'number', min: 0, step: 1 });
  const value2 = textInput(data.value2, { type: 'number', min: 0, step: 1 });
  const openTime = textInput(data.openTime ?? 2, { type: 'number', min: 0, step: 0.5 });
  const onlyWhenNeeded = checkInput('Liegen lassen, wenn es gerade nichts bringt', data.onlyWhenNeeded);

  const wirkung = el('div', 'grid2');
  wirkung.appendChild(field('Effekt', effect));
  wirkung.appendChild(field('Art', mode));
  wirkung.appendChild(field('Wert', value));
  wirkung.appendChild(field('Wert 2', value2, 'oberes Ende bei Geld, Dauer bei Tempo'));
  wirkung.appendChild(field('Offen sichtbar (s)', openTime, 'nur für Truhen'));
  body.appendChild(wirkung);
  body.appendChild(onlyWhenNeeded);

  body.appendChild(el('h3', null, 'Wie oft und wo'));
  const interval = textInput(data.interval, { type: 'number', min: 0.5, step: 1 });
  const chance = textInput(Math.round(data.chance * 100), { type: 'number', min: 0, max: 100, step: 5 });
  const maxOnMap = textInput(data.maxOnMap, { type: 'number', min: 0, max: 50, step: 1 });
  const lifetime = textInput(data.lifetime, { type: 'number', min: 2, step: 1 });
  const minDistance = textInput(data.minDistance, { type: 'number', min: 0, step: 20 });
  const maxDistance = textInput(data.maxDistance, { type: 'number', min: 0, step: 20 });
  const scale = textInput(data.scale, { type: 'number', min: 6, max: 400, step: 2 });

  const wann = el('div', 'grid2');
  wann.appendChild(field('Versuch alle (s)', interval));
  wann.appendChild(field('Chance je Versuch (%)', chance));
  wann.appendChild(field('Höchstens gleichzeitig', maxOnMap, '0 schaltet ihn ab'));
  wann.appendChild(field('Verschwindet nach (s)', lifetime));
  wann.appendChild(field('Mindestabstand (px)', minDistance));
  wann.appendChild(field('Höchstabstand (px)', maxDistance));
  wann.appendChild(field('Größe im Spiel (px)', scale));
  body.appendChild(wann);

  const vorschau = el('p', 'muted');
  const syncVorschau = () => {
    const proMinute = (60 / Math.max(0.5, +interval.value || 1)) * ((+chance.value || 0) / 100);
    vorschau.textContent = `Erscheint rund ${proMinute.toFixed(1)}x pro Minute.`;
  };
  interval.addEventListener('input', syncVorschau);
  chance.addEventListener('input', syncVorschau);
  syncVorschau();
  body.appendChild(vorschau);

  body.appendChild(el('h3', null, 'Aussehen und Ton'));
  const particle = textInput(data.particle, { type: 'color' });
  const sounds = [{ value: '', label: '- kein Ton -' }].concat(
    Object.entries(state.content.audio.sounds || {}).map(([id, set]) => ({ value: id, label: set.label || id })),
  );
  const sound = selectInput(data.sound || '', sounds);
  const optik = el('div', 'grid2');
  optik.appendChild(field('Partikelfarbe', particle));
  optik.appendChild(field('Ton beim Einsammeln', sound));
  body.appendChild(optik);

  const active = checkInput('Erscheint im Spiel', data.active);
  body.appendChild(active);

  openModal(isNew ? 'Gegenstand anlegen' : 'Gegenstand bearbeiten', body, [
    { label: 'Abbrechen', class: 'btn--ghost', onClick: (close) => close() },
    {
      label: 'Speichern',
      class: 'btn--primary',
      onClick: async (close) => {
        try {
          await api('put', {
            section: 'items',
            item: {
              ...data, id: data.id || slug(name.value), name: name.value,
              description: desc.value, sprite: spritePath, openSprite: openPath,
              scale: +scale.value, mode: mode.value, openTime: +openTime.value,
              effect: effect.value, value: +value.value, value2: +value2.value,
              onlyWhenNeeded: onlyWhenNeeded.input.checked,
              interval: +interval.value, chance: (+chance.value || 0) / 100,
              maxOnMap: +maxOnMap.value, lifetime: +lifetime.value,
              minDistance: +minDistance.value, maxDistance: +maxDistance.value,
              particle: particle.value, sound: sound.value, active: active.input.checked,
            },
          });
          close();
          toast('Gespeichert');
          setView('items');
        } catch (e) { toast(e.message, 'error'); }
      },
    },
  ], true);
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
  ['burnDuration', 'Brenndauer (s)', 0.2, 0.1],
  ['potionInterval', 'Heilflasche: Versuch alle (s)', 1, 1],
  ['potionChance', 'Heilflasche: Chance je Versuch (0-1)', 0, 0.05],
  ['potionMax', 'Heilflaschen gleichzeitig', 0, 1],
  ['potionHeal', 'Heilflasche heilt (%)', 1, 1],
  ['potionLifetime', 'Heilflasche verschwindet nach (s)', 3, 1],
  ['ultCooldown', 'Ultimate: Abklingzeit (s)', 1, 1],
  ['ultRadius', 'Ultimate: Radius (px)', 20, 10],
  ['ultKnockback', 'Ultimate: Rückstoß', 0, 50],
  ['ultDamage', 'Ultimate: Schaden', 0, 5],
  ['waveMixShare', 'Anteil älterer Gegner (0-1)', 0, 0.05],
  ['waveStartEnemies', 'Gegner zum Wellenstart', 0, 1],
  ['upgradeChoices', 'Upgrade-Karten pro Welle', 1, 1],
  ['rarityRareBase', 'Chance Rare (%)', 0, 1],
  ['rarityEpicBase', 'Chance Epic (%)', 0, 1],
  ['rarityLegendaryBase', 'Chance Legendary (%)', 0, 1],
  ['rarityCycleBonus', 'Seltenheitsbonus je Zyklus', 1, 0.05],
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
  help.appendChild(el('p', 'muted',
    'Wellen bauen aufeinander auf: Der Gegner der aktuellen Welle führt, bereits bekannte Gegner ' +
    'kommen mit ' + Math.round((b.waveMixShare ?? 0.45) * 100) + ' % ihres Gewichts dazu. Ab Zyklus 2 ' +
    'mischt jede Welle alle Typen – sonst würde nach dem Boss wieder nur der schwächste Gegner kommen. ' +
    'Zum Wellenstart stehen ' + (b.waveStartEnemies ?? 4) + ' Gegner bereit, damit es nach der ' +
    'Upgrade-Auswahl ohne Leerlauf weitergeht.'));
  help.appendChild(el('p', 'muted',
    'Heilflaschen und Truhen stehen unter "Gegenstände".'));
  help.appendChild(el('p', 'muted',
    'Verbrennung: Getroffene Gegner brennen ' + (b.burnDuration ?? 3) + ' s lang. Der Feuerschaden kommt ' +
    'aus den Upgrades "Verbrennung" und "Inferno" und wird mit dem Schadensfaktor des Runs multipliziert.'));
  host.appendChild(help);
};

/* ------------------------------------------------------- Laden & Aussehen */
const SHOP_FIELDS = [
  ['offerCount', 'Auslagen im Laden', 1, 1],
  ['priceBase', 'Grundpreis', 1, 1],
  ['priceCommon', 'Faktor Gewöhnlich', 0.1, 0.1],
  ['priceRare', 'Faktor Selten', 0.1, 0.1],
  ['priceEpic', 'Faktor Episch', 0.1, 0.1],
  ['priceLegendary', 'Faktor Legendär', 0.1, 0.1],
  ['priceWeapon', 'Faktor Waffe', 0.1, 0.1],
  ['priceCycleBonus', 'Aufschlag je Zyklus', 0, 0.05],
  ['priceStackBonus', 'Aufschlag je vorhandener Stufe', 0, 0.05],
  ['rerollCost', 'Tausch kostet', 0, 1],
  ['rerollGrowth', 'Tausch wird teurer (Faktor)', 1, 0.1],
  ['weaponChance', 'Chance auf Waffe (0-1)', 0, 0.05],
  ['lockLimit', 'Wie viele man merken darf', 0, 1],
];

const SHOP_LAYOUT = [
  ['merchantX', 'Händler: Position von links (%)', 0, 1],
  ['merchantY', 'Händler: Position von oben (%)', 0, 1],
  ['merchantScale', 'Händler: Größe (%)', 20, 5],
  ['merchantFrameDuration', 'Händler: Bildwechsel (ms)', 40, 10],
  ['counterY', 'Tresen: Unterkante (%)', 0, 1],
  ['counterScale', 'Tresen: Größe (%)', 20, 5],
];

const UI_LAYOUT = [
  ['charX', 'Figur: Position von links (%)', 0, 1],
  ['charY', 'Figur: Standfläche von oben (%)', 0, 1],
  ['charScale', 'Figur: Größe (%)', 20, 5],
];

views.shop = (host) => {
  const shop = JSON.parse(JSON.stringify(state.content.shop || {}));
  const ui = JSON.parse(JSON.stringify(state.content.ui || {}));

  /* --- Bilder des Ladens --------------------------------------------- */
  const bilder = el('div', 'card');
  bilder.appendChild(el('h3', null, 'Laden: Bilder'));
  bilder.appendChild(el('p', 'muted',
    'Drei Ebenen: Hintergrund ganz hinten, der Händler davor, der Tresen als '
    + 'oberste Ebene vor dem Händler.'));

  const an = checkInput('Laden nach der Upgrade-Auswahl öffnen', shop.enabled !== false);
  an.input.addEventListener('change', () => { shop.enabled = an.input.checked; });
  bilder.appendChild(an);

  const titel = textInput(shop.title || '');
  titel.addEventListener('input', () => { shop.title = titel.value; });
  bilder.appendChild(field('Überschrift', titel));

  bilder.appendChild(spriteField('Hintergrund', shop.background, (p) => { shop.background = p; }));
  bilder.appendChild(spriteField('Tresen (vor dem Händler)', shop.counter, (p) => { shop.counter = p; }));

  // Fuenf Bilder fuer das Ruhebild des Haendlers - derselbe Aufbau wie bei
  // den Charakteren, damit man es nicht neu lernen muss.
  shop.merchantFrames = Array.isArray(shop.merchantFrames) ? shop.merchantFrames : [];
  const frames = el('div', 'framerow');
  const zeichneFrames = () => {
    frames.textContent = '';
    for (let i = 0; i < 5; i++) {
      const slot = el('div', 'frameslot');
      const pfad = shop.merchantFrames[i];
      if (pfad) {
        const img = el('img');
        img.src = '../' + pfad;
        img.alt = '';
        slot.appendChild(img);
        const weg = el('button', 'frameslot__x', '✕');
        weg.title = 'Bild entfernen';
        weg.addEventListener('click', () => {
          shop.merchantFrames.splice(i, 1);
          zeichneFrames();
          zeichneVorschau();
        });
        slot.appendChild(weg);
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
          shop.merchantFrames[i] = data.path;
          shop.merchantFrames = shop.merchantFrames.filter(Boolean);
          zeichneFrames();
          zeichneVorschau();
          toast('Bild ' + (i + 1) + ' gesetzt');
        } catch (err) { toast(err.message, 'error'); }
      });
      slot.appendChild(upload);
      frames.appendChild(slot);
    }
  };
  bilder.appendChild(el('div', 'muted', 'Händler: bis zu fünf Bilder für das Ruhebild.'));
  bilder.appendChild(frames);
  host.appendChild(bilder);

  /* --- Anordnung mit Live-Vorschau ------------------------------------ */
  const anordnung = el('div', 'card');
  anordnung.appendChild(el('h3', null, 'Laden: Anordnung'));
  const vorschau = el('div');
  vorschau.style.cssText = 'position:relative;width:100%;max-width:520px;aspect-ratio:3/2;'
    + 'overflow:hidden;border-radius:12px;background:#0d1119;';
  const vHintergrund = el('img');
  vHintergrund.style.cssText = 'position:absolute;inset:0;width:100%;height:100%;object-fit:cover;';
  const vHaendler = el('img');
  vHaendler.style.cssText = 'position:absolute;width:26%;image-rendering:pixelated;';
  const vTresen = el('img');
  vTresen.style.cssText = 'position:absolute;left:50%;width:118%;image-rendering:pixelated;';
  const vAuslage = el('div');
  vAuslage.style.cssText = 'position:absolute;left:4%;top:12%;width:56%;height:70%;'
    + 'border:2px dashed rgba(108,140,255,.55);border-radius:8px;'
    + 'display:grid;place-items:center;color:#9fb0d4;font-size:12px;';
  vAuslage.textContent = 'Auslagen';
  vorschau.append(vHintergrund, vHaendler, vTresen, vAuslage);
  anordnung.appendChild(vorschau);
  anordnung.appendChild(el('p', 'muted',
    'Die gestrichelte Fläche zeigt, wo die Angebote liegen. Der Händler gehört nach rechts.'));

  const shopInputs = {};
  const gitter = el('div', 'grid2');
  for (const [key, label, min, step] of SHOP_LAYOUT) {
    shopInputs[key] = textInput(shop[key], { type: 'number', min, step });
    shopInputs[key].addEventListener('input', () => {
      shop[key] = +shopInputs[key].value;
      zeichneVorschau();
    });
    gitter.appendChild(field(label, shopInputs[key]));
  }
  anordnung.appendChild(gitter);
  host.appendChild(anordnung);

  function zeichneVorschau() {
    vHintergrund.src = shop.background ? '../' + shop.background : '';
    const erstes = (shop.merchantFrames || []).filter(Boolean)[0] || '';
    vHaendler.src = erstes ? '../' + erstes : '';
    vHaendler.style.left = (shop.merchantX ?? 76) + '%';
    vHaendler.style.top = (shop.merchantY ?? 62) + '%';
    vHaendler.style.transform =
      `translate(-50%, -50%) scale(${(shop.merchantScale ?? 100) / 100})`;
    vTresen.src = shop.counter ? '../' + shop.counter : '';
    vTresen.style.top = (shop.counterY ?? 100) + '%';
    vTresen.style.transform =
      `translate(-50%, -100%) scale(${(shop.counterScale ?? 100) / 100})`;
  }
  zeichneFrames();
  zeichneVorschau();

  /* --- Preise ---------------------------------------------------------- */
  const preise = el('div', 'card');
  preise.appendChild(el('h3', null, 'Laden: Preise'));
  preise.appendChild(el('p', 'muted',
    'Preis = Grundpreis × Faktor der Seltenheit × (1 + Aufschlag je Zyklus × (Zyklus−1)) '
    + '× (1 + Aufschlag je vorhandener Stufe × bereits gekaufte Stufen). Besser ist so '
    + 'automatisch teurer.'));
  const preisGitter = el('div', 'grid2');
  const preisInputs = {};
  for (const [key, label, min, step] of SHOP_FIELDS) {
    preisInputs[key] = textInput(shop[key], { type: 'number', min, step });
    preisGitter.appendChild(field(label, preisInputs[key]));
  }
  preise.appendChild(preisGitter);
  host.appendChild(preise);

  /* --- Menue und Charakterauswahl -------------------------------------- */
  const aussehen = el('div', 'card');
  aussehen.appendChild(el('h3', null, 'Menü & Charakterauswahl'));
  aussehen.appendChild(spriteField('Hintergrund Hauptmenü', ui.menuBackground,
    (p) => { ui.menuBackground = p; }));
  aussehen.appendChild(spriteField('Hintergrund Charakterauswahl', ui.charBackground,
    (p) => { ui.charBackground = p; zeichneCharVorschau(); }));

  const charVorschau = el('div');
  charVorschau.style.cssText = 'position:relative;width:100%;max-width:520px;aspect-ratio:3/2;'
    + 'overflow:hidden;border-radius:12px;background:#0d1119;';
  const cHintergrund = el('img');
  cHintergrund.style.cssText = 'position:absolute;inset:0;width:100%;height:100%;object-fit:cover;';
  const cFigur = el('img');
  cFigur.style.cssText = 'position:absolute;height:32%;image-rendering:pixelated;';
  charVorschau.append(cHintergrund, cFigur);
  aussehen.appendChild(charVorschau);
  aussehen.appendChild(el('p', 'muted',
    'Die Figur steht auf dem angegebenen Punkt - der Punkt ist ihre Standfläche, '
    + 'nicht ihre Mitte.'));

  const uiInputs = {};
  const uiGitter = el('div', 'grid2');
  for (const [key, label, min, step] of UI_LAYOUT) {
    uiInputs[key] = textInput(ui[key], { type: 'number', min, step });
    uiInputs[key].addEventListener('input', () => {
      ui[key] = +uiInputs[key].value;
      zeichneCharVorschau();
    });
    uiGitter.appendChild(field(label, uiInputs[key]));
  }
  aussehen.appendChild(uiGitter);

  function zeichneCharVorschau() {
    cHintergrund.src = ui.charBackground ? '../' + ui.charBackground : '';
    const erster = (state.content.characters || []).find((c) => c.active !== false);
    const sp = (erster && erster.sprites) || {};
    const idle = sp.idle || {};
    const front = sp.front || {};
    const pfad = (idle.frames || [])[0] || idle.gif || (front.frames || [])[0] || front.gif || '';
    cFigur.src = pfad ? '../' + pfad : '';
    cFigur.style.left = (ui.charX ?? 50) + '%';
    cFigur.style.top = (ui.charY ?? 63) + '%';
    cFigur.style.transform = `translate(-50%, -100%) scale(${(ui.charScale ?? 100) / 100})`;
  }
  zeichneCharVorschau();
  host.appendChild(aussehen);

  /* --- Speichern -------------------------------------------------------- */
  const speichern = el('button', 'btn btn--primary', 'Laden & Aussehen speichern');
  speichern.addEventListener('click', async () => {
    for (const [key] of SHOP_FIELDS) shop[key] = +preisInputs[key].value;
    for (const [key] of SHOP_LAYOUT) shop[key] = +shopInputs[key].value;
    for (const [key] of UI_LAYOUT) ui[key] = +uiInputs[key].value;
    shop.merchantFrames = (shop.merchantFrames || []).filter(Boolean);
    try {
      await api('settings', { shop, ui });
      toast('Gespeichert');
      setView('shop');
    } catch (e) { toast(e.message, 'error'); }
  });
  const fuss = el('div', 'card');
  fuss.appendChild(speichern);
  host.appendChild(fuss);
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
    tr.appendChild(el('td', null,
      (character.title || '-') + ((PERK_INFO[character.perk] && character.perk)
        ? ' · ' + PERK_INFO[character.perk].label : '')));
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

/* Fähigkeiten und Werte kommen vom Server, damit Spiel und Admin dasselbe kennen. */
const PERK_INFO = window.ARENA_PERKS || { '': { label: 'Keine', description: '' } };
const PERKS = Object.entries(PERK_INFO).map(([id, info]) => [id, info.label]);

const MOD_FIELDS = [
  ['maxHealth', 'Leben (Faktor)', 0.05, '1.30 = dreißig Prozent mehr Leben'],
  ['moveSpeed', 'Tempo (Faktor)', 0.02, ''],
  ['damageMult', 'Schaden (Faktor)', 0.02, ''],
  ['attackSpeed', 'Angriffstempo (Faktor)', 0.02, ''],
  ['range', 'Reichweite (Faktor)', 0.05, ''],
  ['projectileSpeed', 'Projektiltempo (Faktor)', 0.05, ''],
  ['knockback', 'Rückstoß (Faktor)', 0.05, ''],
  ['pickupRange', 'Aufsammelreichweite (Faktor)', 0.1, 'wie weit Heilflaschen zufliegen'],
  ['potionRate', 'Heilflaschen-Chance (Faktor)', 0.1, ''],
  ['money', 'Geld (Faktor)', 0.1, ''],
  ['armor', 'Rüstung (+)', 1, 'zieht von jedem Treffer ab'],
  ['critChance', 'Krit-Chance (+%)', 1, ''],
  ['critDamage', 'Krit-Schaden (+%)', 5, ''],
  ['dodge', 'Ausweichen (+%)', 1, 'Chance, einen Treffer ganz zu vermeiden'],
  ['regen', 'Regeneration (+HP/s)', 0.1, ''],
  ['shield', 'Startschild (+)', 5, 'fängt Schaden vor dem Leben ab'],
  ['burn', 'Feuerschaden (+HP/s)', 1, 'Gegner brennen auch ohne Upgrade'],
];

/** Standardwert je Feld: Faktoren 1, Zuschläge 0 - kommt aus den Servergrenzen. */
const MOD_DEFAULT = (() => {
  const limits = window.ARENA_MODS || {};
  const out = {};
  for (const [key] of MOD_FIELDS) out[key] = limits[key] ? limits[key][2] : 0;
  return out;
})();

function defaultMods() {
  return { ...MOD_DEFAULT };
}

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
    mods: defaultMods(),
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
  for (const [dir, label] of [
    ['front', 'Nach vorne (unten)'], ['back', 'Nach hinten (oben)'], ['side', 'Seitlich'],
    ['idle', 'Ruhebild (Stehen)'],
  ]) {
    body.appendChild(directionEditor(dir, label, spriteState));
  }

  body.appendChild(laufVorschau(() => ({
    sprites: spriteState,
    scale: +scale.value || 78,
    tint: +tint.value || 0,
    frameDuration: +frameDuration.value || 130,
  })));

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
  body.appendChild(el('h3', null, 'Spezialfähigkeit'));
  const perk = selectInput(data.perk || '', PERKS.map(([value, label]) => ({ value, label })));
  body.appendChild(field('Sonderfähigkeit', perk));
  const perkNote = el('p', 'muted');
  const syncPerkNote = () => {
    perkNote.textContent = (PERK_INFO[perk.value] || {}).description || '';
  };
  perk.addEventListener('change', syncPerkNote);
  syncPerkNote();
  body.appendChild(perkNote);

  body.appendChild(el('h3', null, 'Werte'));
  body.appendChild(el('p', 'muted',
    'Faktoren rechnen mit den Grundwerten des Spielers: 1.00 ist unverändert, '
    + '1.20 sind zwanzig Prozent mehr. Zuschläge kommen oben drauf.'));

  const modFields = {};
  const modGrid = el('div', 'grid2');
  for (const [key, label, step, hint] of MOD_FIELDS) {
    const fallback = MOD_DEFAULT[key];
    modFields[key] = textInput(data.mods?.[key] ?? fallback, { type: 'number', step });
    modGrid.appendChild(field(label, modFields[key], hint));
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

  /* --- Vorschau: die Figur schiesst in alle Richtungen ----------------- */
  const waffen = (state.content.weapons || []).filter((w) => w.active);
  const waffenWahl = selectInput(
    (waffen.find((w) => w.starter) || waffen[0] || {}).id || '',
    waffen.map((w) => ({ value: w.id, label: w.name })),
  );
  body.appendChild(field('Waffe für die Vorschau', waffenWahl));
  body.appendChild(schussVorschau(() => ({
    charakter: {
      sprites: spriteState,
      scale: +scale.value || 78,
      tint: +tint.value || 0,
      hitbox: { oy: +oy.value || 24 },
    },
    waffe: waffen.find((w) => w.id === waffenWahl.value) || waffen[0] || null,
  })));

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
        const mods = defaultMods();
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
  entry.flips = Array.isArray(entry.flips) ? entry.flips : [];
  if (typeof entry.scale !== 'number') entry.scale = 1;
  if (typeof entry.flip !== 'boolean') entry.flip = false;

  box.appendChild(spriteField('Animiertes GIF (optional)', entry.gif, (p) => { entry.gif = p; }));

  // Groesse nur dieser Richtung - die Hitbox bleibt davon unberuehrt.
  const zeile = el('div', 'grid2');
  const groesse = textInput(entry.scale, { type: 'number', min: 0.2, max: 4, step: 0.05 });
  groesse.addEventListener('input', () => { entry.scale = +groesse.value || 1; });
  zeile.appendChild(field('Größe dieser Richtung', groesse, '1.00 = unverändert, nur das Bild'));
  const spiegeln = checkInput('Ganze Richtung spiegeln', entry.flip);
  spiegeln.input.addEventListener('change', () => { entry.flip = spiegeln.input.checked; });
  zeile.appendChild(spiegeln);
  box.appendChild(zeile);

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
        if (entry.flips[i]) img.style.transform = 'scaleX(-1)';
        slot.appendChild(img);
        const remove = el('button', 'frameslot__x', '✕');
        remove.title = 'Bild entfernen';
        remove.addEventListener('click', () => {
          entry.frames.splice(i, 1);
          entry.flips.splice(i, 1);
          render();
        });
        slot.appendChild(remove);

        // Einzelnes Bild spiegeln - fuer Sprites, die falsch herum sind.
        const drehen = el('button', 'frameslot__flip', '⇋');
        drehen.title = 'Dieses Bild spiegeln';
        if (entry.flips[i]) drehen.classList.add('is-active');
        drehen.addEventListener('click', () => {
          entry.flips[i] = !entry.flips[i];
          render();
        });
        slot.appendChild(drehen);
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
          entry.flips[i] = entry.flips[i] || false;
          // Luecken schliessen, Spiegelungen mitziehen.
          const paare = entry.frames
            .map((pfad, k) => [pfad, entry.flips[k] || false])
            .filter(([pfad]) => !!pfad);
          entry.frames = paare.map(([pfad]) => pfad);
          entry.flips = paare.map(([, f]) => f);
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

  /**
   * Ein Musikfeld mit Vorhoeren und Upload.
   * Zwei davon: einer fuer die Menues, einer fuer den Kampf.
   */
  function musikFeld(label, hinweis, startwert) {
    let pfad = startwert || '';
    const row = el('div', 'field');
    row.appendChild(el('label', null, label));
    if (hinweis) row.appendChild(el('small', null, hinweis));

    const name = el('div', 'muted', pfad || 'kein Titel gesetzt');
    const preview = el('audio');
    preview.controls = true;
    preview.preload = 'none';
    preview.style.cssText = 'width:100%;max-width:420px;';
    if (pfad) preview.src = '../' + pfad;

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
        const daten = new FormData();
        daten.append('file', fileInput.files[0]);
        daten.append('kind', 'audio');
        daten.append('name', fileInput.files[0].name.replace(/\.[^.]+$/, ''));
        daten.append('csrf', state.csrf);
        const res = await fetch('../api.php?action=upload', {
          method: 'POST', headers: { 'X-CSRF': state.csrf }, body: daten,
        });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'Upload fehlgeschlagen');
        pfad = data.path;
        name.textContent = pfad;
        preview.src = '../' + pfad;
        toast('Musik hochgeladen');
      } catch (err) {
        toast(err.message, 'error');
      }
      upload.textContent = 'Musik hochladen (MP3, OGG, WAV, M4A)';
      upload.appendChild(fileInput);
      fileInput.value = '';
    });

    const leeren = el('button', 'btn btn--sm btn--ghost', 'Titel entfernen');
    leeren.addEventListener('click', () => {
      pfad = '';
      name.textContent = 'kein Titel gesetzt';
      preview.removeAttribute('src');
    });

    row.appendChild(name);
    row.appendChild(preview);
    const knoepfe = el('div', 'actions');
    knoepfe.appendChild(upload);
    knoepfe.appendChild(leeren);
    row.appendChild(knoepfe);
    row.read = () => pfad;
    return row;
  }

  const menuFeld = musikFeld('Musik im Menü',
    'Läuft im Hauptmenü, in der Auswahl und auf allen Bildschirmen ausserhalb des Kampfes.',
    a.musicMenu || '');
  const kampfFeld = musikFeld('Musik im Kampf',
    'Läuft nur während einer laufenden Runde. Im Menü ist sie aus.',
    a.musicTrack || '');
  form.appendChild(menuFeld);
  form.appendChild(kampfFeld);

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
          musicTrack: kampfFeld.read(),
          musicMenu: menuFeld.read(),
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
          musicTrack: kampfFeld.read(),
          musicMenu: menuFeld.read(),
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
