// Fester Logikschritt, entkoppeltes Zeichnen.
//
// Warum nicht einfach dt an die Physik durchreichen: bei variablem Schritt
// hängt die Sprunghöhe von der Bildrate ab, und dieselbe Eingabe ergibt auf
// zwei Rechnern zwei verschiedene Läufe. Mit festem Schritt ist ein Lauf aus
// Startwert plus Eingabe reproduzierbar – die Grundlage der Tests.

const STEP_MS = 1000 / 60;
/** Längster Zeitsprung, der noch aufgeholt wird. Alles darüber wird verworfen. */
const MAX_FRAME_MS = 250;

export function createLoop({ update, render }) {
  let running = false;
  let handle = 0;
  let previous = 0;
  let accumulator = 0;

  function frame(now) {
    if (!running) return;
    handle = window.requestAnimationFrame(frame);

    let elapsed = now - previous;
    previous = now;
    // Nach einem Tabwechsel oder einem Haltepunkt im Debugger wäre elapsed
    // riesig. Ohne die Deckelung holt die Schleife hunderte Schritte am Stück
    // nach und friert den Browser ein ("spiral of death").
    if (elapsed > MAX_FRAME_MS) elapsed = MAX_FRAME_MS;
    if (elapsed < 0) elapsed = 0;

    accumulator += elapsed;
    let steps = 0;
    while (accumulator >= STEP_MS && steps < 5) {
      update();
      accumulator -= STEP_MS;
      steps += 1;
    }
    if (steps === 5) accumulator = 0;

    render();
  }

  return {
    start() {
      if (running) return;
      running = true;
      previous = window.performance.now();
      accumulator = 0;
      handle = window.requestAnimationFrame(frame);
    },
    stop() {
      running = false;
      window.cancelAnimationFrame(handle);
    },
    /** Nach einer Pause aufrufen, sonst wird die Pausenzeit nachgeholt. */
    resetClock() {
      previous = window.performance.now();
      accumulator = 0;
    },
    get running() {
      return running;
    },
  };
}
