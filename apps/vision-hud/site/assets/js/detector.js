/**
 * Objektdetektor: YOLOv8-nano, trainiert auf Open Images (601 Klassen).
 *
 * Aufbau in drei Schritten:
 *
 *   1. Letterbox    Das Kamerabild wird verkleinert und in ein 512×512-Quadrat
 *                   gelegt, Ränder grau. Seitenverhältnis bleibt erhalten;
 *                   sonst würden Objekte gestaucht und schlechter erkannt.
 *   2. Netz         Ausgabe [605 × 5376]: für 5376 Kandidatenboxen je vier
 *                   Koordinaten (Mitte, Breite, Höhe) und 601 Klassenwerte.
 *   3. Aufräumen    Beste Klasse je Box, Schwelle, Unterdrückung überlappender
 *                   Boxen (NMS), Umrechnung zurück in Videopixel.
 *
 * Zwei Schwellen: ab `sure` ein voller Rahmen mit Name, zwischen `faint` und
 * `sure` ein blasser „?“-Rahmen. Das HUD zeigt so auch, was das Netz nur
 * ahnt – statt es zu verschweigen.
 *
 * Die eigentliche Rechnung läuft über einen austauschbaren „Runner“: TF.js
 * (bevorzugt, teilt sich die WebGL-Instanz mit face-api) oder ONNX Runtime
 * (Rückfall). Vor- und Nachverarbeitung sind für beide gleich.
 */

import { CONFIG, PATHS } from './config.js';
import { oiv7 } from './labels-oiv7.js';

const INPUT = 512;
const CLASSES = 601;
const PAD = 114;

/* ==========================================================================
 * Runner
 * ========================================================================== */

/**
 * Führt das Netz mit TensorFlow.js aus.
 *
 * Das Modell kann in zwei Anordnungen vorliegen – Kanäle vorn (NCHW, wie in
 * PyTorch) oder hinten (NHWC, wie nach der Umwandlung über TensorFlow). Beides
 * wird beim Laden an der Eingabeform erkannt.
 */
export class TfjsRunner {
  constructor(tf, url) {
    this.tf = tf;
    this.url = url;
    this.model = null;
    this.channelsFirst = false;
    this.outputChannelsFirst = true;
    this.name = 'tfjs';
  }

  async load() {
    this.model = await this.tf.loadGraphModel(this.url);
    const shape = this.model.inputs?.[0]?.shape ?? [];
    this.channelsFirst = shape[1] === 3;

    const out = this.model.outputs?.[0]?.shape ?? [];
    // [1, 605, 5376] → Kanäle vorn; [1, 5376, 605] → Kanäle hinten.
    this.outputChannelsFirst = !(out.length === 3 && out[2] > out[1]);
  }

  /**
   * @param {HTMLCanvasElement} canvas  quadratisch, Eingabegrösse
   * @returns {Promise<{boxes: Float32Array, best: Float32Array, cls: Int32Array, count: number}>}
   */
  async detect(canvas) {
    const tf = this.tf;
    const parts = tf.tidy(() => {
      let input = tf.browser.fromPixels(canvas).toFloat().div(255);
      if (this.channelsFirst) input = input.transpose([2, 0, 1]);
      input = input.expandDims(0);

      let out = this.model.execute(input);
      if (Array.isArray(out)) out = out[0];
      let grid = out.squeeze([0]);
      if (this.outputChannelsFirst) grid = grid.transpose(); // → [N, 605]

      const boxes = grid.slice([0, 0], [-1, 4]);
      const scores = grid.slice([0, 4], [-1, -1]);
      return { boxes, best: scores.max(1), cls: scores.argMax(1, 'int32') };
    });

    try {
      const [boxes, best, cls] = await Promise.all([
        parts.boxes.data(),
        parts.best.data(),
        parts.cls.data(),
      ]);
      return { boxes, best, cls: Int32Array.from(cls), count: best.length };
    } finally {
      parts.boxes.dispose();
      parts.best.dispose();
      parts.cls.dispose();
    }
  }

  /**
   * Klassifikation: Ausgabe [1, 1000] Wahrscheinlichkeiten.
   * @returns {Promise<Float32Array>}
   */
  async classify(canvas) {
    const tf = this.tf;
    const out = tf.tidy(() => {
      let input = tf.browser.fromPixels(canvas).toFloat().div(255);
      if (this.channelsFirst) input = input.transpose([2, 0, 1]);
      let result = this.model.execute(input.expandDims(0));
      if (Array.isArray(result)) result = result[0];
      return result.squeeze();
    });
    try {
      return await out.data();
    } finally {
      out.dispose();
    }
  }

  dispose() {
    this.model?.dispose();
    this.model = null;
  }
}

/**
 * Rückfall: ONNX Runtime Web. Wird nur benutzt, wenn kein TF.js-Modell
 * vorliegt. Braucht assets/vendor/ort/ (ort.min.js und die WASM-Datei).
 */
export class OrtRunner {
  constructor(url, inputSize) {
    this.url = url;
    this.inputSize = inputSize;
    this.session = null;
    this.name = 'ort';
  }

  async load() {
    if (!globalThis.ort) {
      await new Promise((resolve, reject) => {
        const el = document.createElement('script');
        el.src = `${PATHS.ort}ort.min.js`;
        el.onload = resolve;
        el.onerror = () => reject(new Error('ONNX Runtime konnte nicht geladen werden'));
        document.head.appendChild(el);
      });
    }
    const ort = globalThis.ort;
    ort.env.wasm.wasmPaths = PATHS.ort;
    ort.env.wasm.numThreads = Math.min(4, navigator.hardwareConcurrency ?? 2);
    this.session = await ort.InferenceSession.create(this.url, {
      executionProviders: ['webgpu', 'wasm'],
      graphOptimizationLevel: 'all',
    });
    this.inputName = this.session.inputNames[0];
    this.outputName = this.session.outputNames[0];
  }

  #tensor(canvas) {
    const size = this.inputSize;
    const { data } = canvas
      .getContext('2d', { willReadFrequently: true })
      .getImageData(0, 0, size, size);
    const plane = size * size;
    const input = new Float32Array(plane * 3);
    for (let i = 0, p = 0; p < plane; i += 4, p += 1) {
      input[p] = data[i] / 255;
      input[plane + p] = data[i + 1] / 255;
      input[plane * 2 + p] = data[i + 2] / 255;
    }
    return new globalThis.ort.Tensor('float32', input, [1, 3, size, size]);
  }

  async detect(canvas) {
    const result = await this.session.run({ [this.inputName]: this.#tensor(canvas) });
    const out = result[this.outputName];
    const [, rows, count] = out.dims; // [1, 605, N]
    const values = out.data;

    const boxes = new Float32Array(count * 4);
    const best = new Float32Array(count);
    const cls = new Int32Array(count);

    for (let n = 0; n < count; n += 1) {
      for (let k = 0; k < 4; k += 1) boxes[n * 4 + k] = values[k * count + n];
      let top = 0;
      let topIndex = 0;
      for (let c = 4; c < rows; c += 1) {
        const v = values[c * count + n];
        if (v > top) {
          top = v;
          topIndex = c - 4;
        }
      }
      best[n] = top;
      cls[n] = topIndex;
    }
    return { boxes, best, cls, count };
  }

  async classify(canvas) {
    const result = await this.session.run({ [this.inputName]: this.#tensor(canvas) });
    return Float32Array.from(result[this.outputName].data);
  }

  dispose() {
    this.session?.release?.();
    this.session = null;
  }
}

/* ==========================================================================
 * Detektor
 * ========================================================================== */

export class Detector {
  /**
   * @param {object} tf  TensorFlow.js-Instanz (faceapi.tf)
   */
  constructor(tf) {
    this.tf = tf;
    this.runner = null;
    this.ready = false;
    this.canvas = document.createElement('canvas');
    this.canvas.width = INPUT;
    this.canvas.height = INPUT;
    this.ctx = this.canvas.getContext('2d', { willReadFrequently: true });
    /** Letzte Letterbox-Umrechnung. */
    this.fit = { scale: 1, dx: 0, dy: 0 };
    this.lastMs = 0;
  }

  /**
   * Lädt das Modell – zuerst TF.js, sonst ONNX.
   * @param {(step: string, state: string, detail?: string) => void} report
   */
  async load(report) {
    report?.('Objekte', 'run');
    const tfjsUrl = `${PATHS.detector}model.json`;

    try {
      const probe = await fetch(tfjsUrl, { method: 'HEAD', cache: 'force-cache' });
      if (!probe.ok) throw new Error(`HTTP ${probe.status}`);
      this.runner = new TfjsRunner(this.tf, tfjsUrl);
      await this.runner.load();
    } catch (error) {
      // Kein TF.js-Modell da (oder kaputt) → ONNX versuchen.
      console.warn('Detektor: TF.js nicht ladbar, versuche ONNX –', error.message);
      this.runner = new OrtRunner(`${PATHS.detector}model.onnx`, INPUT);
      await this.runner.load();
    }

    // Aufwärmen: Der erste Lauf kompiliert die Shader und dauert Sekunden.
    this.ctx.fillStyle = `rgb(${PAD},${PAD},${PAD})`;
    this.ctx.fillRect(0, 0, INPUT, INPUT);
    await this.runner.detect(this.canvas);

    this.ready = true;
    report?.('Objekte', 'ok', `601 Klassen · ${this.runner.name}`);
  }

  /** Legt das Kamerabild seitenrichtig ins Quadrat. */
  #letterbox(video) {
    const vw = video.videoWidth;
    const vh = video.videoHeight;
    const scale = Math.min(INPUT / vw, INPUT / vh);
    const w = Math.round(vw * scale);
    const h = Math.round(vh * scale);
    const dx = Math.floor((INPUT - w) / 2);
    const dy = Math.floor((INPUT - h) / 2);

    this.ctx.fillStyle = `rgb(${PAD},${PAD},${PAD})`;
    this.ctx.fillRect(0, 0, INPUT, INPUT);
    this.ctx.drawImage(video, 0, 0, vw, vh, dx, dy, w, h);
    this.fit = { scale, dx, dy };
  }

  /**
   * Findet Objekte im aktuellen Kamerabild.
   *
   * @param {HTMLVideoElement} video
   * @param {{sure: number, faint: number, max: number}} thresholds
   * @returns {Promise<Array<{box, score, label, labelDe, group, kind, faint, generic}>>}
   *          Koordinaten in Videopixeln
   */
  async detect(video, thresholds) {
    if (!this.ready || !video.videoWidth) return [];
    const started = performance.now();

    this.#letterbox(video);
    const { boxes, best, cls, count } = await this.runner.detect(this.canvas);

    // Kandidaten über der unteren Schwelle einsammeln.
    const candidates = [];
    for (let n = 0; n < count; n += 1) {
      const score = best[n];
      if (score < thresholds.faint) continue;
      const entry = oiv7Index(cls[n]);
      if (entry.hidden) continue;

      const cx = boxes[n * 4];
      const cy = boxes[n * 4 + 1];
      const w = boxes[n * 4 + 2];
      const h = boxes[n * 4 + 3];
      candidates.push({
        x1: cx - w / 2,
        y1: cy - h / 2,
        x2: cx + w / 2,
        y2: cy + h / 2,
        score,
        classIndex: cls[n],
      });
    }

    // Beste zuerst, dann überlappende Boxen unterdrücken.
    candidates.sort((a, b) => b.score - a.score);
    const kept = nms(candidates.slice(0, 600), 0.55, thresholds.max);

    const { scale, dx, dy } = this.fit;
    const vw = video.videoWidth;
    const vh = video.videoHeight;

    const results = kept.map((c) => {
      const entry = oiv7Index(c.classIndex);
      const x = Math.max(0, (c.x1 - dx) / scale);
      const y = Math.max(0, (c.y1 - dy) / scale);
      const x2 = Math.min(vw, (c.x2 - dx) / scale);
      const y2 = Math.min(vh, (c.y2 - dy) / scale);
      return {
        box: { x, y, w: Math.max(1, x2 - x), h: Math.max(1, y2 - y) },
        score: c.score,
        label: entry.id,
        labelDe: entry.de,
        group: entry.group,
        kind: entry.person ? 'person' : 'object',
        faint: c.score < thresholds.sure,
        generic: entry.generic,
      };
    });

    this.lastMs = performance.now() - started;
    return results.filter((r) => r.box.w > 6 && r.box.h > 6);
  }

  dispose() {
    this.runner?.dispose();
    this.runner = null;
    this.ready = false;
  }
}

/* ==========================================================================
 * Hilfen
 * ========================================================================== */

import { OIV7 } from './labels-oiv7.js';

/** Klasse zu einem Ausgabeindex – mit Rückfall, falls die Tabelle nicht passt. */
function oiv7Index(index) {
  return OIV7[index] ?? oiv7(`Klasse ${index}`);
}

/**
 * Non-Maximum-Suppression, klassenunabhängig.
 *
 * Klassenunabhängig ist hier gewollt: Das HUD braucht eine Box je Gegenstand,
 * nicht „Karton“ und „Behälter“ übereinander.
 */
export function nms(sorted, iouLimit, maxOut) {
  const kept = [];
  for (const c of sorted) {
    if (kept.length >= maxOut) break;
    let overlaps = false;
    for (const k of kept) {
      if (iou(c, k) > iouLimit) {
        overlaps = true;
        break;
      }
    }
    if (!overlaps) kept.push(c);
  }
  return kept;
}

function iou(a, b) {
  const left = Math.max(a.x1, b.x1);
  const top = Math.max(a.y1, b.y1);
  const right = Math.min(a.x2, b.x2);
  const bottom = Math.min(a.y2, b.y2);
  const overlap = Math.max(0, right - left) * Math.max(0, bottom - top);
  if (overlap === 0) return 0;
  const union = (a.x2 - a.x1) * (a.y2 - a.y1) + (b.x2 - b.x1) * (b.y2 - b.y1) - overlap;
  return union > 0 ? overlap / union : 0;
}

export const DETECTOR_INPUT = INPUT;
export const DETECTOR_CLASSES = CLASSES;
export { CONFIG as DETECTOR_CONFIG };
