const CACHE_NAME = 'tasksboard-v11';
const STATIC_ASSETS = [
    './',
    './style.css',
    './app.js',
    './manifest.json'
];

// ── Install ───────────────────────────────────────────────────────────────────
self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME).then(cache => cache.addAll(STATIC_ASSETS))
    );
    self.skipWaiting();
});

// ── Activate ──────────────────────────────────────────────────────────────────
self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(keys =>
            Promise.all(keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k)))
        )
    );
    self.clients.claim();
});

// ── Fetch ─────────────────────────────────────────────────────────────────────
self.addEventListener('fetch', event => {
    const url = new URL(event.request.url);

    // Always network for PHP endpoints
    if (url.pathname.endsWith('.php')) {
        event.respondWith(fetch(event.request));
        return;
    }

    // Network-first for static assets, fallback to cache if offline
    if (event.request.method === 'GET') {
        event.respondWith(
            fetch(event.request)
                .then(response => {
                    if (response.ok) {
                        const clone = response.clone();
                        caches.open(CACHE_NAME).then(cache => cache.put(event.request, clone));
                    }
                    return response;
                })
                .catch(() => caches.match(event.request).then(cached => cached || new Response('Offline', { status: 503 })))
        );
        return;
    }

    event.respondWith(fetch(event.request));
});

// ── Push Notifications (server-sent via WebPush) ──────────────────────────────
self.addEventListener('push', event => {
    let data = { title: 'Task Due', body: 'You have a task due now.' };
    try {
        if (event.data) data = event.data.json();
    } catch (e) {
        if (event.data) data.body = event.data.text();
    }

    const options = {
        body:              data.body  || '',
        icon:              './icons/icon-192.png',
        badge:             './icons/icon-192.png',
        tag:               data.tag   || 'task-due',
        data:              { url: data.url || './' },
        requireInteraction: true,
        vibrate:           [200, 100, 200],
        actions: [
            { action: 'open',    title: 'Open App' },
            { action: 'dismiss', title: 'Dismiss'  }
        ]
    };

    event.waitUntil(
        self.registration.showNotification(data.title, options)
    );
});

// ── Notification Click ────────────────────────────────────────────────────────
self.addEventListener('notificationclick', event => {
    event.notification.close();

    if (event.action === 'dismiss') return;

    const targetUrl = (event.notification.data && event.notification.data.url)
        ? event.notification.data.url
        : './';

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(clientList => {
            for (const client of clientList) {
                if (client.url.includes(self.location.origin) && 'focus' in client) {
                    return client.focus();
                }
            }
            return clients.openWindow(targetUrl);
        })
    );
});

// ── Background Sync (future) ──────────────────────────────────────────────────
self.addEventListener('sync', event => {
    if (event.tag === 'sync-tasks') {
        // placeholder for offline sync
    }
});
