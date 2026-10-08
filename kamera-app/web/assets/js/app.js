// Kamera-Link – Steuerung der Oberfläche.

import { bridge, Ccapi, camUrl } from './api.js';
import { storageEstimate } from './db.js';
import { Gallery } from './gallery.js';
import { Live } from './live.js';
import { Queue } from './queue.js';
import { downloadBlob, downloadZip, nativeSave, saveMode, SAVE_TEXT, shareFiles } from './save.js';
import { $, $$, fetchT, fmtBytes, h, icon, lsGet, lsSet, sleep, toast } from './util.js';
import { Viewer } from './viewer.js';

const MODE = document.documentElement.dataset.mode === 'hosted' ? 'hosted' : 'bridge';
const settings = {
  get: (k) => lsGet(`kl.${k}`, null),
  set: (k, v) => lsSet(`kl.${k}`, v),
};

if (MODE === 'hosted') initHosted();
else initBridge();

// =========================================================================
// Web-Version auf dem Hosting: kann die Kamera nicht erreichen – öffnet die Brücke.
// =========================================================================
function initHosted() {
  document.body.classList.add('mode-hosted');
  showView('start');
  setPill('web', 'Web-Version');
  if ('serviceWorker' in navigator) navigator.serviceWorker.register('sw.js').catch(() => {});

  const input = $('#bridge-addr');
  const secure = $('#bridge-https');
  const recents = settings.get('recentBridges') || [];
  input.value = recents[0]?.addr || '';
  secure.checked = !!recents[0]?.https;
  const renderRecents = () => {
    const list = settings.get('recentBridges') || [];
    $('#bridge-recents').replaceChildren(...list.map((r) => h('a', {
      class: 'chip link', href: bridgeUrl(r.addr, r.https),
    }, `${r.https ? '🔒 ' : ''}${r.addr}`)));
  };
  renderRecents();
  $('#bridge-form').addEventListener('submit', (e) => {
    e.preventDefault();
    let addr = input.value.trim().replace(/^https?:\/\//, '').replace(/\/.*$/, '');
    if (!addr) return toast('Adresse der Brücke eingeben', 'err');
    if (!/:\d+$/.test(addr)) addr += secure.checked ? ':8766' : ':8765';
    const list = [{ addr, https: secure.checked }, ...recents.filter((r) => r.addr !== addr)].slice(0, 4);
    settings.set('recentBridges', list);
    location.href = bridgeUrl(addr, secure.checked);
  });

  $('#direct-test').addEventListener('click', async () => {
    const out = $('#direct-result');
    const ip = ($('#direct-ip').value.trim() || '192.168.1.2:8080').replace(/^https?:\/\//, '');
    out.className = 'result';
    out.textContent = 'Teste …';
    const t0 = performance.now();
    try {
      const res = await fetchT(`http://${ip}/ccapi`, { mode: 'cors', cache: 'no-store' }, 5000);
      out.className = 'result ok';
      out.textContent = `Unerwartet: Die Kamera antwortet direkt (HTTP ${res.status}). Bitte melden – dann ginge es ohne Brücke.`;
    } catch {
      const ms = performance.now() - t0;
      out.className = 'result err';
      out.textContent = ms < 4500
        ? `Vom Browser blockiert (nach ${Math.round(ms)} ms): Eine HTTPS-Seite darf die Kamera (nur HTTP, ohne CORS-Freigabe) nicht lesen. Genau dafür gibt es die Brücke.`
        : 'Keine Antwort: Kamera nicht im selben Netz oder CCAPI nicht verbunden.';
    }
  });
}

function bridgeUrl(addr, https) {
  return `${https ? 'https' : 'http'}://${addr}/`;
}

// =========================================================================
// Über die Brücke (oder native Hülle): voller Funktionsumfang
// =========================================================================
async function initBridge() {
  document.body.classList.add('mode-bridge');
  const queue = new Queue();
  await queue.load().catch(() => toast('Lokaler Speicher nicht verfügbar', 'err'));
  const mode = saveMode();
  if (mode === 'native') document.body.classList.add('is-native'); // ZIP gibt es nur über die Brücke
  const saveText = { ...SAVE_TEXT[mode], mode };

  const state = { status: null, ccapi: null, features: {}, loading: false, loadedFor: '' };
  const app = {
    settings, queue,
    get ccapi() { return state.ccapi; },
    features: () => state.features,
    currentView: () => document.body.dataset.view,
    openViewer: (f, list) => viewer.open(f, list),
    enqueue: (files, opts) => enqueue(files, opts),
  };

  const gallery = new Gallery({
    root: $('#grid'), ccapi: null, queue,
    onOpen: (f, list) => viewer.open(f, list),
    onSelect: (sel, on) => renderSelectBar(sel, on),
  });
  app.gallery = gallery;
  const viewer = new Viewer({
    el: $('#viewer'), gallery, queue, saveMode: saveText,
    onEnqueue: (files) => enqueue(files), onSave: (items) => saveItems(items),
  });
  const live = new Live({ root: $('#view-live'), app });

  // ---- Navigation -------------------------------------------------------
  const views = { galerie: 'gallery', live: 'live', downloads: 'queue', verbindung: 'connect' };
  const fromHash = () => views[location.hash.slice(1)] || 'gallery';
  for (const b of $$('.tabbar [data-go]')) b.addEventListener('click', () => (location.hash = b.dataset.go));
  window.addEventListener('hashchange', () => showView(fromHash()));
  showView(fromHash());
  $('#pill').addEventListener('click', () => (location.hash = 'verbindung'));

  // ---- Galerie-Werkzeuge ------------------------------------------------
  for (const b of $$('#filters [data-filter]')) {
    b.addEventListener('click', () => {
      for (const o of $$('#filters [data-filter]')) o.classList.toggle('on', o === b);
      gallery.setFilter(b.dataset.filter);
      updateCounts();
    });
  }
  $('#view-toggle').addEventListener('click', () => {
    const v = gallery.view === 'grid' ? 'list' : 'grid';
    gallery.setView(v);
    settings.set('galleryView', v);
    $('#view-toggle').innerHTML = icon(v === 'grid' ? 'list' : 'grid');
  });
  if (settings.get('galleryView') === 'list') $('#view-toggle').click();
  $('#select-toggle').addEventListener('click', () => gallery.setSelecting(!gallery.selecting));
  $('#reload').addEventListener('click', () => loadGallery(true));
  $('#sel-all').addEventListener('click', () => gallery.selectAll());
  $('#sel-cancel').addEventListener('click', () => gallery.setSelecting(false));
  $('#sel-load').addEventListener('click', () => {
    const files = gallery.files.filter((f) => gallery.selected.has(f.path));
    enqueue(files);
    gallery.setSelecting(false);
  });
  $('#sel-zip').addEventListener('click', () => {
    const paths = gallery.files.filter((f) => gallery.selected.has(f.path)).map((f) => f.path);
    if (!paths.length) return;
    downloadZip(paths);
    toast('ZIP wird erstellt – Safari fragt gleich nach dem Laden.');
  });
  $('#load-all').addEventListener('click', () => {
    const files = gallery.visible();
    if (!files.length) return;
    confirmSheet(`${files.length} Dateien laden?`, 'Bereits übertragene werden übersprungen. Die Übertragung läuft, solange Safari offen bleibt.', 'Alle laden', () => enqueue(files));
  });

  function renderSelectBar(sel, on) {
    document.body.classList.toggle('selecting', on);
    $('#sel-count').textContent = `${sel.size} gewählt`;
    $('#sel-load').disabled = !sel.size;
    $('#sel-zip').disabled = !sel.size;
    $('#select-toggle').classList.toggle('on', on);
  }

  function updateCounts() {
    const c = gallery.counts();
    for (const b of $$('#filters [data-filter]')) b.querySelector('em').textContent = c[b.dataset.filter] ?? '';
    $('#gallery-sub').textContent = c.all ? `${c.all} Dateien` : '';
  }

  // ---- Verbindung -------------------------------------------------------
  let lastOk = false;
  async function refreshStatus() {
    let st;
    try {
      st = await bridge.status();
    } catch {
      state.status = null;
      setPill('down', 'Brücke getrennt');
      renderConnect(null);
      lastOk = false;
      return false;
    }
    state.status = st;
    const cam = st.camera;
    renderConnect(st);
    if (cam.state === 'ok') {
      const dev = cam.device || {};
      state.features = st.features || {};
      if (!state.ccapi) {
        state.ccapi = new Ccapi(state.features);
        gallery.setCcapi(state.ccapi);
      } else state.ccapi.f = state.features;
      const camId = dev.serialnumber || dev.guid || cam.address;
      if (queue.camId !== camId) { queue.camId = camId; renderQueue(); }
      setPill('ok', shortName(dev.productname) || 'Kamera verbunden');
      if (!lastOk) {
        queue.kick();
        if (state.loadedFor !== camId) loadGallery();
        updateBattery();
      }
      lastOk = true;
      return true;
    }
    lastOk = false;
    setPill('warn', cam.state === 'not_ccapi' ? 'CCAPI nicht aktiv' : cam.state === 'no_address' ? 'Keine Kamera' : 'Kamera nicht erreichbar');
    return false;
  }

  async function updateBattery() {
    const b = await state.ccapi?.battery();
    const map = { full: 'voll', high: 'hoch', half: 'halb', quarter: 'niedrig', low: 'leer', unknown: '?' };
    const lvl = b?.level || b?.batterylist?.[0]?.level;
    $('#battery').textContent = lvl ? `Akku ${map[lvl] || lvl}` : '';
  }

  (async function statusLoop() {
    for (;;) {
      const ok = await refreshStatus();
      await sleep(document.visibilityState === 'hidden' ? 15000 : ok ? 5000 : 2500);
    }
  })();
  setInterval(() => { if (lastOk) updateBattery(); }, 60000);

  async function loadGallery(force = false) {
    if (!state.ccapi || state.loading) return;
    state.loading = true;
    $('#reload').classList.add('spin');
    if (!gallery.files.length || force) $('#grid-state').innerHTML = `<div class="skeleton">${'<i></i>'.repeat(18)}</div>`;
    try {
      const paths = await state.ccapi.listAll((n) => ($('#gallery-sub').textContent = `${n} Dateien gefunden …`));
      gallery.setPaths(paths);
      state.loadedFor = queue.camId;
      $('#grid-state').innerHTML = paths.length ? '' : `<div class="empty">${icon('image', 'big')}<p>Keine Bilder auf der Karte.</p></div>`;
      updateCounts();
    } catch (e) {
      $('#grid-state').innerHTML = `<div class="empty">${icon('alert', 'big')}<p>Liste konnte nicht geladen werden: ${escapeHtml(e.message)}</p></div>`;
    } finally {
      state.loading = false;
      $('#reload').classList.remove('spin');
    }
  }

  function renderConnect(st) {
    const steps = $('#conn-steps');
    const cam = st?.camera;
    const row = (ok, title, text) => h('li', { class: ok === true ? 'ok' : ok === false ? 'bad' : 'wait' },
      h('span', { class: 'dot', html: icon(ok === true ? 'check' : ok === false ? 'x' : 'dots') }),
      h('div', {}, h('b', {}, title), text ? h('small', {}, text) : null));
    steps.replaceChildren(
      row(!!st, 'Kamera-Brücke', st ? `Version ${st.bridge.version}${st.bridge.secure ? ' · sichere Verbindung' : ''}` : 'Nicht erreichbar. Läuft das Programm auf dem Computer? Selbes WLAN?'),
      row(cam ? cam.state !== 'unreachable' && cam.state !== 'no_address' : null, 'Kamera im Netzwerk',
        !cam ? '' : cam.state === 'no_address' ? 'Noch keine Adresse – unten suchen oder eintragen.' : cam.state === 'unreachable' ? `${cam.address}: keine Antwort. Kamera an, WLAN-Verbindung aktiv?` : cam.address),
      row(cam ? cam.state === 'ok' : null, 'CCAPI aktiv',
        !cam ? '' : cam.state === 'ok' ? `${cam.device?.productname || 'Kamera'} · Firmware ${cam.device?.firmwareversion || '?'} · ${cam.versions.join(', ')}` : cam.message || ''),
    );
    const caps = $('#conn-caps');
    if (cam?.state === 'ok') {
      const f = st.features || {};
      caps.innerHTML = [
        ['Galerie & Originale', true],
        ['Neue Aufnahmen erkennen', f.polling ? 'Ereignisse' : 'Ordnerabgleich'],
        ['Livebild', !!(f.liveview && f.flip)],
        ['Fotos-App', mode === 'download' ? 'nur über HTTPS' : 'ja'],
      ].map(([k, v]) => `<span class="cap ${v === false ? 'no' : ''}">${icon(v === false ? 'x' : 'check')}${k}${typeof v === 'string' ? ` <em>${v}</em>` : ''}</span>`).join('');
    } else caps.innerHTML = '';
    const addr = $('#cam-addr');
    if (cam?.address && document.activeElement !== addr && !addr.dataset.touched) addr.value = cam.address;
    // Sichere Verbindung
    const sec = $('#secure-card');
    if (st && !st.bridge.secure && st.bridge.httpsUrls.length) {
      sec.hidden = false;
      $('#secure-actions').hidden = false;
      $('#secure-open').href = st.bridge.httpsUrls[0];
      $('#secure-ca').href = st.bridge.caUrl;
      $('#secure-text').textContent = 'Damit „In Fotos sichern“ über das Teilen-Menü geht, braucht Safari HTTPS. Einmalig das Zertifikat der Brücke installieren und vertrauen, dann die sichere Adresse öffnen.';
    } else if (st && !st.bridge.secure) {
      sec.hidden = false;
      $('#secure-actions').hidden = true;
      $('#secure-text').textContent = 'Für „In Fotos sichern“ die Brücke mit HTTPS starten (Startdatei „mit Fotos-Funktion“ bzw. Option --https). Ohne HTTPS landen Downloads in der Dateien-App.';
    } else sec.hidden = true;
    $('#save-mode').innerHTML = `${icon(mode === 'download' ? 'file' : 'photos')}<div><b>${saveText.title}</b><small>${saveText.hint}</small></div>`;
  }

  $('#cam-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const addr = $('#cam-addr').value.trim();
    if (!addr) return;
    try {
      await bridge.setCamera(addr);
      $('#cam-addr').dataset.touched = '';
      state.loadedFor = '';
      lastOk = false;
      await refreshStatus();
      toast(state.status?.camera?.state === 'ok' ? 'Kamera verbunden' : 'Adresse gespeichert – Kamera antwortet noch nicht', state.status?.camera?.state === 'ok' ? 'ok' : '');
    } catch (err) { toast(err.message, 'err'); }
  });
  $('#cam-addr').addEventListener('input', (e) => (e.target.dataset.touched = '1'));
  $('#cam-scan').addEventListener('click', async () => {
    const btn = $('#cam-scan');
    btn.disabled = true;
    btn.classList.add('busy');
    $('#scan-result').textContent = 'Suche im Netzwerk (bis 30 s) …';
    try {
      const { found } = await bridge.discover();
      if (!found.length) $('#scan-result').textContent = 'Keine CCAPI-Kamera gefunden. Ist die Kamera im selben WLAN wie der Computer und im CCAPI-Menü verbunden?';
      else {
        $('#scan-result').replaceChildren(...found.map((f) => h('button', {
          class: 'chip link', type: 'button',
          onclick: () => { $('#cam-addr').value = f.address; $('#cam-form').requestSubmit(); },
        }, `${f.name} · ${f.address}`)));
        if (found.length === 1) { $('#cam-addr').value = found[0].address; $('#cam-form').requestSubmit(); }
      }
    } catch (err) {
      $('#scan-result').textContent = `Suche fehlgeschlagen: ${err.message}`;
    } finally {
      btn.disabled = false;
      btn.classList.remove('busy');
    }
  });

  // ---- Übertragen -------------------------------------------------------
  async function enqueue(files, { quiet = false } = {}) {
    if (!state.ccapi) return toast('Kamera nicht verbunden', 'err');
    let added = 0, dup = 0, busy = 0;
    for (const f of files) {
      let size = null;
      try { size = (await gallery.loadInfo(f.path)).size; } catch { /* Grösse folgt beim Laden */ }
      const r = await queue.add(f, size);
      if (r === 'added' || r === 'requeued') added++;
      else if (r === 'duplicate') dup++;
      else busy++;
      gallery.refreshState(f.path);
    }
    if (!quiet || dup) {
      const parts = [];
      if (added) parts.push(`${added} in Warteschlange`);
      if (dup) parts.push(`${dup} schon übertragen`);
      if (busy) parts.push(`${busy} bereits eingereiht`);
      toast(parts.join(' · ') || 'Nichts zu tun', added ? 'ok' : '');
    }
    renderQueue();
  }

  // ---- Downloads-Ansicht ------------------------------------------------
  const rows = new Map();
  function statusText(it) {
    const pct = it.size ? Math.floor(((it.bytes + (it.live || 0)) / it.size) * 100) : 0;
    switch (it.status) {
      case 'queued': return it.error || 'Wartet';
      case 'running': return `${pct} % · ${fmtBytes(it.bytes + (it.live || 0))} von ${fmtBytes(it.size)}${it.speed ? ` · ${fmtBytes(it.speed)}/s` : ''}`;
      case 'paused': return `Pausiert · ${pct} %`;
      case 'error': return it.error || 'Fehler';
      case 'cancelled': return 'Abgebrochen';
      case 'done': return `Übertragen · ${fmtBytes(it.size ?? it.bytes)}${it.saved ? ' · gesichert' : ''}`;
      default: return '';
    }
  }
  function qrow(it) {
    const actions = [];
    const b = (ic, label, fn, cls = '') => h('button', { class: `icon-btn ${cls}`, type: 'button', 'aria-label': label, title: label, html: icon(ic), onclick: fn });
    if (it.status === 'running' || it.status === 'queued') actions.push(b('pause', 'Pausieren', () => queue.pause(it.id)), b('x', 'Abbrechen', () => queue.cancel(it.id)));
    if (it.status === 'paused') actions.push(b('play', 'Fortsetzen', () => queue.resume(it.id)), b('x', 'Abbrechen', () => queue.cancel(it.id)));
    if (it.status === 'error' || it.status === 'cancelled') actions.push(b('retry', 'Wiederholen', () => queue.resume(it.id)), b('trash', 'Aus Liste entfernen', () => queue.remove(it.id)));
    if (it.status === 'done') actions.push(b('share', saveText.title, () => saveItems([it]), 'accent'), b('trash', 'Aus App entfernen (Kamera bleibt unverändert)', () => confirmSheet('Aus der App entfernen?', 'Nur die Kopie in dieser App wird gelöscht. Auf der Kamera bleibt alles unverändert.', 'Entfernen', () => queue.remove(it.id))));
    const pct = it.size ? ((it.bytes + (it.live || 0)) / it.size) * 100 : 0;
    const el = h('div', { class: `qrow s-${it.status} ${it.saved ? 'saved' : ''}`, dataset: { id: it.id } },
      h('img', { class: 'qthumb', alt: '', loading: 'lazy', src: camUrl(it.path, 'thumbnail'), onerror: (e) => e.target.classList.add('noimg') }),
      h('div', { class: 'qmain' },
        h('b', {}, it.name, h('span', { class: `badge inline b-${it.type}` }, it.type === 'jpeg' ? 'JPG' : it.type === 'raw' ? 'RAW' : it.name.split('.').pop())),
        h('small', {}, statusText(it)),
        h('span', { class: 'bar' }, h('i', { style: `width:${it.status === 'done' ? 100 : pct}%` }))),
      h('div', { class: 'qact' }, ...actions));
    return el;
  }
  function renderQueue() {
    const list = queue.list();
    rows.clear();
    const box = $('#queue-list');
    if (!list.length) {
      box.replaceChildren(h('div', { class: 'empty', html: `${icon('down', 'big')}<p>Noch keine Downloads. In der Galerie Bilder antippen oder auswählen.</p>` }));
    } else {
      box.replaceChildren(...list.map((it) => { const r = qrow(it); rows.set(it.id, r); return r; }));
    }
    renderSummary();
  }
  function renderSummary() {
    const s = queue.stats();
    const done = queue.list().filter((i) => i.status === 'done');
    const unsaved = done.filter((i) => !i.saved);
    $('#q-title').textContent = s.open ? `${s.open} offen` : done.length ? 'Alles übertragen' : 'Downloads';
    $('#q-sub').textContent = s.open ? `${fmtBytes(s.bytes)} von ${fmtBytes(s.size)}` : `${done.length} in der App gespeichert`;
    $('#q-bar').style.width = s.size ? `${Math.min(100, (s.bytes / s.size) * 100)}%` : !s.open && done.length ? '100%' : '0%';
    $('#q-halt').hidden = !s.open;
    $('#q-halt').innerHTML = queue.halted ? `${icon('play')}<span>Fortsetzen</span>` : `${icon('pause')}<span>Alle pausieren</span>`;
    $('#q-save').hidden = !unsaved.length;
    $('#q-save span').textContent = `${saveText.title} (${unsaved.length})`;
    $('#q-clean').hidden = !done.some((i) => i.saved);
    const badge = $('#tab-badge');
    badge.textContent = s.open || '';
    badge.hidden = !s.open;
    storageEstimate().then((e) => { if (e) $('#q-storage').textContent = `App-Speicher: ${fmtBytes(e.usage)} belegt${e.quota ? ` · ${fmtBytes(e.quota)} verfügbar` : ''}`; });
  }
  queue.addEventListener('change', (e) => {
    const it = e.detail;
    gallery.refreshState(it.path);
    const old = rows.get(it.id);
    if (it.removed || !old) return renderQueue();
    const fresh = qrow(it);
    old.replaceWith(fresh);
    rows.set(it.id, fresh);
    renderSummary();
  });
  queue.addEventListener('progress', (e) => {
    const it = e.detail;
    const r = rows.get(it.id);
    if (!r) return;
    r.querySelector('small').textContent = statusText(it);
    r.querySelector('.bar i').style.width = `${it.size ? ((it.bytes + it.live) / it.size) * 100 : 0}%`;
    renderSummaryThrottled();
  });
  let sumT = 0;
  function renderSummaryThrottled() {
    if (Date.now() - sumT < 500) return;
    sumT = Date.now();
    renderSummary();
  }
  queue.addEventListener('halted', renderSummary);
  $('#q-halt').addEventListener('click', () => (queue.halted ? queue.resumeAll() : queue.haltAll()));
  $('#q-save').addEventListener('click', () => saveItems(queue.list().filter((i) => i.status === 'done' && !i.saved)));
  $('#q-clean').addEventListener('click', () => {
    const saved = queue.list().filter((i) => i.status === 'done' && i.saved);
    confirmSheet(`${saved.length} gesicherte Dateien aus der App entfernen?`, 'Gibt Speicher auf dem iPhone frei. Fotos-App, Dateien-App und Kamera bleiben unverändert.', 'Entfernen', async () => {
      for (const it of saved) await queue.remove(it.id);
    });
  });
  renderQueue();

  // ---- Sichern ----------------------------------------------------------
  async function saveItems(items) {
    items = items.filter((i) => i.status === 'done');
    if (!items.length) return;
    if (mode === 'native') {
      sheet(`<h3>In Fotos sichern …</h3><p class="muted">${items.length} Datei(en)</p>`);
      try {
        await nativeSave(items);
        await queue.markSaved(items.map((i) => i.id));
        closeSheet();
        toast('In Fotos gesichert', 'ok');
      } catch (e) { closeSheet(); toast(e.message, 'err'); }
      return;
    }
    if (mode === 'share') {
      // Safari verlangt einen frischen Fingertipp direkt vor dem Teilen: erst vorbereiten.
      const total = items.reduce((a, i) => a + (i.size || 0), 0);
      sheet(`<h3>Wird vorbereitet …</h3><p class="muted">${items.length} Datei(en) · ${fmtBytes(total)}</p>`);
      const files = [];
      for (const it of items) files.push(new File([await queue.blob(it.id)], it.name, { type: it.mime }));
      if (!navigator.canShare({ files })) {
        closeSheet();
        return toast('Diese Dateien kann Safari nicht teilen – „Direkt in Dateien“ nutzen.', 'err');
      }
      const raw = items.some((i) => i.type === 'raw');
      sheet(`<h3>Bereit: ${items.length} Datei(en)</h3>
        <p class="muted">Im Teilen-Menü <b>„${items.length > 1 ? 'Bilder' : 'Bild'} sichern“</b> bzw. <b>„Video sichern“</b> wählen – so landen die Originale in der Fotos-App.${raw ? ' Für RAW (CR3) „In Dateien sichern“ verwenden, falls „Bild sichern“ fehlt.' : ''}</p>
        <button class="btn primary big" id="share-go">${icon('share')}<span>Teilen / Sichern</span></button>`);
      $('#share-go').addEventListener('click', async () => {
        try {
          await shareFiles(files);
          await queue.markSaved(items.map((i) => i.id));
          closeSheet();
          toast('Gesichert', 'ok');
        } catch (e) {
          if (e.name !== 'AbortError') toast(`Teilen fehlgeschlagen: ${e.message}`, 'err');
          closeSheet();
        }
      });
      return;
    }
    // download: Safari legt die Dateien in „Dateien › Downloads“ ab
    for (const it of items) {
      await downloadBlob(await queue.blob(it.id), it.name);
      await sleep(700);
    }
    await queue.markSaved(items.map((i) => i.id));
    toast('An Safari übergeben – zu finden in Dateien › Downloads', 'ok');
  }

  // ---- Bottom-Sheet -----------------------------------------------------
  function sheet(html) {
    const s = $('#sheet');
    $('#sheet-body').innerHTML = html;
    s.hidden = false;
    requestAnimationFrame(() => s.classList.add('open'));
  }
  function closeSheet() {
    const s = $('#sheet');
    s.classList.remove('open');
    setTimeout(() => (s.hidden = true), 200);
  }
  function confirmSheet(title, text, ok, fn) {
    sheet(`<h3>${escapeHtml(title)}</h3><p class="muted">${escapeHtml(text)}</p>
      <div class="row2"><button class="btn ghost big" id="sh-no">Abbrechen</button><button class="btn primary big" id="sh-yes">${escapeHtml(ok)}</button></div>`);
    $('#sh-no').addEventListener('click', closeSheet);
    $('#sh-yes').addEventListener('click', () => { closeSheet(); fn(); });
  }
  $('#sheet').addEventListener('click', (e) => { if (e.target.id === 'sheet') closeSheet(); });

  // Wiederaufnahme nach Rückkehr in die App (iOS pausiert Hintergrund-Tabs)
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') { refreshStatus(); queue.kick(); }
  });
  window.addEventListener('beforeunload', (e) => {
    if (queue.active) { e.preventDefault(); e.returnValue = ''; }
  });
  window.__kl = { queue, gallery, live, state }; // für Tests und Fehlersuche
}

// =========================================================================
function showView(name) {
  document.body.dataset.view = name;
  for (const v of $$('.view')) v.hidden = v.id !== `view-${name}`;
  for (const b of $$('.tabbar [data-view]')) b.classList.toggle('on', b.dataset.view === name);
  window.scrollTo(0, 0);
}

function setPill(kind, text) {
  const p = $('#pill');
  p.className = `pill ${kind}`;
  $('#pill-text').textContent = text;
}

function shortName(n) {
  return (n || '').replace(/^Canon\s+/i, '');
}

function escapeHtml(s) {
  return String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}
