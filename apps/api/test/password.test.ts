import { describe, expect, it } from 'vitest';
import { hashPassword, verifyPassword } from '../src/auth/password.js';

describe('Passwort-Hashing', () => {
  const password = 'ein-sehr-langes-passwort-2026';

  it('verwendet Argon2id', async () => {
    const hash = await hashPassword(password);
    expect(hash.startsWith('$argon2id$')).toBe(true);
  });

  it('erzeugt für dasselbe Passwort zwei verschiedene Hashes', async () => {
    // Unterschiedlicher Salt – sonst könnte man an gleichen Hashes erkennen,
    // welche Konten dasselbe Passwort verwenden.
    const a = await hashPassword(password);
    const b = await hashPassword(password);
    expect(a).not.toBe(b);
  });

  it('enthält das Passwort nicht im Klartext', async () => {
    const hash = await hashPassword(password);
    expect(hash).not.toContain(password);
  });

  it('bestätigt das richtige Passwort', async () => {
    const hash = await hashPassword(password);
    expect(await verifyPassword({ hash, password })).toBe(true);
  });

  it('lehnt ein falsches Passwort ab', async () => {
    const hash = await hashPassword(password);
    expect(await verifyPassword({ hash, password: 'etwas-ganz-anderes-2026' })).toBe(false);
  });

  it('lehnt einen beschädigten Hash ab, ohne abzustürzen', async () => {
    expect(await verifyPassword({ hash: 'kein-gueltiger-hash', password })).toBe(false);
    expect(await verifyPassword({ hash: '', password })).toBe(false);
  });
});
