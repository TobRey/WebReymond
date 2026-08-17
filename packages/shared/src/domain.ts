import { z } from 'zod';

/* -------------------------------------------------------------------------- */
/* Sprachen                                                                   */
/* -------------------------------------------------------------------------- */

/** Unterstützte Sprachen. Erweitern heisst: hier ergänzen und Katalog anlegen. */
export const LOCALES = ['de', 'en'] as const;

export const localeSchema = z.enum(LOCALES);

export type Locale = z.infer<typeof localeSchema>;

export const DEFAULT_LOCALE: Locale = 'de';

/* -------------------------------------------------------------------------- */
/* Rollen                                                                     */
/* -------------------------------------------------------------------------- */

/**
 * Die drei Standardrollen (siehe README, Abschnitt Benutzerrollen).
 * Reihenfolge = aufsteigende Berechtigung. Feinere Rechte kommen später als
 * eigene Berechtigungstabelle dazu; diese Rollen bleiben die grobe Ebene.
 */
export const ROLES = ['user', 'customer', 'admin'] as const;

export const roleSchema = z.enum(ROLES);

export type Role = z.infer<typeof roleSchema>;

const ROLE_RANK: Record<Role, number> = {
  user: 0,
  customer: 1,
  admin: 2,
};

/**
 * Prüft, ob eine Rolle mindestens der geforderten Stufe entspricht.
 *
 * Bewusst NUR eine Grobprüfung: Ob jemand ein bestimmtes Objekt sehen darf,
 * entscheidet zusätzlich die Eigentümerprüfung in der Policy-Schicht.
 * Rolle allein ist nie ausreichend (siehe SECURITY.md – IDOR).
 */
export function hasAtLeastRole(actual: Role, required: Role): boolean {
  return ROLE_RANK[actual] >= ROLE_RANK[required];
}
