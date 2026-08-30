// Mogli: Zustandsautomat, Sprunglogik, Animationsauswahl.
// Kein DOM – die Physiktests fahren diese Datei direkt in Node.
//
// Seit 3.0 steuert der Spieler selbst: links, rechts, springen. Die
// Sprunghöhe ist variabel (loslassen kappt den Aufstieg) – das ist die
// Mario-Absprache, auf die sich jede Karte aus dem Editor verlassen darf.

import { PHYS, T } from './constants.js';
import { approach, moveAndCollide } from './physics.js';

/**
 * Reihenfolge und Länge der Animationen. `hold` = letztes Bild stehen lassen.
 *
 * Jede Bewegung hat genau FÜNF Bilder – die Absprache mit dem Admin-Bereich,
 * dort gibt es je Bewegung fünf Plätze. `wallslide` und `vine` stammen aus
 * dem Turm-Spiel; sie bleiben im Paketformat gültig (alte Grafikpakete sollen
 * nicht brechen), werden aber nicht mehr abgespielt.
 */
export const ANIMATIONS = {
  idle: { frames: 5, ticksPerFrame: 10, loop: true },
  run: { frames: 5, ticksPerFrame: 4, loop: true },
  jump: { frames: 5, ticksPerFrame: 5, loop: false, hold: true },
  fall: { frames: 5, ticksPerFrame: 6, loop: true },
  wallslide: { frames: 5, ticksPerFrame: 6, loop: true },
  land: { frames: 5, ticksPerFrame: 3, loop: false, next: 'idle' },
  vine: { frames: 5, ticksPerFrame: 3, loop: true },
  death: { frames: 5, ticksPerFrame: 7, loop: false, hold: true },
};

export function createPlayer(startX, startY) {
  return {
    body: {
      x: startX,
      y: startY,
      w: PHYS.hitboxW,
      h: PHYS.hitboxH,
      vx: 0,
      vy: 0,
    },
    /** 1 = nach rechts, -1 = nach links. Folgt der letzten Laufeingabe. */
    facing: 1,
    // Der Spieler startet stehend. Ohne das wäre der allererste Sprung
    // keiner, weil onGround erst nach der ersten Kollision gesetzt wird.
    onGround: true,
    coyote: PHYS.coyoteTicks,
    jumpBuffer: 0,
    /** Steigt gerade durch einen gehaltenen Sprung (kappbar). */
    jumping: false,
    dead: false,
    deadTicks: 0,
    emeralds: 0,
    anim: 'idle',
    animFrame: 0,
    animTick: 0,
    landTimer: 0,
    /** Von einer beweglichen Plattform mitgenommen (je Tick gesetzt). */
    carriedVx: 0,
  };
}

function setAnim(player, name) {
  if (player.anim === name) return;
  player.anim = name;
  player.animFrame = 0;
  player.animTick = 0;
}

function advanceAnim(player) {
  const def = ANIMATIONS[player.anim];
  player.animTick += 1;
  if (player.animTick < def.ticksPerFrame) return;
  player.animTick = 0;
  const last = def.frames - 1;
  if (player.animFrame < last) {
    player.animFrame += 1;
  } else if (def.loop) {
    player.animFrame = 0;
  } else if (def.next) {
    setAnim(player, def.next);
  }
  // `hold`: einfach auf dem letzten Bild stehen bleiben.
}

/**
 * Ein Logikschritt. Verändert `player` und hängt Ereignisse an `events` an
 * ('jump', 'land', 'death').
 *
 * @param {ReturnType<typeof createPlayer>} player
 * @param {{left:boolean,right:boolean,jump:boolean,jumpPressed:boolean,
 *   jumpReleased:boolean}} input
 * @param {{tileAt:Function,touchCrumble:Function}} level
 * @param {string[]} events
 */
export function updatePlayer(player, input, level, events) {
  const body = player.body;

  if (player.dead) {
    player.deadTicks += 1;
    // Nach dem Tod noch kurz fallen lassen, das liest sich besser als ein
    // eingefrorenes Bild.
    body.vy = Math.min(body.vy + PHYS.gravityDown, PHYS.maxFallSpeed);
    body.x += body.vx;
    body.y += body.vy;
    body.vx *= 0.9;
    advanceAnim(player);
    return;
  }

  // --- Eingabe -------------------------------------------------------------
  if (input.jumpPressed) player.jumpBuffer = PHYS.jumpBufferTicks;
  else if (player.jumpBuffer > 0) player.jumpBuffer -= 1;

  const move = (input.right ? 1 : 0) - (input.left ? 1 : 0);
  if (move !== 0) player.facing = move;

  // --- Springen ------------------------------------------------------------
  if (player.jumpBuffer > 0 && (player.onGround || player.coyote > 0)) {
    body.vy = PHYS.jumpVel;
    player.coyote = 0;
    player.jumpBuffer = 0;
    player.onGround = false;
    player.jumping = true;
    setAnim(player, 'jump');
    events.push('jump');
  }

  // Variable Sprunghöhe: Loslassen kappt den Aufstieg. Nur den eigenen
  // Sprung – der Rückstoss einer Feder oder eines Gegners bleibt ungekappt,
  // sonst fühlt sich die Feder je nach Fingerhaltung verschieden stark an.
  if (player.jumping && input.jumpReleased && body.vy < PHYS.jumpCutVel) {
    body.vy = PHYS.jumpCutVel;
  }
  if (body.vy >= 0) player.jumping = false;

  // --- Waagerechte Bewegung ------------------------------------------------
  const target = move * PHYS.runMax;
  const accel = player.onGround ? PHYS.groundAccel : PHYS.airAccel;
  const decel = player.onGround ? PHYS.groundDecel : PHYS.airDecel;
  body.vx = approach(body.vx, target, accel, decel);
  body.vx = Math.max(-PHYS.maxVx, Math.min(PHYS.maxVx, body.vx));

  // --- Schwerkraft ---------------------------------------------------------
  body.vy += body.vy < 0 ? PHYS.gravityUp : PHYS.gravityDown;
  if (body.vy > PHYS.maxFallSpeed) body.vy = PHYS.maxFallSpeed;

  // --- Bewegen und stossen -------------------------------------------------
  // Die Mitnahme durch eine bewegliche Plattform ist eine eigene Grösse und
  // fliesst nicht in vx ein: sie darf weder gebremst noch gekappt werden,
  // und beim Absprung nimmt man sie als Schwung mit (entities.js setzt sie).
  const wasOnGround = player.onGround;
  const savedVx = body.vx;
  body.vx += player.carriedVx;
  const hit = moveAndCollide(body, level);
  body.vx = body.vx === 0 ? 0 : savedVx;
  player.carriedVx = 0;
  player.onGround = hit.onGround;

  if (hit.onGround) {
    player.coyote = PHYS.coyoteTicks;
    if (!wasOnGround) {
      player.landTimer = 8;
      events.push('land');
    }
    if (hit.groundTile === T.CRUMBLE) level.touchCrumble(hit.groundCol, hit.groundRow);
  } else if (player.coyote > 0) {
    player.coyote -= 1;
  }

  if (hit.hitTop) player.jumping = false;
  if (player.landTimer > 0) player.landTimer -= 1;

  // --- Animation -----------------------------------------------------------
  chooseAnim(player, move);
  advanceAnim(player);
}

function chooseAnim(player, move) {
  if (player.dead) return;
  if (!player.onGround) {
    setAnim(player, player.body.vy < 0 ? 'jump' : 'fall');
    return;
  }
  if (player.landTimer > 4) {
    setAnim(player, 'land');
    return;
  }
  setAnim(player, move !== 0 || Math.abs(player.body.vx) > 0.3 ? 'run' : 'idle');
}

/** Ein Tick, in dem nichts passiert: Mogli steht und atmet (Menü-Vorschau). */
export function idleTick(player) {
  setAnim(player, 'idle');
  advanceAnim(player);
}

export function kill(player, events) {
  if (player.dead) return;
  player.dead = true;
  player.deadTicks = 0;
  player.body.vy = -3.4;
  player.body.vx = 0;
  setAnim(player, 'death');
  events.push('death');
}

/** Zurück ins Leben am Wiedereinstiegspunkt – die Uhr läuft weiter. */
export function respawn(player, x, y) {
  player.body.x = x;
  player.body.y = y;
  player.body.vx = 0;
  player.body.vy = 0;
  player.dead = false;
  player.deadTicks = 0;
  player.onGround = false;
  player.jumping = false;
  player.coyote = 0;
  player.jumpBuffer = 0;
  player.facing = 1;
  setAnim(player, 'idle');
}
