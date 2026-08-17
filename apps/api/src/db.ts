import { PrismaPg } from '@prisma/adapter-pg';
import { PrismaClient } from './generated/prisma/client.js';

/**
 * Datenbankzugang.
 *
 * Seit Prisma 7 bekommt der Client einen Treiber-Adapter statt einer
 * Verbindungszeichenfolge im Schema. Die Zeichenfolge kommt ausschliesslich
 * aus der Umgebung und wird nie geloggt.
 */
export function createDb(connectionString: string): PrismaClient {
  return new PrismaClient({ adapter: new PrismaPg({ connectionString }) });
}

export type Db = PrismaClient;
