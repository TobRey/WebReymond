// Service Worker der Web-Version: hält Startseite und Anleitung offline verfügbar
// (z. B. wenn das iPhone im Kamera-WLAN ohne Internet ist). Keine Kameradaten.
const CACHE = 'kamera-link-@@VERSION@@';
const ASSETS = [/*@@ASSETS@@*/];

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(CACHE).then((c) => c.addAll(['./', ...ASSETS])).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
  e.waitUntil(caches.keys()
    .then((keys) => Promise.all(keys.filter((k) => k.startsWith('kamera-link-') && k !== CACHE).map((k) => caches.delete(k))))
    .then(() => self.clients.claim()));
});

self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET' || new URL(req.url).origin !== location.origin) return;
  if (new URL(req.url).pathname.endsWith('download.php')) return;
  if (req.mode === 'navigate') {
    // Netz zuerst (aktuelle Version), sonst Kopie aus dem Cache
    e.respondWith(fetch(req).then((res) => {
      const copy = res.clone();
      caches.open(CACHE).then((c) => c.put('./', copy));
      return res;
    }).catch(() => caches.match('./')));
    return;
  }
  e.respondWith(caches.match(req, { ignoreSearch: true }).then((hit) => hit || fetch(req)));
});
