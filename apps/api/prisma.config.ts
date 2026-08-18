import { defineConfig, env } from 'prisma/config';

/**
 * Konfiguration für die Prisma-Kommandozeile (Migrationen, Generierung).
 *
 * Seit Prisma 7 steht die Verbindungszeichenfolge nicht mehr im Schema,
 * sondern hier – und wird ausschliesslich aus der Umgebung gelesen.
 * Damit landet keine Zugangsinformation in einer versionierten Datei.
 */
export default defineConfig({
  schema: 'prisma/schema.prisma',
  datasource: {
    url: env('DATABASE_URL'),
  },
  migrations: {
    path: 'prisma/migrations',
  },
});
