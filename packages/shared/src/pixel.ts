import { z } from 'zod';

/**
 * Vertrag für das Pixel-Art-Werkzeug.
 *
 * Ein Sprite besteht aus einer Palette und einem Raster aus Zeichen:
 * jedes Zeichen ist ein Schlüssel der Palette, der Punkt "." ist durchsichtig.
 * Dieses Format ist klein genug, dass ein Sprachmodell es zuverlässig
 * erzeugen kann, und exakt genug, dass daraus ein GIF wird – ohne Umweg
 * über ein Bildformat, das die Pixel wieder verwischen würde.
 */

/** Durchsichtiges Pixel. Bewusst ein Zeichen, das keine Farbe sein kann. */
export const TRANSPARENT_KEY = '.';

/** Auswählbare Bildgrössen. Der erste Eintrag ist die Vorgabe (32×64). */
export const SPRITE_SIZES = [
  { width: 32, height: 64 },
  { width: 16, height: 16 },
  { width: 32, height: 32 },
  { width: 48, height: 48 },
  { width: 64, height: 64 },
] as const;

export const DEFAULT_SPRITE_SIZE = SPRITE_SIZES[0];

/**
 * Animationen mit ihrer Bildanzahl.
 *
 * Sechs bis acht Bilder sind der Bereich, in dem eine Bewegung rund wirkt,
 * ohne dass die Erzeugung unnötig lange dauert.
 */
export const ANIMATIONS = [
  { id: 'walk', frames: 8 },
  { id: 'idle', frames: 6 },
  { id: 'jump', frames: 6 },
  { id: 'wave', frames: 6 },
] as const;

export type AnimationId = (typeof ANIMATIONS)[number]['id'];

export const animationIdSchema = z.enum(['walk', 'idle', 'jump', 'wave']);

export function framesForAnimation(id: AnimationId): number {
  return ANIMATIONS.find((animation) => animation.id === id)?.frames ?? 6;
}

/* -------------------------------------------------------------------------- */
/* Sprite                                                                     */
/* -------------------------------------------------------------------------- */

/** Palette: ein Zeichen → eine Farbe als #rrggbb. Höchstens 255 Farben. */
export const spritePaletteSchema = z
  .record(
    z.string().length(1),
    z.string().regex(/^#[0-9a-fA-F]{6}$/, 'Farbe muss als #rrggbb angegeben werden'),
  )
  .refine((palette) => Object.keys(palette).length <= 255, 'Höchstens 255 Farben');

export type SpritePalette = z.infer<typeof spritePaletteSchema>;

/**
 * Ein Bild: eine Zeile je Pixelreihe.
 *
 * Die Grenzen entsprechen der grössten erlaubten Leinwand (64 × 64). Sie
 * halten auch die Anfrage klein, mit der eine vorhandene Figur zum Animieren
 * zurückgeschickt wird.
 */
export const spriteFrameSchema = z.array(z.string().max(64)).min(1).max(64);

export type SpriteFrame = z.infer<typeof spriteFrameSchema>;

export const spriteSchema = z.object({
  width: z.number().int().min(8).max(64),
  height: z.number().int().min(8).max(64),
  palette: spritePaletteSchema,
  frames: z.array(spriteFrameSchema).min(1).max(8),
});

export type Sprite = z.infer<typeof spriteSchema>;

/* -------------------------------------------------------------------------- */
/* Anfragen                                                                   */
/* -------------------------------------------------------------------------- */

const sizeFields = {
  width: z.number().int().min(8).max(64),
  height: z.number().int().min(8).max(64),
};

/** Beschreibung der Figur. Kurz gehalten – das begrenzt auch die Kosten. */
export const promptSchema = z.string().trim().min(3).max(300);

export const characterRequestSchema = z.object({
  prompt: promptSchema,
  ...sizeFields,
});

export type CharacterRequest = z.infer<typeof characterRequestSchema>;

export const animationRequestSchema = z.object({
  prompt: promptSchema,
  animation: animationIdSchema,
  /** Die bereits erzeugte Figur – damit die Animation dieselbe Figur zeigt. */
  base: spriteSchema,
});

export type AnimationRequest = z.infer<typeof animationRequestSchema>;

/** Antwort beider Endpunkte: immer ein Sprite (eine oder mehrere Bilder). */
export const spriteResponseSchema = spriteSchema;

export type SpriteResponse = Sprite;
