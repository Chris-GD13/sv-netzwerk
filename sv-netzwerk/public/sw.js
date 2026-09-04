// SV-Netzwerk Prüferportal - Service Worker mit Offline-Support
// Automatischer Sync beim Reconnect und Offline-Mode

import { precacheAndRoute } from 'workbox-precaching';
import { registerRoute } from 'workbox-routing';
import { CacheFirst, NetworkFirst } from 'workbox-strategies';

const CACHE_VERSION = '20260904-5';
const PAGE_CACHE = `portal-pages-${CACHE_VERSION}`;
const API_CACHE = `api-cache-${CACHE_VERSION}`;
const ASSET_CACHE = `assets-cache-${CACHE_VERSION}`;

self.addEventListener('install', () => self.skipWaiting());

// Precache files from build
precacheAndRoute(self.__WB_MANIFEST || []);

// HTML pages - Network First (online priority, fallback to cache)
registerRoute(
  ({ request }) => request.mode === 'navigate',
  new NetworkFirst({
    cacheName: PAGE_CACHE,
    plugins: [],
  })
);

// API calls - Network First with short timeout
registerRoute(
  ({ url }) => url.pathname.startsWith('/intern/api/'),
  new NetworkFirst({
    cacheName: API_CACHE,
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
    cacheName: ASSET_CACHE,
    plugins: [],
  })
);

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
    Promise.all([
      caches.keys().then((cacheNames) => Promise.all(cacheNames.filter((cacheName) =>
        (cacheName.startsWith('portal-pages') && cacheName !== PAGE_CACHE) ||
        (cacheName.startsWith('api-cache') && cacheName !== API_CACHE) ||
        (cacheName.startsWith('assets-cache') && cacheName !== ASSET_CACHE)
      ).map((cacheName) => caches.delete(cacheName)))),
      self.clients.claim(),
    ])
  );
});
