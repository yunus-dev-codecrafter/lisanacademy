/* =========================================================
   LISANUN MUBEEN ACADEMY — SERVICE WORKER
   sw.js · Enables PWA installability, asset caching, offline fallback & Push notifications
   ========================================================= */

const CACHE_NAME = 'lisanun-pwa-v2';
const OFFLINE_URL = '/offline.html';

const PRECACHE_ASSETS = [
  OFFLINE_URL,
  '/manifest.json',
  '/assets/CSS/base.css',
  '/assets/CSS/components.css',
  '/assets/CSS/layout.css',
  '/assets/CSS/icons.css',
  '/assets/icons/icon-192.png',
  '/assets/icons/icon-512.png',
  '/assets/icons/icon-maskable-192.png',
  '/assets/icons/icon-maskable-512.png',
  '/assets/icons/apple-touch-icon.png',
  '/assets/icons/favicon-32x32.png',
  '/assets/icons/favicon-16x16.png',
  '/assets/js/sidebar.js',
  '/assets/js/pwa.js',
  '/assets/js/notifications.js'
];

/* 1. Install event: Cache essential app shell & offline page */
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => {
      return cache.addAll(PRECACHE_ASSETS).catch((err) => {
        console.warn('PWA: Some precache assets failed to load:', err);
      });
    }).then(() => self.skipWaiting())
  );
});

/* 2. Activate event: Clean up previous cache versions */
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => {
      return Promise.all(
        keys.map((key) => {
          if (key !== CACHE_NAME) {
            return caches.delete(key);
          }
        })
      );
    }).then(() => self.clients.claim())
  );
});

/* 3. Fetch event: Network-first for pages, cache-first/stale-while-revalidate for static assets */
self.addEventListener('fetch', (event) => {
  const request = event.request;

  // Only handle GET requests
  if (request.method !== 'GET') {
    return;
  }

  const url = new URL(request.url);

  // Skip API & cron endpoints from offline HTML fallback
  if (url.pathname.startsWith('/api/') || url.pathname.startsWith('/cron/')) {
    return;
  }

  // HTML page navigations -> Network First, fallback to cached offline page
  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request)
        .then((networkResponse) => {
          return networkResponse;
        })
        .catch(async () => {
          const cache = await caches.open(CACHE_NAME);
          const cachedOffline = await cache.match(OFFLINE_URL);
          return cachedOffline || new Response('Offline', { status: 503, statusText: 'Offline' });
        })
    );
    return;
  }

  // Static assets (CSS, JS, images, fonts, icons) -> Stale While Revalidate
  const isStaticAsset =
    url.origin === self.location.origin &&
    (url.pathname.startsWith('/assets/') ||
     url.pathname.startsWith('/logo/') ||
     url.pathname === '/manifest.json' ||
     url.pathname === '/offline.html');

  const isGoogleFont =
    url.origin === 'https://fonts.googleapis.com' ||
    url.origin === 'https://fonts.gstatic.com';

  if (isStaticAsset || isGoogleFont) {
    event.respondWith(
      caches.open(CACHE_NAME).then((cache) => {
        return cache.match(request).then((cachedResponse) => {
          const fetchPromise = fetch(request)
            .then((networkResponse) => {
              if (networkResponse && networkResponse.status === 200) {
                cache.put(request, networkResponse.clone());
              }
              return networkResponse;
            })
            .catch(() => cachedResponse);

          return cachedResponse || fetchPromise;
        });
      })
    );
    return;
  }

  // Default fallback: regular network fetch
  event.respondWith(
    fetch(request).catch(() => caches.match(request))
  );
});

/* 4. Push event: Received from Web Push Server */
self.addEventListener('push', (event) => {
  let data = {
    title: 'Lisanun Mubeen Academy',
    body: 'You have a new update from Lisanun Mubeen.',
    url: '/',
    icon: '/assets/icons/icon-192.png',
    badge: '/assets/icons/favicon-32x32.png'
  };

  if (event.data) {
    try {
      data = Object.assign(data, event.data.json());
    } catch (e) {
      data.body = event.data.text();
    }
  }

  const options = {
    body: data.body,
    icon: data.icon || '/assets/icons/icon-192.png',
    badge: data.badge || '/assets/icons/favicon-32x32.png',
    vibrate: [150, 60, 150],
    data: {
      url: data.url || '/'
    }
  };

  event.waitUntil(
    self.registration.showNotification(data.title, options)
  );
});

/* 5. Notification click event: Open/focus target page in app */
self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const targetUrl = (event.notification.data && event.notification.data.url)
    ? event.notification.data.url
    : '/';

  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
      // If a window is already open, focus it and navigate
      for (const client of clientList) {
        if (client.url.includes(self.location.origin) && 'focus' in client) {
          client.navigate(targetUrl);
          return client.focus();
        }
      }
      // Otherwise open new window
      if (clients.openWindow) {
        return clients.openWindow(targetUrl);
      }
    })
  );
});
