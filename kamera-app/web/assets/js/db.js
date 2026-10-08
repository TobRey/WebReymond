// IndexedDB: Downloadliste, übertragene Teilstücke (für Wiederaufnahme) und Datei-Infos.

const DB_NAME = 'kamera-link';
const DB_VERSION = 1;
let dbp = null;

function open() {
  if (dbp) return dbp;
  dbp = new Promise((resolve, reject) => {
    const req = indexedDB.open(DB_NAME, DB_VERSION);
    req.onupgradeneeded = () => {
      const db = req.result;
      if (!db.objectStoreNames.contains('transfers')) db.createObjectStore('transfers', { keyPath: 'id' });
      if (!db.objectStoreNames.contains('chunks')) db.createObjectStore('chunks', { keyPath: ['tid', 'idx'] });
      if (!db.objectStoreNames.contains('info')) db.createObjectStore('info', { keyPath: 'key' });
      if (!db.objectStoreNames.contains('kv')) db.createObjectStore('kv', { keyPath: 'k' });
    };
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });
  return dbp;
}

function wrap(req) {
  return new Promise((resolve, reject) => {
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });
}

async function tx(store, mode, fn) {
  const db = await open();
  return new Promise((resolve, reject) => {
    const t = db.transaction(store, mode);
    let result;
    Promise.resolve(fn(t.objectStore(store))).then((r) => (result = r));
    t.oncomplete = () => resolve(result);
    t.onerror = () => reject(t.error);
    t.onabort = () => reject(t.error || new Error('abgebrochen'));
  });
}

export const db = {
  get: (store, key) => tx(store, 'readonly', (s) => wrap(s.get(key))),
  put: (store, value) => tx(store, 'readwrite', (s) => wrap(s.put(value))),
  del: (store, key) => tx(store, 'readwrite', (s) => wrap(s.delete(key))),
  all: (store) => tx(store, 'readonly', (s) => wrap(s.getAll())),

  chunkRange(tid) {
    return IDBKeyRange.bound([tid, 0], [tid, Number.MAX_SAFE_INTEGER]);
  },
  chunks(tid) {
    return tx('chunks', 'readonly', (s) => wrap(s.getAll(this.chunkRange(tid))));
  },
  deleteChunks(tid) {
    return tx('chunks', 'readwrite', (s) => wrap(s.delete(this.chunkRange(tid))));
  },
};

export async function storageEstimate() {
  try {
    return await navigator.storage?.estimate?.();
  } catch {
    return null;
  }
}
