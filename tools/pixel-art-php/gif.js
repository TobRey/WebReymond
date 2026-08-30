/**
 * Minimaler GIF89a-Kodierer.
 *
 * Absichtlich ohne Bibliothek: Pixel-Art hat wenige Farben und feste Raster –
 * genau der Fall, für den GIF gebaut wurde. Das GIF entsteht im Browser des
 * Besuchers, der Server hat damit nichts zu tun.
 */

/**
 * @param {{width:number, height:number, palette:Array<Array<number>>, frames:Uint8Array[], delayMs:number}} options
 *   Index 0 der Farbtabelle ist durchsichtig und wird nie gezeichnet.
 */
function encodeGif(options) {
  var width = options.width;
  var height = options.height;
  var palette = options.palette;
  var frames = options.frames;

  // Die Farbtabelle muss eine Zweierpotenz sein, mindestens 4 Einträge.
  var tableSize = 4;
  while (tableSize < palette.length) tableSize *= 2;
  var colourBits = Math.log2(tableSize);

  var bytes = [];
  var byte = function (value) {
    bytes.push(value & 0xff);
  };
  // Zwei Bytes, kleinstwertiges zuerst – so schreibt GIF alle Zahlen.
  var short = function (value) {
    byte(value);
    byte(value >> 8);
  };
  var text = function (value) {
    for (var i = 0; i < value.length; i += 1) byte(value.charCodeAt(i));
  };

  text('GIF89a');
  short(width);
  short(height);
  byte(0x80 | 0x70 | (colourBits - 1)); // Farbtabelle vorhanden, Grösse
  byte(0); // Hintergrundfarbe
  byte(0); // Seitenverhältnis: quadratische Pixel

  for (var i = 0; i < tableSize; i += 1) {
    var colour = palette[i] || [0, 0, 0];
    byte(colour[0]);
    byte(colour[1]);
    byte(colour[2]);
  }

  // Endlosschleife (Netscape-Erweiterung – der De-facto-Standard dafür).
  byte(0x21);
  byte(0xff);
  byte(0x0b);
  text('NETSCAPE2.0');
  byte(0x03);
  byte(0x01);
  short(0); // 0 = unendlich oft
  byte(0);

  // GIF rechnet in Hundertstelsekunden. Unter 2 blenden Browser eigenmächtig
  // auf 10 hoch – deshalb nie darunter gehen.
  var delay = Math.max(2, Math.round(options.delayMs / 10));

  for (var f = 0; f < frames.length; f += 1) {
    byte(0x21);
    byte(0xf9);
    byte(0x04);
    byte(0x09); // Bild vorher löschen (Disposal 2) + Index 0 ist durchsichtig
    short(delay);
    byte(0); // durchsichtiger Farbindex
    byte(0);

    byte(0x2c); // Bildbeschreibung
    short(0);
    short(0);
    short(width);
    short(height);
    byte(0); // keine eigene Farbtabelle, nicht verschachtelt

    var minCodeSize = Math.max(2, colourBits);
    byte(minCodeSize);
    writeLzw(byte, minCodeSize, frames[f]);
  }

  byte(0x3b); // Ende der Datei
  return new Uint8Array(bytes);
}

/**
 * LZW-Kompression, wie GIF sie vorschreibt.
 *
 * Der Code wächst mit dem Wörterbuch von `minCodeSize + 1` auf höchstens 12
 * Bit; ist es voll, setzt ein Clear-Code alles zurück.
 */
function writeLzw(byte, minCodeSize, indices) {
  var clearCode = 1 << minCodeSize;
  var endCode = clearCode + 1;

  var nextCode = endCode + 1;
  var codeSize = minCodeSize + 1;
  var table = {};

  // Bilddaten stehen in Blöcken von höchstens 255 Bytes.
  var block = [];
  var bits = 0;
  var bitCount = 0;

  var flushBlock = function () {
    if (block.length === 0) return;
    byte(block.length);
    for (var i = 0; i < block.length; i += 1) byte(block[i]);
    block = [];
  };

  var emitByte = function (value) {
    block.push(value);
    if (block.length === 255) flushBlock();
  };

  var emitCode = function (code) {
    bits |= code << bitCount;
    bitCount += codeSize;
    while (bitCount >= 8) {
      emitByte(bits & 0xff);
      bits >>= 8;
      bitCount -= 8;
    }
  };

  emitCode(clearCode);

  var prefix = indices[0] || 0;
  for (var i = 1; i < indices.length; i += 1) {
    var pixel = indices[i];
    var key = (prefix << 8) | pixel;
    var known = table[key];

    if (known !== undefined) {
      prefix = known;
      continue;
    }

    emitCode(prefix);

    if (nextCode === 4096) {
      // Wörterbuch voll: von vorne anfangen, sonst passen die Codes nicht mehr.
      emitCode(clearCode);
      table = {};
      nextCode = endCode + 1;
      codeSize = minCodeSize + 1;
    } else {
      if (nextCode >= 1 << codeSize) codeSize += 1;
      table[key] = nextCode;
      nextCode += 1;
    }

    prefix = pixel;
  }

  emitCode(prefix);
  emitCode(endCode);
  if (bitCount > 0) emitByte(bits & 0xff);

  flushBlock();
  byte(0); // Ende der Bilddaten
}

/**
 * Aus den Zeichenrastern ein GIF bauen.
 *
 * Vergrössert wird schon beim Kodieren: Ein 32×64 grosses GIF wäre auf dem
 * Bildschirm winzig, und jede spätere Vergrösserung würde die Pixel verwischen.
 */
function bilderZuGif(palette, bilder, breite, hoehe, faktor, dauerMs) {
  var tasten = Object.keys(palette).sort();
  var farben = [[0, 0, 0]];
  var nummerVon = {};

  for (var i = 0; i < tasten.length; i += 1) {
    var hex = palette[tasten[i]];
    farben.push([
      parseInt(hex.slice(1, 3), 16),
      parseInt(hex.slice(3, 5), 16),
      parseInt(hex.slice(5, 7), 16),
    ]);
    nummerVon[tasten[i]] = i + 1;
  }

  var breiteGross = breite * faktor;
  var frames = bilder.map(function (zeilen) {
    var pixel = new Uint8Array(breiteGross * hoehe * faktor);

    for (var y = 0; y < hoehe; y += 1) {
      var zeile = zeilen[y] || '';
      for (var x = 0; x < breite; x += 1) {
        var nummer = nummerVon[zeile.charAt(x)] || 0;
        if (nummer === 0) continue;

        // Ein Quellpixel füllt ein Quadrat aus faktor × faktor Zielpixeln.
        for (var dy = 0; dy < faktor; dy += 1) {
          var anfang = (y * faktor + dy) * breiteGross + x * faktor;
          pixel.fill(nummer, anfang, anfang + faktor);
        }
      }
    }

    return pixel;
  });

  var daten = encodeGif({
    width: breiteGross,
    height: hoehe * faktor,
    palette: farben,
    frames: frames,
    delayMs: dauerMs,
  });

  return new Blob([daten], { type: 'image/gif' });
}
