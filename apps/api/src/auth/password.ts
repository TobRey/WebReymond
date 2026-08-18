import { hash, verify } from '@node-rs/argon2';

/**
 * Passwort-Hashing mit Argon2id.
 *
 * Argon2id gewann die Password Hashing Competition und ist gegen Angriffe mit
 * Grafikkarten deutlich widerstandsfähiger als ältere Verfahren. Die Parameter
 * folgen den OWASP-Empfehlungen: 19 MiB Speicher, 2 Durchläufe, Parallelität 1.
 *
 * Der Salt wird von der Bibliothek erzeugt und steckt im Ergebnis-String –
 * es gibt hier bewusst nichts selbst zu basteln.
 */
const options = {
  memoryCost: 19456,
  timeCost: 2,
  parallelism: 1,
} as const;

// Argon2id ist die Voreinstellung der Bibliothek. Verlassen wollen wir uns
// darauf nicht blind: Ein Test prüft, dass der erzeugte Hash mit "$argon2id$"
// beginnt – sollte sich die Voreinstellung je ändern, schlägt er fehl.

export async function hashPassword(password: string): Promise<string> {
  return hash(password, options);
}

export async function verifyPassword({
  hash: storedHash,
  password,
}: {
  hash: string;
  password: string;
}): Promise<boolean> {
  try {
    return await verify(storedHash, password, options);
  } catch {
    // Ein beschädigter oder fremdformatiger Hash darf nicht als Treffer gelten
    // und darf auch keinen Serverfehler auslösen.
    return false;
  }
}
