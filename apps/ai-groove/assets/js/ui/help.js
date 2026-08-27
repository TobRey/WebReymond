/**
 * AI Groove – Hilfeseite.
 * Bewusst kurz: fünf Schritte, die wichtigsten Gesten, häufige Fragen.
 */

import { h, icon, clear } from '../core/dom.js';
import { navigate } from './router.js';
import { openTutorial } from './tutorial.js';
import { settings } from '../core/settings.js';

const STEPS = [
  ['Sound hinzufügen', 'Links auf „+ Neues Sample“ tippen: Datei hochladen, Mikrofon aufnehmen oder mit KI erzeugen.'],
  ['Auf ein Pad ziehen', 'Das Sample aus der Liste auf ein Pad ziehen. Antippen spielt es sofort – ohne Verzögerung.'],
  ['Pattern bauen', 'In der Ansicht „Sequence“ Schritte setzen. Für Melodien die Piano Roll verwenden.'],
  ['Arrangement erstellen', 'In „Arrange“ Patterns auf die Spuren ziehen, verschieben, kopieren und loopen.'],
  ['Exportieren', 'Über „Export“ als WAV oder MP3 (320 kbit/s) herunterladen.'],
];

const GESTURES = [
  ['Ein Finger im Raster', 'Schritte setzen / Noten und Clips bearbeiten'],
  ['Zwei Finger', 'Verschieben und Zoomen'],
  ['Langes Antippen', 'Kontextmenü (Bearbeiten, Duplizieren, Löschen …)'],
  ['Doppeltippen auf einen Clip', 'Zugehöriges Pattern öffnen'],
  ['Ziehen am Clipende', 'Länge ändern (Trimmen)'],
];

const FAQ = [
  [
    'Werden meine Projekte auf dem Server gespeichert?',
    'Nein. Alles bleibt in deinem Browser (IndexedDB). Nach etwa 12 Stunden ohne Bearbeitung gilt ein Projekt als temporär abgelaufen – es ist dann noch da, wird aber als abgelaufen markiert. Für dauerhafte Sicherung als .aigroove-Datei exportieren.',
  ],
  [
    'Brauche ich einen KI-Zugang?',
    'Nein. Der eingebaute lokale Generator erzeugt Drums, Bass, Stabs, Riser und FX ohne Internet. Für Modelle wie Stable Audio kann jeder seinen eigenen API-Key hinterlegen.',
  ],
  [
    'Warum ist ein neues Sample so leise?',
    'Neue Samples starten bei 30 %, damit sich mehrere Spuren summieren lassen, ohne zu übersteuern. Im Mixer lässt sich das bis 100 % (und darüber als Gain-Effekt) anheben.',
  ],
  [
    'Der Ton startet auf dem iPhone nicht.',
    'Safari erlaubt Audio erst nach einer Berührung. Einmal irgendwo tippen genügt. Achte ausserdem darauf, dass der Stummschalter am Gerät nicht aktiv ist.',
  ],
  [
    'Kann ich AI Groove wie eine App installieren?',
    'Ja. Auf dem iPhone in Safari über „Teilen → Zum Home-Bildschirm“. Auf Android und dem Desktop bietet der Browser die Installation direkt an.',
  ],
];

export function createHelpView() {
  const element = h('div.page');
  const inner = h('div.page__inner');
  element.appendChild(inner);

  function build() {
    clear(inner);

    const head = h('div.page-head');
    const backBtn = h('button.btn.btn--ghost.btn--sm', { type: 'button' });
    const backIcon = icon('chevron', 16);
    backIcon.style.transform = 'rotate(180deg)';
    backBtn.appendChild(backIcon);
    backBtn.appendChild(h('span', { text: 'Zurück' }));
    backBtn.addEventListener('click', () => history.back());
    head.appendChild(backBtn);
    head.appendChild(h('h1', { text: 'Hilfe', style: { fontSize: '22px' } }));
    inner.appendChild(head);

    // Schritte
    const stepsBox = h('section');
    stepsBox.appendChild(h('h2.section-title', { text: 'In fünf Schritten zum Track' }));
    const steps = h('div.steps');
    for (const [title, text] of STEPS) {
      steps.appendChild(h('div.step', null, [h('div', null, [h('b', { text: title }), h('span', { text })])]));
    }
    stepsBox.appendChild(steps);

    const startBtn = h('button.btn.btn--primary', { type: 'button', style: { marginTop: '16px' } });
    startBtn.appendChild(icon('play', 16));
    startBtn.appendChild(h('span', { text: 'Interaktive Einführung starten' }));
    startBtn.addEventListener('click', () => {
      settings.set('tutorialDone', false);
      navigate('/studio');
      setTimeout(() => openTutorial(), 400);
    });
    stepsBox.appendChild(startBtn);
    inner.appendChild(stepsBox);

    // Gesten
    const gestureBox = h('section');
    gestureBox.appendChild(h('h2.section-title', { text: 'Bedienung' }));
    const table = h('table.kbd-table');
    for (const [gesture, meaning] of GESTURES) {
      const tr = h('tr');
      tr.appendChild(h('td', { text: meaning }));
      const td = h('td');
      td.appendChild(h('kbd', { text: gesture }));
      tr.appendChild(td);
      table.appendChild(tr);
    }
    gestureBox.appendChild(table);
    inner.appendChild(gestureBox);

    // FAQ
    const faqBox = h('section');
    faqBox.appendChild(h('h2.section-title', { text: 'Häufige Fragen' }));
    const prose = h('div.prose');
    for (const [question, answer] of FAQ) {
      prose.appendChild(h('h3', { text: question }));
      prose.appendChild(h('p', { text: answer }));
    }
    faqBox.appendChild(prose);
    inner.appendChild(faqBox);

    // Links
    const links = h('div.sed__tools');
    const aiBtn = h('button.btn.btn--sm', { type: 'button', text: 'KI verbinden' });
    aiBtn.addEventListener('click', () => navigate('/ai'));
    const setBtn = h('button.btn.btn--sm', { type: 'button', text: 'Einstellungen' });
    setBtn.addEventListener('click', () => navigate('/settings'));
    const studioBtn = h('button.btn.btn--sm', { type: 'button', text: 'Zum Studio' });
    studioBtn.addEventListener('click', () => navigate('/studio'));
    links.appendChild(aiBtn);
    links.appendChild(setBtn);
    links.appendChild(studioBtn);
    inner.appendChild(links);
  }

  return {
    element,
    onShow: build,
  };
}
