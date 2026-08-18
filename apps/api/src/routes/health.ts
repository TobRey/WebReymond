import type { FastifyInstance } from 'fastify';
import {
  healthResponseSchema,
  pingResponseSchema,
  type HealthResponse,
  type PingResponse,
} from '@webreymond/shared';
import type { Config } from '../config.js';

export async function registerHealthRoutes(app: FastifyInstance, config: Config): Promise<void> {
  /**
   * GET /health
   * Wird vom Deployment-Skript und von der Überwachung abgefragt.
   * Bewusst ohne Authentifizierung, aber auch ohne verwertbare Interna.
   */
  app.get('/health', async () => {
    const body: HealthResponse = {
      status: 'ok',
      version: config.version,
      uptimeSeconds: Math.round(process.uptime() * 100) / 100,
    };

    // Die eigene Antwort gegen das gemeinsame Schema prüfen: So fällt ein
    // Vertragsbruch bereits im Test auf und nicht erst beim Portal.
    return healthResponseSchema.parse(body);
  });

  /** GET /v1/ping – minimaler Beispielendpunkt der versionierten API. */
  app.get('/v1/ping', async () => {
    const body: PingResponse = {
      message: 'pong',
      timestamp: new Date().toISOString(),
    };
    return pingResponseSchema.parse(body);
  });
}
