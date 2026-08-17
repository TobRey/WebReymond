import cors from '@fastify/cors';
import helmet from '@fastify/helmet';
import rateLimit from '@fastify/rate-limit';
import Fastify, { type FastifyError, type FastifyInstance } from 'fastify';
import { ApiErrorCode, type ApiError } from '@webheaven/shared';
import type { Config } from './config.js';
import { registerHealthRoutes } from './routes/health.js';

/**
 * Baut den Server auf, ohne ihn zu starten.
 *
 * Diese Trennung erlaubt Tests über `app.inject()` – ohne echten Port,
 * ohne Wartezeiten und ohne Portkonflikte in der CI.
 */
export async function buildServer(config: Config): Promise<FastifyInstance> {
  const app = Fastify({
    logger: {
      level: config.LOG_LEVEL,
      /**
       * Secret-Filter: Diese Felder werden nie ins Log geschrieben.
       * Ein Token in einer Logdatei ist ein Token in fremden Händen.
       */
      redact: {
        paths: [
          'req.headers.authorization',
          'req.headers.cookie',
          'req.headers["x-api-key"]',
          'res.headers["set-cookie"]',
          '*.password',
          '*.token',
          '*.secret',
          '*.apiKey',
        ],
        censor: '[entfernt]',
      },
    },
    // Hinter nginx: die echte Client-IP aus X-Forwarded-For übernehmen,
    // sonst zählen Rate-Limits alle Anfragen auf dieselbe Adresse.
    trustProxy: true,
  });

  // Sicherheitsheader (CSP, nosniff, Referrer-Policy, HSTS in Produktion).
  await app.register(helmet, {
    contentSecurityPolicy: {
      directives: {
        defaultSrc: ["'none'"],
        frameAncestors: ["'none'"],
      },
    },
  });

  // Nur das Portal darf im Browser auf die API zugreifen.
  await app.register(cors, {
    origin: [config.CORS_ORIGIN],
    credentials: true,
  });

  // Grundschutz gegen Brute Force und Überlast. Strengere Limits je Endpunkt folgen in Phase 4.
  await app.register(rateLimit, {
    max: 100,
    timeWindow: '1 minute',
  });

  await registerHealthRoutes(app, config);

  /**
   * Einheitliche Fehlerantworten. Interne Details bleiben im Log,
   * nach aussen geht nur eine allgemeine Meldung plus die Request-ID.
   */
  app.setErrorHandler((error: FastifyError, request, reply) => {
    const status = error.statusCode ?? 500;

    if (status >= 500) {
      request.log.error({ err: error }, 'Unbehandelter Fehler');
    }

    const body: ApiError = {
      error: {
        code: status === 429 ? ApiErrorCode.RATE_LIMITED : mapStatusToCode(status),
        message: status >= 500 ? 'Interner Fehler.' : error.message,
        requestId: request.id,
      },
    };

    void reply.status(status).send(body);
  });

  app.setNotFoundHandler((request, reply) => {
    const body: ApiError = {
      error: {
        code: ApiErrorCode.NOT_FOUND,
        message: 'Nicht gefunden.',
        requestId: request.id,
      },
    };
    void reply.status(404).send(body);
  });

  return app;
}

function mapStatusToCode(status: number): ApiError['error']['code'] {
  switch (status) {
    case 400:
      return ApiErrorCode.BAD_REQUEST;
    case 401:
      return ApiErrorCode.UNAUTHORIZED;
    case 403:
      return ApiErrorCode.FORBIDDEN;
    case 404:
      return ApiErrorCode.NOT_FOUND;
    case 429:
      return ApiErrorCode.RATE_LIMITED;
    default:
      return ApiErrorCode.INTERNAL;
  }
}
