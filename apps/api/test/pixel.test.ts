import { describe, expect, it } from 'vitest';
import { SpriteGenerationError, extractJson, normalizeSprite } from '../src/pixel/sprite.js';

/**
 * Tests für das Aufbereiten der Modellantwort.
 *
 * Sprachmodelle halten sich fast immer an das Format – "fast" reicht hier
 * nicht: Eine Zeile zu kurz darf nie zu einem Fehler beim Benutzer führen.
 */

const options = { width: 4, height: 3, frameCount: 2 };

describe('extractJson', () => {
  it('findet das JSON auch zwischen Text und Codezaun', () => {
    const text = 'Hier ist deine Figur:\n```json\n{"palette":{"a":"#ff0000"}}\n```\nViel Spass!';
    expect(extractJson(text)).toEqual({ palette: { a: '#ff0000' } });
  });

  it('meldet einen Fehler, wenn gar kein JSON da ist', () => {
    expect(() => extractJson('Das kann ich leider nicht.')).toThrow(SpriteGenerationError);
  });

  it('meldet einen Fehler bei kaputtem JSON', () => {
    expect(() => extractJson('{"palette": ')).toThrow(SpriteGenerationError);
  });
});

describe('normalizeSprite', () => {
  it('bringt jedes Bild auf das erwartete Raster', () => {
    const sprite = normalizeSprite(
      {
        palette: { a: '#ff0000', b: '#00FF00' },
        frames: [
          ['ab', 'aabbaa', 'ab..'], // zu kurz, zu lang, genau richtig
          ['....', '....', '....'],
        ],
      },
      options,
    );

    expect(sprite.frames[0]).toEqual(['ab..', 'aabb', 'ab..']);
    expect(sprite.width).toBe(4);
    expect(sprite.height).toBe(3);
  });

  it('ergänzt fehlende Zeilen als durchsichtig', () => {
    const sprite = normalizeSprite({ palette: { a: '#ff0000' }, frames: [['aaaa']] }, options);
    expect(sprite.frames[0]).toEqual(['aaaa', '....', '....']);
  });

  it('ersetzt unbekannte Zeichen durch durchsichtig', () => {
    const sprite = normalizeSprite({ palette: { a: '#ff0000' }, frames: [['aXa?']] }, options);
    expect(sprite.frames[0]?.[0]).toBe('a.a.');
  });

  it('nimmt höchstens so viele Bilder wie angefordert', () => {
    const frame = ['aaaa', 'aaaa', 'aaaa'];
    const sprite = normalizeSprite(
      { palette: { a: '#ff0000' }, frames: [frame, frame, frame, frame] },
      options,
    );
    expect(sprite.frames).toHaveLength(2);
  });

  it('versteht Kurzfarben und vereinheitlicht die Schreibweise', () => {
    const sprite = normalizeSprite({ palette: { a: '#F00' }, frames: [['aaaa']] }, options);
    expect(sprite.palette).toEqual({ a: '#ff0000' });
  });

  it('lässt den reservierten Punkt und unbrauchbare Farben weg', () => {
    const sprite = normalizeSprite(
      { palette: { a: '#ff0000', '.': '#000000', bb: '#111111', c: 'rot' }, frames: [['aaaa']] },
      options,
    );
    expect(sprite.palette).toEqual({ a: '#ff0000' });
  });

  it('meldet einen Fehler ohne Palette oder ohne Bilder', () => {
    expect(() => normalizeSprite({ frames: [['aaaa']] }, options)).toThrow(SpriteGenerationError);
    expect(() => normalizeSprite({ palette: { a: '#ff0000' } }, options)).toThrow(
      SpriteGenerationError,
    );
  });
});
