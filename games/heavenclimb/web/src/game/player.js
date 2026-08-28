// Der Kletterer: Zustandsautomat, Sprunglogik, Animationsauswahl.
// Kein DOM – die Physiktests fahren diese Datei direkt in Node.

import { PHYS, T, TILE } from './constants.js';
import { approach, moveAndCollide, overlappingTiles, wallSide } from './physics.js';

/** Reihenfolge und Länge der Animationen. `hold` = letztes Bild stehen lassen. */
export const ANIMATIONS = {
  idle: { frames: 4, ticksPerFrame: 8, loop: true },
  run: { frames: 6, ticksPerFrame: 5, loop: true },
  jump: { frames: 2, ticksPerFrame: 6, loop: false, hold: true },
  fall: { frames: 2, ticksPerFrame: 8, loop: true },
  spin: { frames: 4, ticksPerFrame: 3, loop: false, next: 'fall' },
  wallslide: { frames: 2, ticksPerFrame: 6, loop: true },
  land: { frames: 2, ticksPerFrame: 4, loop: false, next: 'idle' },
  death: { frames: 5, ticksPerFrame: 6, loop: false, hold: true },
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
    prevX: startX,
    prevY: startY,
    facing: 1,
    // Der Spieler startet stehend. Ohne das wäre der allererste Sprung ein
    // Doppelsprung, weil onGround erst nach der ersten Kollision gesetzt wird.
    onGround: true,
    sliding: false,
    slideSide: 0,
    coyote: PHYS.coyoteTicks,
    wallCoyote: 0,
    wallCoyoteSide: 0,
    jumpBuffer: 0,
    airJumps: PHYS.maxAirJumps,
    lockout: 0,
    dropThrough: 0,
    dead: false,
    deadTicks: 0,
    gems: 0,
    /** Höchster erreichter Punkt (kleinstes y). */
    bestY: startY,
    anim: 'idle',
    animFrame: 0,
    animTick: 0,
    landTimer: 0,
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
 * ('jump', 'doubleJump', 'wallJump', 'land', 'gem', 'spring', 'death').
 *
 * @param {ReturnType<typeof createPlayer>} player
 * @param {{left:boolean,right:boolean,down:boolean,jump:boolean,
 *   jumpPressed:boolean,jumpReleased:boolean}} input
 * @param {{tileAt:Function,setTile:Function,touchCrumble:Function}} level
 * @param {string[]} events
 */
export function updatePlayer(player, input, level, events) {
  const body = player.body;
  player.prevX = body.x;
  player.prevY = body.y;

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
  let dir = (input.right ? 1 : 0) - (input.left ? 1 : 0);
  if (player.lockout > 0) {
    player.lockout -= 1;
    // Direkt nach dem Wandsprung zählt die Eingabe nur zu einem Viertel.
    // Ohne das könnte man den Abstoss sofort wegdrücken und klebte an der Wand.
    dir *= 0.25;
  }
  if (dir > 0) player.facing = 1;
  else if (dir < 0) player.facing = -1;

  if (input.jumpPressed) player.jumpBuffer = PHYS.jumpBufferTicks;
  else if (player.jumpBuffer > 0) player.jumpBuffer -= 1;

  if (input.down) player.dropThrough = 12;
  else if (player.dropThrough > 0) player.dropThrough -= 1;

  // --- Wandkontakt ---------------------------------------------------------
  const side = wallSide(body, level);
  if (side !== 0 && !player.onGround) {
    player.wallCoyote = PHYS.wallCoyoteTicks;
    player.wallCoyoteSide = side;
  } else if (player.wallCoyote > 0) {
    player.wallCoyote -= 1;
  }

  player.sliding = !player.onGround && side !== 0 && body.vy > 0;
  player.slideSide = player.sliding ? side : 0;

  // --- Springen ------------------------------------------------------------
  if (player.jumpBuffer > 0) {
    if (player.onGround || player.coyote > 0) {
      body.vy = PHYS.jumpVel;
      player.coyote = 0;
      player.jumpBuffer = 0;
      player.airJumps = PHYS.maxAirJumps;
      player.onGround = false;
      setAnim(player, 'jump');
      events.push('jump');
    } else if (player.wallCoyote > 0) {
      const away = -player.wallCoyoteSide;
      body.vy = PHYS.wallJumpVy;
      body.vx = away * PHYS.wallJumpVx;
      player.facing = away;
      player.lockout = PHYS.wallJumpLockout;
      player.wallCoyote = 0;
      player.jumpBuffer = 0;
      // Der Wandsprung füllt den Doppelsprung wieder auf – das ist der Grund,
      // warum sich Wandsprungketten gut anfühlen.
      player.airJumps = PHYS.maxAirJumps;
      player.sliding = false;
      setAnim(player, 'jump');
      events.push('wallJump');
    } else if (player.airJumps > 0) {
      body.vy = PHYS.doubleJumpVel;
      player.airJumps -= 1;
      player.jumpBuffer = 0;
      setAnim(player, 'spin');
      events.push('doubleJump');
    }
  }

  if (input.jumpReleased && body.vy < 0) body.vy *= PHYS.jumpCut;

  // --- Waagerechte Bewegung ------------------------------------------------
  const target = dir * PHYS.runMax;
  if (player.onGround) {
    body.vx = approach(body.vx, target, PHYS.groundAccel, PHYS.groundDecel);
  } else {
    body.vx = approach(body.vx, target, PHYS.airAccel, PHYS.airDecel);
  }
  body.vx = Math.max(-PHYS.maxVx, Math.min(PHYS.maxVx, body.vx));

  // --- Schwerkraft ---------------------------------------------------------
  body.vy += body.vy < 0 ? PHYS.gravityUp : PHYS.gravityDown;
  const fallCap = player.sliding ? PHYS.wallSlideMaxFall : PHYS.maxFallSpeed;
  if (body.vy > fallCap) body.vy = fallCap;

  // --- Bewegen und stossen -------------------------------------------------
  const wasOnGround = player.onGround;
  const hit = moveAndCollide(body, level, { dropThrough: player.dropThrough > 0 });
  player.onGround = hit.onGround;

  if (hit.onGround) {
    player.coyote = PHYS.coyoteTicks;
    player.airJumps = PHYS.maxAirJumps;
    if (!wasOnGround) {
      player.landTimer = 8;
      events.push('land');
    }
    if (hit.groundTile === T.CRUMBLE) level.touchCrumble(hit.groundCol, hit.groundRow);
    if (hit.groundTile === T.SPRING) {
      body.vy = PHYS.springVel;
      player.onGround = false;
      player.airJumps = PHYS.maxAirJumps;
      setAnim(player, 'jump');
      events.push('spring');
    }
  } else if (player.coyote > 0) {
    player.coyote -= 1;
  }

  if (player.landTimer > 0) player.landTimer -= 1;

  // --- Kachelwirkungen -----------------------------------------------------
  for (const cell of overlappingTiles(body, level)) {
    if (cell.tile === T.SPIKE) {
      kill(player, events);
      return;
    }
    if (cell.tile === T.GEM) {
      level.setTile(cell.col, cell.row, T.EMPTY);
      player.gems += 1;
      events.push('gem');
    }
  }

  if (body.y < player.bestY) player.bestY = body.y;

  // --- Animation -----------------------------------------------------------
  chooseAnim(player);
  advanceAnim(player);
}

function chooseAnim(player) {
  if (player.dead) return;
  if (player.sliding) {
    setAnim(player, 'wallslide');
    return;
  }
  if (!player.onGround) {
    if (player.anim === 'spin') return; // Salto zu Ende laufen lassen
    setAnim(player, player.body.vy < 0 ? 'jump' : 'fall');
    return;
  }
  if (player.landTimer > 4) {
    setAnim(player, 'land');
    return;
  }
  setAnim(player, Math.abs(player.body.vx) > 0.25 ? 'run' : 'idle');
}

export function kill(player, events) {
  if (player.dead) return;
  player.dead = true;
  player.deadTicks = 0;
  player.body.vy = -3.2;
  player.body.vx = 0;
  setAnim(player, 'death');
  events.push('death');
}

/** Erreichte Höhe in Metern. Eine Kachel = ein Meter. */
export function heightInMetres(player, startY) {
  return Math.max(0, Math.floor((startY - player.bestY) / TILE));
}
