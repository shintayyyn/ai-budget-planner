/* Amotan service worker (offline-first): offline app shell, every build chunk, cached OCR engine, and last-known API data. */
const VERSION = 'v4';
const SHELL = `shell-${VERSION}`;
const STATIC = `static-${VERSION}`;
const DATA = `data-${VERSION}`;
const NAV_TIMEOUT = 3500;
const OWN_CACHE = /^(shell|static|data)-v\d+$/;
const SHELL_FILES = ['/', '/manifest.webmanifest', '/icons/icon-192.png'];
// Receipt OCR engine (self-hosted), so scanning works offline too.
const OCR_FILES = [
    '/vendor/tesseract/worker.min.js',
    '/vendor/tesseract/lang/eng.traineddata.gz',
    ...['lstm', 'simd-lstm', 'relaxedsimd-lstm'].flatMap((v) => ['.js', '.wasm', '.wasm.js'].map((ext) => `/vendor/tesseract/core/tesseract-core-${v}${ext}`)),
];

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

async function fill(cacheName, urls, onEach = () => {}) {
    const cache = await caches.open(cacheName);
    let failed = 0;
    let next = 0;
    const work = async () => {
        while (next < urls.length) {
            const url = urls[next++];
            try {
                if (!(await cache.match(url))) {
                    const res = await fetch(url);
                    if (res.ok) await cache.put(url, res);
                    else failed++;
                }
            } catch { failed++; }
            onEach();
        }
    };
    await Promise.all(Array.from({ length: 6 }, work));
    return failed;
}

async function precache() {
    await (await caches.open(SHELL)).addAll(SHELL_FILES);
    await fill(STATIC, await buildFiles());
}

/** Save everything the app needs offline, reporting progress to the page. */
async function prepare(port) {
    const build = await buildFiles();
    const total = SHELL_FILES.length + build.length + OCR_FILES.length;
    let done = 0;
    const tick = () => port?.postMessage({ done: ++done, total });
    let failed = await fill(SHELL, SHELL_FILES, tick);
    failed += await fill(STATIC, [...build, ...OCR_FILES], tick);
    port?.postMessage({ done: total, total, failed, finished: true });
}

self.addEventListener('install', (event) => {
    event.waitUntil(precache().then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((k) => OWN_CACHE.test(k) && ![SHELL, STATIC, DATA].includes(k)).map((k) => caches.delete(k))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('message', (event) => {
    // Sent on logout so no financial data stays cached on a shared device.
    if (event.data === 'clear-data') event.waitUntil(caches.delete(DATA));
    if (event.data?.type === 'prepare') event.waitUntil(prepare(event.ports[0]));
});

// The tunnel/proxy answers 502-504 with a plain-text page when the server is down: treat that like being offline.
const serverDown = (res) => [502, 503, 504].includes(res.status) && !(res.headers.get('Content-Type') || '').includes('json');
const offlineData = async (request) => (await caches.match(request, { ignoreVary: true })) || new Response(JSON.stringify({ message: 'You are offline.' }), { status: 503, headers: { 'Content-Type': 'application/json', 'X-Amotan-Offline': '1' } });

const shellCopy = () => caches.open(SHELL).then((c) => c.match('/', { ignoreVary: true, ignoreSearch: true }));

self.addEventListener('fetch', (event) => {
    const { request } = event;
    const url = new URL(request.url);
    if (request.method !== 'GET' || url.origin !== self.location.origin) return;

    // Hashed build assets, icons and the OCR engine never change: cache first.
    if (url.pathname.startsWith('/build/') || url.pathname.startsWith('/vendor/') || url.pathname.startsWith('/icons/')) {
        event.respondWith(caches.open(STATIC).then(async (cache) => {
            const hit = await cache.match(request, { ignoreVary: true, ignoreSearch: true });
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
            if (serverDown(res)) return offlineData(request);
            if (res.ok) {
                const copy = res.clone();
                caches.open(DATA).then((c) => c.put(request, copy));
            }
            return res;
        }).catch(() => offlineData(request)));
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
                if (res && res.status < 500) return res;
                return (await shellCopy()) || res || (await network);
            } catch {
                return (await shellCopy()) || Response.error();
            }
        })());
    }
});
