/**
 * AI Groove – MP3-Encoder-Worker.
 *
 * Nutzt lamejs (LAME-Portierung, LGPL – siehe assets/js/vendor/LAME-LICENSE.txt).
 * Die Kodierung laeuft blockweise, damit Fortschritt gemeldet werden kann und
 * der Speicherbedarf gering bleibt.
 */

/* global lamejs */

try {
  importScripts('../vendor/lame.min.js');
} catch (err) {
  self.postMessage({ type: 'error', message: 'Der MP3-Encoder konnte nicht geladen werden.' });
}

const BLOCK = 1152; // Samples pro MP3-Frame

self.onmessage = (event) => {
  const data = event.data;
  if (!data || data.cmd !== 'encode') return;

  try {
    if (typeof lamejs === 'undefined') {
      throw new Error('MP3-Encoder nicht verfügbar.');
    }

    const { left, right, channels, sampleRate, bitrate } = data;
    const numChannels = channels >= 2 ? 2 : 1;
    const encoder = new lamejs.Mp3Encoder(numChannels, sampleRate, bitrate);

    const chunks = [];
    const total = left.length;
    let lastReport = 0;

    for (let i = 0; i < total; i += BLOCK) {
      const l = left.subarray(i, Math.min(i + BLOCK, total));
      const r = numChannels === 2 ? right.subarray(i, Math.min(i + BLOCK, total)) : null;
      const buf = numChannels === 2 ? encoder.encodeBuffer(l, r) : encoder.encodeBuffer(l);
      if (buf.length > 0) chunks.push(new Uint8Array(buf));

      const progress = i / total;
      if (progress - lastReport > 0.02) {
        lastReport = progress;
        self.postMessage({ type: 'progress', value: progress });
      }
    }

    const tail = encoder.flush();
    if (tail.length > 0) chunks.push(new Uint8Array(tail));

    self.postMessage({ type: 'progress', value: 1 });
    self.postMessage({ type: 'done', chunks });
  } catch (err) {
    self.postMessage({ type: 'error', message: err && err.message ? err.message : 'MP3-Kodierung fehlgeschlagen.' });
  }
};
