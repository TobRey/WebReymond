/**
 * Minimaler GIF87a/89a-Decoder.
 *
 * Ein animiertes GIF laesst sich mit drawImage nicht Frame für Frame steuern -
 * der Browser entscheidet selbst, welches Bild gerade sichtbar ist. Deshalb
 * zerlegen wir die Datei einmal beim Laden in fertige Frame-Canvases. Danach
 * ist die Animation reine Index-Arithmetik und kostet im Spiel nichts mehr.
 */

export function decodeGif(buffer) {
  const bytes = new Uint8Array(buffer);
  let p = 0;

  const readByte = () => bytes[p++];
  const readShort = () => {
    const v = bytes[p] | (bytes[p + 1] << 8);
    p += 2;
    return v;
  };

  const header = String.fromCharCode(bytes[0], bytes[1], bytes[2], bytes[3], bytes[4], bytes[5]);
  if (header !== 'GIF87a' && header !== 'GIF89a') throw new Error('Keine GIF-Datei');
  p = 6;

  const width = readShort();
  const height = readShort();
  const flags = readByte();
  const bgIndex = readByte();
  readByte(); // pixel aspect ratio

  const globalTableSize = flags & 0x80 ? 2 << (flags & 0x07) : 0;
  const globalTable = globalTableSize ? readColorTable(bytes, p, globalTableSize) : null;
  p += globalTableSize * 3;

  const frames = [];
  let gce = { delay: 100, transparentIndex: -1, disposal: 0 };

  // Zwei Puffer: die zusammengesetzte Anzeige und der Zustand davor (disposal 3).
  const canvas = makeCanvas(width, height);
  const ctx = canvas.getContext('2d', { willReadFrequently: true });
  let previous = null;

  while (p < bytes.length) {
    const block = readByte();

    if (block === 0x3b) break; // Trailer

    if (block === 0x21) {
      const label = readByte();
      if (label === 0xf9) {
        readByte(); // Blockgröße (immer 4)
        const packed = readByte();
        const delay = readShort();
        const transparentIndex = readByte();
        readByte(); // Blockterminator
        gce = {
          delay: delay <= 1 ? 100 : delay * 10,
          transparentIndex: packed & 0x01 ? transparentIndex : -1,
          disposal: (packed >> 2) & 0x07,
        };
      } else {
        p = skipBlocks(bytes, p);
      }
      continue;
    }

    if (block !== 0x2c) break; // unbekannt -> abbrechen

    const fx = readShort();
    const fy = readShort();
    const fw = readShort();
    const fh = readShort();
    const packed = readByte();
    const localSize = packed & 0x80 ? 2 << (packed & 0x07) : 0;
    const interlaced = !!(packed & 0x40);
    const table = localSize ? readColorTable(bytes, p, localSize) : globalTable;
    p += localSize * 3;

    const minCodeSize = readByte();
    const { data, next } = readSubBlocks(bytes, p);
    p = next;

    const indices = lzwDecode(data, minCodeSize, fw * fh);
    const order = interlaced ? deinterlace(fh) : null;

    if (gce.disposal === 3) {
      previous = ctx.getImageData(0, 0, width, height);
    }

    // Palette einmal in fertige 32-Bit-Werte umrechnen, dann pro Pixel nur
    // noch ein Schreibvorgang statt vier - das halbiert die Dekodierzeit.
    const paletteSize = (table.length / 3) | 0;
    const rgba = new Uint32Array(paletteSize);
    for (let i = 0; i < paletteSize; i++) {
      const c = i * 3;
      rgba[i] = (255 << 24) | (table[c + 2] << 16) | (table[c + 1] << 8) | table[c];
    }

    const image = ctx.createImageData(fw, fh);
    const pix32 = new Uint32Array(image.data.buffer);
    const transparent = gce.transparentIndex;
    for (let row = 0; row < fh; row++) {
      const srcRow = order ? order[row] : row;
      const src = srcRow * fw;
      const dst = row * fw;
      for (let col = 0; col < fw; col++) {
        const index = indices[src + col];
        pix32[dst + col] = index === transparent || index >= paletteSize ? 0 : rgba[index];
      }
    }

    // Teilbild über einen Zwischen-Canvas einblenden, damit transparente
    // Pixel den bestehenden Hintergrund nicht löschen.
    const patch = makeCanvas(fw, fh);
    patch.getContext('2d').putImageData(image, 0, 0);
    ctx.drawImage(patch, fx, fy);

    const frame = makeCanvas(width, height);
    frame.getContext('2d').drawImage(canvas, 0, 0);
    frames.push({ canvas: frame, delay: gce.delay });

    if (gce.disposal === 2) {
      ctx.clearRect(fx, fy, fw, fh);
    } else if (gce.disposal === 3 && previous) {
      ctx.putImageData(previous, 0, 0);
    }
  }

  if (!frames.length) throw new Error('GIF ohne Frames');
  return { width, height, frames, bgIndex };
}

function makeCanvas(w, h) {
  const c = document.createElement('canvas');
  c.width = w;
  c.height = h;
  return c;
}

function readColorTable(bytes, offset, size) {
  return bytes.subarray(offset, offset + size * 3);
}

function skipBlocks(bytes, p) {
  let size = bytes[p++];
  while (size !== 0) {
    p += size;
    size = bytes[p++];
  }
  return p;
}

function readSubBlocks(bytes, p) {
  const parts = [];
  let total = 0;
  let size = bytes[p++];
  while (size !== 0) {
    parts.push(bytes.subarray(p, p + size));
    total += size;
    p += size;
    size = bytes[p++];
  }
  const data = new Uint8Array(total);
  let at = 0;
  for (const part of parts) {
    data.set(part, at);
    at += part.length;
  }
  return { data, next: p };
}

function deinterlace(height) {
  const rows = new Array(height);
  let at = 0;
  for (const [start, step] of [[0, 8], [4, 8], [2, 4], [1, 2]]) {
    for (let row = start; row < height; row += step) rows[at++] = row;
  }
  return rows;
}

/** Klassischer GIF-LZW: variable Codelänge, Clear- und EOI-Code. */
function lzwDecode(data, minCodeSize, pixelCount) {
  const clearCode = 1 << minCodeSize;
  const eoiCode = clearCode + 1;
  const output = new Uint8Array(pixelCount);

  const prefix = new Int32Array(4096);
  const suffix = new Uint8Array(4096);
  const stack = new Uint8Array(4096);

  let codeSize = minCodeSize + 1;
  let nextCode = eoiCode + 1;
  let mask = (1 << codeSize) - 1;
  let bitBuffer = 0;
  let bitCount = 0;
  let at = 0;
  let out = 0;
  let previousCode = -1;
  let firstChar = 0;

  for (let i = 0; i < clearCode; i++) {
    prefix[i] = 0;
    suffix[i] = i;
  }

  while (out < pixelCount) {
    while (bitCount < codeSize) {
      if (at >= data.length) return output;
      bitBuffer |= data[at++] << bitCount;
      bitCount += 8;
    }
    const code = bitBuffer & mask;
    bitBuffer >>= codeSize;
    bitCount -= codeSize;

    if (code === clearCode) {
      codeSize = minCodeSize + 1;
      mask = (1 << codeSize) - 1;
      nextCode = eoiCode + 1;
      previousCode = -1;
      continue;
    }
    if (code === eoiCode) break;

    let current = code;
    let top = 0;

    if (current >= nextCode) {
      stack[top++] = firstChar;
      current = previousCode;
    }
    while (current >= clearCode) {
      stack[top++] = suffix[current];
      current = prefix[current];
    }
    firstChar = suffix[current] || current;
    stack[top++] = firstChar;

    while (top > 0 && out < pixelCount) output[out++] = stack[--top];

    if (previousCode !== -1 && nextCode < 4096) {
      prefix[nextCode] = previousCode;
      suffix[nextCode] = firstChar;
      nextCode++;
      if ((nextCode & mask) === 0 && nextCode < 4096) {
        codeSize++;
        mask += nextCode;
      }
    }
    previousCode = code;
  }

  return output;
}
