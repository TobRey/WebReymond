// Ein GIF in einzelne Bilder zerlegen.
//
// WARUM ES DIESE DATEI GIBT
// Wer eine Bewegung zeichnet, hat am Ende meist ein GIF – aus Aseprite, aus
// Piskel, aus einem Anhang. Fünf einzelne PNG hochzuladen ist dagegen
// Fleissarbeit, bei der man sich in der Reihenfolge vertut. Das GIF ist die
// Form, in der die Arbeit sowieso schon vorliegt.
//
// Der Browser kann von einem GIF nur das Ganze anzeigen, nicht die Bilder
// darin einzeln herausgeben: <img> spielt es ab, drawImage() erwischt je nach
// Zufall irgendeines, und ImageDecoder gibt es auf iOS nicht. Wer die Bilder
// einzeln braucht, muss das Format selbst lesen. Das ist der Grund für diese
// Datei und für ihre Länge.
//
// Kein DOM, keine Abhängigkeiten – damit test/gif.test.mjs sie direkt in Node
// laden kann. Herausgegeben werden fertige RGBA-Flächen; wer daraus ein Bild
// machen will, malt sie in einen Canvas.

/** Ein Bild ist erst vollständig, wenn die Vorgänger darunter liegen. */
const DISPOSE_BACKGROUND = 2;
const DISPOSE_PREVIOUS = 3;

class Reader {
  constructor(bytes) {
    this.bytes = bytes;
    this.pos = 0;
  }
  byte() {
    if (this.pos >= this.bytes.length) throw new Error('GIF endet mitten im Satz');
    return this.bytes[this.pos++];
  }
  short() {
    return this.byte() | (this.byte() << 8);
  }
  take(count) {
    if (this.pos + count > this.bytes.length) throw new Error('GIF endet mitten im Satz');
    const out = this.bytes.subarray(this.pos, this.pos + count);
    this.pos += count;
    return out;
  }
  /**
   * Die Blockkette: Länge, Daten, Länge, Daten … bis zu einer Länge von null.
   * So sind im GIF sowohl die Bilddaten als auch jede Zusatzangabe abgelegt.
   */
  blocks() {
    const teile = [];
    let laenge = this.byte();
    let gesamt = 0;
    while (laenge !== 0) {
      const teil = this.take(laenge);
      teile.push(teil);
      gesamt += laenge;
      laenge = this.byte();
    }
    const out = new Uint8Array(gesamt);
    let ziel = 0;
    for (const teil of teile) {
      out.set(teil, ziel);
      ziel += teil.length;
    }
    return out;
  }
  skipBlocks() {
    let laenge = this.byte();
    while (laenge !== 0) {
      this.take(laenge);
      laenge = this.byte();
    }
  }
}

/** Farbtabelle: je drei Bytes rot, grün, blau. */
function readTable(reader, eintraege) {
  const table = new Uint8Array(eintraege * 3);
  table.set(reader.take(eintraege * 3));
  return table;
}

/**
 * LZW, wie GIF es benutzt – nicht ganz wie sonst überall.
 *
 * Zwei Eigenheiten, die man kennen muss, sonst kommt Rauschen heraus:
 * die Bits stehen von der niedrigsten Stelle aufwärts, und die Codebreite
 * wächst mit dem Wörterbuch mit und wird bei jedem Löschcode zurückgesetzt.
 */
function lzw(minCodeSize, data, erwartet) {
  const clear = 1 << minCodeSize;
  const ende = clear + 1;

  const praefix = new Int32Array(4096);
  const letzter = new Uint8Array(4096);
  const laenge = new Int32Array(4096);
  for (let i = 0; i < clear; i += 1) {
    praefix[i] = -1;
    letzter[i] = i;
    laenge[i] = 1;
  }

  const out = new Uint8Array(erwartet);
  let geschrieben = 0;

  let codeSize = minCodeSize + 1;
  let naechster = ende + 1;
  let vorher = -1;

  let bitPos = 0;
  const bits = data.length * 8;
  const stapel = new Uint8Array(4096);

  while (bitPos + codeSize <= bits && geschrieben < erwartet) {
    // Code herauslesen, niedrigste Stelle zuerst.
    let code = 0;
    for (let i = 0; i < codeSize; i += 1) {
      const stelle = bitPos + i;
      const bit = (data[stelle >> 3] >> (stelle & 7)) & 1;
      code |= bit << i;
    }
    bitPos += codeSize;

    if (code === clear) {
      codeSize = minCodeSize + 1;
      naechster = ende + 1;
      vorher = -1;
      continue;
    }
    if (code === ende) break;

    let eintrag = code;
    if (code >= naechster) {
      // Der Sonderfall: ein Code, der erst durch sich selbst entsteht.
      if (vorher < 0) throw new Error('GIF: Code ohne Vorgänger');
      eintrag = vorher;
    }

    // Die Kette rückwärts einsammeln und vorwärts ausgeben.
    let tiefe = 0;
    let lauf = eintrag;
    while (lauf >= 0) {
      stapel[tiefe++] = letzter[lauf];
      lauf = praefix[lauf];
    }
    if (code >= naechster) stapel[tiefe++] = stapel[tiefe - 2] ?? stapel[0];
    for (let i = tiefe - 1; i >= 0 && geschrieben < erwartet; i -= 1) {
      out[geschrieben++] = stapel[i];
    }

    if (vorher >= 0 && naechster < 4096) {
      praefix[naechster] = vorher;
      letzter[naechster] = stapel[tiefe - 1];
      laenge[naechster] = laenge[vorher] + 1;
      naechster += 1;
      if ((naechster & (naechster - 1)) === 0 && naechster < 4096 && codeSize < 12) {
        codeSize += 1;
      }
    }
    vorher = code < naechster ? code : naechster - 1;
  }

  return out;
}

/** Zeilenreihenfolge eines verschränkten Bildes: 0,8 → 4,8 → 2,4 → 1,2. */
function deinterlace(zeilen, hoehe) {
  const out = new Int32Array(hoehe);
  let ziel = 0;
  for (const [start, schritt] of [
    [0, 8],
    [4, 8],
    [2, 4],
    [1, 2],
  ]) {
    for (let y = start; y < hoehe; y += schritt) out[ziel++] = y;
  }
  return zeilen ? out : null;
}

/**
 * Zerlegt ein GIF.
 *
 * @param {ArrayBuffer|Uint8Array} quelle
 * @returns {{width: number, height: number,
 *   frames: Array<{pixels: Uint8ClampedArray, delayMs: number}>}}
 */
export function decodeGif(quelle) {
  const bytes = quelle instanceof Uint8Array ? quelle : new Uint8Array(quelle);
  const reader = new Reader(bytes);

  const kopf = String.fromCharCode(...reader.take(6));
  if (kopf !== 'GIF87a' && kopf !== 'GIF89a') throw new Error('Das ist keine GIF-Datei.');

  const width = reader.short();
  const height = reader.short();
  const flags = reader.byte();
  reader.byte(); // Hintergrundfarbe – für uns immer durchsichtig
  reader.byte(); // Seitenverhältnis, seit Jahrzehnten unbenutzt

  let globalTable = null;
  if (flags & 0x80) globalTable = readTable(reader, 1 << ((flags & 0x07) + 1));

  if (width <= 0 || height <= 0 || width > 4096 || height > 4096) {
    throw new Error(`Ungewöhnliche Masse: ${width} × ${height}`);
  }

  const frames = [];
  // Die Leinwand: hier steht immer das zuletzt fertige Bild.
  const leinwand = new Uint8ClampedArray(width * height * 4);

  let delayMs = 100;
  let transparent = -1;
  let dispose = 0;

  for (;;) {
    let marke;
    try {
      marke = reader.byte();
    } catch {
      break; // Datei ohne Schlussmarke: was da ist, gilt.
    }

    if (marke === 0x3b) break; // Ende
    if (marke === 0x21) {
      const art = reader.byte();
      if (art === 0xf9) {
        reader.byte(); // Blocklänge, immer 4
        const f = reader.byte();
        delayMs = reader.short() * 10;
        const t = reader.byte();
        reader.byte(); // Blockende
        transparent = f & 0x01 ? t : -1;
        dispose = (f >> 2) & 0x07;
        if (delayMs === 0) delayMs = 100; // 0 heisst „so schnell es geht"
      } else {
        reader.skipBlocks();
      }
      continue;
    }
    if (marke !== 0x2c) continue; // Unbekanntes überspringen statt abbrechen

    // --- Ein Bild ---------------------------------------------------------
    const left = reader.short();
    const top = reader.short();
    const bildW = reader.short();
    const bildH = reader.short();
    const bildFlags = reader.byte();

    let tabelle = globalTable;
    if (bildFlags & 0x80) tabelle = readTable(reader, 1 << ((bildFlags & 0x07) + 1));
    if (tabelle === null) throw new Error('GIF ohne Farbtabelle');

    const verschraenkt = (bildFlags & 0x40) !== 0;
    const minCodeSize = reader.byte();
    const daten = reader.blocks();
    const indizes = lzw(minCodeSize, daten, bildW * bildH);

    // Für „danach wiederherstellen" den Stand vorher aufheben.
    const vorher = dispose === DISPOSE_PREVIOUS ? leinwand.slice() : null;

    const zeilen = verschraenkt ? deinterlace(true, bildH) : null;
    for (let y = 0; y < bildH; y += 1) {
      const zielY = top + (zeilen === null ? y : zeilen[y]);
      if (zielY < 0 || zielY >= height) continue;
      for (let x = 0; x < bildW; x += 1) {
        const zielX = left + x;
        if (zielX < 0 || zielX >= width) continue;
        const index = indizes[y * bildW + x];
        if (index === transparent) continue; // durchsichtig: darunter bleibt stehen
        const q = index * 3;
        const z = (zielY * width + zielX) * 4;
        leinwand[z] = tabelle[q];
        leinwand[z + 1] = tabelle[q + 1];
        leinwand[z + 2] = tabelle[q + 2];
        leinwand[z + 3] = 255;
      }
    }

    frames.push({ pixels: leinwand.slice(), delayMs });

    if (dispose === DISPOSE_BACKGROUND) {
      // Nur der Bereich dieses Bildes wird geräumt, nicht die ganze Fläche.
      for (let y = top; y < Math.min(top + bildH, height); y += 1) {
        for (let x = left; x < Math.min(left + bildW, width); x += 1) {
          leinwand.fill(0, (y * width + x) * 4, (y * width + x) * 4 + 4);
        }
      }
    } else if (dispose === DISPOSE_PREVIOUS && vorher !== null) {
      leinwand.set(vorher);
    }

    delayMs = 100;
    transparent = -1;
    dispose = 0;
  }

  if (frames.length === 0) throw new Error('Die GIF-Datei enthält kein Bild.');
  return { width, height, frames };
}

/**
 * Wählt aus beliebig vielen Bildern genau `anzahl` aus.
 *
 * Zwei Fälle, und sie brauchen verschiedene Regeln:
 *
 * BIS ZU `anzahl` BILDER – der Reihe nach, jedes mindestens einmal. Es wäre
 * verlockend, auch hier nach Zeit zu gehen, aber das geht schief: ein GIF aus
 * drei Bildern, dessen letztes eine Sekunde steht und dessen erste beiden je
 * 40 ms dauern, besteht zeitlich zu 93 % aus dem letzten Bild. Nach Zeit
 * ausgewählt bekäme man fünfmal dasselbe – aus einer Bewegung würde ein
 * Standbild. Ein gezeichnetes Bild wegzuwerfen ist immer falsch; die Abstände
 * kann das Spiel ohnehin nicht übernehmen, es läuft je Bewegung im festen Takt.
 *
 * MEHR ALS `anzahl` BILDER – dann muss ausgewählt werden, und zwar nach ZEIT.
 * Wer hier stur jedes n-te nimmt, gewichtet ein Bild, das nur aufblitzt,
 * genauso wie eines, das eine halbe Sekunde steht. Nach Zeit ausgewählt sieht
 * das Ergebnis aus wie das GIF; nach Position sieht es nur ähnlich aus.
 *
 * @param {Array<{delayMs: number}>} frames
 * @param {number} anzahl
 * @returns {number[]} Positionen in `frames`, `anzahl` Stück
 */
export function pickEvenlyInTime(frames, anzahl) {
  if (frames.length <= anzahl) {
    // Gleichmässig gedehnt: keines faellt weg, keines kommt doppelt so oft
    // vor wie nötig.
    return Array.from({ length: anzahl }, (_, i) => Math.floor((i * frames.length) / anzahl));
  }

  const enden = [];
  let summe = 0;
  for (const frame of frames) {
    summe += Math.max(1, frame.delayMs);
    enden.push(summe);
  }

  const out = [];
  for (let i = 0; i < anzahl; i += 1) {
    // Die Mitte des i-ten Abschnitts, damit weder Anfang noch Ende
    // doppelt gewichtet werden.
    const zeit = (summe * (i + 0.5)) / anzahl;
    let index = enden.findIndex((ende) => zeit < ende);
    if (index < 0) index = frames.length - 1;
    out.push(index);
  }
  return out;
}
