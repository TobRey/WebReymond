// Bilddateien einlesen, umrechnen und anzeigen.
//
// HIER STAND EINMAL DAS GEGENTEIL
// Ursprünglich wurde jede Datei abgelehnt, die nicht schon genau 32 × 32 oder
// 16 × 16 gross war – mit dem Argument, ein heruntergerechnetes Foto sei kein
// Pixelbild, sondern Matsch. Das Argument stimmt immer noch. Trotzdem war die
// Regel falsch: wer Grafik einsetzen will, soll zeichnen und nicht Masse
// abzählen, und ein grob umgerechnetes Bild ist allemal besser als eine
// Absage. Jetzt geht jede Grösse und jedes Format, das der Browser lesen kann.
//
// Wie umgerechnet wird, steht in ../../src/render/fit.js. Kurz: Seitenverhältnis
// bleibt erhalten, und ob geglättet wird, hängt davon ab, ob das Verhältnis
// glatt aufgeht (Pixelart scharf lassen) oder nicht (Foto glätten).
//
// Herausgegeben wird immer ein PNG in der Zielgrösse. Damit bleibt es dabei,
// dass auf dem Server ausschliesslich PNG ankommt – die Prüfung dort ändert
// sich durch all das nicht.

import { decodeGif, pickEvenlyInTime } from '../../src/render/gif.js';
import { containRect, layerSize, scaleMode } from '../../src/render/fit.js';
import { dataUrlBytes } from '../../src/net/assetRules.js';

/**
 * Öffnet eine Bilddatei, ohne sie durch base64 zu schleifen.
 *
 * Der naheliegende Weg wäre FileReader → Daten-URL → Image. Er funktioniert
 * bei kleinen Dateien und bringt den Browser bei grossen zum Stehen: base64
 * bläht jede Datei um ein Drittel auf, und ein Handyfoto mit sieben Megabyte
 * wird so zu einer Zeichenkette von zehn. Genau daran ist der erste Versuch
 * hängengeblieben, als ein 1920 × 1080 grosses Bild eingesetzt wurde.
 *
 * createImageBitmap nimmt die Datei direkt entgegen. Wo es das nicht gibt,
 * tut es eine Objekt-Adresse – auch die kopiert nichts.
 *
 * @returns {Promise<{quelle: CanvasImageSource, breite: number, hoehe: number,
 *   schliessen: () => void}>}
 */
async function oeffneBild(file) {
  if (typeof createImageBitmap === 'function') {
    try {
      const bitmap = await createImageBitmap(file);
      return {
        quelle: bitmap,
        breite: bitmap.width,
        hoehe: bitmap.height,
        schliessen: () => bitmap.close?.(),
      };
    } catch {
      // Manche Formate kennt createImageBitmap nicht – dann der Weg darunter.
    }
  }

  const url = URL.createObjectURL(file);
  try {
    const image = await loadImage(url);
    return {
      quelle: image,
      breite: image.naturalWidth,
      hoehe: image.naturalHeight,
      schliessen: () => URL.revokeObjectURL(url),
    };
  } catch (error) {
    URL.revokeObjectURL(url);
    throw error;
  }
}

/** Ein GIF als Leinwand mit seinem ersten Bild. */
function ersteGifSeite(bytes) {
  const gif = decodeGif(bytes);
  const canvas = document.createElement('canvas');
  canvas.width = gif.width;
  canvas.height = gif.height;
  const ctx = canvas.getContext('2d');
  const bild = ctx.createImageData(gif.width, gif.height);
  bild.data.set(gif.frames[0].pixels);
  ctx.putImageData(bild, 0, 0);
  return { canvas, frameCount: gif.frames.length };
}

/** Ob eine Datei ein GIF ist – Typ oder Endung, je nachdem was da ist. */
export function istGif(file) {
  return file.type.includes('gif') || /\.gif$/i.test(file.name);
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
export async function acceptGif(file, ziel) {
  const gif = decodeGif(new Uint8Array(await file.arrayBuffer()));
  const gewaehlt = pickEvenlyInTime(gif.frames, ziel.count);

  // Erst jedes gewählte Bild in Originalgrösse auf eine Leinwand, dann von
  // dort auf die Zielgrösse. Der Umweg ist nötig, weil die GIF-Bilder als
  // rohe Punktdaten vorliegen und drawImage kein Feld von Zahlen annimmt.
  const roh = document.createElement('canvas');
  roh.width = gif.width;
  roh.height = gif.height;
  const rohCtx = roh.getContext('2d');

  const dataUrls = [];
  for (const index of gewaehlt) {
    const bild = rohCtx.createImageData(gif.width, gif.height);
    bild.data.set(gif.frames[index].pixels);
    rohCtx.putImageData(bild, 0, 0);
    dataUrls.push(fitToPng(roh, gif.width, gif.height, ziel.size, ziel.size));
  }

  return {
    dataUrls,
    frameCount: gif.frames.length,
    width: gif.width,
    height: gif.height,
  };
}

/**
 * Rechnet eine Quelle auf ein festes Feld um und gibt ein PNG zurück.
 *
 * @param {CanvasImageSource} quelle
 * @returns {string} Daten-URL
 */
export function fitToPng(quelle, srcW, srcH, breite, hoehe) {
  const canvas = document.createElement('canvas');
  canvas.width = breite;
  canvas.height = hoehe;
  const ctx = canvas.getContext('2d');

  const rect = containRect(srcW, srcH, breite, hoehe);
  ctx.imageSmoothingEnabled = scaleMode(srcW, srcH, rect.w, rect.h) === 'weich';
  ctx.imageSmoothingQuality = 'high';
  ctx.drawImage(quelle, 0, 0, srcW, srcH, rect.x, rect.y, rect.w, rect.h);
  return canvas.toDataURL('image/png');
}

/**
 * Nimmt eine beliebige Bilddatei und gibt sie in der Zielgrösse zurück.
 *
 * Ein GIF wird über den eigenen Leser geöffnet und sein erstes Bild benutzt –
 * der Browser gibt bei einem animierten GIF sonst je nach Zufall irgendeines
 * heraus, und "irgendeines" ist keine Antwort, die man einem Nutzer zumuten
 * kann.
 *
 * @param {File} file
 * @param {{width: number, height: number}} ziel
 * @returns {Promise<{dataUrl: string, width: number, height: number, frameCount: number}>}
 *   width/height sind die Masse der QUELLE, damit die Anzeige sagen kann,
 *   was umgerechnet wurde.
 */
export async function acceptImage(file, ziel) {
  if (istGif(file)) {
    const { canvas, frameCount } = ersteGifSeite(new Uint8Array(await file.arrayBuffer()));
    return {
      dataUrl: fitToPng(canvas, canvas.width, canvas.height, ziel.width, ziel.height),
      width: canvas.width,
      height: canvas.height,
      frameCount,
    };
  }

  const bild = await oeffneBild(file);
  try {
    return {
      dataUrl: fitToPng(bild.quelle, bild.breite, bild.hoehe, ziel.width, ziel.height),
      width: bild.breite,
      height: bild.hoehe,
      frameCount: 1,
    };
  } finally {
    bild.schliessen();
  }
}

/**
 * Wie acceptImage, aber für eine Hintergrundebene: die Breite liegt fest, die
 * Höhe folgt dem Seitenverhältnis. Sie bestimmt, nach welcher Strecke sich die
 * Ebene beim Klettern wiederholt.
 *
 * @param {File} file
 * @param {{width: number, maxHeight: number}} ziel
 */
export async function acceptLayer(file, ziel) {
  let quelle;
  let srcW;
  let srcH;
  let schliessen = () => {};

  if (istGif(file)) {
    const seite = ersteGifSeite(new Uint8Array(await file.arrayBuffer()));
    quelle = seite.canvas;
    srcW = seite.canvas.width;
    srcH = seite.canvas.height;
  } else {
    const bild = await oeffneBild(file);
    quelle = bild.quelle;
    srcW = bild.breite;
    srcH = bild.hoehe;
    schliessen = bild.schliessen;
  }

  // Verkleinern, bis es passt.
  //
  // Ein Foto von 800 × 3000 ergibt als Ebene 256 × 960 - und das sind 667 kB,
  // mehr als ein einzelnes Bild im Paket haben darf. Die Grenze anzuheben wäre
  // die naheliegende Antwort und die schlechtere: das ganze Paket geht als ein
  // POST an admin.php, und auf einem gewöhnlichen Webspace ist bei acht
  // Megabyte Schluss. Ein Paket, das der Server annimmt, ist mehr wert als
  // zweihundert Pixel mehr Höhe.
  //
  // Also wird die Höhe schrittweise zurückgenommen, statt die Datei
  // abzulehnen. Für den, der sie einsetzt, gibt es damit keine Grenze mehr,
  // gegen die er laufen könnte.
  let maxHoehe = ziel.maxHeight;
  let mass = layerSize(srcW, srcH, ziel.width, maxHoehe);
  let dataUrl = zeichneEbene(quelle, srcW, mass);
  let verkleinert = false;

  while (dataUrlBytes(dataUrl) > ziel.maxBytes && mass.height > 64) {
    maxHoehe = Math.max(64, Math.floor(mass.height * 0.75));
    mass = layerSize(srcW, srcH, ziel.width, maxHoehe);
    dataUrl = zeichneEbene(quelle, srcW, mass);
    verkleinert = true;
  }

  schliessen();

  return {
    dataUrl,
    width: srcW,
    height: srcH,
    zielHoehe: mass.height,
    beschnitten: mass.crop.h !== srcH,
    verkleinert,
    bytes: dataUrlBytes(dataUrl),
  };
}

/** Zeichnet die Ebene in der berechneten Grösse und gibt ein PNG zurück. */
function zeichneEbene(quelle, srcW, mass) {
  const canvas = document.createElement('canvas');
  canvas.width = mass.width;
  canvas.height = mass.height;
  const ctx = canvas.getContext('2d');
  ctx.imageSmoothingEnabled = scaleMode(srcW, mass.crop.h, mass.width, mass.height) === 'weich';
  ctx.imageSmoothingQuality = 'high';
  ctx.drawImage(quelle, 0, mass.crop.y, srcW, mass.crop.h, 0, 0, mass.width, mass.height);
  return canvas.toDataURL('image/png');
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
