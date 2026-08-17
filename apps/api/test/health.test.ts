import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import type { FastifyInstance } from 'fastify';
import { healthResponseSchema, pingResponseSchema } from '@webheaven/shared';
import { loadConfig } from '../src/config.js';
import { createTestServer, type TestContext } from './helpers.js';
import { TEST_DATABASE_URL } from './global-setup.js';

let ctx: TestContext;
let app: FastifyInstance;

beforeAll(async () => {
  ctx = await createTestServer();
  app = ctx.app;
});

afterAll(async () => {
  await ctx.close();
});

describe('GET /health', () => {
  it('antwortet mit Status 200 und gültigem Schema', async () => {
    const response = await app.inject({ method: 'GET', url: '/health' });

    expect(response.statusCode).toBe(200);
    const parsed = healthResponseSchema.safeParse(response.json());
    expect(parsed.success).toBe(true);
    expect(response.json().status).toBe('ok');
  });

  it('setzt Sicherheitsheader', async () => {
    const response = await app.inject({ method: 'GET', url: '/health' });

    expect(response.headers['x-content-type-options']).toBe('nosniff');
    expect(response.headers['content-security-policy']).toBeDefined();
  });
});

describe('GET /v1/ping', () => {
  it('antwortet mit pong und gültigem Zeitstempel', async () => {
    const response = await app.inject({ method: 'GET', url: '/v1/ping' });

    expect(response.statusCode).toBe(200);
    expect(pingResponseSchema.safeParse(response.json()).success).toBe(true);
  });
});

describe('unbekannte Route', () => {
  it('antwortet mit 404 im einheitlichen Fehlerformat', async () => {
    const response = await app.inject({ method: 'GET', url: '/gibt-es-nicht' });

    expect(response.statusCode).toBe(404);
    expect(response.json().error.code).toBe('not_found');
    // Die Antwort verrät nichts über den Server – nur Code, Meldung, Request-ID.
    expect(Object.keys(response.json().error).sort()).toEqual(['code', 'message', 'requestId']);
  });
});

describe('Konfiguration', () => {
  const minimal = { DATABASE_URL: TEST_DATABASE_URL, AUTH_SECRET: 'x'.repeat(32) };

  it('lehnt einen unsinnigen Port ab', () => {
    expect(() => loadConfig({ ...minimal, API_PORT: '99999' })).toThrow(/Ungültige Konfiguration/);
  });

  it('lauscht standardmässig nur lokal', () => {
    expect(loadConfig(minimal).API_HOST).toBe('127.0.0.1');
  });

  it('startet nicht ohne Datenbankverbindung', () => {
    expect(() => loadConfig({ AUTH_SECRET: 'x'.repeat(32) })).toThrow(/DATABASE_URL/);
  });

  it('startet nicht ohne ausreichend langen AUTH_SECRET', () => {
    expect(() => loadConfig({ DATABASE_URL: TEST_DATABASE_URL, AUTH_SECRET: 'kurz' })).toThrow(
      /AUTH_SECRET/,
    );
  });
});
