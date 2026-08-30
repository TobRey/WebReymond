import { TRANSPARENT_KEY, type Sprite, type SpritePalette } from '@webheaven/shared';

/**
 * Ein eigenes Bild in ein Zeichenraster übersetzen.
 *
 * Das passiert vollständig im Browser: keine Anfrage, keine Kosten.
 *
 * Wichtigster Punkt: Pixel-Art soll unverändert bleiben. Solche Bilder sind
 * fast immer vergrössert exportiert – ein 14 × 50 grosser Sprite liegt als
 * 140 × 500 grosse Datei vor, jeder Bildpunkt als 10 × 10 grosser Block.
 * Wird dieses Raster erkannt, übernimmt das Werkzeug die Vorlage Punkt für
 * Punkt. Nur wenn sie nicht ins Raster passt – etwa bei einem Foto – wird
 * gerechnet.
 */

/** Grösser als das braucht niemand, um daraus 64 Pixel zu machen. */
const MAX_QUELLE = 2048;

/** Zeichen, die als Palettenschlüssel dienen. Der Punkt fehlt mit Absicht. */
const SCHLUESSEL = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789+*%$#@';

/** Ab dieser Deckkraft gilt ein Pixel als vorhanden. */
const DECKKRAFT = 128;

/** Ein Bild samt erkanntem Raster. */
export interface Raster {
  daten: ImageData;
  breite: number;
  hoehe: number;
  /** Kantenlänge eines Bildpunkts der Vorlage – 1, wenn es kein Raster gibt. */
  block: number;
  /** Grösse in echten Bildpunkten: breite/block × hoehe/block. */
  nativBreite: number;
  nativHoehe: number;
}

export interface LoadedImage {
  /** Das ganze Bild, so wie es hochgeladen wurde. */
  voll: Raster;
  /** Dasselbe ohne durchsichtigen Rand. */
  eng: Raster;
}

function kontext(breite: number, hoehe: number): CanvasRenderingContext2D {
  const flaeche = document.createElement('canvas');
  flaeche.width = breite;
  flaeche.height = hoehe;
  const ctx = flaeche.getContext('2d', { willReadFrequently: true });
  if (!ctx) throw new Error('Der Browser kann keine Bilder verarbeiten.');
  return ctx;
}

export async function loadImage(datei: File): Promise<LoadedImage> {
  const bitmap = await createImageBitmap(datei);

  const faktor = Math.min(1, MAX_QUELLE / Math.max(bitmap.width, bitmap.height));
  const breite = Math.max(1, Math.round(bitmap.width * faktor));
  const hoehe = Math.max(1, Math.round(bitmap.height * faktor));

  const ctx = kontext(breite, hoehe);
  ctx.drawImage(bitmap, 0, 0, breite, hoehe);
  bitmap.close();

  const daten = ctx.getImageData(0, 0, breite, hoehe);
  return { voll: rastererkennung(daten), eng: rastererkennung(beschneiden(daten)) };
}

/** Durchsichtigen Rand abschneiden. */
function beschneiden(daten: ImageData): ImageData {
  let links = daten.width;
  let rechts = -1;
  let oben = daten.height;
  let unten = -1;

  for (let y = 0; y < daten.height; y += 1) {
    for (let x = 0; x < daten.width; x += 1) {
      if ((daten.data[(y * daten.width + x) * 4 + 3] as number) < DECKKRAFT) continue;
      if (x < links) links = x;
      if (x > rechts) rechts = x;
      if (y < oben) oben = y;
      if (y > unten) unten = y;
    }
  }

  // Bild ohne durchsichtige Stellen (etwa ein Foto): nichts abzuschneiden.
  if (rechts < 0) return daten;

  const breite = rechts - links + 1;
  const hoehe = unten - oben + 1;
  if (breite === daten.width && hoehe === daten.height) return daten;

  const ziel = kontext(breite, hoehe).createImageData(breite, hoehe);
  for (let y = 0; y < hoehe; y += 1) {
    const von = ((y + oben) * daten.width + links) * 4;
    ziel.data.set(daten.data.subarray(von, von + breite * 4), y * breite * 4);
  }
  return ziel;
}

/** Sucht die Kantenlänge, in der das Bild aus einfarbigen Blöcken besteht. */
function rastererkennung(daten: ImageData): Raster {
  const grenze = Math.min(64, Math.floor(daten.width / 4), Math.floor(daten.height / 4));

  for (let block = grenze; block >= 2; block -= 1) {
    if (daten.width % block !== 0 || daten.height % block !== 0) continue;
    if (bloeckeEinfarbig(daten, block)) {
      return {
        daten,
        breite: daten.width,
        hoehe: daten.height,
        block,
        nativBreite: daten.width / block,
        nativHoehe: daten.height / block,
      };
    }
  }

  return {
    daten,
    breite: daten.width,
    hoehe: daten.height,
    block: 1,
    nativBreite: daten.width,
    nativHoehe: daten.height,
  };
}

function bloeckeEinfarbig(daten: ImageData, block: number): boolean {
  const werte = daten.data;

  for (let y = 0; y < daten.height; y += block) {
    for (let x = 0; x < daten.width; x += block) {
      const erster = (y * daten.width + x) * 4;
      for (let dy = 0; dy < block; dy += 1) {
        for (let dx = 0; dx < block; dx += 1) {
          const hier = ((y + dy) * daten.width + x + dx) * 4;
          for (let k = 0; k < 4; k += 1) {
            if (werte[hier + k] !== werte[erster + k]) return false;
          }
        }
      }
    }
  }

  return true;
}

/**
 * Die Grösse, die am besten zum Bild passt.
 *
 * Erste Wahl ist immer eine, in die die Vorlage unverändert hineinpasst –
 * dann muss kein einziger Bildpunkt neu berechnet werden.
 */
export function bestSize<T extends { width: number; height: number }>(
  bild: LoadedImage,
  groessen: readonly T[],
): T {
  const passend = (raster: Raster): T | null => {
    let beste: T | null = null;
    for (const groesse of groessen) {
      if (raster.nativBreite > groesse.width || raster.nativHoehe > groesse.height) continue;
      if (!beste || groesse.width * groesse.height < beste.width * beste.height) beste = groesse;
    }
    return beste;
  };

  // Das ganze Bild zuerst: Wer seinen Sprite mit Rand exportiert hat, soll
  // diesen Rand behalten.
  const ganz = passend(bild.voll) ?? passend(bild.eng);
  if (ganz) return ganz;

  const verhaeltnis = bild.eng.breite / bild.eng.hoehe;
  let beste = groessen[0] as T;
  let abstand = Number.POSITIVE_INFINITY;

  for (const groesse of groessen) {
    const eigen = Math.abs(Math.log(groesse.width / groesse.height) - Math.log(verhaeltnis));
    // Bei gleichem Seitenverhältnis die grössere Leinwand: mehr Bildpunkte,
    // mehr Ähnlichkeit mit der Vorlage.
    const gleichauf =
      Math.abs(eigen - abstand) <= 0.01 &&
      groesse.width * groesse.height > beste.width * beste.height;
    if (eigen < abstand - 0.01 || gleichauf) {
      abstand = Math.min(abstand, eigen);
      beste = groesse;
    }
  }

  return beste;
}

/**
 * Aus dem geladenen Bild ein Sprite machen.
 *
 * Passt die Vorlage ins Raster, wird sie unverändert übernommen. Sonst wird
 * sie so gross wie möglich hineingerechnet – mittig und unten bündig, damit
 * die Füsse auf der Grundlinie stehen.
 */
export function imageToSprite(
  bild: LoadedImage,
  breite: number,
  hoehe: number,
  maxFarben = 32,
): Sprite {
  const passt = (raster: Raster) => raster.nativBreite <= breite && raster.nativHoehe <= hoehe;
  const quelle = passt(bild.voll) ? bild.voll : bild.eng;

  const skalierung = Math.min(breite / quelle.breite, hoehe / quelle.hoehe);
  const genau = passt(quelle);
  const zielBreite = genau
    ? quelle.nativBreite
    : Math.max(1, Math.round(quelle.breite * skalierung));
  const zielHoehe = genau ? quelle.nativHoehe : Math.max(1, Math.round(quelle.hoehe * skalierung));

  const versatz = { x: Math.floor((breite - zielBreite) / 2), y: hoehe - zielHoehe };
  const daten = new Uint8ClampedArray(breite * hoehe * 4);

  if (genau) {
    uebernehmen(quelle, daten, breite, versatz);
  } else {
    verkleinern(quelle, zielBreite, zielHoehe, daten, breite, versatz);
  }

  return rasterZuSprite(daten, breite, hoehe, maxFarben);
}

/**
 * Die Vorlage Punkt für Punkt übernehmen: dieselben Pixel, dieselben Farben.
 */
function uebernehmen(
  quelle: Raster,
  ziel: Uint8ClampedArray,
  zeilenBreite: number,
  versatz: { x: number; y: number },
): void {
  const daten = quelle.daten.data;

  for (let y = 0; y < quelle.nativHoehe; y += 1) {
    for (let x = 0; x < quelle.nativBreite; x += 1) {
      const i = (y * quelle.block * quelle.breite + x * quelle.block) * 4;
      if ((daten[i + 3] as number) < DECKKRAFT) continue;

      const j = ((y + versatz.y) * zeilenBreite + (x + versatz.x)) * 4;
      ziel[j] = daten[i] as number;
      ziel[j + 1] = daten[i + 1] as number;
      ziel[j + 2] = daten[i + 2] as number;
      ziel[j + 3] = 255;
    }
  }
}

/**
 * Verkleinern durch Mitteln über ganze Blöcke.
 *
 * Der Browser könnte das selbst, aber sein Filter greift über den Block
 * hinaus und mischt an jeder Kante Zwischenfarben an – aus fünf Farben
 * würden dreissig. Hier mittelt jeder Zielpixel genau über die Quellpixel,
 * die auf ihn fallen.
 */
function verkleinern(
  quelle: Raster,
  zielBreite: number,
  zielHoehe: number,
  ziel: Uint8ClampedArray,
  zeilenBreite: number,
  versatz: { x: number; y: number },
): void {
  const daten = quelle.daten.data;

  for (let y = 0; y < zielHoehe; y += 1) {
    const y0 = Math.floor((y * quelle.hoehe) / zielHoehe);
    const y1 = Math.max(y0 + 1, Math.floor(((y + 1) * quelle.hoehe) / zielHoehe));

    for (let x = 0; x < zielBreite; x += 1) {
      const x0 = Math.floor((x * quelle.breite) / zielBreite);
      const x1 = Math.max(x0 + 1, Math.floor(((x + 1) * quelle.breite) / zielBreite));

      let summeR = 0;
      let summeG = 0;
      let summeB = 0;
      let summeA = 0;
      let anzahl = 0;

      for (let qy = y0; qy < y1; qy += 1) {
        for (let qx = x0; qx < x1; qx += 1) {
          const i = (qy * quelle.breite + qx) * 4;
          const a = daten[i + 3] as number;
          // Farben nach Deckkraft gewichten, sonst zieht ein durchsichtiger
          // Rand die Farbe der Figur nach Schwarz.
          summeR += (daten[i] as number) * a;
          summeG += (daten[i + 1] as number) * a;
          summeB += (daten[i + 2] as number) * a;
          summeA += a;
          anzahl += 1;
        }
      }

      if (summeA === 0 || summeA / anzahl < DECKKRAFT) continue;

      const j = ((y + versatz.y) * zeilenBreite + (x + versatz.x)) * 4;
      ziel[j] = Math.round(summeR / summeA);
      ziel[j + 1] = Math.round(summeG / summeA);
      ziel[j + 2] = Math.round(summeB / summeA);
      ziel[j + 3] = 255;
    }
  }
}

/** Aus fertigen Bildpunkten Palette und Zeichenraster machen. */
function rasterZuSprite(
  daten: Uint8ClampedArray,
  breite: number,
  hoehe: number,
  maxFarben: number,
): Sprite {
  // Erst zählen, welche Farben überhaupt vorkommen …
  const haeufigkeit = new Map<number, number>();
  for (let i = 0; i < daten.length; i += 4) {
    if ((daten[i + 3] as number) < DECKKRAFT) continue;
    const farbe =
      ((daten[i] as number) << 16) | ((daten[i + 1] as number) << 8) | (daten[i + 2] as number);
    haeufigkeit.set(farbe, (haeufigkeit.get(farbe) ?? 0) + 1);
  }

  if (haeufigkeit.size === 0) {
    throw new Error('Auf dem Bild ist nichts zu sehen.');
  }

  // … dann auf so wenige zusammenfassen, dass eine Palette daraus wird.
  const farben = medianCut(
    [...haeufigkeit].map(([farbe, anzahl]) => ({ farbe, anzahl })),
    maxFarben,
  );

  const palette: SpritePalette = {};
  farben.forEach((farbe, index) => {
    palette[SCHLUESSEL[index] as string] = '#' + farbe.toString(16).padStart(6, '0');
  });

  // Jede Quellfarbe bekommt den nächstgelegenen Paletteneintrag – einmal
  // ausgerechnet, danach nachgeschlagen.
  const zuordnung = new Map<number, string>();
  for (const farbe of haeufigkeit.keys()) {
    let beste = 0;
    let abstand = Number.POSITIVE_INFINITY;
    farben.forEach((kandidat, index) => {
      const d = abstandQuadrat(farbe, kandidat);
      if (d < abstand) {
        abstand = d;
        beste = index;
      }
    });
    zuordnung.set(farbe, SCHLUESSEL[beste] as string);
  }

  const zeilen: string[] = [];
  for (let y = 0; y < hoehe; y += 1) {
    let zeile = '';
    for (let x = 0; x < breite; x += 1) {
      const i = (y * breite + x) * 4;
      if ((daten[i + 3] as number) < DECKKRAFT) {
        zeile += TRANSPARENT_KEY;
        continue;
      }
      const farbe =
        ((daten[i] as number) << 16) | ((daten[i + 1] as number) << 8) | (daten[i + 2] as number);
      zeile += zuordnung.get(farbe) ?? TRANSPARENT_KEY;
    }
    zeilen.push(zeile);
  }

  return { width: breite, height: hoehe, palette, frames: [zeilen] };
}

interface Vorkommen {
  farbe: number;
  anzahl: number;
}

/**
 * Median-Cut: die Farben so lange in zwei Hälften teilen, bis genug Gruppen
 * da sind. Jede Gruppe wird zu einer Farbe der Palette.
 *
 * Hat das Bild von sich aus wenige Farben – wie fast jede Pixel-Art –, endet
 * jede Gruppe bei genau einer Farbe: Die Palette ist dann die ursprüngliche.
 */
function medianCut(vorkommen: Vorkommen[], maxFarben: number): number[] {
  let gruppen: Vorkommen[][] = [vorkommen];

  while (gruppen.length < maxFarben) {
    let index = -1;
    let groesste = 0;

    gruppen.forEach((gruppe, i) => {
      const spanne = spannweite(gruppe);
      if (gruppe.length > 1 && spanne.weite > groesste) {
        groesste = spanne.weite;
        index = i;
      }
    });

    if (index === -1) break;

    const gruppe = gruppen[index] as Vorkommen[];
    const kanal = spannweite(gruppe).kanal;
    gruppe.sort((a, b) => anteil(a.farbe, kanal) - anteil(b.farbe, kanal));
    const mitte = Math.floor(gruppe.length / 2);
    gruppen.splice(index, 1, gruppe.slice(0, mitte), gruppe.slice(mitte));
    gruppen = gruppen.filter((eintrag) => eintrag.length > 0);
  }

  return gruppen.map(mittelwert);
}

function anteil(farbe: number, kanal: number): number {
  return (farbe >> (16 - kanal * 8)) & 0xff;
}

function spannweite(gruppe: Vorkommen[]): { kanal: number; weite: number } {
  let kanal = 0;
  let weite = -1;

  for (let k = 0; k < 3; k += 1) {
    let klein = 255;
    let gross = 0;
    for (const eintrag of gruppe) {
      const wert = anteil(eintrag.farbe, k);
      if (wert < klein) klein = wert;
      if (wert > gross) gross = wert;
    }
    if (gross - klein > weite) {
      weite = gross - klein;
      kanal = k;
    }
  }

  return { kanal, weite };
}

/** Mittelwert einer Gruppe, gewichtet danach, wie oft eine Farbe vorkommt. */
function mittelwert(gruppe: Vorkommen[]): number {
  const summe = [0, 0, 0];
  let anzahl = 0;

  for (const eintrag of gruppe) {
    for (let k = 0; k < 3; k += 1) {
      summe[k] = (summe[k] as number) + anteil(eintrag.farbe, k) * eintrag.anzahl;
    }
    anzahl += eintrag.anzahl;
  }

  return (
    (Math.round((summe[0] as number) / anzahl) << 16) |
    (Math.round((summe[1] as number) / anzahl) << 8) |
    Math.round((summe[2] as number) / anzahl)
  );
}

function abstandQuadrat(a: number, b: number): number {
  const dr = anteil(a, 0) - anteil(b, 0);
  const dg = anteil(a, 1) - anteil(b, 1);
  const db = anteil(a, 2) - anteil(b, 2);
  return dr * dr + dg * dg + db * db;
}
