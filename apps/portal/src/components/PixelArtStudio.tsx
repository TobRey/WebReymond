'use client';

import { useEffect, useState } from 'react';
import { useTranslations } from 'next-intl';
import { Button, Input } from '@webheaven/ui';
import {
  ANIMATIONS,
  DEFAULT_SPRITE_SIZE,
  SPRITE_SIZES,
  type AnimationId,
  type Sprite,
} from '@webheaven/shared';
import { api } from '@/lib/api';
import { spriteToGif } from '@/lib/pixel';
import { FormMessage } from './FormMessage';

/**
 * Das Pixel-Art-Werkzeug.
 *
 * Zwei Schritte, mehr nicht: Figur beschreiben und zeichnen lassen, danach
 * eine Bewegung anklicken. Aus den Bildern entsteht das GIF direkt im
 * Browser – der Server schickt nur das Raster, kein fertiges Bild.
 */

interface Result {
  sprite: Sprite;
  url: string;
}

/** Erzeugt das GIF und gibt die Adresse zurück, unter der es der Browser kennt. */
function toResult(sprite: Sprite): Result {
  return { sprite, url: URL.createObjectURL(spriteToGif(sprite)) };
}

export function PixelArtStudio() {
  const t = useTranslations('pixelArt');

  const [prompt, setPrompt] = useState('');
  const [sizeIndex, setSizeIndex] = useState(0);
  const [character, setCharacter] = useState<Result | null>(null);
  const [animation, setAnimation] = useState<Result | null>(null);
  const [pending, setPending] = useState<'character' | AnimationId | null>(null);
  const [error, setError] = useState<string | null>(null);

  // Ein Blob bleibt im Speicher, bis er freigegeben wird. Beim Austauschen
  // und beim Verlassen der Seite geben wir das alte Bild deshalb frei.
  useEffect(() => () => URL.revokeObjectURL(character?.url ?? ''), [character]);
  useEffect(() => () => URL.revokeObjectURL(animation?.url ?? ''), [animation]);

  function reportError(status: number) {
    if (status === 0) setError(t('errors.network'));
    else if (status === 429) setError(t('errors.rateLimited'));
    else if (status === 503) setError(t('errors.unavailable'));
    else setError(t('errors.generic'));
  }

  async function createCharacter() {
    const size = SPRITE_SIZES[sizeIndex] ?? DEFAULT_SPRITE_SIZE;
    setPending('character');
    setError(null);

    const result = await api.pixelCharacter({ prompt: prompt.trim(), ...size });
    setPending(null);

    if (!result.ok || !result.data) {
      reportError(result.status);
      return;
    }

    // Eine neue Figur macht die alte Animation ungültig.
    setAnimation(null);
    setCharacter(toResult(result.data));
  }

  async function createAnimation(id: AnimationId) {
    if (!character) return;

    setPending(id);
    setError(null);

    const result = await api.pixelAnimation({
      prompt: prompt.trim(),
      animation: id,
      base: character.sprite,
    });
    setPending(null);

    if (!result.ok || !result.data) {
      reportError(result.status);
      return;
    }

    setAnimation(toResult(result.data));
  }

  const busy = pending !== null;
  const canCreate = prompt.trim().length >= 3 && !busy;

  return (
    <div className="flex flex-col gap-10">
      <section className="flex flex-col gap-4">
        <div className="max-w-xl">
          <Input
            label={t('promptLabel')}
            hint={t('promptHint')}
            placeholder={t('promptPlaceholder')}
            name="prompt"
            value={prompt}
            maxLength={300}
            autoComplete="off"
            onChange={(event) => setPrompt(event.target.value)}
          />
        </div>

        <div className="wh-field max-w-xs">
          <label className="wh-field__label" htmlFor="pixel-size">
            {t('sizeLabel')}
          </label>
          <select
            id="pixel-size"
            className="wh-input"
            value={sizeIndex}
            onChange={(event) => setSizeIndex(Number(event.target.value))}
          >
            {SPRITE_SIZES.map((size, index) => (
              <option key={`${size.width}x${size.height}`} value={index}>
                {size.width} × {size.height}
                {index === 0 ? ` ${t('sizeDefault')}` : ''}
              </option>
            ))}
          </select>
        </div>

        <div>
          <Button onClick={() => void createCharacter()} disabled={!canCreate}>
            {pending === 'character' ? t('creating') : t('create')}
          </Button>
        </div>

        {error ? <FormMessage kind="error">{error}</FormMessage> : null}
      </section>

      <section className="border-t border-border-subtle pt-8">
        <h2 className="text-xl font-semibold tracking-tight">{t('animationsTitle')}</h2>
        <p className="mt-2 mb-5 max-w-2xl text-sm text-content-muted">
          {character ? t('animationsHint') : t('animationsLocked')}
        </p>

        <div className="flex flex-wrap gap-3">
          {ANIMATIONS.map(({ id, frames }) => (
            <Button
              key={id}
              variant="secondary"
              disabled={!character || busy}
              onClick={() => void createAnimation(id)}
            >
              {pending === id ? t('creating') : `${t(`animations.${id}`)} (${frames})`}
            </Button>
          ))}
        </div>
      </section>

      <section className="grid gap-6 border-t border-border-subtle pt-8 sm:grid-cols-2">
        <Preview
          title={t('characterTitle')}
          result={character}
          empty={t('empty')}
          download={t('download')}
          fileName="figur.gif"
        />
        <Preview
          title={t('animationTitle')}
          result={animation}
          empty={t('emptyAnimation')}
          download={t('download')}
          fileName="animation.gif"
        />
      </section>
    </div>
  );
}

function Preview({
  title,
  result,
  empty,
  download,
  fileName,
}: {
  title: string;
  result: Result | null;
  empty: string;
  download: string;
  fileName: string;
}) {
  return (
    <div className="flex flex-col gap-3">
      <h3 className="text-sm font-medium text-content-muted">{title}</h3>

      <div className="flex min-h-64 items-center justify-center rounded-token-md border border-border-subtle bg-surface-muted p-4">
        {result ? (
          // Das GIF ist bereits achtfach vergrössert kodiert; "pixelated"
          // sorgt dafür, dass auch jede weitere Skalierung scharf bleibt.
          <img
            src={result.url}
            alt={title}
            className="max-h-96 w-auto"
            style={{ imageRendering: 'pixelated' }}
          />
        ) : (
          <p className="text-sm text-content-muted">{empty}</p>
        )}
      </div>

      {result ? (
        <a className="wh-button wh-button--ghost self-start" href={result.url} download={fileName}>
          {download}
        </a>
      ) : null}
    </div>
  );
}
