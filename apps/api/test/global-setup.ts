import { execFileSync } from 'node:child_process';

/**
 * Vor allen Tests: Das Schema in die Testdatenbank einspielen.
 *
 * Die Tests laufen gegen eine echte PostgreSQL-Datenbank, nicht gegen eine
 * Attrappe. Nur so merken wir, wenn eine Migration kaputt ist, ein Index fehlt
 * oder eine Fremdschlüsselregel nicht greift.
 */
export const TEST_DATABASE_URL =
  process.env['TEST_DATABASE_URL'] ??
  'postgresql://webreymond:webreymond_dev_only@127.0.0.1:5432/webreymond_test';

export default function setup(): void {
  execFileSync('pnpm', ['exec', 'prisma', 'migrate', 'deploy'], {
    env: { ...process.env, DATABASE_URL: TEST_DATABASE_URL },
    stdio: 'inherit',
  });
}
