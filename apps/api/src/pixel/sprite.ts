import Anthropic from '@anthropic-ai/sdk';
import {
  TRANSPARENT_KEY,
  framesForAnimation,
  spritePaletteSchema,
  type AnimationId,
  type Sprite,
  type SpritePalette,
} from '@webheaven/shared';

/**
 * Erzeugt Pixel-Figuren mit Claude.
 *
 * Das Modell zeichnet nicht als Bild, sondern als Raster aus Zeichen. Das ist
 * für Pixel-Art der genauere Weg: Jedes Pixel sitzt dort, wo es hingehört,
 * und nichts wird beim Skalieren weichgezeichnet.
 */

const MODEL = 'claude-opus-5';

/** Reicht für acht Bilder à 64×64 Zeichen samt Palette und Denkschritten. */
const MAX_TOKENS = 32000;

export class SpriteGenerationError extends Error {}

const ANIMATION_BRIEFS: Record<AnimationId, string> = {
  walk: 'a walk cycle seen from the side: legs and arms swing through contact, down, passing and up poses',
  idle: 'a calm idle loop: breathing, a small up and down of the body, tiny head movement',
  jump: 'a jump: crouch, take-off, rise, peak, fall, landing',
  wave: 'waving hello with one arm while the body stays mostly still',
};

export interface SpriteGenerator {
  character(input: { prompt: string; width: number; height: number }): Promise<Sprite>;
  animation(input: { prompt: string; animation: AnimationId; base: Sprite }): Promise<Sprite>;
}

function systemPrompt(
  width: number,
  height: number,
  frameCount: number,
  palette?: SpritePalette,
): string {
  const paletteRegel = palette
    ? [
        '- Use ONLY these palette keys and colours, unchanged: ' + JSON.stringify(palette),
        '- Do not invent new keys and do not change a single colour.',
      ]
    : ['- Palette keys are single characters, colours are #rrggbb. Use at most 16 colours.'];

  return [
    'You are a pixel art artist. You answer with JSON and nothing else.',
    '',
    'Answer shape:',
    '{"palette":{"a":"#rrggbb","b":"#rrggbb"},"frames":[["..a..","..b.."]]}',
    '',
    'Rules:',
    `- Exactly ${frameCount} frame(s).`,
    `- Every frame has exactly ${height} strings, every string exactly ${width} characters.`,
    `- Every character is either "${TRANSPARENT_KEY}" (transparent) or a key of the palette.`,
    ...paletteRegel,
    '- Draw a readable silhouette first: dark outline, one base tone and one shadow tone per material.',
    '- Keep the figure inside the canvas and leave a one pixel margin.',
    '- No text, no frame numbers, no background - the background stays transparent.',
    '- Output raw JSON, no markdown fences, no explanation.',
  ].join('\n');
}

function characterPrompt(prompt: string): string {
  return [
    `Draw this character as a single pixel art sprite: ${prompt}`,
    'Full body, centred, standing in a neutral pose.',
  ].join('\n');
}

function animationPrompt(input: {
  prompt: string;
  animation: AnimationId;
  base: Sprite;
  frameCount: number;
}): string {
  const baseFrame = input.base.frames[0] ?? [];
  return [
    `Here is an existing pixel art character ("${input.prompt}") as palette and grid:`,
    JSON.stringify({ palette: input.base.palette, frame: baseFrame }),
    '',
    `Animate this character: ${ANIMATION_BRIEFS[input.animation]}.`,
    `Return ${input.frameCount} frames of this character.`,
    '',
    "This is someone else's character. You are not redesigning it, you are moving it:",
    '- Copy the character pixel by pixel. Every row that the movement does not touch',
    '  must be identical to the grid above, character for character.',
    '- Do not change the outline, the shading, the face, the clothing or the size.',
    '- Move only the body parts that the pose needs, and only by a few pixels.',
    '- The animation is a loop: frame 1 must follow smoothly after the last frame.',
    '- Keep the feet on the same baseline unless the movement lifts them.',
  ].join('\n');
}

/**
 * Schneidet die JSON-Antwort aus dem Text.
 *
 * Modelle liefern gelegentlich einen Satz davor oder Codezaun-Zeichen drumherum.
 * Statt daran zu scheitern, nehmen wir den äussersten JSON-Block.
 */
export function extractJson(text: string): unknown {
  const start = text.indexOf('{');
  const end = text.lastIndexOf('}');
  if (start === -1 || end <= start) {
    throw new SpriteGenerationError('Antwort enthält kein JSON.');
  }
  try {
    return JSON.parse(text.slice(start, end + 1));
  } catch {
    throw new SpriteGenerationError('Antwort ist kein gültiges JSON.');
  }
}

/**
 * Bringt die Antwort auf exakt das erwartete Raster.
 *
 * Ein Bild, das eine Zeile zu kurz ist, wäre sonst ein harter Fehler für den
 * Benutzer. Fehlende Pixel sind durchsichtig, unbekannte Zeichen ebenfalls –
 * das Ergebnis ist im schlimmsten Fall etwas unvollständig, aber nie kaputt.
 */
export function normalizeSprite(
  value: unknown,
  options: { width: number; height: number; frameCount: number; palette?: SpritePalette },
): Sprite {
  const { width, height, frameCount } = options;
  const raw = value as { palette?: unknown; frames?: unknown };

  // Ist eine Palette vorgegeben, gilt genau diese. Erfindet das Modell eine
  // Farbe dazu, wäre das eine Änderung an der Figur – und die ist nicht
  // gewollt, wenn die Figur schon existiert.
  const palette: SpritePalette = options.palette
    ? options.palette
    : spritePaletteSchema.parse(normalizePalette(raw.palette));
  if (!Array.isArray(raw.frames) || raw.frames.length === 0) {
    throw new SpriteGenerationError('Antwort enthält keine Bilder.');
  }

  const frames = raw.frames.slice(0, frameCount).map((frame) => {
    const rows = Array.isArray(frame) ? frame.map((row) => String(row)) : [];
    return Array.from({ length: height }, (_, y) => normalizeRow(rows[y] ?? '', width, palette));
  });

  if (frames.length === 0) {
    throw new SpriteGenerationError('Antwort enthält keine Bilder.');
  }

  return { width, height, palette, frames };
}

function normalizePalette(value: unknown): SpritePalette {
  if (typeof value !== 'object' || value === null) {
    throw new SpriteGenerationError('Antwort enthält keine Palette.');
  }

  const palette: SpritePalette = {};
  for (const [key, colour] of Object.entries(value as Record<string, unknown>)) {
    // Der Punkt ist für "durchsichtig" reserviert und darf keine Farbe haben.
    if (key.length !== 1 || key === TRANSPARENT_KEY || typeof colour !== 'string') continue;
    const short = /^#([0-9a-fA-F]{3})$/.exec(colour);
    const normalized = short
      ? `#${[...(short[1] as string)].map((c) => c + c).join('')}`
      : colour.trim();
    if (/^#[0-9a-fA-F]{6}$/.test(normalized)) palette[key] = normalized.toLowerCase();
  }

  if (Object.keys(palette).length === 0) {
    throw new SpriteGenerationError('Antwort enthält keine brauchbare Palette.');
  }
  return palette;
}

function normalizeRow(row: string, width: number, palette: SpritePalette): string {
  const chars = Array.from({ length: width }, (_, x) => {
    const char = row[x] ?? TRANSPARENT_KEY;
    return char in palette ? char : TRANSPARENT_KEY;
  });
  return chars.join('');
}

async function ask(client: Anthropic, system: string, prompt: string): Promise<string> {
  // Streaming, weil acht Bilder viel Ausgabe sind: ohne Stream läuft die
  // Anfrage in das HTTP-Zeitlimit des SDK.
  const stream = client.messages.stream({
    model: MODEL,
    max_tokens: MAX_TOKENS,
    system,
    messages: [{ role: 'user', content: prompt }],
  });

  const message = await stream.finalMessage();
  const text = message.content
    .filter((block) => block.type === 'text')
    .map((block) => block.text)
    .join('');

  if (!text.trim()) throw new SpriteGenerationError('Leere Antwort des Modells.');
  return text;
}

export function createSpriteGenerator(apiKey: string): SpriteGenerator {
  const client = new Anthropic({ apiKey });

  return {
    async character({ prompt, width, height }) {
      const text = await ask(client, systemPrompt(width, height, 1), characterPrompt(prompt));
      return normalizeSprite(extractJson(text), { width, height, frameCount: 1 });
    },

    async animation({ prompt, animation, base }) {
      const frameCount = framesForAnimation(animation);
      const { width, height, palette } = base;
      const text = await ask(
        client,
        systemPrompt(width, height, frameCount, palette),
        animationPrompt({ prompt, animation, base, frameCount }),
      );
      return normalizeSprite(extractJson(text), { width, height, frameCount, palette });
    },
  };
}
