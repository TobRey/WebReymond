// Der Admin-Bereich: Anmeldung, vier Reiter, Speichern.
//
// Der Zustand ist EIN Objekt (`pack`) im Format aus src/net/assetRules.js.
// Jede Bedienung ändert dieses Objekt, jede Anzeige liest daraus. Es gibt
// keinen zweiten Zustand im DOM – sonst laufen die beiden früher oder später
// auseinander, und man speichert etwas anderes, als man sieht.

import { ANIMATION_NAMES, LIMITS, validatePack } from '../../src/net/assetRules.js';
import { PHYS, VIEW_H_MAX, VIEW_W } from '../../src/game/constants.js';
import { ANIMATIONS } from '../../src/game/player.js';
import { MAP_LIMITS } from '../../src/net/mapRules.js';
import * as store from './store.js';
import {
  acceptGif,
  acceptImage,
  acceptLayer,
  bindFileArea,
  loadImage,
  showImage,
} from './sheet.js';
import { bindBoxEditor, drawBox } from './hitbox.js';
import { buildElementsTab } from './elements.js';
import { createEditor } from './editor.js';

const $ = (id) => document.getElementById(id);

const dom = {
  mode: $('mode'),
  gate: $('gate'),
  work: $('work'),
  loginForm: $('loginForm'),
  code: $('code'),
  loginStatus: $('loginStatus'),
  btnSignOut: $('btnSignOut'),
  tabs: $('tabs'),
  anims: $('anims'),
  layers: $('layers'),
  addLayer: $('addLayer'),
  hitCanvas: $('hitCanvas'),
  hitNumbers: $('hitNumbers'),
  hitReset: $('hitReset'),
  hitWarn: $('hitWarn'),
  elementList: $('elementList'),
  editorCanvas: $('editorCanvas'),
  editorWrap: $('editorWrap'),
  palette: $('palette'),
  props: $('props'),
  mapSelect: $('mapSelect'),
  mapNew: $('mapNew'),
  mapRename: $('mapRename'),
  mapUp: $('mapUp'),
  mapDown: $('mapDown'),
  mapDelete: $('mapDelete'),
  mapCell: $('mapCell'),
  mapCols: $('mapCols'),
  mapRows: $('mapRows'),
  mapTest: $('mapTest'),
  btnSave: $('btnSave'),
  btnDownload: $('btnDownload'),
  btnClear: $('btnClear'),
  saveStatus: $('saveStatus'),
};

/** Die mitgelieferte Trefferfläche – der Rücksetzpunkt. */
const DEFAULT_HITBOX = {
  x: PHYS.hitboxOffX,
  y: PHYS.hitboxOffY,
  w: PHYS.hitboxW,
  h: PHYS.hitboxH,
};

/** Der gesamte Bearbeitungszustand. */
let pack = { version: 1 };

/** Dekodierte Bilder zum Anzeigen. Nicht Teil des Pakets. */
const shown = { frames: {}, layers: [] };

/**
 * Je Bildplatz die Funktion, die ihn neu zeichnet.
 *
 * Nötig, seit ein GIF fünf Plätze auf einmal füllt: die Anzeige einfach neu
 * aufzubauen wäre kürzer, würde aber bei jedem GIF eine weitere
 * requestAnimationFrame-Schleife je Bewegung starten - die alten laufen
 * weiter, niemand hält sie an.
 */
const slotRefresh = {};

// ---------------------------------------------------------------------------
// Kleine Helfer am Paket
// ---------------------------------------------------------------------------

function frameList(name) {
  pack.player ??= {};
  pack.player.frames ??= {};
  pack.player.frames[name] ??= new Array(LIMITS.framesPerAnimation).fill(null);
  return pack.player.frames[name];
}

function hitbox() {
  return pack.player?.hitbox ?? DEFAULT_HITBOX;
}

/** Leere Zweige wieder entfernen, damit nichts Sinnloses gespeichert wird. */
function prune() {
  if (pack.player?.frames) {
    for (const [name, list] of Object.entries(pack.player.frames)) {
      if (list.every((image) => image === null)) delete pack.player.frames[name];
    }
    if (Object.keys(pack.player.frames).length === 0) delete pack.player.frames;
  }
  if (pack.player && Object.keys(pack.player).length === 0) delete pack.player;

  if (pack.tiles) {
    for (const [name, entry] of Object.entries(pack.tiles)) {
      if (Object.keys(entry).length === 0) delete pack.tiles[name];
    }
    if (Object.keys(pack.tiles).length === 0) delete pack.tiles;
  }

  if (pack.background && (pack.background.layers ?? []).length === 0) delete pack.background;
}

function status(element, text, kind = '') {
  element.textContent = text;
  element.className = `status${kind ? ` status--${kind}` : ''}`;
}

/** Fehlerschlüssel des Servers in einen lesbaren Satz. */
const ERRORS = {
  wrong_code: 'Falscher Code.',
  rate_limited: 'Zu viele Versuche. In zehn Minuten wieder probieren.',
  not_signed_in: 'Die Anmeldung ist abgelaufen. Bitte neu anmelden.',
  store_unavailable: 'Der Server kann nicht schreiben. Braucht data/ Schreibrechte?',
  pack_too_large: 'Das Paket ist zu gross. Weniger oder kleinere Bilder.',
  image_too_large: 'Ein Bild ist zu gross.',
  not_a_png: 'Eine Datei ist kein gültiges PNG.',
  unknown_animation: 'Unbekannte Bewegung.',
  unknown_tile: 'Unbekannte Kachel.',
  wrong_frame_count: 'Falsche Anzahl Bilder.',
  invalid_hitbox: 'Die Trefferfläche liegt ausserhalb des Bildes.',
  hitbox_too_small: 'Die Trefferfläche ist zu klein.',
  box_not_allowed: 'An Wänden lässt sich kein Kasten einzeichnen.',
  invalid_box: 'Der Kasten liegt ausserhalb der Kachel.',
  too_many_layers: `Höchstens ${LIMITS.backgroundLayers} Ebenen.`,
  invalid_speed: 'Das Tempo muss zwischen 0 und 2 liegen.',
  local_full: 'Der Browserspeicher ist voll.',
  needs_one_flag: 'Jede Karte braucht genau eine Zielflagge.',
  too_many_maps: `Höchstens ${MAP_LIMITS.maxMaps} Karten.`,
  too_many_elements: `Höchstens ${MAP_LIMITS.maxElements} Elemente je Karte.`,
  element_out_of_bounds: 'Ein Element liegt ausserhalb der Karte.',
  map_too_large: 'Diese Karte ist zu gross.',
  maps_too_large: 'Alle Karten zusammen sind zu gross.',
  invalid_name: 'Der Kartenname fehlt.',
  unknown_element: 'Unbekanntes Element.',
  no_php: 'Kein PHP erreichbar.',
};

function explain(error, detail) {
  const text = ERRORS[error] ?? `Unbekannter Fehler (${error}).`;
  return detail ? `${text} (${detail})` : text;
}

// ---------------------------------------------------------------------------
// Reiter „Figur"
// ---------------------------------------------------------------------------

function buildAnimations() {
  dom.anims.replaceChildren();

  for (const name of ANIMATION_NAMES) {
    const box = document.createElement('div');
    box.className = 'anim';

    const head = document.createElement('div');
    head.className = 'anim__head';
    const label = document.createElement('span');
    label.className = 'anim__name';
    label.textContent = name;
    const play = document.createElement('canvas');
    play.className = 'anim__play';
    play.width = LIMITS.frameSize;
    play.height = LIMITS.frameSize;
    head.append(label, buildGifDrop(name), play);

    const slots = document.createElement('div');
    slots.className = 'slots';

    for (let index = 0; index < LIMITS.framesPerAnimation; index += 1) {
      slots.append(buildSlot(name, index));
    }

    box.append(head, slots);
    dom.anims.append(box);

    startPlayback(name, play);
  }
}

/**
 * Die Fläche für „ein GIF für die ganze Bewegung".
 *
 * Sie sitzt bewusst neben der Bewegung und nicht auf einem der fünf Plätze:
 * ein GIF füllt alle fünf auf einmal, und wo etwas fünf Felder ändert, soll
 * man nicht auf ein einzelnes Feld zielen müssen.
 */
/** Ein einzelnes Bild für alle fünf Plätze – der Fall "kein GIF eingeworfen". */
async function alleGleich(file) {
  const bild = await acceptImage(file, { width: LIMITS.frameSize, height: LIMITS.frameSize });
  return {
    dataUrls: new Array(LIMITS.framesPerAnimation).fill(bild.dataUrl),
    frameCount: 1,
  };
}

function buildGifDrop(name) {
  // Der Dateiwähler steht NEBEN dem Knopf, nicht darin: bindFileArea ruft bei
  // einem Klick input.click(), und ein Klick auf ein Kind blubbert zurück zum
  // Knopf - das wäre eine Schleife ohne Ende.
  const wrap = document.createElement('div');
  wrap.className = 'gifwrap';

  const drop = document.createElement('button');
  drop.type = 'button';
  drop.className = 'gifdrop';
  const kopf = document.createElement('b');
  kopf.textContent = 'GIF';
  const unten = document.createElement('span');
  unten.textContent = 'alle 5 auf einmal';
  drop.append(kopf, unten);

  const input = document.createElement('input');
  input.type = 'file';
  // Auch hier keine Vorauswahl: was kein GIF ist, landet unten in allen fünf
  // Plätzen. Eine Datei abzulehnen, weil sie in der falschen Fläche gelandet
  // ist, hilft niemandem.
  input.accept = 'image/*';
  input.hidden = true;

  bindFileArea(drop, input, async (file) => {
    try {
      const istGif = file.type.includes('gif') || /\.gif$/i.test(file.name);
      const { dataUrls, frameCount } = istGif
        ? await acceptGif(file, { size: LIMITS.frameSize, count: LIMITS.framesPerAnimation })
        : await alleGleich(file);

      const liste = frameList(name);
      shown.frames[name] ??= new Array(LIMITS.framesPerAnimation).fill(null);
      for (let i = 0; i < dataUrls.length; i += 1) {
        liste[i] = dataUrls[i];
        shown.frames[name][i] = await loadImage(dataUrls[i]);
      }
      for (const auffrischen of slotRefresh[name] ?? []) auffrischen();
      refreshHitbox();

      // Was mit den Bildern passiert ist, gehört gesagt: bei mehr als fünf
      // wurde ausgewählt, bei weniger gedehnt. Wer das nicht liest, wundert
      // sich später über eine Bewegung, die er so nicht gezeichnet hat.
      const hinweis = !istGif
        ? 'kein GIF – dasselbe Bild in alle fünf Plätze'
        : frameCount === LIMITS.framesPerAnimation
          ? `${frameCount} Bilder übernommen`
          : frameCount > LIMITS.framesPerAnimation
            ? `${frameCount} Bilder im GIF – fünf davon nach Laufzeit ausgewählt`
            : `${frameCount} Bilder im GIF – auf fünf Plätze verteilt, keines fehlt`;
      status(dom.saveStatus, `${name}: ${hinweis}. Noch nicht gespeichert.`);
    } catch (error) {
      status(dom.saveStatus, `${name}: ${error.message}`, 'bad');
    }
  });

  wrap.append(drop, input);
  return wrap;
}

/**
 * Sagt in einem Halbsatz, was mit der Datei passiert ist.
 *
 * Stillschweigend umzurechnen wäre der bequeme Weg und der falsche: wer ein
 * 200 × 200 grosses Bild einsetzt und ein 32 × 32 grosses zurückbekommt, soll
 * das lesen und nicht raten.
 */
function umgerechnet(bild, ziel) {
  const teile = [];
  if (bild.frameCount > 1) teile.push(`GIF mit ${bild.frameCount} Bildern, erstes genommen`);
  teile.push(
    bild.width === ziel && bild.height === ziel
      ? 'eingesetzt'
      : `${bild.width} × ${bild.height} umgerechnet auf ${ziel} × ${ziel}`,
  );
  return `${teile.join(', ')}. Noch nicht gespeichert.`;
}

function buildSlot(name, index) {
  const slot = document.createElement('div');
  slot.className = 'slot';

  const view = document.createElement('canvas');
  view.className = 'slot__view';
  view.width = LIMITS.frameSize;
  view.height = LIMITS.frameSize;
  view.title = `${name} ${index + 1}`;

  const input = document.createElement('input');
  input.type = 'file';
  input.accept = 'image/*';

  const clear = document.createElement('button');
  clear.type = 'button';
  clear.className = 'slot__clear';
  clear.textContent = `${index + 1} ✕`;

  const refresh = () => {
    const image = shown.frames[name]?.[index] ?? null;
    showImage(view, image);
    view.classList.toggle('slot__view--filled', image !== null);
  };
  slotRefresh[name] ??= [];
  slotRefresh[name][index] = refresh;

  bindFileArea(view, input, async (file) => {
    try {
      const bild = await acceptImage(file, {
        width: LIMITS.frameSize,
        height: LIMITS.frameSize,
      });
      frameList(name)[index] = bild.dataUrl;
      shown.frames[name] ??= new Array(LIMITS.framesPerAnimation).fill(null);
      shown.frames[name][index] = await loadImage(bild.dataUrl);
      refresh();
      status(dom.saveStatus, `${name} ${index + 1}: ${umgerechnet(bild, LIMITS.frameSize)}`);
    } catch (error) {
      status(dom.saveStatus, `${name} ${index + 1}: ${error.message}`, 'bad');
    }
  });

  clear.addEventListener('click', () => {
    if (pack.player?.frames?.[name]) pack.player.frames[name][index] = null;
    if (shown.frames[name]) shown.frames[name][index] = null;
    refresh();
    status(dom.saveStatus, `${name} ${index + 1} geleert – noch nicht gespeichert.`);
  });

  slot.append(view, input, clear);
  refresh();
  return slot;
}

/**
 * Spielt die Bewegung im echten Tempo ab.
 *
 * Leere Plätze bleiben leer statt übersprungen zu werden: so sieht man sofort,
 * welches Bild noch fehlt, statt sich über ein Stocken zu wundern.
 */
function startPlayback(name, canvas) {
  const definition = ANIMATIONS[name];
  let frame = 0;
  let tick = 0;

  const step = () => {
    tick += 1;
    if (tick >= definition.ticksPerFrame) {
      tick = 0;
      frame = (frame + 1) % LIMITS.framesPerAnimation;
      showImage(canvas, shown.frames[name]?.[frame] ?? null);
    }
    window.requestAnimationFrame(step);
  };
  showImage(canvas, shown.frames[name]?.[0] ?? null);
  window.requestAnimationFrame(step);
}

function refreshHitbox() {
  const box = hitbox();
  // Das erste eingesetzte Bild als Hintergrund – sonst zieht man den Kasten
  // ins Leere.
  const sample =
    Object.values(shown.frames)
      .flat()
      .find((image) => image) ?? null;
  drawBox(dom.hitCanvas, sample, box);
  dom.hitNumbers.textContent = `x ${box.x}  y ${box.y}  Breite ${box.w}  Höhe ${box.h}`;

  // Die Warnung nennt die Zahl, an der es scheitern würde – nicht nur "zu
  // gross". Der Absatzabstand ist 4 Kacheln; ein zu hoher Körper stösst sich
  // beim Sprung den Kopf.
  const tooTall = box.h > 26;
  dom.hitWarn.hidden = !tooTall;
  if (tooTall) {
    dom.hitWarn.textContent = `Höhe ${box.h}: über 26 Pixel stösst sich Mogli beim Sprung den Kopf am nächsten Absatz. Prüfe es im Reiter Vorschau.`;
  }
}

// ---------------------------------------------------------------------------
// Reiter „Kacheln"
// ---------------------------------------------------------------------------

// ---------------------------------------------------------------------------
// Reiter „Hintergrund"
// ---------------------------------------------------------------------------

function buildLayers() {
  dom.layers.replaceChildren();
  const list = pack.background?.layers ?? [];

  list.forEach((layer, index) => {
    const row = document.createElement('div');
    row.className = 'layer';

    const view = document.createElement('img');
    view.className = 'layer__view';
    view.src = layer.image;
    view.alt = `Ebene ${index + 1}`;

    const side = document.createElement('div');
    side.className = 'layer__side';

    const label = document.createElement('label');
    label.className = 'field';
    const caption = document.createElement('span');
    caption.className = 'field__label';
    caption.textContent = `Ebene ${index + 1} · Tempo`;
    const speed = document.createElement('input');
    speed.type = 'number';
    speed.min = '0';
    speed.max = '2';
    speed.step = '0.01';
    speed.value = String(layer.speed);
    speed.addEventListener('change', () => {
      const value = Number(speed.value);
      layer.speed = Number.isFinite(value) ? Math.max(0, Math.min(2, value)) : 0;
      speed.value = String(layer.speed);
      status(dom.saveStatus, `Ebene ${index + 1}: Tempo geändert – noch nicht gespeichert.`);
    });
    label.append(caption, speed);

    const remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'btn btn--ghost';
    remove.textContent = 'Entfernen';
    remove.addEventListener('click', () => {
      list.splice(index, 1);
      prune();
      buildLayers();
      status(dom.saveStatus, 'Ebene entfernt – noch nicht gespeichert.');
    });

    side.append(label, remove);
    row.append(view, side);
    dom.layers.append(row);
  });

  dom.addLayer.hidden = list.length >= LIMITS.backgroundLayers;
}

function bindAddLayer() {
  const input = document.createElement('input');
  input.type = 'file';
  input.accept = 'image/*';
  input.style.display = 'none';
  document.body.append(input);

  dom.addLayer.addEventListener('click', () => input.click());
  input.addEventListener('change', async () => {
    const file = input.files?.[0];
    input.value = '';
    if (!file) return;
    try {
      // Die Breite liegt fest, die Höhe folgt dem Bild: sie bestimmt, nach
      // welcher Strecke sich die Ebene beim Klettern wiederholt.
      const ebene = await acceptLayer(file, {
        width: VIEW_W,
        // Der höchste Bildschirm, den es gibt: so gross muss die Ebene sein,
        // damit ein stehender Hintergrund ihn füllt, ohne vergrössert zu
        // werden.
        coverHeight: VIEW_H_MAX,
        maxHeight: LIMITS.maxLayerHeight,
        maxBytes: LIMITS.maxLayerBytes,
      });
      pack.background ??= { layers: [] };
      pack.background.layers.push({ image: ebene.dataUrl, speed: 0.3 });
      buildLayers();
      const gleich = ebene.width === ebene.zielBreite && ebene.height === ebene.zielHoehe;
      const wie =
        (gleich
          ? `${ebene.width} × ${ebene.height} übernommen`
          : `${ebene.width} × ${ebene.height} umgerechnet auf ${ebene.zielBreite} × ${ebene.zielHoehe}`) +
        (ebene.beschnitten ? ', mittig beschnitten' : '') +
        (ebene.verkleinert ? ', weiter verkleinert damit es ins Paket passt' : '') +
        ` (${Math.round(ebene.bytes / 1024)} kB)`;
      status(dom.saveStatus, `Ebene: ${wie}. Noch nicht gespeichert.`);
    } catch (error) {
      status(dom.saveStatus, `Ebene: ${error.message}`, 'bad');
    }
  });
}

// ---------------------------------------------------------------------------
// Reiter
// ---------------------------------------------------------------------------

const TAB_IDS = ['figur', 'elemente', 'karten', 'hintergrund'];

function showTab(wanted) {
  for (const id of TAB_IDS) {
    $(`tab-${id}`).hidden = id !== wanted;
  }
  for (const button of dom.tabs.querySelectorAll('[data-tab]')) {
    button.setAttribute('aria-pressed', String(button.dataset.tab === wanted));
  }

  if (wanted === 'figur') refreshHitbox();
  if (wanted === 'karten') editor.redraw();
}

// ---------------------------------------------------------------------------
// Speichern
// ---------------------------------------------------------------------------

async function doSave() {
  prune();
  const checked = validatePack(pack);
  if (!checked.ok) {
    status(dom.saveStatus, explain(checked.error, checked.detail), 'bad');
    return;
  }
  // Ein Knopf speichert ALLES – Grafik und Karten. Zwei Speicherknöpfe
  // hiessen: einer wird vergessen, und dann spielt die Welt eine Karte,
  // deren Grafik es nicht gibt (oder umgekehrt).
  const mapsChecked = editor.validateAll();
  if (!mapsChecked.ok) {
    status(
      dom.saveStatus,
      `Karte „${mapsChecked.name}": ${explain(mapsChecked.error, mapsChecked.detail)}`,
      'bad',
    );
    return;
  }
  status(dom.saveStatus, 'Wird gespeichert …');
  try {
    const { bytes } = await store.save(checked.pack);
    const { count } = await store.saveMaps(editor.getMaps());
    const kb = Math.round(bytes / 1024);
    status(
      dom.saveStatus,
      store.getMode() === 'local'
        ? `Gespeichert (${kb} kB Grafik, ${count} Karten) – nur in diesem Browser. Für alle Besucher: „Als Dateien laden" und beide Dateien nach data/ hochladen.`
        : `Gespeichert (${kb} kB Grafik, ${count} Karten). Die Spielseite neu laden.`,
      'good',
    );
  } catch (error) {
    status(dom.saveStatus, explain(error.message, error.detail), 'bad');
  }
}

async function doClear() {
  pack = { version: 1 };
  shown.frames = {};
  shown.layers = [];
  try {
    await store.clearStored();
  } catch (error) {
    status(dom.saveStatus, explain(error.message), 'bad');
    return;
  }
  buildAnimations();
  buildElementsTab(dom.elementList, elementsHost);
  buildLayers();
  refreshHitbox();
  status(
    dom.saveStatus,
    'Alles zurückgesetzt. Das Spiel zeigt wieder die mitgelieferte Grafik.',
    'good',
  );
}

// ---------------------------------------------------------------------------
// Anmeldung und Start
// ---------------------------------------------------------------------------

async function enter() {
  dom.gate.hidden = true;
  dom.work.hidden = false;
  dom.btnSignOut.hidden = store.getMode() === 'local';

  try {
    pack = await store.load();
  } catch (error) {
    pack = { version: 1 };
    status(dom.saveStatus, explain(error.message), 'bad');
  }
  if (pack?.version !== 1) pack = { version: 1 };

  // Die gespeicherten Bilder zum Anzeigen dekodieren.
  for (const [name, list] of Object.entries(pack.player?.frames ?? {})) {
    shown.frames[name] = await Promise.all(
      list.map((url) => (url === null ? null : loadImage(url).catch(() => null))),
    );
  }
  buildAnimations();
  buildElementsTab(dom.elementList, elementsHost);
  buildLayers();
  refreshHitbox();

  // Die Karten des Editors.
  let maps = [];
  try {
    maps = await store.loadMaps();
  } catch (error) {
    status(dom.saveStatus, explain(error.message), 'bad');
  }
  editor.setMaps(maps);
}

/** Was der Elemente-Reiter vom Rest braucht. */
const elementsHost = {
  get: () => pack,
  status: (text, bad = false) => status(dom.saveStatus, text, bad ? 'bad' : ''),
};

/** Der Editor. Erst enter() reicht ihm die Karten hinein. */
const editor = createEditor(dom, {
  status: (text, bad = false) => status(dom.saveStatus, text, bad ? 'bad' : ''),
  onDirty: () => status(dom.saveStatus, 'Karte geändert – noch nicht gespeichert.'),
  beforeTest: () =>
    status(
      dom.saveStatus,
      'Zum Testen bitte erst speichern – das Spiel lädt die GESPEICHERTEN Karten.',
    ),
});

function bindEverything() {
  dom.loginForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    status(dom.loginStatus, 'Wird geprüft …');
    const result = await store.signIn(dom.code.value);
    if (result.ok) {
      dom.code.value = '';
      status(dom.loginStatus, '');
      await enter();
    } else {
      status(dom.loginStatus, explain(result.error), 'bad');
    }
  });

  dom.btnSignOut.addEventListener('click', () => {
    store.signOut();
    dom.work.hidden = true;
    dom.gate.hidden = false;
    dom.btnSignOut.hidden = true;
  });

  dom.tabs.addEventListener('click', (event) => {
    const button = event.target.closest('[data-tab]');
    if (button) showTab(button.dataset.tab);
  });

  bindBoxEditor(
    dom.hitCanvas,
    LIMITS.frameSize,
    () => hitbox(),
    (box) => {
      pack.player ??= {};
      pack.player.hitbox = box;
      refreshHitbox();
      status(dom.saveStatus, 'Trefferfläche geändert – noch nicht gespeichert.');
    },
  );

  dom.hitReset.addEventListener('click', () => {
    if (pack.player) delete pack.player.hitbox;
    refreshHitbox();
    status(dom.saveStatus, 'Trefferfläche zurückgesetzt – noch nicht gespeichert.');
  });

  bindAddLayer();

  dom.btnSave.addEventListener('click', doSave);
  dom.btnClear.addEventListener('click', doClear);
  dom.btnDownload.addEventListener('click', () => {
    prune();
    const checked = validatePack(pack);
    if (!checked.ok) {
      status(dom.saveStatus, explain(checked.error, checked.detail), 'bad');
      return;
    }
    // Beide Dateien: assets.json (Grafik) und maps.json (Karten). Wer ohne
    // PHP arbeitet, lädt beide nach data/ hoch.
    store.download(checked.pack);
    const mapsChecked = editor.validateAll();
    if (mapsChecked.ok) store.downloadMaps(editor.getMaps());
    else {
      status(
        dom.saveStatus,
        `Karten nicht dabei – „${mapsChecked.name}": ${explain(mapsChecked.error, mapsChecked.detail)}`,
        'bad',
      );
    }
  });
}

async function boot() {
  bindEverything();

  const mode = await store.probe();
  if (mode === 'local') {
    dom.mode.textContent = 'Nur dieser Browser · kein PHP';
    dom.mode.className = 'mode mode--local';
    // Ohne PHP gibt es keinen Code zu prüfen: es liegt ohnehin nichts auf dem
    // Server, was ein Code schützen könnte.
    await enter();
  } else {
    dom.mode.textContent = 'Server';
    dom.mode.className = 'mode';
    if (store.isSignedIn()) await enter();
  }
}

boot();
