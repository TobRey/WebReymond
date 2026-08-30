import type { FastifyInstance, FastifyReply, FastifyRequest } from 'fastify';
import {
  ApiErrorCode,
  animationRequestSchema,
  characterRequestSchema,
  type ApiError,
  type Sprite,
} from '@webheaven/shared';
import type { Config } from '../config.js';
import { SpriteGenerationError, createSpriteGenerator } from '../pixel/sprite.js';

/**
 * Endpunkte des Pixel-Art-Werkzeugs.
 *
 * Beide Endpunkte kosten Geld (Modellaufrufe) und dauern mehrere Sekunden.
 * Deshalb ein deutlich strengeres Limit als der Rest der API.
 */
const RATE_LIMIT = { config: { rateLimit: { max: 10, timeWindow: '10 minutes' } } };

function fail(
  reply: FastifyReply,
  request: FastifyRequest,
  status: number,
  code: ApiError['error']['code'],
  message: string,
): FastifyReply {
  const body: ApiError = { error: { code, message, requestId: request.id } };
  return reply.status(status).send(body);
}

/** Ohne Schlüssel gibt es dieses Werkzeug auf diesem Server schlicht nicht. */
function notConfigured(request: FastifyRequest, reply: FastifyReply): FastifyReply {
  return fail(
    reply,
    request,
    503,
    ApiErrorCode.INTERNAL,
    'Das Pixel-Werkzeug ist auf diesem Server nicht eingerichtet.',
  );
}

export async function registerPixelRoutes(app: FastifyInstance, config: Config): Promise<void> {
  // Ohne Schlüssel läuft der Rest der API weiter.
  const generator = config.ANTHROPIC_API_KEY
    ? createSpriteGenerator(config.ANTHROPIC_API_KEY)
    : null;

  async function run(
    request: FastifyRequest,
    reply: FastifyReply,
    work: () => Promise<Sprite>,
  ): Promise<unknown> {
    try {
      return await work();
    } catch (error) {
      if (error instanceof SpriteGenerationError) {
        request.log.warn({ err: error }, 'Sprite konnte nicht erzeugt werden');
        return fail(
          reply,
          request,
          502,
          ApiErrorCode.INTERNAL,
          'Die Figur konnte nicht gezeichnet werden. Bitte noch einmal versuchen.',
        );
      }
      // Alles andere (Netzwerk, Kontingent, Schlüssel) geht ins Log,
      // nach aussen bleibt es allgemein.
      request.log.error({ err: error }, 'Modellaufruf fehlgeschlagen');
      return fail(
        reply,
        request,
        502,
        ApiErrorCode.INTERNAL,
        'Der Zeichendienst antwortet gerade nicht.',
      );
    }
  }

  /** POST /v1/pixel/character – eine Figur nach Beschreibung. */
  app.post('/v1/pixel/character', RATE_LIMIT, async (request, reply) => {
    if (!generator) return notConfigured(request, reply);

    const input = characterRequestSchema.safeParse(request.body);
    if (!input.success) {
      return fail(reply, request, 400, ApiErrorCode.BAD_REQUEST, 'Ungültige Eingabe.');
    }

    return run(request, reply, () => generator.character(input.data));
  });

  /** POST /v1/pixel/animation – dieselbe Figur als Bewegungsablauf. */
  app.post('/v1/pixel/animation', RATE_LIMIT, async (request, reply) => {
    if (!generator) return notConfigured(request, reply);

    const input = animationRequestSchema.safeParse(request.body);
    if (!input.success) {
      return fail(reply, request, 400, ApiErrorCode.BAD_REQUEST, 'Ungültige Eingabe.');
    }

    return run(request, reply, () => generator.animation(input.data));
  });
}
