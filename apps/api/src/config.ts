import { z } from 'zod';

/**
 * Konfiguration kommt ausschliesslich aus Umgebungsvariablen und wird beim
 * Start validiert. Fehlt etwas oder ist es unsinnig, startet die Anwendung
 * gar nicht erst – besser ein klarer Fehler beim Start als ein halb
 * funktionierender Dienst in Produktion.
 *
 * Secrets stehen NIE im Code und NIE im Repository (siehe SECURITY.md).
 */
const envSchema = z.object({
  NODE_ENV: z.enum(['development', 'test', 'production']).default('development'),
  API_PORT: z.coerce.number().int().min(1).max(65535).default(3001),
  /**
   * Bindeadresse. Standard 127.0.0.1: Die API ist damit nur lokal erreichbar,
   * nach aussen stellt sie ausschliesslich nginx bereit (siehe DEPLOYMENT.md).
   */
  API_HOST: z.string().min(1).default('127.0.0.1'),
  /** Öffentliche Adresse der API – wird für Links in E-Mails gebraucht. */
  API_PUBLIC_URL: z.url().default('http://localhost:3001'),
  /** Adresse des Portals. Nur von dort werden Browser-Anfragen akzeptiert. */
  APP_URL: z.url().default('http://localhost:3000'),
  LOG_LEVEL: z.enum(['fatal', 'error', 'warn', 'info', 'debug', 'trace']).default('info'),

  /** Verbindung zur PostgreSQL-Datenbank. */
  DATABASE_URL: z.string().min(1),

  /**
   * Schlüssel zum Signieren von Sitzungen und Token.
   *
   * Kein Standardwert – ein vergessener Schlüssel wäre ein stiller
   * Totalausfall der Sicherheit. Erzeugen mit: openssl rand -base64 48
   */
  AUTH_SECRET: z.string().min(32, 'AUTH_SECRET muss mindestens 32 Zeichen lang sein'),
});

export type Config = z.infer<typeof envSchema> & { version: string };

export function loadConfig(env: NodeJS.ProcessEnv = process.env): Config {
  const parsed = envSchema.safeParse(env);

  if (!parsed.success) {
    const details = parsed.error.issues
      .map((issue) => `  - ${issue.path.join('.') || '(root)'}: ${issue.message}`)
      .join('\n');
    throw new Error(`Ungültige Konfiguration:\n${details}`);
  }

  return { ...parsed.data, version: process.env['npm_package_version'] ?? '0.1.0' };
}
