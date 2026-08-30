// Bindet Karte, Mogli, Elemente und Kamera zusammen. Bewusst ohne DOM: so
// kann ein kompletter Lauf in Node simuliert werden (Tests).

import { CAM, PHYS, VIEW_H, VIEW_W } from './constants.js';
import { buildLevel } from './map.js';
import { createEntities, carryPlayer, touchEntities, updateEntities } from './entities.js';
import { createPlayer, kill, respawn, updatePlayer } from './player.js';

/**
 * @param {object} map eine durch mapRules geprüfte Karte
 */
export function createWorld(map) {
  const level = buildLevel(map);
  const entities = createEntities(map);

  const startX = map.spawn.x * map.cell + (map.cell - PHYS.hitboxW) / 2;
  const startY = (map.spawn.y + 1) * map.cell - PHYS.hitboxH;
  const player = createPlayer(startX, startY);

  const world = {
    map,
    level,
    entities,
    player,
    tick: 0,
    /** Vor der ersten Eingabe steht die Uhr – man bestimmt selbst den Start. */
    started: false,
    finished: false,
    /** Läufe enden am Ziel, nicht am Tod: gestorben heisst zurückgesetzt. */
    deaths: 0,
    keys: 0,
    /** Zeitgutschrift aus Smaragden, in Ticks. */
    bonusTicks: 0,
    respawnAt: { x: startX, y: startY },
    camera: { x: 0, y: 0 },
    /** Ereignisse des letzten Ticks, für Ton und Partikel. */
    events: [],
    crumbled: [],

    /** Die Zeit, die zählt: Uhr minus Smaragd-Gutschrift, nie unter 0. */
    get resultTicks() {
      return Math.max(0, world.tick - world.bonusTicks);
    },
    get seconds() {
      return world.tick / 60;
    },
    update,
  };

  placeCamera(world, true);

  function update(input) {
    world.events.length = 0;
    if (world.finished) return world.events;

    // Die Uhr startet mit der ersten echten Eingabe, nicht beim Laden.
    if (!world.started) {
      if (!input.left && !input.right && !input.jumpPressed) {
        placeCamera(world, false);
        return world.events;
      }
      world.started = true;
    }

    world.tick += 1;

    // Reihenfolge je Tick, und sie ist Absicht:
    // 1. Zerfall (sonst verschwindet ein Block mitten im Bewegungsschritt)
    // 2. Elemente bewegen, 3. Spieler, 4. Plattform-Mitnahme, 5. Berührungen.
    //
    // Die Mitnahme kommt NACH dem Spieler: sie setzt onGround und den
    // Mitnahme-Schub für den nächsten Tick. Stand sie davor, überschrieb die
    // Kachelphysik ihr onGround wieder mit false – der Spieler stand auf der
    // Plattform und galt trotzdem als fallend, der Test hat es gefasst.
    world.crumbled = level.updateCrumbling();
    updateEntities(entities, world.tick);
    updatePlayer(player, input, level, world.events);
    carryPlayer(entities, player);

    // Kartenränder: links und rechts sind unsichtbare Wände, unten ist Schluss.
    const body = player.body;
    if (body.x < 0) {
      body.x = 0;
      body.vx = Math.max(0, body.vx);
    } else if (body.x + body.w > level.widthPx) {
      body.x = level.widthPx - body.w;
      body.vx = Math.min(0, body.vx);
    }
    if (!player.dead && body.y > level.heightPx + PHYS.fallOutMargin) {
      kill(player, world.events);
    }

    const touched = touchEntities(entities, player, world, world.events);
    if (touched.finished) {
      world.finished = true;
      return world.events;
    }

    // Tod ist kein Ende: kurz liegen lassen, dann zurück zum Checkpoint.
    // Die Uhr läuft weiter – der Zeitverlust IST die Strafe.
    if (player.dead && player.deadTicks > 55) {
      world.deaths += 1;
      respawn(player, world.respawnAt.x, world.respawnAt.y);
      world.events.push('respawn');
    }

    placeCamera(world, false);
    return world.events;
  }

  return world;
}

/**
 * Die Kamera: waagerecht ein Mario-Fenster (Totzone), senkrecht weiches
 * Folgen. Beides an die Kartenränder geklemmt – und reine Darstellung:
 * kein Wert hier darf je ins Spielgeschehen zurückfliessen
 * (test/viewheight.test.mjs besteht darauf).
 */
function placeCamera(world, snap) {
  const cam = world.camera;
  const body = world.player.body;
  const level = world.level;

  const left = cam.x + VIEW_W * CAM.deadLeft;
  const right = cam.x + VIEW_W * CAM.deadRight;
  let targetX = cam.x;
  if (body.x < left) targetX = body.x - VIEW_W * CAM.deadLeft;
  else if (body.x > right) targetX = body.x - VIEW_W * CAM.deadRight;
  targetX = Math.max(0, Math.min(level.widthPx - VIEW_W, targetX));

  let targetY = body.y - VIEW_H * CAM.playerAtY;
  if (level.heightPx <= VIEW_H) {
    // Karte niedriger als das Bild: unten anschlagen, oben füllt der
    // Hintergrund (cover kann das seit 2.2.1).
    targetY = level.heightPx - VIEW_H;
  } else {
    targetY = Math.max(0, Math.min(level.heightPx - VIEW_H, targetY));
  }

  if (snap) {
    cam.x = targetX;
    cam.y = targetY;
  } else {
    cam.x = targetX; // waagerecht hart – die Totzone ist die Glättung
    cam.y += (targetY - cam.y) * CAM.smoothingY;
  }
}

/** Zeit hübsch: "1:23.4". */
export function formatTicks(ticks) {
  const totalTenths = Math.round((ticks / 60) * 10);
  const minutes = Math.floor(totalTenths / 600);
  const rest = totalTenths - minutes * 600;
  const seconds = Math.floor(rest / 10);
  return `${minutes}:${String(seconds).padStart(2, '0')}.${rest % 10}`;
}

/** Nur für Tests: eine leere Eingabe. */
export function emptyInput() {
  return { left: false, right: false, jump: false, jumpPressed: false, jumpReleased: false };
}
