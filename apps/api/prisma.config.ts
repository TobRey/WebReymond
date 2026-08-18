import { existsSync } from 'node:fs';
import { resolve } from 'node:path';
import { defineConfig } from 'prisma/config';

/**
 * Konfiguration für die Prisma-Kommandozeile (Migrationen, Generierung).
 *
 * Seit Prisma 7 steht die Verbindungszeichenfolge nicht mehr im Schema,
 * sondern hier – und wird ausschliesslich aus der Umgebung gelesen.
 * Damit landet keine Zugangsinformation in einer versionierten Datei.
 */

// Die .env aus dem Projektwurzelverzeichnis laden, falls vorhanden.
// Dadurch funktioniert `pnpm db:migrate` direkt nach `cp .env.example .env`,
// ohne dass man die Variable von Hand in die Shell exportieren muss.
const envFile = resolve(import.meta.dirname, '../../.env');
if (existsSync(envFile)) {
  process.loadEnvFile(envFile);
}

const url = process.env['DATABASE_URL'];

export default defineConfig({
  schema: 'prisma/schema.prisma',
  // Bewusst nur gesetzt, wenn die Variable existiert: `prisma generate` braucht
  // keine Datenbank und muss auch bei einer frischen Installation ohne .env
  // durchlaufen – sonst scheitert bereits `pnpm install`.
  ...(url ? { datasource: { url } } : {}),
  migrations: {
    path: 'prisma/migrations',
  },
});
