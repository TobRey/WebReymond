/**
 * Privatsphäre: Verschlüsselung, Privatmodus, Löschen.
 *
 * ZUR VERSCHLÜSSELUNG, OHNE SCHÖNFÄRBEREI:
 * Eine Webseite hat keinen Ort, an dem sie einen Schlüssel vor jemandem
 * verstecken könnte, der das entsperrte Gerät in der Hand hält. Ein
 * automatisch erzeugter, daneben abgelegter Schlüssel wäre reine Zierde.
 *
 * Deshalb hängt die Verschlüsselung hier an einem Kennwort, das nur der
 * Nutzer kennt: Daraus wird per PBKDF2 (210 000 Runden, SHA-256) ein
 * AES-GCM-Schlüssel abgeleitet. Ohne Kennwort sind die gespeicherten
 * Gesichtsmerkmale nicht zu entschlüsseln – auch nicht von dieser Seite.
 * Der Preis: Wer das Kennwort vergisst, verliert die Kartei. Das steht so
 * auch in der Oberfläche.
 *
 * Ist kein Kennwort gesetzt, wird nichts verschlüsselt und die Oberfläche
 * sagt das deutlich, statt Sicherheit vorzutäuschen.
 */

const SALT_KEY = 'visionhud.salt.v1';
const CHECK_KEY = 'visionhud.check.v1';
const ITERATIONS = 210000;

function toBase64(buffer) {
  return btoa(String.fromCharCode(...new Uint8Array(buffer)));
}

function fromBase64(text) {
  return Uint8Array.from(atob(text), (c) => c.charCodeAt(0));
}

export class Vault {
  constructor() {
    this.key = null;
  }

  get locked() {
    return this.enabled && !this.key;
  }

  /** Ist überhaupt ein Kennwort eingerichtet? */
  get enabled() {
    try {
      return Boolean(localStorage.getItem(SALT_KEY));
    } catch {
      return false;
    }
  }

  static get available() {
    return Boolean(globalThis.crypto?.subtle);
  }

  #salt() {
    const stored = localStorage.getItem(SALT_KEY);
    if (stored) return fromBase64(stored);
    const salt = crypto.getRandomValues(new Uint8Array(16));
    localStorage.setItem(SALT_KEY, toBase64(salt));
    return salt;
  }

  async #derive(passphrase) {
    const material = await crypto.subtle.importKey(
      'raw',
      new TextEncoder().encode(passphrase),
      'PBKDF2',
      false,
      ['deriveKey'],
    );
    return crypto.subtle.deriveKey(
      { name: 'PBKDF2', salt: this.#salt(), iterations: ITERATIONS, hash: 'SHA-256' },
      material,
      { name: 'AES-GCM', length: 256 },
      false,
      ['encrypt', 'decrypt'],
    );
  }

  /** Richtet ein Kennwort ein (überschreibt ein bestehendes nicht). */
  async setUp(passphrase) {
    if (!Vault.available) throw new Error('Dieser Browser kann nicht verschlüsseln.');
    if (passphrase.length < 8) throw new Error('Das Kennwort braucht mindestens 8 Zeichen.');

    this.key = await this.#derive(passphrase);
    // Eine bekannte Zeichenfolge verschlüsselt ablegen – daran erkennt
    // `unlock`, ob das eingegebene Kennwort stimmt.
    localStorage.setItem(CHECK_KEY, JSON.stringify(await this.encrypt('visionhud')));
  }

  /** Öffnet einen bestehenden Tresor. */
  async unlock(passphrase) {
    if (!this.enabled) throw new Error('Es ist kein Kennwort eingerichtet.');
    this.key = await this.#derive(passphrase);
    try {
      const probe = JSON.parse(localStorage.getItem(CHECK_KEY) ?? 'null');
      if (!probe || (await this.decrypt(probe)) !== 'visionhud') throw new Error('falsch');
    } catch {
      this.key = null;
      throw new Error('Das Kennwort stimmt nicht.');
    }
    return true;
  }

  lock() {
    this.key = null;
  }

  /** Entfernt Kennwort und Prüfwert. Die Daten bleiben – aber verschlüsselt. */
  forget() {
    localStorage.removeItem(SALT_KEY);
    localStorage.removeItem(CHECK_KEY);
    this.key = null;
  }

  async encrypt(text) {
    if (!this.key) throw new Error('Der Tresor ist zu.');
    const iv = crypto.getRandomValues(new Uint8Array(12));
    const data = new TextEncoder().encode(text);
    const cipher = await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, this.key, data);
    return { iv: toBase64(iv), data: toBase64(cipher) };
  }

  async decrypt(payload) {
    if (!this.key) throw new Error('Der Tresor ist zu.');
    const plain = await crypto.subtle.decrypt(
      { name: 'AES-GCM', iv: fromBase64(payload.iv) },
      this.key,
      fromBase64(payload.data),
    );
    return new TextDecoder().decode(plain);
  }

  /** Verschlüsselt die personenbezogenen Felder eines Datensatzes. */
  async sealPerson(person) {
    if (!this.key) return person;
    const secret = JSON.stringify({
      name: person.name,
      age: person.age,
      note: person.note ?? '',
      descriptors: person.descriptors.map((d) => Array.from(d)),
      thumb: person.thumb ?? null,
    });
    return {
      id: person.id,
      registeredAt: person.registeredAt,
      sealed: await this.encrypt(secret),
    };
  }

  /** Macht `sealPerson` rückgängig. */
  async openPerson(row) {
    if (!row.sealed) return row;
    const plain = JSON.parse(await this.decrypt(row.sealed));
    return {
      id: row.id,
      registeredAt: row.registeredAt,
      name: plain.name,
      age: plain.age,
      note: plain.note ?? '',
      thumb: plain.thumb ?? null,
      descriptors: plain.descriptors.map((d) => Float32Array.from(d)),
    };
  }
}

/**
 * Löscht restlos alles, was diese Seite je gespeichert hat.
 * Wird nur nach ausdrücklicher Bestätigung aufgerufen.
 */
export async function wipeEverything() {
  const report = { databases: 0, keys: 0 };

  for (const name of ['visionhud', 'visionhud-objects']) {
    await new Promise((resolve) => {
      const request = indexedDB.deleteDatabase(name);
      request.onsuccess =
        request.onerror =
        request.onblocked =
          () => {
            report.databases += 1;
            resolve();
          };
    });
  }

  try {
    for (const key of Object.keys(localStorage)) {
      if (key.startsWith('visionhud')) {
        localStorage.removeItem(key);
        report.keys += 1;
      }
    }
  } catch {
    /* gesperrter Speicher */
  }

  // Auch der Zwischenspeicher der PWA – sonst blieben alte Stände liegen.
  try {
    for (const name of await caches.keys()) {
      if (name.startsWith('visionhud')) await caches.delete(name);
    }
  } catch {
    /* kein Cache vorhanden */
  }

  return report;
}
