// localStorage mit Sicherheitsnetz.
//
// Es gibt drei Fälle, in denen der direkte Zugriff eine Ausnahme wirft und das
// Spiel sonst schon beim Start abstürzt:
//   - Safari im privaten Modus wirft bei setItem (Kontingent 0),
//   - manche Browser werfen schon beim blossen Zugriff über file://,
//   - Nutzer können Website-Daten komplett sperren.
// In allen dreien fällt der Speicher stillschweigend auf eine Ablage im
// Arbeitsspeicher zurück: die Einstellungen halten dann nur bis zum Neuladen,
// aber nichts geht kaputt.

const memory = new Map();
let available = null;

function probe() {
  if (available !== null) return available;
  try {
    const key = '__hc_probe__';
    window.localStorage.setItem(key, '1');
    window.localStorage.removeItem(key);
    available = true;
  } catch {
    available = false;
  }
  return available;
}

export function readString(key, fallback = null) {
  if (!probe()) return memory.has(key) ? memory.get(key) : fallback;
  try {
    const value = window.localStorage.getItem(key);
    return value === null ? fallback : value;
  } catch {
    return fallback;
  }
}

export function writeString(key, value) {
  if (!probe()) {
    memory.set(key, value);
    return;
  }
  try {
    window.localStorage.setItem(key, value);
  } catch {
    memory.set(key, value);
  }
}

export function readJson(key, fallback) {
  const raw = readString(key, null);
  if (raw === null) return fallback;
  try {
    const parsed = JSON.parse(raw);
    return parsed === null ? fallback : parsed;
  } catch {
    // Beschädigter Eintrag (halb geschrieben, von Hand verändert): verwerfen
    // statt das Spiel daran scheitern zu lassen.
    return fallback;
  }
}

export function writeJson(key, value) {
  try {
    writeString(key, JSON.stringify(value));
  } catch {
    // Zirkuläre Struktur wäre ein Programmierfehler – hier trotzdem nicht
    // den Spielfluss abbrechen.
  }
}

/** Ob echte Persistenz zur Verfügung steht (für den Hinweis in der Anzeige). */
export function isPersistent() {
  return probe();
}
