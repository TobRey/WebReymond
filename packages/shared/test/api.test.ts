import { describe, expect, it } from 'vitest';
import {
  ApiErrorCode,
  apiErrorSchema,
  healthResponseSchema,
  pingResponseSchema,
} from '../src/api.js';

describe('healthResponseSchema', () => {
  it('akzeptiert eine gültige Antwort', () => {
    const result = healthResponseSchema.safeParse({
      status: 'ok',
      version: '0.1.0',
      uptimeSeconds: 12.5,
    });
    expect(result.success).toBe(true);
  });

  it('lehnt eine negative Laufzeit ab', () => {
    const result = healthResponseSchema.safeParse({
      status: 'ok',
      version: '0.1.0',
      uptimeSeconds: -1,
    });
    expect(result.success).toBe(false);
  });

  it('lehnt fehlende Felder ab', () => {
    expect(healthResponseSchema.safeParse({ status: 'ok' }).success).toBe(false);
  });
});

describe('pingResponseSchema', () => {
  it('verlangt einen gültigen Zeitstempel', () => {
    expect(
      pingResponseSchema.safeParse({ message: 'pong', timestamp: '2026-08-17T20:00:00.000Z' })
        .success,
    ).toBe(true);
    expect(pingResponseSchema.safeParse({ message: 'pong', timestamp: 'gestern' }).success).toBe(
      false,
    );
  });
});

describe('apiErrorSchema', () => {
  it('akzeptiert einen bekannten Fehlercode', () => {
    const result = apiErrorSchema.safeParse({
      error: { code: ApiErrorCode.NOT_FOUND, message: 'Nicht gefunden' },
    });
    expect(result.success).toBe(true);
  });

  it('lehnt unbekannte Fehlercodes ab', () => {
    const result = apiErrorSchema.safeParse({
      error: { code: 'kaputt', message: 'egal' },
    });
    expect(result.success).toBe(false);
  });

  it('verlangt eine nicht leere Meldung', () => {
    const result = apiErrorSchema.safeParse({
      error: { code: ApiErrorCode.INTERNAL, message: '' },
    });
    expect(result.success).toBe(false);
  });
});
