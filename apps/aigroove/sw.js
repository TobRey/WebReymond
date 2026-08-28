/**
 * AI Groove – Service Worker.
 *
 * Strategie:
 *   - Statische Assets: cache-first (App startet auch offline).
 *   - Navigationen: network-first mit Cache-Fallback.
 *   - /api/: NIE cachen (die App braucht keinen Server, die Regel bleibt als Schutz).
 */

const VERSION = 'aigroove-v1.1.2';
const STATIC_CACHE = `${VERSION}-static`;

const SCOPE_PATH = new URL('./', self.registration.scope).pathname;

/** Dateien, die fuer den Kaltstart benoetigt werden. */
const PRECACHE = [
  './',
  './index.php',
  './manifest.webmanifest',
  './assets/css/base.css',
  './assets/css/components.css',
  './assets/css/dashboard.css',
  './assets/css/responsive.css',
  './assets/css/studio.css',
  './assets/css/tokens.css',
  './assets/icons/apple-touch-icon.png',
  './assets/icons/favicon-32.png',
  './assets/icons/icon-192.png',
  './assets/icons/icon-512.png',
  './assets/icons/icon.svg',
  './assets/icons/maskable-512.png',
  './assets/js/ai/actions.js',
  './assets/js/ai/assistant.js',
  './assets/js/ai/generate.js',
  './assets/js/ai/groovenet/compose.js',
  './assets/js/ai/groovenet/encoder.js',
  './assets/js/ai/groovenet/features.js',
  './assets/js/ai/groovenet/fft.js',
  './assets/js/ai/groovenet/genome.js',
  './assets/js/ai/groovenet/index.js',
  './assets/js/ai/groovenet/lexicon.js',
  './assets/js/ai/groovenet/master.js',
  './assets/js/ai/groovenet/optimizer.js',
  './assets/js/ai/groovenet/synth.js',
  './assets/js/ai/groovenet/target.js',
  './assets/js/ai/providers.js',
  './assets/js/audio/context.js',
  './assets/js/audio/dsp.js',
  './assets/js/audio/effects.js',
  './assets/js/audio/engine.js',
  './assets/js/audio/graph.js',
  './assets/js/audio/peaks.js',
  './assets/js/audio/recorder.js',
  './assets/js/audio/render.js',
  './assets/js/audio/sampler.js',
  './assets/js/audio/scheduler.js',
  './assets/js/audio/timeline.js',
  './assets/js/audio/wav.js',
  './assets/js/core/bus.js',
  './assets/js/core/dom.js',
  './assets/js/core/file.js',
  './assets/js/core/history.js',
  './assets/js/core/idb.js',
  './assets/js/core/project.js',
  './assets/js/core/settings.js',
  './assets/js/core/store.js',
  './assets/js/core/util.js',
  './assets/js/main.js',
  './assets/js/ui/aiconnect.js',
  './assets/js/ui/aisample.js',
  './assets/js/ui/arrangement.js',
  './assets/js/ui/chat.js',
  './assets/js/ui/contextmenu.js',
  './assets/js/ui/dashboard.js',
  './assets/js/ui/diagnostics.js',
  './assets/js/ui/dragdrop.js',
  './assets/js/ui/exportui.js',
  './assets/js/ui/gridbase.js',
  './assets/js/ui/help.js',
  './assets/js/ui/mixer.js',
  './assets/js/ui/modal.js',
  './assets/js/ui/pads.js',
  './assets/js/ui/pianoroll.js',
  './assets/js/ui/router.js',
  './assets/js/ui/sampleeditor.js',
  './assets/js/ui/samplelist.js',
  './assets/js/ui/sequencer.js',
  './assets/js/ui/settingsview.js',
  './assets/js/ui/shell.js',
  './assets/js/ui/toast.js',
  './assets/js/ui/transport.js',
  './assets/js/ui/tutorial.js',
  './assets/js/vendor/lame.min.js',
  './assets/js/workers/groovenet-worker.js',
  './assets/js/workers/mp3-worker.js',
  './assets/js/workers/peaks-worker.js',
  './assets/js/workers/recorder-processor.js',
  './assets/js/workers/ticker.js',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    (async () => {
      const cache = await caches.open(STATIC_CACHE);
      // Einzeln hinzufuegen, damit eine fehlende Datei nicht die ganze Installation kippt.
      await Promise.all(
        PRECACHE.map(async (url) => {
          try {
            const res = await fetch(new Request(url, { cache: 'reload' }));
            if (res.ok) await cache.put(url, res);
          } catch (_) {
            /* offline beim Install – wird spaeter nachgeladen */
          }
        }),
      );
      await self.skipWaiting();
    })(),
  );
});


self.addEventListener('activate', (event) => {
  event.waitUntil(
    (async () => {
      const keys = await caches.keys();
      await Promise.all(keys.filter((k) => !k.startsWith(VERSION)).map((k) => caches.delete(k)));
      await self.clients.claim();
    })(),
  );
});

self.addEventListener('message', (event) => {
  if (event.data === 'skip-waiting') self.skipWaiting();
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;

  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;

  // KI-Proxy und alles unter /api/ niemals cachen.
  if (url.pathname.includes('/api/')) return;

  if (req.mode === 'navigate') {
    event.respondWith(
      (async () => {
        try {
          const fresh = await fetch(req);
          const cache = await caches.open(STATIC_CACHE);
          cache.put(SCOPE_PATH + 'index.php', fresh.clone()).catch(() => {});
          return fresh;
        } catch (_) {
          const cache = await caches.open(STATIC_CACHE);
          return (
            (await cache.match(SCOPE_PATH + 'index.php')) ||
            (await cache.match('./index.php')) ||
            (await cache.match('./')) ||
            new Response('<h1>AI Groove ist offline</h1>', {
              headers: { 'Content-Type': 'text/html; charset=utf-8' },
              status: 503,
            })
          );
        }
      })(),
    );
    return;
  }

  event.respondWith(
    (async () => {
      const cache = await caches.open(STATIC_CACHE);
      // Query-String (?v=) beim Nachschlagen ignorieren.
      const cached = await cache.match(req, { ignoreSearch: true });
      if (cached) {
        // Im Hintergrund aktualisieren.
        fetch(req)
          .then((res) => {
            if (res.ok) cache.put(req, res.clone());
          })
          .catch(() => {});
        return cached;
      }
      try {
        const res = await fetch(req);
        if (res.ok && res.type === 'basic') cache.put(req, res.clone());
        return res;
      } catch (err) {
        return new Response('', { status: 504, statusText: 'Offline' });
      }
    })(),
  );
});
