/**
 * AI Groove – Verwaltung des KI-Zugangs.
 *
 * WICHTIGE REGELN (bewusst hier dokumentiert):
 *  - Der API-Key gehoert dem Nutzer und bleibt auf dessen Geraet.
 *  - Er wird NIE auf dem Server gespeichert, nie geloggt und nie in eine
 *    .aigroove-Projektdatei geschrieben.
 *  - Er verlaesst das Geraet nur als Header genau der Anfrage, die der Nutzer
 *    ausgeloest hat (direkt zum Anbieter oder durch den eigenen PHP-Proxy).
 *  - "Nur diese Sitzung" nutzt sessionStorage: beim Schliessen des Tabs weg.
 */

const STORAGE_KEY = 'aigroove.ai.v1';

/** @typedef {{providerId:string, key:string, mode:'local'|'session', transport:'proxy'|'direct'}} Connection */

function storageFor(mode) {
  try {
    return mode === 'session' ? window.sessionStorage : window.localStorage;
  } catch (_) {
    return null;
  }
}

function readFrom(store) {
  if (!store) return null;
  try {
    const raw = store.getItem(STORAGE_KEY);
    if (!raw) return null;
    const parsed = JSON.parse(raw);
    if (!parsed || typeof parsed.key !== 'string' || !parsed.providerId) return null;
    return parsed;
  } catch (_) {
    return null;
  }
}

class KeyStore {
  constructor() {
    /** @type {Connection|null} */
    this.connection = readFrom(storageFor('session')) || readFrom(storageFor('local'));
  }

  get isConnected() {
    return !!(this.connection && this.connection.key);
  }

  get providerId() {
    return this.connection?.providerId || 'local';
  }

  get transport() {
    return this.connection?.transport || 'proxy';
  }

  get mode() {
    return this.connection?.mode || 'local';
  }

  /** Nur zur Anzeige: die ersten und letzten Zeichen. */
  maskedKey() {
    const k = this.connection?.key || '';
    if (k.length <= 10) return '••••••••';
    return `${k.slice(0, 4)}${'•'.repeat(Math.min(18, k.length - 8))}${k.slice(-4)}`;
  }

  /** Liefert den Key. Ausschliesslich fuer den unmittelbaren Request verwenden. */
  peekKey() {
    return this.connection?.key || '';
  }

  /**
   * Speichert eine Verbindung.
   * @param {Connection} conn
   */
  save(conn) {
    const clean = {
      providerId: String(conn.providerId),
      key: String(conn.key).trim(),
      mode: conn.mode === 'session' ? 'session' : 'local',
      transport: conn.transport === 'direct' ? 'direct' : 'proxy',
      savedAt: Date.now(),
    };
    this.clear();
    this.connection = clean;
    const store = storageFor(clean.mode);
    try {
      store?.setItem(STORAGE_KEY, JSON.stringify(clean));
    } catch (_) {
      // Kein Speicher verfuegbar – der Key gilt dann nur bis zum Neuladen.
    }
    return clean;
  }

  /** Entfernt die Verbindung vollstaendig aus beiden Speichern. */
  clear() {
    for (const mode of ['local', 'session']) {
      try {
        storageFor(mode)?.removeItem(STORAGE_KEY);
      } catch (_) {
        /* egal */
      }
    }
    this.connection = null;
  }

  /** Einfache Plausibilitaetspruefung, damit Tippfehler frueh auffallen. */
  static validateKey(providerId, key) {
    const k = String(key || '').trim();
    if (!k) return 'Bitte einen API-Key eingeben.';
    if (k.length < 16) return 'Der Key sieht zu kurz aus. Bitte vollständig einfügen.';
    if (/\s/.test(k)) return 'Der Key darf keine Leerzeichen enthalten.';
    if (providerId === 'stability' && !/^sk-/.test(k)) {
      return 'Stability-Keys beginnen üblicherweise mit „sk-“. Bitte prüfen.';
    }
    return null;
  }
}

export const keystore = new KeyStore();
export { KeyStore };
