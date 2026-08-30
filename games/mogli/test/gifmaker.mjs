// Ein GIF-Schreiber – nur für die Tests, er wird nicht ausgeliefert.
//
// Er ist nötig, weil in dieser Umgebung kein Werkzeug GIF erzeugen kann (das
// vorhandene ffmpeg ist auf Video zusammengestrichen, Pillow fehlt). Damit
// mein Schreiber nicht denselben Denkfehler enthält wie mein Leser und beide
// sich gegenseitig bestätigen, wird jede erzeugte Datei zusätzlich von
// Chromiums eigenem ImageDecoder gelesen – siehe tools/gif-gegenprobe.mjs.
//
// Der LZW-Teil komprimiert wirklich. Ein Schreiber, der nur Einzelzeichen
// ausgibt, wäre einfacher, würde den Leser aber nie an der interessanten
// Stelle prüfen: an wachsenden Ketten, wachsender Codebreite und dem
// Sonderfall „Code, der erst durch sich selbst entsteht".

class Bits {
  constructor() {
    this.bytes = [];
    this.wert = 0;
    this.anzahl = 0;
  }
  push(code, breite) {
    this.wert |= code << this.anzahl;
    this.anzahl += breite;
    while (this.anzahl >= 8) {
      this.bytes.push(this.wert & 0xff);
      this.wert >>= 8;
      this.anzahl -= 8;
    }
  }
  ende() {
    if (this.anzahl > 0) this.bytes.push(this.wert & 0xff);
    return this.bytes;
  }
}

/** GIF-LZW über eine Folge von Farbindizes. */
function lzwEncode(indizes, minCodeSize) {
  const clear = 1 << minCodeSize;
  const ende = clear + 1;
  const bits = new Bits();
  let breite = minCodeSize + 1;
  let naechster = ende + 1;
  const tabelle = new Map();

  bits.push(clear, breite);
  let kette = String(indizes[0]);

  for (let i = 1; i < indizes.length; i += 1) {
    const zeichen = String(indizes[i]);
    const laenger = kette + ',' + zeichen;
    if (tabelle.has(laenger) || (kette.indexOf(',') < 0 && false)) {
      kette = laenger;
      continue;
    }
    bits.push(tabelle.has(kette) ? tabelle.get(kette) : Number(kette), breite);
    if (naechster < 4096) {
      tabelle.set(laenger, naechster);
      naechster += 1;
      if (naechster > 1 << breite && breite < 12) breite += 1;
    } else {
      bits.push(clear, breite);
      tabelle.clear();
      naechster = ende + 1;
      breite = minCodeSize + 1;
    }
    kette = zeichen;
  }
  bits.push(tabelle.has(kette) ? tabelle.get(kette) : Number(kette), breite);
  bits.push(ende, breite);
  return bits.ende();
}

function blocks(bytes) {
  const out = [];
  for (let i = 0; i < bytes.length; i += 255) {
    const teil = bytes.slice(i, i + 255);
    out.push(teil.length, ...teil);
  }
  out.push(0);
  return out;
}

function short(n) {
  return [n & 0xff, (n >> 8) & 0xff];
}

/** Nächster Zweierpotenz-Exponent für eine Farbzahl (mindestens 2 Einträge). */
function tableBits(anzahl) {
  let b = 1;
  while (1 << b < anzahl) b += 1;
  return Math.max(1, b);
}

function tableBytes(palette, bits) {
  const out = [];
  for (let i = 0; i < 1 << bits; i += 1) {
    const farbe = palette[i] ?? [0, 0, 0];
    out.push(farbe[0], farbe[1], farbe[2]);
  }
  return out;
}

/**
 * Schreibt ein GIF.
 *
 * @param {{width: number, height: number, palette: number[][],
 *   frames: Array<{indices: number[], left?: number, top?: number,
 *     width?: number, height?: number, delayCs?: number, transparent?: number,
 *     dispose?: number, interlace?: boolean, palette?: number[][]}>}} spec
 * @returns {Uint8Array}
 */
export function makeGif(spec) {
  const out = [];
  const globalBits = tableBits(spec.palette.length);

  out.push(...[...'GIF89a'].map((c) => c.charCodeAt(0)));
  out.push(...short(spec.width), ...short(spec.height));
  out.push(0x80 | ((globalBits - 1) & 0x07), 0, 0);
  out.push(...tableBytes(spec.palette, globalBits));

  for (const frame of spec.frames) {
    const w = frame.width ?? spec.width;
    const h = frame.height ?? spec.height;

    // Steuerblock: Verzögerung, Durchsichtigkeit, was danach passiert
    out.push(0x21, 0xf9, 4);
    const transparent = frame.transparent ?? -1;
    out.push((((frame.dispose ?? 0) & 0x07) << 2) | (transparent >= 0 ? 1 : 0));
    out.push(...short(frame.delayCs ?? 10));
    out.push(transparent >= 0 ? transparent : 0, 0);

    out.push(0x2c);
    out.push(...short(frame.left ?? 0), ...short(frame.top ?? 0), ...short(w), ...short(h));

    let bits = globalBits;
    if (frame.palette) {
      bits = tableBits(frame.palette.length);
      out.push(0x80 | (frame.interlace ? 0x40 : 0) | ((bits - 1) & 0x07));
      out.push(...tableBytes(frame.palette, bits));
    } else {
      out.push(frame.interlace ? 0x40 : 0);
    }

    // Verschränkt heisst: die Zeilen stehen in anderer Reihenfolge in der Datei.
    let indizes = frame.indices;
    if (frame.interlace) {
      const neu = [];
      for (const [start, schritt] of [
        [0, 8],
        [4, 8],
        [2, 4],
        [1, 2],
      ]) {
        for (let y = start; y < h; y += schritt) neu.push(...indizes.slice(y * w, (y + 1) * w));
      }
      indizes = neu;
    }

    const minCodeSize = Math.max(2, bits);
    out.push(minCodeSize);
    out.push(...blocks(lzwEncode(indizes, minCodeSize)));
  }

  out.push(0x3b);
  return new Uint8Array(out);
}
