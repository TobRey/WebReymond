// Sichern auf dem iPhone – nur über tatsächlich vorhandene Systemfunktionen:
//  native  – iOS-Hülle (optional) speichert direkt in die Fotos-App (PhotoKit)
//  share   – Teilen-Menü (Web Share API, nur über HTTPS): "Bild/Video sichern" oder "In Dateien sichern"
//  download – Safari-Download in die Dateien-App (über HTTP einzig möglicher Weg)

const native = () => window.webkit?.messageHandlers?.kameraNative;

export function saveMode() {
  if (native()) return 'native';
  try {
    if (window.isSecureContext && navigator.canShare &&
        navigator.canShare({ files: [new File(['x'], 't.jpg', { type: 'image/jpeg' })] })) return 'share';
  } catch { /* nicht unterstützt */ }
  return 'download';
}

export const SAVE_TEXT = {
  native: { title: 'In Fotos sichern', hint: 'Speichert die Originale direkt in die Fotos-App.' },
  share: { title: 'Sichern / Teilen', hint: 'Im Teilen-Menü „Bild sichern“ bzw. „Video sichern“ wählen → Fotos-App. RAW: „In Dateien sichern“.' },
  download: { title: 'In Dateien laden', hint: 'Über HTTP erlaubt Safari nur den Download in die Dateien-App. Für die Fotos-App die sichere Verbindung (HTTPS) der Brücke nutzen.' },
};

let seq = 0;
const pending = new Map();
window.__kameraNativeReply = (id, ok, message) => {
  const p = pending.get(id);
  if (!p) return;
  pending.delete(id);
  ok ? p.resolve(message) : p.reject(new Error(message || 'Fehler'));
};

/** Native Hülle lädt das Original selbst direkt von der Kamera und legt es in Fotos ab. */
export function nativeSave(items) {
  const id = ++seq;
  return new Promise((resolve, reject) => {
    pending.set(id, { resolve, reject });
    native().postMessage({ id, action: 'save', items: items.map((i) => ({ path: i.path, name: i.name })) });
  });
}

export async function shareFiles(files) {
  await navigator.share({ files });
}

export async function downloadBlob(blob, name) {
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = name;
  a.rel = 'noopener';
  document.body.append(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 60000);
}

/** ZIP der Originale (unkomprimiert, Bytes unverändert) direkt von der Brücke. */
export function downloadZip(paths) {
  const form = document.createElement('form');
  form.method = 'POST';
  form.action = 'bridge/zip';
  const input = document.createElement('input');
  input.type = 'hidden';
  input.name = 'paths';
  input.value = JSON.stringify(paths);
  form.append(input);
  document.body.append(form);
  form.submit();
  form.remove();
}
