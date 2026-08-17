import type { Db } from '../db.js';

/**
 * Sicherheitsrelevante Ereignisse, die protokolliert werden.
 * Neue Ereignisse hier ergänzen, damit die Liste vollständig bleibt.
 */
export const AuditAction = {
  USER_REGISTERED: 'user.registered',
  USER_SIGNED_IN: 'user.signed_in',
  USER_SIGNED_OUT: 'user.signed_out',
  PASSWORD_RESET_REQUESTED: 'user.password_reset_requested',
  PASSWORD_CHANGED: 'user.password_changed',
  TWO_FACTOR_ENABLED: 'user.two_factor_enabled',
  TWO_FACTOR_DISABLED: 'user.two_factor_disabled',
  ROLE_CHANGED: 'user.role_changed',
} as const;

export type AuditActionValue = (typeof AuditAction)[keyof typeof AuditAction];

export interface AuditEntry {
  action: AuditActionValue;
  actorId?: string | undefined;
  actorEmail?: string | undefined;
  targetType?: string | undefined;
  targetId?: string | undefined;
  ipAddress?: string | undefined;
  userAgent?: string | undefined;
  metadata?: Record<string, string | number | boolean | null> | undefined;
}

/**
 * Schreibt einen Eintrag ins Audit-Log.
 *
 * Zwei bewusste Entscheidungen:
 *
 * 1. Schlägt das Schreiben fehl, wird der Fehler protokolliert, aber nicht
 *    weitergereicht. Ein defektes Protokoll darf keine Anmeldung verhindern.
 * 2. In `metadata` gehören niemals Passwörter, Token oder Sitzungsschlüssel.
 *    Der Typ lässt bewusst nur einfache Werte zu, damit nicht versehentlich
 *    ein ganzes Objekt mit Geheimnissen hineinwandert.
 */
export async function writeAuditLog(
  db: Db,
  entry: AuditEntry,
  onError?: (error: unknown) => void,
): Promise<void> {
  try {
    await db.auditLog.create({
      data: {
        action: entry.action,
        actorId: entry.actorId ?? null,
        actorEmail: entry.actorEmail ?? null,
        targetType: entry.targetType ?? null,
        targetId: entry.targetId ?? null,
        ipAddress: entry.ipAddress ?? null,
        userAgent: entry.userAgent ?? null,
        ...(entry.metadata ? { metadata: entry.metadata } : {}),
      },
    });
  } catch (error) {
    onError?.(error);
  }
}
