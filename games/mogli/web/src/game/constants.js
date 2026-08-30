// Alle Zahlen des Spiels an einer Stelle. Wer die Steuerung anders haben will,
// ändert hier – und nirgends sonst.
//
// Einheit ist immer "pro Tick" (nicht pro Sekunde). Die Schleife garantiert
// einen festen Schritt von 1/60 s, deshalb braucht update() kein dt. Das macht
// einen Lauf bei gleichem Start und gleicher Eingabe exakt reproduzierbar –
// erst dadurch sind die Tests in games/mogli/test/ überhaupt möglich.
//
// Umrechnung: px/Tick × 60 = px/s, px/Tick² × 3600 = px/s².
//
// SEIT 3.0 LIEGT DIE WELT QUER. Das Spiel läuft nach rechts (und zurück),
// die Karten kommen aus dem Editor im Admin-Bereich. Der endlose Turm, die
// aufsteigende Glut und der Ein-Knopf-Autolauf sind Geschichte – wer sie
// nachlesen will: docs/decisions/0012 und 0018.

export const TICK_HZ = 60;
export const TILE = 16;

/**
 * Interne Auflösung. Die BREITE liegt fest – sechzehn Kacheln, das ist der
 * sichtbare Ausschnitt, mit dem jede Karte rechnen kann. Die HÖHE richtet
 * sich nach dem Gerät (Mechanik unverändert seit 2.0): ein Handy ist mehr als
 * doppelt so hoch wie breit, ein festes Bild liesse dort oben und unten je
 * ein Drittel schwarz.
 *
 * Mehr Bildhöhe zeigt mehr Karte über und unter Mogli, macht das Spiel aber
 * nicht leichter: die Kamera ist reine Darstellung, kein Spielelement mehr.
 * test/viewheight.test.mjs besteht darauf.
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

/**
 * Kantenlänge eines Figurenbildes: genau zwei Kacheln. Deutlich grösser als
 * die Trefferfläche – Haare, Arme und Beine ragen darüber hinaus, das gibt
 * der Figur Silhouette.
 */
export const FRAME = 32;

/**
 * Kachelarten des KOLLISIONSGITTERS. Hier stehen nur noch die drei Arten,
 * die wirklich Gitterverhalten haben; alles andere (Smaragde, Stacheln,
 * Türen, Gegner …) sind Elemente mit eigener Trefferfläche – siehe
 * elements.js. 0 ist immer "leer".
 */
export const T = {
  EMPTY: 0,
  SOLID: 1, // fester Block, kollidiert von allen Seiten
  PLATFORM: 2, // trägt nur von oben, von unten springt man hindurch
  CRUMBLE: 3, // fest, zerfällt aber kurz nach dem Betreten
};

/** Kacheln, gegen die von allen Seiten kollidiert wird. */
export function isSolid(tile) {
  return tile === T.SOLID || tile === T.CRUMBLE;
}

export const PHYS = {
  // --- Schwerkraft ---------------------------------------------------------
  // Fallen ist schwerer als Steigen. Das ist kein Realismus, sondern der
  // Grund, warum sich Sprünge in guten Jump'n'Runs knackig anfühlen.
  gravityUp: 0.32, // 1152 px/s²
  gravityDown: 0.5, // 1800 px/s²
  maxFallSpeed: 8.0, // 480 px/s

  // --- Sprung --------------------------------------------------------------
  // Anders als im Turm-Spiel ist die Sprunghöhe VARIABEL: loslassen kappt
  // den Aufstieg. Der Wert ist nicht v²/2g gerechnet, sondern gemessen: die
  // Formel verspricht bei -6.4 volle 64 px, die diskreten Ticks liefern nur
  // 60.8, weil die Schwerkraft schon im Absprung-Tick abzieht. Mit -6.7
  // trägt der Sprung gemessene ~66 px – GENAU VIER KACHELN plus Reserve, und
  // physics.test.mjs besteht darauf. Ein kurzer Tipp schafft gut anderthalb.
  jumpVel: -6.7,
  /** Beim Loslassen wird die Restgeschwindigkeit hierauf gekappt. */
  jumpCutVel: -2.2,

  // --- Laufen --------------------------------------------------------------
  // Links/rechts liegt jetzt am Spieler. Gegen die Laufrichtung wird stärker
  // gebremst als beschleunigt (approach() in physics.js), damit ein
  // Richtungswechsel zackig ist statt schwammig.
  runMax: 2.7, // 162 px/s
  groundAccel: 0.28,
  groundDecel: 0.34, // Auslaufen ohne Taste
  airAccel: 0.2,
  airDecel: 0.08, // in der Luft rollt es weiter – bewusst wenig Bremse
  maxVx: 6.0, // Klemme für Federn und Portale

  // --- Nachsicht -----------------------------------------------------------
  // Ohne diese Werte fühlt sich jedes Jump'n'Run kaputt an, obwohl die
  // Physik stimmt.
  coyoteTicks: 6, // Sprung noch kurz nach der Kante
  jumpBufferTicks: 8, // zu früh gedrückter Sprung wird nachgeholt

  // --- Gegner --------------------------------------------------------------
  /** Rückstoss nach oben, wenn man einen Gegner platt springt. */
  stompVel: -5.2,

  // --- Körper --------------------------------------------------------------
  // Die Trefferfläche ist deutlich kleiner als das 32×32-Bild: schmal, damit
  // man durch Ein-Kachel-Lücken passt, und oben angeschnitten, damit Haare
  // nicht an Decken hängen bleiben. Die Füsse stehen auf der Unterkante des
  // Bildes, deshalb hitboxOffY = 32 − hitboxH.
  hitboxW: 12,
  hitboxH: 20,
  hitboxOffX: 10,
  hitboxOffY: 12,

  /** Ticks, bis ein betretener Bröckelblock zerfällt (0.35 s). */
  crumbleTicks: 21,
  /** Unter der Kartenunterkante ist nach so vielen Pixeln Schluss. */
  fallOutMargin: 48,
};

export const CAM = {
  /**
   * Waagerechte Totzone: solange Mogli zwischen diesen Anteilen der
   * Bildbreite bleibt, steht die Kamera. Erst am Rand der Zone schiebt er
   * sie – das übliche Mario-Fenster, es verhindert das nervöse Mitzittern
   * bei jedem Schritt.
   */
  deadLeft: 0.38,
  deadRight: 0.52,
  /** Senkrecht: wo Mogli im Bild sitzt (Anteil von oben), weich verfolgt. */
  playerAtY: 0.58,
  smoothingY: 0.12,
};

/** Zeitgutschrift je eingesammeltem Smaragd, in Ticks (1 s). */
export const EMERALD_TICKS = 60;
