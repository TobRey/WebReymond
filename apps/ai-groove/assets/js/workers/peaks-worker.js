/**
 * AI Groove – Worker zur Berechnung von Waveform-Spitzenwerten.
 *
 * Grosse Samples werden hier zerlegt, damit der Haupt-Thread frei bleibt und
 * die Oberflaeche fluessig bei 60 fps zeichnen kann.
 */

self.onmessage = (event) => {
  const { id, channels, buckets } = event.data;
  const n = Math.max(1, buckets | 0);
  const len = channels[0].length;
  const min = new Float32Array(n);
  const max = new Float32Array(n);
  const per = len / n;

  for (let b = 0; b < n; b++) {
    const s = Math.floor(b * per);
    const e = Math.min(len, Math.max(s + 1, Math.floor((b + 1) * per)));
    let lo = 1;
    let hi = -1;
    for (let c = 0; c < channels.length; c++) {
      const d = channels[c];
      for (let i = s; i < e; i++) {
        const v = d[i];
        if (v < lo) lo = v;
        if (v > hi) hi = v;
      }
    }
    if (lo > hi) {
      lo = 0;
      hi = 0;
    }
    min[b] = lo;
    max[b] = hi;
  }

  self.postMessage({ id, min, max, buckets: n }, [min.buffer, max.buffer]);
};
