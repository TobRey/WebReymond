// Der Zustand des Admin-Bereichs und der Weg zum Server.
//
// Zwei Betriebsarten, genau wie bei der Bestenliste:
//
//   'server' – admin.php antwortet. Gespeichert wird auf dem Server, alle
//              Besucher sehen die Grafik.
//   'local'  – kein PHP. Alles bleibt im Browser (localStorage), und der Knopf
//              „Als Datei laden" gibt eine assets.json heraus, die man selbst
//              nach data/ hochladen kann.
//
// Die zweite Art ist kein Notbehelf für kaputte Server, sondern der Normalfall
// für jeden, der die ZIP auf einen Webspace ohne PHP legt.

const LOCAL_KEY = 'mogli.admin.pack.v1';
const TOKEN_KEY = 'mogli.admin.token.v1';

/** @type {'server'|'local'|'unknown'} */
let mode = 'unknown';
let token = null;

export function getMode() {
  return mode;
}

export function getToken() {
  return token;
}

export function isSignedIn() {
  return mode === 'local' || token !== null;
}

function readSession(key) {
  try {
    return window.sessionStorage.getItem(key);
  } catch {
    return null;
  }
}

function writeSession(key, value) {
  try {
    if (value === null) window.sessionStorage.removeItem(key);
    else window.sessionStorage.setItem(key, value);
  } catch {
    // Privater Modus: dann eben nur für diesen Seitenaufruf.
  }
}

/**
 * admin.php liegt eine Ebene höher, im Wurzelverzeichnis des Spiels – dort, wo
 * auch score.php und assets.php liegen. Diese Seite liegt in admin/, deshalb
 * das `../`: ohne wäre es admin/admin.php, und das gibt es nicht.
 */
const ENDPOINT = '../admin.php';

async function post(payload) {
  const response = await fetch(ENDPOINT, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  });
  // Kein PHP heisst hier: die Antwort ist kein JSON. Ein Webspace ohne PHP
  // liefert die Datei als Text aus, ein anderer wirft eine HTML-Fehlerseite –
  // beides scheitert an dieser Stelle, und beides bedeutet dasselbe.
  try {
    return (await response.json()) ?? { ok: false, error: 'no_php' };
  } catch {
    return { ok: false, error: 'no_php' };
  }
}

/**
 * Prüft einmal, ob es admin.php überhaupt gibt.
 *
 * Bewusst ein GET: admin.php beantwortet alles ausser POST mit einem sauberen
 * `method_not_allowed` in JSON. Kommt gültiges JSON zurück, läuft dort PHP;
 * kommt etwas anderes (Textdatei, HTML-Fehlerseite), gibt es kein PHP.
 *
 * Ein Anmeldeversuch mit falschem Code täte es auch – würde aber jedes Mal
 * einen der fünf Versuche verbrauchen. Zweimal die Seite öffnen, und man wäre
 * ausgesperrt, bevor man den Code überhaupt getippt hat.
 */
export async function probe() {
  try {
    const response = await fetch(ENDPOINT, { headers: { Accept: 'application/json' } });
    const data = await response.json();
    mode = typeof data?.ok === 'boolean' ? 'server' : 'local';
  } catch {
    mode = 'local';
  }

  if (mode === 'server') {
    const stored = readSession(TOKEN_KEY);
    if (stored !== null) token = stored;
  }
  return mode;
}

/** @returns {Promise<{ok: true} | {ok: false, error: string}>} */
export async function signIn(code) {
  if (mode === 'local') return { ok: true };

  const data = await post({ action: 'login', code });
  if (data.ok !== true) return { ok: false, error: data.error ?? 'unknown' };

  token = data.token;
  writeSession(TOKEN_KEY, token);
  return { ok: true };
}

export function signOut() {
  token = null;
  writeSession(TOKEN_KEY, null);
}

/** Holt das gespeicherte Paket. */
export async function load() {
  if (mode === 'local') {
    try {
      const raw = window.localStorage.getItem(LOCAL_KEY);
      return raw === null ? { version: 1 } : JSON.parse(raw);
    } catch {
      return { version: 1 };
    }
  }

  const data = await post({ action: 'load', token });
  if (data.ok !== true) throw new Error(data.error ?? 'unknown');
  return data.pack ?? { version: 1 };
}

/** Speichert das Paket. */
export async function save(pack) {
  if (mode === 'local') {
    try {
      window.localStorage.setItem(LOCAL_KEY, JSON.stringify(pack));
    } catch {
      throw new Error('local_full');
    }
    return { bytes: JSON.stringify(pack).length };
  }

  const data = await post({ action: 'save', token, pack });
  if (data.ok !== true) {
    const error = new Error(data.error ?? 'unknown');
    error.detail = data.detail;
    throw error;
  }
  return { bytes: data.bytes ?? 0 };
}

/** Leert das Paket wieder. */
export async function clearStored() {
  if (mode === 'local') {
    try {
      window.localStorage.removeItem(LOCAL_KEY);
    } catch {
      // Nichts zu tun.
    }
    return;
  }
  const data = await post({ action: 'clear', token });
  if (data.ok !== true) throw new Error(data.error ?? 'unknown');
}

// ---------------------------------------------------------------------------
// Karten (der Editor speichert hier)
// ---------------------------------------------------------------------------

const LOCAL_MAPS_KEY = 'mogli.maps.v1';

/**
 * Holt die gespeicherten Karten. Im local-Modus derselbe Schlüssel, aus dem
 * auch das SPIEL liest (net/maps.js) – Editor und Spiel teilen sich die
 * Ablage, gespeichert ist gespielt.
 */
export async function loadMaps() {
  if (mode === 'local') {
    try {
      const raw = window.localStorage.getItem(LOCAL_MAPS_KEY);
      const parsed = raw === null ? [] : JSON.parse(raw);
      return Array.isArray(parsed) ? parsed : [];
    } catch {
      return [];
    }
  }
  const data = await post({ action: 'maps_load', token });
  if (data.ok !== true) throw new Error(data.error ?? 'unknown');
  return Array.isArray(data.maps) ? data.maps : [];
}

/** Speichert alle Karten. */
export async function saveMaps(maps) {
  if (mode === 'local') {
    try {
      window.localStorage.setItem(LOCAL_MAPS_KEY, JSON.stringify(maps));
    } catch {
      throw new Error('local_full');
    }
    return { count: maps.length };
  }
  const data = await post({ action: 'maps_save', token, maps });
  if (data.ok !== true) {
    const error = new Error(data.error ?? 'unknown');
    error.detail = data.detail;
    throw error;
  }
  return { count: data.count ?? maps.length };
}

/** Karten als Datei – für Webspaces ohne PHP: nach data/maps.json hochladen. */
export function downloadMaps(maps) {
  const blob = new Blob([JSON.stringify({ version: 1, updated: 0, maps })], {
    type: 'application/json',
  });
  triggerDownload(blob, 'maps.json');
}

function triggerDownload(blob, filename) {
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = filename;
  document.body.append(link);
  link.click();
  link.remove();
  window.setTimeout(() => URL.revokeObjectURL(url), 10_000);
}

/**
 * Gibt das Paket als Datei heraus – der Weg für Webspaces ohne PHP: die Datei
 * nach data/assets.json hochladen, fertig.
 */
export function download(pack) {
  const blob = new Blob([JSON.stringify({ version: 1, updated: 0, pack })], {
    type: 'application/json',
  });
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = 'assets.json';
  document.body.append(link);
  link.click();
  link.remove();
  // Nicht sofort freigeben: manche Browser holen die Daten erst danach ab.
  window.setTimeout(() => URL.revokeObjectURL(url), 10_000);
}
