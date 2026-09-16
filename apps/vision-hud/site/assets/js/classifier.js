/**
 * Zweitstufe: ImageNet-Klassifikator (1000 Klassen) auf Bildausschnitten.
 *
 * Der Detektor sagt „Karton“ oder „Haushaltsgerät“; die Zweitstufe sieht sich
 * genau diesen Ausschnitt an und sagt „Paket“ oder „Ventilator“. Sie läuft
 * nicht auf dem ganzen Bild, sondern nur auf Ausschnitten einzelner Ziele –
 * und nur ein paar Mal pro Sekunde. Das Ergebnis wird am Ziel gemerkt.
 *
 * Wann der feinere Name den Detektornamen ersetzt, entscheidet `refine()`:
 * nur bei allgemeinen Detektorklassen und nur, wenn die Zweitstufe sicher
 * genug ist. Ein „Stuhl“ bleibt ein Stuhl, auch wenn ImageNet „Klappstuhl“
 * dazu sagt – das wäre keine Verbesserung, nur ein Wechsel.
 */

import { PATHS } from './config.js';
import { IMAGENET, refinementAllowed } from './labels-imagenet.js';
import { TfjsRunner, OrtRunner } from './detector.js';

const INPUT = 224;

export class Classifier {
  constructor(tf) {
    this.tf = tf;
    this.runner = null;
    this.ready = false;
    this.canvas = document.createElement('canvas');
    this.canvas.width = INPUT;
    this.canvas.height = INPUT;
    this.ctx = this.canvas.getContext('2d', { willReadFrequently: true });
    this.busy = false;
  }

  async load(report) {
    report?.('Zweitstufe', 'run');
    const tfjsUrl = `${PATHS.classifier}model.json`;
    try {
      const probe = await fetch(tfjsUrl, { method: 'HEAD', cache: 'force-cache' });
      if (!probe.ok) throw new Error(`HTTP ${probe.status}`);
      this.runner = new TfjsRunner(this.tf, tfjsUrl);
      await this.runner.load();
    } catch (error) {
      console.warn('Zweitstufe: TF.js nicht ladbar, versuche ONNX –', error.message);
      this.runner = new OrtRunner(`${PATHS.classifier}model.onnx`, INPUT);
      await this.runner.load();
    }

    this.ctx.fillStyle = '#777';
    this.ctx.fillRect(0, 0, INPUT, INPUT);
    await this.runner.classify(this.canvas);

    this.ready = true;
    report?.('Zweitstufe', 'ok', `1000 Klassen · ${this.runner.name}`);
  }

  /**
   * Bestimmt den Inhalt eines Ausschnitts.
   *
   * Der Ausschnitt wird quadratisch um die Box gelegt, mit etwas Rand: Der
   * Klassifikator wurde auf ganze Objekte trainiert, nicht auf angeschnittene.
   *
   * @returns {Promise<Array<{index: number, de: string, group: string, score: number}>>} Top 3
   */
  async classify(video, box) {
    if (!this.ready || this.busy || !video.videoWidth) return [];
    this.busy = true;
    try {
      const vw = video.videoWidth;
      const vh = video.videoHeight;
      const side = Math.max(box.w, box.h) * 1.15;
      const cx = box.x + box.w / 2;
      const cy = box.y + box.h / 2;
      const sx = Math.max(0, Math.min(vw - 1, cx - side / 2));
      const sy = Math.max(0, Math.min(vh - 1, cy - side / 2));
      const sw = Math.min(side, vw - sx);
      const sh = Math.min(side, vh - sy);
      if (sw < 24 || sh < 24) return [];

      this.ctx.fillStyle = '#777';
      this.ctx.fillRect(0, 0, INPUT, INPUT);
      this.ctx.drawImage(video, sx, sy, sw, sh, 0, 0, INPUT, INPUT);

      const probabilities = await this.runner.classify(this.canvas);
      return topK(probabilities, 3);
    } finally {
      this.busy = false;
    }
  }

  dispose() {
    this.runner?.dispose();
    this.runner = null;
    this.ready = false;
  }
}

/** Die k grössten Werte mit Klasseninfo. */
function topK(values, k) {
  const indices = [];
  for (let i = 0; i < values.length; i += 1) {
    if (indices.length < k) {
      indices.push(i);
      indices.sort((a, b) => values[b] - values[a]);
    } else if (values[i] > values[indices[k - 1]]) {
      indices[k - 1] = i;
      indices.sort((a, b) => values[b] - values[a]);
    }
  }
  return indices.map((index) => ({
    index,
    de: IMAGENET[index]?.de ?? `Klasse ${index}`,
    group: IMAGENET[index]?.group ?? 'misc',
    score: values[index],
  }));
}

/**
 * Entscheidet, ob und wie das Ergebnis der Zweitstufe angezeigt wird.
 *
 * @param {object} track  Ziel mit `label`, `labelDe`, `group`, `generic`, `faint`
 * @param {Array} top  Ergebnis von classify()
 * @param {number} minScore
 * @returns {{label: string, score: number, index: number}|null}
 */
export function refine(track, top, minScore = 0.5) {
  const [first] = top;
  if (!first) return null;
  if (!refinementAllowed(first.index, track.group)) return null;

  // Ein konkreter Detektorname wird nur innerhalb seiner Gruppe verfeinert
  // („Schuh“ wird nie zur „Mütze“); allgemeine Klassen dürfen überallhin.
  if (!track.generic && first.group !== track.group) return null;

  // Allgemeine Klassen und unsichere Treffer dürfen verfeinert werden;
  // eine bereits konkrete Klasse nur bei sehr sicherer Zweitstufe.
  const needed = track.generic || track.faint ? minScore : 0.85;
  if (first.score < needed) return null;

  // Gleicher Name in anderer Schreibweise ist kein Gewinn.
  if (first.de.toLowerCase() === String(track.labelDe).toLowerCase()) return null;

  return { label: first.de, score: first.score, index: first.index };
}
