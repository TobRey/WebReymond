// Alle Zahlen des Spiels an einer Stelle. Wer die Steuerung anders haben will,
// ändert hier – und nirgends sonst.
//
// Einheit ist immer "pro Tick" (nicht pro Sekunde). Die Schleife garantiert
// einen festen Schritt von 1/60 s, deshalb braucht update() kein dt. Das macht
// einen Lauf bei gleichem Startwert und gleicher Eingabe exakt reproduzierbar –
// erst dadurch sind die Tests in games/heavenclimb/test/ überhaupt möglich.
//
// Umrechnung: px/Tick × 60 = px/s, px/Tick² × 3600 = px/s².

export const TICK_HZ = 60;
export const TILE = 16;

/** Interne Auflösung: Hochformat, weil das Spiel nach oben geht. */
export const VIEW_W = 240; // 15 Kacheln
export const VIEW_H = 320; // 20 Kacheln

/** Breite der Spielwelt in Kacheln. Die Kamera bewegt sich nur vertikal. */
export const GRID_W = 15;
/** Höhe eines Abschnitts in Kacheln. Erzeugt und verworfen wird abschnittsweise. */
export const CHUNK_ROWS = 16;

/** Kachelarten. 0 ist immer "leer". */
export const T = {
  EMPTY: 0,
  SOLID: 1,
  CRUMBLE: 2,
  WALL: 3,
  SPIKE: 4,
  ONEWAY: 5,
  GEM: 6,
  SPRING: 7,
};

/** Kacheln, gegen die von allen Seiten kollidiert wird. */
export function isSolid(tile) {
  return tile === T.SOLID || tile === T.CRUMBLE || tile === T.WALL || tile === T.SPRING;
}

/** Kacheln, an denen gerutscht und abgesprungen werden kann. */
export function isGrippable(tile) {
  return tile === T.SOLID || tile === T.WALL;
}

/** Kacheln ohne Kollision, die beim Berühren etwas auslösen. */
export function isPickup(tile) {
  return tile === T.GEM;
}

export const PHYS = {
  // --- Schwerkraft ---------------------------------------------------------
  // Fallen ist schwerer als Steigen. Das ist kein Realismus, sondern der
  // Grund, warum sich Sprünge in guten Jump'n'Runs knackig anfühlen.
  gravityUp: 0.4, // 1440 px/s²
  gravityDown: 0.62, // 2232 px/s²
  maxFallSpeed: 7.6, // 456 px/s

  // --- Sprung --------------------------------------------------------------
  // Scheitel = v² / (2·gravityUp).
  // Gemessen (nicht nur gerechnet – die Schrittintegration verliert etwas):
  // Einfachsprung 57 px, mit Doppelsprung 93 px. Der Generator verlangt
  // höchstens 48 px bzw. 64 px, es bleibt also spürbar Reserve.
  jumpVel: -7.0,
  doubleJumpVel: -5.6,
  jumpCut: 0.45, // Taste losgelassen und vy < 0 -> vy *= 0.45
  maxAirJumps: 1, // der Doppelsprung
  springVel: -9.0, // Sprungfeder: 101 px = 6.3 Kacheln

  // --- Laufen --------------------------------------------------------------
  runMax: 2.35, // 141 px/s
  groundAccel: 0.42,
  groundDecel: 0.55,
  airAccel: 0.3,
  airDecel: 0.16,
  maxVx: 4.2, // Klemme, damit Wandsprungketten nicht eskalieren

  // --- Wand ----------------------------------------------------------------
  wallSlideMaxFall: 1.8, // 108 px/s statt 456
  wallJumpVx: 3.4,
  wallJumpVy: -6.2,
  wallJumpLockout: 9, // Ticks, in denen die Eingabe nur zu 25 % zählt
  wallStick: 8, // Ticks Kleben trotz Eingabe von der Wand weg

  // --- Nachsicht -----------------------------------------------------------
  // Ohne diese drei Werte fühlt sich jedes Jump'n'Run kaputt an, obwohl die
  // Physik stimmt.
  coyoteTicks: 6, // Sprung noch kurz nach dem Abgrund
  wallCoyoteTicks: 5, // Wandsprung noch kurz nach dem Wandverlust
  jumpBufferTicks: 7, // zu früh gedrückter Sprung wird nachgeholt

  // --- Körper --------------------------------------------------------------
  // Die Trefferfläche ist kleiner als das 16×16-Bild: schmaler, damit man
  // durch Lücken passt, und oben angeschnitten, damit der Kopf nicht anstösst.
  hitboxW: 8,
  hitboxH: 14,
  hitboxOffX: 4,
  hitboxOffY: 2,
};

export const GEN = {
  /** Immer per Einfachsprung erreichbar (3 Kacheln = 48 px < 54.5 px Scheitel). */
  guaranteedDy: 3,
  /** Ab hoher Schwierigkeit: verlangt den Doppelsprung (4 Kacheln = 64 px). */
  stretchDy: 4,
  /** Grösster horizontaler Abstand zwischen zwei Plattformkanten, in Kacheln. */
  maxDx: 3,
  minPlatW: 2,
  maxPlatW: 6,
  /** Höhe, ab der die Schwierigkeit ihr Maximum erreicht hat, in Pixeln. */
  fullDifficultyPx: 6000,
  /** Schwierigkeit, ab der Wandschächte vorkommen. */
  shaftFrom: 0.25,
  /** Schwierigkeit, ab der der weite Sprung (stretchDy) erlaubt ist. */
  stretchFrom: 0.45,
  /** Ticks, bis eine berührte bröckelnde Plattform verschwindet (0.35 s). */
  crumbleTicks: 21,
};

export const HAZARD = {
  baseSpeed: 0.28, // 16.8 px/s
  heightBonusAt: 2400, // px, ab denen der Zuschlag voll greift
  heightBonus: 0.55,
  lateStartTick: 1800, // 30 s
  lateBonusPerTick: 0.05 / 600,
  lateBonusMax: 0.3,
  maxSpeed: 1.5, // 90 px/s
  catchUpLead: 300, // px Vorsprung, ab dem die Gefahr aufholt
  catchUpFactor: 1.9,
  mercyLead: 90, // px, ab denen sie kurz Gnade zeigt
  mercyFactor: 0.75,
  /** Startabstand unter dem Spieler – Zeit, sich zu sortieren. */
  startOffset: 320,
};

export const CAM = {
  /** Der Spieler sitzt so weit über dem unteren Bildrand. */
  playerOffset: 190,
  smoothing: 0.18,
};
