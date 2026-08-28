// Die einzige Farbquelle des Spiels.
//
// Das Portal hat mit packages/ui/src/styles/tokens.css eine eigene, strikte
// Token-Regel ("nie #hex ausserhalb von tokens.css"). HeavenClimb liegt
// bewusst ausserhalb dieses Systems: kein Build-Schritt, keine gemeinsame CSS.
// Deshalb bringt es eine eigene, ebenso zentralisierte Farbquelle mit – hier
// und in style.css als --hc-*. In allen anderen Dateien des Spiels stehen
// keine Hex-Werte.
//
// Die Töne sind an das WebHeaven-Farbschema angelehnt (Navy, Blau, Türkis).

/** Index-Zeichen -> Farbe. '.' ist durchsichtig und steht nicht in der Tabelle. */
export const PALETTE = {
  0: '#0f172a', // Umriss, tiefes Navy
  1: '#1e293b', // dunkler Schatten
  2: '#334155', // mittleres Grau
  3: '#64748b', // Helmschatten
  4: '#cbd5e1', // Helm hell
  5: '#ffffff', // Weiss, Glanzlicht
  6: '#2563eb', // Anzug
  7: '#60a5fa', // Anzug hell
  8: '#0891b2', // Visier dunkel
  9: '#22d3ee', // Visier hell, Türkis
  a: '#f59e0b', // Bernstein (Sprungfeder)
  b: '#ef4444', // Rot (Gefahr, Stacheln)
  c: '#7c2d12', // dunkles Braun (Gefahrenschatten)
  d: '#f8fafc', // Wolkenweiss
  e: '#fbbf24', // Gold (Edelstein)
  f: '#a16207', // dunkles Gold
};

/** Farben, die nicht im 16er-Raster stecken (Hintergrund, Oberfläche). */
export const COLORS = {
  skyLow: '#0b1220',
  skyMid: '#12203a',
  skyHigh: '#1e1b4b',
  skySpace: '#050813',
  star: '#e2e8f0',
  farRock: '#182236',
  nearRock: '#0d1526',
  cloud: '#f8fafc',
  hazardCore: '#ef4444',
  hazardHot: '#fbbf24',
  hazardEdge: '#7c2d12',
  hudText: '#e2e8f0',
  hudDim: '#94a3b8',
  hudGood: '#22d3ee',
  letterbox: '#05070f',
};
