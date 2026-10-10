/* Amotan service worker (offline-first): offline app shell, every build chunk, cached OCR engine, and last-known API data. */
const VERSION = 'v3';
const SHELL = `shell-${VERSION}`;
const STATIC = `static-${VERSION}`;
const DATA = `data-${VERSION}`;
const NAV_TIMEOUT = 3500;

// Every hashed file from the Vite build, so screens never opened before still work offline.
async function buildFiles() {
    try {
        const manifest = await (await fetch('/build/manifest.json', { cache: 'no-store' })).json();
        const files = new Set();
        for (const entry of Object.values(manifest)) {
            if (entry.file) files.add(`/build/${entry.file}`);
            (entry.css || []).forEach((f) => files.add(`/build/${f}`));
            (entry.assets || []).forEach((f) => files.add(`/build/${f}`));
        }
        return [...files];
    } catch {
        return [];
    }
}

async function precache() {
    const shell = await caches.open(SHELL);
    await shell.addAll(['/', '/manifest.webmanifest', '/icons/icon-192.png']);
    const files = await caches.open(STATIC);
    await Promise.all((await buildFiles()).map(async (url) => {
        if (await files.match(url)) return;
        try {
            const res = await fetch(url);
            if (res.ok) await files.put(url, res);
        } catch {}
    }));
}

self.addEventListener('install', (event) => {
    event.waitUntil(precache().then(() => self.skipWaiting()));
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

const shellCopy = () => caches.open(SHELL).then((c) => c.match('/', { ignoreVary: true, ignoreSearch: true }));

self.addEventListener('fetch', (event) => {
    const { request } = event;
    const url = new URL(request.url);
    if (request.method !== 'GET' || url.origin !== self.location.origin) return;

    // Hashed build assets, icons and the OCR engine never change: cache first.
    if (url.pathname.startsWith('/build/') || url.pathname.startsWith('/vendor/') || url.pathname.startsWith('/icons/')) {
        event.respondWith(caches.open(STATIC).then(async (cache) => {
            const hit = await cache.match(request, { ignoreVary: true });
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
            if (res.ok) {
                const copy = res.clone();
                caches.open(DATA).then((c) => c.put(request, copy));
            }
            return res;
        }).catch(async () => (await caches.match(request, { ignoreVary: true })) || new Response(JSON.stringify({ message: 'You are offline.' }), { status: 503, headers: { 'Content-Type': 'application/json', 'X-Amotan-Offline': '1' } })));
        return;
    }

    // Page navigations: network first, but a slow or missing connection falls back to the saved app shell.
    if (request.mode === 'navigate') {
        event.respondWith((async () => {
            const network = fetch(request).then((res) => {
                if (res.ok) {
                    const copy = res.clone();
                    caches.open(SHELL).then((c) => c.put('/', copy));
                }
                return res;
            });
            const timeout = new Promise((resolve) => setTimeout(resolve, NAV_TIMEOUT));
            try {
                const res = await Promise.race([network, timeout]);
                if (res) return res;
                return (await shellCopy()) || (await network);
            } catch {
                return (await shellCopy()) || Response.error();
            }
        })());
    }
});
