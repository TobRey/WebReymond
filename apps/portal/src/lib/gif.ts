/**
 * Minimaler GIF89a-Kodierer.
 *
 * Absichtlich ohne Bibliothek: Pixel-Art hat wenige Farben und feste Raster –
 * genau der Fall, für den GIF gebaut wurde. Der ganze Kodierer passt in eine
 * Datei, läuft im Browser und braucht keine weitere Abhängigkeit.
 */

export interface GifOptions {
  width: number;
  height: number;
  /** Farbtabelle als [r, g, b]. Index 0 ist durchsichtig und wird nie gezeichnet. */
  palette: Array<[number, number, number]>;
  /** Ein Eintrag je Bild: width × height Farbindizes. */
  frames: Uint8Array[];
  /** Anzeigedauer je Bild in Millisekunden. */
  delayMs: number;
}

class ByteBuffer {
  private readonly bytes: number[] = [];

  byte(value: number): void {
    this.bytes.push(value & 0xff);
  }

  /** Zwei Bytes, kleinstwertiges zuerst – so schreibt GIF alle Zahlen. */
  short(value: number): void {
    this.byte(value);
    this.byte(value >> 8);
  }

  text(value: string): void {
    for (let i = 0; i < value.length; i += 1) this.byte(value.charCodeAt(i));
  }

  /** `<ArrayBuffer>` festzuhalten spart später eine Umwandlung für den Blob. */
  toUint8Array(): Uint8Array<ArrayBuffer> {
    return Uint8Array.from(this.bytes);
  }
}

export function encodeGif(options: GifOptions): Uint8Array<ArrayBuffer> {
  const { width, height, palette, frames, delayMs } = options;

  // Die Farbtabelle muss eine Zweierpotenz sein, mindestens 4 Einträge.
  let tableSize = 4;
  while (tableSize < palette.length) tableSize *= 2;
  const colourBits = Math.log2(tableSize);

  const out = new ByteBuffer();

  out.text('GIF89a');
  out.short(width);
  out.short(height);
  out.byte(0x80 | 0x70 | (colourBits - 1)); // Farbtabelle vorhanden, Grösse
  out.byte(0); // Hintergrundfarbe
  out.byte(0); // Seitenverhältnis: quadratische Pixel

  for (let i = 0; i < tableSize; i += 1) {
    const colour = palette[i] ?? [0, 0, 0];
    out.byte(colour[0]);
    out.byte(colour[1]);
    out.byte(colour[2]);
  }

  // Endlosschleife (Netscape-Erweiterung – der De-facto-Standard dafür).
  out.byte(0x21);
  out.byte(0xff);
  out.byte(0x0b);
  out.text('NETSCAPE2.0');
  out.byte(0x03);
  out.byte(0x01);
  out.short(0); // 0 = unendlich oft
  out.byte(0);

  // GIF rechnet in Hundertstelsekunden. Unter 2 blenden Browser eigenmächtig
  // auf 10 hoch – deshalb nie darunter gehen.
  const delay = Math.max(2, Math.round(delayMs / 10));

  for (const frame of frames) {
    out.byte(0x21);
    out.byte(0xf9);
    out.byte(0x04);
    out.byte(0x09); // Bild vorher löschen (Disposal 2) + Index 0 ist durchsichtig
    out.short(delay);
    out.byte(0); // durchsichtiger Farbindex
    out.byte(0);

    out.byte(0x2c); // Bildbeschreibung
    out.short(0);
    out.short(0);
    out.short(width);
    out.short(height);
    out.byte(0); // keine eigene Farbtabelle, nicht verschachtelt

    const minCodeSize = Math.max(2, colourBits);
    out.byte(minCodeSize);
    writeLzw(out, minCodeSize, frame);
  }

  out.byte(0x3b); // Ende der Datei
  return out.toUint8Array();
}

/**
 * LZW-Kompression, wie GIF sie vorschreibt.
 *
 * Der Code wächst mit dem Wörterbuch von `minCodeSize + 1` auf höchstens 12
 * Bit; ist es voll, setzt ein Clear-Code alles zurück.
 */
function writeLzw(out: ByteBuffer, minCodeSize: number, indices: Uint8Array): void {
  const clearCode = 1 << minCodeSize;
  const endCode = clearCode + 1;

  let nextCode = endCode + 1;
  let codeSize = minCodeSize + 1;
  let table = new Map<number, number>();

  // Bilddaten stehen in Blöcken von höchstens 255 Bytes.
  const block: number[] = [];
  let bits = 0;
  let bitCount = 0;

  const flushBlock = (): void => {
    if (block.length === 0) return;
    out.byte(block.length);
    for (const byte of block) out.byte(byte);
    block.length = 0;
  };

  const emitByte = (byte: number): void => {
    block.push(byte);
    if (block.length === 255) flushBlock();
  };

  const emitCode = (code: number): void => {
    bits |= code << bitCount;
    bitCount += codeSize;
    while (bitCount >= 8) {
      emitByte(bits & 0xff);
      bits >>= 8;
      bitCount -= 8;
    }
  };

  emitCode(clearCode);

  let prefix = indices[0] ?? 0;
  for (let i = 1; i < indices.length; i += 1) {
    const pixel = indices[i] as number;
    const key = (prefix << 8) | pixel;
    const known = table.get(key);

    if (known !== undefined) {
      prefix = known;
      continue;
    }

    emitCode(prefix);

    if (nextCode === 4096) {
      // Wörterbuch voll: von vorne anfangen, sonst passen die Codes nicht mehr.
      emitCode(clearCode);
      table = new Map();
      nextCode = endCode + 1;
      codeSize = minCodeSize + 1;
    } else {
      if (nextCode >= 1 << codeSize) codeSize += 1;
      table.set(key, nextCode);
      nextCode += 1;
    }

    prefix = pixel;
  }

  emitCode(prefix);
  emitCode(endCode);
  if (bitCount > 0) emitByte(bits & 0xff);

  flushBlock();
  out.byte(0); // Ende der Bilddaten
}
