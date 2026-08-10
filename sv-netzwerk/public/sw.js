// SV-Netzwerk Prüferportal - Service Worker mit Offline-Support
// Automatischer Sync beim Reconnect und Offline-Mode

import { precacheAndRoute } from 'workbox-precaching';
import { registerRoute } from 'workbox-routing';
import { CacheFirst, NetworkFirst } from 'workbox-strategies';

// Precache files from build
precacheAndRoute(self.__WB_MANIFEST || []);

// HTML pages - Network First (online priority, fallback to cache)
registerRoute(
  ({ request }) => request.mode === 'navigate',
  new NetworkFirst({
    cacheName: 'portal-pages',
    plugins: [],
  })
);

// API calls - Network First with short timeout
registerRoute(
  ({ url }) => url.pathname.startsWith('/api/'),
  new NetworkFirst({
    cacheName: 'api-cache',
    networkTimeoutSeconds: 5,
    plugins: [],
  })
);

// Assets (images, fonts, etc) - Cache First
registerRoute(
  ({ request }) => 
    request.destination === 'image' ||
    request.destination === 'font' ||
    request.destination === 'style' ||
    request.destination === 'script',
  new CacheFirst({
    cacheName: 'assets-cache',
    plugins: [],
  })
);

// Offline page fallback
self.addEventListener('fetch', (event) => {
  if (event.request.method !== 'GET') return;

  event.respondWith(
    fetch(event.request).catch(() => {
      // Return offline page or cached response
      return caches.match(event.request) || 
        caches.match('/offline.html') ||
        new Response('Offline - Die Seite ist nicht verfügbar', { status: 503 });
    })
  );
});

// Background Sync for queued updates (when online again)
self.addEventListener('sync', (event) => {
  if (event.tag === 'sync-drafts') {
    event.waitUntil(
      (async () => {
        try {
          // Trigger sync from main thread
          const clients = await self.clients.matchAll();
          clients.forEach(client => {
            client.postMessage({ type: 'SYNC_REQUEST' });
          });
        } catch (err) {
          console.error('[Service Worker] Sync failed:', err);
        }
      })()
    );
  }
});

// Message handler from main thread
self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
});

// Clean up old caches on activate
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((cacheNames) => {
      return Promise.all(
        cacheNames
          .filter(cacheName => {
            const isOld = 
              !cacheName.includes('portal-pages') &&
              !cacheName.includes('api-cache') &&
              !cacheName.includes('assets-cache');
            return isOld;
          })
          .map(cacheName => caches.delete(cacheName))
      );
    })
  );
});
