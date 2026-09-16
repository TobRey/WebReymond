/**
 * Texterkennung im Kamerabild (Tesseract).
 *
 * Läuft vollständig auf dem Gerät. Die rund 7 MB Programm und Sprachdaten
 * werden erst beim ersten Bedarf geladen – wer die Funktion nie benutzt,
 * lädt sie auch nie.
 *
 * Drei Betriebsarten:
 *
 *   read()        einmal das ganze Bild lesen (Scan, Sprachbefehl)
 *   background    alle paar Sekunden im Hintergrund lesen, solange die Kamera
 *                 ruhig gehalten wird – ein verwackeltes Bild ergibt nur Salat
 *   magnify()     einen Ausschnitt 3× vergrössern, nachschärfen und erneut
 *                 lesen – so werden auch kleine, entfernte Schriften lesbar
 *
 * Der Tesseract-Arbeiter ist ein Web Worker; der Hauptthread bleibt frei, das
 * Bild ruckelt nicht. Es läuft aber immer nur eine Erkennung gleichzeitig.
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

/**
 * Misst, wie ruhig die Kamera gehalten wird.
 *
 * 32×24 Graustufen, verglichen mit dem vorigen Bild. Liegt der Anteil
 * geänderter Pixel unter der Schwelle, gilt die Kamera als still.
 */
export class Stillness {
  constructor() {
    this.canvas = document.createElement('canvas');
    this.canvas.width = 32;
    this.canvas.height = 24;
    this.ctx = this.canvas.getContext('2d', { willReadFrequently: true });
    this.previous = null;
    this.ratio = 1;
  }

  /** @returns {number} Anteil geänderter Pixel, 0 = völlig still */
  measure(video) {
    if (!video.videoWidth) return 1;
    this.ctx.drawImage(video, 0, 0, 32, 24);
    const data = this.ctx.getImageData(0, 0, 32, 24).data;
    const grey = new Uint8Array(32 * 24);
    for (let i = 0, p = 0; i < data.length; i += 4, p += 1) {
      grey[p] = (data[i] * 77 + data[i + 1] * 151 + data[i + 2] * 28) >> 8;
    }
    if (!this.previous) {
      this.previous = grey;
      return 1;
    }
    let changed = 0;
    for (let i = 0; i < grey.length; i += 1) {
      if (Math.abs(grey[i] - this.previous[i]) > 22) changed += 1;
    }
    this.previous = grey;
    this.ratio = changed / grey.length;
    return this.ratio;
  }

  get still() {
    return this.ratio < 0.04;
  }
}

/** Was der Arbeiter zurückgeben soll: Text und die Blockstruktur mit Wortboxen. */
const OUTPUT = { text: true, blocks: true };

export class Ocr {
  constructor() {
    this.worker = null;
    this.busy = false;
    this.canvas = document.createElement('canvas');
    this.magnifyCanvas = document.createElement('canvas');
    this.backgroundTimer = null;
    /** Letztes Hintergrundergebnis, für Lupe und Sprachbefehle. */
    this.last = { words: [], blocks: [], text: '', at: 0 };
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
    if (this.preparing) return this.preparing;

    this.preparing = (async () => {
      const Tesseract = await loadLibrary();
      this.worker = await Tesseract.createWorker(['deu', 'eng'], 1, {
        workerPath: `${PATHS.tesseract}worker.min.js`,
        // Direkt auf die Datei zeigen: Dann überspringt tesseract.js die
        // Merkmalserkennung und lädt genau diesen Build.
        corePath: `${PATHS.tesseract}tesseract-core-simd-lstm.js`,
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
      // Nur Buchstaben, Ziffern und Satzzeichen – kein Rauschen aus Kanten.
      await this.worker.setParameters({ preserve_interword_spaces: '1' });
    })();

    try {
      await this.preparing;
    } finally {
      this.preparing = null;
    }
    return undefined;
  }

  /**
   * Liest den Text im aktuellen Kamerabild.
   *
   * Das Bild wird auf höchstens `maxWidth` gebracht: Darunter leidet die
   * Erkennung, darüber steigt die Rechenzeit ohne Gewinn.
   *
   * @returns {Promise<{text: string, confidence: number, words: Array, blocks: Array}>}
   */
  async read(video, maxWidth = 1280) {
    if (this.busy) throw new Error('Texterkennung läuft bereits');
    await this.prepare();

    const vw = video.videoWidth;
    const vh = video.videoHeight;
    if (!vw || !vh) throw new Error('Kein Kamerabild');

    const scale = Math.min(1, maxWidth / vw);
    this.canvas.width = Math.round(vw * scale);
    this.canvas.height = Math.round(vh * scale);

    const ctx = this.canvas.getContext('2d', { willReadFrequently: true });
    ctx.drawImage(video, 0, 0, this.canvas.width, this.canvas.height);
    increaseContrast(ctx, this.canvas.width, this.canvas.height);

    this.busy = true;
    try {
      const { data } = await this.worker.recognize(this.canvas, {}, OUTPUT);
      const words = collectWords(data, 1 / scale);
      const result = {
        text: (data.text ?? '').replace(/\s+/g, ' ').trim(),
        confidence: (data.confidence ?? 0) / 100,
        words,
        blocks: groupBlocks(words),
      };
      this.last = { ...result, at: performance.now() };
      return result;
    } finally {
      this.busy = false;
    }
  }

  /* ---------------- Hintergrund ---------------- */

  /**
   * Liest alle `intervalMs` im Hintergrund – aber nur, wenn `shouldRun()`
   * zustimmt (Kamera still, Seite sichtbar, nicht im Privatmodus).
   * @param {object} options
   * @param {HTMLVideoElement} options.video
   * @param {number} options.intervalMs
   * @param {() => boolean} options.shouldRun
   * @param {(result: object) => void} options.onResult
   */
  startBackground({ video, intervalMs, shouldRun, onResult }) {
    this.stopBackground();
    let stopped = false;

    const tick = async () => {
      if (stopped) return;
      if (shouldRun() && !this.busy) {
        try {
          // Kleineres Bild als beim gezielten Lesen: schneller, reicht zum
          // Finden von Textblöcken. Die Lupe liest dann in voller Schärfe.
          const result = await this.read(video, 960);
          if (result.words.length > 0 || this.last.words.length > 0) onResult(result);
        } catch {
          /* Nächster Takt versucht es erneut. */
        }
      }
      if (!stopped) this.backgroundTimer = setTimeout(tick, intervalMs);
    };

    this.backgroundTimer = setTimeout(tick, 800);
    this.stopBackground = () => {
      stopped = true;
      clearTimeout(this.backgroundTimer);
      this.backgroundTimer = null;
      this.stopBackground = () => {};
    };
  }

  stopBackground() {
    /* Wird von startBackground ersetzt. */
  }

  /* ---------------- Lupe ---------------- */

  /**
   * Vergrössert einen Ausschnitt und liest ihn erneut.
   *
   * Kleine Schrift scheitert nicht an Tesseract, sondern an den wenigen
   * Pixeln je Buchstabe. Hochskalieren mit weicher Interpolation und ein
   * leichtes Nachschärfen geben dem Erkenner wieder Kanten zum Festhalten.
   *
   * @param {HTMLVideoElement} video
   * @param {{x:number,y:number,w:number,h:number}} box  in Videopixeln
   * @param {number} factor  Vergrösserung
   * @returns {Promise<{canvas: HTMLCanvasElement, text: string, confidence: number, factor: number}>}
   */
  async magnify(video, box, factor = 3) {
    await this.prepare();
    const vw = video.videoWidth;
    const vh = video.videoHeight;
    if (!vw || !vh) throw new Error('Kein Kamerabild');

    // Etwas Rand, damit angeschnittene Buchstaben mitkommen.
    const margin = Math.max(8, Math.min(box.w, box.h) * 0.12);
    const sx = Math.max(0, box.x - margin);
    const sy = Math.max(0, box.y - margin);
    const sw = Math.min(vw - sx, box.w + margin * 2);
    const sh = Math.min(vh - sy, box.h + margin * 2);

    // Zielgrösse begrenzen – ein 4000-px-Bild bringt nichts mehr.
    const target = Math.min(factor, 2400 / Math.max(sw, sh));
    const out = this.magnifyCanvas;
    out.width = Math.round(sw * target);
    out.height = Math.round(sh * target);

    const ctx = out.getContext('2d', { willReadFrequently: true });
    ctx.imageSmoothingEnabled = true;
    ctx.imageSmoothingQuality = 'high';
    ctx.drawImage(video, sx, sy, sw, sh, 0, 0, out.width, out.height);
    sharpen(ctx, out.width, out.height);

    // Kopie für die Anzeige, bevor der Kontrast fürs Lesen verändert wird.
    const view = document.createElement('canvas');
    view.width = out.width;
    view.height = out.height;
    view.getContext('2d').drawImage(out, 0, 0);

    increaseContrast(ctx, out.width, out.height);

    // Auf einen laufenden Hintergrundlauf warten statt zu scheitern.
    let waited = 0;
    while (this.busy && waited < 6000) {
      await new Promise((resolve) => setTimeout(resolve, 100));
      waited += 100;
    }

    this.busy = true;
    try {
      const { data } = await this.worker.recognize(out, {}, OUTPUT);
      return {
        canvas: view,
        text: (data.text ?? '')
          .replace(/[ \t]+/g, ' ')
          .replace(/\n{2,}/g, '\n')
          .trim(),
        confidence: (data.confidence ?? 0) / 100,
        factor: target,
      };
    } finally {
      this.busy = false;
    }
  }

  async dispose() {
    this.stopBackground();
    try {
      await this.worker?.terminate();
    } catch {
      /* egal */
    }
    this.worker = null;
  }
}

/* ==========================================================================
 * Hilfen
 * ========================================================================== */

/** Wörter aus dem Tesseract-Ergebnis, zurück auf Videopixel. */
/**
 * Alle Wörter eines Ergebnisses, in Videopixel umgerechnet.
 *
 * tesseract.js liefert seit Version 5 keine flache Wortliste mehr; die Wörter
 * stecken in Blöcken → Absätzen → Zeilen. Beides wird verstanden.
 */
function collectWords(data, scale) {
  const flat =
    data.words ??
    (data.blocks ?? []).flatMap((block) =>
      (block.paragraphs ?? []).flatMap((paragraph) =>
        (paragraph.lines ?? []).flatMap((line) => line.words ?? []),
      ),
    );
  return flat
    .filter((word) => word.confidence > 55 && word.text.trim().length > 0)
    .map((word) => ({
      text: word.text,
      confidence: word.confidence / 100,
      box: {
        x: word.bbox.x0 * scale,
        y: word.bbox.y0 * scale,
        w: (word.bbox.x1 - word.bbox.x0) * scale,
        h: (word.bbox.y1 - word.bbox.y0) * scale,
      },
    }));
}

/**
 * Fasst Wörter zu Zeilen und Zeilen zu Blöcken zusammen.
 *
 * Zwei Wörter gehören zur selben Zeile, wenn sich ihre Höhen überlappen;
 * zwei Zeilen zum selben Block, wenn sie sich waagrecht überschneiden und
 * ihr Abstand kleiner ist als anderthalb Zeilenhöhen.
 *
 * @returns {Array<{box: object, text: string, lines: number, confidence: number}>}
 */
export function groupBlocks(words) {
  if (words.length === 0) return [];

  const sorted = [...words].sort((a, b) => a.box.y - b.box.y || a.box.x - b.box.x);
  const lines = [];

  for (const word of sorted) {
    const line = lines.find((candidate) => {
      const top = Math.max(candidate.box.y, word.box.y);
      const bottom = Math.min(candidate.box.y + candidate.box.h, word.box.y + word.box.h);
      return bottom - top > Math.min(candidate.box.h, word.box.h) * 0.5;
    });
    if (line) {
      line.words.push(word);
      line.box = union(line.box, word.box);
    } else {
      lines.push({ words: [word], box: { ...word.box } });
    }
  }

  for (const line of lines) line.words.sort((a, b) => a.box.x - b.box.x);
  lines.sort((a, b) => a.box.y - b.box.y);

  const blocks = [];
  for (const line of lines) {
    const block = blocks.find((candidate) => {
      const gap = line.box.y - (candidate.box.y + candidate.box.h);
      const overlapX =
        Math.min(candidate.box.x + candidate.box.w, line.box.x + line.box.w) -
        Math.max(candidate.box.x, line.box.x);
      return gap < line.box.h * 1.5 && overlapX > -line.box.h;
    });
    if (block) {
      block.lines.push(line);
      block.box = union(block.box, line.box);
    } else {
      blocks.push({ lines: [line], box: { ...line.box } });
    }
  }

  return blocks
    .map((block) => {
      const allWords = block.lines.flatMap((line) => line.words);
      return {
        box: block.box,
        text: block.lines.map((line) => line.words.map((w) => w.text).join(' ')).join('\n'),
        lines: block.lines.length,
        confidence: allWords.reduce((sum, w) => sum + w.confidence, 0) / allWords.length,
        wordCount: allWords.length,
      };
    })
    .filter((block) => block.wordCount >= 1 && looksLikeText(block.text))
    .sort((a, b) => b.box.w * b.box.h - a.box.w * a.box.h);
}

function union(a, b) {
  const x = Math.min(a.x, b.x);
  const y = Math.min(a.y, b.y);
  return {
    x,
    y,
    w: Math.max(a.x + a.w, b.x + b.w) - x,
    h: Math.max(a.y + a.h, b.y + b.h) - y,
  };
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

/**
 * Leichte Unschärfemaske: Das hochskalierte Bild ist weich; ein Hauch
 * Schärfe bringt die Buchstabenkanten zurück, ohne Rauschen zu verstärken.
 */
function sharpen(ctx, width, height) {
  const image = ctx.getImageData(0, 0, width, height);
  const src = image.data;
  const out = new Uint8ClampedArray(src.length);
  const amount = 0.6;

  for (let y = 0; y < height; y += 1) {
    for (let x = 0; x < width; x += 1) {
      const i = (y * width + x) * 4;
      if (x === 0 || y === 0 || x === width - 1 || y === height - 1) {
        out[i] = src[i];
        out[i + 1] = src[i + 1];
        out[i + 2] = src[i + 2];
        out[i + 3] = 255;
        continue;
      }
      for (let c = 0; c < 3; c += 1) {
        const centre = src[i + c];
        const around =
          (src[i - 4 + c] + src[i + 4 + c] + src[i - width * 4 + c] + src[i + width * 4 + c]) / 4;
        out[i + c] = Math.max(0, Math.min(255, centre + (centre - around) * amount));
      }
      out[i + 3] = 255;
    }
  }
  image.data.set(out);
  ctx.putImageData(image, 0, 0);
}
