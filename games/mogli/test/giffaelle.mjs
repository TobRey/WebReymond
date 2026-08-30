// Die GIF-Fälle, einmal aufgeschrieben – benutzt von test/gif.test.mjs und
// von tools/gif-gegenprobe.mjs. Sonst prüfte die Gegenprobe mit Chromium
// andere Dateien als der Test, und ihr grünes Ergebnis sagte nichts über den.

const SCHWARZ = [16, 16, 24];
const ROT = [220, 40, 40];
const GRUEN = [40, 200, 90];
const BLAU = [50, 90, 230];
export const PALETTE = [SCHWARZ, ROT, GRUEN, BLAU];

/** Eine Fläche aus Farbindizes, mit einem Rechteck darin. */
function flaeche(w, h, grund, rechteck) {
  const out = new Array(w * h).fill(grund);
  if (rechteck) {
    const [x0, y0, bw, bh, farbe] = rechteck;
    for (let y = y0; y < y0 + bh; y += 1) {
      for (let x = x0; x < x0 + bw; x += 1) {
        if (x >= 0 && x < w && y >= 0 && y < h) out[y * w + x] = farbe;
      }
    }
  }
  return out;
}

export function faelle() {
  return [
    {
      name: 'fünf Bilder, gleich lang',
      spec: {
        width: 32,
        height: 32,
        palette: PALETTE,
        frames: [0, 1, 2, 3, 4].map((i) => ({
          indices: flaeche(32, 32, 0, [i * 5, i * 4, 8, 8, (i % 3) + 1]),
          delayCs: 10,
        })),
      },
    },
    {
      name: 'ein einzelnes Bild',
      spec: {
        width: 16,
        height: 16,
        palette: PALETTE,
        frames: [{ indices: flaeche(16, 16, 2, [2, 2, 6, 9, 1]) }],
      },
    },
    {
      name: 'verschränkt',
      spec: {
        width: 32,
        height: 32,
        palette: PALETTE,
        frames: [{ indices: flaeche(32, 32, 0, [4, 1, 20, 30, 3]), interlace: true }],
      },
    },
    {
      name: 'Teilbild mit Versatz',
      spec: {
        width: 32,
        height: 32,
        palette: PALETTE,
        frames: [
          { indices: flaeche(32, 32, 1, null) },
          { indices: flaeche(8, 8, 2, null), left: 12, top: 5, width: 8, height: 8 },
        ],
      },
    },
    {
      name: 'durchsichtig, Vorbild bleibt',
      spec: {
        width: 16,
        height: 16,
        palette: PALETTE,
        frames: [
          { indices: flaeche(16, 16, 1, null) },
          // Index 0 ist durchsichtig: darunter muss Rot stehenbleiben.
          { indices: flaeche(16, 16, 0, [4, 4, 4, 4, 2]), transparent: 0, dispose: 1 },
        ],
      },
    },
    {
      name: 'danach räumen (dispose 2)',
      spec: {
        width: 16,
        height: 16,
        palette: PALETTE,
        frames: [
          { indices: flaeche(16, 16, 3, null) },
          { indices: flaeche(6, 6, 1, null), left: 2, top: 2, width: 6, height: 6, dispose: 2 },
          { indices: flaeche(16, 16, 0, null), transparent: 0, dispose: 1 },
        ],
      },
    },
    {
      name: 'danach zurück (dispose 3)',
      spec: {
        width: 16,
        height: 16,
        palette: PALETTE,
        frames: [
          { indices: flaeche(16, 16, 2, null) },
          { indices: flaeche(8, 8, 1, null), left: 0, top: 0, width: 8, height: 8, dispose: 3 },
          { indices: flaeche(16, 16, 0, null), transparent: 0, dispose: 1 },
        ],
      },
    },
    {
      name: 'eigene Farbtabelle je Bild',
      spec: {
        width: 16,
        height: 16,
        palette: PALETTE,
        frames: [
          { indices: flaeche(16, 16, 1, null) },
          {
            indices: flaeche(16, 16, 1, null),
            palette: [
              [0, 0, 0],
              [7, 199, 255],
            ],
          },
        ],
      },
    },
    {
      name: 'ungleiche Verzögerungen',
      spec: {
        width: 8,
        height: 8,
        palette: PALETTE,
        frames: [
          { indices: flaeche(8, 8, 1, null), delayCs: 4 },
          { indices: flaeche(8, 8, 2, null), delayCs: 4 },
          { indices: flaeche(8, 8, 3, null), delayCs: 100 },
        ],
      },
    },
    {
      name: 'grosse Fläche, lange LZW-Ketten',
      spec: {
        width: 64,
        height: 64,
        palette: PALETTE,
        frames: [
          {
            // Viele gleiche Zeilen: genau das lässt das Wörterbuch wachsen und
            // die Codebreite mehrfach springen.
            indices: flaeche(64, 64, 2, [8, 8, 48, 48, 1]),
          },
        ],
      },
    },
  ];
}
