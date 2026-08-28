/**
 * AI Groove – Studio-Oberfläche.
 *
 * Setzt Kopfzeile, Sample-Liste, Hauptansichten, Inspector, Transportleiste
 * und (auf dem Handy) die untere Navigation zusammen.
 *
 * Die Ansichten bleiben im Speicher, damit Zoom und Scrollposition beim
 * Wechseln erhalten bleiben und keine Canvas neu aufgebaut werden müssen.
 */

import { h, icon, iconButton, onLongPress } from '../core/dom.js';
import { store } from '../core/store.js';
import { bus, EV } from '../core/bus.js';
import { engine } from '../audio/engine.js';
import { navigate } from './router.js';
import { toast } from './toast.js';
import { promptModal } from './modal.js';
import { createSampleList } from './samplelist.js';
import { createPadsView } from './pads.js';
import { createSequencerView } from './sequencer.js';
import { createPianoRollView } from './pianoroll.js';
import { createArrangementView } from './arrangement.js';
import { createMixerView, createFxPanel } from './mixer.js';
import { createTransport } from './transport.js';
import { createChatPanel } from './chat.js';
import { openExportDialog } from './exportui.js';
import { openDiagnostics, toggleDiagnosticsHud } from './diagnostics.js';
import { openAddSampleDialog } from './aisample.js';
import { anyModalOpen } from './modal.js';
import { relativeTime, downloadBlob, safeFilename } from '../core/util.js';
import { openContextMenu } from './contextmenu.js';

const VIEWS = [
  { id: 'pads', label: 'Pads', icon: 'pads' },
  { id: 'seq', label: 'Sequence', icon: 'grid' },
  { id: 'piano', label: 'Piano Roll', icon: 'piano' },
  { id: 'arrange', label: 'Arrange', icon: 'timeline' },
  { id: 'mixer', label: 'Mixer', icon: 'mixer' },
];

/** Auf dem Handy zusätzlich die Sample-Liste als eigener Reiter. */
const MOBILE_TABS = [
  { id: 'pads', label: 'Pads', icon: 'pads' },
  { id: 'seq', label: 'Sequence', icon: 'grid' },
  { id: 'arrange', label: 'Arrange', icon: 'timeline' },
  { id: 'mixer', label: 'Mixer', icon: 'mixer' },
  { id: 'sounds', label: 'Sounds', icon: 'sound' },
];

export function createStudio() {
  const element = h('div.studio', { dataset: { mobileView: 'pads' } });

  // =========================================================================
  //  Kopfzeile
  // =========================================================================
  const topbar = h('div.topbar');

  const homeBtn = iconButton('chevron', 'Zurück zum Dashboard', { class: 'btn--ghost btn--sm' });
  homeBtn.firstChild.style.transform = 'rotate(180deg)';
  homeBtn.addEventListener('click', () => {
    engine.stop();
    store.save().catch(() => {});
    navigate('/');
  });
  topbar.appendChild(homeBtn);

  const titleWrap = h('div.topbar__title');
  const nameBtn = h('button.topbar__name', { type: 'button', text: 'Projekt' });
  nameBtn.addEventListener('click', async () => {
    const name = await promptModal({
      title: 'Projekt umbenennen',
      label: 'Name',
      value: store.project.name,
    });
    if (name) {
      store.setName(name);
      syncTitle();
    }
  });
  const metaWrap = h('div.topbar__meta');
  const saveDot = h('span.save-dot');
  const saveText = h('span', { text: 'Bereit' });
  metaWrap.appendChild(saveDot);
  metaWrap.appendChild(saveText);
  titleWrap.appendChild(nameBtn);
  titleWrap.appendChild(metaWrap);
  topbar.appendChild(titleWrap);

  // Ansichtswechsler (Desktop)
  const viewTabs = h('div.viewtabs', { role: 'tablist' });
  const viewButtons = new Map();
  for (const view of VIEWS) {
    const btn = h('button.viewtabs__btn', {
      type: 'button',
      role: 'tab',
      text: view.label,
      'aria-selected': 'false',
    });
    btn.addEventListener('click', () => setView(view.id));
    viewButtons.set(view.id, btn);
    viewTabs.appendChild(btn);
  }
  topbar.appendChild(viewTabs);

  const addBtn = iconButton('plus', 'Neues Sample', { class: 'btn--sm' });
  addBtn.addEventListener('click', () => openAddSampleDialog());
  topbar.appendChild(addBtn);

  const inspectorBtn = iconButton('chat', 'Groove AI', { class: 'btn--sm' });
  inspectorBtn.addEventListener('click', () => toggleInspector());
  topbar.appendChild(inspectorBtn);

  const menuBtn = iconButton('dots', 'Menü', { class: 'btn--ghost btn--sm' });
  menuBtn.addEventListener('click', (event) => openMainMenu(event));
  topbar.appendChild(menuBtn);

  element.appendChild(topbar);

  // =========================================================================
  //  Arbeitsfläche
  // =========================================================================
  const workspace = h('div.workspace');
  element.appendChild(workspace);

  const sampleList = createSampleList();
  workspace.appendChild(sampleList.element);

  const main = h('div.ws-col.ws-main');
  workspace.appendChild(main);

  const views = {
    pads: createPadsView(),
    seq: createSequencerView(),
    piano: createPianoRollView(),
    arrange: createArrangementView(),
    mixer: createMixerView(),
  };
  for (const view of Object.values(views)) main.appendChild(view.element);

  // --- Inspector -------------------------------------------------------------
  const inspector = h('div.ws-col.ws-inspector');
  const inspHead = h('div.panel-head');
  const inspTitle = h('div.panel-head__title', { text: 'Groove AI' });
  inspHead.appendChild(inspTitle);

  const inspTabs = h('div.segmented');
  const chatTab = h('button.segmented__btn', { type: 'button', text: 'Groove AI', 'aria-pressed': 'true' });
  const fxTab = h('button.segmented__btn', { type: 'button', text: 'Effekte', 'aria-pressed': 'false' });
  inspTabs.appendChild(chatTab);
  inspTabs.appendChild(fxTab);
  inspHead.appendChild(inspTabs);

  const closeInsp = iconButton('close', 'Schliessen', { class: 'btn--ghost btn--sm' });
  closeInsp.addEventListener('click', () => toggleInspector(false));
  inspHead.appendChild(closeInsp);
  inspector.appendChild(inspHead);

  const chat = createChatPanel();
  const fxPanel = createFxPanel();
  const fxWrap = h('div.inspector', { hidden: true });
  fxWrap.appendChild(fxPanel.element);

  inspector.appendChild(chat.element);
  inspector.appendChild(fxWrap);
  workspace.appendChild(inspector);

  function setInspectorTab(tab) {
    const isChat = tab === 'chat';
    chatTab.setAttribute('aria-pressed', String(isChat));
    fxTab.setAttribute('aria-pressed', String(!isChat));
    chat.element.hidden = !isChat;
    fxWrap.hidden = isChat;
    inspTitle.textContent = isChat ? 'Groove AI' : 'Effekte';
    if (!isChat) fxPanel.render();
  }
  chatTab.addEventListener('click', () => setInspectorTab('chat'));
  fxTab.addEventListener('click', () => setInspectorTab('fx'));

  let inspectorOpen = false;
  function toggleInspector(force) {
    inspectorOpen = force == null ? !inspectorOpen : force;
    workspace.classList.toggle('workspace--inspector', inspectorOpen);
    inspectorBtn.setAttribute('aria-pressed', String(inspectorOpen));
    if (inspectorOpen && chatTab.getAttribute('aria-pressed') === 'true') {
      setTimeout(() => chat.focus(), 250);
    }
  }

  // =========================================================================
  //  Transport und untere Navigation
  // =========================================================================
  const transport = createTransport();
  element.appendChild(transport.element);

  const tabbar = h('div.tabbar', { role: 'tablist' });
  const tabButtons = new Map();
  for (const tab of MOBILE_TABS) {
    const btn = h('button.tabbar__btn', { type: 'button', role: 'tab', 'aria-selected': 'false' });
    btn.appendChild(icon(tab.icon, 22));
    btn.appendChild(h('span', { text: tab.label }));
    btn.addEventListener('click', () => {
      if (tab.id === 'sounds') setMobileView('sounds');
      else {
        setMobileView(tab.id);
        setView(tab.id);
      }
    });
    tabButtons.set(tab.id, btn);
    tabbar.appendChild(btn);
  }
  element.appendChild(tabbar);

  // =========================================================================
  //  Ansichtswechsel
  // =========================================================================
  let currentView = 'pads';

  function setView(id) {
    if (!views[id]) return;
    if (currentView === id && views[id].element.classList.contains('view--active')) return;

    views[currentView]?.onHide?.();
    for (const [key, view] of Object.entries(views)) {
      view.element.classList.toggle('view--active', key === id);
    }
    currentView = id;
    views[id].onShow?.();

    for (const [key, btn] of viewButtons) btn.setAttribute('aria-selected', String(key === id));
    for (const [key, btn] of tabButtons) btn.setAttribute('aria-selected', String(key === id));
    store.setUi({ view: id });
  }

  function setMobileView(id) {
    element.dataset.mobileView = id;
    for (const [key, btn] of tabButtons) btn.setAttribute('aria-selected', String(key === id));
    if (id !== 'sounds' && views[id]) setView(id);
  }

  // =========================================================================
  //  Menü
  // =========================================================================
  function openMainMenu(event) {
    const rect = event.currentTarget.getBoundingClientRect();
    openContextMenu({ x: rect.right - 220, y: rect.bottom + 6 }, [
      { heading: store.project.name },
      { label: 'Neues Sample …', icon: 'plus', onClick: () => openAddSampleDialog() },
      { label: 'Track exportieren …', icon: 'download', kbd: 'Ctrl+E', onClick: () => openExportDialog() },
      {
        label: 'Projektdatei speichern (.aigroove)',
        icon: 'folder',
        onClick: async () => {
          const stop = toast.sticky('Projektdatei wird erstellt …');
          try {
            const blob = await store.exportProjectFile();
            downloadBlob(blob, `${safeFilename(store.project.name)}.aigroove`);
            stop();
            toast.ok('Projektdatei gespeichert');
          } catch (err) {
            stop();
            toast.error('Export fehlgeschlagen', err.message);
          }
        },
      },
      { separator: true },
      { label: 'Einstellungen', icon: 'settings', onClick: () => navigate('/settings') },
      { label: 'KI-Einstellungen', icon: 'key', onClick: () => navigate('/ai') },
      { label: 'Hilfe & Einführung', icon: 'help', onClick: () => navigate('/help') },
      { separator: true },
      { label: 'Diagnose (Audio & Scheduler)', icon: 'power', onClick: () => openDiagnostics() },
      { label: 'Zum Dashboard', icon: 'chevron', onClick: () => navigate('/') },
    ]);
  }

  // =========================================================================
  //  Tastenkürzel
  // =========================================================================
  function onKeyDown(event) {
    if (anyModalOpen()) return;
    const target = event.target;
    const typing =
      target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.isContentEditable);
    const mod = event.metaKey || event.ctrlKey;

    if (event.code === 'Space' && !typing) {
      event.preventDefault();
      transport.togglePlay();
      return;
    }

    if (mod && event.key.toLowerCase() === 'z') {
      event.preventDefault();
      const label = event.shiftKey ? store.history.redo() : store.history.undo();
      if (label) toast.info(event.shiftKey ? 'Wiederholt' : 'Rückgängig', label);
      return;
    }

    if (mod && event.key.toLowerCase() === 'y') {
      event.preventDefault();
      const label = store.history.redo();
      if (label) toast.info('Wiederholt', label);
      return;
    }

    if (mod && event.key.toLowerCase() === 'e') {
      event.preventDefault();
      openExportDialog();
      return;
    }

    if (mod && event.key.toLowerCase() === 's') {
      event.preventDefault();
      store.save().then(() => toast.ok('Gespeichert', 'Projekt liegt im lokalen Speicher.'));
      return;
    }

    if (mod && event.key >= '1' && event.key <= '5') {
      event.preventDefault();
      setView(VIEWS[Number(event.key) - 1].id);
      return;
    }

    // Verstecktes Diagnose-Fenster
    if (event.altKey && mod && event.key.toLowerCase() === 'd') {
      event.preventDefault();
      toggleDiagnosticsHud();
    }
  }

  // =========================================================================
  //  Statusanzeige
  // =========================================================================
  function syncTitle() {
    nameBtn.textContent = store.project.name;
    document.title = `${store.project.name} — AI Groove`;
  }

  let saveTimer = 0;
  function markSaved() {
    saveDot.classList.remove('save-dot--dirty');
    saveDot.classList.add('save-dot--saved');
    saveText.textContent = 'Gespeichert';
    clearTimeout(saveTimer);
    saveTimer = setTimeout(() => {
      saveDot.classList.remove('save-dot--saved');
      saveText.textContent = `Gespeichert ${relativeTime(store.lastSavedAt)}`;
    }, 1800);
  }

  function markDirty() {
    saveDot.classList.add('save-dot--dirty');
    saveDot.classList.remove('save-dot--saved');
    saveText.textContent = 'Nicht gespeichert';
  }

  const offSaved = bus.on(EV.PROJECT_SAVED, markSaved);
  const offDirty = bus.on(EV.PROJECT_DIRTY, markDirty);
  const offLoaded = bus.on(EV.PROJECT_LOADED, () => {
    syncTitle();
    setView(store.project.ui.view || 'pads');
  });
  const offStorage = bus.on(EV.STORAGE_WARNING, ({ message }) => {
    saveText.textContent = 'Speicherproblem';
    toast.error('Lokaler Speicher', message);
  });
  const offSelection = bus.on(EV.SELECTION_CHANGED, (payload) => {
    if (payload?.view === 'piano') setView('piano');
    if (payload?.view === 'seq') setView('seq');
    if (payload?.openFx) {
      toggleInspector(true);
      setInspectorTab('fx');
    }
  });

  // Langes Antippen der Statuszeile öffnet die Diagnose (für normale Nutzer verborgen).
  onLongPress(metaWrap, () => openDiagnostics());

  syncTitle();
  setView(store.project.ui.view || 'pads');
  setMobileView(store.project.ui.view || 'pads');
  setInspectorTab('chat');

  return {
    element,
    onShow() {
      window.addEventListener('keydown', onKeyDown);
      syncTitle();
      views[currentView]?.onShow?.();
      engine.init().catch(() => {});
    },
    onHide() {
      window.removeEventListener('keydown', onKeyDown);
      views[currentView]?.onHide?.();
    },
    destroy() {
      this.onHide();
      offSaved();
      offDirty();
      offLoaded();
      offStorage();
      offSelection();
      for (const view of Object.values(views)) view.destroy?.();
      sampleList.destroy();
      transport.destroy();
      chat.destroy();
      fxPanel.destroy();
    },
  };
}
