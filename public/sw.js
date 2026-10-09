/* AI Budget Planner service worker: offline app shell, cached OCR engine, and last-known API data. */
const VERSION = 'v1';
const SHELL = `shell-${VERSION}`;
const STATIC = `static-${VERSION}`;
const DATA = `data-${VERSION}`;

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(SHELL).then((c) => c.addAll(['/', '/manifest.webmanifest', '/icons/icon-192.png'])).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((k) => ![SHELL, STATIC, DATA].includes(k)).map((k) => caches.delete(k))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('message', (event) => {
    // Sent on logout so no financial data stays cached on a shared device.
    if (event.data === 'clear-data') event.waitUntil(caches.delete(DATA));
});

self.addEventListener('fetch', (event) => {
    const { request } = event;
    const url = new URL(request.url);
    if (request.method !== 'GET' || url.origin !== self.location.origin) return;

    // Hashed build assets, icons and the OCR engine never change: cache first.
    if (url.pathname.startsWith('/build/') || url.pathname.startsWith('/vendor/') || url.pathname.startsWith('/icons/')) {
        event.respondWith(caches.open(STATIC).then(async (cache) => {
            const hit = await cache.match(request);
            if (hit) return hit;
            const res = await fetch(request);
            if (res.ok) cache.put(request, res.clone());
            return res;
        }));
        return;
    }

    // API reads: network first, fall back to the last copy when offline.
    if (url.pathname.startsWith('/api/')) {
        if (url.pathname.includes('/receipt')) return;
        event.respondWith(fetch(request).then((res) => {
            if (res.ok) caches.open(DATA).then((c) => c.put(request, res.clone()));
            return res;
        }).catch(async () => (await caches.match(request)) || new Response(JSON.stringify({ message: 'You are offline.' }), { status: 503, headers: { 'Content-Type': 'application/json' } })));
        return;
    }

    // Page navigations: network first, offline falls back to the cached app shell.
    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).then((res) => {
            caches.open(SHELL).then((c) => c.put('/', res.clone()));
            return res;
        }).catch(() => caches.match('/')));
    }
});
