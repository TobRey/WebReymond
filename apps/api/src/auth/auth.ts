import { betterAuth } from 'better-auth';
import { prismaAdapter } from 'better-auth/adapters/prisma';
import { twoFactor } from 'better-auth/plugins';
import type { Config } from '../config.js';
import type { Db } from '../db.js';
import { AuditAction, writeAuditLog } from './audit.js';
import { hashPassword, verifyPassword } from './password.js';

export interface AuthDeps {
  db: Db;
  config: Config;
  /** Wird für Protokollausgaben verwendet – kein direkter Zugriff auf den Logger. */
  log: (level: 'info' | 'warn' | 'error', message: string, details?: unknown) => void;
}

/**
 * Authentifizierung für WebHeaven.
 *
 * Bewusste Entscheidungen:
 *
 * - **Argon2id statt Standardverfahren** – siehe SECURITY.md.
 * - **`role` ist kein Eingabefeld** (`input: false`). Ohne diese Zeile könnte
 *   sich jemand bei der Registrierung selbst zum Administrator machen.
 * - **Sitzungen liegen in der Datenbank**, damit ein Zugang sofort widerrufen
 *   werden kann.
 * - **Eigene Rate-Limits** für Anmeldung, Registrierung und Passwort-Reset,
 *   deutlich strenger als der allgemeine Grundschutz.
 * - **Passwort-Reset verrät nicht**, ob eine Adresse existiert.
 */
export function createAuth({ db, config, log }: AuthDeps) {
  const isProduction = config.NODE_ENV === 'production';

  return betterAuth({
    appName: 'WebHeaven',
    baseURL: config.API_PUBLIC_URL,
    basePath: '/api/auth',
    secret: config.AUTH_SECRET,

    database: prismaAdapter(db, { provider: 'postgresql' }),

    trustedOrigins: [config.APP_URL],

    emailAndPassword: {
      enabled: true,
      // 12 Zeichen: lange Passphrasen schlagen kurze Zeichensalate.
      minPasswordLength: 12,
      maxPasswordLength: 256,
      // Wird auf true gesetzt, sobald der E-Mail-Versand steht (Phase 19).
      requireEmailVerification: false,
      password: {
        hash: hashPassword,
        verify: verifyPassword,
      },
      sendResetPassword: async ({ user, url }) => {
        // Bis der Mailversand steht (Phase 19), landet der Link im Protokoll.
        // In Produktion wird hier NICHT der Link geloggt – sonst stünde ein
        // gültiges Passwort-Reset-Token in der Logdatei.
        if (isProduction) {
          log('warn', 'Passwort-Reset angefordert, aber kein Mailversand konfiguriert');
        } else {
          log('info', `Passwort-Reset für ${user.email}: ${url}`);
        }
      },
    },

    user: {
      additionalFields: {
        role: {
          type: 'string',
          defaultValue: 'customer',
          // Entscheidend: Die Rolle darf niemals aus der Anfrage kommen.
          input: false,
        },
      },
    },

    session: {
      expiresIn: 60 * 60 * 24 * 7, // 7 Tage
      updateAge: 60 * 60 * 24, // Verlängerung frühestens nach 24 Stunden
    },

    rateLimit: {
      enabled: true,
      window: 60,
      max: 60,
      customRules: {
        '/sign-in/email': { window: 300, max: 5 },
        '/sign-up/email': { window: 3600, max: 5 },
        '/request-password-reset': { window: 3600, max: 5 },
        '/reset-password': { window: 3600, max: 5 },
        '/two-factor/verify-totp': { window: 300, max: 5 },
      },
    },

    advanced: {
      cookiePrefix: 'webheaven',
      useSecureCookies: isProduction,
      ipAddress: {
        /**
         * Die echte Kunden-IP steht hinter nginx im Header X-Forwarded-For.
         * Ohne diese Angabe landen alle Anfragen unter derselben Adresse –
         * Rate-Limits und Audit-Log wären damit praktisch wertlos.
         *
         * Das ist nur vertretbar, weil die API ausschliesslich auf 127.0.0.1
         * lauscht und niemand direkt an nginx vorbeikommt. Sobald der Server
         * steht (Phase 7), tragen wir zusätzlich die vertrauenswürdigen
         * Proxy-Adressen ein.
         */
        ipAddressHeaders: ['x-forwarded-for'],
      },
    },

    plugins: [twoFactor({ issuer: 'WebHeaven' })],

    databaseHooks: {
      user: {
        create: {
          after: async (user) => {
            await writeAuditLog(
              db,
              {
                action: AuditAction.USER_REGISTERED,
                actorId: user.id,
                actorEmail: user.email,
                targetType: 'user',
                targetId: user.id,
              },
              (error) => log('error', 'Audit-Log konnte nicht geschrieben werden', error),
            );
          },
        },
      },
      session: {
        create: {
          after: async (session) => {
            await writeAuditLog(
              db,
              {
                action: AuditAction.USER_SIGNED_IN,
                actorId: session.userId,
                targetType: 'session',
                targetId: session.id,
                ipAddress: session.ipAddress ?? undefined,
                userAgent: session.userAgent ?? undefined,
              },
              (error) => log('error', 'Audit-Log konnte nicht geschrieben werden', error),
            );
          },
        },
      },
    },
  });
}

export type Auth = ReturnType<typeof createAuth>;
