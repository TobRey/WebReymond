/**
 * Lädt die Modelle und führt die Bildauswertung durch.
 *
 * Aufbau der Auswertung – bewusst in Stufen unterschiedlicher Kosten zerlegt,
 * weil ein Mobiltelefon sonst nach wenigen Sekunden einbricht:
 *
 *   1. Objekte      (YOLOv8, 601 Klassen)  ~alle 110 ms auf 512×512
 *   2. Gesichter    (Tiny-Detektor)        ~alle 120 ms, liefert nur Positionen
 *   3. Zweitstufe   (ImageNet, 1000 Kl.)   ein Ausschnitt alle ~260 ms
 *   4. Wer ist das? (Merkmalsvektor …)     höchstens ein Gesicht pro Runde
 *
 * Alle vier laufen auf derselben TensorFlow.js-Instanz: face-api.js bringt
 * sie mit und veröffentlicht sie als `faceapi.tf`. So gibt es im Browser einen
 * einzigen WebGL-Kontext.
 */

import { CONFIG, PATHS } from './config.js';
import { Detector } from './detector.js';
import { Classifier } from './classifier.js';

/** Lädt ein klassisches <script> und wartet, bis es ausgeführt wurde. */
function loadScript(src) {
  return new Promise((resolve, reject) => {
    const el = document.createElement('script');
    el.src = src;
    el.async = false;
    el.onload = () => resolve();
    el.onerror = () => reject(new Error(`Datei nicht gefunden: ${src}`));
    document.head.appendChild(el);
  });
}

/** Ein Arbeitsbild fester Breite, in das die Kamera verkleinert wird. */
function createWorkCanvas(width) {
  const canvas = document.createElement('canvas');
  canvas.width = width;
  canvas.height = Math.round((width * 9) / 16);
  return canvas;
}

export class Engine {
  constructor() {
    this.faceapi = null;
    this.tf = null;
    this.detector = null;
    this.classifier = null;
    this.ready = { objects: false, faces: false, classifier: false };

    this.faceCanvas = createWorkCanvas(CONFIG.faces.workWidth);
    this.cropCanvas = document.createElement('canvas');
    this.cropCanvas.width = CONFIG.faces.cropSize;
    this.cropCanvas.height = CONFIG.faces.cropSize;

    this.faceOptions = null;
    this.cropOptions = null;
  }

  /**
   * Holt die Bibliothek und schaltet TensorFlow.js auf die Grafikeinheit.
   * @param {(step: string, state: 'run'|'ok'|'fail', detail?: string) => void} report
   */
  async bootstrap(report) {
    report('Bibliothek', 'run');
    await loadScript(`${PATHS.vendor}face-api.js`);

    const faceapi = globalThis.faceapi;
    if (!faceapi?.tf) throw new Error('face-api.js konnte nicht geladen werden.');
    this.faceapi = faceapi;
    this.tf = faceapi.tf;
    // Für Werkzeuge, die ein globales `tf` erwarten.
    globalThis.tf = this.tf;
    report('Bibliothek', 'ok', `TF ${this.tf.version?.tfjs ?? this.tf.version_core ?? ''}`);

    report('Rechenwerk', 'run');
    /*
     * In dieser Reihenfolge, weil der Unterschied gewaltig ist: WebGL rechnet
     * auf der Grafikeinheit (Millisekunden), WASM auf der CPU (zehnmal
     * langsamer), reines JavaScript noch einmal um ein Vielfaches langsamer.
     */
    for (const backend of ['webgl', 'wasm', 'cpu']) {
      try {
        if (await this.tf.setBackend(backend)) {
          await this.tf.ready();
          break;
        }
      } catch {
        /* Nächsten versuchen. */
      }
    }
    await this.tf.ready();

    // Auf iOS zahlt sich das aus: kleinere Zwischenspeicher, weniger Speicherdruck.
    try {
      this.tf.env().set('WEBGL_DELETE_TEXTURE_THRESHOLD', 0);
      this.tf.env().set('WEBGL_FORCE_F16_TEXTURES', false);
    } catch {
      /* Nicht jedes Backend kennt diese Schalter. */
    }

    report('Rechenwerk', 'ok', this.tf.getBackend().toUpperCase());

    this.detector = new Detector(this.tf);
    this.classifier = new Classifier(this.tf);
  }

  /** Lädt die Gesichtsmodelle (rund 7 MB). */
  async loadFaceModels(report) {
    report('Gesichter', 'run');
    const { nets } = this.faceapi;
    const uri = PATHS.faceModels;

    await Promise.all([
      nets.tinyFaceDetector.loadFromUri(uri),
      nets.faceLandmark68Net.loadFromUri(uri),
      nets.faceRecognitionNet.loadFromUri(uri),
      nets.ageGenderNet.loadFromUri(uri),
      nets.faceExpressionNet.loadFromUri(uri),
    ]);

    this.faceOptions = new this.faceapi.TinyFaceDetectorOptions({
      inputSize: CONFIG.faces.inputSize,
      scoreThreshold: CONFIG.faces.minScore,
    });
    this.cropOptions = new this.faceapi.TinyFaceDetectorOptions({
      inputSize: CONFIG.faces.cropInputSize,
      scoreThreshold: CONFIG.faces.cropMinScore,
    });
    this.ready.faces = true;
    report('Gesichter', 'ok', '5 Modelle');
  }

  /** Lädt den Objektdetektor (601 Klassen). */
  async loadObjectModel(report) {
    await this.detector.load(report);
    this.ready.objects = true;
  }

  /** Lädt die Zweitstufe (1000 Klassen) – nach dem Detektor, nicht dringend. */
  async loadClassifier(report) {
    await this.classifier.load(report);
    this.ready.classifier = true;
  }

  /**
   * Zeichnet das Kamerabild verkleinert in ein Arbeitsbild.
   * @returns {{canvas: HTMLCanvasElement, scale: number}} Faktor zurück auf Videopixel
   */
  #prepare(canvas, video) {
    const vw = video.videoWidth;
    const vh = video.videoHeight;
    if (!vw || !vh) return null;

    const scale = canvas.width / vw;
    canvas.height = Math.max(1, Math.round(vh * scale));
    const ctx = canvas.getContext('2d', { willReadFrequently: true });
    ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
    return { canvas, scale: 1 / scale };
  }

  /**
   * Sucht Objekte im aktuellen Bild.
   * @param {HTMLVideoElement} video
   * @param {number} sure  Schwelle für einen vollen Rahmen
   * @param {boolean} includeFaint  auch unsichere Treffer liefern
   */
  async detectObjects(video, sure, includeFaint = true) {
    if (!this.ready.objects) return [];
    return this.detector.detect(video, {
      sure,
      faint: includeFaint ? CONFIG.objects.faint : sure,
      max: CONFIG.objects.maxResults,
    });
  }

  /** Zweitstufe auf einem Ziel. */
  async classifyBox(video, box) {
    if (!this.ready.classifier) return [];
    return this.classifier.classify(video, box);
  }

  /**
   * Sucht Gesichter – schnelle Stufe, nur Positionen.
   * @returns {Promise<Array<{box: object, score: number, label: string, kind: string}>>}
   */
  async detectFaces(video) {
    if (!this.ready.faces) return [];
    const prepared = this.#prepare(this.faceCanvas, video);
    if (!prepared) return [];

    const found = await this.faceapi.detectAllFaces(prepared.canvas, this.faceOptions);

    return found.map((face) => ({
      box: {
        x: face.box.x * prepared.scale,
        y: face.box.y * prepared.scale,
        w: face.box.width * prepared.scale,
        h: face.box.height * prepared.scale,
      },
      score: face.score,
      label: 'face',
      kind: 'face',
    }));
  }

  /**
   * Teure Stufe: schneidet ein Gesicht aus dem Originalbild aus und berechnet
   * Merkmalsvektor, Alter, Geschlecht und Stimmung.
   *
   * Der Ausschnitt ist der Grund, warum dieser Schritt überhaupt bezahlbar ist:
   * statt eines 1280 px breiten Bildes verarbeiten die Netze 224 px.
   */
  async identify(video, box) {
    if (!this.ready.faces) return null;

    const vw = video.videoWidth;
    const vh = video.videoHeight;
    if (!vw || !vh) return null;

    // Quadratischer Ausschnitt mit Rand – Ohren und Kinn gehören zum Merkmal.
    const margin = CONFIG.faces.cropMargin;
    const side = Math.max(box.w, box.h) * (1 + margin * 2);
    const cx = box.x + box.w / 2;
    const cy = box.y + box.h / 2;

    const sx = Math.max(0, Math.min(vw - 1, cx - side / 2));
    const sy = Math.max(0, Math.min(vh - 1, cy - side / 2));
    const sw = Math.min(side, vw - sx);
    const sh = Math.min(side, vh - sy);
    if (sw < 24 || sh < 24) return null;

    const ctx = this.cropCanvas.getContext('2d', { willReadFrequently: true });
    ctx.clearRect(0, 0, this.cropCanvas.width, this.cropCanvas.height);
    ctx.drawImage(video, sx, sy, sw, sh, 0, 0, this.cropCanvas.width, this.cropCanvas.height);

    const result = await this.faceapi
      .detectSingleFace(this.cropCanvas, this.cropOptions)
      .withFaceLandmarks()
      .withFaceExpressions()
      .withAgeAndGender()
      .withFaceDescriptor();

    if (!result?.descriptor) return null;

    const expressions = result.expressions ?? {};
    const [topExpression] = Object.entries(expressions).sort((a, b) => b[1] - a[1]);

    return {
      descriptor: result.descriptor,
      age: result.age,
      gender: result.gender,
      genderProbability: result.genderProbability,
      expression: topExpression?.[0] ?? 'neutral',
      score: result.detection?.score ?? 0,
      thumb: this.cropCanvas,
    };
  }

  /** Gibt das Vorschaubild als Data-URL zurück (für die Kartei). */
  snapshotThumb(size = 96) {
    const out = document.createElement('canvas');
    out.width = size;
    out.height = size;
    out.getContext('2d').drawImage(this.cropCanvas, 0, 0, size, size);
    return out.toDataURL('image/jpeg', 0.72);
  }

  /** Belegter Grafikspeicher – nützlich für die Diagnose. */
  memory() {
    try {
      const info = this.tf.memory();
      return { tensors: info.numTensors, mb: (info.numBytes / 1048576).toFixed(1) };
    } catch {
      return { tensors: 0, mb: '0' };
    }
  }
}
