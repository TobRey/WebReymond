/**
 * Texterkennung im Kamerabild (Tesseract).
 *
 * Läuft vollständig auf dem Gerät. Die rund 7 MB Programm und Sprachdaten
 * werden erst beim ersten Vorlesen geladen – wer die Funktion nie benutzt,
 * lädt sie auch nie.
 */

import { PATHS } from './config.js';

let loading = null;

/** Lädt tesseract.min.js genau einmal nach. */
function loadLibrary() {
  if (globalThis.Tesseract) return Promise.resolve(globalThis.Tesseract);
  loading ??= new Promise((resolve, reject) => {
    const el = document.createElement('script');
    el.src = `${PATHS.tesseract}tesseract.min.js`;
    el.onload = () =>
      globalThis.Tesseract
        ? resolve(globalThis.Tesseract)
        : reject(new Error('Tesseract nicht verfügbar'));
    el.onerror = () => reject(new Error('Texterkennung konnte nicht geladen werden'));
    document.head.appendChild(el);
  });
  return loading;
}

export class Ocr {
  constructor() {
    this.worker = null;
    this.busy = false;
    this.canvas = document.createElement('canvas');
  }

  get ready() {
    return Boolean(this.worker);
  }

  /**
   * Startet den Arbeiter. Dauert beim ersten Mal einige Sekunden.
   * @param {(status: string, progress: number) => void} [onProgress]
   */
  async prepare(onProgress) {
    if (this.worker) return;

    const Tesseract = await loadLibrary();
    this.worker = await Tesseract.createWorker(['deu', 'eng'], 1, {
      workerPath: `${PATHS.tesseract}worker.min.js`,
      // Direkt auf die Datei zeigen: Dann überspringt tesseract.js die
      // Merkmalserkennung und lädt genau diesen Build statt drei Varianten
      // zu erwarten, von denen wir nur eine ausliefern.
      corePath: `${PATHS.tesseract}tesseract-core-simd-lstm.wasm.js`,
      langPath: PATHS.tesseract.replace(/\/$/, ''),
      gzip: true,
      /*
       * `logger` muss immer eine Funktion sein. tesseract.js ruft den
       * Rückmelder bei jeder Fortschrittsmeldung des Arbeiters auf, ohne zu
       * prüfen, ob es ihn gibt – mit `undefined` wirft jede einzelne Meldung
       * einen TypeError in die Konsole.
       */
      logger: (message) => {
        onProgress?.(
          message.status ?? '',
          typeof message.progress === 'number' ? message.progress : 0,
        );
      },
      errorHandler: (error) => console.error('Texterkennung:', error),
    });
  }

  /**
   * Liest den Text im aktuellen Kamerabild.
   *
   * Das Bild wird auf höchstens 1280 px Breite gebracht: Darunter leidet die
   * Erkennung, darüber steigt die Rechenzeit ohne Gewinn.
   *
   * @returns {Promise<{text: string, confidence: number, words: Array}>}
   */
  async read(video) {
    if (this.busy) throw new Error('Texterkennung läuft bereits');
    await this.prepare();

    const vw = video.videoWidth;
    const vh = video.videoHeight;
    if (!vw || !vh) throw new Error('Kein Kamerabild');

    const scale = Math.min(1, 1280 / vw);
    this.canvas.width = Math.round(vw * scale);
    this.canvas.height = Math.round(vh * scale);

    const ctx = this.canvas.getContext('2d', { willReadFrequently: true });
    ctx.drawImage(video, 0, 0, this.canvas.width, this.canvas.height);
    increaseContrast(ctx, this.canvas.width, this.canvas.height);

    this.busy = true;
    try {
      const { data } = await this.worker.recognize(this.canvas);
      const words = (data.words ?? [])
        .filter((word) => word.confidence > 55 && word.text.trim().length > 0)
        .map((word) => ({
          text: word.text,
          confidence: word.confidence / 100,
          // Zurück auf Videopixel, damit das HUD die Kästen setzen kann.
          box: {
            x: word.bbox.x0 / scale,
            y: word.bbox.y0 / scale,
            w: (word.bbox.x1 - word.bbox.x0) / scale,
            h: (word.bbox.y1 - word.bbox.y0) / scale,
          },
        }));

      return {
        text: (data.text ?? '').replace(/\s+/g, ' ').trim(),
        confidence: (data.confidence ?? 0) / 100,
        words,
      };
    } finally {
      this.busy = false;
    }
  }

  async dispose() {
    try {
      await this.worker?.terminate();
    } catch {
      /* egal */
    }
    this.worker = null;
  }
}

/**
 * Schätzt, ob eine Erkennung überhaupt Text ist.
 *
 * Tesseract liefert auch auf einem Gesicht oder einer Hauswand etwas zurück –
 * dann aber als Zeichensalat („j ” . eg A s @ bm ~ » 4%“). Ohne diese Prüfung
 * landet solcher Salat im Scanbericht und wird vorgelesen.
 *
 * @param {string} text
 * @param {boolean} strict Für den Scan; verlangt mehrere echte Wörter
 */
export function looksLikeText(text, strict = false) {
  const clean = text.trim();
  if (clean.length < (strict ? 6 : 3)) return false;

  // Ein „Wort“ beginnt mit einem Buchstaben und besteht überwiegend aus solchen.
  const words = clean.split(/\s+/).filter((word) => /^\p{L}[\p{L}\p{N}.,'’-]{2,}$/u.test(word));
  if (words.length < (strict ? 2 : 1)) return false;

  const letters = (clean.match(/\p{L}/gu) ?? []).length;
  return letters / clean.length >= (strict ? 0.62 : 0.45);
}

/**
 * Hebt den Kontrast an, bevor gelesen wird.
 * Kameras liefern flaue Bilder; Tesseract mag harte Schwarz-Weiss-Kanten.
 */
function increaseContrast(ctx, width, height) {
  const image = ctx.getImageData(0, 0, width, height);
  const d = image.data;

  let min = 255;
  let max = 0;
  for (let i = 0; i < d.length; i += 4) {
    const grey = (d[i] * 77 + d[i + 1] * 151 + d[i + 2] * 28) >> 8;
    if (grey < min) min = grey;
    if (grey > max) max = grey;
  }
  const span = Math.max(1, max - min);

  for (let i = 0; i < d.length; i += 4) {
    const grey = (d[i] * 77 + d[i + 1] * 151 + d[i + 2] * 28) >> 8;
    const stretched = Math.max(0, Math.min(255, ((grey - min) / span) * 255));
    d[i] = stretched;
    d[i + 1] = stretched;
    d[i + 2] = stretched;
  }
  ctx.putImageData(image, 0, 0);
}
