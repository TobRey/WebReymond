// Ganzzahlige Skalierung ohne Weichzeichnen – der heikelste Teil der
// Darstellung.
//
// Der verbreitete Weg (CSS-Breite = 240·S px plus image-rendering: pixelated)
// geht bei gebrochenem devicePixelRatio schief: 1.5 oder 2.625 sind auf
// Windows- und Android-Geräten üblich, und dann liegt ein Spielpixel nicht
// mehr auf einem ganzzahligen Vielfachen von Gerätepixeln – das Bild flimmert.
//
// Deshalb wird die Skalierung in GERÄTEpixeln berechnet und der Zeichenpuffer
// des sichtbaren Canvas exakt auf ein ganzzahliges Vielfaches gesetzt. Die
// CSS-Grösse darf dabei ruhig gebrochen sein; der Browser bildet sie 1:1 auf
// Gerätepixel ab.

import { VIEW_H, VIEW_W } from '../game/constants.js';

/** Der Puffer, in den das Spiel zeichnet: immer exakt 240 × 320. */
export function createBuffer() {
  const canvas = document.createElement('canvas');
  canvas.width = VIEW_W;
  canvas.height = VIEW_H;
  const ctx = canvas.getContext('2d', { alpha: false });
  ctx.imageSmoothingEnabled = false;
  return { canvas, ctx };
}

/**
 * Passt den sichtbaren Canvas an den verfügbaren Platz an.
 * @param {HTMLCanvasElement} screen
 * @param {HTMLElement} area Der Kasten, der den Platz vorgibt.
 * @returns {number} der gewählte ganzzahlige Vergrösserungsfaktor
 */
export function fitScreen(screen, area) {
  const dpr = window.devicePixelRatio || 1;
  const box = area.getBoundingClientRect();

  const scale = Math.max(
    1,
    Math.floor(Math.min((box.width * dpr) / VIEW_W, (box.height * dpr) / VIEW_H)),
  );

  const deviceW = VIEW_W * scale;
  const deviceH = VIEW_H * scale;
  if (screen.width !== deviceW || screen.height !== deviceH) {
    screen.width = deviceW;
    screen.height = deviceH;
  }
  screen.style.width = `${deviceW / dpr}px`;
  screen.style.height = `${deviceH / dpr}px`;

  const ctx = screen.getContext('2d', { alpha: false });
  ctx.imageSmoothingEnabled = false;
  return scale;
}

/** Überträgt den Puffer auf den Bildschirm. */
export function present(screen, buffer) {
  const ctx = screen.getContext('2d', { alpha: false });
  ctx.imageSmoothingEnabled = false;
  ctx.drawImage(buffer, 0, 0, screen.width, screen.height);
}

/**
 * Hängt die Grössenanpassung an alle Ereignisse, die sie auslösen können, und
 * bündelt sie über ein einzelnes requestAnimationFrame.
 */
export function watchResize(screen, area, onResize) {
  let queued = false;
  const schedule = () => {
    if (queued) return;
    queued = true;
    window.requestAnimationFrame(() => {
      queued = false;
      onResize(fitScreen(screen, area));
    });
  };
  window.addEventListener('resize', schedule);
  window.addEventListener('orientationchange', schedule);
  if (window.visualViewport) window.visualViewport.addEventListener('resize', schedule);
  schedule();
  return schedule;
}
