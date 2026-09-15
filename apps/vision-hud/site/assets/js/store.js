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
  constructor() {
    this.db = null;
    /** Wird auf true gesetzt, sobald IndexedDB nicht nutzbar ist. */
    this.usesFallback = false;
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
    if (this.usesFallback) return readFallback();

    const tx = this.db.transaction(STORE, 'readonly');
    const rows = await toPromise(tx.objectStore(STORE).getAll());
    return rows.map(reviveRecord).sort((a, b) => a.name.localeCompare(b.name, 'de'));
  }

  async put(person) {
    await this.open();
    if (this.usesFallback) {
      const rows = readFallback().filter((row) => row.id !== person.id);
      rows.push(person);
      writeFallback(rows);
      return person;
    }

    const tx = this.db.transaction(STORE, 'readwrite');
    tx.objectStore(STORE).put(serialiseRecord(person));
    await new Promise((resolve, reject) => {
      tx.oncomplete = resolve;
      tx.onerror = () => reject(tx.error ?? new Error('Speichern fehlgeschlagen'));
    });
    return person;
  }

  async remove(id) {
    await this.open();
    if (this.usesFallback) {
      writeFallback(readFallback().filter((row) => row.id !== id));
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
      writeFallback([]);
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

function reviveRecord(row) {
  return { ...row, descriptors: (row.descriptors ?? []).map((d) => Float32Array.from(d)) };
}

function readFallback() {
  try {
    const raw = localStorage.getItem(FALLBACK_KEY);
    if (!raw) return [];
    return JSON.parse(raw).map(reviveRecord);
  } catch {
    return [];
  }
}

function writeFallback(rows) {
  try {
    localStorage.setItem(FALLBACK_KEY, JSON.stringify(rows.map(serialiseRecord)));
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
