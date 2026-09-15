/**
 * Gestensteuerung über Bildbewegung.
 *
 * WARUM KEINE FINGERERKENNUNG: Die üblichen Handmodelle (MediaPipe Hands,
 * TensorFlow handpose) liegen auf tfhub.dev und lassen sich nicht in die
 * Auslieferung übernehmen. Ohne Modell bleibt die Bildbewegung – und die
 * reicht für die Gesten, die man einhändig vor einer Handykamera überhaupt
 * sinnvoll macht: wischen, winken, die Kamera kurz abdecken.
 *
 * Verfahren: Das Kamerabild wird auf 48×36 Graustufen verkleinert und mit dem
 * vorigen Bild verglichen. Aus den Pixeln, die sich geändert haben, entsteht
 * ein Schwerpunkt. Wandert dieser Schwerpunkt schnell und weit genug in eine
 * Richtung, ist das ein Wisch.
 *
 * Das ist absichtlich grob: Es kostet praktisch nichts, läuft auf jedem Gerät
 * und verwechselt nichts mit einem Gesicht.
 */

const W = 48;
const H = 36;

/** Anteil geänderter Pixel, ab dem überhaupt von Bewegung die Rede ist. */
const MOVE_THRESHOLD = 0.035;
/**
 * Abgedeckt wird am *Zustand* des Bildes erkannt, nicht an der Änderung:
 * dunkel (Hand auf der Linse) oder völlig strukturlos.
 *
 * Über die Änderungsrate ginge es nicht. Eine abgedeckte Linse liefert nach
 * dem ersten Moment ein völlig unbewegtes Bild – die Änderungsrate fällt also
 * sofort wieder auf null, und der Zustand "abgedeckt" wäre nach einem
 * einzigen Bild schon wieder vorbei.
 */
const COVER_BRIGHTNESS = 30;
const COVER_VARIANCE = 45;
/** Strecke in Bildbreiten, die ein Wisch zurücklegen muss. */
const SWIPE_DISTANCE = 0.38;
/** Längste Dauer eines Wischs in Millisekunden. */
const SWIPE_TIME = 900;

export const GESTURES = [
  { id: 'swipeLeft', name: 'Wischen nach links' },
  { id: 'swipeRight', name: 'Wischen nach rechts' },
  { id: 'swipeUp', name: 'Wischen nach oben' },
  { id: 'swipeDown', name: 'Wischen nach unten' },
  { id: 'cover', name: 'Kamera kurz abdecken' },
  { id: 'wave', name: 'Winken' },
];

/** Aktionen, die sich auf eine Geste legen lassen. */
export const GESTURE_ACTIONS = [
  { id: 'none', name: 'nichts' },
  { id: 'scan', name: 'Umgebung scannen' },
  { id: 'readText', name: 'Text vorlesen' },
  { id: 'translate', name: 'Text übersetzen' },
  { id: 'registerFace', name: 'Gesicht erfassen' },
  { id: 'toggleHud', name: 'Anzeige ein/aus' },
  { id: 'flipCamera', name: 'Kamera wechseln' },
  { id: 'describe', name: 'Umgebung beschreiben' },
  { id: 'listen', name: 'ReyRey zuhören lassen' },
  { id: 'silence', name: 'ReyRey verstummen lassen' },
];

export const DEFAULT_BINDINGS = {
  swipeLeft: 'toggleHud',
  swipeRight: 'flipCamera',
  swipeUp: 'scan',
  swipeDown: 'readText',
  cover: 'silence',
  wave: 'listen',
};

export class GestureWatcher {
  constructor() {
    this.canvas = document.createElement('canvas');
    this.canvas.width = W;
    this.canvas.height = H;
    this.ctx = this.canvas.getContext('2d', { willReadFrequently: true });

    this.previous = null;
    /** Schwerpunkte der letzten Bewegungen. */
    this.trail = [];
    this.lastFired = 0;
    this.covering = false;
    this.coverStart = 0;
    /** Richtungswechsel für das Erkennen von Winken. */
    this.flips = 0;
    this.lastDirection = 0;
    this.lastFlipAt = 0;
  }

  reset() {
    this.previous = null;
    this.trail = [];
    this.flips = 0;
  }

  /**
   * Untersucht ein Einzelbild.
   * @returns {string|null} Kennung der erkannten Geste
   */
  update(video, now) {
    if (!video.videoWidth) return null;
    // Nach einer Geste kurz nichts annehmen, sonst löst eine Bewegung doppelt aus.
    if (now - this.lastFired < 1200) return null;

    this.ctx.drawImage(video, 0, 0, W, H);
    const frame = this.ctx.getImageData(0, 0, W, H).data;

    const grey = new Uint8Array(W * H);
    for (let i = 0, p = 0; i < frame.length; i += 4, p += 1) {
      // Ganzzahlige Helligkeit – schneller als die Gleitkommaformel.
      grey[p] = (frame[i] * 77 + frame[i + 1] * 151 + frame[i + 2] * 28) >> 8;
    }

    if (!this.previous) {
      this.previous = grey;
      return null;
    }

    let changed = 0;
    let sumX = 0;
    let sumY = 0;
    let sum = 0;
    let sumSquares = 0;

    for (let y = 0; y < H; y += 1) {
      for (let x = 0; x < W; x += 1) {
        const index = y * W + x;
        const value = grey[index];
        sum += value;
        sumSquares += value * value;
        if (Math.abs(value - this.previous[index]) > 26) {
          changed += 1;
          sumX += x;
          sumY += y;
        }
      }
    }
    this.previous = grey;

    const pixels = W * H;
    const ratio = changed / pixels;
    const mean = sum / pixels;
    const variance = sumSquares / pixels - mean * mean;

    // --- Abdecken: dunkel oder strukturlos, und zwar durchgehend ---
    if (mean < COVER_BRIGHTNESS || variance < COVER_VARIANCE) {
      if (!this.covering) {
        this.covering = true;
        this.coverStart = now;
      }
      this.trail = [];
      return null;
    }
    if (this.covering) {
      this.covering = false;
      const held = now - this.coverStart;
      // Zu kurz war ein Schatten, zu lang ist die Kamera in der Tasche.
      if (held > 220 && held < 2600) return this.#fire('cover', now);
      return null;
    }

    if (ratio < MOVE_THRESHOLD) {
      // Ruhe: Verlauf altern lassen, damit kein Wisch über Minuten entsteht.
      if (this.trail.length > 0 && now - this.trail[this.trail.length - 1].t > 400) {
        this.trail = [];
        this.flips = 0;
      }
      return null;
    }

    const point = { x: sumX / changed / W, y: sumY / changed / H, t: now };
    this.trail.push(point);
    if (this.trail.length > 14) this.trail.shift();

    // --- Winken: mehrfacher Richtungswechsel auf der Stelle ---
    if (this.trail.length >= 3) {
      const previousPoint = this.trail[this.trail.length - 2];
      const direction = Math.sign(point.x - previousPoint.x);
      if (direction !== 0 && this.lastDirection !== 0 && direction !== this.lastDirection) {
        if (now - this.lastFlipAt < 700) this.flips += 1;
        else this.flips = 1;
        this.lastFlipAt = now;
      }
      if (direction !== 0) this.lastDirection = direction;
      if (this.flips >= 3) {
        this.flips = 0;
        return this.#fire('wave', now);
      }
    }

    // --- Wisch: weite Strecke in kurzer Zeit ---
    const start = this.trail[0];
    const span = now - start.t;
    if (span > SWIPE_TIME) {
      this.trail.shift();
      return null;
    }

    const dx = point.x - start.x;
    const dy = point.y - start.y;

    if (Math.abs(dx) > SWIPE_DISTANCE && Math.abs(dx) > Math.abs(dy) * 1.6) {
      return this.#fire(dx > 0 ? 'swipeRight' : 'swipeLeft', now);
    }
    if (Math.abs(dy) > SWIPE_DISTANCE && Math.abs(dy) > Math.abs(dx) * 1.6) {
      return this.#fire(dy > 0 ? 'swipeDown' : 'swipeUp', now);
    }

    return null;
  }

  #fire(id, now) {
    this.lastFired = now;
    this.trail = [];
    this.flips = 0;
    return id;
  }
}
