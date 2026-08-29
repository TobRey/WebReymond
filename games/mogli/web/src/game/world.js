// Bindet Level, Mogli, Gefahr und Kamera zusammen. Bewusst ohne DOM: so
// kann ein kompletter Lauf in Node simuliert werden (Tests, Automat).

import { CAM, EMERALD_BONUS, PHYS, TILE, VIEW_H, cameraOffset } from './constants.js';
import { createLevel, difficultyFromHeight } from './level.js';
import { createHazard, updateHazard } from './hazard.js';
import { createPlayer, heightInMetres, idleTick, kill, updatePlayer } from './player.js';

export { EMERALD_BONUS };

/** Der Weck-Tipp wird verbraucht und nicht als Sprung durchgereicht. */
const WAKE_INPUT = { jump: false, jumpPressed: false, jumpReleased: false };

export function createWorld(seed) {
  const level = createLevel(seed);
  // Startzeile 0 ist der Absatz an der linken Wand, Mogli steht darauf und
  // schaut nach rechts.
  const startY = -PHYS.hitboxH;
  // Ganz aussen an der linken Wand: so hat er den gesamten Startabsatz als
  // Anlauf, genau wie bei jedem späteren Absatz auch.
  const startX = TILE + (TILE - PHYS.hitboxW) / 2;

  level.ensureUpTo(-Math.ceil(VIEW_H / TILE) - 8);

  const player = createPlayer(startX, startY);
  const hazard = createHazard(startY);
  const cameraTarget = startY - (VIEW_H - cameraOffset());

  const world = {
    seed,
    level,
    player,
    hazard,
    startY,
    tick: 0,
    over: false,
    /** Vor dem ersten Tipp steht alles still – auch die Glut. */
    started: false,
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
      return heightInMetres(player, startY) + player.emeralds * EMERALD_BONUS;
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

    // Der Lauf beginnt beim ersten Tipp, nicht beim Öffnen des Bildschirms.
    // Bis dahin steht alles still, auch die Glut – man bestimmt selbst, wann
    // es losgeht.
    //
    // Dieser erste Tipp weckt Mogli nur auf, er springt noch nicht. Täte er
    // es, flöge er vom Startabsatz direkt darüber hinaus, bevor er überhaupt
    // gelaufen ist: der Sprung trägt 82 px, der Absatz ist 80 px breit.
    let effective = input;
    if (!world.started) {
      if (!input.jumpPressed) {
        idleTick(player);
        return world.events;
      }
      world.started = true;
      effective = WAKE_INPUT;
    }

    world.tick += 1;

    // Zuerst zerfallen lassen, dann bewegen. Andersherum kann ein Quader
    // mitten in einem Bewegungsteilschritt verschwinden – das ist der
    // klassische "ich falle grundlos durch den Boden"-Fehler.
    world.crumbled = level.updateCrumbling();

    updatePlayer(player, effective, level, world.events);

    // Kamera folgt nur nach oben.
    const target = player.body.y - (VIEW_H - cameraOffset());
    if (target < world.camera.minY) world.camera.minY = target;
    world.camera.y += (world.camera.minY - world.camera.y) * CAM.smoothing;

    updateHazard(hazard, world.tick, world.heightPx, player.body.y, player.bestY);

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
  return Math.max(0, Math.min(1, 1 - lead / 240));
}

/** Nur für Tests und den Automaten: eine leere Eingabe. */
export function emptyInput() {
  return { jump: false, jumpPressed: false, jumpReleased: false };
}
