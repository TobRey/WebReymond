import { z } from 'zod';

/* -------------------------------------------------------------------------- */
/* Fehlerformat                                                               */
/* -------------------------------------------------------------------------- */

/**
 * Ein einheitliches Fehlerformat für die gesamte API.
 *
 * Wichtig: Fehlermeldungen sind bewusst allgemein gehalten. Sie verraten nie,
 * ob ein Konto existiert, welcher Pfad auf dem Server liegt oder was eine
 * Datenbank gemeldet hat – solche Details landen nur im Log, nie beim Aufrufer.
 */
export const ApiErrorCode = {
  BAD_REQUEST: 'bad_request',
  UNAUTHORIZED: 'unauthorized',
  FORBIDDEN: 'forbidden',
  NOT_FOUND: 'not_found',
  RATE_LIMITED: 'rate_limited',
  INTERNAL: 'internal',
} as const;

export const apiErrorSchema = z.object({
  error: z.object({
    code: z.enum([
      ApiErrorCode.BAD_REQUEST,
      ApiErrorCode.UNAUTHORIZED,
      ApiErrorCode.FORBIDDEN,
      ApiErrorCode.NOT_FOUND,
      ApiErrorCode.RATE_LIMITED,
      ApiErrorCode.INTERNAL,
    ]),
    /** Kurze, für Menschen lesbare Meldung – ohne interne Details. */
    message: z.string().min(1),
    /** Verknüpft die Antwort mit dem Logeintrag, ohne Interna preiszugeben. */
    requestId: z.string().optional(),
  }),
});

export type ApiError = z.infer<typeof apiErrorSchema>;

/* -------------------------------------------------------------------------- */
/* Statusendpunkte                                                            */
/* -------------------------------------------------------------------------- */

/** Antwort von GET /health – für Monitoring und Deployment-Healthcheck. */
export const healthResponseSchema = z.object({
  status: z.literal('ok'),
  /** Version der laufenden Anwendung, damit man nach einem Deployment sieht, was läuft. */
  version: z.string().min(1),
  /** Laufzeit des Prozesses in Sekunden – verrät sofort einen stillen Neustart. */
  uptimeSeconds: z.number().nonnegative(),
});

export type HealthResponse = z.infer<typeof healthResponseSchema>;

/** Antwort von GET /v1/ping – minimaler Beispielendpunkt mit Zeitstempel. */
export const pingResponseSchema = z.object({
  message: z.literal('pong'),
  timestamp: z.iso.datetime(),
});

export type PingResponse = z.infer<typeof pingResponseSchema>;
