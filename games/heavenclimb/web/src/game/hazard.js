// Die aufsteigende Gefahr ("die Flut"). Sie ist der einzige Grund, warum ein
// Lauf überhaupt endet – und gleichzeitig die untere Todeslinie, weshalb es
// keine gesonderte Regel für "unten aus dem Bild gefallen" braucht.

import { HAZARD, VIEW_H } from './constants.js';

export function createHazard(startY) {
  return { y: startY + HAZARD.startOffset, speed: HAZARD.baseSpeed };
}

/**
 * Geschwindigkeit in px/Tick.
 * @param {number} tick Ticks seit Spielbeginn.
 * @param {number} heightPx Bisher erklommene Höhe in Pixeln.
 * @param {number} lead Abstand der Gefahr unter dem Spieler, in Pixeln.
 */
export function hazardSpeed(tick, heightPx, lead) {
  let v =
    HAZARD.baseSpeed +
    Math.min(HAZARD.heightBonus, (heightPx / HAZARD.heightBonusAt) * HAZARD.heightBonus);

  // Wer sehr lange spielt, soll nicht ewig weiterspielen können.
  if (tick > HAZARD.lateStartTick) {
    v += Math.min(HAZARD.lateBonusMax, (tick - HAZARD.lateStartTick) * HAZARD.lateBonusPerTick);
  }

  // Nachrücken, damit ein guter Lauf die Gefahr nicht abhängt …
  if (lead > HAZARD.catchUpLead) v *= HAZARD.catchUpFactor;
  // … und kurz Luft holen lassen, wenn es knapp wird.
  else if (lead < HAZARD.mercyLead) v *= HAZARD.mercyFactor;

  return Math.min(v, HAZARD.maxSpeed);
}

/**
 * @param {{y:number,speed:number}} hazard
 * @param {number} tick
 * @param {number} heightPx
 * @param {number} playerY
 * @param {number} cameraY Weltkoordinate der oberen Bildkante.
 */
export function updateHazard(hazard, tick, heightPx, playerY, cameraY) {
  hazard.speed = hazardSpeed(tick, heightPx, hazard.y - playerY);
  hazard.y -= hazard.speed;
  // Nie weiter als knapp unter den unteren Bildrand zurückfallen: sonst kann
  // man beliebig tief fallen, ohne zu sterben.
  hazard.y = Math.min(hazard.y, cameraY + VIEW_H + 8);
}
