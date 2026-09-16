/**
 * Service Worker – macht die Seite installierbar und offline lauffähig.
 *
 * Zwei Strategien, aus einem Grund getrennt:
 *
 *   Modelle und Bibliotheken  →  zuerst aus dem Zwischenspeicher.
 *      Diese Dateien ändern sich nie (ihre Namen sind fest) und sind zusammen
 *      über 30 MB. Sie jedes Mal neu zu prüfen wäre pure Verschwendung.
 *
 *   Alles andere  →  zuerst aus dem Netz, Zwischenspeicher als Rückfall.
 *      Damit eine neue Fassung der Seite sofort ankommt und nicht erst nach
 *      einem Neustart des Browsers.
 *
 * Der Scope ergibt sich aus dem Ort dieser Datei – deshalb funktioniert das
 * auch in einem Unterordner, ohne dass hier etwas einzutragen wäre.
 */

const VERSION = 'visionhud-v3';
const SHELL = `${VERSION}-shell`;
const HEAVY = `${VERSION}-modelle`;

/** Das Nötigste, damit die Seite offline überhaupt startet. */
const SHELL_FILES = [
  './',
  './index.html',
  './pruefung.html',
  './assets/css/hud.css',
  './assets/js/app.js',
  './assets/manifest.webmanifest',
  './assets/icons/favicon.svg',
  './assets/icons/touch-icon.png',
];

const isHeavy = (url) =>
  url.includes('/assets/models/') ||
  url.includes('/assets/vendor/') ||
  url.endsWith('.bin') ||
  url.endsWith('.wasm') ||
  url.endsWith('.traineddata.gz');

self.addEventListener('install', (event) => {
  event.waitUntil(
    (async () => {
      const cache = await caches.open(SHELL);
      // Einzeln statt addAll: Eine fehlende Datei soll nicht die
      // gesamte Installation scheitern lassen.
      await Promise.all(SHELL_FILES.map((file) => cache.add(file).catch(() => undefined)));
      await self.skipWaiting();
    })(),
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    (async () => {
      for (const name of await caches.keys()) {
        if (name.startsWith('visionhud-') && !name.startsWith(VERSION)) {
          await caches.delete(name);
        }
      }
      await self.clients.claim();
    })(),
  );
});

self.addEventListener('fetch', (event) => {
  const { request } = event;
  if (request.method !== 'GET') return;

  const url = new URL(request.url);
  // Fremde Domains gehen den Service Worker nichts an – erst recht nicht
  // die Anfragen an einen KI-Dienst.
  if (url.origin !== self.location.origin) return;

  if (isHeavy(url.pathname)) {
    event.respondWith(cacheFirst(request));
    return;
  }
  event.respondWith(networkFirst(request));
});

async function cacheFirst(request) {
  const cache = await caches.open(HEAVY);
  const hit = await cache.match(request);
  if (hit) return hit;

  const response = await fetch(request);
  // Teilantworten (206) lassen sich nicht zwischenspeichern.
  if (response.ok && response.status === 200) cache.put(request, response.clone());
  return response;
}

async function networkFirst(request) {
  const cache = await caches.open(SHELL);
  try {
    const response = await fetch(request);
    if (response.ok) cache.put(request, response.clone());
    return response;
  } catch (error) {
    const hit = await cache.match(request);
    if (hit) return hit;
    if (request.mode === 'navigate') {
      const shell = await cache.match('./index.html');
      if (shell) return shell;
    }
    throw error;
  }
}

/** Erlaubt der Seite, den Zwischenspeicher zu leeren (Löschfunktion). */
self.addEventListener('message', (event) => {
  if (event.data === 'visionhud:wipe-cache') {
    event.waitUntil(
      (async () => {
        for (const name of await caches.keys()) {
          if (name.startsWith('visionhud-')) await caches.delete(name);
        }
      })(),
    );
  }
});
