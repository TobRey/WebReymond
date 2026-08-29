// Erzeugt web/src/render/frames.js – die 40 Einzelbilder von Mogli.
//
// WARUM EIN WERKZEUG UND NICHT VON HAND
// Acht Bewegungen à fünf Bildern à 32 × 32 Zeichen sind 1280 Zeilen Raster.
// Von Hand getippt wäre das nicht nur lang, sondern vor allem inkonsistent:
// die Figur würde zwischen zwei Bildern um einen Pixel schrumpfen, ein Arm
// wäre in Bild 3 zwei Pixel dick und in Bild 4 drei. Genau das sieht man in
// Bewegung sofort.
//
// Stattdessen steht hier eine Strichfigur: Kopf, Rumpf, zwei Arme, zwei Beine,
// je Bild mit Anfangs- und Endpunkt. Daraus wird das Raster gezeichnet. Die
// Figur bleibt damit über alle 40 Bilder gleich gebaut, und eine Bewegung
// ändert man, indem man vier Zahlen ändert – nicht 32 Zeilen Text.
//
// DAS ERGEBNIS WIRD EINGECHECKT. frames.js ist danach ganz normale Datenquelle,
// die das Spiel direkt liest; es gibt weiterhin keinen Build-Schritt. Dieses
// Werkzeug läuft nur, wenn jemand die Figur ändern will:
//
//     node games/mogli/tools/make-frames.mjs
//
// Farben (Schlüssel aus render/palette.js):
//   0 Umriss · g Haar · h/i/j Haut dunkel/mittel/hell · k/l/m Lendenschurz

import { writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const OUT = join(HERE, '..', 'web', 'src', 'render', 'frames.js');

const SIZE = 32;
const EMPTY = '.';

// ---------------------------------------------------------------------------
// Zeichenwerkzeug auf dem Zeichenraster
// ---------------------------------------------------------------------------

function createGrid() {
  return Array.from({ length: SIZE }, () => new Array(SIZE).fill(EMPTY));
}

function put(grid, x, y, ch) {
  const xi = Math.round(x);
  const yi = Math.round(y);
  if (xi < 0 || yi < 0 || xi >= SIZE || yi >= SIZE) return;
  grid[yi][xi] = ch;
}

function rect(grid, x, y, w, h, ch) {
  for (let dy = 0; dy < h; dy += 1) {
    for (let dx = 0; dx < w; dx += 1) put(grid, x + dx, y + dy, ch);
  }
}

/** Eine gefüllte Ellipse – Köpfe und Schultern sind nicht eckig. */
function ellipse(grid, cx, cy, rx, ry, ch) {
  for (let dy = -ry; dy <= ry; dy += 1) {
    const t = dy / ry;
    const half = Math.floor(rx * Math.sqrt(Math.max(0, 1 - t * t)) + 0.5);
    for (let dx = -half; dx <= half; dx += 1) put(grid, cx + dx, cy + dy, ch);
  }
}

/**
 * Ein Glied: dicke Strecke von (x1,y1) nach (x2,y2).
 *
 * Gezeichnet wird mit einer Scheibe je Schritt statt mit einer Linie plus
 * Verdickung – sonst hat ein diagonaler Arm Löcher, und genau die fallen in
 * Bewegung auf.
 */
function limb(grid, x1, y1, x2, y2, thickness, ch) {
  const steps = Math.max(1, Math.ceil(Math.hypot(x2 - x1, y2 - y1)) * 2);
  const r = (thickness - 1) / 2;
  for (let i = 0; i <= steps; i += 1) {
    const t = i / steps;
    const x = x1 + (x2 - x1) * t;
    const y = y1 + (y2 - y1) * t;
    for (let dy = -Math.ceil(r); dy <= Math.ceil(r); dy += 1) {
      for (let dx = -Math.ceil(r); dx <= Math.ceil(r); dx += 1) {
        if (dx * dx + dy * dy <= r * r + 0.5) put(grid, x + dx, y + dy, ch);
      }
    }
  }
}

/**
 * Legt einen Umriss um alles Gezeichnete.
 *
 * Das ist der Grund, warum die Figur auf 16 Kacheln Breite überhaupt lesbar
 * bleibt: vor dichtem Laub verschwindet eine Silhouette ohne dunkle Kante.
 * Automatisch statt von Hand – so ist der Umriss in allen 40 Bildern gleich.
 */
function outline(grid, ch = '0') {
  const copy = grid.map((row) => row.slice());
  for (let y = 0; y < SIZE; y += 1) {
    for (let x = 0; x < SIZE; x += 1) {
      if (copy[y][x] !== EMPTY) continue;
      const touches =
        (y > 0 && copy[y - 1][x] !== EMPTY) ||
        (y < SIZE - 1 && copy[y + 1][x] !== EMPTY) ||
        (x > 0 && copy[y][x - 1] !== EMPTY) ||
        (x < SIZE - 1 && copy[y][x + 1] !== EMPTY);
      if (touches) grid[y][x] = ch;
    }
  }
}

// ---------------------------------------------------------------------------
// Die Figur
// ---------------------------------------------------------------------------

/** Ruhemasse. Alle Posen sind Abweichungen davon. */
const BODY = {
  headX: 16,
  headY: 8,
  headRx: 5,
  headRy: 5,
  shoulderY: 16,
  hipY: 23,
  armThick: 3,
  legThick: 3,
};

/**
 * Zeichnet ein Bild.
 *
 * `pose` beschreibt nur, was sich bewegt:
 *   lift      Rumpf hebt/senkt sich (Atmen, Landen)
 *   lean      Rumpf neigt sich nach vorn (Laufen, Fallen)
 *   armA/armB Handpunkte relativ zur Schulter
 *   legA/legB Fusspunkte relativ zur Hüfte
 *   blink     Augen zu
 *   hairLift  Haar weht nach oben (Sprung, Rankenzug)
 */
function drawPose(pose) {
  const grid = createGrid();
  const lift = pose.lift ?? 0;
  const lean = pose.lean ?? 0;

  const hipX = 16;
  const hipY = BODY.hipY + lift;
  const shoulderX = 16 + lean;
  const shoulderY = BODY.shoulderY + lift;
  const headX = BODY.headX + Math.round(lean * 1.5);
  const headY = BODY.headY + lift;

  // Zeichenreihenfolge wie bei einer Gliederpuppe: von hinten nach vorn.
  // Der VORDERE Arm kommt ganz zum Schluss – sonst verschwindet er hinter dem
  // Kopf, sobald er erhoben ist, und genau das braucht der Rankenzug.

  // --- Beine (zuerst, damit der Lendenschurz sie überdeckt) ----------------
  for (const leg of [pose.legB, pose.legA]) {
    if (!leg) continue;
    limb(grid, hipX + leg.hx, hipY, hipX + leg.x, hipY + leg.y, BODY.legThick, 'i');
    // Fuss
    rect(grid, hipX + leg.x - 1, hipY + leg.y - 1, 3, 2, 'h');
  }

  // --- Hinterer Arm --------------------------------------------------------
  if (pose.armB) {
    const arm = pose.armB;
    // Dunkler als der vordere: das ist die ganze Tiefenwirkung, die eine
    // Figur dieser Grösse braucht.
    limb(
      grid,
      shoulderX + arm.sx,
      shoulderY,
      shoulderX + arm.x,
      shoulderY + arm.y,
      BODY.armThick,
      'h',
    );
  }

  // --- Rumpf ---------------------------------------------------------------
  limb(grid, shoulderX, shoulderY, hipX, hipY - 1, 9, 'i');
  // Brustschatten: gibt dem Rumpf Rundung statt einer Fläche.
  limb(grid, shoulderX - 3, shoulderY + 1, hipX - 3, hipY - 2, 2, 'h');
  limb(grid, shoulderX + 3, shoulderY + 1, hipX + 3, hipY - 2, 2, 'h');

  // --- Lendenschurz --------------------------------------------------------
  rect(grid, hipX - 5, hipY - 3, 11, 4, 'k');
  rect(grid, hipX - 4, hipY - 2, 9, 3, 'l');
  rect(grid, hipX - 3, hipY - 1, 7, 2, 'm');

  // --- Kopf ----------------------------------------------------------------
  ellipse(grid, headX, headY, BODY.headRx, BODY.headRy, 'i');
  // Haar: Kappe über der Stirn, hinten länger.
  const hair = pose.hairLift ?? 0;
  ellipse(grid, headX, headY - 2 - hair, BODY.headRx, BODY.headRy - 1, 'g');
  rect(grid, headX - BODY.headRx, headY - 3 - hair, 2, 5, 'g');
  rect(grid, headX + BODY.headRx - 1, headY - 3 - hair, 2, 5, 'g');
  // Gesicht wieder freilegen
  ellipse(grid, headX, headY + 1, BODY.headRx - 1, BODY.headRy - 2, 'i');

  // Augen. Blickrichtung ist immer nach rechts; gespiegelt wird beim Brennen
  // des Blattes, nicht hier.
  if (pose.blink) {
    rect(grid, headX - 2, headY + 1, 2, 1, 'h');
    rect(grid, headX + 2, headY + 1, 2, 1, 'h');
  } else {
    rect(grid, headX - 2, headY, 2, 2, '0');
    rect(grid, headX + 2, headY, 2, 2, '0');
    put(grid, headX - 1, headY, 'j');
    put(grid, headX + 3, headY, 'j');
  }
  // Mund
  rect(grid, headX - 1, headY + 3, 3, 1, 'h');

  // --- Vorderer Arm, ganz oben --------------------------------------------
  if (pose.armA) {
    const arm = pose.armA;
    limb(
      grid,
      shoulderX + arm.sx,
      shoulderY,
      shoulderX + arm.x,
      shoulderY + arm.y,
      BODY.armThick,
      'i',
    );
    // Hand
    rect(grid, shoulderX + arm.x - 1, shoulderY + arm.y - 1, 3, 3, 'j');
  }

  // Lichtkante: die Sonne steht oben rechts.
  for (let y = 0; y < SIZE; y += 1) {
    for (let x = SIZE - 1; x > 0; x -= 1) {
      if (grid[y][x] === 'i' && grid[y][x - 1] === EMPTY) grid[y][x] = 'j';
    }
  }

  outline(grid);
  return grid.map((row) => row.join(''));
}

// ---------------------------------------------------------------------------
// Die acht Bewegungen, je fünf Bilder
// ---------------------------------------------------------------------------

const STAND_ARMS = { armA: { sx: 4, x: 6, y: 7 }, armB: { sx: -4, x: -6, y: 7 } };
const STAND_LEGS = { legA: { hx: 2, x: 3, y: 8 }, legB: { hx: -2, x: -3, y: 8 } };

/** Ein Laufbild: Arme und Beine gegengleich, Phase in Radiant. */
function runPose(phase) {
  const swing = Math.sin(phase);
  const lift = Math.round(Math.abs(Math.cos(phase)) * -1);
  return {
    lean: 1,
    lift,
    armA: { sx: 4, x: 4 + Math.round(swing * 5), y: 6 - Math.round(Math.abs(swing) * 2) },
    armB: { sx: -4, x: -4 - Math.round(swing * 5), y: 6 - Math.round(Math.abs(swing) * 2) },
    legA: { hx: 2, x: 2 + Math.round(swing * 6), y: 8 - Math.round(Math.abs(swing) * 2) },
    legB: { hx: -2, x: -2 - Math.round(swing * 6), y: 8 - Math.round(Math.abs(swing) * 2) },
  };
}

const ANIMATIONS = {
  // Stehen: kaum Bewegung, dafür ein Blinzeln. Kommt selten vor – Mogli läuft
  // ja von allein –, aber am Start und beim Anstossen an eine Wand.
  idle: [
    { ...STAND_ARMS, ...STAND_LEGS },
    { ...STAND_ARMS, ...STAND_LEGS, lift: -1 },
    { ...STAND_ARMS, ...STAND_LEGS, lift: -1, blink: true },
    { ...STAND_ARMS, ...STAND_LEGS, lift: -1 },
    { ...STAND_ARMS, ...STAND_LEGS },
  ],

  // Laufen: ein voller Schritt auf fünf Bilder verteilt.
  run: [0, 1, 2, 3, 4].map((i) => runPose((i / 5) * Math.PI * 2)),

  // Steigflug: Arme oben, Beine angezogen, Haar weht.
  jump: [
    {
      lean: 1,
      hairLift: 1,
      armA: { sx: 4, x: 7, y: -4 },
      armB: { sx: -4, x: -6, y: -2 },
      legA: { hx: 2, x: 4, y: 6 },
      legB: { hx: -2, x: -1, y: 4 },
    },
    {
      lean: 1,
      hairLift: 2,
      armA: { sx: 4, x: 8, y: -5 },
      armB: { sx: -4, x: -7, y: -3 },
      legA: { hx: 2, x: 5, y: 5 },
      legB: { hx: -2, x: -1, y: 3 },
    },
    {
      lean: 1,
      hairLift: 2,
      armA: { sx: 4, x: 8, y: -5 },
      armB: { sx: -4, x: -7, y: -4 },
      legA: { hx: 2, x: 5, y: 5 },
      legB: { hx: -2, x: -2, y: 3 },
    },
    {
      lean: 1,
      hairLift: 1,
      armA: { sx: 4, x: 7, y: -4 },
      armB: { sx: -4, x: -6, y: -3 },
      legA: { hx: 2, x: 4, y: 6 },
      legB: { hx: -2, x: -2, y: 4 },
    },
    {
      lean: 1,
      hairLift: 1,
      armA: { sx: 4, x: 7, y: -3 },
      armB: { sx: -4, x: -6, y: -2 },
      legA: { hx: 2, x: 4, y: 6 },
      legB: { hx: -2, x: -1, y: 5 },
    },
  ],

  // Fallen: Arme rudern, Beine strecken sich nach unten.
  fall: [
    {
      lean: -1,
      hairLift: 2,
      armA: { sx: 4, x: 8, y: -2 },
      armB: { sx: -4, x: -8, y: -1 },
      legA: { hx: 2, x: 4, y: 8 },
      legB: { hx: -2, x: -3, y: 7 },
    },
    {
      lean: -1,
      hairLift: 2,
      armA: { sx: 4, x: 8, y: -3 },
      armB: { sx: -4, x: -8, y: 0 },
      legA: { hx: 2, x: 5, y: 8 },
      legB: { hx: -2, x: -4, y: 7 },
    },
    {
      lean: -1,
      hairLift: 3,
      armA: { sx: 4, x: 9, y: -2 },
      armB: { sx: -4, x: -8, y: -2 },
      legA: { hx: 2, x: 4, y: 8 },
      legB: { hx: -2, x: -4, y: 8 },
    },
    {
      lean: -1,
      hairLift: 2,
      armA: { sx: 4, x: 8, y: -1 },
      armB: { sx: -4, x: -8, y: -2 },
      legA: { hx: 2, x: 3, y: 8 },
      legB: { hx: -2, x: -3, y: 8 },
    },
    {
      lean: -1,
      hairLift: 2,
      armA: { sx: 4, x: 8, y: -2 },
      armB: { sx: -4, x: -7, y: -1 },
      legA: { hx: 2, x: 4, y: 8 },
      legB: { hx: -2, x: -3, y: 7 },
    },
  ],

  // An der Wand: das Gesicht zur Wand, beide Arme dagegen, Beine angewinkelt.
  // Die Wand ist rechts – gespiegelt wird beim Brennen.
  wallslide: [
    {
      lean: 2,
      armA: { sx: 4, x: 8, y: -3 },
      armB: { sx: -2, x: 6, y: 3 },
      legA: { hx: 2, x: 5, y: 7 },
      legB: { hx: -2, x: 0, y: 8 },
    },
    {
      lean: 2,
      lift: -1,
      armA: { sx: 4, x: 8, y: -2 },
      armB: { sx: -2, x: 6, y: 4 },
      legA: { hx: 2, x: 5, y: 8 },
      legB: { hx: -2, x: 0, y: 7 },
    },
    {
      lean: 2,
      armA: { sx: 4, x: 8, y: -3 },
      armB: { sx: -2, x: 7, y: 3 },
      legA: { hx: 2, x: 6, y: 7 },
      legB: { hx: -2, x: 1, y: 8 },
    },
    {
      lean: 2,
      lift: -1,
      armA: { sx: 4, x: 8, y: -2 },
      armB: { sx: -2, x: 6, y: 4 },
      legA: { hx: 2, x: 5, y: 8 },
      legB: { hx: -2, x: 0, y: 7 },
    },
    {
      lean: 2,
      armA: { sx: 4, x: 8, y: -3 },
      armB: { sx: -2, x: 6, y: 3 },
      legA: { hx: 2, x: 5, y: 7 },
      legB: { hx: -2, x: 0, y: 8 },
    },
  ],

  // Aufkommen: tief in die Knie und wieder hoch.
  land: [
    {
      lift: 3,
      armA: { sx: 4, x: 8, y: 2 },
      armB: { sx: -4, x: -8, y: 2 },
      legA: { hx: 3, x: 5, y: 5 },
      legB: { hx: -3, x: -5, y: 5 },
    },
    {
      lift: 4,
      armA: { sx: 4, x: 9, y: 1 },
      armB: { sx: -4, x: -9, y: 1 },
      legA: { hx: 3, x: 6, y: 4 },
      legB: { hx: -3, x: -6, y: 4 },
    },
    {
      lift: 3,
      armA: { sx: 4, x: 8, y: 3 },
      armB: { sx: -4, x: -8, y: 3 },
      legA: { hx: 3, x: 5, y: 5 },
      legB: { hx: -3, x: -5, y: 5 },
    },
    {
      lift: 1,
      armA: { sx: 4, x: 7, y: 5 },
      armB: { sx: -4, x: -7, y: 5 },
      legA: { hx: 2, x: 4, y: 7 },
      legB: { hx: -2, x: -4, y: 7 },
    },
    { ...STAND_ARMS, ...STAND_LEGS },
  ],

  // Rankenzug: senkrecht gestreckt, beide Arme nach OBEN gerissen, Haar steht.
  //
  // Die Hände müssen über den Kopf hinaus und seitlich daran vorbei – sitzen
  // sie auf Kopfhöhe, verschmelzen sie mit der Silhouette des Schädels, und
  // die ganze Pose liest sich wie Stehen. Auf 32 Pixeln entscheidet das ein
  // einziger Pixel Abstand.
  vine: [
    {
      hairLift: 3,
      armA: { sx: 4, x: 6, y: -13 },
      armB: { sx: -4, x: -6, y: -13 },
      legA: { hx: 2, x: 2, y: 9 },
      legB: { hx: -2, x: -2, y: 9 },
    },
    {
      hairLift: 4,
      armA: { sx: 4, x: 5, y: -14 },
      armB: { sx: -4, x: -5, y: -14 },
      legA: { hx: 2, x: 3, y: 9 },
      legB: { hx: -2, x: -1, y: 9 },
    },
    {
      hairLift: 4,
      armA: { sx: 4, x: 6, y: -14 },
      armB: { sx: -4, x: -6, y: -14 },
      legA: { hx: 2, x: 1, y: 9 },
      legB: { hx: -2, x: -3, y: 9 },
    },
    {
      hairLift: 3,
      armA: { sx: 4, x: 5, y: -13 },
      armB: { sx: -4, x: -5, y: -13 },
      legA: { hx: 2, x: 3, y: 9 },
      legB: { hx: -2, x: -1, y: 9 },
    },
    {
      hairLift: 4,
      armA: { sx: 4, x: 6, y: -14 },
      armB: { sx: -4, x: -6, y: -14 },
      legA: { hx: 2, x: 2, y: 9 },
      legB: { hx: -2, x: -2, y: 9 },
    },
  ],

  // Tod: nach hinten weggerissen, dann schlaff.
  death: [
    {
      lean: -2,
      hairLift: 3,
      armA: { sx: 4, x: 9, y: -5 },
      armB: { sx: -4, x: -9, y: -5 },
      legA: { hx: 2, x: 5, y: 7 },
      legB: { hx: -2, x: -5, y: 7 },
    },
    {
      lean: -3,
      hairLift: 4,
      armA: { sx: 4, x: 10, y: -6 },
      armB: { sx: -4, x: -10, y: -6 },
      legA: { hx: 2, x: 6, y: 6 },
      legB: { hx: -2, x: -6, y: 6 },
    },
    {
      lean: -4,
      hairLift: 3,
      armA: { sx: 4, x: 10, y: -4 },
      armB: { sx: -4, x: -10, y: -4 },
      legA: { hx: 2, x: 7, y: 5 },
      legB: { hx: -2, x: -7, y: 5 },
    },
    {
      lean: -4,
      lift: 2,
      hairLift: 2,
      blink: true,
      armA: { sx: 4, x: 9, y: -2 },
      armB: { sx: -4, x: -9, y: -2 },
      legA: { hx: 2, x: 7, y: 4 },
      legB: { hx: -2, x: -7, y: 4 },
    },
    {
      lean: -4,
      lift: 4,
      hairLift: 1,
      blink: true,
      armA: { sx: 4, x: 9, y: 0 },
      armB: { sx: -4, x: -9, y: 0 },
      legA: { hx: 2, x: 7, y: 3 },
      legB: { hx: -2, x: -7, y: 3 },
    },
  ],
};

// ---------------------------------------------------------------------------
// Ausgabe
// ---------------------------------------------------------------------------

const HEAD = `// Die Pixeldaten von Mogli – 40 Einzelbilder à 32 × 32.
//
// NICHT VON HAND ÄNDERN. Diese Datei wird erzeugt:
//
//     node games/mogli/tools/make-frames.mjs
//
// Dort steht die Figur als Strichmodell (Kopf, Rumpf, Arme, Beine mit
// Anfangs- und Endpunkt je Bild). Das hält die Figur über alle 40 Bilder
// gleich gebaut – von Hand getippt wäre sie zwischen zwei Bildern um einen
// Pixel geschrumpft, und genau das sieht man in Bewegung sofort.
//
// Das Ergebnis ist trotzdem ganz normale Datenquelle: das Spiel liest sie
// direkt, es gibt weiterhin keinen Build-Schritt.
//
// Ein Zeichen ist ein Pixel. '.' ist durchsichtig, alles andere ist ein
// Schlüssel aus PALETTE (render/palette.js):
//   0 Umriss · g Haar · h/i/j Haut dunkel/mittel/hell · k/l/m Lendenschurz
//
// games/mogli/test/frames.test.mjs prüft Grösse, Anzahl und Farbschlüssel.
`;

// HEAD endet bereits mit einem Zeilenumbruch – kein zusaetzliches ''.
const parts = [HEAD, '// prettier-ignore', 'export const FRAMES = {'];

for (const [name, poses] of Object.entries(ANIMATIONS)) {
  parts.push(`  ${name}: [`);
  for (const pose of poses) {
    parts.push('    [');
    for (const row of drawPose(pose)) parts.push(`      '${row}',`);
    parts.push('    ],');
  }
  parts.push('  ],');
  parts.push('');
}

parts.push('};');
parts.push('');
parts.push('export const FRAME_SIZE = 32;');
parts.push('');

writeFileSync(OUT, parts.join('\n'));

const total = Object.values(ANIMATIONS).reduce((sum, a) => sum + a.length, 0);
console.log(
  `${OUT}: ${Object.keys(ANIMATIONS).length} Bewegungen, ${total} Bilder à ${SIZE}×${SIZE}`,
);
