import { decodeGif } from './gif.js';

/**
 * Ein Sprite ist eine Liste fertiger Frames plus Zeitangaben.
 * PNGs haben genau einen Frame, GIFs ihre dekodierten Einzelbilder.
 */
/** Auflösung der Bild-Nachschlagetabelle in Millisekunden. */
const FRAME_STEP = 16;

export class Sprite {
  constructor(frames, delays, width, height, src) {
    this.frames = frames;
    this.delays = delays;
    this.width = width;
    this.height = height;
    this.src = src;
    this.duration = delays.reduce((a, b) => a + b, 0) || 100;
    this.animated = frames.length > 1;

    // Nachschlagetabelle statt Schleife: Bei 80 Gegnern wird frameAt
    // achtzigmal pro Bild aufgerufen, jedes Mal mit einer Schleife über
    // alle Einzelbilder. Einmal vorberechnet ist daraus ein Array-Zugriff.
    if (this.animated) {
      const steps = Math.max(1, Math.ceil(this.duration / FRAME_STEP));
      this.lookup = new Uint8Array(steps);
      for (let step = 0; step < steps; step++) {
        let t = step * FRAME_STEP;
        let index = frames.length - 1;
        for (let i = 0; i < frames.length; i++) {
          t -= delays[i];
          if (t < 0) {
            index = i;
            break;
          }
        }
        this.lookup[step] = index;
      }
    }
  }

  /** Endlos laufende Animation. */
  frameAt(timeMs) {
    if (!this.animated) return this.frames[0];
    const t = timeMs % this.duration;
    return this.frames[this.lookup[(t / FRAME_STEP) | 0]];
  }

  /** Einmalige Animation - liefert null, wenn sie durchgelaufen ist. */
  frameOnce(timeMs) {
    if (!this.animated) return timeMs < this.duration ? this.frames[0] : null;
    if (timeMs >= this.duration) return null;
    let t = timeMs;
    for (let i = 0; i < this.frames.length; i++) {
      t -= this.delays[i];
      if (t < 0) return this.frames[i];
    }
    return null;
  }
}


/**
 * Schneidet transparente Raender weg.
 *
 * Die Quell-GIFs haben sehr unterschiedlich viel Leerraum (256x256 bis
 * 1000x1000). Ohne Trimmen würde derselbe Skalierungswert bei jedem
 * Gegner eine andere sichtbare Größe ergeben. Die Grenzen werden auf
 * einer verkleinerten Kopie gesucht, das kostet beim Laden fast nichts.
 */
function trimFrames(frames, width, height) {
  const probeSize = 110;
  const scale = Math.min(1, probeSize / Math.max(width, height));
  const pw = Math.max(1, Math.round(width * scale));
  const ph = Math.max(1, Math.round(height * scale));
  const probe = document.createElement('canvas');
  probe.width = pw;
  probe.height = ph;
  const pctx = probe.getContext('2d', { willReadFrequently: true });

  let minX = pw;
  let minY = ph;
  let maxX = -1;
  let maxY = -1;

  for (const frame of frames) {
    pctx.clearRect(0, 0, pw, ph);
    pctx.drawImage(frame, 0, 0, pw, ph);
    const data = pctx.getImageData(0, 0, pw, ph).data;
    for (let y = 0; y < ph; y++) {
      for (let x = 0; x < pw; x++) {
        if (data[(y * pw + x) * 4 + 3] > 12) {
          if (x < minX) minX = x;
          if (x > maxX) maxX = x;
          if (y < minY) minY = y;
          if (y > maxY) maxY = y;
        }
      }
    }
  }

  if (maxX < 0) return null;

  const pad = 1;
  const x0 = Math.max(0, Math.floor((minX - pad) / scale));
  const y0 = Math.max(0, Math.floor((minY - pad) / scale));
  const x1 = Math.min(width, Math.ceil((maxX + 1 + pad) / scale));
  const y1 = Math.min(height, Math.ceil((maxY + 1 + pad) / scale));
  const w = x1 - x0;
  const h = y1 - y0;
  if (w <= 0 || h <= 0) return null;
  // Kaum Rand vorhanden - dann lohnt das Zuschneiden nicht.
  if (w * h > width * height * 0.93) return null;

  const trimmed = frames.map((frame) => {
    const canvas = document.createElement('canvas');
    canvas.width = w;
    canvas.height = h;
    canvas.getContext('2d').drawImage(frame, x0, y0, w, h, 0, 0, w, h);
    return canvas;
  });
  return { frames: trimmed, width: w, height: h };
}

/**
 * Verkleinert sehr große Frames.
 *
 * Die Quell-GIFs sind bis zu 1000x1000 Pixel groß, gezeichnet werden sie im
 * Spiel mit 60 bis 200 Pixeln Höhe. Ohne diesen Schritt kosten Dekodierung
 * und Speicher auf dem Handy ein Vielfaches, ohne dass man etwas sieht.
 */
/**
 * Obergrenze für die Kantenlänge eines Sprites.
 *
 * Im Spiel werden Figuren 60-200 px hoch gezeichnet. Alles darüber kostet
 * nur Speicher und Zeichenzeit: Ein 384er Sprite mit zehn Bildern belegt
 * knapp sechs Megabyte, ein 192er nur noch anderthalb. Auf dem Handy ist
 * das der Unterschied zwischen flüssig und irgendwann eingefroren.
 *
 * Die Karte lädt mit maxSide 0 und bleibt deshalb in voller Auflösung.
 */
const MAX_SPRITE_SIDE = 192;

function shrinkFrames(frames, width, height, maxSide = MAX_SPRITE_SIDE) {
  const longest = Math.max(width, height);
  if (!maxSide || longest <= maxSide) return null;
  const factor = maxSide / longest;
  const w = Math.max(1, Math.round(width * factor));
  const h = Math.max(1, Math.round(height * factor));
  const scaled = frames.map((frame) => {
    const canvas = document.createElement('canvas');
    canvas.width = w;
    canvas.height = h;
    const ctx = canvas.getContext('2d');
    ctx.imageSmoothingEnabled = true;
    ctx.imageSmoothingQuality = 'high';
    ctx.drawImage(frame, 0, 0, w, h);
    return canvas;
  });
  return { frames: scaled, width: w, height: h };
}

const cache = new Map();
const pending = new Map();

/** Ersatzsprite, falls eine Datei fehlt - klar erkennbar, aber nicht stoerend. */
function fallbackSprite(src, label = '?') {
  const size = 48;
  const c = document.createElement('canvas');
  c.width = size;
  c.height = size;
  const ctx = c.getContext('2d');
  ctx.fillStyle = 'rgba(255,90,140,0.22)';
  ctx.fillRect(0, 0, size, size);
  ctx.strokeStyle = '#ff5a8c';
  ctx.lineWidth = 2;
  ctx.strokeRect(1, 1, size - 2, size - 2);
  ctx.fillStyle = '#ff9ec0';
  ctx.font = 'bold 20px system-ui, sans-serif';
  ctx.textAlign = 'center';
  ctx.textBaseline = 'middle';
  ctx.fillText(label, size / 2, size / 2);
  return new Sprite([c], [100], size, size, src);
}

const LOAD_TIMEOUT = 12000;

/** Bricht ein hängendes Laden ab, statt ewig zu warten. */
function withTimeout(promise, src, ms = LOAD_TIMEOUT) {
  return new Promise((resolve, reject) => {
    const timer = setTimeout(() => reject(new Error('Zeitüberschreitung: ' + src)), ms);
    promise.then(
      (value) => {
        clearTimeout(timer);
        resolve(value);
      },
      (error) => {
        clearTimeout(timer);
        reject(error);
      },
    );
  });
}

async function loadOne(src, maxSide = MAX_SPRITE_SIDE) {
  const isGif = /\.gif(\?|$)/i.test(src);
  if (isGif) {
    const controller = typeof AbortController === 'function' ? new AbortController() : null;
    const abort = setTimeout(() => controller && controller.abort(), LOAD_TIMEOUT);
    const res = await fetch(src, controller ? { signal: controller.signal } : undefined)
      .finally(() => clearTimeout(abort));
    if (!res.ok) throw new Error(res.status + ' ' + src);
    const gif = decodeGif(await res.arrayBuffer());
    let frames = gif.frames.map((f) => f.canvas);
    let width = gif.width;
    let height = gif.height;
    const trimmed = trimFrames(frames, width, height);
    if (trimmed) {
      frames = trimmed.frames;
      width = trimmed.width;
      height = trimmed.height;
    }
    const shrunk = shrinkFrames(frames, width, height, maxSide);
    if (shrunk) {
      frames = shrunk.frames;
      width = shrunk.width;
      height = shrunk.height;
    }
    return new Sprite(frames, gif.frames.map((f) => f.delay), width, height, src);
  }
  const img = new Image();
  img.decoding = 'async';
  await new Promise((resolve, reject) => {
    img.onload = resolve;
    img.onerror = () => reject(new Error('Bild fehlt: ' + src));
    img.src = src;
  });

  // Auch Einzelbilder werden verkleinert - eine 360er Waffengrafik, die
  // 46 px breit gezeichnet wird, muss nicht in voller Größe im Speicher
  // liegen. Nur die Karte kommt mit maxSide 0 hier durch.
  const shrunk = shrinkFrames([img], img.naturalWidth, img.naturalHeight, maxSide);
  if (shrunk) return new Sprite(shrunk.frames, [100], shrunk.width, shrunk.height, src);
  return new Sprite([img], [100], img.naturalWidth, img.naturalHeight, src);
}

/**
 * Baut ein Sprite aus mehreren Einzelbildern (bis zu fünf pro Richtung).
 * Alle Bilder werden auf die Größe des ersten gebracht, damit die
 * Animation nicht springt.
 */
async function loadSequence(paths, frameDuration) {
  const images = [];
  for (const path of paths) {
    const sprite = await Assets.load(path);
    if (sprite) images.push(sprite);
  }
  if (!images.length) return null;

  const width = images[0].width;
  const height = images[0].height;
  const frames = images.map((sprite) => {
    const frame = sprite.frames[0];
    if (sprite.width === width && sprite.height === height) return frame;
    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;
    const ctx = canvas.getContext('2d');
    ctx.imageSmoothingEnabled = false;
    // Bild mittig einpassen, ohne es zu verzerren.
    const factor = Math.min(width / sprite.width, height / sprite.height);
    const w = sprite.width * factor;
    const h = sprite.height * factor;
    ctx.drawImage(frame, (width - w) / 2, height - h, w, h);
    return canvas;
  });
  return new Sprite(frames, frames.map(() => frameDuration), width, height, paths.join('|'));
}

/** Färbt alle Frames eines Sprites um (Farbdrehung in Grad). */
function tintSprite(sprite, degrees) {
  if (!degrees) return sprite;
  const frames = sprite.frames.map((frame) => {
    const canvas = document.createElement('canvas');
    canvas.width = sprite.width;
    canvas.height = sprite.height;
    const ctx = canvas.getContext('2d');
    if ('filter' in ctx) ctx.filter = `hue-rotate(${degrees}deg) saturate(1.15)`;
    ctx.drawImage(frame, 0, 0, sprite.width, sprite.height);
    return canvas;
  });
  return new Sprite(frames, sprite.delays.slice(), sprite.width, sprite.height, sprite.src + '#tint' + degrees);
}

/**
 * Blendet die Bildränder weich aus.
 *
 * Der Staubeffekt ist ein Rechteck mit sichtbarer Kante. Ein radialer
 * Verlauf über destination-in lässt die Ränder verlaufen, sodass die
 * Wolke am Boden aufgeht statt als Kachel zu enden.
 */
function fadeEdges(sprite, softness = 0.42) {
  const frames = sprite.frames.map((frame) => {
    const w = sprite.width;
    const h = sprite.height;
    const canvas = document.createElement('canvas');
    canvas.width = w;
    canvas.height = h;
    const ctx = canvas.getContext('2d');
    ctx.drawImage(frame, 0, 0, w, h);

    ctx.globalCompositeOperation = 'destination-in';
    ctx.save();
    // Einheitskreis auf die Bildgröße strecken: die Blende passt damit
    // auch bei breiten Bildern bis in die Ecken.
    ctx.translate(w / 2, h / 2);
    ctx.scale(w / 2, h / 2);
    const gradient = ctx.createRadialGradient(0, 0, 0, 0, 0, 1);
    gradient.addColorStop(0, 'rgba(0,0,0,1)');
    gradient.addColorStop(Math.max(0, 1 - softness), 'rgba(0,0,0,1)');
    gradient.addColorStop(1, 'rgba(0,0,0,0)');
    ctx.fillStyle = gradient;
    ctx.fillRect(-1, -1, 2, 2);
    ctx.restore();
    ctx.globalCompositeOperation = 'source-over';
    return canvas;
  });
  return new Sprite(frames, sprite.delays.slice(), sprite.width, sprite.height, sprite.src + '#faded');
}

export const Assets = {
  missing: [],

  /** Lädt ein Asset genau einmal; Fehler enden in einem sichtbaren Platzhalter. */
  /**
   * @param src   Pfad zur Bilddatei
   * @param maxSide  Kantenlänge, auf die verkleinert wird.
   *                 0 heißt: volle Auflösung behalten (Karte).
   */
  async load(src, maxSide = MAX_SPRITE_SIDE) {
    if (!src) return fallbackSprite('', '?');
    // Volle Auflösung bekommt einen eigenen Platz im Zwischenspeicher,
    // sonst überschreibt die Karte das verkleinerte Sprite oder umgekehrt.
    const key = maxSide === MAX_SPRITE_SIDE ? src : src + '#max' + maxSide;
    if (cache.has(key)) return cache.get(key);
    if (pending.has(key)) return pending.get(key);

    const task = withTimeout(loadOne(src, maxSide), src)
      .catch((err) => {
        console.warn('[assets]', err.message);
        if (!Assets.missing.includes(src)) Assets.missing.push(src);
        return fallbackSprite(src, '!');
      })
      .then((sprite) => {
        cache.set(key, sprite);
        pending.delete(key);
        return sprite;
      });

    pending.set(key, task);
    return task;
  },

  /** Kantenlänge, auf die Sprites verkleinert werden. */
  get maxSide() {
    return MAX_SPRITE_SIDE;
  },

  /** Nur für Diagnose: alles, was gerade im Zwischenspeicher liegt. */
  cacheEntries() {
    return [...cache.entries()];
  },

  /**
   * Lädt die Sprites eines Charakters: je Richtung entweder ein GIF oder
   * eine Bilderfolge, dazu die getönte Fassung und der weiche Staub.
   */
  async loadCharacter(character) {
    const key = 'character:' + character.id + ':' + (character.tint || 0);
    if (cache.has(key)) return cache.get(key);

    const build = async (dir) => {
      const entry = (character.sprites && character.sprites[dir]) || {};
      let sprite = null;
      if (Array.isArray(entry.frames) && entry.frames.length) {
        sprite = await loadSequence(entry.frames, character.frameDuration || 130);
      }
      if (!sprite && entry.gif) sprite = await Assets.load(entry.gif);
      if (!sprite) sprite = await Assets.load('assets/sprites/playerfront.gif');
      return tintSprite(sprite, character.tint || 0);
    };

    const [front, back, side, dustRaw] = await Promise.all([
      build('front'), build('back'), build('side'),
      Assets.load(character.dustSprite || 'assets/sprites/staub.gif'),
    ]);
    const set = { front, back, side, dust: fadeEdges(dustRaw) };
    cache.set(key, set);
    return set;
  },

  async loadAll(list) {
    const unique = [...new Set(list.filter(Boolean))];
    await Promise.all(unique.map((src) => Assets.load(src)));
  },

  get(src) {
    return cache.get(src) || null;
  },

  /** Verwirft ein Asset, damit ein neuer Upload sofort sichtbar wird. */
  forget(src) {
    cache.delete(src);
    pending.delete(src);
  },
};
