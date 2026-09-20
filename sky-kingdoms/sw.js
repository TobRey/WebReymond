/**
 * Sky Kingdoms – Service Worker.
 *
 * Aufgabe: Die Oberfläche startet auch ohne Netz. Spieldaten werden NIE aus dem
 * Cache beantwortet – sie kommen immer frisch vom Server (sonst würden alte
 * Rohstoffmengen angezeigt).
 *
 * Der Pfad ist absichtlich relativ: dadurch funktioniert der Service Worker in
 * jedem Unterordner.
 */

const VERSION = 'sk-v1';
const SHELL = VERSION + '-shell';

// Der Gültigkeitsbereich ist der Ordner, in dem diese Datei liegt.
const BASE = new URL('./', self.location).pathname;

const PRECACHE = [
    BASE + 'assets/css/app.css',
    BASE + 'assets/css/game.css',
    BASE + 'assets/js/app.js',
    BASE + 'assets/img/logo.svg',
    BASE + 'offline.php'
];

self.addEventListener('install', function (event) {
    event.waitUntil(
        caches.open(SHELL)
            .then(function (cache) { return cache.addAll(PRECACHE); })
            .then(function () { return self.skipWaiting(); })
            .catch(function () { /* Ohne Cache läuft das Spiel trotzdem */ })
    );
});

self.addEventListener('activate', function (event) {
    event.waitUntil(
        caches.keys().then(function (keys) {
            return Promise.all(keys.map(function (key) {
                if (key.indexOf(VERSION) !== 0) { return caches.delete(key); }
                return null;
            }));
        }).then(function () { return self.clients.claim(); })
    );
});

self.addEventListener('fetch', function (event) {
    const request = event.request;
    if (request.method !== 'GET') { return; }

    const url = new URL(request.url);
    if (url.origin !== self.location.origin) { return; }

    // Spieldaten und Seiten immer vom Server holen.
    const isApi = url.pathname.indexOf(BASE + 'api/') === 0;
    const isPage = request.mode === 'navigate';

    if (isApi) {
        return; // Standardverhalten des Browsers, kein Cache
    }

    if (isPage) {
        event.respondWith(
            fetch(request).catch(function () {
                return caches.match(BASE + 'offline.php').then(function (cached) {
                    return cached || new Response('Offline', { status: 503, headers: { 'Content-Type': 'text/plain' } });
                });
            })
        );
        return;
    }

    // Statische Dateien: erst Cache, dann Netz (und nachladen).
    event.respondWith(
        caches.match(request).then(function (cached) {
            const network = fetch(request).then(function (response) {
                if (response && response.status === 200 && response.type === 'basic') {
                    const copy = response.clone();
                    caches.open(SHELL).then(function (cache) { cache.put(request, copy); });
                }
                return response;
            }).catch(function () { return cached; });

            return cached || network;
        })
    );
});
