import { afterAll, beforeAll, beforeEach, describe, expect, it } from 'vitest';
import {
  cookieHeader,
  createTestServer,
  nextIp,
  resetDatabase,
  signIn,
  signUp,
  testUser,
  type TestContext,
} from './helpers.js';

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

describe('Registrierung', () => {
  it('legt ein Konto an', async () => {
    const response = await signUp(ctx.app);
    expect(response.statusCode).toBe(200);

    const user = await ctx.db.user.findUnique({ where: { email: testUser.email } });
    expect(user).not.toBeNull();
    expect(user?.name).toBe(testUser.name);
  });

  it('speichert das Passwort niemals im Klartext', async () => {
    await signUp(ctx.app);

    const account = await ctx.db.account.findFirst({ where: { providerId: 'credential' } });
    expect(account?.password).toBeTruthy();
    expect(account?.password).not.toContain(testUser.password);
    // Argon2id – wird die Voreinstellung der Bibliothek je geändert, fällt es hier auf.
    expect(account?.password?.startsWith('$argon2id$')).toBe(true);
  });

  it('vergibt automatisch die Rolle "customer", nie "admin"', async () => {
    await signUp(ctx.app);

    const user = await ctx.db.user.findUnique({ where: { email: testUser.email } });
    expect(user?.role).toBe('customer');
  });

  it('ignoriert eine mitgeschickte Rolle (Rechteausweitung)', async () => {
    // Der klassische Angriff: role einfach mit ins Formular schreiben.
    const response = await ctx.app.inject({
      method: 'POST',
      url: '/api/auth/sign-up/email',
      headers: { 'x-forwarded-for': nextIp() },
      payload: { ...testUser, role: 'admin' },
    });
    expect(response.statusCode).toBe(200);

    const user = await ctx.db.user.findUnique({ where: { email: testUser.email } });
    expect(user?.role).toBe('customer');
  });

  it('lehnt zu kurze Passwörter ab', async () => {
    const response = await signUp(ctx.app, { password: 'kurz123' });
    expect(response.statusCode).toBeGreaterThanOrEqual(400);

    const user = await ctx.db.user.findUnique({ where: { email: testUser.email } });
    expect(user).toBeNull();
  });

  it('lässt dieselbe E-Mail-Adresse kein zweites Mal zu', async () => {
    await signUp(ctx.app);
    const second = await signUp(ctx.app);

    expect(second.statusCode).toBeGreaterThanOrEqual(400);
    expect(await ctx.db.user.count()).toBe(1);
  });

  it('schreibt einen Audit-Log-Eintrag', async () => {
    await signUp(ctx.app);

    const entry = await ctx.db.auditLog.findFirst({ where: { action: 'user.registered' } });
    expect(entry).not.toBeNull();
    expect(entry?.actorEmail).toBe(testUser.email);
  });
});

describe('Anmeldung', () => {
  beforeEach(async () => {
    await signUp(ctx.app);
  });

  it('funktioniert mit korrektem Passwort und setzt einen Cookie', async () => {
    const response = await signIn(ctx.app);

    expect(response.statusCode).toBe(200);
    const cookies = cookieHeader(response);
    expect(cookies).toContain('webheaven');
  });

  it('scheitert mit falschem Passwort', async () => {
    const response = await signIn(ctx.app, { password: 'falsches-passwort-2026' });
    expect(response.statusCode).toBeGreaterThanOrEqual(400);
  });

  it('verrät nicht, ob eine Adresse existiert', async () => {
    const unbekannt = await signIn(ctx.app, {
      email: 'gibt-es-nicht@example.test',
      password: 'ein-sehr-langes-passwort-2026',
    });
    const falschesPasswort = await signIn(ctx.app, { password: 'falsches-passwort-2026' });

    // Gleicher Status und gleiche Meldung – sonst kann man Konten durchprobieren.
    expect(unbekannt.statusCode).toBe(falschesPasswort.statusCode);
    expect(unbekannt.json().message).toBe(falschesPasswort.json().message);
  });

  it('legt eine Sitzung in der Datenbank an', async () => {
    // Die Registrierung meldet bereits an – deshalb wird die Differenz geprüft
    // und nicht die absolute Zahl.
    const vorher = await ctx.db.session.count();
    await signIn(ctx.app);

    expect(await ctx.db.session.count()).toBe(vorher + 1);

    const session = await ctx.db.session.findFirst({ orderBy: { createdAt: 'desc' } });
    expect(session?.expiresAt.getTime()).toBeGreaterThan(Date.now());
    // Sitzungen liegen in der Datenbank, damit ein Zugang sofort entzogen
    // werden kann – nicht nur als Token im Browser.
    expect(session?.userId).toBeTruthy();
  });

  it('schreibt einen Audit-Log-Eintrag inklusive Absenderadresse', async () => {
    await signIn(ctx.app, { ip: '198.51.100.42' });

    const entry = await ctx.db.auditLog.findFirst({
      where: { action: 'user.signed_in' },
      orderBy: { createdAt: 'desc' },
    });
    expect(entry).not.toBeNull();
    // Ohne IP-Adresse wäre das Protokoll im Ernstfall wertlos.
    expect(entry?.ipAddress).toBe('198.51.100.42');
  });
});

describe('GET /v1/me', () => {
  it('antwortet ohne Anmeldung mit 401', async () => {
    const response = await ctx.app.inject({ method: 'GET', url: '/v1/me' });

    expect(response.statusCode).toBe(401);
    expect(response.json().error.code).toBe('unauthorized');
  });

  it('antwortet mit einem gefälschten Cookie ebenfalls mit 401', async () => {
    const response = await ctx.app.inject({
      method: 'GET',
      url: '/v1/me',
      headers: { cookie: 'webheaven.session_token=frei-erfunden' },
    });

    expect(response.statusCode).toBe(401);
  });

  it('liefert nach der Anmeldung das eigene Konto', async () => {
    await signUp(ctx.app);
    const login = await signIn(ctx.app);

    const response = await ctx.app.inject({
      method: 'GET',
      url: '/v1/me',
      headers: { cookie: cookieHeader(login) },
    });

    expect(response.statusCode).toBe(200);
    expect(response.json()).toMatchObject({
      email: testUser.email,
      name: testUser.name,
      role: 'customer',
    });
  });

  it('gibt niemals den Passwort-Hash heraus', async () => {
    await signUp(ctx.app);
    const login = await signIn(ctx.app);

    const response = await ctx.app.inject({
      method: 'GET',
      url: '/v1/me',
      headers: { cookie: cookieHeader(login) },
    });

    const body = response.body.toLowerCase();
    expect(body).not.toContain('password');
    expect(body).not.toContain('argon2');
  });

  it('endet nach dem Abmelden wieder mit 401', async () => {
    await signUp(ctx.app);
    const login = await signIn(ctx.app);
    const cookie = cookieHeader(login);

    await ctx.app.inject({ method: 'POST', url: '/api/auth/sign-out', headers: { cookie } });

    const response = await ctx.app.inject({ method: 'GET', url: '/v1/me', headers: { cookie } });
    expect(response.statusCode).toBe(401);
  });
});

describe('Passwort-Reset', () => {
  beforeEach(async () => {
    await signUp(ctx.app);
  });

  it('erzeugt ein Einmal-Token für eine bekannte Adresse', async () => {
    const response = await ctx.app.inject({
      method: 'POST',
      url: '/api/auth/request-password-reset',
      headers: { 'x-forwarded-for': nextIp() },
      payload: { email: testUser.email, redirectTo: 'http://localhost:3000/de/passwort-neu' },
    });

    expect(response.statusCode).toBe(200);
    expect(await ctx.db.verification.count()).toBeGreaterThan(0);
  });

  it('antwortet bei unbekannter Adresse genauso – ohne Token anzulegen', async () => {
    const response = await ctx.app.inject({
      method: 'POST',
      url: '/api/auth/request-password-reset',
      headers: { 'x-forwarded-for': nextIp() },
      payload: {
        email: 'gibt-es-nicht@example.test',
        redirectTo: 'http://localhost:3000/de/passwort-neu',
      },
    });

    // Gleiche Antwort wie oben: Die Existenz eines Kontos bleibt geheim.
    expect(response.statusCode).toBe(200);
    expect(await ctx.db.verification.count()).toBe(0);
  });
});
