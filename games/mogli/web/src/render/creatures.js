// Die drei Wesen aus der Vorgeschichte, je zwei Bilder à 24 × 24.
//
// Sie tauchen nur in den Story-Tafeln auf und werden dort gross skaliert
// gezeigt. Zwei Bilder genügen: eines mit offenen, eines mit geschlossenen
// Augen. Mehr braucht es nicht, damit sie lebendig wirken – ein Blinzeln im
// Sekundentakt reicht dem Auge völlig.
//
// Ein Zeichen ist ein Pixel, '.' ist durchsichtig, alles andere ein Schlüssel
// aus PALETTE. Jede Zeile hat exakt 24 Zeichen, jedes Bild exakt 24 Zeilen –
// test/frames.test.mjs prüft das mit.

import { paintPixels } from './sprites.js';

// prettier-ignore
export const CREATURES = {
  // Kiki: ein kleines rundes Äffchen, das viel zu viel redet.
  kiki: [
    [
      '........................',
      '........................',
      '.....00..........00.....',
      '...0oo0..........0oo0...',
      '...0oo0.00000000.0oo0...',
      '...0oo00oooooooo00oo0...',
      '....000oooooooooo000....',
      '......0oooooooooo0......',
      '......0oqqqqqqqqo0......',
      '......0q00qqqq00q0......',
      '......0q00qqqq00q0......',
      '......0qqqqvvqqqq0......',
      '......0qqq0000qqq0......',
      '.......0qqqqqqqq0.......',
      '.......0oooooooo0.......',
      '......0oooooooooo0......',
      '......0oooooooooo0.00...',
      '.......0oooooooo0.0o0...',
      '.......00oooooo00.0o0...',
      '........0o0..0o0.00.....',
      '........000..000........',
      '........................',
      '........................',
      '........................',
    ],
    [
      '........................',
      '........................',
      '....00............00....',
      '...0oo0..........0oo0...',
      '...0oo0.00000000.0oo0...',
      '...0oo00oooooooo00oo0...',
      '....000oooooooooo000....',
      '......0oooooooooo0......',
      '......0oqqqqqqqqo0......',
      '......0qqqqqqqqqq0......',
      '......0q00qqqq00q0......',
      '......0qqqqvvqqqq0......',
      '......0qq000000qq0......',
      '.......0qqqqqqqq0.......',
      '.......0oooooooo0.......',
      '......0oooooooooo0......',
      '......0oooooooooo0.00...',
      '.......0oooooooo0.0o0...',
      '.......00oooooo00.0o0...',
      '........0o0..0o0.00.....',
      '........000..000........',
      '........................',
      '........................',
      '........................',
    ],
  ],

  // Bimbo: eine breite, sehr entspannte Kröte.
  bimbo: [
    [
      '........................',
      '........................',
      '........................',
      '........00000000........',
      '......005555555500......',
      '.....05555555555550.....',
      '....0555555555555550....',
      '....0500555555005550....',
      '....050v055555v05550....',
      '....0555555555555550....',
      '....0555555555555550....',
      '...055555000000555550...',
      '...0555555555555555550..',
      '..05555555555555555550..',
      '..05ppppppppppppppp550..',
      '..05pppppppppppppppp50..',
      '..05pppppppppppppppp50..',
      '..055pppppppppppppp550..',
      '...05555pppppppp555550..',
      '...0055555555555555500..',
      '..0550..00000000..0550..',
      '..0550............0550..',
      '...000............000...',
      '........................',
    ],
    [
      '........................',
      '........................',
      '........................',
      '........00000000........',
      '......005555555500......',
      '.....05555555555550.....',
      '....0555555555555550....',
      '....0555555555555550....',
      '....0500055555000550....',
      '....0555555555555550....',
      '....0555555555555550....',
      '...055555000000555550...',
      '...0555555555555555550..',
      '..05555555555555555550..',
      '..05ppppppppppppppp550..',
      '..05pppppppppppppppp50..',
      '..05pppppppppppppppp50..',
      '..055pppppppppppppp550..',
      '...05555pppppppp555550..',
      '...0055555555555555500..',
      '..0550..00000000..0550..',
      '..0550............0550..',
      '...000............000...',
      '........................',
    ],
  ],

  // Schnuff: ein junger Tapir mit sehr grosser Nase und wenig Selbstvertrauen.
  schnuff: [
    [
      '........................',
      '........................',
      '.......000....000.......',
      '......0bb0....0bb0......',
      '......0bb000000bb0......',
      '.....00bbbbbbbbbb00.....',
      '....0bbbbbbbbbbbbbb0....',
      '....0bbbbbbbbbbbbbb0....',
      '....0bb0bbbbbbb0bbb0....',
      '....0bb0bbbbbbb0bbb0....',
      '....0bbbbbbbbbbbbbb0....',
      '....0bbbbbbbbbbbbbb0....',
      '.....0bbbbbbbbbbbb0.....',
      '.....0bbbb000bbbbb0.....',
      '......0bb0jjj0bbb0......',
      '......0bb0jjj0bbb0......',
      '.......0bb000bb0........',
      '.......0bbbbbbb0........',
      '.......0bbbbbbb0........',
      '.......0bb0.0bb0........',
      '.......0bb0.0bb0........',
      '.......000..000.........',
      '........................',
      '........................',
    ],
    [
      '........................',
      '........................',
      '........................',
      '.......000....000.......',
      '......0bb0....0bb0......',
      '......0bb000000bb0......',
      '.....00bbbbbbbbbb00.....',
      '....0bbbbbbbbbbbbbb0....',
      '....0bbbbbbbbbbbbbb0....',
      '....0bb000bbbb000bb0....',
      '....0bbbbbbbbbbbbbb0....',
      '....0bbbbbbbbbbbbbb0....',
      '.....0bbbbbbbbbbbb0.....',
      '.....0bbbb000bbbbb0.....',
      '......0bb0jjj0bbb0......',
      '......0bb0jjj0bbb0......',
      '.......0bb000bb0........',
      '.......0bbbbbbb0........',
      '.......0bbbbbbb0........',
      '.......0bb0.0bb0........',
      '.......0bb0.0bb0........',
      '.......000..000.........',
      '........................',
      '........................',
    ],
  ],
};

export const CREATURE_SIZE = 24;

/**
 * Brennt ein Wesen in ein kleines Canvas: die zwei Bilder nebeneinander.
 * Die Story-Tafel zeichnet daraus und blendet zwischen ihnen um.
 *
 * @param {keyof typeof CREATURES} name
 * @returns {HTMLCanvasElement}
 */
export function buildCreatureAtlas(name) {
  const frames = CREATURES[name];
  const canvas = document.createElement('canvas');
  canvas.width = CREATURE_SIZE * frames.length;
  canvas.height = CREATURE_SIZE;
  const ctx = canvas.getContext('2d');
  ctx.imageSmoothingEnabled = false;
  frames.forEach((rows, index) => paintPixels(ctx, rows, index * CREATURE_SIZE, 0));
  return canvas;
}
