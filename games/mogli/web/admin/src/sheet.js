// Bilddateien einlesen und anzeigen.
//
// Warum die Grösse geprüft wird, statt einfach zu skalieren: ein auf 32 × 32
// heruntergerechnetes Foto ist kein Pixelbild, sondern Matsch. Wer 64 × 64
// hochlädt, hat sich vermutlich vertan – das gehört gesagt, nicht stillschweigend
// ausgebügelt. Für GIF gilt dasselbe.

import { decodeGif, pickEvenlyInTime } from '../../src/render/gif.js';

/**
 * Liest eine Datei als Daten-URL.
 * @returns {Promise<string>}
 */
export function readAsDataUrl(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.addEventListener('load', () => resolve(String(reader.result)), { once: true });
    reader.addEventListener('error', () => reject(new Error('Datei nicht lesbar')), { once: true });
    reader.readAsDataURL(file);
  });
}

/** Lädt eine Daten-URL als Bild. */
export function loadImage(dataUrl) {
  return new Promise((resolve, reject) => {
    const image = new Image();
    image.addEventListener('load', () => resolve(image), { once: true });
    image.addEventListener('error', () => reject(new Error('kein lesbares Bild')), { once: true });
    image.src = dataUrl;
  });
}

/**
 * Zerlegt eine GIF-Datei in genau `anzahl` PNG-Bilder.
 *
 * Der Weg ist absichtlich kurz: das GIF wird hier zerlegt und als die schon
 * bekannten Einzelbilder abgelegt. Damit ändert sich nichts am Paketformat,
 * nichts an admin.php und nichts am Spiel – ein GIF ist eine bequemere Art,
 * dieselben fünf Bilder einzugeben, mehr nicht. Auf dem Server kommt
 * weiterhin ausschliesslich PNG an.
 *
 * @param {File} file
 * @param {{size: number, count: number}} erwartet
 * @returns {Promise<{dataUrls: string[], frameCount: number, width: number, height: number}>}
 */
export async function acceptGif(file, erwartet) {
  const puffer = new Uint8Array(await file.arrayBuffer());
  const gif = decodeGif(puffer);

  if (gif.width !== erwartet.size || gif.height !== erwartet.size) {
    throw new Error(
      `Das GIF ist ${gif.width} × ${gif.height}. Gebraucht werden ${erwartet.size} × ${erwartet.size} – ` +
        'kleinrechnen würde die Pixelkanten zerstören.',
    );
  }

  const gewaehlt = pickEvenlyInTime(gif.frames, erwartet.count);
  const canvas = document.createElement('canvas');
  canvas.width = gif.width;
  canvas.height = gif.height;
  const ctx = canvas.getContext('2d');

  const dataUrls = [];
  for (const index of gewaehlt) {
    const bild = ctx.createImageData(gif.width, gif.height);
    bild.data.set(gif.frames[index].pixels);
    ctx.putImageData(bild, 0, 0);
    dataUrls.push(canvas.toDataURL('image/png'));
  }

  return { dataUrls, frameCount: gif.frames.length, width: gif.width, height: gif.height };
}

/**
 * Nimmt eine Datei entgegen und gibt Daten-URL plus Masse zurück.
 *
 * @param {File} file
 * @param {{width?: number, height?: number}} [expect] erwartete Masse
 * @returns {Promise<{dataUrl: string, width: number, height: number}>}
 */
export async function acceptImage(file, expect = {}) {
  if (file.type.includes('gif') || /\.gif$/i.test(file.name)) {
    // Ein GIF auf einem EINZELNEN Platz ist fast immer ein Missverständnis:
    // gemeint war die ganze Bewegung. Dorthin zeigen, statt nur abzulehnen.
    throw new Error('Ein GIF gehört auf den GIF-Knopf neben der Bewegung – er füllt alle fünf.');
  }
  if (!file.type.includes('png')) {
    throw new Error('Nur PNG-Dateien. Andere Formate haben keine sauberen Pixelkanten.');
  }

  const dataUrl = await readAsDataUrl(file);
  const image = await loadImage(dataUrl);

  if (expect.width !== undefined && image.naturalWidth !== expect.width) {
    throw new Error(
      `${image.naturalWidth} × ${image.naturalHeight} – erwartet sind ${expect.width} Pixel Breite.`,
    );
  }
  if (expect.height !== undefined && image.naturalHeight !== expect.height) {
    throw new Error(
      `${image.naturalWidth} × ${image.naturalHeight} – erwartet sind ${expect.height} Pixel Höhe.`,
    );
  }

  return { dataUrl, width: image.naturalWidth, height: image.naturalHeight };
}

/**
 * Zeichnet ein Bild (oder ein leeres Feld) in einen Canvas.
 * @param {HTMLCanvasElement} canvas
 * @param {HTMLImageElement|null} image
 */
export function showImage(canvas, image) {
  const ctx = canvas.getContext('2d');
  ctx.imageSmoothingEnabled = false;
  ctx.clearRect(0, 0, canvas.width, canvas.height);
  if (image !== null) ctx.drawImage(image, 0, 0, canvas.width, canvas.height);
}

/**
 * Hängt „Datei auswählen" und „Datei hineinziehen" an eine Fläche.
 *
 * Beides, weil beides erwartet wird: am Rechner zieht man Dateien, am Handy
 * gibt es kein Ziehen.
 *
 * @param {HTMLElement} area
 * @param {HTMLInputElement} input
 * @param {(file: File) => void} onFile
 */
export function bindFileArea(area, input, onFile) {
  area.addEventListener('click', () => input.click());
  input.addEventListener('change', () => {
    const file = input.files?.[0];
    if (file) onFile(file);
    // Zurücksetzen, damit dieselbe Datei erneut gewählt werden kann.
    input.value = '';
  });

  const stop = (event) => {
    event.preventDefault();
    event.stopPropagation();
  };
  area.addEventListener('dragover', (event) => {
    stop(event);
    area.classList.add('slot__view--drop');
  });
  area.addEventListener('dragleave', (event) => {
    stop(event);
    area.classList.remove('slot__view--drop');
  });
  area.addEventListener('drop', (event) => {
    stop(event);
    area.classList.remove('slot__view--drop');
    const file = event.dataTransfer?.files?.[0];
    if (file) onFile(file);
  });
}
