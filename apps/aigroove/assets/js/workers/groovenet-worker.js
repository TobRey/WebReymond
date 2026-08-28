/**
 * AI Groove – Rechenknecht der eigenen KI.
 *
 * Die Klangsuche ist rechenintensiv. Liefe sie im Hauptstrang, wuerde die
 * Oberflaeche waehrenddessen einfrieren und die Wiedergabe stottern. Deshalb
 * arbeitet GrooveNet hier in einem eigenen Arbeitsstrang (Web Worker).
 *
 * Nachrichten vom Hauptstrang:
 *   { id, op: 'generate' | 'transform', payload }
 *   { id, op: 'cancel' }
 *
 * Antworten:
 *   { id, type: 'progress', progress, label }
 *   { id, type: 'done', results }
 *   { id, type: 'error', message }
 */

import * as groovenet from '../ai/groovenet/index.js';

/** Auftraege, die abgebrochen wurden. */
const cancelled = new Set();

self.addEventListener('message', (event) => {
  const { id, op, payload } = event.data || {};

  if (op === 'cancel') {
    cancelled.add(id);
    return;
  }

  const onProgress = (progress, label) => {
    if (cancelled.has(id)) return;
    self.postMessage({ id, type: 'progress', progress, label });
  };
  const shouldStop = () => cancelled.has(id);

  try {
    const results =
      op === 'transform'
        ? groovenet.transform({ ...payload, onProgress })
        : groovenet.generate({ ...payload, onProgress, shouldStop });

    if (cancelled.has(id)) {
      cancelled.delete(id);
      return;
    }

    // Die Audiodaten werden uebergeben statt kopiert: kein zusaetzlicher
    // Speicher, keine Wartezeit bei langen Klaengen.
    const transfer = [];
    for (const item of results) {
      for (const channel of item.channels) transfer.push(channel.buffer);
    }
    self.postMessage({ id, type: 'done', results }, transfer);
  } catch (error) {
    self.postMessage({ id, type: 'error', message: String(error?.message || error) });
  } finally {
    cancelled.delete(id);
  }
});
