/**
 * AI Groove – Dateiimport und -pruefung.
 *
 * Dateien verlassen niemals das Geraet: sie werden lokal gelesen, dekodiert und
 * in IndexedDB abgelegt. Der Server sieht sie nie.
 */

import { humanBytes } from './util.js';

/** Groessenlimit fuer ein einzelnes Sample. */
export const MAX_SAMPLE_BYTES = 64 * 1024 * 1024;

/** Groessenlimit fuer eine Projektdatei. */
export const MAX_PROJECT_BYTES = 512 * 1024 * 1024;

export const AUDIO_EXTENSIONS = ['wav', 'wave', 'mp3', 'm4a', 'aac', 'mp4', 'ogg', 'oga', 'opus', 'flac', 'webm', 'aif', 'aiff'];

export const AUDIO_ACCEPT = [
  'audio/wav',
  'audio/x-wav',
  'audio/wave',
  'audio/mpeg',
  'audio/mp3',
  'audio/mp4',
  'audio/x-m4a',
  'audio/aac',
  'audio/ogg',
  'audio/opus',
  'audio/flac',
  'audio/x-flac',
  'audio/webm',
  'audio/aiff',
  'audio/x-aiff',
  '.wav',
  '.mp3',
  '.m4a',
  '.aac',
  '.ogg',
  '.flac',
  '.aiff',
].join(',');

export class FileError extends Error {
  constructor(code, message) {
    super(message);
    this.name = 'FileError';
    this.code = code;
  }
}

export function extensionOf(name) {
  const m = String(name || '').toLowerCase().match(/\.([a-z0-9]{1,5})$/);
  return m ? m[1] : '';
}

/**
 * Prueft eine Audiodatei, bevor sie gelesen wird.
 * Verlaesst sich nicht allein auf den MIME-Typ des Browsers (der ist unzuverlaessig),
 * sondern prueft zusaetzlich die Endung und spaeter den echten Inhalt beim Dekodieren.
 */
export function validateAudioFile(file) {
  if (!file) throw new FileError('empty', 'Es wurde keine Datei ausgewählt.');
  if (file.size === 0) throw new FileError('empty', 'Die Datei ist leer.');
  if (file.size > MAX_SAMPLE_BYTES) {
    throw new FileError(
      'too_large',
      `Die Datei ist zu gross (${humanBytes(file.size)}). Erlaubt sind maximal ${humanBytes(MAX_SAMPLE_BYTES)}.`,
    );
  }

  const ext = extensionOf(file.name);
  const mime = (file.type || '').toLowerCase();
  const looksAudio = mime.startsWith('audio/') || mime === 'application/ogg' || mime === 'video/mp4';

  if (!looksAudio && !AUDIO_EXTENSIONS.includes(ext)) {
    throw new FileError(
      'unsupported',
      'Dieses Dateiformat wird nicht unterstützt. Möglich sind WAV, MP3, M4A/AAC, OGG und FLAC.',
    );
  }
  return { ext, mime: mime || guessMime(ext) };
}

export function guessMime(ext) {
  switch (ext) {
    case 'wav':
    case 'wave':
      return 'audio/wav';
    case 'mp3':
      return 'audio/mpeg';
    case 'm4a':
    case 'mp4':
    case 'aac':
      return 'audio/mp4';
    case 'ogg':
    case 'oga':
      return 'audio/ogg';
    case 'opus':
      return 'audio/opus';
    case 'flac':
      return 'audio/flac';
    case 'webm':
      return 'audio/webm';
    case 'aif':
    case 'aiff':
      return 'audio/aiff';
    default:
      return 'application/octet-stream';
  }
}

/** Liest eine Datei als ArrayBuffer. */
export function readArrayBuffer(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(reader.result);
    reader.onerror = () => reject(new FileError('read_failed', 'Die Datei konnte nicht gelesen werden.'));
    reader.readAsArrayBuffer(file);
  });
}

/** Erkennt den Container anhand der ersten Bytes (Magic Bytes). */
export function sniffAudioFormat(buffer) {
  const b = new Uint8Array(buffer, 0, Math.min(16, buffer.byteLength));
  const str = (i, n) => String.fromCharCode(...b.subarray(i, i + n));
  if (b.length >= 12 && str(0, 4) === 'RIFF' && str(8, 4) === 'WAVE') return 'audio/wav';
  if (b.length >= 4 && str(0, 4) === 'fLaC') return 'audio/flac';
  if (b.length >= 4 && str(0, 4) === 'OggS') return 'audio/ogg';
  if (b.length >= 12 && str(4, 4) === 'ftyp') return 'audio/mp4';
  if (b.length >= 3 && str(0, 3) === 'ID3') return 'audio/mpeg';
  if (b.length >= 2 && b[0] === 0xff && (b[1] & 0xe0) === 0xe0) return 'audio/mpeg';
  if (b.length >= 4 && str(0, 4) === 'FORM') return 'audio/aiff';
  if (b.length >= 4 && b[0] === 0x1a && b[1] === 0x45 && b[2] === 0xdf && b[3] === 0xa3) return 'audio/webm';
  return '';
}

/** Oeffnet den Dateiauswahldialog und liefert die gewaehlten Dateien. */
export function pickFiles({ accept = AUDIO_ACCEPT, multiple = false } = {}) {
  return new Promise((resolve) => {
    const input = document.createElement('input');
    input.type = 'file';
    input.accept = accept;
    input.multiple = multiple;
    input.style.position = 'fixed';
    input.style.left = '-9999px';
    document.body.appendChild(input);

    let settled = false;
    const finish = (files) => {
      if (settled) return;
      settled = true;
      input.remove();
      resolve(files);
    };

    input.addEventListener('change', () => finish(Array.from(input.files || [])));
    // Falls der Nutzer abbricht, meldet der Browser nichts – nach Rueckkehr aufraeumen.
    window.addEventListener(
      'focus',
      () => setTimeout(() => finish(Array.from(input.files || [])), 800),
      { once: true },
    );

    input.click();
  });
}

/** Erkennt eine AI-Groove-Projektdatei. */
export function isProjectFile(file) {
  return extensionOf(file.name) === 'aigroove';
}

/** Freundliche Beschreibung eines Fehlers beim Dekodieren. */
export function decodeErrorMessage(ext) {
  const known = {
    flac: 'FLAC wird von diesem Browser nicht unterstützt. Safari auf älteren iPhones kann FLAC nicht abspielen – bitte die Datei als WAV oder MP3 konvertieren.',
    ogg: 'OGG/Vorbis wird von Safari nicht unterstützt. Bitte WAV, MP3 oder M4A verwenden.',
    opus: 'Opus wird von diesem Browser nicht unterstützt. Bitte WAV, MP3 oder M4A verwenden.',
    webm: 'Dieses WebM-Audio kann der Browser nicht dekodieren. Bitte WAV oder MP3 verwenden.',
  };
  return (
    known[ext] ||
    'Die Audiodatei konnte nicht dekodiert werden. Sie ist möglicherweise beschädigt oder das Format wird von diesem Browser nicht unterstützt.'
  );
}
