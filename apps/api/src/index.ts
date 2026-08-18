import { loadConfig } from './config.js';
import { createDb } from './db.js';
import { buildServer } from './server.js';

/**
 * Startpunkt der API.
 *
 * Reihenfolge: Konfiguration prüfen → Datenbank verbinden → Server bauen →
 * lauschen. Fehlerhafte Konfiguration beendet den Prozess sofort mit klarer
 * Meldung, statt halb betriebsbereit weiterzulaufen.
 */
async function main(): Promise<void> {
  const config = loadConfig();
  const db = createDb(config.DATABASE_URL);
  const app = await buildServer({ config, db });

  // Sauberes Herunterfahren: laufende Anfragen zu Ende bedienen,
  // damit ein Deployment keine Anfrage mitten im Satz abschneidet.
  for (const signal of ['SIGINT', 'SIGTERM'] as const) {
    process.once(signal, () => {
      app.log.info({ signal }, 'Fahre herunter');
      void app
        .close()
        .then(() => db.$disconnect())
        .then(() => process.exit(0));
    });
  }

  await app.listen({ port: config.API_PORT, host: config.API_HOST });
}

main().catch((error: unknown) => {
  console.error('API konnte nicht gestartet werden:', error);
  process.exit(1);
});
