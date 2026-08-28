/**
 * GrooveNet – schnelle Fourier-Transformation und Spektrogramm-Werkzeuge.
 *
 * Diese Datei ist die mathematische Grundlage der eigenen KI. Sie enthaelt
 * keine Musikkenntnis, sondern nur Signalverarbeitung:
 *
 *   FFT          Zerlegt ein Stueck Audio in seine Frequenzanteile.
 *   STFT         Macht das fortlaufend und ergibt ein Spektrogramm
 *                (Frequenz ueber Zeit) – das "Bild", auf dem die KI arbeitet.
 *   ISTFT        Baut aus so einem Bild wieder hoerbares Audio.
 *   Griffin-Lim  Rekonstruiert fehlende Phaseninformation, wenn die KI ein
 *                Spektrogramm veraendert hat. Das ist derselbe Ansatz, den
 *                auch grosse Audiomodelle fuer den letzten Schritt nutzen.
 *
 * Alles laeuft ohne Abhaengigkeiten und ohne Internet.
 */

/** Naechste Zweierpotenz groesser oder gleich n. */
export function nextPow2(n) {
  let p = 1;
  while (p < n) p *= 2;
  return p;
}

/**
 * Radix-2-FFT mit vorberechneten Tabellen.
 * Eine Instanz wird fuer viele Transformationen derselben Groesse wiederverwendet.
 */
export class FFT {
  constructor(size) {
    if (size < 2 || (size & (size - 1)) !== 0) {
      throw new Error('FFT-Groesse muss eine Zweierpotenz sein.');
    }
    this.size = size;
    this.levels = Math.log2(size);

    // Bit-Umkehr-Tabelle: legt fest, wie die Eingabe umsortiert wird.
    this.rev = new Uint32Array(size);
    for (let i = 0; i < size; i++) {
      let x = i;
      let r = 0;
      for (let b = 0; b < this.levels; b++) {
        r = (r << 1) | (x & 1);
        x >>= 1;
      }
      this.rev[i] = r >>> 0;
    }

    // Drehfaktoren (Twiddle Factors) fuer die halbe Laenge.
    this.cos = new Float64Array(size / 2);
    this.sin = new Float64Array(size / 2);
    for (let i = 0; i < size / 2; i++) {
      this.cos[i] = Math.cos((-2 * Math.PI * i) / size);
      this.sin[i] = Math.sin((-2 * Math.PI * i) / size);
    }
  }

  /**
   * Transformiert an Ort und Stelle.
   * @param {Float64Array} re Realteil (Laenge = size)
   * @param {Float64Array} im Imaginaerteil (Laenge = size)
   * @param {boolean} [inverse] true = Ruecktransformation inklusive 1/N
   */
  transform(re, im, inverse = false) {
    const n = this.size;
    const rev = this.rev;

    for (let i = 0; i < n; i++) {
      const j = rev[i];
      if (j > i) {
        let t = re[i];
        re[i] = re[j];
        re[j] = t;
        t = im[i];
        im[i] = im[j];
        im[j] = t;
      }
    }

    const sign = inverse ? -1 : 1;
    for (let len = 2; len <= n; len <<= 1) {
      const half = len >> 1;
      const step = n / len;
      for (let i = 0; i < n; i += len) {
        for (let k = 0, idx = 0; k < half; k++, idx += step) {
          const wr = this.cos[idx];
          const wi = this.sin[idx] * sign;
          const a = i + k;
          const b = a + half;
          const xr = re[b] * wr - im[b] * wi;
          const xi = re[b] * wi + im[b] * wr;
          re[b] = re[a] - xr;
          im[b] = im[a] - xi;
          re[a] += xr;
          im[a] += xi;
        }
      }
    }

    if (inverse) {
      const f = 1 / n;
      for (let i = 0; i < n; i++) {
        re[i] *= f;
        im[i] *= f;
      }
    }
  }

  /**
   * Betragsspektrum eines reellen Signalausschnitts.
   * @param {Float32Array|Float64Array} frame Laenge = size
   * @param {Float32Array} [out] Ziel der Laenge size/2 + 1
   */
  magnitude(frame, out) {
    const n = this.size;
    const re = this._re || (this._re = new Float64Array(n));
    const im = this._im || (this._im = new Float64Array(n));
    for (let i = 0; i < n; i++) {
      re[i] = frame[i];
      im[i] = 0;
    }
    this.transform(re, im);
    const bins = n / 2 + 1;
    const dst = out || new Float32Array(bins);
    for (let i = 0; i < bins; i++) dst[i] = Math.hypot(re[i], im[i]);
    return dst;
  }
}

/** Hann-Fenster. Verhindert Kanten und damit falsche Frequenzanteile. */
export function hannWindow(size) {
  const w = new Float64Array(size);
  for (let i = 0; i < size; i++) w[i] = 0.5 - 0.5 * Math.cos((2 * Math.PI * i) / size);
  return w;
}

/**
 * Kurzzeit-Fourier-Transformation.
 *
 * @param {Float32Array} signal
 * @param {{fftSize?:number, hop?:number, keepPhase?:boolean}} [opts]
 * @returns {{mag:Float32Array[], phase:(Float32Array[]|null), fftSize:number, hop:number, bins:number, frames:number}}
 */
export function stft(signal, opts = {}) {
  const fftSize = opts.fftSize || 1024;
  const hop = opts.hop || fftSize / 4;
  const keepPhase = opts.keepPhase !== false;
  const fft = new FFT(fftSize);
  const win = hannWindow(fftSize);
  const bins = fftSize / 2 + 1;

  const frames = Math.max(1, Math.ceil(signal.length / hop));
  const mag = [];
  const phase = keepPhase ? [] : null;

  const re = new Float64Array(fftSize);
  const im = new Float64Array(fftSize);

  for (let f = 0; f < frames; f++) {
    const start = f * hop;
    for (let i = 0; i < fftSize; i++) {
      const s = start + i;
      re[i] = (s < signal.length ? signal[s] : 0) * win[i];
      im[i] = 0;
    }
    fft.transform(re, im);
    const m = new Float32Array(bins);
    const p = keepPhase ? new Float32Array(bins) : null;
    for (let i = 0; i < bins; i++) {
      m[i] = Math.hypot(re[i], im[i]);
      if (p) p[i] = Math.atan2(im[i], re[i]);
    }
    mag.push(m);
    if (phase) phase.push(p);
  }

  return { mag, phase, fftSize, hop, bins, frames };
}

/**
 * Rueckwandlung eines Spektrogramms in Audio (Overlap-Add mit Fensterausgleich).
 *
 * @param {Float32Array[]} mag
 * @param {Float32Array[]} phase
 * @param {{fftSize:number, hop:number, length?:number}} opts
 * @returns {Float32Array}
 */
export function istft(mag, phase, opts) {
  const { fftSize, hop } = opts;
  const fft = new FFT(fftSize);
  const win = hannWindow(fftSize);
  const frames = mag.length;
  const length = opts.length || (frames - 1) * hop + fftSize;

  const out = new Float64Array(length + fftSize);
  const norm = new Float64Array(length + fftSize);
  const re = new Float64Array(fftSize);
  const im = new Float64Array(fftSize);
  const bins = fftSize / 2 + 1;

  for (let f = 0; f < frames; f++) {
    const m = mag[f];
    const p = phase[f];
    for (let i = 0; i < bins; i++) {
      re[i] = m[i] * Math.cos(p[i]);
      im[i] = m[i] * Math.sin(p[i]);
    }
    // Konjugiert symmetrische Ergaenzung: das Ergebnis bleibt reell.
    for (let i = bins; i < fftSize; i++) {
      const j = fftSize - i;
      re[i] = re[j];
      im[i] = -im[j];
    }
    fft.transform(re, im, true);

    const start = f * hop;
    for (let i = 0; i < fftSize; i++) {
      out[start + i] += re[i] * win[i];
      norm[start + i] += win[i] * win[i];
    }
  }

  const result = new Float32Array(length);
  for (let i = 0; i < length; i++) {
    result[i] = norm[i] > 1e-8 ? out[i] / norm[i] : 0;
  }
  return result;
}

/**
 * Griffin-Lim: findet zu einem gewuenschten Betragsspektrogramm eine passende
 * Phase. Noetig, sobald die KI ein Spektrogramm veraendert hat – ohne diesen
 * Schritt klingt das Ergebnis metallisch und verwaschen.
 *
 * @param {Float32Array[]} targetMag Ziel-Betragsspektrogramm
 * @param {{fftSize:number, hop:number, length:number, iterations?:number, phase?:Float32Array[]}} opts
 * @returns {Float32Array}
 */
export function griffinLim(targetMag, opts) {
  const iterations = opts.iterations ?? 24;
  const { fftSize, hop, length } = opts;
  const bins = fftSize / 2 + 1;

  // Startphase: entweder vorgegeben (dann konvergiert es deutlich schneller)
  // oder zufaellig, aber deterministisch.
  let phase = opts.phase;
  if (!phase) {
    phase = [];
    let seed = 0x2545f491;
    for (let f = 0; f < targetMag.length; f++) {
      const p = new Float32Array(bins);
      for (let i = 0; i < bins; i++) {
        seed = (seed * 1664525 + 1013904223) >>> 0;
        p[i] = (seed / 4294967296) * 2 * Math.PI - Math.PI;
      }
      phase.push(p);
    }
  }

  let signal = istft(targetMag, phase, { fftSize, hop, length });

  for (let it = 0; it < iterations; it++) {
    const analysed = stft(signal, { fftSize, hop, keepPhase: true });
    const frames = Math.min(targetMag.length, analysed.mag.length);
    const nextPhase = [];
    for (let f = 0; f < targetMag.length; f++) {
      nextPhase.push(f < frames ? analysed.phase[f] : phase[f]);
    }
    signal = istft(targetMag, nextPhase, { fftSize, hop, length });
    phase = nextPhase;
  }

  return signal;
}
