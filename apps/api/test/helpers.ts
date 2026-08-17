import type { FastifyInstance } from 'fastify';
import { loadConfig } from '../src/config.js';
import { createDb, type Db } from '../src/db.js';
import { buildServer } from '../src/server.js';
import { TEST_DATABASE_URL } from './global-setup.js';

/** Nur für Tests. In Produktion kommt der Schlüssel aus der Umgebung. */
const TEST_AUTH_SECRET = 'test-secret-mindestens-32-zeichen-lang-000000';

export interface TestContext {
  app: FastifyInstance;
  db: Db;
  close: () => Promise<void>;
}

export async function createTestServer(): Promise<TestContext> {
  const config = loadConfig({
    NODE_ENV: 'test',
    LOG_LEVEL: 'fatal',
    DATABASE_URL: TEST_DATABASE_URL,
    AUTH_SECRET: TEST_AUTH_SECRET,
  });

  const db = createDb(TEST_DATABASE_URL);
  const app = await buildServer({ config, db });
  await app.ready();

  return {
    app,
    db,
    close: async () => {
      await app.close();
      await db.$disconnect();
    },
  };
}

/** Leert alle Tabellen, damit jeder Test von einem sauberen Stand startet. */
export async function resetDatabase(db: Db): Promise<void> {
  await db.$executeRawUnsafe(
    'TRUNCATE TABLE "audit_log", "two_factor", "session", "account", "verification", "user" RESTART IDENTITY CASCADE',
  );
}

/**
 * Die Rate-Limits zählen pro IP-Adresse – und sie sind absichtlich streng.
 * Damit sich die Tests nicht gegenseitig aussperren, bekommt jeder Aufruf
 * eine eigene Absenderadresse. Die Limits selbst werden in rate-limit.test.ts
 * mit einer festen Adresse geprüft; sie sind also weiterhin scharf gestellt.
 */
let ipCounter = 0;
export function nextIp(): string {
  ipCounter += 1;
  return `10.${Math.floor(ipCounter / 65025) % 256}.${Math.floor(ipCounter / 255) % 256}.${(ipCounter % 254) + 1}`;
}

export const testUser = {
  name: 'Test Kundin',
  email: 'kundin@example.test',
  password: 'ein-sehr-langes-passwort-2026',
};

type Options = Partial<typeof testUser> & { ip?: string };

/** Registriert ein Konto über den echten Endpunkt und liefert die Antwort. */
export async function signUp(app: FastifyInstance, overrides: Options = {}) {
  const { ip, ...user } = overrides;
  return app.inject({
    method: 'POST',
    url: '/api/auth/sign-up/email',
    headers: { 'x-forwarded-for': ip ?? nextIp() },
    payload: { ...testUser, ...user },
  });
}

/** Meldet ein Konto an und liefert die Antwort inklusive Cookies. */
export async function signIn(app: FastifyInstance, overrides: Options = {}) {
  return app.inject({
    method: 'POST',
    url: '/api/auth/sign-in/email',
    headers: { 'x-forwarded-for': overrides.ip ?? nextIp() },
    payload: {
      email: overrides.email ?? testUser.email,
      password: overrides.password ?? testUser.password,
    },
  });
}

/** Liest den Sitzungs-Cookie aus einer Antwort, um ihn weiterzuverwenden. */
export function cookieHeader(response: { headers: Record<string, unknown> }): string {
  const raw = response.headers['set-cookie'];
  const cookies = Array.isArray(raw) ? raw : typeof raw === 'string' ? [raw] : [];
  return cookies.map((cookie) => String(cookie).split(';')[0]).join('; ');
}
