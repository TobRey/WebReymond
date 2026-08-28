/**
 * AI Groove – Taktgeber-Worker.
 *
 * Warum ein Worker? Browser drosseln setInterval im Haupt-Thread stark, sobald
 * der Tab in den Hintergrund geht oder die Seite viel rendert. Ein Worker-Timer
 * laeuft davon unabhaengig weiter.
 *
 * WICHTIG: Dieser Timer bestimmt NICHT den Klangzeitpunkt. Er weckt nur den
 * Scheduler auf. Die exakten Startzeiten kommen ausschliesslich aus
 * AudioContext.currentTime (Sample-genau).
 */

let timer = 0;
let interval = 25;

self.onmessage = (event) => {
  const data = event.data || {};
  switch (data.cmd) {
    case 'start':
      if (data.interval) interval = Math.max(5, Math.min(200, data.interval));
      if (timer) clearInterval(timer);
      timer = setInterval(() => self.postMessage('tick'), interval);
      break;
    case 'interval':
      interval = Math.max(5, Math.min(200, data.interval || 25));
      if (timer) {
        clearInterval(timer);
        timer = setInterval(() => self.postMessage('tick'), interval);
      }
      break;
    case 'stop':
      if (timer) clearInterval(timer);
      timer = 0;
      break;
    default:
      break;
  }
};
