/**
 * AI Groove – Seite „KI“.
 *
 * Frueher wurde hier ein fremder Zugang eingerichtet. Das ist nicht mehr
 * noetig: AI Groove bringt mit GrooveNet eine eigene KI mit, die vollstaendig
 * im Browser laeuft.
 *
 * Diese Seite zeigt den Zustand der Engine, stellt die Rechenqualitaet ein
 * und macht sichtbar, wie ein Prompt verstanden wird.
 */

import { h, clear, segmented } from '../core/dom.js';
import { navigate } from './router.js';
import { toast } from './toast.js';
import { activeProvider, engineStatus, setQuality, getQuality, analysePrompt } from '../ai/providers.js';
import { generateVariants } from '../ai/generate.js';
import { settings } from '../core/settings.js';

const QUALITY_OPTIONS = [
  { value: 'fast', label: 'Schnell', hint: 'Wenige Sekunden. Gut zum Ausprobieren und für schwache Geräte.' },
  { value: 'balanced', label: 'Ausgewogen', hint: 'Vorgabe. Guter Kompromiss aus Rechenzeit und Treffsicherheit.' },
  { value: 'best', label: 'Beste Qualität', hint: 'Deutlich mehr Suchdurchläufe. Braucht länger, trifft den Prompt genauer.' },
];

/** Klangarten in verständlichen Worten. */
const KIND_LABELS = {
  kick: 'Kick', sub: 'Sub-Bass', bass: 'Bass', snare: 'Snare', clap: 'Clap',
  hat: 'Hi-Hat', openhat: 'Offene Hi-Hat', cymbal: 'Becken', tom: 'Tom',
  perc: 'Percussion', rim: 'Rimshot', stab: 'Stab', chord: 'Akkord', pad: 'Pad',
  drone: 'Drone', lead: 'Lead', pluck: 'Pluck', bell: 'Glocke', vocal: 'Vocal',
  riser: 'Riser', impact: 'Impact', sweep: 'Sweep', texture: 'Textur',
};

export function createAiConnectView() {
  const element = h('div.page');
  const inner = h('div.page__inner');
  element.appendChild(inner);

  let quality = settings.get('aiQuality') || getQuality();
  setQuality(quality);

  const analysisBox = h('div.settings-group');
  const testBox = h('div.settings-group');

  /** Zeigt, wie ein Beispieltext verstanden wird. */
  function renderAnalysis(text) {
    clear(analysisBox);
    analysisBox.appendChild(h('h2.section-title', { text: 'Prompt-Prüfung' }));
    analysisBox.appendChild(
      h('p.prose', {
        text: 'Hier lässt sich nachsehen, was die KI aus einem Text herausliest – bevor ein Klang erzeugt wird.',
      }),
    );

    const input = h('input.input', {
      type: 'text',
      value: text,
      placeholder: 'z. B. dunkler analoger Techno-Kick, sehr punchy, kein Hall',
      maxLength: 300,
    });
    analysisBox.appendChild(input);

    const result = h('div.settings-group__rows');
    analysisBox.appendChild(result);

    const update = () => {
      clear(result);
      const value = input.value.trim();
      if (!value) return;
      const a = analysePrompt(value);
      const rows = [
        ['Klangart', KIND_LABELS[a.kind] || a.kind],
        ['Ergebnis', a.mode === 'loop' ? 'Schleife (mehrere Spuren)' : 'Einzelklang'],
        ['Stil', a.genre || 'nicht genannt'],
        ['Tempo', a.bpm ? `${a.bpm} bpm` : 'nicht genannt'],
        ['Tonart', a.root != null ? `${noteName(a.root)} ${a.scale}` : a.scale],
        ['Vorgeschlagene Länge', `${a.seconds.toFixed(2)} s`],
        ['Erkannte Begriffe', a.matched.length ? a.matched.map((m) => m.term).join(', ') : 'keine'],
      ];
      for (const [label, value2] of rows) {
        const row = h('div.settings-row');
        row.appendChild(h('div.settings-row__label', null, [h('b', { text: label })]));
        row.appendChild(h('div.settings-row__control', null, [h('span', { text: String(value2) })]));
        result.appendChild(row);
      }
    };

    input.addEventListener('input', update);
    update();
  }

  /** Kurzer Selbsttest: erzeugt einen Klang und meldet die Passgenauigkeit. */
  function renderTest() {
    clear(testBox);
    testBox.appendChild(h('h2.section-title', { text: 'Selbsttest' }));
    const line = h('p.field__hint', { text: 'Erzeugt einen kurzen Testklang und misst, wie genau er zum Text passt.' });
    testBox.appendChild(line);

    const btn = h('button.btn.btn--sm', { type: 'button', text: 'Test starten' });
    btn.addEventListener('click', async () => {
      btn.disabled = true;
      line.textContent = 'Test läuft …';
      try {
        const started = Date.now();
        const variants = await generateVariants({
          prompt: 'punchy techno kick, dark',
          duration: 0.7,
          count: 1,
          onProgress: (p, label) => {
            line.textContent = `${label || 'Test läuft'} (${Math.round(p * 100)} %)`;
          },
        });
        const match = variants[0]?.meta?.match;
        line.textContent = `Test bestanden: Klang in ${((Date.now() - started) / 1000).toFixed(1)} s erzeugt${
          match != null ? `, Passgenauigkeit ${match} %` : ''
        }.`;
        toast('Die KI arbeitet einwandfrei.');
      } catch (error) {
        line.textContent = `Test fehlgeschlagen: ${error.message}`;
        toast('Der Test ist fehlgeschlagen.', 'error');
      } finally {
        btn.disabled = false;
      }
    });
    testBox.appendChild(btn);
  }

  function build() {
    clear(inner);
    const provider = activeProvider();
    const status = engineStatus();

    inner.appendChild(h('h1.page__title', { text: 'KI' }));
    inner.appendChild(
      h('p.prose', {
        text: 'AI Groove erzeugt Klänge mit einer eigenen KI. Es gibt nichts einzurichten und nichts zu bezahlen.',
      }),
    );

    // --- Zustand -------------------------------------------------------------
    const statusBox = h('div.settings-group');
    statusBox.appendChild(h('h2.section-title', { text: 'Engine' }));
    for (const [label, value] of [
      ['Name', provider.label],
      ['Version', status.engine],
      ['Rechenweg', status.worker],
      ['Internet nötig', 'nein'],
      ['Kosten', 'keine'],
      ['API-Key', 'nicht erforderlich'],
    ]) {
      const row = h('div.settings-row');
      row.appendChild(h('div.settings-row__label', null, [h('b', { text: label })]));
      row.appendChild(h('div.settings-row__control', null, [h('span', { text: value })]));
      statusBox.appendChild(row);
    }
    statusBox.appendChild(h('p.field__hint', { text: provider.description }));
    inner.appendChild(statusBox);

    // --- Qualitaet -----------------------------------------------------------
    const qualityBox = h('div.settings-group');
    qualityBox.appendChild(h('h2.section-title', { text: 'Rechenqualität' }));
    const hint = h('p.field__hint', { text: QUALITY_OPTIONS.find((o) => o.value === quality)?.hint || '' });
    const seg = segmented(
      QUALITY_OPTIONS.map((o) => ({ label: o.label, value: o.value })),
      quality,
      (value) => {
        quality = value;
        setQuality(value);
        settings.set('aiQuality', value);
        hint.textContent = QUALITY_OPTIONS.find((o) => o.value === value)?.hint || '';
        toast(`Rechenqualität: ${QUALITY_OPTIONS.find((o) => o.value === value)?.label}`);
      },
    );
    qualityBox.appendChild(seg);
    qualityBox.appendChild(hint);
    qualityBox.appendChild(
      h('p.field__hint', {
        text: 'Die Einstellung wirkt auf Einzelklänge. Schleifen werden immer vollständig berechnet.',
      }),
    );
    inner.appendChild(qualityBox);

    // --- Wie es arbeitet -----------------------------------------------------
    const howBox = h('div.settings-group');
    howBox.appendChild(h('h2.section-title', { text: 'So arbeitet die KI' }));
    const steps = h('ol.prose');
    for (const line of [
      'Der Text wird in Wörter zerlegt und mit einem eingebauten Klanglexikon abgeglichen – deutsch und englisch, auch bei Tippfehlern und zusammengesetzten Wörtern.',
      'Daraus entsteht ein Profil aus 16 Klangeigenschaften: Helligkeit, Wucht, Anschlag, Ausklang, Rauschanteil, Raum und weitere.',
      'Die KI stellt ein Zielspektrum auf: so soll der Klang über Frequenz und Zeit aussehen.',
      'Ein Suchverfahren erzeugt viele Klangkandidaten, misst jeden gegen das Ziel und verbessert die besten weiter (Differentielle Evolution).',
      'Der beste Kandidat wird in voller Auflösung berechnet, geräumlicht und für die Ausgabe aufbereitet.',
    ]) {
      steps.appendChild(h('li', { text: line }));
    }
    howBox.appendChild(steps);
    inner.appendChild(howBox);

    // --- Datenschutz ---------------------------------------------------------
    const privacy = h('div.settings-group');
    privacy.appendChild(h('h2.section-title', { text: 'Datenschutz' }));
    const list = h('ul.prose');
    for (const line of [
      'Prompts verlassen das Gerät nicht – es gibt keine Anfrage an einen Dienst.',
      'Samples werden ausschließlich lokal verarbeitet.',
      'Es werden keine Zugangsdaten benötigt, also können auch keine verloren gehen.',
      'Die Klangerzeugung funktioniert ohne Internet, auch als installierte App.',
    ]) {
      list.appendChild(h('li', { text: line }));
    }
    privacy.appendChild(list);
    inner.appendChild(privacy);

    inner.appendChild(analysisBox);
    renderAnalysis('dunkler analoger Techno-Kick, sehr punchy, kein Hall');

    inner.appendChild(testBox);
    renderTest();

    const backHome = h('button.btn.btn--sm', { type: 'button', text: 'Zum Studio' });
    backHome.addEventListener('click', () => navigate('/studio'));
    inner.appendChild(backHome);
  }

  return {
    element,
    onShow() {
      quality = settings.get('aiQuality') || getQuality();
      setQuality(quality);
      build();
    },
  };
}

/** Notenname einer MIDI-Nummer, z. B. 45 -> „A2“. */
function noteName(midi) {
  const names = ['C', 'C#', 'D', 'D#', 'E', 'F', 'F#', 'G', 'G#', 'A', 'A#', 'B'];
  return `${names[((midi % 12) + 12) % 12]}${Math.floor(midi / 12) - 1}`;
}
