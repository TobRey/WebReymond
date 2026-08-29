// Ein einfacher Automat, der das Spiel selbst spielt.
//
// Er hat zwei Aufgaben:
//   1. Im Menü und hinter der Story klettert er im Hintergrund – der
//      Bildschirm ist nie tot.
//   2. In den Tests zeigt er, dass der erzeugte Turm mit der echten Physik
//      begehbar ist und man nirgends endgültig feststeckt.
//
// Bei einem Ein-Knopf-Spiel ist ein Automat erfreulich kurz: er muss nur
// entscheiden, WANN getippt wird. Zwei Regeln genügen:
//
//   - An der Wand rutschend und der Wandsprung ist noch frei: sofort tippen.
//     Der Wandsprung dreht die Laufrichtung und ist die einzige Art, dort
//     wieder wegzukommen. Ist er schon verbraucht, hilft Tippen nicht mehr –
//     dann fällt er und wartet auf den Boden.
//   - Am Boden, wenn zwei Kacheln voraus kein Grund mehr ist: tippen. Das ist
//     die Kante des Absatzes, und genau dort springt auch ein Mensch.
//
// Er spielt bewusst mittelmässig: keine bildgenauen Eingaben, keine Planung.
// Was er schafft, schafft auch ein Mensch.

import { T, TILE, isSolid } from './constants.js';

/** Wie weit voraus er nach Boden schaut, in Pixeln. */
const LOOKAHEAD = 10;

export function createBot() {
  return { held: false };
}

function standable(level, col, row) {
  const tile = level.tileAt(col, row);
  return isSolid(tile) || tile === T.LEAF;
}

/**
 * Erzeugt die Eingabe für einen Tick.
 * @param {ReturnType<typeof createBot>} bot
 * @param {object} world
 * @param {object} out Eingabeobjekt, das beschrieben wird.
 */
export function botInput(bot, world, out) {
  const player = world.player;
  const body = player.body;

  let want = false;

  if (player.dead || player.vine > 0) {
    want = false;
  } else if (!world.started) {
    // Vor dem ersten Tipp steht alles still – auch der Automat muss ihn geben.
    want = true;
  } else if (player.sliding) {
    // An der Wand: abstossen, sonst rutscht er der Gefahr entgegen. Es gibt
    // aber nur einen Wandsprung je Flugphase; ist er weg, wäre Tippen nur ein
    // sinnlos verbrauchter Sprungpuffer.
    want = !player.wallJumpUsed;
  } else if (player.onGround) {
    // Kante voraus? Dann springen.
    const probeX = player.facing > 0 ? body.x + body.w + LOOKAHEAD : body.x - LOOKAHEAD;
    const col = Math.floor(probeX / TILE);
    const row = Math.floor((body.y + body.h) / TILE);
    want = !standable(world.level, col, row);
  } else if (body.vy < 0) {
    // Im Steigflug den Knopf halten. Die Sprunghöhe hängt zwar nicht daran,
    // aber ein sauberer Druck-Halte-Los-Verlauf entspricht dem, was ein
    // Mensch tut, und hält den Sprungpuffer frei.
    want = bot.held;
  }

  out.jumpPressed = want && !bot.held;
  out.jumpReleased = !want && bot.held;
  out.jump = want;
  bot.held = want;
}
