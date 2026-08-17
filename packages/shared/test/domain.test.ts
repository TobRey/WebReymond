import { describe, expect, it } from 'vitest';
import { DEFAULT_LOCALE, hasAtLeastRole, localeSchema, roleSchema } from '../src/domain.js';

describe('localeSchema', () => {
  it('akzeptiert unterstützte Sprachen', () => {
    expect(localeSchema.parse('de')).toBe('de');
    expect(localeSchema.parse('en')).toBe('en');
  });

  it('lehnt unbekannte Sprachen ab', () => {
    expect(localeSchema.safeParse('fr').success).toBe(false);
    expect(localeSchema.safeParse('').success).toBe(false);
  });

  it('hat Deutsch als Standardsprache', () => {
    expect(DEFAULT_LOCALE).toBe('de');
  });
});

describe('roleSchema', () => {
  it('kennt genau die drei Standardrollen', () => {
    expect(roleSchema.options).toEqual(['user', 'customer', 'admin']);
  });

  it('lehnt erfundene Rollen ab', () => {
    expect(roleSchema.safeParse('superadmin').success).toBe(false);
  });
});

describe('hasAtLeastRole', () => {
  it('erlaubt gleiche und höhere Rollen', () => {
    expect(hasAtLeastRole('admin', 'admin')).toBe(true);
    expect(hasAtLeastRole('admin', 'customer')).toBe(true);
    expect(hasAtLeastRole('customer', 'user')).toBe(true);
  });

  it('verweigert niedrigere Rollen', () => {
    expect(hasAtLeastRole('user', 'customer')).toBe(false);
    expect(hasAtLeastRole('customer', 'admin')).toBe(false);
  });

  it('gibt einem Kunden keinen Adminzugriff', () => {
    // Der wichtigste Fall: Rechteausweitung darf nicht passieren.
    expect(hasAtLeastRole('customer', 'admin')).toBe(false);
    expect(hasAtLeastRole('user', 'admin')).toBe(false);
  });
});
