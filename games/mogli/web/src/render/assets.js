// Eigene Grafik über die mitgelieferte legen.
//
// Ein Paket kommt aus dem Admin-Bereich und liegt auf dem Server. Dieses Modul
// holt es, prüft es mit denselben Regeln wie der Server (net/assetRules.js),
// dekodiert die Bilder und hält sie bereit. Die Zeichenmodule fragen hier
// nach – und bekommen null, wenn nichts eingesetzt ist.
//
// ZWEI ZUSAGEN, DIE DIESE DATEI EINHALTEN MUSS
//
// 1. Ohne Paket ändert sich nichts. Kein PHP, keine Datei, ein kaputtes Paket,
//    ein Bild, das nicht lädt – in jedem dieser Fälle spielt das Spiel mit der
//    mitgelieferten Grafik weiter. Ein Grafikpaket ist Zierde, keine
//    Voraussetzung.
// 2. Ein halb geladenes Paket wird nicht angewendet. Entweder alle Bilder sind
//    da, oder es bleibt bei der mitgelieferten Grafik. Sonst hätte man eine
//    Figur mit fremdem Kopf und eigenen Beinen.

import { PHYS, T } from '../game/constants.js';
import { resetTileBoxes, setTileBox } from '../game/tilebox.js';
import { packIsEmpty, validatePack } from '../net/assetRules.js';

/**
 * Kachelname im Paket -> Kachelart in der Physik. Seit 3.0 gibt es nur noch
 * drei Gitterarten; Stacheln, Smaragde und Ranken sind Elemente mit eigener
 * Trefferfläche und tauchen hier nicht mehr auf.
 */
const TILE_KIND = {
  STONE_A: T.SOLID,
  STONE_B: T.SOLID,
  BURIED_A: T.SOLID,
  BURIED_B: T.SOLID,
  CRUMBLE_0: T.CRUMBLE,
  CRUMBLE_1: T.CRUMBLE,
  CRUMBLE_2: T.CRUMBLE,
  LEAF: T.PLATFORM,
};

/** Die Trefferfläche, wie sie ohne Paket gilt – zum Zurückstellen. */
const BUILT_IN_HITBOX = {
  x: PHYS.hitboxOffX,
  y: PHYS.hitboxOffY,
  w: PHYS.hitboxW,
  h: PHYS.hitboxH,
};

let frames = null; // { [animation]: (HTMLImageElement|null)[] }
let tiles = null; // { [slotName]: HTMLImageElement }
let layers = null; // [{ image, speed }]
let elements = null; // { [type]: { image: HTMLImageElement, box: {x,y,w,h}|null } }

/** Was das Spiel abfragt. Alle drei geben null, wenn nichts eingesetzt ist. */
export function frameOverride(animation) {
  return frames?.[animation] ?? null;
}

export function tileOverride(slotName) {
  return tiles?.[slotName] ?? null;
}

export function layerOverride() {
  return layers;
}

/** Eigene Grafik für einen Element-Typ, oder null. */
export function elementOverride(type) {
  return elements?.[type]?.image ?? null;
}

/**
 * Die eingezeichnete Trefferfläche eines Element-Typs, als ANTEILE des
 * Bildes (0…1) – oder null für "ganzes Rechteck". Anteile statt Pixel, weil
 * das Bild beim Zeichnen auf die Elementgrösse gezogen wird.
 */
export function elementBoxNorm(type) {
  const entry = elements?.[type];
  if (entry === undefined || entry.box === null || entry.image === null) return null;
  const iw = entry.image.naturalWidth || entry.image.width;
  const ih = entry.image.naturalHeight || entry.image.height;
  if (!iw || !ih) return null;
  const b = entry.box;
  return { x: b.x / iw, y: b.y / ih, w: b.w / iw, h: b.h / ih };
}

export function hasPack() {
  return frames !== null || tiles !== null || layers !== null || elements !== null;
}

/** Lädt ein Bild aus einer Daten-URL. Wirft, wenn es kein Bild ist. */
function decode(dataUrl) {
  return new Promise((resolve, reject) => {
    const image = new Image();
    image.addEventListener('load', () => resolve(image), { once: true });
    image.addEventListener('error', () => reject(new Error('Bild nicht lesbar')), { once: true });
    image.src = dataUrl;
  });
}

/**
 * Wendet ein bereits geprüftes Paket an.
 *
 * @returns {Promise<boolean>} true, wenn danach etwas anders aussieht.
 */
export async function applyPack(raw) {
  clear();
  if (packIsEmpty(raw)) return false;

  const checked = validatePack(raw);
  if (!checked.ok) {
    // Ein Paket, das der Server angenommen hat und der Client ablehnt, ist ein
    // Fehler in einer der beiden Prüfungen. Sichtbar melden, aber weiterspielen.
    console.warn(`Mogli: Grafikpaket abgelehnt (${checked.error}) – mitgelieferte Grafik bleibt.`);
    return false;
  }
  const pack = checked.pack;

  // --- Alles dekodieren, bevor irgendetwas gesetzt wird -------------------
  const nextFrames = {};
  const nextTiles = {};
  const nextLayers = [];
  const nextElements = {};
  try {
    for (const [name, list] of Object.entries(pack.player?.frames ?? {})) {
      nextFrames[name] = await Promise.all(list.map((url) => (url === null ? null : decode(url))));
    }
    for (const [name, entry] of Object.entries(pack.tiles ?? {})) {
      if (entry.image !== undefined) nextTiles[name] = await decode(entry.image);
    }
    for (const layer of pack.background?.layers ?? []) {
      nextLayers.push({ image: await decode(layer.image), speed: layer.speed });
    }
    for (const [type, entry] of Object.entries(pack.elements ?? {})) {
      nextElements[type] = {
        image: entry.image === undefined ? null : await decode(entry.image),
        box: entry.box ?? null,
      };
    }
  } catch (error) {
    console.warn(`Mogli: Grafikpaket unvollständig (${error.message}) – nichts übernommen.`);
    clear();
    return false;
  }

  // --- Erst jetzt übernehmen ----------------------------------------------
  if (Object.keys(nextFrames).length > 0) frames = nextFrames;
  if (Object.keys(nextTiles).length > 0) tiles = nextTiles;
  if (nextLayers.length > 0) layers = nextLayers;
  if (Object.keys(nextElements).length > 0) elements = nextElements;

  const hitbox = pack.player?.hitbox;
  if (hitbox !== undefined) applyHitbox(hitbox);

  for (const [name, entry] of Object.entries(pack.tiles ?? {})) {
    const kind = TILE_KIND[name];
    if (kind !== undefined && entry.box !== undefined) setTileBox(kind, entry.box);
  }

  return hasPack() || hitbox !== undefined;
}

function applyHitbox(box) {
  PHYS.hitboxOffX = box.x;
  PHYS.hitboxOffY = box.y;
  PHYS.hitboxW = box.w;
  PHYS.hitboxH = box.h;
}

/** Zurück auf die mitgelieferte Grafik. */
export function clear() {
  frames = null;
  tiles = null;
  layers = null;
  elements = null;
  resetTileBoxes();
  applyHitbox(BUILT_IN_HITBOX);
}

/**
 * Holt das Paket vom Server.
 *
 * Jeder Fehlschlag – kein PHP, 404, kaputtes JSON, Datei mit `file://`
 * geöffnet – endet hier still bei „kein Paket". Das ist kein Sonderfall,
 * sondern der Normalfall für jeden, der die ZIP einfach nur hochlädt.
 */
export async function loadPack(url = 'assets.php') {
  let response;
  try {
    response = await fetch(url, { headers: { Accept: 'application/json' } });
  } catch {
    return false;
  }
  if (!response.ok) return false;

  let data;
  try {
    data = await response.json();
  } catch {
    return false;
  }
  if (data?.ok !== true || packIsEmpty(data.pack)) return false;

  return applyPack(data.pack);
}
