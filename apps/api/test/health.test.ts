import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import type { FastifyInstance } from 'fastify';
import { healthResponseSchema, pingResponseSchema } from '@webheaven/shared';
import { loadConfig } from '../src/config.js';
import { buildServer } from '../src/server.js';

let app: FastifyInstance;

beforeAll(async () => {
  app = await buildServer(loadConfig({ NODE_ENV: 'test', LOG_LEVEL: 'fatal' }));
  await app.ready();
});

afterAll(async () => {
  await app.close();
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
  it('lehnt einen unsinnigen Port ab', () => {
    expect(() => loadConfig({ API_PORT: '99999' })).toThrow(/Ungültige Konfiguration/);
  });

  it('lauscht standardmässig nur lokal', () => {
    expect(loadConfig({}).API_HOST).toBe('127.0.0.1');
  });
});
