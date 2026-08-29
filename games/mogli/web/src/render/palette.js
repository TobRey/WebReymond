// Die einzige Farbquelle des Spiels.
//
// Das Portal hat mit packages/ui/src/styles/tokens.css eine eigene, strikte
// Token-Regel ("nie #hex ausserhalb von tokens.css"). Mogli liegt
// bewusst ausserhalb dieses Systems: kein Build-Schritt, keine gemeinsame CSS.
// Deshalb bringt es eine eigene, ebenso zentralisierte Farbquelle mit – hier
// und in style.css als --hc-*. In allen anderen Dateien des Spiels stehen
// keine Hex-Werte.
//
// 32 Farben statt der üblichen 16, und zwar in geschlossenen Abstufungsreihen:
// jede Oberfläche hat Schatten, Grundton, Licht und Glanz. Genau das trennt
// den Eindruck einer 8-Bit-Konsole von dem einer 32-Bit-Konsole – nicht mehr
// Pixel, sondern mehr Zwischentöne auf denselben Pixeln.
//
// Thema: überwucherter Tempelschacht im Dschungel.

/** Index-Zeichen -> Farbe. '.' ist durchsichtig und steht nicht in der Tabelle. */
export const PALETTE = {
  // Umriss und Tiefen
  0: '#060d0c',
  1: '#0d1a16',
  2: '#14261f',

  // Laub, von dunkel nach hell
  3: '#1d3a2c',
  4: '#2b5a3d',
  5: '#46874f',
  6: '#6fb85e',
  7: '#a8d97a',

  // Tempelstein
  8: '#23241c', // Fugen
  9: '#474b36', // Schatten
  a: '#6b7150', // Grundton
  b: '#959b6b', // Licht
  c: '#bfc294', // Glanz

  // Moos auf dem Stein
  d: '#357a38',
  e: '#57ad46',
  f: '#8bd964',

  // Mogli: Haar und Haut
  g: '#33200f', // Haar, zugleich Umriss der Haut
  h: '#8a4d26', // Haut Schatten
  i: '#c07a42', // Haut
  j: '#e8a76a', // Haut Licht

  // Lendenschurz und alles Warme
  k: '#7a1410',
  l: '#c3281c',
  m: '#ef5a35',

  // Gold und Fackelschein
  n: '#6b4a08',
  o: '#c08c14',
  p: '#f5c53a',
  q: '#ffeb9c',

  // Smaragd
  r: '#0a5c42',
  s: '#14a072',
  t: '#3fe3a8',
  u: '#c2ffe8',

  // Glanzlicht
  v: '#ffffff',
};

/** Farben ausserhalb des Kachelrasters: Hintergrund, Wasser, Glut, Anzeige. */
export const COLORS = {
  // Der Schacht wird nach oben hin heller und dunstiger.
  hazeLow: '#0a1c17',
  hazeMid: '#123328',
  hazeHigh: '#1b4a3a',
  hazeTop: '#27614c',

  farCanopy: '#153023',
  midCanopy: '#1f4531',
  nearCanopy: '#28583c',

  waterDeep: '#123c4a',
  waterMid: '#1f6b7a',
  waterFoam: '#a8e6ef',

  torchGlow: '#f5c53a',

  // Die aufsteigende Glut: der einzige wirklich warme Bereich im Bild und
  // dadurch sofort als Gefahr lesbar.
  emberDeep: '#5c1004',
  emberCore: '#d9330f',
  emberHot: '#ff8a1f',
  emberSpark: '#ffe08a',

  hudText: '#dbe8cf',
  hudDim: '#8fa383',
  hudGood: '#3fe3a8',
  letterbox: '#050a09',
};
