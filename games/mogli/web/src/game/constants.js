// Alle Zahlen des Spiels an einer Stelle. Wer die Steuerung anders haben will,
// ändert hier – und nirgends sonst.
//
// Einheit ist immer "pro Tick" (nicht pro Sekunde). Die Schleife garantiert
// einen festen Schritt von 1/60 s, deshalb braucht update() kein dt. Das macht
// einen Lauf bei gleichem Startwert und gleicher Eingabe exakt reproduzierbar –
// erst dadurch sind die Tests in games/mogli/test/ überhaupt möglich.
//
// Umrechnung: px/Tick × 60 = px/s, px/Tick² × 3600 = px/s².

export const TICK_HZ = 60;
export const TILE = 16;

/**
 * Interne Auflösung: Hochformat, weil das Spiel nach oben geht.
 *
 * Die Breite liegt fest – der Schacht ist sechzehn Kacheln breit, daran hängt
 * die gesamte Levelgeometrie. Die HÖHE dagegen richtet sich nach dem Gerät.
 *
 * Grund: Handys sind sehr viel höher als breit (ein iPhone hat 0.46), ein
 * festes 3:4-Bild liesse dort oben und unten je ein Drittel schwarz. Beim
 * Start wird deshalb die Höhe gewählt, die zum Bildschirm passt, auf ein
 * Vielfaches der Kachelgrösse gerundet. Mehr Höhe zeigt nur mehr Schacht über
 * Mogli – das Spiel wird davon nicht leichter, denn die Gefahr kommt von unten.
 */
export const VIEW_W = 256; // 16 Kacheln, unveränderlich
export const VIEW_H_MIN = 336; // 21 Kacheln
export const VIEW_H_MAX = 640; // 40 Kacheln
export let VIEW_H = VIEW_H_MIN;

/**
 * Setzt die Bildhöhe auf das nächste Vielfache der Kachelgrösse im erlaubten
 * Bereich. Wird beim Start und bei Grössenänderungen aufgerufen.
 * @returns {number} die tatsächlich gesetzte Höhe
 */
export function setViewHeight(wanted) {
  const rounded = Math.round(wanted / TILE) * TILE;
  VIEW_H = Math.max(VIEW_H_MIN, Math.min(VIEW_H_MAX, rounded));
  return VIEW_H;
}

/** Breite der Spielwelt in Kacheln. Die Kamera bewegt sich nur vertikal. */
export const GRID_W = 16;

/**
 * Kantenlänge eines Figurenbildes: genau zwei Kacheln. Deutlich grösser als
 * die Trefferfläche – Haare, Arme und Beine ragen darüber hinaus, das gibt der
 * Figur Silhouette.
 *
 * 32 statt 24, seit der Schacht 16 statt 12 Kacheln breit ist: sonst wäre
 * Mogli auf dem Bildschirm ein Drittel kleiner geworden, weil der ganzzahlige
 * Vergrösserungsfaktor mit der Bildbreite fällt.
 */
export const FRAME = 32;

/** Höhe eines Abschnitts in Kacheln. Erzeugt und verworfen wird abschnittsweise. */
export const CHUNK_ROWS = 16;

/** Kachelarten. 0 ist immer "leer". */
export const T = {
  EMPTY: 0,
  STONE: 1, // Tempelquader, tragend
  CRUMBLE: 2, // morscher Quader, bricht nach kurzer Zeit
  WALL: 3, // die beiden Seitenwände, daran wird gerutscht und abgesprungen
  SPIKE: 4, // Dornen
  LEAF: 5, // Blattplattform, nur von oben tragend
  EMERALD: 6, // Punkte
  VINE: 7, // Ranke: reisst nach oben
};

/** Kacheln, gegen die von allen Seiten kollidiert wird. */
export function isSolid(tile) {
  return tile === T.STONE || tile === T.CRUMBLE || tile === T.WALL;
}

/** Kacheln, an denen gerutscht und abgesprungen werden kann. */
export function isGrippable(tile) {
  return tile === T.WALL || tile === T.STONE;
}

/** Kacheln ohne Kollision, die beim Berühren etwas auslösen. */
export function isPickup(tile) {
  return tile === T.EMERALD || tile === T.VINE;
}

export const PHYS = {
  // --- Schwerkraft ---------------------------------------------------------
  // Fallen ist schwerer als Steigen. Das ist kein Realismus, sondern der
  // Grund, warum sich Sprünge in guten Jump'n'Runs knackig anfühlen.
  gravityUp: 0.4, // 1440 px/s²
  gravityDown: 0.62, // 2232 px/s²
  maxFallSpeed: 8.4, // 504 px/s

  // --- Sprung --------------------------------------------------------------
  // Ein Knopf, ein Sprung, immer gleich hoch.
  //
  // Bewusst OHNE variable Sprunghöhe: jeder Absatz liegt drei Kacheln höher,
  // ein kurzer Tipp müsste also trotzdem 48 px schaffen. Eine Kappung beim
  // Loslassen würde genau das kaputt machen – wer kurz antippt, käme nie an.
  // So hängt alles am Zeitpunkt und nichts an der Haltedauer.
  //
  // Die Höhe ist nicht frei gewählt, sondern aus der Form des Turms
  // hergeleitet. Der nächste Absatz liegt 64 px höher (4 Kacheln), und Mogli
  // muss über dessen Unterkante sein, BEVOR er sie waagerecht erreicht – sonst
  // stösst er sich den Kopf.
  //
  // Diese Werte sind NICHT gerechnet, sondern gesucht: /tmp-Suchlauf hat mit
  // der echten Physik jede vorkommende Kombination aus Absatzbreiten und Lücke
  // durchgespielt. jumpVel −8.2 bei Lauftempo 4.0 und Lücken von 5–6 Kacheln
  // ist der Satz, der alle 22 Fälle schafft und dabei den kürzesten Takt hat:
  // 42 Ticks je Absatz. Heute waren es 39 Ticks – aber für nur 3 Kacheln
  // Höhengewinn statt 4. Unterm Strich klettert Mogli also 24 % schneller.
  //
  // Der Beweis liegt nicht hier, sondern in test/level.test.mjs: er spielt
  // dieselben Fälle bei jedem Testlauf erneut durch.
  jumpVel: -8.2,
  maxAirJumps: 0,

  // --- Laufen --------------------------------------------------------------
  // Mogli läuft von allein. Es gibt keine Richtungstaste; die Richtung dreht
  // sich am Wandsprung und wenn er am Boden gegen eine Wand läuft.
  runMax: 4.0, // 240 px/s – das Spiel soll treiben
  groundAccel: 0.9, // schnell auf Tempo
  airAccel: 0.55,
  maxVx: 5.4, // Klemme, damit Wandsprungketten nicht eskalieren

  // --- Wand ----------------------------------------------------------------
  // Mit dem grösseren Absatzabstand muss auch der Abstoss weiter tragen; die
  // Werte sind im selben Verhältnis wie Sprung und Lauftempo mitgewachsen.
  wallSlideMaxFall: 2.2, // 132 px/s statt 504
  wallJumpVx: 3.8,
  wallJumpVy: -7.4,
  wallJumpLockout: 8, // Ticks, in denen der Abstoss die Laufrichtung dominiert

  // --- Nachsicht -----------------------------------------------------------
  // Ohne diese Werte fühlt sich jedes Jump'n'Run kaputt an, obwohl die
  // Physik stimmt. Bei einem Ein-Knopf-Spiel zählen sie doppelt: der ganze
  // Anspruch soll im Zeitpunkt liegen, nicht in der Reaktionszeit der Technik.
  coyoteTicks: 5, // Sprung noch kurz nach dem Abgrund
  wallCoyoteTicks: 6, // Wandsprung noch kurz nach dem Wandverlust
  jumpBufferTicks: 8, // zu früh gedrückter Sprung wird nachgeholt

  // --- Ranke ---------------------------------------------------------------
  // Eine Ranke greift von oben zu und reisst Mogli hoch: schnell und kurz.
  // Währenddessen wirkt keine Schwerkraft, danach fällt er normal weiter.
  vineTicks: 15,
  vineSpeed: -8.6, // 15 × 8.6 = 129 px ≈ 8 Kacheln
  /** So lange bleibt die Laufrichtung nach der Ranke frei steuerbar. */
  vineDrag: 0.82,

  // --- Körper --------------------------------------------------------------
  // Die Trefferfläche ist deutlich kleiner als das 32×32-Bild: schmal, damit
  // man durch Lücken passt, und oben stark angeschnitten, damit Haare und
  // erhobene Arme nicht an Decken hängen bleiben. Die Füsse stehen auf der
  // Unterkante des Bildes, deshalb hitboxOffY = 32 − hitboxH.
  //
  // Die Höhe ist nach oben begrenzt durch den Absatzabstand: bei 4 Kacheln
  // (64 px) bleiben mit 20 px Körper noch 44 px Kopffreiheit. Ob das reicht,
  // entscheidet nicht diese Zahl, sondern test/level.test.mjs – er spielt jede
  // vorkommende Sprungkombination mit genau dieser Trefferfläche durch.
  hitboxW: 12,
  hitboxH: 20,
  hitboxOffX: 10,
  hitboxOffY: 12,
};

export const GEN = {
  /**
   * Der Turm ist eine Zickzack-Treppe: Absätze hängen abwechselnd an der
   * linken und an der rechten Wand. Mogli läuft von allein zum offenen Ende,
   * springt hinüber, läuft drüben in die Wand, dreht und springt zurück.
   */
  minDy: 4,

  /**
   * Die waagerechte Lücke zwischen zwei Absätzen, in Kacheln – die einzige
   * Grösse, aus der sich alles andere ergibt.
   *
   * Nach unten begrenzt, weil Mogli sonst unter die Unterkante des Zielabsatzes
   * gerät, bevor er hoch genug ist, und sich den Kopf stösst. Nach oben
   * begrenzt durch die Flugzeit.
   */
  minGapX: 5,
  maxGapX: 6,
  /** Ab dieser Schwierigkeit kommt auch die grosse Lücke vor. */
  wideGapFrom: 0.4,

  /**
   * Die Breite eines Absatzes ist KEINE freie Zahl: sie ergibt sich aus
   * (GRID_W − 2) − Lücke − Breite des vorigen Absatzes. Die beiden Werte hier
   * sind nur die Klemmen, falls diese Rechnung aus dem Rahmen läuft.
   */
  minLedgeW: 2,
  maxLedgeW: 8,

  /** Höhe, ab der die Schwierigkeit ihr Maximum erreicht hat, in Pixeln. */
  fullDifficultyPx: 6000,
  /** Ticks, bis ein berührter morscher Quader verschwindet (0.3 s). */
  crumbleTicks: 18,

  /** Wahrscheinlichkeiten, jeweils von leicht nach schwer interpoliert. */
  emeraldChance: [0.45, 0.35],
  vineChance: [0.16, 0.24],
  spikeChance: [0.0, 0.3],
  crumbleChance: [0.0, 0.35],
  leafChance: [0.0, 0.22],
  /** Wie oft ein Absatz ganz entfällt und nur die Wand bleibt. */
  gapChance: [0.0, 0.22],
};

export const HAZARD = {
  baseSpeed: 0.34, // 20 px/s
  heightBonusAt: 2400, // px, ab denen der Zuschlag voll greift
  heightBonus: 0.7,
  lateStartTick: 1800, // 30 s
  lateBonusPerTick: 0.05 / 600,
  lateBonusMax: 0.35,
  maxSpeed: 1.9, // 114 px/s
  catchUpLead: 280, // px Vorsprung, ab dem die Gefahr aufholt
  catchUpFactor: 2.1,
  mercyLead: 80, // px, ab denen sie kurz Gnade zeigt
  mercyFactor: 0.72,

  /**
   * Die Leine: weiter als so viele Pixel unter die bisher erreichte Höhe fällt
   * die Glut nie zurück. Ohne sie könnte man nach einem guten Lauf beliebig
   * tief stürzen, ohne zu sterben.
   *
   * Der Wert steht bewusst hier und wird NICHT aus dem Bildausschnitt
   * hergeleitet. Früher hing die Leine am unteren Bildrand – damit war das
   * Spiel auf einem hohen Handy ein anderes als auf einem breiten Monitor:
   * dieselbe Eingabe endete dort nach 4 m und hier nach 60 m. Ein Wert, den
   * man sehen kann, darf nicht bestimmen, wie schwer das Spiel ist.
   */
  maxLead: 240,
  /** Startabstand unter dem Spieler. Gleich der Leine, sonst ruckt es im ersten Tick. */
  startOffset: 240,
};

export const CAM = {
  /**
   * Wo Mogli im Bild sitzt, als Anteil von oben gemessen – NICHT als fester
   * Abstand in Pixeln. Die Bildhöhe richtet sich nach dem Gerät; ein fester
   * Abstand hiesse, dass er auf einem hohen Handy in der Bildmitte klebt und
   * auf einem kurzen fast oben. Als Anteil sitzt er überall gleich: knapp
   * unterhalb der Mitte, mit reichlich Platz nach oben, denn dorthin geht es.
   */
  playerAt: 0.62,
  smoothing: 0.2,
};

/** Abstand des Spielers vom unteren Bildrand, aus CAM.playerAt hergeleitet. */
export function cameraOffset() {
  return Math.round(VIEW_H * (1 - CAM.playerAt));
}

/** Punkte pro eingesammeltem Smaragd. */
export const EMERALD_BONUS = 20;
