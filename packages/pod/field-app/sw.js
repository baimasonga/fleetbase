/*
 * Service worker: keeps the app itself available offline.
 *
 * The app's files are served from the cache, and refreshed in the background
 * when online. Data requests (/int/...) always go to the network: the app keeps
 * its own data in IndexedDB.
 */
const VERSION = 'pod-field-v1';
const SHELL = [
  './', 'index.html', 'app.css', 'manifest.webmanifest',
  'js/main.js', 'js/db.js', 'js/api.js', 'js/card.js', 'js/policy.js', 'js/media.js', 'js/scanner.js', 'js/sync.js', 'js/ui.js',
  'vendor/jsQR.js', 'icons/icon-192.png', 'icons/icon-512.png',
];

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(VERSION).then((cache) => cache.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((key) => key !== VERSION).map((key) => caches.delete(key))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);
  if (event.request.method !== 'GET' || url.origin !== self.location.origin || !url.pathname.startsWith('/pod-field/')) {
    return; // API calls and anything else: straight to the network
  }

  event.respondWith(
    caches.open(VERSION).then(async (cache) => {
      const cached = await cache.match(event.request, { ignoreSearch: true });
      const fresh = fetch(event.request)
        .then((response) => {
          if (response.ok) cache.put(event.request, response.clone());
          return response;
        })
        .catch(() => null);

      if (cached) {
        event.waitUntil(fresh);
        return cached;
      }
      return (await fresh) || (await cache.match('index.html')) || Response.error();
    })
  );
});
