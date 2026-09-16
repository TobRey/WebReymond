/**
 * Handzeichen: echte Fingerposen über MediaPipe.
 *
 * MediaPipe liefert je Hand 21 Landmarken (Handgelenk, je vier Punkte pro
 * Finger) und erkennt sieben Zeichen von Haus aus. Eigene Zeichen entstehen
 * aus den Landmarken: Die Hand wird auf eine Normalform gebracht – Ursprung im
 * Handgelenk, Grösse über die Handfläche, Drehung über die Mittelfinger-Achse,
 * linke Hand gespiegelt – und als Zahlenvektor mit gespeicherten Vorlagen
 * verglichen. So zählt nur die Fingerstellung, nicht wo die Hand im Bild ist
 * oder wie gross sie erscheint.
 *
 * Auslösen erst nach kurzem Halten: Ein Zeichen, das im Vorbeiwinken für
 * einen Sekundenbruchteil entsteht, darf nichts schalten. Ein Ring um die
 * Hand zeigt, wie lange noch.
 */

import { PATHS } from './config.js';

/** Deutsche Namen der eingebauten Zeichen. */
export const BUILTIN_GESTURES = {
  Closed_Fist: 'Faust',
  Open_Palm: 'Offene Hand',
  Pointing_Up: 'Zeigefinger',
  Thumb_Down: 'Daumen runter',
  Thumb_Up: 'Daumen hoch',
  Victory: 'Peace',
  ILoveYou: 'I love you',
};

/** Indizes der Landmarken. */
const WRIST = 0;
const INDEX_MCP = 5;
const MIDDLE_MCP = 9;
const PINKY_MCP = 17;
const TIPS = [4, 8, 12, 16, 20];
const PIPS = [3, 6, 10, 14, 18];

/** Ab dieser Ähnlichkeit gilt ein eigenes Zeichen als erkannt. */
const MATCH_THRESHOLD = 0.92;
/** So lange muss ein Zeichen ruhig gehalten werden (ms). */
const HOLD_MS = 800;
/** Sperre nach dem Auslösen (ms) – gegen Doppelauslösung beim Zeichenwechsel. */
const COOLDOWN_MS = 1200;

let bundle = null;

/** Lädt das MediaPipe-Bundle genau einmal. */
async function loadBundle() {
  bundle ??= await import(`../vendor/mediapipe/vision_bundle.mjs`);
  return bundle;
}

/* ==========================================================================
 * Normalform und Vergleich
 * ========================================================================== */

/** Länge des Vektors: 42 Koordinaten + 5 Finger-Flags + 2 Richtungswerte. */
export const VECTOR_LENGTH = 42 + 5 + 2;

/**
 * Bringt 21 Landmarken auf die Normalform und liefert einen Vektor.
 *
 * 42 Werte (x, y je Landmarke), 5 Flags „Finger gestreckt“ und 2 Werte für
 * die Richtung der Hand im Bild. Die Flags wiegen doppelt: Ob ein Finger oben
 * oder unten ist, unterscheidet Zeichen viel stärker als kleine
 * Lageunterschiede der Gelenke. Die Richtung ist schwach gewichtet: Eine um
 * 25° gekippte Hand bleibt dasselbe Zeichen, eine um 180° gedrehte nicht
 * (Daumen hoch ≠ Daumen runter).
 *
 * @param {Array<{x:number,y:number,z?:number}>} landmarks  0…1-Koordinaten
 * @param {'Left'|'Right'} handedness
 * @param {number} aspect  Breite/Höhe des Bildes – MediaPipe normiert x und y
 *   getrennt, wodurch die Hand im Querformat gestaucht erscheint. Mit dem
 *   Seitenverhältnis wird sie wieder isotrop, und ein im Hochformat
 *   aufgenommenes Zeichen passt auch im Querformat.
 * @returns {Float32Array}
 */
export function handVector(landmarks, handedness = 'Right', aspect = 1) {
  const pts = landmarks.map((p) => ({ x: p.x * aspect, y: p.y }));
  const wrist = pts[WRIST];
  const middle = pts[MIDDLE_MCP];
  const index = pts[INDEX_MCP];
  const pinky = pts[PINKY_MCP];

  // Grösse: Abstand Handgelenk → Mittelfinger-Grundgelenk.
  const size = Math.hypot(middle.x - wrist.x, middle.y - wrist.y) || 1e-6;
  // Drehung: Mittelfinger-Achse zeigt nach oben (negatives y).
  const heading = Math.atan2(middle.y - wrist.y, middle.x - wrist.x);
  const angle = heading + Math.PI / 2;
  const cos = Math.cos(-angle);
  const sin = Math.sin(-angle);
  // Linke Hand spiegeln, damit dieselbe Geste denselben Vektor ergibt.
  const flip = handedness === 'Left' ? -1 : 1;

  const out = new Float32Array(VECTOR_LENGTH);
  for (let i = 0; i < 21; i += 1) {
    const dx = (pts[i].x - wrist.x) / size;
    const dy = (pts[i].y - wrist.y) / size;
    out[i * 2] = (dx * cos - dy * sin) * flip;
    out[i * 2 + 1] = dx * sin + dy * cos;
  }

  // Richtung der Hand im Bild, schwach gewichtet (siehe oben).
  out[47] = Math.cos(heading) * 1.5 * flip;
  out[48] = Math.sin(heading) * 1.5;

  // Finger gestreckt: Spitze weiter vom Handgelenk entfernt als das Mittelgelenk.
  const palm = Math.hypot(index.x - pinky.x, index.y - pinky.y) || 1e-6;
  for (let f = 0; f < 5; f += 1) {
    const tip = pts[TIPS[f]];
    const pip = pts[PIPS[f]];
    const tipDistance = Math.hypot(tip.x - wrist.x, tip.y - wrist.y);
    const pipDistance = Math.hypot(pip.x - wrist.x, pip.y - wrist.y);
    // Daumen: seitlicher Abstand zur Handfläche statt Länge.
    const extended =
      f === 0
        ? Math.hypot(tip.x - index.x, tip.y - index.y) / palm > 0.75
        : tipDistance > pipDistance * 1.08;
    out[42 + f] = extended ? 2 : -2;
  }

  return out;
}

/** Kosinus-Ähnlichkeit zweier Vektoren, 0 … 1. */
export function similarity(a, b) {
  let dot = 0;
  let na = 0;
  let nb = 0;
  for (let i = 0; i < a.length; i += 1) {
    dot += a[i] * b[i];
    na += a[i] * a[i];
    nb += b[i] * b[i];
  }
  return na && nb ? Math.max(0, dot / Math.sqrt(na * nb)) : 0;
}

/** Mittelwert mehrerer Vektoren – die Vorlage eines eigenen Zeichens. */
export function averageVector(vectors) {
  const out = new Float32Array(vectors[0].length);
  for (const vector of vectors) {
    for (let i = 0; i < out.length; i += 1) out[i] += vector[i] / vectors.length;
  }
  return out;
}

/* ==========================================================================
 * Erkennung
 * ========================================================================== */

export class Hands {
  constructor() {
    this.recognizer = null;
    this.ready = false;
    this.loading = null;
    /** Eigene Zeichen: { id, name, vector, samples } */
    this.custom = [];
    /** Zustand des Haltens. */
    this.current = null;
    this.heldSince = 0;
    this.lastFired = 0;
    this.lastTimestamp = 0;
    /** Aufnahme eines neuen Zeichens. */
    this.recording = null;
  }

  /** Lädt WASM und Modell. Dauert beim ersten Mal zwei bis vier Sekunden. */
  async prepare() {
    if (this.ready) return;
    if (this.loading) return this.loading;

    this.loading = (async () => {
      const { FilesetResolver, GestureRecognizer } = await loadBundle();
      const vision = await FilesetResolver.forVisionTasks(PATHS.mediapipe.replace(/\/$/, ''));
      try {
        this.recognizer = await GestureRecognizer.createFromOptions(vision, {
          baseOptions: { modelAssetPath: PATHS.gestureModel, delegate: 'GPU' },
          runningMode: 'VIDEO',
          numHands: 1,
          minHandDetectionConfidence: 0.55,
          minHandPresenceConfidence: 0.55,
          minTrackingConfidence: 0.5,
        });
      } catch {
        // Ohne WebGL-Delegat auf die CPU – langsamer, aber es läuft.
        this.recognizer = await GestureRecognizer.createFromOptions(vision, {
          baseOptions: { modelAssetPath: PATHS.gestureModel, delegate: 'CPU' },
          runningMode: 'VIDEO',
          numHands: 1,
        });
      }
      this.ready = true;
    })();

    try {
      await this.loading;
    } finally {
      this.loading = null;
    }
    return undefined;
  }

  setCustom(list) {
    // Vorlagen aus einer älteren Fassung (kürzerer Vektor) werden verworfen –
    // sie wären ohnehin nicht mehr vergleichbar.
    this.custom = list
      .filter((entry) => Array.isArray(entry.vector) && entry.vector.length === VECTOR_LENGTH)
      .map((entry) => ({ ...entry, vector: Float32Array.from(entry.vector) }));
  }

  /**
   * Untersucht ein Einzelbild.
   *
   * @returns {{hand: object|null, fired: object|null, recorded: object|null}}
   *   hand     – für das HUD: Landmarken in Videopixeln, Zeichen, Haltefortschritt
   *   fired    – ausgelöstes Zeichen { id, name, custom }
   *   recorded – fertige Aufnahme eines neuen Zeichens { vector, samples }
   */
  update(video, now) {
    if (!this.ready || !video.videoWidth) return { hand: null, fired: null, recorded: null };

    // MediaPipe verlangt streng steigende Zeitstempel.
    const stamp = Math.max(this.lastTimestamp + 1, Math.round(now));
    this.lastTimestamp = stamp;

    let result;
    try {
      result = this.recognizer.recognizeForVideo(video, stamp);
    } catch {
      return { hand: null, fired: null, recorded: null };
    }

    const landmarks = result.landmarks?.[0];
    if (!landmarks) {
      this.#release();
      return { hand: null, fired: null, recorded: null };
    }

    const handedness = result.handednesses?.[0]?.[0]?.categoryName ?? 'Right';
    const vector = handVector(landmarks, handedness, video.videoWidth / video.videoHeight);

    // --- Aufnahme eines neuen Zeichens ---
    let recorded = null;
    if (this.recording) {
      const rec = this.recording;
      if (!rec.startedAt) rec.startedAt = now;
      rec.samples.push(vector);
      const elapsed = now - rec.startedAt;
      // Fertig nach `wanted` Bildern – oder nach `minMs` mit wenigstens
      // `minFrames` Bildern, falls das Gerät nur wenige Bilder pro Sekunde schafft.
      const byTime = Math.min(elapsed / rec.minMs, rec.samples.length / rec.minFrames);
      rec.progress = Math.min(1, Math.max(rec.samples.length / rec.wanted, byTime));
      const enough =
        rec.samples.length >= rec.wanted ||
        (rec.samples.length >= rec.minFrames && elapsed >= rec.minMs);
      if (enough) {
        recorded = {
          vector: Array.from(averageVector(rec.samples)),
          samples: rec.samples.length,
        };
        this.recording = null;
      }
    }

    // --- Zeichen bestimmen: eigene zuerst, sonst eingebaute ---
    let gesture = null;
    let best = 0;
    for (const entry of this.custom) {
      const score = similarity(vector, entry.vector);
      if (score > best) {
        best = score;
        gesture = { id: entry.id, name: entry.name, custom: true, score };
      }
    }
    if (!gesture || best < MATCH_THRESHOLD) {
      const builtin = result.gestures?.[0]?.[0];
      if (builtin && builtin.categoryName !== 'None' && builtin.score >= 0.55) {
        gesture = {
          id: builtin.categoryName,
          name: BUILTIN_GESTURES[builtin.categoryName] ?? builtin.categoryName,
          custom: false,
          score: builtin.score,
        };
      } else {
        gesture = null;
      }
    }

    // --- Halten und Auslösen ---
    // Ein Zeichen löst je Halten genau einmal aus. Wer die Hand oben lässt,
    // schaltet nicht alle zwei Sekunden erneut – erst loslassen oder das
    // Zeichen wechseln, dann wieder halten.
    let fired = null;
    let hold = 0;
    if (gesture && !this.recording) {
      if (this.current?.id !== gesture.id) {
        this.current = { ...gesture, fired: false };
        this.heldSince = now;
      }
      hold = Math.min(1, (now - this.heldSince) / HOLD_MS);
      if (hold >= 1 && !this.current.fired && now - this.lastFired > COOLDOWN_MS) {
        this.lastFired = now;
        this.current.fired = true;
        fired = gesture;
      }
    } else {
      this.#release();
    }

    const hand = {
      landmarks: landmarks.map((p) => ({ x: p.x * video.videoWidth, y: p.y * video.videoHeight })),
      gesture: this.recording ? 'Aufnahme …' : (gesture?.name ?? null),
      custom: gesture?.custom ?? false,
      hold: this.recording ? this.recording.progress : hold,
      handedness,
    };

    return { hand, fired, recorded };
  }

  #release() {
    this.current = null;
    this.heldSince = 0;
  }

  /**
   * Beginnt die Aufnahme eines neuen Zeichens: etwa 2 s, in denen die Hand
   * ruhig gehalten wird. Gemittelt werden bis zu `frames` Bilder.
   */
  startRecording(frames = 22, { minFrames = 8, minMs = 1500 } = {}) {
    this.recording = { samples: [], wanted: frames, minFrames, minMs, startedAt: 0, progress: 0 };
  }

  cancelRecording() {
    this.recording = null;
  }

  get isRecording() {
    return Boolean(this.recording);
  }

  async dispose() {
    try {
      this.recognizer?.close();
    } catch {
      /* egal */
    }
    this.recognizer = null;
    this.ready = false;
  }
}
