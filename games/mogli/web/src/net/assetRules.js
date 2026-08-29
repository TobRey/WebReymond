// Die Regeln für ein Grafikpaket – als reine Funktionen, ohne DOM und ohne
// Netz. Genau dieselbe Prüfung läuft an drei Stellen:
//
//   1. im Admin-Bereich, bevor gesendet wird (damit man den Fehler sofort sieht)
//   2. in admin.php, bevor geschrieben wird (dort ist sie massgeblich)
//   3. in games/mogli/test/assets.test.mjs
//
// Dieselbe Aufteilung wie bei scoreRules.js: die Regeln stehen EINMAL, und die
// PHP-Seite spiegelt sie. Wer hier etwas ändert, ändert score.php-artig auch
// admin.php – der Test hält beide Zahlen zusammen.

/** Die acht Bewegungen. Reihenfolge ist die Anzeigereihenfolge im Admin. */
export const ANIMATION_NAMES = [
  'idle',
  'run',
  'jump',
  'fall',
  'wallslide',
  'land',
  'vine',
  'death',
];

/**
 * Die Kachelplätze, die ausgetauscht werden können.
 *
 * Die Namen sind die Schlüssel aus render/tiles.js. Bewusst NICHT dabei sind
 * die Wandvarianten mit Fackel, Götze und Kristall: die sind Schmuck, den
 * scene.js nach Zeilennummer wählt: wer die Wand ersetzt, ersetzt WALL_A und
 * WALL_B, und der Schmuck verschwindet mit. Das ist ehrlicher, als vier
 * Plätze anzubieten, deren Zusammenspiel niemand erklären kann.
 */
export const TILE_NAMES = [
  'STONE_A',
  'STONE_B',
  'BURIED_A',
  'BURIED_B',
  'CRUMBLE_0',
  'CRUMBLE_1',
  'CRUMBLE_2',
  'WALL_A',
  'WALL_B',
  'SPIKE',
  'LEAF',
  'EMERALD_0',
  'EMERALD_1',
  'EMERALD_2',
  'EMERALD_3',
  'VINE_0',
  'VINE_1',
];

export const LIMITS = {
  /** Bilder je Bewegung. Deckt sich mit ANIMATIONS in game/player.js. */
  framesPerAnimation: 5,
  frameSize: 32,
  tileSize: 16,
  backgroundLayers: 4,

  /** Ein einzelnes Bild, in Bytes nach dem Dekodieren. */
  maxImageBytes: 256 * 1024,
  /** Das ganze Paket als JSON, in Bytes. */
  maxPackBytes: 4 * 1024 * 1024,

  /** Die Trefferfläche darf nicht beliebig klein oder gross werden. */
  minHitbox: 4,
};

/** Kachelnamen, deren Kasten festliegt: an Wänden wird abgesprungen. */
export const FIXED_BOX_TILES = ['WALL_A', 'WALL_B'];

const PNG_PREFIX = 'data:image/png;base64,';

/**
 * Ist das eine PNG-Daten-URL mit gültiger Signatur?
 *
 * Die Signatur wird wirklich geprüft, nicht nur die Endung oder der MIME-Typ
 * im Text davor: beides kann jeder frei behaupten. Auf dem Server ist das die
 * Prüfung, die verhindert, dass etwas anderes als ein Bild in der Ablage
 * landet.
 */
export function isPngDataUrl(value) {
  if (typeof value !== 'string' || !value.startsWith(PNG_PREFIX)) return false;
  const base64 = value.slice(PNG_PREFIX.length);
  if (base64.length === 0 || !/^[A-Za-z0-9+/]+={0,2}$/.test(base64)) return false;

  // Die ersten acht Bytes eines PNG sind festgelegt: 89 50 4E 47 0D 0A 1A 0A.
  // Zwölf base64-Zeichen decken sie sicher ab.
  const head = base64.slice(0, 12);
  let bytes;
  try {
    bytes = base64Head(head);
  } catch {
    return false;
  }
  const signature = [0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a];
  return signature.every((byte, index) => bytes[index] === byte);
}

/** Dekodiert die ersten base64-Zeichen zu Bytes – ohne atob/Buffer. */
function base64Head(text) {
  const table = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';
  const out = [];
  let bits = 0;
  let value = 0;
  for (const char of text) {
    const index = table.indexOf(char);
    if (index < 0) throw new Error('kein base64');
    value = (value << 6) | index;
    bits += 6;
    if (bits >= 8) {
      bits -= 8;
      out.push((value >> bits) & 0xff);
    }
  }
  return out;
}

/** Ungefähre Bytezahl hinter einer base64-Zeichenkette. */
export function dataUrlBytes(value) {
  if (typeof value !== 'string') return 0;
  const base64 = value.slice(value.indexOf(',') + 1);
  const padding = base64.endsWith('==') ? 2 : base64.endsWith('=') ? 1 : 0;
  return Math.floor((base64.length * 3) / 4) - padding;
}

function intInRange(value, min, max) {
  const n = Number(value);
  return Number.isInteger(n) && n >= min && n <= max;
}

function validBox(box, size) {
  if (box === null || typeof box !== 'object') return false;
  if (!intInRange(box.x, 0, size - 1) || !intInRange(box.y, 0, size - 1)) return false;
  if (!intInRange(box.w, 1, size) || !intInRange(box.h, 1, size)) return false;
  return box.x + box.w <= size && box.y + box.h <= size;
}

/**
 * Prüft ein ganzes Paket.
 *
 * Verworfen wird streng: ein unbekannter Schlüssel, eine falsche Bildzahl oder
 * ein Kasten ausserhalb der Kachel machen das Paket ungültig. Ein halb
 * angenommenes Paket wäre schlimmer als gar keins – es fiele erst mitten im
 * Spiel auf.
 *
 * @returns {{ok: true, pack: object} | {ok: false, error: string, detail?: string}}
 */
export function validatePack(input) {
  if (input === null || typeof input !== 'object') {
    return { ok: false, error: 'invalid_pack' };
  }
  if (input.version !== 1) {
    return { ok: false, error: 'wrong_version', detail: String(input.version) };
  }

  const pack = { version: 1 };

  // --- Figur ---------------------------------------------------------------
  if (input.player !== undefined && input.player !== null) {
    if (typeof input.player !== 'object') return { ok: false, error: 'invalid_player' };
    const player = {};

    if (input.player.hitbox !== undefined && input.player.hitbox !== null) {
      const box = input.player.hitbox;
      if (!validBox(box, LIMITS.frameSize)) {
        return { ok: false, error: 'invalid_hitbox' };
      }
      if (box.w < LIMITS.minHitbox || box.h < LIMITS.minHitbox) {
        return { ok: false, error: 'hitbox_too_small' };
      }
      player.hitbox = { x: box.x, y: box.y, w: box.w, h: box.h };
    }

    if (input.player.frames !== undefined && input.player.frames !== null) {
      if (typeof input.player.frames !== 'object') return { ok: false, error: 'invalid_frames' };
      const frames = {};
      for (const [name, list] of Object.entries(input.player.frames)) {
        if (!ANIMATION_NAMES.includes(name)) {
          return { ok: false, error: 'unknown_animation', detail: name };
        }
        if (!Array.isArray(list) || list.length !== LIMITS.framesPerAnimation) {
          return { ok: false, error: 'wrong_frame_count', detail: name };
        }
        for (const image of list) {
          if (image === null) continue;
          if (!isPngDataUrl(image)) return { ok: false, error: 'not_a_png', detail: name };
          if (dataUrlBytes(image) > LIMITS.maxImageBytes) {
            return { ok: false, error: 'image_too_large', detail: name };
          }
        }
        // Eine Bewegung, in der KEIN Platz belegt ist, wird weggelassen statt
        // als leere Liste gespeichert – sonst überschriebe sie die
        // mitgelieferte Grafik mit Nichts.
        if (list.some((image) => image !== null)) frames[name] = list.slice();
      }
      if (Object.keys(frames).length > 0) player.frames = frames;
    }

    if (Object.keys(player).length > 0) pack.player = player;
  }

  // --- Kacheln -------------------------------------------------------------
  if (input.tiles !== undefined && input.tiles !== null) {
    if (typeof input.tiles !== 'object') return { ok: false, error: 'invalid_tiles' };
    const tiles = {};
    for (const [name, entry] of Object.entries(input.tiles)) {
      if (!TILE_NAMES.includes(name)) {
        return { ok: false, error: 'unknown_tile', detail: name };
      }
      if (entry === null || typeof entry !== 'object') {
        return { ok: false, error: 'invalid_tile', detail: name };
      }
      const clean = {};
      if (entry.image !== undefined && entry.image !== null) {
        if (!isPngDataUrl(entry.image)) return { ok: false, error: 'not_a_png', detail: name };
        if (dataUrlBytes(entry.image) > LIMITS.maxImageBytes) {
          return { ok: false, error: 'image_too_large', detail: name };
        }
        clean.image = entry.image;
      }
      if (entry.box !== undefined && entry.box !== null) {
        if (FIXED_BOX_TILES.includes(name)) {
          return { ok: false, error: 'box_not_allowed', detail: name };
        }
        if (!validBox(entry.box, LIMITS.tileSize)) {
          return { ok: false, error: 'invalid_box', detail: name };
        }
        clean.box = { x: entry.box.x, y: entry.box.y, w: entry.box.w, h: entry.box.h };
      }
      if (Object.keys(clean).length > 0) tiles[name] = clean;
    }
    if (Object.keys(tiles).length > 0) pack.tiles = tiles;
  }

  // --- Hintergrund ---------------------------------------------------------
  if (input.background !== undefined && input.background !== null) {
    if (typeof input.background !== 'object') return { ok: false, error: 'invalid_background' };
    const layers = input.background.layers;
    if (layers !== undefined && layers !== null) {
      if (!Array.isArray(layers) || layers.length > LIMITS.backgroundLayers) {
        return { ok: false, error: 'too_many_layers' };
      }
      const clean = [];
      for (const layer of layers) {
        if (layer === null || typeof layer !== 'object') {
          return { ok: false, error: 'invalid_layer' };
        }
        if (!isPngDataUrl(layer.image)) return { ok: false, error: 'not_a_png', detail: 'layer' };
        if (dataUrlBytes(layer.image) > LIMITS.maxImageBytes) {
          return { ok: false, error: 'image_too_large', detail: 'layer' };
        }
        const speed = Number(layer.speed);
        if (!Number.isFinite(speed) || speed < 0 || speed > 2) {
          return { ok: false, error: 'invalid_speed' };
        }
        // Auf zwei Nachkommastellen: mehr sieht niemand, und es hält die
        // Ablage klein.
        clean.push({ image: layer.image, speed: Math.round(speed * 100) / 100 });
      }
      if (clean.length > 0) pack.background = { layers: clean };
    }
  }

  // --- Gesamtgrösse --------------------------------------------------------
  const size = JSON.stringify(pack).length;
  if (size > LIMITS.maxPackBytes) {
    return { ok: false, error: 'pack_too_large', detail: String(size) };
  }

  return { ok: true, pack };
}

/** Ein leeres, gültiges Paket – der Zustand „nichts eingesetzt". */
export function emptyPack() {
  return { version: 1 };
}

/** Enthält das Paket überhaupt etwas? */
export function packIsEmpty(pack) {
  return (
    pack === null ||
    typeof pack !== 'object' ||
    (pack.player === undefined && pack.tiles === undefined && pack.background === undefined)
  );
}
