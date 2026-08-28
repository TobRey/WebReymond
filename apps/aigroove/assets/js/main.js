/**
 * AI Groove – Einstiegspunkt.
 *
 * Reihenfolge beim Start:
 *   1. Einstellungen auf die Darstellung anwenden (kein Aufblitzen)
 *   2. Routen registrieren und Oberfläche starten
 *   3. Letztes Projekt laden oder ein neues anlegen
 *   4. Audio erst nach der ersten Nutzergeste entsperren (Pflicht auf iOS)
 *   5. Service Worker registrieren (PWA)
 */

import { settings } from './core/settings.js';
import { store } from './core/store.js';
import { bus, EV } from './core/bus.js';
import { registerRoute, startRouter, navigate } from './ui/router.js';
import { createDashboard } from './ui/dashboard.js';
import { createStudio } from './ui/shell.js';
import { createSettingsView } from './ui/settingsview.js';
import { createAiConnectView } from './ui/aiconnect.js';
import { createHelpView } from './ui/help.js';
import { toast } from './ui/toast.js';
import { attachUnlockHandlers, unlockAudio } from './audio/context.js';
import { engine } from './audio/engine.js';
import { restoreDiagnostics } from './ui/diagnostics.js';
import { maybeStartTutorial } from './ui/tutorial.js';
import { collectGarbage, requestPersistence } from './core/idb.js';
import { EXPIRY_MS } from './core/project.js';
import { relativeTime } from './core/util.js';

const APP_VERSION = '1.2.0';

// --- 1. Darstellung ----------------------------------------------------------
settings.apply();

// --- Globale Fehlerbehandlung ------------------------------------------------
// Nutzer bekommen eine verständliche Meldung, Details nur in der Konsole.
window.addEventListener('error', (event) => {
  console.error('[AI Groove] Unbehandelter Fehler', event.error || event.message);
});

window.addEventListener('unhandledrejection', (event) => {
  const reason = event.reason;
  console.error('[AI Groove] Nicht behandeltes Promise', reason);
  if (reason && reason.name === 'AbortError') return;
  if (reason && typeof reason.message === 'string' && reason.message.length < 200) {
    toast.error('Etwas hat nicht geklappt', reason.message);
  }
});

// --- 2. Routen ---------------------------------------------------------------
registerRoute('/', createDashboard);
registerRoute('/studio', createStudio, { keepAlive: true });
registerRoute('/settings', createSettingsView);
registerRoute('/ai', createAiConnectView);
registerRoute('/help', createHelpView);
registerRoute('/import', createDashboard);

// --- 3. Start ----------------------------------------------------------------
async function boot() {
  const appEl = document.getElementById('app');
  const bootEl = document.getElementById('boot');

  try {
    const last = await store.getLastProjectInfo();
    if (last && !last.expired) {
      await store.loadProjectById(last.id).catch(async () => {
        await store.newProject('Neues Projekt');
      });
    } else {
      await store.newProject('Neues Projekt');
      if (last?.expired) {
        // Das alte Projekt bleibt erhalten und ist im Dashboard erreichbar.
        setTimeout(() => {
          toast.warn(
            'Temporäres Projekt abgelaufen',
            `„${last.name}“ wurde zuletzt ${relativeTime(last.updatedAt)} bearbeitet und gilt nach ${Math.round(
              EXPIRY_MS / 3600000,
            )} Stunden als abgelaufen. Du findest es weiterhin im Dashboard.`,
          );
        }, 1200);
      }
    }
  } catch (err) {
    console.error('[AI Groove] Projekt konnte nicht geladen werden', err);
    toast.error(
      'Lokaler Speicher nicht verfügbar',
      'AI Groove läuft weiter, kann aber nichts sichern. Im privaten Modus mancher Browser ist das normal.',
    );
    // Ohne Speicher trotzdem arbeitsfähig bleiben.
    try {
      await store.setProject(store.project, { fresh: false });
    } catch (_) {
      /* egal */
    }
  }

  store.startAutosave();
  requestPersistence();
  collectGarbage(store.project);

  startRouter(appEl);
  appEl.hidden = false;

  // Startbildschirm ausblenden.
  bootEl.classList.add('boot--done');
  setTimeout(() => bootEl.remove(), 500);

  // --- 4. Audio --------------------------------------------------------------
  attachUnlockHandlers();
  // Einmalig versuchen – klappt auf dem Desktop oft direkt.
  unlockAudio()
    .then(() => engine.init())
    .catch(() => {});

  bus.on(EV.PROJECT_LOADED, () => engine.preloadAll());

  restoreDiagnostics();

  if (location.hash.startsWith('#/studio')) {
    maybeStartTutorial();
  }
}

boot();

// --- 5. Service Worker -------------------------------------------------------
if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker
      .register(new URL('../../sw.js', import.meta.url), { scope: './' })
      .then((registration) => {
        registration.addEventListener?.('updatefound', () => {
          const worker = registration.installing;
          worker?.addEventListener('statechange', () => {
            if (worker.state === 'installed' && navigator.serviceWorker.controller) {
              toast.info('Update wird geladen', 'Die neue Version wird jetzt übernommen.');
            }
          });
        });

        // Sobald die neue Fassung die Kontrolle uebernimmt, wird die Seite
        // einmal neu geladen. Ohne das laeuft im Fenster weiter der alte
        // Programmcode aus dem alten Zwischenspeicher.
        //
        // Achtung: Das kann erst ab dieser Fassung wirken. Beim Sprung von
        // einer aelteren Fassung laeuft in diesem Moment noch deren main.js
        // ohne diesen Handler – dort ist einmalig ein zweites Neuladen noetig.
        // Erzwungenes Neuladen aus dem Service Worker heraus waere der
        // naheliegende Ausweg, blockiert dort aber die Aktivierung.
        let reloading = false;
        navigator.serviceWorker.addEventListener('controllerchange', () => {
          if (reloading) return;
          reloading = true;
          window.location.reload();
        });
      })
      .catch((err) => {
        console.warn('[AI Groove] Service Worker nicht registriert', err);
      });
  });
}

// Für die Diagnose in der Konsole erreichbar (keine Geheimnisse enthalten).
window.AIGroove = {
  version: APP_VERSION,
  store,
  engine,
  bus,
  navigate,
};
