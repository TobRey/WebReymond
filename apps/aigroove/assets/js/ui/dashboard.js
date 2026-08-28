/**
 * AI Groove – Dashboard (Startseite).
 */

import { h, icon, clear } from '../core/dom.js';
import { store } from '../core/store.js';
import { navigate } from './router.js';
import { toast, reportError } from './toast.js';
import { confirmModal, promptModal } from './modal.js';
import { pickFiles, readArrayBuffer, MAX_PROJECT_BYTES } from '../core/file.js';
import { relativeTime, humanBytes, downloadBlob, safeFilename } from '../core/util.js';
import { estimateStorage, requestPersistence } from '../core/idb.js';
import { settings } from '../core/settings.js';
import { openTutorial } from './tutorial.js';

const APP_VERSION = '1.2.1';

function tile({ iconName, title, text, primary = false, disabled = false, onClick }) {
  const btn = h(`button.tile${primary ? '.tile--primary' : ''}`, { type: 'button', disabled });
  const ic = h('div.tile__icon');
  ic.appendChild(icon(iconName, 22));
  btn.appendChild(ic);
  btn.appendChild(h('div.tile__title', { text: title }));
  btn.appendChild(h('div.tile__text', { text }));
  if (onClick) btn.addEventListener('click', onClick);
  return btn;
}

/** Dekorative, animierte Wellenlinie im Hero. */
function heroWave() {
  const canvas = h('canvas.hero__wave', { width: 600, height: 220, 'aria-hidden': 'true' });
  const ctx = canvas.getContext('2d');
  let raf = 0;
  let t = 0;
  const reduced =
    settings.get('reduceMotion') || window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  const draw = () => {
    const w = canvas.width;
    const hgt = canvas.height;
    ctx.clearRect(0, 0, w, hgt);
    for (let line = 0; line < 3; line++) {
      ctx.beginPath();
      const amp = 22 - line * 5;
      const speed = 0.6 + line * 0.25;
      for (let x = 0; x <= w; x += 4) {
        const p = x / w;
        const y =
          hgt / 2 +
          Math.sin(p * 7 + t * speed + line) * amp * Math.sin(p * Math.PI) +
          Math.sin(p * 19 - t * speed * 0.7) * (amp * 0.35) * Math.sin(p * Math.PI);
        if (x === 0) ctx.moveTo(x, y);
        else ctx.lineTo(x, y);
      }
      ctx.strokeStyle = ['rgba(125,92,255,0.55)', 'rgba(47,216,196,0.42)', 'rgba(77,156,255,0.32)'][line];
      ctx.lineWidth = 2;
      ctx.stroke();
    }
    if (!reduced) {
      t += 0.016;
      raf = requestAnimationFrame(draw);
    }
  };

  draw();
  canvas.stop = () => cancelAnimationFrame(raf);
  return canvas;
}

export function createDashboard() {
  const element = h('div.page');
  const inner = h('div.page__inner');
  element.appendChild(inner);

  const recentWrap = h('div');
  const storageLine = h('p.field__hint');
  let wave = null;

  async function openProject(id) {
    try {
      await store.loadProjectById(id);
      navigate('/studio');
    } catch (err) {
      reportError('Projekt konnte nicht geladen werden', err);
      await refresh();
    }
  }

  async function newProject() {
    const name = await promptModal({
      title: 'Neues Projekt',
      label: 'Projektname',
      value: `Projekt ${new Date().toLocaleDateString('de-DE')}`,
      confirmLabel: 'Erstellen',
    });
    if (!name) return;
    try {
      await store.newProject(name);
      settings.set('tutorialDone', settings.get('tutorialDone'));
      navigate('/studio');
      if (!settings.get('tutorialDone')) setTimeout(() => openTutorial(), 600);
    } catch (err) {
      reportError('Projekt konnte nicht angelegt werden', err);
    }
  }

  async function importProject() {
    const files = await pickFiles({ accept: '.aigroove,application/octet-stream', multiple: false });
    if (!files.length) return;
    const file = files[0];
    if (file.size > MAX_PROJECT_BYTES) {
      toast.error('Datei zu gross', `Die Projektdatei ist ${humanBytes(file.size)} gross.`);
      return;
    }
    const stop = toast.sticky('Projekt wird importiert …', file.name);
    try {
      const buffer = await readArrayBuffer(file);
      await store.importProjectFile(buffer);
      stop();
      toast.ok('Projekt importiert', store.project.name);
      navigate('/studio');
    } catch (err) {
      stop();
      reportError('Import fehlgeschlagen', err);
    }
  }

  async function exportProject(id, name) {
    const stop = toast.sticky('Projektdatei wird erstellt …');
    try {
      const wasCurrent = store.project.id === id;
      if (!wasCurrent) await store.loadProjectById(id);
      const blob = await store.exportProjectFile();
      downloadBlob(blob, `${safeFilename(name)}.aigroove`);
      stop();
      toast.ok('Projektdatei gespeichert', `${safeFilename(name)}.aigroove`);
    } catch (err) {
      stop();
      reportError('Export fehlgeschlagen', err);
    }
  }

  async function deleteProject(id, name) {
    const ok = await confirmModal({
      title: 'Projekt löschen?',
      message: `„${name}“ wird endgültig aus dem lokalen Speicher entfernt. Exportiere es vorher als .aigroove-Datei, wenn du es behalten möchtest.`,
      confirmLabel: 'Löschen',
      danger: true,
    });
    if (!ok) return;
    try {
      await store.deleteProject(id);
      toast.ok('Projekt gelöscht');
      await refresh();
    } catch (err) {
      reportError('Löschen fehlgeschlagen', err);
    }
  }

  async function refresh() {
    // --- Zuletzt verwendet -------------------------------------------------
    clear(recentWrap);
    let projects = [];
    try {
      projects = await store.listProjects();
    } catch (err) {
      recentWrap.appendChild(
        h('div.empty', null, [
          h('div.empty__icon', null, icon('folder', 22)),
          h('span', {
            text: 'Der lokale Speicher ist nicht verfügbar. Im privaten Modus mancher Browser lassen sich Projekte nicht sichern.',
          }),
        ]),
      );
      return;
    }

    recentWrap.appendChild(h('h2.section-title', { text: 'Zuletzt lokal verwendet' }));

    if (!projects.length) {
      recentWrap.appendChild(
        h('div.empty', null, [
          h('div.empty__icon', null, icon('folder', 22)),
          h('span', { text: 'Noch keine Projekte. Lege oben ein neues Projekt an.' }),
        ]),
      );
    } else {
      const list = h('div.recent');
      for (const p of projects) {
        const row = h('div.recent__row');
        const main = h('button.recent__main', { type: 'button' });
        main.appendChild(h('div.recent__name', { text: p.name }));
        const meta = h('div.recent__meta');
        meta.appendChild(h('span', { text: relativeTime(p.updatedAt) }));
        meta.appendChild(h('span', { text: `${p.bpm} BPM` }));
        meta.appendChild(h('span', { text: `${p.samples} Sample${p.samples === 1 ? '' : 's'}` }));
        meta.appendChild(h('span', { text: `${p.patterns} Pattern` }));
        if (p.expired) meta.appendChild(h('span.chip.chip--warn', { text: 'abgelaufen' }));
        main.appendChild(meta);
        main.addEventListener('click', () => openProject(p.id));
        row.appendChild(main);

        const exportBtn = h('button.btn.btn--sm.btn--icon.btn--ghost', {
          type: 'button',
          title: 'Als .aigroove exportieren',
          'aria-label': 'Exportieren',
        });
        exportBtn.appendChild(icon('download', 16));
        exportBtn.addEventListener('click', () => exportProject(p.id, p.name));

        const delBtn = h('button.btn.btn--sm.btn--icon.btn--ghost', {
          type: 'button',
          title: 'Löschen',
          'aria-label': 'Löschen',
        });
        delBtn.appendChild(icon('trash', 16));
        delBtn.addEventListener('click', () => deleteProject(p.id, p.name));

        row.appendChild(exportBtn);
        row.appendChild(delBtn);
        list.appendChild(row);
      }
      recentWrap.appendChild(list);
    }

    // --- Speicherinfo ------------------------------------------------------
    const est = await estimateStorage();
    if (est.supported && est.quota > 0) {
      const pct = Math.round((est.usage / est.quota) * 100);
      storageLine.textContent = `Lokaler Speicher: ${humanBytes(est.usage)} von ca. ${humanBytes(est.quota)} belegt (${pct} %). Projekte bleiben nur auf diesem Gerät und in diesem Browser.`;
      if (pct > 85) {
        toast.warn(
          'Lokaler Speicher fast voll',
          'Bitte nicht mehr benötigte Projekte löschen oder als .aigroove exportieren.',
        );
      }
    } else {
      storageLine.textContent =
        'Projekte werden nur lokal in diesem Browser gespeichert – nicht auf dem Server.';
    }
  }

  async function build() {
    clear(inner);

    // --- Kopfzeile ---------------------------------------------------------
    const head = h('div.page-head');
    const brand = h('div.brand', null, [
      h('div.brand__mark'),
      h('div', null, [
        h('div.brand__name', { text: 'AI Groove' }),
        h('div.brand__ver', { text: `Version ${APP_VERSION}` }),
      ]),
    ]);
    head.appendChild(brand);
    head.appendChild(h('div.page-head__spacer'));

    const helpBtn = h('button.btn.btn--ghost.btn--sm', { type: 'button' });
    helpBtn.appendChild(icon('help', 17));
    helpBtn.appendChild(h('span', { text: 'Hilfe' }));
    helpBtn.addEventListener('click', () => navigate('/help'));

    const setBtn = h('button.btn.btn--ghost.btn--sm', { type: 'button' });
    setBtn.appendChild(icon('settings', 17));
    setBtn.appendChild(h('span', { text: 'Einstellungen' }));
    setBtn.addEventListener('click', () => navigate('/settings'));

    head.appendChild(helpBtn);
    head.appendChild(setBtn);
    inner.appendChild(head);

    // --- Hero --------------------------------------------------------------
    const hero = h('section.hero');
    wave = heroWave();
    hero.appendChild(wave);
    hero.appendChild(h('div.hero__eyebrow', { text: 'Musikproduktion im Browser' }));
    hero.appendChild(h('h1.hero__title', { text: 'Bau deinen Track. Sofort.' }));
    hero.appendChild(
      h('p.hero__sub', {
        text:
          'Launchpad, Step Sequencer, Piano Roll, Arrangement, Mixer, Effekte und ein KI-Sample-Generator – alles direkt auf deinem Gerät. Ohne Konto, ohne Upload, ohne Installation.',
      }),
    );

    const actions = h('div.hero__actions');
    const startBtn = h('button.btn.btn--primary.btn--lg', { type: 'button' });
    startBtn.appendChild(icon('plus', 19));
    startBtn.appendChild(h('span', { text: 'Neues Projekt' }));
    startBtn.addEventListener('click', newProject);
    actions.appendChild(startBtn);

    const last = await store.getLastProjectInfo().catch(() => null);
    if (last) {
      const resumeBtn = h('button.btn.btn--lg', { type: 'button' });
      resumeBtn.appendChild(icon('play', 18));
      resumeBtn.appendChild(
        h('span', {
          text: last.expired ? `„${last.name}“ (abgelaufen) öffnen` : `„${last.name}“ fortsetzen`,
        }),
      );
      resumeBtn.addEventListener('click', async () => {
        if (last.expired) {
          const ok = await confirmModal({
            title: 'Abgelaufenes Projekt',
            message: `„${last.name}“ wurde seit über 12 Stunden nicht bearbeitet und gilt als temporär abgelaufen. Es ist noch vorhanden – möchtest du es trotzdem öffnen?`,
            confirmLabel: 'Öffnen',
          });
          if (!ok) return;
        }
        openProject(last.id);
      });
      actions.appendChild(resumeBtn);
    }
    hero.appendChild(actions);
    inner.appendChild(hero);

    // --- Kacheln -----------------------------------------------------------
    const tilesSection = h('section');
    tilesSection.appendChild(h('h2.section-title', { text: 'Schnellzugriff' }));
    const tiles = h('div.tiles');

    tiles.appendChild(
      tile({
        iconName: 'plus',
        title: 'Neues Projekt',
        text: 'Leeres Projekt mit 128 BPM starten.',
        primary: true,
        onClick: newProject,
      }),
    );
    tiles.appendChild(
      tile({
        iconName: 'play',
        title: 'Fortsetzen',
        text: last ? `Zuletzt: ${relativeTime(last.updatedAt)}` : 'Noch kein Projekt vorhanden.',
        disabled: !last,
        onClick: () => last && openProject(last.id),
      }),
    );
    tiles.appendChild(
      tile({
        iconName: 'upload',
        title: 'Projektdatei importieren',
        text: 'Eine .aigroove-Datei mit allen Samples öffnen.',
        onClick: importProject,
      }),
    );
    tiles.appendChild(
      tile({
        iconName: 'mic',
        title: 'Mikrofon',
        text: 'Eingang wählen und Berechtigung prüfen.',
        onClick: () => navigate('/settings?section=mic'),
      }),
    );
    tiles.appendChild(
      tile({
        iconName: 'key',
        title: 'KI-Einstellungen',
        text: 'Eigene KI: Rechenqualität und Prompt-Prüfung.',
        onClick: () => navigate('/ai'),
      }),
    );
    tiles.appendChild(
      tile({
        iconName: 'settings',
        title: 'Einstellungen',
        text: 'Darstellung, Audio, Tastenbelegung.',
        onClick: () => navigate('/settings'),
      }),
    );
    tiles.appendChild(
      tile({
        iconName: 'help',
        title: 'Kurze Einführung',
        text: 'In fünf Schritten zum ersten Track.',
        onClick: () => navigate('/help'),
      }),
    );

    tilesSection.appendChild(tiles);
    inner.appendChild(tilesSection);

    // --- Zuletzt verwendet -------------------------------------------------
    inner.appendChild(recentWrap);

    // --- Fusszeile ---------------------------------------------------------
    const footer = h('div');
    footer.appendChild(storageLine);
    inner.appendChild(footer);

    await refresh();
  }

  return {
    element,
    async onShow(params) {
      await build();
      requestPersistence();
      if (params?.get('new') === '1') newProject();
    },
    onHide() {
      wave?.stop?.();
    },
    destroy() {
      wave?.stop?.();
    },
  };
}
