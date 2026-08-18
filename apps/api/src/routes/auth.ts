import type { FastifyInstance, FastifyRequest } from 'fastify';
import { ApiErrorCode, roleSchema, type ApiError, type Role } from '@webreymond/shared';
import type { Auth } from '../auth/auth.js';

/** Wandelt Fastify-Header in Web-Standard-Header um. */
function toWebHeaders(request: FastifyRequest): Headers {
  const headers = new Headers();
  for (const [key, value] of Object.entries(request.headers)) {
    if (Array.isArray(value)) {
      for (const item of value) headers.append(key, item);
    } else if (value !== undefined) {
      headers.append(key, value);
    }
  }
  return headers;
}

export interface SessionUser {
  id: string;
  email: string;
  name: string;
  role: Role;
}

/**
 * Liest die Sitzung aus der Anfrage.
 *
 * Gibt `null` zurück, wenn keine gültige Sitzung vorliegt – die Entscheidung,
 * was dann passiert, trifft die aufrufende Route.
 */
export async function getSessionUser(
  auth: Auth,
  request: FastifyRequest,
): Promise<SessionUser | null> {
  const session = await auth.api.getSession({ headers: toWebHeaders(request) });
  if (!session) return null;

  // Die Rolle kommt aus der Datenbank. Ist sie unbekannt oder beschädigt,
  // gilt die niedrigste Stufe – niemals die höchste.
  const role = roleSchema.safeParse((session.user as { role?: unknown }).role);

  return {
    id: session.user.id,
    email: session.user.email,
    name: session.user.name,
    role: role.success ? role.data : 'user',
  };
}

export async function registerAuthRoutes(app: FastifyInstance, auth: Auth): Promise<void> {
  /**
   * Alle Anmelde-Endpunkte der Bibliothek unter /api/auth/*.
   *
   * Fastify und die Bibliothek sprechen unterschiedliche Dialekte: Fastify
   * arbeitet mit eigenen Objekten, die Bibliothek mit den Web-Standards
   * Request und Response. Diese Route übersetzt zwischen beiden.
   */
  app.route({
    method: ['GET', 'POST'],
    url: '/api/auth/*',
    handler: async (request, reply) => {
      const url = new URL(request.url, `${request.protocol}://${request.hostname}`);

      const init: RequestInit = {
        method: request.method,
        headers: toWebHeaders(request),
      };

      if (request.method !== 'GET' && request.body !== undefined && request.body !== null) {
        init.body = typeof request.body === 'string' ? request.body : JSON.stringify(request.body);
      }

      const response = await auth.handler(new Request(url, init));

      // Set-Cookie muss einzeln gesetzt werden. Würde man alle Header stumpf
      // durchreichen, verschmelzen mehrere Cookies zu einem kaputten Wert.
      const setCookies = response.headers.getSetCookie();
      for (const [key, value] of response.headers) {
        if (key.toLowerCase() !== 'set-cookie') {
          void reply.header(key, value);
        }
      }
      if (setCookies.length > 0) {
        void reply.header('set-cookie', setCookies);
      }

      void reply.status(response.status);
      return response.body ? await response.text() : null;
    },
  });

  /**
   * GET /v1/me – wer bin ich?
   *
   * Der erste geschützte Endpunkt. Ohne gültige Sitzung: 401, ohne Umwege.
   */
  app.get('/v1/me', async (request, reply) => {
    const user = await getSessionUser(auth, request);

    if (!user) {
      const body: ApiError = {
        error: {
          code: ApiErrorCode.UNAUTHORIZED,
          message: 'Nicht angemeldet.',
          requestId: request.id,
        },
      };
      return reply.status(401).send(body);
    }

    return user;
  });
}
