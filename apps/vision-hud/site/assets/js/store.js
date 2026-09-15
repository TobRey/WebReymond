/**
 * Dauerhafte Ablage der erfassten Gesichter – ausschliesslich auf dem Gerät.
 *
 * Gespeichert wird pro Person: Name, Alter, ein kleines Vorschaubild und
 * mehrere 128-stellige Merkmalsvektoren. Aus einem solchen Vektor lässt sich
 * kein Bild zurückrechnen; er ist trotzdem ein personenbezogenes Datum und
 * verlässt deshalb weder den Browser noch das Gerät.
 *
 * Erste Wahl ist IndexedDB, weil es Float32Array direkt speichert. Wo das
 * gesperrt ist (privates Fenster, alte WebView), fällt der Speicher
 * automatisch auf localStorage zurück.
 */

const DB_NAME = 'visionhud';
const DB_VERSION = 1;
const STORE = 'people';
const FALLBACK_KEY = 'visionhud.people.v1';

/** Wandelt eine IndexedDB-Anfrage in ein Promise. */
function toPromise(request) {
  return new Promise((resolve, reject) => {
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error ?? new Error('IndexedDB-Fehler'));
  });
}

export class PeopleStore {
  /**
   * @param {import('./privacy.js').Vault} [vault] Wenn ein Kennwort gesetzt
   *        ist, werden Name, Alter, Notiz, Bild und Merkmalsvektoren
   *        verschlüsselt abgelegt (siehe privacy.js).
   */
  constructor(vault = null) {
    this.db = null;
    /** Wird auf true gesetzt, sobald IndexedDB nicht nutzbar ist. */
    this.usesFallback = false;
    this.vault = vault;
    this.#locked = 0;
  }

  /** Anzahl Datensätze, die hinter dem Kennwort liegen. */
  #locked = 0;

  /** Datensätze, die verschlüsselt sind, ohne dass der Tresor offen ist. */
  get hasLockedRows() {
    return this.#locked > 0;
  }

  async open() {
    if (this.db || this.usesFallback) return;

    if (typeof indexedDB === 'undefined') {
      this.usesFallback = true;
      return;
    }

    try {
      this.db = await new Promise((resolve, reject) => {
        const request = indexedDB.open(DB_NAME, DB_VERSION);
        request.onupgradeneeded = () => {
          const db = request.result;
          if (!db.objectStoreNames.contains(STORE)) {
            db.createObjectStore(STORE, { keyPath: 'id' });
          }
        };
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error ?? new Error('IndexedDB gesperrt'));
        // Ein privates Fenster lässt open() gelegentlich hängen statt zu scheitern.
        setTimeout(() => reject(new Error('Zeitüberschreitung')), 4000);
      });
    } catch {
      this.usesFallback = true;
    }
  }

  async all() {
    await this.open();
    const rows = this.usesFallback
      ? readFallbackRaw()
      : await toPromise(this.db.transaction(STORE, 'readonly').objectStore(STORE).getAll());

    const people = [];
    this.#locked = 0;

    for (const row of rows) {
      if (row.sealed) {
        // Verschlüsselter Datensatz: nur lesbar, wenn der Tresor offen ist.
        if (!this.vault?.key) {
          this.#locked += 1;
          continue;
        }
        try {
          people.push(await this.vault.openPerson(row));
        } catch {
          this.#locked += 1;
        }
      } else {
        people.push(reviveRecord(row));
      }
    }

    return people.sort((a, b) => a.name.localeCompare(b.name, 'de'));
  }

  async put(person) {
    await this.open();
    // Steht ein Kennwort und ist der Tresor offen, wird verschlüsselt abgelegt.
    const record = this.vault?.key ? await this.vault.sealPerson(person) : serialiseRecord(person);

    if (this.usesFallback) {
      const rows = readFallbackRaw().filter((row) => row.id !== person.id);
      rows.push(record);
      writeFallbackRaw(rows);
      return person;
    }

    const tx = this.db.transaction(STORE, 'readwrite');
    tx.objectStore(STORE).put(record);
    await new Promise((resolve, reject) => {
      tx.oncomplete = resolve;
      tx.onerror = () => reject(tx.error ?? new Error('Speichern fehlgeschlagen'));
    });
    return person;
  }

  async remove(id) {
    await this.open();
    if (this.usesFallback) {
      writeFallbackRaw(readFallbackRaw().filter((row) => row.id !== id));
      return;
    }
    const tx = this.db.transaction(STORE, 'readwrite');
    tx.objectStore(STORE).delete(id);
    await new Promise((resolve) => {
      tx.oncomplete = resolve;
    });
  }

  async clear() {
    await this.open();
    if (this.usesFallback) {
      writeFallbackRaw([]);
      return;
    }
    const tx = this.db.transaction(STORE, 'readwrite');
    tx.objectStore(STORE).clear();
    await new Promise((resolve) => {
      tx.oncomplete = resolve;
    });
  }
}

/* --------------------------------------------------------------------------
 * Umwandlung für die Ablage
 *
 * IndexedDB kann Float32Array, JSON kann es nicht. Beim Sichern und beim
 * Rückfallspeicher werden die Vektoren deshalb zu normalen Zahlenlisten.
 * -------------------------------------------------------------------------- */

function serialiseRecord(person) {
  return { ...person, descriptors: person.descriptors.map((d) => Array.from(d)) };
}

/**
 * Wandelt alles um, was zwischen zwei Sitzungen erhalten bleiben muss.
 * Verschlüsselte Datensätze werden dabei nicht angefasst.
 */

function reviveRecord(row) {
  return { ...row, descriptors: (row.descriptors ?? []).map((d) => Float32Array.from(d)) };
}

function readFallbackRaw() {
  try {
    const raw = localStorage.getItem(FALLBACK_KEY);
    return raw ? JSON.parse(raw) : [];
  } catch {
    return [];
  }
}

function writeFallbackRaw(rows) {
  try {
    localStorage.setItem(FALLBACK_KEY, JSON.stringify(rows));
  } catch {
    /* Voller oder gesperrter Speicher: Die Sitzung läuft ohne Ablage weiter. */
  }
}

/* --------------------------------------------------------------------------
 * Abgleich
 * -------------------------------------------------------------------------- */

/** Euklidischer Abstand zweier gleich langer Merkmalsvektoren. */
export function distance(a, b) {
  let sum = 0;
  for (let i = 0; i < a.length; i += 1) {
    const d = a[i] - b[i];
    sum += d * d;
  }
  return Math.sqrt(sum);
}

/**
 * Hält die bekannten Personen im Arbeitsspeicher und beantwortet die Frage
 * "Wer ist das?" – der Abgleich läuft bei jedem erkannten Gesicht und darf
 * deshalb nicht erst die Datenbank fragen.
 */
export class Matcher {
  constructor() {
    this.people = [];
  }

  load(people) {
    this.people = people;
  }

  get size() {
    return this.people.length;
  }

  /**
   * Sucht die ähnlichste bekannte Person.
   * @param {Float32Array} descriptor Merkmalsvektor des gesehenen Gesichts
   * @param {number} threshold Abstand, bis zu dem eine Person als erkannt gilt
   * @returns {{person: object|null, distance: number}}
   */
  match(descriptor, threshold) {
    let best = null;
    let bestDistance = Number.POSITIVE_INFINITY;

    for (const person of this.people) {
      for (const known of person.descriptors) {
        const d = distance(descriptor, known);
        if (d < bestDistance) {
          bestDistance = d;
          best = person;
        }
      }
    }

    return bestDistance <= threshold
      ? { person: best, distance: bestDistance }
      : { person: null, distance: bestDistance };
  }
}

/** Erzeugt eine Kennung, die auch ohne crypto.randomUUID funktioniert. */
export function makeId() {
  if (globalThis.crypto?.randomUUID) return globalThis.crypto.randomUUID();
  return `p-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;
}
