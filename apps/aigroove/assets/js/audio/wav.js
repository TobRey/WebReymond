/**
 * AI Groove – WAV-Kodierung.
 *
 * Wird fuer Mikrofonaufnahmen, bearbeitete Samples und den verlustfreien
 * Export verwendet. 16 Bit und 24 Bit PCM werden unterstuetzt.
 */

/**
 * @param {AudioBuffer} buffer
 * @param {{bitDepth?: 16|24|32}} [options]
 * @returns {ArrayBuffer}
 */
export function encodeWav(buffer, options = {}) {
  const bitDepth = options.bitDepth === 24 ? 24 : options.bitDepth === 32 ? 32 : 16;
  const numChannels = buffer.numberOfChannels;
  const sampleRate = buffer.sampleRate;
  const numFrames = buffer.length;
  const bytesPerSample = bitDepth / 8;
  const blockAlign = numChannels * bytesPerSample;
  const dataSize = numFrames * blockAlign;
  const isFloat = bitDepth === 32;

  const out = new ArrayBuffer(44 + dataSize);
  const view = new DataView(out);

  const writeString = (offset, str) => {
    for (let i = 0; i < str.length; i++) view.setUint8(offset + i, str.charCodeAt(i));
  };

  writeString(0, 'RIFF');
  view.setUint32(4, 36 + dataSize, true);
  writeString(8, 'WAVE');
  writeString(12, 'fmt ');
  view.setUint32(16, 16, true); // Groesse des fmt-Blocks
  view.setUint16(20, isFloat ? 3 : 1, true); // 1 = PCM, 3 = IEEE Float
  view.setUint16(22, numChannels, true);
  view.setUint32(24, sampleRate, true);
  view.setUint32(28, sampleRate * blockAlign, true);
  view.setUint16(32, blockAlign, true);
  view.setUint16(34, bitDepth, true);
  writeString(36, 'data');
  view.setUint32(40, dataSize, true);

  // Kanaele vorab holen: getChannelData() ist teuer, wenn es pro Sample laeuft.
  const channels = [];
  for (let c = 0; c < numChannels; c++) channels.push(buffer.getChannelData(c));

  let offset = 44;
  if (isFloat) {
    for (let i = 0; i < numFrames; i++) {
      for (let c = 0; c < numChannels; c++) {
        view.setFloat32(offset, channels[c][i], true);
        offset += 4;
      }
    }
  } else if (bitDepth === 24) {
    for (let i = 0; i < numFrames; i++) {
      for (let c = 0; c < numChannels; c++) {
        const s = Math.max(-1, Math.min(1, channels[c][i]));
        const v = Math.round(s < 0 ? s * 8388608 : s * 8388607);
        view.setUint8(offset, v & 0xff);
        view.setUint8(offset + 1, (v >> 8) & 0xff);
        view.setUint8(offset + 2, (v >> 16) & 0xff);
        offset += 3;
      }
    }
  } else {
    for (let i = 0; i < numFrames; i++) {
      for (let c = 0; c < numChannels; c++) {
        const s = Math.max(-1, Math.min(1, channels[c][i]));
        view.setInt16(offset, s < 0 ? s * 0x8000 : s * 0x7fff, true);
        offset += 2;
      }
    }
  }

  return out;
}

/** Erzeugt einen Blob aus einem AudioBuffer. */
export function wavBlob(buffer, options) {
  return new Blob([encodeWav(buffer, options)], { type: 'audio/wav' });
}

/**
 * Wandelt einen AudioBuffer in verschachtelte Int16-Daten pro Kanal um.
 * Format, das der MP3-Encoder erwartet.
 */
export function toInt16Channels(buffer) {
  const n = buffer.length;
  const chs = Math.min(2, buffer.numberOfChannels);
  const out = [];
  for (let c = 0; c < chs; c++) {
    const src = buffer.getChannelData(c);
    const dst = new Int16Array(n);
    for (let i = 0; i < n; i++) {
      const s = Math.max(-1, Math.min(1, src[i]));
      dst[i] = s < 0 ? s * 0x8000 : s * 0x7fff;
    }
    out.push(dst);
  }
  if (out.length === 1) out.push(out[0]); // Mono -> Dual-Mono fuer den Encoder
  return out;
}
