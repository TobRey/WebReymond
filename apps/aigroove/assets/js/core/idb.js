/**
 * AI Groove – IndexedDB-Schicht.
 *
 * Aufbau:
 *   projects : { id, name, updatedAt, createdAt, data }   – Projektzustand ohne Audio
 *   audio    : { id, mime, bytes, size, createdAt }       – kodierte Audiodaten (WAV/MP3/…)
 *   meta     : { key, value }                             – App-Zustand, Einstellungen
 *
 * Audio wird bewusst KODIERT gespeichert (Originaldatei bzw. WAV bei Aufnahmen),
 * nicht als dekodierte Float-Daten: das spart ein Vielfaches an Speicherplatz.
 */

const DB_NAME = 'aigroove';
const DB_VERSION = 1;

let dbPromise = null;

export class StorageError extends Error {
  constructor(code, message, cause) {
    super(message);
    this.name = 'StorageError';
    this.code = code;
    this.cause = cause;
  }
}

function openDB() {
  if (dbPromise) return dbPromise;

  dbPromise = new Promise((resolve, reject) => {
    if (!('indexedDB' in window)) {
      reject(new StorageError('unsupported', 'Dieser Browser unterstützt keine lokale Speicherung (IndexedDB).'));
      return;
    }

    let req;
    try {
      req = indexedDB.open(DB_NAME, DB_VERSION);
    } catch (err) {
      reject(new StorageError('blocked', 'Der lokale Speicher ist nicht verfügbar (evtl. privater Modus).', err));
      return;
    }

    req.onupgradeneeded = () => {
      const db = req.result;
      if (!db.objectStoreNames.contains('projects')) {
        const store = db.createObjectStore('projects', { keyPath: 'id' });
        store.createIndex('updatedAt', 'updatedAt');
      }
      if (!db.objectStoreNames.contains('audio')) {
        db.createObjectStore('audio', { keyPath: 'id' });
      }
      if (!db.objectStoreNames.contains('meta')) {
        db.createObjectStore('meta', { keyPath: 'key' });
      }
    };

    req.onsuccess = () => {
      const db = req.result;
      db.onversionchange = () => db.close();
      resolve(db);
    };

    req.onerror = () =>
      reject(new StorageError('open_failed', 'Der lokale Speicher konnte nicht geöffnet werden.', req.error));

    req.onblocked = () =>
      reject(new StorageError('blocked', 'Eine andere AI-Groove-Registerkarte blockiert den Speicher.'));
  });

  return dbPromise;
}

function tx(db, stores, mode) {
  const transaction = db.transaction(stores, mode);
  const done = new Promise((resolve, reject) => {
    transaction.oncomplete = () => resolve();
    transaction.onabort = () => reject(mapError(transaction.error));
    transaction.onerror = () => reject(mapError(transaction.error));
  });
  return { transaction, done };
}

function mapError(err) {
  if (!err) return new StorageError('unknown', 'Unbekannter Speicherfehler.');
  if (err.name === 'QuotaExceededError' || err.code === 22) {
    return new StorageError(
      'quota',
      'Der lokale Speicher ist voll. Bitte alte Projekte löschen oder das Projekt als .aigroove exportieren.',
      err,
    );
  }
  return new StorageError('io', 'Speichern fehlgeschlagen.', err);
}

function request(store, method, ...args) {
  return new Promise((resolve, reject) => {
    let req;
    try {
      req = store[method](...args);
    } catch (err) {
      reject(mapError(err));
      return;
    }
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(mapError(req.error));
  });
}

// --- Oeffentliche API --------------------------------------------------------

export async function put(storeName, value) {
  const db = await openDB();
  const { transaction, done } = tx(db, [storeName], 'readwrite');
  const result = request(transaction.objectStore(storeName), 'put', value);
  await done;
  return result;
}

export async function putMany(storeName, values) {
  if (!values.length) return;
  const db = await openDB();
  const { transaction, done } = tx(db, [storeName], 'readwrite');
  const store = transaction.objectStore(storeName);
  for (const v of values) store.put(v);
  await done;
}

export async function get(storeName, key) {
  const db = await openDB();
  const { transaction } = tx(db, [storeName], 'readonly');
  return request(transaction.objectStore(storeName), 'get', key);
}

export async function getAll(storeName) {
  const db = await openDB();
  const { transaction } = tx(db, [storeName], 'readonly');
  return request(transaction.objectStore(storeName), 'getAll');
}

export async function getAllKeys(storeName) {
  const db = await openDB();
  const { transaction } = tx(db, [storeName], 'readonly');
  return request(transaction.objectStore(storeName), 'getAllKeys');
}

export async function del(storeName, key) {
  const db = await openDB();
  const { transaction, done } = tx(db, [storeName], 'readwrite');
  transaction.objectStore(storeName).delete(key);
  await done;
}

export async function delMany(storeName, keys) {
  if (!keys.length) return;
  const db = await openDB();
  const { transaction, done } = tx(db, [storeName], 'readwrite');
  const store = transaction.objectStore(storeName);
  for (const k of keys) store.delete(k);
  await done;
}

export async function clearStore(storeName) {
  const db = await openDB();
  const { transaction, done } = tx(db, [storeName], 'readwrite');
  transaction.objectStore(storeName).clear();
  await done;
}

// --- Bequeme Wrapper ---------------------------------------------------------

export const meta = {
  async get(key, fallback = null) {
    try {
      const row = await get('meta', key);
      return row ? row.value : fallback;
    } catch (_) {
      return fallback;
    }
  },
  async set(key, value) {
    return put('meta', { key, value });
  },
  async del(key) {
    return del('meta', key);
  },
};

export const audioStore = {
  /**
   * Legt kodierte Audiodaten ab.
   * @param {string} id
   * @param {ArrayBuffer|Uint8Array} bytes
   * @param {string} mime
   */
  async put(id, bytes, mime) {
    const buf = bytes instanceof Uint8Array ? bytes.buffer.slice(bytes.byteOffset, bytes.byteOffset + bytes.byteLength) : bytes;
    await put('audio', { id, mime, bytes: buf, size: buf.byteLength, createdAt: Date.now() });
    return id;
  },
  async get(id) {
    return get('audio', id);
  },
  async del(id) {
    return del('audio', id);
  },
  async delMany(ids) {
    return delMany('audio', ids);
  },
  async keys() {
    return getAllKeys('audio');
  },
  async totalSize() {
    const rows = await getAll('audio');
    return rows.reduce((sum, r) => sum + (r.size || 0), 0);
  },
};

export const projectStore = {
  async list() {
    const rows = await getAll('projects');
    return rows.sort((a, b) => (b.updatedAt || 0) - (a.updatedAt || 0));
  },
  async get(id) {
    return get('projects', id);
  },
  async save(project) {
    return put('projects', {
      id: project.id,
      name: project.name,
      createdAt: project.createdAt,
      updatedAt: project.updatedAt,
      data: project,
    });
  },
  async del(id) {
    return del('projects', id);
  },
};

/**
 * Entfernt Audiodaten, die von keinem gespeicherten Projekt mehr referenziert werden.
 * Wird nach dem Loeschen von Projekten und beim Start aufgerufen.
 */
export async function collectGarbage(activeProject = null) {
  try {
    const [projects, keys] = await Promise.all([projectStore.list(), audioStore.keys()]);
    const used = new Set();
    const collect = (p) => {
      for (const s of p?.samples || []) {
        if (s.dataId) used.add(s.dataId);
        for (const v of s.variants || []) if (v.dataId) used.add(v.dataId);
      }
    };
    for (const row of projects) collect(row.data);
    if (activeProject) collect(activeProject);

    const orphans = keys.filter((k) => !used.has(k));
    if (orphans.length) await audioStore.delMany(orphans);
    return orphans.length;
  } catch (err) {
    console.warn('[idb] Aufräumen fehlgeschlagen', err);
    return 0;
  }
}

/** Speicherbelegung schaetzen (falls der Browser es meldet). */
export async function estimateStorage() {
  try {
    if (navigator.storage?.estimate) {
      const est = await navigator.storage.estimate();
      return { usage: est.usage || 0, quota: est.quota || 0, supported: true };
    }
  } catch (_) {
    /* egal */
  }
  return { usage: 0, quota: 0, supported: false };
}

/** Bittet den Browser, den Speicher nicht automatisch zu leeren. */
export async function requestPersistence() {
  try {
    if (navigator.storage?.persist) {
      if (await navigator.storage.persisted()) return true;
      return await navigator.storage.persist();
    }
  } catch (_) {
    /* egal */
  }
  return false;
}

/** Loescht die gesamte lokale Datenbank. */
export async function wipeAll() {
  await Promise.all([clearStore('projects'), clearStore('audio')]);
}
