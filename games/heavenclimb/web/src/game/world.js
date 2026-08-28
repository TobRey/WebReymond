// Bindet Level, Spieler, Gefahr und Kamera zusammen. Bewusst ohne DOM: so
// kann ein kompletter Lauf in Node simuliert werden (Tests, Selbsttest).

import { CAM, PHYS, TILE, VIEW_H, VIEW_W } from './constants.js';
import { createLevel, difficultyFromHeight } from './level.js';
import { createHazard, updateHazard } from './hazard.js';
import { createPlayer, heightInMetres, kill, updatePlayer } from './player.js';

/** Punkte pro eingesammeltem Edelstein, in Metern. */
export const GEM_BONUS = 25;

export function createWorld(seed) {
  const level = createLevel(seed);
  // Startzeile 0 ist Boden, der Spieler steht darauf.
  const startY = -PHYS.hitboxH;
  const startX = Math.floor(VIEW_W / 2 - PHYS.hitboxW / 2);

  level.ensureUpTo(-Math.ceil(VIEW_H / TILE) - 8);

  const player = createPlayer(startX, startY);
  const hazard = createHazard(startY);
  const cameraTarget = startY - (VIEW_H - CAM.playerOffset);

  const world = {
    seed,
    level,
    player,
    hazard,
    startY,
    tick: 0,
    over: false,
    camera: { y: cameraTarget, minY: cameraTarget },
    /** Ereignisse des letzten Ticks, für Ton und Partikel. */
    events: [],
    /** Kacheln, die in diesem Tick zerbröselt sind – für Staubwolken. */
    crumbled: [],

    get heightPx() {
      return Math.max(0, startY - player.bestY);
    },
    get metres() {
      return heightInMetres(player, startY);
    },
    get score() {
      return heightInMetres(player, startY) + player.gems * GEM_BONUS;
    },
    get difficulty() {
      return difficultyFromHeight(Math.max(0, startY - player.bestY));
    },
    get seconds() {
      return world.tick / 60;
    },
    update,
  };

  function update(input) {
    world.events.length = 0;
    if (world.over) return world.events;

    world.tick += 1;

    // Zuerst zerfallen lassen, dann bewegen. Andersherum kann eine Plattform
    // mitten in einem Bewegungsteilschritt verschwinden – das ist der
    // klassische "ich falle grundlos durch den Boden"-Fehler.
    world.crumbled = level.updateCrumbling();

    updatePlayer(player, input, level, world.events);

    // Kamera folgt nur nach oben.
    const target = player.body.y - (VIEW_H - CAM.playerOffset);
    if (target < world.camera.minY) world.camera.minY = target;
    world.camera.y += (world.camera.minY - world.camera.y) * CAM.smoothing;

    updateHazard(hazard, world.tick, world.heightPx, player.body.y, world.camera.y);

    if (!player.dead && player.body.y + player.body.h >= hazard.y) {
      kill(player, world.events);
    }

    // Nachschieben und aufräumen.
    level.ensureUpTo(Math.floor(world.camera.y / TILE) - 24);
    level.cullBelow(Math.floor(hazard.y / TILE) + 6);

    if (player.dead && player.deadTicks > 70) world.over = true;

    return world.events;
  }

  return world;
}

/** Für die Anzeige: wie voll der Gefahrenbalken ist (0…1). */
export function hazardPressure(world) {
  const lead = world.hazard.y - (world.player.body.y + world.player.body.h);
  return Math.max(0, Math.min(1, 1 - lead / 260));
}

/** Nur für Tests und Debugging: eine leere Eingabe. */
export function emptyInput() {
  return {
    left: false,
    right: false,
    down: false,
    jump: false,
    jumpPressed: false,
    jumpReleased: false,
  };
}
