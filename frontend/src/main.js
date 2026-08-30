/**
 * WebAtze – Einstiegspunkt des Frontends
 *
 * Reihenfolge mit Absicht:
 *   1. Sofort: Kopfzeile, Menü, Theme, Formulare – alles, was man anfassen kann.
 *   2. Danach: Einblendungen und Parallax.
 *   3. Zuletzt und nur wenn das Gerät es verkraftet: die 3D-Szene.
 *
 * So ist die Seite bedienbar, lange bevor das schwere Stück geladen ist.
 */

import './styles/app.css';
import { env } from './scripts/env.js';
import {
  initReveals,
  initWordReveal,
  initParallax,
  initScrollProgress,
  initTilt,
  initMagnets,
  initCursor,
  initCounters,
  initMarquee,
  initThumbnails,
} from './scripts/motion.js';
import {
  initHeader,
  initMobileNav,
  initTheme,
  initAccordion,
  initContactForm,
  initAnchors,
  initReadingProgress,
} from './scripts/ui.js';

/** Verrät dem CSS, dass JavaScript läuft – erst dann wird etwas ausgeblendet. */
document.documentElement.classList.add('js');

function boot() {
  // --- Sofort bedienbar ---
  initTheme();
  initHeader();
  initMobileNav();
  initAccordion();
  initContactForm();
  initAnchors();

  // --- Bewegung ---
  initWordReveal();
  initReveals();
  initScrollProgress();
  initReadingProgress();
  initParallax();
  initMarquee();
  initCounters();
  initThumbnails();
  initTilt();
  initMagnets();
  initCursor();

  // --- Der schwere Teil ganz zum Schluss ---
  loadHeroScene();
}

/**
 * Die 3D-Szene wird als eigenes Paket nachgeladen. Sie steckt deshalb
 * nicht im Haupt-JavaScript und bremst den ersten Aufbau nicht.
 */
function loadHeroScene() {
  const stage = document.querySelector('[data-hero-stage]');
  if (!stage) return;

  if (!env.wants3D) {
    showFallback(stage);
    return;
  }

  const start = () => {
    import('./scenes/hero.js')
      .then(({ initHero }) => {
        const scene = initHero(stage);
        if (!scene) showFallback(stage);
        // Beim Verlassen der Seite sauber aufräumen.
        window.addEventListener('pagehide', () => scene?.destroy(), { once: true });
      })
      .catch(() => showFallback(stage));
  };

  // Warten, bis der Browser Luft hat.
  if ('requestIdleCallback' in window) {
    requestIdleCallback(start, { timeout: 1800 });
  } else {
    setTimeout(start, 300);
  }
}

/** Ersatzhintergrund – bewusst gestaltet, nicht bloss leer. */
function showFallback(stage) {
  stage.classList.add('is-fallback');
  if (document.querySelector('.wa-fallback-bg')) return;

  const fallback = document.createElement('div');
  fallback.className = 'wa-fallback-bg';
  fallback.setAttribute('aria-hidden', 'true');
  document.body.prepend(fallback);
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', boot, { once: true });
} else {
  boot();
}
