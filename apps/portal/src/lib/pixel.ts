import { TRANSPARENT_KEY, type Sprite } from '@webheaven/shared';
import { encodeGif } from './gif';

/**
 * Vom Zeichenraster zum fertigen GIF.
 *
 * Die Bilder werden schon beim Kodieren vergrössert. Ein 32×64 grosses GIF
 * wäre auf dem Bildschirm winzig, und jede Vergrösserung im Browser oder in
 * einem anderen Programm würde die Pixel verwischen.
 */

/** Ein Pixel der Figur wird zu 8×8 Pixeln im GIF. */
export const PIXEL_SCALE = 8;

/** Anzeigedauer je Bild – etwa acht Bilder pro Sekunde. */
export const FRAME_DELAY_MS = 120;

function hexToRgb(hex: string): [number, number, number] {
  return [
    parseInt(hex.slice(1, 3), 16),
    parseInt(hex.slice(3, 5), 16),
    parseInt(hex.slice(5, 7), 16),
  ];
}

export function spriteToGif(sprite: Sprite, scale = PIXEL_SCALE): Blob {
  // Index 0 bleibt für "durchsichtig" reserviert, die Farben folgen dahinter.
  const keys = Object.keys(sprite.palette).sort();
  const palette: Array<[number, number, number]> = [
    [0, 0, 0],
    ...keys.map((key) => hexToRgb(sprite.palette[key] as string)),
  ];

  const indexOf = new Map<string, number>(keys.map((key, i) => [key, i + 1]));

  const width = sprite.width * scale;
  const height = sprite.height * scale;

  const frames = sprite.frames.map((rows) => {
    const pixels = new Uint8Array(width * height);

    for (let y = 0; y < sprite.height; y += 1) {
      const row = rows[y] ?? '';
      for (let x = 0; x < sprite.width; x += 1) {
        const char = row[x] ?? TRANSPARENT_KEY;
        const index = indexOf.get(char) ?? 0;
        if (index === 0) continue;

        // Ein Quellpixel füllt ein Quadrat aus scale × scale Zielpixeln.
        for (let dy = 0; dy < scale; dy += 1) {
          const offset = (y * scale + dy) * width + x * scale;
          pixels.fill(index, offset, offset + scale);
        }
      }
    }

    return pixels;
  });

  const data = encodeGif({ width, height, palette, frames, delayMs: FRAME_DELAY_MS });
  return new Blob([data], { type: 'image/gif' });
}
