/**
 * Was kann dieses Gerät?
 *
 * Diese Fragen werden einmal beantwortet und überall wiederverwendet.
 * Sie entscheiden, ob die 3D-Szene überhaupt geladen wird – das ist der
 * wichtigste Hebel für die Ladezeit auf schwächeren Geräten.
 */

const reducedMotionQuery = window.matchMedia('(prefers-reduced-motion: reduce)');

export const env = {
  /** Nutzer hat im Betriebssystem "Bewegung reduzieren" eingeschaltet. */
  get reducedMotion() {
    return reducedMotionQuery.matches;
  },

  /** Echte Maus vorhanden (kein Finger). */
  get hasPointer() {
    return window.matchMedia('(hover: hover) and (pointer: fine)').matches;
  },

  /** Datensparmodus im Browser aktiv. */
  get saveData() {
    return navigator.connection?.saveData === true;
  },

  /** Langsame Verbindung. */
  get slowNetwork() {
    const type = navigator.connection?.effectiveType ?? '';
    return type === 'slow-2g' || type === '2g';
  },

  /**
   * Sehr schwaches Gerät.
   *
   * Die Grenze liegt bewusst tief: ein Notebook mit vier Kernen stellt die
   * Szene mühelos dar. Ausgeschlossen werden nur wirklich knappe Geräte,
   * bei denen die Bildrate spürbar einbrechen würde.
   */
  get weakDevice() {
    const memory = navigator.deviceMemory ?? 8;
    const cores = navigator.hardwareConcurrency ?? 8;
    return memory <= 2 || cores <= 2;
  },

  /** Bildschirm gross genug, dass sich die Szene lohnt. */
  get bigEnough() {
    return window.innerWidth >= 768;
  },

  /** Unterstützt der Browser WebGL2? */
  get webgl() {
    try {
      const canvas = document.createElement('canvas');
      return Boolean(canvas.getContext('webgl2'));
    } catch {
      return false;
    }
  },

  /**
   * Die eine Entscheidung: 3D laden oder nicht.
   *
   * Fällt sie negativ aus, übernimmt der CSS-Verlauf im Hintergrund.
   * Der sieht bewusst gewollt aus und nicht wie ein Fehler.
   */
  get wants3D() {
    return (
      this.webgl &&
      !this.reducedMotion &&
      !this.saveData &&
      !this.slowNetwork &&
      !this.weakDevice &&
      this.bigEnough
    );
  },
};

/** Auf Änderungen der Bewegungseinstellung reagieren, ohne neu zu laden. */
export function onReducedMotionChange(callback) {
  const handler = (event) => callback(event.matches);
  reducedMotionQuery.addEventListener('change', handler);
  return () => reducedMotionQuery.removeEventListener('change', handler);
}

/** Wert in einen Bereich zwingen. */
export const clamp = (value, min, max) => Math.min(Math.max(value, min), max);

/** Sanft von a nach b – der Kern jeder weichen Bewegung. */
export const lerp = (a, b, t) => a + (b - a) * t;

/**
 * Rahmengenauer Takt, an dem sich alle Animationen anhängen.
 * Ein einziger requestAnimationFrame für die ganze Seite statt zehn –
 * das ist spürbar sparsamer.
 */
const tasks = new Set();
let running = false;

function frame(time) {
  for (const task of tasks) {
    task(time);
  }
  if (tasks.size > 0) {
    requestAnimationFrame(frame);
  } else {
    running = false;
  }
}

export function onFrame(task) {
  tasks.add(task);
  if (!running) {
    running = true;
    requestAnimationFrame(frame);
  }
  return () => tasks.delete(task);
}
