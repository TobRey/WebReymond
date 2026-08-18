import { afterAll, beforeAll, beforeEach, describe, expect, it } from 'vitest';
import { createTestServer, resetDatabase, signIn, signUp, type TestContext } from './helpers.js';

/**
 * Hier werden die Rate-Limits absichtlich ausgelöst.
 *
 * Alle Anfragen kommen von derselben Adresse – so verhält es sich auch bei
 * einem echten Angriff, der Passwörter durchprobiert.
 */
const ANGREIFER_IP = '203.0.113.66';

let ctx: TestContext;

beforeAll(async () => {
  ctx = await createTestServer();
});

afterAll(async () => {
  await ctx.close();
});

beforeEach(async () => {
  await resetDatabase(ctx.db);
});

describe('Schutz gegen Passwort-Durchprobieren', () => {
  it('sperrt nach wenigen Fehlversuchen von derselben Adresse', async () => {
    await signUp(ctx.app);

    const stati: number[] = [];
    for (let versuch = 0; versuch < 8; versuch += 1) {
      const response = await signIn(ctx.app, {
        password: `falsches-passwort-${versuch}`,
        ip: ANGREIFER_IP,
      });
      stati.push(response.statusCode);
    }

    // Irgendwann wird nicht mehr geantwortet, sondern gebremst.
    expect(stati).toContain(429);
    // Und die Sperre kommt früh, nicht erst nach hunderten Versuchen.
    expect(stati.indexOf(429)).toBeLessThanOrEqual(6);
  });

  it('bremst andere Adressen dabei nicht aus', async () => {
    await signUp(ctx.app);

    for (let versuch = 0; versuch < 8; versuch += 1) {
      await signIn(ctx.app, { password: 'falsch', ip: ANGREIFER_IP });
    }

    // Eine unbeteiligte Kundin muss sich weiterhin anmelden können.
    const response = await signIn(ctx.app, { ip: '198.51.100.7' });
    expect(response.statusCode).toBe(200);
  });
});
