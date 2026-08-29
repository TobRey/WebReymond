// Mogli: Zustandsautomat, Sprunglogik, Animationsauswahl.
// Kein DOM – die Physiktests fahren diese Datei direkt in Node.
//
// Die Steuerung hat genau einen Knopf. Mogli läuft von allein, ein Tippen
// springt. Die Laufrichtung dreht sich an zwei Stellen und nur dort:
//   - beim Wandsprung (er stösst sich ab und läuft fortan andersherum)
//   - wenn er am Boden gegen eine Wand rennt (er dreht einfach um)
// Alles andere – wohin er läuft, wann er beschleunigt – nimmt ihm das Spiel ab.

import { PHYS, T, TILE } from './constants.js';
import { approach, moveAndCollide, overlappingTiles, wallSide } from './physics.js';

/** Reihenfolge und Länge der Animationen. `hold` = letztes Bild stehen lassen. */
export const ANIMATIONS = {
  idle: { frames: 4, ticksPerFrame: 9, loop: true },
  run: { frames: 8, ticksPerFrame: 4, loop: true },
  jump: { frames: 2, ticksPerFrame: 6, loop: false, hold: true },
  fall: { frames: 2, ticksPerFrame: 7, loop: true },
  wallslide: { frames: 2, ticksPerFrame: 6, loop: true },
  land: { frames: 2, ticksPerFrame: 4, loop: false, next: 'idle' },
  vine: { frames: 3, ticksPerFrame: 4, loop: true },
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
    /** 1 = nach rechts, -1 = nach links. Dreht sich nur an Wänden. */
    facing: 1,
    // Der Spieler startet stehend. Ohne das wäre der allererste Sprung keiner,
    // weil onGround erst nach der ersten Kollision gesetzt wird.
    onGround: true,
    sliding: false,
    slideSide: 0,
    coyote: PHYS.coyoteTicks,
    wallCoyote: 0,
    wallCoyoteSide: 0,
    jumpBuffer: 0,
    lockout: 0,
    /** Restticks des Rankenzugs. > 0 heisst: Schwerkraft ist ausgesetzt. */
    vine: 0,
    dead: false,
    deadTicks: 0,
    emeralds: 0,
    vinesUsed: 0,
    /** Höchster erreichter Punkt (kleinstes y). */
    bestY: startY,
    anim: 'idle',
    animFrame: 0,
    animTick: 0,
    landTimer: 0,
    /** Zählt Wandsprünge – nur für die Anzeige und die Tests. */
    wallJumps: 0,
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
 * ('jump', 'wallJump', 'turn', 'land', 'emerald', 'vine', 'death').
 *
 * @param {ReturnType<typeof createPlayer>} player
 * @param {{jump:boolean, jumpPressed:boolean, jumpReleased:boolean}} input
 * @param {{tileAt:Function,setTile:Function,touchCrumble:Function}} level
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

  if (player.lockout > 0) player.lockout -= 1;

  // --- Wandkontakt ---------------------------------------------------------
  const side = wallSide(body, level);
  if (side !== 0 && !player.onGround) {
    player.wallCoyote = PHYS.wallCoyoteTicks;
    player.wallCoyoteSide = side;
  } else if (player.wallCoyote > 0) {
    player.wallCoyote -= 1;
  }

  player.sliding = !player.onGround && player.vine === 0 && side !== 0 && body.vy > 0;
  player.slideSide = player.sliding ? side : 0;

  // --- Springen ------------------------------------------------------------
  // Genau ein Knopf, und die Reihenfolge entscheidet: am Boden ein normaler
  // Sprung, an der Wand ein Wandsprung. Einen dritten Fall gibt es nicht.
  if (player.jumpBuffer > 0 && player.vine === 0) {
    if (player.onGround || player.coyote > 0) {
      body.vy = PHYS.jumpVel;
      player.coyote = 0;
      player.jumpBuffer = 0;
      player.onGround = false;
      setAnim(player, 'jump');
      events.push('jump');
    } else if (player.wallCoyote > 0) {
      const away = -player.wallCoyoteSide;
      body.vy = PHYS.wallJumpVy;
      body.vx = away * PHYS.wallJumpVx;
      // Hier und nur hier dreht sich die Laufrichtung im Flug.
      player.facing = away;
      player.lockout = PHYS.wallJumpLockout;
      player.wallCoyote = 0;
      player.jumpBuffer = 0;
      player.sliding = false;
      player.wallJumps += 1;
      setAnim(player, 'jump');
      events.push('wallJump');
    }
  }

  // --- Ranke ---------------------------------------------------------------
  if (player.vine > 0) {
    player.vine -= 1;
    body.vy = PHYS.vineSpeed;
    // Waagerecht ausbremsen, damit der Zug spürbar senkrecht ist.
    body.vx *= PHYS.vineDrag;
    setAnim(player, 'vine');
  } else {
    // --- Waagerechte Bewegung: von allein, immer in Blickrichtung ----------
    const target = player.facing * PHYS.runMax;
    let accel = player.onGround ? PHYS.groundAccel : PHYS.airAccel;
    // Kurz nach dem Wandsprung schwächer beschleunigen, damit der Abstoss
    // spürbar bleibt und nicht sofort auf Lauftempo eingeebnet wird.
    if (player.lockout > 0) accel *= 0.5;
    body.vx = approach(body.vx, target, accel, accel);
    body.vx = Math.max(-PHYS.maxVx, Math.min(PHYS.maxVx, body.vx));

    // --- Schwerkraft ------------------------------------------------------
    body.vy += body.vy < 0 ? PHYS.gravityUp : PHYS.gravityDown;
    const fallCap = player.sliding ? PHYS.wallSlideMaxFall : PHYS.maxFallSpeed;
    if (body.vy > fallCap) body.vy = fallCap;
  }

  // --- Bewegen und stossen -------------------------------------------------
  const wasOnGround = player.onGround;
  const hit = moveAndCollide(body, level);
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

  // Am Boden gegen eine Wand gelaufen: einfach umdrehen. Ohne diese Regel
  // stünde Mogli für immer an der Wand und drückte dagegen.
  if (player.onGround) {
    if (hit.hitRight && player.facing > 0) {
      player.facing = -1;
      events.push('turn');
    } else if (hit.hitLeft && player.facing < 0) {
      player.facing = 1;
      events.push('turn');
    }
  }

  if (player.landTimer > 0) player.landTimer -= 1;

  // --- Kachelwirkungen -----------------------------------------------------
  for (const cell of overlappingTiles(body, level)) {
    if (cell.tile === T.SPIKE) {
      kill(player, events);
      return;
    }
    if (cell.tile === T.EMERALD) {
      level.setTile(cell.col, cell.row, T.EMPTY);
      player.emeralds += 1;
      events.push('emerald');
    } else if (cell.tile === T.VINE && player.vine === 0) {
      level.setTile(cell.col, cell.row, T.EMPTY);
      player.vine = PHYS.vineTicks;
      player.vinesUsed += 1;
      player.sliding = false;
      setAnim(player, 'vine');
      events.push('vine');
    }
  }

  if (body.y < player.bestY) player.bestY = body.y;

  // --- Animation -----------------------------------------------------------
  chooseAnim(player);
  advanceAnim(player);
}

function chooseAnim(player) {
  if (player.dead) return;
  if (player.vine > 0) {
    setAnim(player, 'vine');
    return;
  }
  if (player.sliding) {
    setAnim(player, 'wallslide');
    return;
  }
  if (!player.onGround) {
    setAnim(player, player.body.vy < 0 ? 'jump' : 'fall');
    return;
  }
  if (player.landTimer > 4) {
    setAnim(player, 'land');
    return;
  }
  setAnim(player, Math.abs(player.body.vx) > 0.3 ? 'run' : 'idle');
}

/**
 * Ein Tick, in dem nichts passiert: Mogli steht und atmet.
 *
 * Wird vor dem ersten Tipp gefahren. Ohne das liefe er sofort los, und ein
 * neuer Spieler hätte nach dem Start eine Fünftelsekunde bis zum ersten
 * Abgrund – der Lauf wäre vorbei, bevor er begriffen hat, dass er läuft.
 */
export function idleTick(player) {
  setAnim(player, 'idle');
  advanceAnim(player);
}

export function kill(player, events) {
  if (player.dead) return;
  player.dead = true;
  player.deadTicks = 0;
  player.vine = 0;
  player.body.vy = -3.4;
  player.body.vx = 0;
  setAnim(player, 'death');
  events.push('death');
}

/** Erreichte Höhe in Metern. Eine Kachel = ein Meter. */
export function heightInMetres(player, startY) {
  return Math.max(0, Math.floor((startY - player.bestY) / TILE));
}
