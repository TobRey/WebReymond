// Kacheln werden prozedural gezeichnet statt als Zeichenketten hinterlegt:
// Quader, Moos und Ranken sind aus wenigen Rechtecken aufgebaut, da wären
// 16 Zeilen Text je Kachel nur Ballast. Mogli dagegen lebt von handgesetzten
// Pixeln – er liegt in frames.js.
//
// Vorbild ist ein überwucherter Tempelschacht: heller Sandstein mit dunklen
// Fugen, dickes Moos auf allen waagerechten Flächen, Ranken, die von den
// Kanten hängen. Jede Fläche bekommt Schatten, Grundton, Licht und Glanz –
// vier Tonwerte statt zwei, das ist der ganze Unterschied zur 8-Bit-Optik.

import { PALETTE, COLORS } from './palette.js';
import { tileOverride } from './assets.js';

export const TILE_SIZE = 16;

/** Feste Plätze im Kachelblatt. */
export const SLOT = {
  STONE_A: 0,
  STONE_B: 1,
  BURIED_A: 2,
  BURIED_B: 3,
  CRUMBLE_0: 4,
  CRUMBLE_1: 5,
  CRUMBLE_2: 6,
  WALL_A: 7,
  WALL_B: 8,
  SPIKE: 9,
  LEAF: 10,
  // Die Einzelbilder stehen einzeln da, obwohl der Zeichencode sie in einer
  // Schleife füllt: der Admin-Bereich braucht je Bild einen benannten Platz,
  // und "EMERALD_0 plus drei" wäre eine Absprache, die nur im Kommentar steht.
  EMERALD_0: 11,
  EMERALD_1: 12,
  EMERALD_2: 13,
  EMERALD_3: 14,
  VINE_0: 15,
  VINE_1: 16,

  // Wandschmuck. Das sind KEINE eigenen Kachelarten – die Wand bleibt für die
  // Physik überall dieselbe. Es sind nur andere Bilder derselben Wand, die
  // scene.js nach Zeilennummer auswählt. So bekommt der Schacht den Rhythmus
  // der Vorlage (Fackel, Götze, Kristallnische), ohne dass sich am Spiel
  // irgendetwas ändert.
  WALL_TORCH_0: 17, // zwei Bilder, die Flamme flackert
  WALL_TORCH_1: 18,
  WALL_IDOL: 19,
  WALL_CRYSTAL: 20,
};

const SLOT_COUNT = 21;

// Feste Sprenkelmuster – kein Zufall zur Laufzeit, damit dieselbe Kachel
// immer gleich aussieht.
const GRIT_A = [
  [3, 8],
  [9, 7],
  [5, 12],
  [12, 10],
  [7, 13],
];
const GRIT_B = [
  [2, 10],
  [6, 6],
  [11, 13],
  [13, 8],
  [4, 12],
];

/** Der nackte Quader: Sandstein mit Fuge unten und rechts. */
function block(ctx, ox, oy, grit) {
  ctx.fillStyle = PALETTE.a;
  ctx.fillRect(ox, oy, TILE_SIZE, TILE_SIZE);

  // Innenzeichnung: eine angedeutete zweite Fuge, damit ein grosser Block
  // nicht wie eine glatte Fläche wirkt.
  ctx.fillStyle = PALETTE[9];
  ctx.fillRect(ox, oy + 9, TILE_SIZE, 1);
  ctx.fillRect(ox + 7, oy + 10, 1, 6);

  ctx.fillStyle = PALETTE.b;
  ctx.fillRect(ox, oy + 10, TILE_SIZE, 1);

  // Fugen am Rand
  ctx.fillStyle = PALETTE[8];
  ctx.fillRect(ox, oy + TILE_SIZE - 2, TILE_SIZE, 2);
  ctx.fillRect(ox + TILE_SIZE - 1, oy, 1, TILE_SIZE);

  ctx.fillStyle = PALETTE[9];
  for (const [x, y] of grit) ctx.fillRect(ox + x, oy + y, 1, 1);
}

/** Moospolster auf der Oberkante, mit unregelmässigem Saum. */
function moss(ctx, ox, oy, offsets) {
  ctx.fillStyle = PALETTE.d;
  ctx.fillRect(ox, oy, TILE_SIZE, 4);
  ctx.fillStyle = PALETTE.e;
  ctx.fillRect(ox, oy, TILE_SIZE, 2);
  ctx.fillStyle = PALETTE.f;
  ctx.fillRect(ox, oy, TILE_SIZE, 1);

  // Unterer Saum: das Moos hängt verschieden weit über den Stein.
  ctx.fillStyle = PALETTE.d;
  offsets.forEach((depth, x) => {
    if (depth > 0) ctx.fillRect(ox + x, oy + 4, 1, depth);
  });

  // Ein paar hellere Halme oben.
  ctx.fillStyle = PALETTE[6];
  ctx.fillRect(ox + 2, oy, 1, 1);
  ctx.fillRect(ox + 8, oy, 1, 1);
  ctx.fillRect(ox + 13, oy, 1, 1);
}

const MOSS_A = [0, 1, 2, 1, 0, 0, 2, 3, 2, 1, 0, 1, 2, 1, 0, 0];
const MOSS_B = [1, 2, 1, 0, 0, 1, 3, 2, 1, 0, 1, 2, 2, 1, 0, 1];

/** Einzelne Ranke, die von einer Kante herabhängt. */
function hangingVine(ctx, ox, oy, x, length) {
  ctx.fillStyle = PALETTE.d;
  ctx.fillRect(ox + x, oy, 1, length);
  ctx.fillStyle = PALETTE.e;
  ctx.fillRect(ox + x, oy, 1, Math.max(1, length - 3));
  // Blättchen
  ctx.fillStyle = PALETTE[5];
  ctx.fillRect(ox + x - 1, oy + 3, 1, 1);
  ctx.fillRect(ox + x + 1, oy + 6, 1, 1);
}

function cracks(ctx, ox, oy, stage) {
  ctx.fillStyle = PALETTE[8];
  if (stage >= 0) {
    ctx.fillRect(ox + 8, oy + 4, 1, 5);
    ctx.fillRect(ox + 7, oy + 7, 1, 3);
  }
  if (stage >= 1) {
    ctx.fillRect(ox + 3, oy + 5, 1, 6);
    ctx.fillRect(ox + 4, oy + 9, 2, 1);
    ctx.fillRect(ox + 12, oy + 4, 1, 7);
  }
  if (stage >= 2) {
    ctx.fillRect(ox + 9, oy + 3, 4, 1);
    ctx.fillRect(ox + 1, oy + 7, 3, 1);
    ctx.fillRect(ox + 13, oy + 9, 1, 5);
    ctx.fillRect(ox + 5, oy + 12, 4, 1);
    // Der Quader sackt sichtbar in sich zusammen.
    ctx.fillStyle = PALETTE[9];
    ctx.fillRect(ox, oy, TILE_SIZE, 1);
  }
}

/** Die Seitenwände des Schachts: höheres Mauerwerk, dicht berankt. */
function wall(ctx, ox, oy, variant) {
  ctx.fillStyle = PALETTE[9];
  ctx.fillRect(ox, oy, TILE_SIZE, TILE_SIZE);
  ctx.fillStyle = PALETTE.a;
  ctx.fillRect(ox + 1, oy + 1, TILE_SIZE - 2, TILE_SIZE - 3);

  // Versetzte Quaderfugen – daran erkennt man auf einen Blick, dass hier
  // gerutscht und abgesprungen werden kann.
  ctx.fillStyle = PALETTE[8];
  ctx.fillRect(ox, oy + 7, TILE_SIZE, 1);
  ctx.fillRect(ox, oy + TILE_SIZE - 1, TILE_SIZE, 1);
  ctx.fillRect(ox + (variant === 0 ? 5 : 10), oy, 1, 7);
  ctx.fillRect(ox + (variant === 0 ? 11 : 4), oy + 8, 1, 8);

  ctx.fillStyle = PALETTE.b;
  ctx.fillRect(ox + 1, oy + 8, TILE_SIZE - 2, 1);
  ctx.fillRect(ox + 1, oy + 1, TILE_SIZE - 2, 1);

  // Bewuchs
  ctx.fillStyle = PALETTE[3];
  ctx.fillRect(ox + (variant === 0 ? 2 : 12), oy, 2, TILE_SIZE);
  ctx.fillStyle = PALETTE.d;
  ctx.fillRect(ox + (variant === 0 ? 2 : 12), oy, 1, TILE_SIZE);
  ctx.fillStyle = PALETTE[4];
  ctx.fillRect(ox + (variant === 0 ? 13 : 3), oy + 4, 1, 5);
}

/**
 * Fackel in einer Wandhalterung. Zwei Bilder, damit die Flamme lebt.
 *
 * Die Flamme wirft Licht auf den Stein ringsum – dafür sind die warmen
 * Steintöne A–C in der Palette. Ohne diesen Lichthof wäre die Fackel nur ein
 * gelber Fleck, der vor der Wand klebt, statt in ihr zu stecken.
 */
function torch(ctx, ox, oy, variant) {
  wall(ctx, ox, oy, 0);

  // Lichthof: der Stein um die Flamme wird wärmer.
  ctx.fillStyle = PALETTE.A;
  ctx.fillRect(ox + 3, oy + 1, 10, 12);
  ctx.fillStyle = PALETTE.B;
  ctx.fillRect(ox + 4, oy + 2, 8, 10);
  ctx.fillStyle = PALETTE.C;
  ctx.fillRect(ox + 5, oy + 3, 6, 7);

  // Halterung
  ctx.fillStyle = PALETTE[8];
  ctx.fillRect(ox + 6, oy + 9, 4, 5);
  ctx.fillStyle = PALETTE[9];
  ctx.fillRect(ox + 6, oy + 9, 4, 1);
  ctx.fillRect(ox + 7, oy + 10, 1, 4);

  // Flamme: aussen dunkel, innen weiss – so liest sie sich als Licht.
  const tall = variant === 0 ? 0 : 1;
  ctx.fillStyle = PALETTE.n;
  ctx.fillRect(ox + 5, oy + 4 - tall, 6, 6 + tall);
  ctx.fillStyle = PALETTE.o;
  ctx.fillRect(ox + 6, oy + 4 - tall, 4, 5 + tall);
  ctx.fillStyle = PALETTE.p;
  ctx.fillRect(ox + 7, oy + 5 - tall, 2, 4 + tall);
  ctx.fillStyle = PALETTE.q;
  ctx.fillRect(ox + 7 + tall, oy + 6 - tall, 1, 2);
}

/**
 * Götzenkopf im Mauerwerk. Vier Augen wären ein Gesicht zu viel – zwei
 * leuchtende Punkte und ein Zahnrand genügen, damit man auf 16 × 16 Pixeln
 * "da schaut etwas" liest.
 */
function idol(ctx, ox, oy) {
  wall(ctx, ox, oy, 1);

  // Nische
  ctx.fillStyle = PALETTE[8];
  ctx.fillRect(ox + 2, oy + 2, 12, 12);
  ctx.fillStyle = PALETTE[9];
  ctx.fillRect(ox + 3, oy + 3, 10, 10);
  ctx.fillStyle = PALETTE.a;
  ctx.fillRect(ox + 3, oy + 3, 10, 1);
  ctx.fillRect(ox + 3, oy + 3, 1, 10);

  // Stirn und Wangen
  ctx.fillStyle = PALETTE.b;
  ctx.fillRect(ox + 4, oy + 4, 8, 3);
  ctx.fillStyle = PALETTE[9];
  ctx.fillRect(ox + 4, oy + 7, 8, 1);

  // Augen
  ctx.fillStyle = PALETTE.o;
  ctx.fillRect(ox + 5, oy + 5, 2, 2);
  ctx.fillRect(ox + 9, oy + 5, 2, 2);
  ctx.fillStyle = PALETTE.p;
  ctx.fillRect(ox + 5, oy + 5, 1, 1);
  ctx.fillRect(ox + 9, oy + 5, 1, 1);

  // Zähne
  ctx.fillStyle = PALETTE.H;
  for (let x = 0; x < 4; x += 1) ctx.fillRect(ox + 5 + x * 2, oy + 9, 1, 2);
  ctx.fillStyle = PALETTE[8];
  ctx.fillRect(ox + 4, oy + 11, 8, 1);

  // Bewuchs kriecht über den Rand – der Tempel ist verlassen.
  ctx.fillStyle = PALETTE.d;
  ctx.fillRect(ox + 2, oy + 2, 5, 1);
  ctx.fillRect(ox + 2, oy + 2, 1, 4);
  ctx.fillStyle = PALETTE[4];
  ctx.fillRect(ox + 11, oy + 12, 3, 1);
}

/** Violette Kristalle in einer Wandnische – der einzige kalte Ton im Bild. */
function crystalWall(ctx, ox, oy) {
  wall(ctx, ox, oy, 0);

  ctx.fillStyle = PALETTE[8];
  ctx.fillRect(ox + 3, oy + 4, 10, 10);

  const shard = (cx, cy, h) => {
    for (let dy = 0; dy < h; dy += 1) {
      const half = Math.max(0, Math.round(((h - dy) / h) * 2));
      ctx.fillStyle = dy < h / 3 ? PALETTE.y : dy < (h * 2) / 3 ? PALETTE.x : PALETTE.w;
      ctx.fillRect(ox + cx - half, oy + cy + dy, half * 2 + 1, 1);
    }
    ctx.fillStyle = PALETTE.z;
    ctx.fillRect(ox + cx, oy + cy + 1, 1, 2);
  };

  shard(6, 5, 8);
  shard(10, 7, 6);
  shard(8, 9, 4);
}

/** Dornen: gebleichte Knochenspitzen auf einem Moossockel. */
function spike(ctx, ox, oy) {
  ctx.fillStyle = PALETTE.d;
  ctx.fillRect(ox, oy + TILE_SIZE - 4, TILE_SIZE, 4);
  ctx.fillStyle = PALETTE[3];
  ctx.fillRect(ox, oy + TILE_SIZE - 1, TILE_SIZE, 1);

  for (let i = 0; i < 3; i += 1) {
    const cx = ox + i * 5 + 3;
    for (let h = 0; h < 12; h += 1) {
      const half = Math.max(0, Math.round((h / 12) * 2));
      ctx.fillStyle = h < 5 ? PALETTE.q : PALETTE.o;
      ctx.fillRect(cx - half, oy + 1 + h, half * 2 + 1, 1);
    }
    ctx.fillStyle = PALETTE.v;
    ctx.fillRect(cx, oy + 2, 1, 2);
  }
}

/** Blattplattform: trägt nur von oben, sieht auch danach aus. */
function leaf(ctx, ox, oy) {
  ctx.fillStyle = PALETTE[4];
  ctx.fillRect(ox, oy + 1, TILE_SIZE, 4);
  ctx.fillStyle = PALETTE[5];
  ctx.fillRect(ox, oy, TILE_SIZE, 2);
  ctx.fillStyle = PALETTE[6];
  ctx.fillRect(ox, oy, TILE_SIZE, 1);
  ctx.fillStyle = PALETTE[3];
  ctx.fillRect(ox, oy + 5, TILE_SIZE, 1);
  // Blattrippen, damit klar ist: das ist Pflanze, kein Stein.
  ctx.fillStyle = PALETTE[3];
  for (let x = 1; x < TILE_SIZE; x += 4) ctx.fillRect(ox + x, oy + 1, 1, 4);
  ctx.fillStyle = PALETTE[7];
  ctx.fillRect(ox + 3, oy, 2, 1);
  ctx.fillRect(ox + 11, oy, 2, 1);
}

/** Smaragd: geschliffener Kristall mit wanderndem Glanzlicht. */
function emerald(ctx, ox, oy, frame) {
  const cx = ox + 8;
  const cy = oy + 8;
  for (let dy = -6; dy <= 6; dy += 1) {
    const half = Math.max(0, 4 - Math.floor(Math.abs(dy) / 1.6));
    ctx.fillStyle = dy < -1 ? PALETTE.t : dy > 2 ? PALETTE.r : PALETTE.s;
    ctx.fillRect(cx - half, cy + dy, half * 2 + 1, 1);
  }
  // Facetten
  ctx.fillStyle = PALETTE.r;
  ctx.fillRect(cx - 1, cy - 1, 1, 6);
  ctx.fillStyle = PALETTE.t;
  ctx.fillRect(cx + 1, cy - 2, 1, 5);

  const glint = (frame % 4) - 1;
  ctx.fillStyle = PALETTE.u;
  ctx.fillRect(cx - 2 + glint, cy - 4, 1, 2);
  ctx.fillStyle = PALETTE.v;
  ctx.fillRect(cx - 2 + glint, cy - 4, 1, 1);
}

/**
 * Die greifbare Ranke. Sie hängt von oben herab und endet in einer Blüte –
 * dieselbe Form wie die Deko-Ranken, aber leuchtend, damit man sie im
 * Vorbeirennen als Belohnung erkennt.
 */
function vine(ctx, ox, oy, frame) {
  const sway = frame === 0 ? 0 : 1;
  ctx.fillStyle = PALETTE.d;
  for (let y = 0; y < 10; y += 1) {
    const x = 8 + (y > 5 ? sway : 0);
    ctx.fillRect(ox + x - 1, oy + y, 2, 1);
  }
  ctx.fillStyle = PALETTE.f;
  for (let y = 0; y < 10; y += 1) {
    const x = 8 + (y > 5 ? sway : 0);
    ctx.fillRect(ox + x - 1, oy + y, 1, 1);
  }
  // Blüte am Ende
  const bx = ox + 8 + sway;
  const by = oy + 10;
  ctx.fillStyle = PALETTE[5];
  ctx.fillRect(bx - 3, by, 7, 4);
  ctx.fillRect(bx - 2, by - 1, 5, 6);
  ctx.fillStyle = PALETTE[7];
  ctx.fillRect(bx - 2, by, 5, 2);
  ctx.fillStyle = PALETTE.p;
  ctx.fillRect(bx - 1, by + 1, 3, 2);
  ctx.fillStyle = PALETTE.q;
  ctx.fillRect(bx, by + 1, 1, 1);
}

/**
 * Brennt alle Kacheln einmalig in ein Blatt.
 * @returns {{canvas: HTMLCanvasElement, slots: number}}
 */
export function buildTileSheet() {
  const canvas = document.createElement('canvas');
  canvas.width = TILE_SIZE * SLOT_COUNT;
  canvas.height = TILE_SIZE;
  const ctx = canvas.getContext('2d');
  ctx.imageSmoothingEnabled = false;

  // Freiliegende Quader tragen Moos und eine Ranke, verdeckte nicht.
  block(ctx, SLOT.STONE_A * TILE_SIZE, 0, GRIT_A);
  moss(ctx, SLOT.STONE_A * TILE_SIZE, 0, MOSS_A);
  hangingVine(ctx, SLOT.STONE_A * TILE_SIZE, 0, 12, 9);

  block(ctx, SLOT.STONE_B * TILE_SIZE, 0, GRIT_B);
  moss(ctx, SLOT.STONE_B * TILE_SIZE, 0, MOSS_B);
  hangingVine(ctx, SLOT.STONE_B * TILE_SIZE, 0, 4, 12);

  block(ctx, SLOT.BURIED_A * TILE_SIZE, 0, GRIT_A);
  block(ctx, SLOT.BURIED_B * TILE_SIZE, 0, GRIT_B);

  for (let stage = 0; stage < 3; stage += 1) {
    const ox = (SLOT.CRUMBLE_0 + stage) * TILE_SIZE;
    block(ctx, ox, 0, GRIT_A);
    moss(ctx, ox, 0, MOSS_A);
    cracks(ctx, ox, 0, stage);
  }

  wall(ctx, SLOT.WALL_A * TILE_SIZE, 0, 0);
  wall(ctx, SLOT.WALL_B * TILE_SIZE, 0, 1);
  torch(ctx, SLOT.WALL_TORCH_0 * TILE_SIZE, 0, 0);
  torch(ctx, SLOT.WALL_TORCH_1 * TILE_SIZE, 0, 1);
  idol(ctx, SLOT.WALL_IDOL * TILE_SIZE, 0);
  crystalWall(ctx, SLOT.WALL_CRYSTAL * TILE_SIZE, 0);
  spike(ctx, SLOT.SPIKE * TILE_SIZE, 0);
  leaf(ctx, SLOT.LEAF * TILE_SIZE, 0);
  for (let frame = 0; frame < 4; frame += 1) {
    emerald(ctx, (SLOT.EMERALD_0 + frame) * TILE_SIZE, 0, frame);
  }
  for (let frame = 0; frame < 2; frame += 1) {
    vine(ctx, (SLOT.VINE_0 + frame) * TILE_SIZE, 0, frame);
  }

  // Eingesetzte Kacheln zuletzt, damit sie die gezeichnete Kachel wirklich
  // ersetzen und nicht darunter durchscheinen. Die Zelle wird vorher geleert:
  // ein PNG mit durchsichtigen Stellen soll durchsichtig bleiben und nicht das
  // mitgelieferte Moos durchlassen.
  for (const [name, slot] of Object.entries(SLOT)) {
    const image = tileOverride(name);
    if (image === null) continue;
    const ox = slot * TILE_SIZE;
    ctx.clearRect(ox, 0, TILE_SIZE, TILE_SIZE);
    ctx.drawImage(image, ox, 0, TILE_SIZE, TILE_SIZE);
  }

  return { canvas, slots: SLOT_COUNT };
}

/** Zeichnet eine Kachel aus dem Blatt an eine Bildschirmposition. */
export function drawTile(ctx, sheet, slot, x, y) {
  ctx.drawImage(
    sheet.canvas,
    slot * TILE_SIZE,
    0,
    TILE_SIZE,
    TILE_SIZE,
    Math.round(x),
    Math.round(y),
    TILE_SIZE,
    TILE_SIZE,
  );
}

export { COLORS };
