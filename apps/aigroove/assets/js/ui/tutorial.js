/**
 * AI Groove – kurze interaktive Einführung (Coach Marks).
 *
 * Fünf Schritte, jederzeit abbrechbar, kein langer Text.
 */

import { h } from '../core/dom.js';
import { settings } from '../core/settings.js';
import { qs } from '../core/dom.js';

const STEPS = [
  {
    target: '[data-tour="add-sample"]',
    title: '1. Sound hinzufügen',
    text: 'Hier legst du Sounds an: Datei hochladen, Mikrofon aufnehmen oder mit KI erzeugen.',
  },
  {
    target: '[data-tour="pads"]',
    title: '2. Auf ein Pad ziehen',
    text: 'Ziehe ein Sample aus der Liste auf ein Pad. Antippen spielt es sofort – das ist dein Launchpad.',
  },
  {
    target: '[data-tour="transport"]',
    title: '3. Tempo und Wiedergabe',
    text: 'Play, Aufnahme, Tempo, Swing, Metronom und Loop. Die Leertaste startet und stoppt.',
  },
  {
    target: '[data-tour="patterns"]',
    title: '4. Pattern bauen',
    text: 'Lege Patterns an und setze im Raster Schritte. Für Melodien gibt es die Piano Roll.',
  },
  {
    target: '[data-tour="export"]',
    title: '5. Exportieren',
    text: 'Wenn der Track sitzt: als WAV oder MP3 320 herunterladen. Fertig.',
  },
];

let active = null;

export function openTutorial() {
  closeTutorial();

  let index = 0;
  const spot = h('div.coach-spot');
  const card = h('div.coach');
  const title = h('div.coach__title');
  const text = h('div.coach__text');
  const foot = h('div.coach__foot');
  const count = h('div.coach__count');
  const skip = h('button.btn.btn--sm.btn--ghost', { type: 'button', text: 'Überspringen' });
  const next = h('button.btn.btn--sm.btn--primary', { type: 'button', text: 'Weiter' });

  foot.appendChild(count);
  foot.appendChild(skip);
  foot.appendChild(next);
  card.appendChild(title);
  card.appendChild(text);
  card.appendChild(foot);

  document.body.appendChild(spot);
  document.body.appendChild(card);

  skip.addEventListener('click', () => finish());
  next.addEventListener('click', () => {
    index++;
    if (index >= STEPS.length) finish();
    else show();
  });

  function show() {
    const step = STEPS[index];
    let target = qs(step.target);

    // Ist das Ziel gerade nicht sichtbar (z. B. andere Ansicht), Schritt überspringen.
    let guard = 0;
    while ((!target || target.offsetParent === null) && guard++ < STEPS.length) {
      index++;
      if (index >= STEPS.length) {
        finish();
        return;
      }
      target = qs(STEPS[index].target);
    }
    if (!target) {
      finish();
      return;
    }

    const current = STEPS[index];
    const rect = target.getBoundingClientRect();
    const pad = 6;
    spot.style.left = `${rect.left - pad}px`;
    spot.style.top = `${rect.top - pad}px`;
    spot.style.width = `${rect.width + pad * 2}px`;
    spot.style.height = `${rect.height + pad * 2}px`;

    title.textContent = current.title;
    text.textContent = current.text;
    count.textContent = `${index + 1} / ${STEPS.length}`;
    next.textContent = index === STEPS.length - 1 ? 'Fertig' : 'Weiter';

    // Karte unter oder über dem Ziel platzieren – immer im Bild.
    const cardRect = card.getBoundingClientRect();
    const below = rect.bottom + 12;
    const above = rect.top - cardRect.height - 12;
    const top = below + cardRect.height < window.innerHeight - 12 ? below : Math.max(12, above);
    const left = Math.min(
      Math.max(12, rect.left + rect.width / 2 - cardRect.width / 2),
      window.innerWidth - cardRect.width - 12,
    );
    card.style.top = `${top}px`;
    card.style.left = `${left}px`;
  }

  function finish() {
    settings.set('tutorialDone', true);
    closeTutorial();
  }

  const onResize = () => show();
  window.addEventListener('resize', onResize);

  active = {
    close() {
      window.removeEventListener('resize', onResize);
      spot.remove();
      card.remove();
    },
  };

  requestAnimationFrame(show);
  return active;
}

export function closeTutorial() {
  if (active) {
    active.close();
    active = null;
  }
}

/** Startet die Einführung beim ersten Besuch automatisch. */
export function maybeStartTutorial() {
  if (settings.get('tutorialDone')) return;
  setTimeout(() => openTutorial(), 900);
}
