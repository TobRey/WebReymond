// Ein einfacher Kletterautomat.
//
// Er hat zwei Aufgaben:
//   1. Im Menü klettert er im Hintergrund – der Bildschirm ist nie tot.
//   2. In den Tests beweist er, dass die erzeugten Türme wirklich begehbar
//      sind. Die geometrische Zusage des Generators (höchstens 4 Kacheln hoch,
//      3 Kacheln seitlich) ist eine Rechnung; dass ein Körper mit der echten
//      Physik dort auch ankommt, zeigt erst ein Durchlauf.
//
// Die entscheidende Einsicht steckt in `launchColumn`: senkrecht nach oben
// kann man auf keine Plattform springen, der Kopf stösst an ihre Unterseite.
// Der Automat sucht deshalb auf seiner Standfläche die Kachel, die NICHT
// unter dem Ziel liegt und ihm am nächsten ist, stellt sich dorthin und
// springt von da schräg hinauf. Genau das macht ein Mensch auch.
//
// Er spielt bewusst mittelmässig: keine bildgenauen Eingaben, keine Planung
// über die nächste Plattform hinaus. Was er schafft, schafft auch ein Mensch.

import { GRID_W, T, TILE, isSolid } from './constants.js';

export function createBot() {
  return { target: null, launchX: 0, landX: 0, jumpHeld: false, armed: false, overTop: false };
}

/** Kann man auf dieser Kachel stehen? Auch Durchstiegsplattformen zählen. */
function standable(level, col, row) {
  const tile = level.tileAt(col, row);
  return isSolid(tile) || tile === T.ONEWAY;
}

/** Die zusammenhängende Standfläche unter den Füssen. */
function standingSpan(world) {
  const body = world.player.body;
  const row = Math.floor((body.y + body.h) / TILE);
  const level = world.level;

  // Beide Körperkanten prüfen, nicht nur die Mitte: an einer Plattformkante
  // steht die Figur oft nur mit einer Hälfte auf festem Grund, und die Mitte
  // schwebt über dem Abgrund. Wer hier nur die Mittelspalte abfragt, findet
  // keinen Boden und setzt den Absprungpunkt auf die eigene Position – der
  // Automat springt dann auf der Stelle, bis ihn die Gefahr holt.
  const first = Math.floor(body.x / TILE);
  const last = Math.floor((body.x + body.w - 0.0001) / TILE);
  let col = -1;
  for (let c = first; c <= last; c += 1) {
    if (standable(level, c, row)) {
      col = c;
      break;
    }
  }
  if (col < 0) return null;

  let left = col;
  let right = col;
  while (left > 0 && standable(level, left - 1, row)) left -= 1;
  while (right < GRID_W - 1 && standable(level, right + 1, row)) right += 1;
  return { left, right, row };
}

/** Die tiefste Plattform oberhalb der Füsse. */
function pickTarget(world) {
  const body = world.player.body;
  const feetRow = Math.floor((body.y + body.h - 1) / TILE);
  let best = null;
  for (const platform of world.level.platforms) {
    if (platform.row >= feetRow) continue;
    if (best === null || platform.row > best.row) best = platform;
  }
  return best;
}

/**
 * Die Kachel der Standfläche, von der aus sich das Ziel anfliegen lässt:
 * nicht unter dem Ziel, und davon die dem Ziel nächste.
 */
function launchColumn(span, target, fromX) {
  const targetLeft = target.col;
  const targetRight = target.col + target.width - 1;
  let bestCol = null;
  let bestScore = Infinity;
  for (let col = span.left; col <= span.right; col += 1) {
    if (col >= targetLeft && col <= targetRight) continue;
    const distance = col < targetLeft ? targetLeft - col : col - targetRight;
    // Nähe zum Ziel zählt hauptsächlich; bei Gleichstand gewinnt die Seite,
    // auf der der Spieler ohnehin schon steht. Ohne diesen zweiten Teil läuft
    // der Automat regelmässig quer über die ganze Plattform, während die
    // Gefahr weitersteigt.
    const score = distance * 100 + Math.abs(col * TILE + TILE / 2 - fromX);
    if (score < bestScore) {
      bestScore = score;
      bestCol = col;
    }
  }
  // Sollte der Generator seine Zusage je brechen: lieber an den Rand stellen
  // als gar nichts tun.
  return bestCol === null ? span.left : bestCol;
}

function plan(bot, world) {
  const target = pickTarget(world);
  if (target === null) {
    bot.target = null;
    return;
  }
  const span = standingSpan(world);
  const changed =
    bot.target === null || bot.target.row !== target.row || bot.target.col !== target.col;
  bot.target = target;

  const myCentre = world.player.body.x + world.player.body.w / 2;
  const previousLaunch = bot.launchX;
  bot.launchX = span === null ? myCentre : launchColumn(span, target, myCentre) * TILE + TILE / 2;

  // Neues Ziel oder deutlich verschobener Absprungpunkt: die Bereitschaft
  // verfällt, sonst springt er von der falschen Stelle.
  if (changed || Math.abs(previousLaunch - bot.launchX) > 8) {
    bot.armed = false;
    bot.overTop = false;
  }

  const targetLeft = target.col * TILE;
  const targetRight = (target.col + target.width) * TILE;
  // Angeflogen wird die dem Absprung zugewandte Kante, nicht die Mitte: über
  // der Kante bleibt nur ein kurzes Zeitfenster, in dem er seitlich
  // herüberkommen muss.
  bot.landX = bot.launchX < targetLeft ? targetLeft + 5 : targetRight - 5;
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

  out.down = false;

  // Neu planen nur am Boden. Ein Zielwechsel mitten im Sprung liesse den
  // Automaten in der Luft die Richtung wechseln und nie ankommen.
  if (player.onGround || bot.target === null) plan(bot, world);

  if (bot.target === null || player.dead) {
    out.left = false;
    out.right = false;
    out.jump = false;
    out.jumpPressed = false;
    out.jumpReleased = bot.jumpHeld;
    bot.jumpHeld = false;
    return;
  }

  const myCentre = body.x + body.w / 2;
  const targetTopY = bot.target.row * TILE;
  const stillBelow = body.y + body.h > targetTopY + 2;
  const toLaunch = bot.launchX - myCentre;
  // Einmal am Absprungpunkt angekommen, bleibt er "bereit", bis der Sprung
  // erfolgt ist. Ohne dieses Merken würde er beim Bremsen aus dem
  // Toleranzfenster laufen und wieder von vorne anlaufen – er stünde ewig.
  if (player.onGround && Math.abs(toLaunch) < 7) bot.armed = true;
  // Jeder Sprungversuch fängt von vorne an. Ohne das Zurücksetzen behält ein
  // misslungener Versuch das Merkmal "über der Kante" und der nächste Aufstieg
  // zieht sofort seitlich – direkt unter die Plattform.
  if (player.onGround) bot.overTop = false;
  if (!stillBelow) bot.overTop = true;

  if (player.onGround && !bot.armed) {
    // Zum Absprungpunkt laufen.
    out.left = toLaunch < 0;
    out.right = toLaunch > 0;
  } else if (bot.overTop) {
    // Oberhalb der Plattformkante: jetzt herüberziehen.
    const dx = bot.landX - myCentre;
    out.left = dx < -3;
    out.right = dx > 3;
  } else {
    // Bereit oder im Aufstieg: keine Seitwärtsbewegung.
    //
    // Das ist der Kern. Der Absprungpunkt liegt direkt neben der Plattform;
    // schon vier Pixel zur Seite genügen, um unter ihre Unterkante zu geraten,
    // und dann stösst der Kopf an. Also senkrecht neben ihr aufsteigen und
    // erst über der Kante herüberziehen – genauso spielt es ein Mensch.
    out.left = false;
    out.right = false;
  }

  if (player.sliding) {
    // Von der Wand weg drücken, sonst klebt er dort fest.
    out.left = player.slideSide > 0;
    out.right = player.slideSide < 0;
  }

  // Steht er waagerecht schon über der Zielplattform?
  const overTarget =
    myCentre > bot.target.col * TILE && myCentre < (bot.target.col + bot.target.width) * TILE;

  const wantJump =
    // Erst abspringen, wenn der Anlauf abgebremst ist.
    (player.onGround && bot.armed && Math.abs(body.vx) < 0.6) ||
    player.sliding ||
    // Steigt noch und ist unter dem Ziel: Taste halten (höherer Sprung).
    (!player.onGround && body.vy < 0 && stillBelow) ||
    // Fällt und kommt nicht hoch genug: Doppelsprung.
    (!player.onGround && body.vy > 0 && stillBelow && player.airJumps > 0) ||
    // Über der Kante, aber noch nicht über der Plattform und schon im Sinkflug:
    // der Doppelsprung kauft die Flugzeit, die zum Herüberziehen fehlt.
    (!player.onGround && body.vy > 1 && bot.overTop && !overTarget && player.airJumps > 0);

  out.jumpPressed = wantJump && !bot.jumpHeld;
  out.jumpReleased = !wantJump && bot.jumpHeld;
  out.jump = wantJump;
  bot.jumpHeld = wantJump;
}
