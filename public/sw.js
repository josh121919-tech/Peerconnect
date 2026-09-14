/**
 * PeerConnect Service Worker
 * Strategy:
 *  - App shell (CSS, fonts, JS) → Cache First
 *  - API/dynamic pages           → Network First with offline fallback
 *  - Images                      → Cache First with expiry
 */

// The app's folder, worked out from where this file is served
// (<folder>/public/sw.js), so nothing here names a particular install.
const BASE = new URL('..', self.location).pathname;

const CACHE_VERSION  = 'pc-v1';
const SHELL_CACHE    = `${CACHE_VERSION}-shell`;
const DYNAMIC_CACHE  = `${CACHE_VERSION}-dynamic`;
const IMAGE_CACHE    = `${CACHE_VERSION}-images`;

// ── Assets to precache (app shell) ─────────────────────────────────────────
const SHELL_ASSETS = [
  BASE + 'public/css/design-system.css',
  BASE + 'public/offline.html',
  BASE + 'public/icons/icon.svg',
  'https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,400&family=Playfair+Display:wght@600;700&display=swap',
];

// ── Routes that should never be served from cache ──────────────────────────
const NETWORK_ONLY = [
  BASE + 'public/index.php',
  'google-login',
  'logout',
  'save-booking',
  'save-report',
];

// ── Install: precache shell assets ─────────────────────────────────────────
self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(SHELL_CACHE)
      .then(cache => cache.addAll(SHELL_ASSETS.map(url => {
        return new Request(url, { cache: 'reload' });
      })).catch(() => {}))
      .then(() => self.skipWaiting())
  );
});

// ── Activate: delete old caches ────────────────────────────────────────────
self.addEventListener('activate', event => {
  const KEEP = [SHELL_CACHE, DYNAMIC_CACHE, IMAGE_CACHE];
  event.waitUntil(
    caches.keys()
      .then(keys => Promise.all(
        keys.filter(k => !KEEP.includes(k)).map(k => caches.delete(k))
      ))
      .then(() => self.clients.claim())
  );
});

// ── Fetch: routing strategy ────────────────────────────────────────────────
self.addEventListener('fetch', event => {
  const req = event.request;
  const url = new URL(req.url);

  // Skip non-GET, cross-origin extension requests, chrome-extension, etc.
  if (req.method !== 'GET') return;
  if (!url.protocol.startsWith('http')) return;

  // Network-only routes (auth, mutations)
  if (NETWORK_ONLY.some(p => url.pathname.includes(p) || url.href.includes(p))) {
    event.respondWith(fetch(req));
    return;
  }

  // Images → cache first (stale-while-revalidate)
  if (req.destination === 'image') {
    event.respondWith(cacheFirst(req, IMAGE_CACHE, 60 * 60 * 24 * 7)); // 7 days
    return;
  }

  // CSS / JS / fonts → cache first (shell cache)
  if (['style', 'script', 'font'].includes(req.destination)) {
    event.respondWith(cacheFirst(req, SHELL_CACHE, 60 * 60 * 24));
    return;
  }

  // HTML pages → network first with offline fallback
  if (req.destination === 'document' || req.headers.get('Accept')?.includes('text/html')) {
    event.respondWith(networkFirst(req));
    return;
  }

  // Everything else → network first
  event.respondWith(networkFirst(req));
});

// ── Strategy: Cache First ──────────────────────────────────────────────────
async function cacheFirst(req, cacheName, maxAgeSeconds = 86400) {
  const cache    = await caches.open(cacheName);
  const cached   = await cache.match(req);

  if (cached) {
    const cachedDate = cached.headers.get('sw-cached-at');
    const age        = cachedDate ? (Date.now() - parseInt(cachedDate)) / 1000 : 0;
    if (age < maxAgeSeconds) return cached;
  }

  try {
    const fresh = await fetch(req);
    if (fresh.ok) {
      const clone   = fresh.clone();
      const headers = new Headers(clone.headers);
      headers.set('sw-cached-at', Date.now().toString());
      const response = new Response(await clone.blob(), {
        status: clone.status, statusText: clone.statusText, headers
      });
      cache.put(req, response);
    }
    return fresh;
  } catch {
    return cached || offlineFallback(req);
  }
}

// ── Strategy: Network First ────────────────────────────────────────────────
async function networkFirst(req) {
  const cache = await caches.open(DYNAMIC_CACHE);
  try {
    const fresh = await fetch(req);
    if (fresh.ok && req.method === 'GET') {
      cache.put(req, fresh.clone());
    }
    return fresh;
  } catch {
    const cached = await cache.match(req);
    return cached || offlineFallback(req);
  }
}

// ── Offline fallback ───────────────────────────────────────────────────────
async function offlineFallback(req) {
  const accept = req.headers.get('Accept') || '';
  if (accept.includes('text/html')) {
    const offline = await caches.match(BASE + 'public/offline.html');
    if (offline) return offline;
  }
  return new Response('Offline', { status: 503, headers: { 'Content-Type': 'text/plain' } });
}

// ── Background Sync ────────────────────────────────────────────────────────
self.addEventListener('sync', event => {
  if (event.tag === 'sync-feedback') {
    event.waitUntil(syncPendingFeedback());
  }
});

async function syncPendingFeedback() {
  try {
    const db      = await openDB();
    const pending = await getAllPending(db);
    for (const item of pending) {
      const res = await fetch(item.url, {
        method: 'POST',
        body:   item.formData,
      });
      if (res.ok) await deletePending(db, item.id);
    }
  } catch (e) {
    console.warn('[SW] Background sync failed:', e);
  }
}

// ── Push Notifications ────────────────────────────────────────────────────
self.addEventListener('push', event => {
  if (!event.data) return;
  let data = {};
  try { data = event.data.json(); } catch { data = { title: 'PeerConnect', body: event.data.text() }; }

  const options = {
    body:    data.body   || 'You have a new notification.',
    icon:    BASE + 'public/icons/icon.svg',
    badge:   BASE + 'public/icons/icon.svg',
    tag:     data.tag    || 'pc-notification',
    data:    { url: data.url || BASE },
    actions: data.actions || [{ action: 'open', title: 'Open' }],
    vibrate: [200, 100, 200],
  };

  event.waitUntil(
    self.registration.showNotification(data.title || 'PeerConnect', options)
  );
});

self.addEventListener('notificationclick', event => {
  event.notification.close();
  const url = event.notification.data?.url || BASE;
  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then(list => {
      for (const client of list) {
        if (client.url === url && 'focus' in client) return client.focus();
      }
      if (clients.openWindow) return clients.openWindow(url);
    })
  );
});

// ── Minimal IndexedDB helpers for background sync ─────────────────────────
function openDB() {
  return new Promise((resolve, reject) => {
    const req = indexedDB.open('pc-sync', 1);
    req.onupgradeneeded = e => {
      e.target.result.createObjectStore('pending', { keyPath: 'id', autoIncrement: true });
    };
    req.onsuccess  = e => resolve(e.target.result);
    req.onerror    = e => reject(e.target.error);
  });
}
function getAllPending(db) {
  return new Promise((resolve, reject) => {
    const tx  = db.transaction('pending', 'readonly');
    const req = tx.objectStore('pending').getAll();
    req.onsuccess = e => resolve(e.target.result);
    req.onerror   = e => reject(e.target.error);
  });
}
function deletePending(db, id) {
  return new Promise((resolve, reject) => {
    const tx  = db.transaction('pending', 'readwrite');
    const req = tx.objectStore('pending').delete(id);
    req.onsuccess = () => resolve();
    req.onerror   = e => reject(e.target.error);
  });
}
