/**
 * AI Groove – interner Diagnose-Modus.
 *
 * Für normale Nutzer verborgen. Erreichbar über:
 *   • Studio-Menü → „Diagnose“
 *   • langes Antippen der Statuszeile neben dem Projektnamen
 *   • Ctrl/Cmd + Alt + D (HUD ein/aus)
 *
 * Zeigt Audio-Latenz, Scheduler-Genauigkeit, Speicher und Browserfähigkeiten.
 */

import { h, icon } from '../core/dom.js';
import { openModal } from './modal.js';
import { engine } from '../audio/engine.js';
import { store } from '../core/store.js';
import { latencyInfo } from '../audio/context.js';
import { estimateStorage } from '../core/idb.js';
import { checkProxy } from '../ai/providers.js';
import { keystore } from '../ai/keystore.js';
import { humanBytes, isIOS, isSafari, isTouch } from '../core/util.js';
import { settings } from '../core/settings.js';

let hud = null;
let hudRaf = 0;

/** Kleine Dauereinblendung mit den wichtigsten Werten. */
export function toggleDiagnosticsHud(force) {
  const shouldShow = force == null ? !hud : force;
  if (!shouldShow) {
    if (hud) {
      cancelAnimationFrame(hudRaf);
      hud.remove();
      hud = null;
    }
    settings.set('diagnostics', false);
    return false;
  }
  if (hud) return true;

  hud = h('div.diag-hud');
  document.body.appendChild(hud);
  settings.set('diagnostics', true);

  let frames = 0;
  let lastTime = performance.now();
  let fps = 0;

  const paint = () => {
    frames++;
    const now = performance.now();
    if (now - lastTime >= 500) {
      fps = Math.round((frames * 1000) / (now - lastTime));
      frames = 0;
      lastTime = now;
    }
    const d = engine.diagnostics();
    hud.textContent = [
      `FPS ${String(fps).padStart(3)}   Stimmen ${d.voices}`,
      `SR ${d.sampleRate} Hz  Status ${d.state}`,
      `Latenz ${d.baseLatency}/${d.outputLatency} ms`,
      `Lookahead ${d.lookahead} ms  Drift ${d.drift} ms`,
      `Position ${d.position} t  Modus ${d.mode}`,
      `Events ${d.timelineEvents}  Fenster ${d.lastWindow}`,
      `Buffer ${d.buffers} (${humanBytes(d.bufferMemory)})`,
    ].join('\n');
    hudRaf = requestAnimationFrame(paint);
  };
  hudRaf = requestAnimationFrame(paint);
  return true;
}

function row(label, value) {
  return h('div.diag__row', null, [h('span', { text: label }), h('b', { text: String(value) })]);
}

export async function openDiagnostics() {
  const body = h('div.diag');

  const audioSection = h('div');
  audioSection.appendChild(h('div.insp-section__title', { text: 'Audio' }));
  body.appendChild(audioSection);

  const schedSection = h('div');
  schedSection.appendChild(h('div.insp-section__title', { text: 'Scheduler' }));
  body.appendChild(schedSection);

  const sysSection = h('div');
  sysSection.appendChild(h('div.insp-section__title', { text: 'System & Browser' }));
  body.appendChild(sysSection);

  const storeSection = h('div');
  storeSection.appendChild(h('div.insp-section__title', { text: 'Speicher' }));
  body.appendChild(storeSection);

  const aiSection = h('div');
  aiSection.appendChild(h('div.insp-section__title', { text: 'KI' }));
  body.appendChild(aiSection);

  function refreshAudio() {
    const d = engine.diagnostics();
    const l = latencyInfo();
    audioSection.replaceChildren(
      h('div.insp-section__title', { text: 'Audio' }),
      row('Abtastrate', `${d.sampleRate} Hz`),
      row('Context-Status', d.state),
      row('Basis-Latenz', `${d.baseLatency} ms`),
      row('Ausgabe-Latenz', `${d.outputLatency} ms`),
      row('Gesamtlatenz (geschätzt)', `${(Number(d.baseLatency) + Number(d.outputLatency)).toFixed(2)} ms`),
      row('Gehaltene Stimmen (Pads/Vorhören)', d.voices),
      row('Dekodierte Samples', `${d.buffers} (${humanBytes(d.bufferMemory)})`),
      row('iOS erkannt', l.ios ? 'ja' : 'nein'),
    );

    schedSection.replaceChildren(
      h('div.insp-section__title', { text: 'Scheduler' }),
      row('Modus', d.mode),
      row('Lookahead', `${d.lookahead} ms`),
      row('Planungsabweichung', `${d.drift} ms`),
      row('Ereignisse in der Zeitachse', d.timelineEvents),
      row('Ereignisse im letzten Fenster', d.lastWindow),
      row('Position', `${d.position} Ticks`),
      row('Tempo', `${store.project.bpm} BPM`),
      row('Swing', `${Math.round(store.project.swing * 100)} %`),
      row('Taktgeber', 'Web Worker (25 ms) + AudioContext-Zeit'),
    );
  }
  refreshAudio();

  sysSection.appendChild(row('Plattform', navigator.platform || 'unbekannt'));
  sysSection.appendChild(row('Touch-Punkte', navigator.maxTouchPoints || 0));
  sysSection.appendChild(row('Touch-Gerät', isTouch ? 'ja' : 'nein'));
  sysSection.appendChild(row('Safari', isSafari ? 'ja' : 'nein'));
  sysSection.appendChild(row('iOS/iPadOS', isIOS ? 'ja' : 'nein'));
  sysSection.appendChild(row('Sichere Verbindung (HTTPS)', window.isSecureContext ? 'ja' : 'nein'));
  sysSection.appendChild(row('AudioWorklet', 'audioWorklet' in (window.AudioContext?.prototype || {}) ? 'ja' : 'nein'));
  sysSection.appendChild(row('OfflineAudioContext', window.OfflineAudioContext ? 'ja' : 'nein'));
  sysSection.appendChild(row('MediaRecorder', window.MediaRecorder ? 'ja' : 'nein'));
  sysSection.appendChild(row('Service Worker', 'serviceWorker' in navigator ? 'ja' : 'nein'));
  sysSection.appendChild(row('Bildschirm', `${window.innerWidth} × ${window.innerHeight} @${window.devicePixelRatio}x`));
  sysSection.appendChild(row('Leistungsmodus', settings.get('performanceMode')));

  const est = await estimateStorage();
  storeSection.appendChild(row('Belegt', humanBytes(est.usage)));
  storeSection.appendChild(row('Kontingent', est.quota ? humanBytes(est.quota) : 'unbekannt'));
  storeSection.appendChild(row('Persistent', (await navigator.storage?.persisted?.()) ? 'ja' : 'nein'));
  storeSection.appendChild(row('Samples im Projekt', store.project.samples.length));
  storeSection.appendChild(row('Patterns', store.project.patterns.length));
  storeSection.appendChild(row('Clips', store.project.clips.length));
  storeSection.appendChild(row('Undo-Schritte', store.history.past.length));

  aiSection.appendChild(row('Verbunden', keystore.isConnected ? 'ja' : 'nein'));
  aiSection.appendChild(row('Anbieter', keystore.providerId));
  aiSection.appendChild(row('Übertragung', keystore.transport === 'direct' ? 'direkt' : 'über eigenen Server'));
  aiSection.appendChild(row('Key-Speicher', keystore.mode === 'session' ? 'nur Sitzung' : 'lokal'));
  const proxyRow = row('Proxy erreichbar', 'wird geprüft …');
  aiSection.appendChild(proxyRow);
  checkProxy().then((result) => {
    proxyRow.lastChild.textContent = result.ok
      ? `ja (PHP ${result.php}${result.curl ? ', cURL' : ', ohne cURL'})`
      : result.message || 'nein';
  });

  const hudBtn = h('button.btn.btn--sm', { type: 'button' });
  hudBtn.appendChild(icon('power', 15));
  hudBtn.appendChild(h('span', { text: hud ? 'Live-Anzeige ausblenden' : 'Live-Anzeige einblenden' }));
  hudBtn.addEventListener('click', () => {
    const on = toggleDiagnosticsHud();
    hudBtn.lastChild.textContent = on ? 'Live-Anzeige ausblenden' : 'Live-Anzeige einblenden';
  });

  const refreshBtn = h('button.btn.btn--sm', { type: 'button', text: 'Werte aktualisieren' });
  refreshBtn.addEventListener('click', refreshAudio);

  body.appendChild(h('div.sed__tools', null, [hudBtn, refreshBtn]));

  return openModal({
    title: 'Diagnose',
    size: 'wide',
    body,
    actions: [{ label: 'Schliessen', variant: 'primary' }],
  });
}

/** Beim Start wiederherstellen, falls der Nutzer die Anzeige aktiviert hatte. */
export function restoreDiagnostics() {
  if (settings.get('diagnostics')) toggleDiagnosticsHud(true);
}
