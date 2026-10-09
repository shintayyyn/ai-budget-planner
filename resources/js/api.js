// Fetch wrapper for the Laravel API (Sanctum bearer tokens), offline-first:
// reads fall back to the last copy saved on the device, and changes made
// offline are queued in the outbox and synced when the device is back online.
import { cache, newKey, describe } from './offline';
import { enqueue } from './sync';

const TOKEN_KEY = 'abp_token';

export const token = {
    get: () => { try { return localStorage.getItem(TOKEN_KEY); } catch { return null; } },
    set: (t) => { try { t ? localStorage.setItem(TOKEN_KEY, t) : localStorage.removeItem(TOKEN_KEY); } catch {} },
};

export class ApiError extends Error {
    constructor(status, body) {
        super(body?.message || `Request failed (${status})`);
        this.status = status;
        this.errors = body?.errors || {};
        this.offline = status === 0;
    }
}

// Things that only make sense live (auth, AI, uploads, code resets) are never queued.
const LIVE_ONLY = /^\/(login|register|logout|ai\/|profile\/share-code|transactions\/receipt)/;
const offlineError = () => new ApiError(0, { message: "You're offline and this hasn't been saved on this device yet." });
const isOfflineReply = (res) => res.status === 503 && res.headers.get('X-Amotan-Offline') === '1';

function headersFor(body, key) {
    const headers = { Accept: 'application/json' };
    const t = token.get();
    if (t) headers.Authorization = `Bearer ${t}`;
    if (body && !(body instanceof FormData)) headers['Content-Type'] = 'application/json';
    if (key) headers['Idempotency-Key'] = key;
    return headers;
}

let onUnauthorized = () => {};
export const setUnauthorizedHandler = (fn) => { onUnauthorized = fn; };

async function request(method, path, body, { query, raw } = {}) {
    const url = new URL(`/api${path}`, location.origin);
    Object.entries(query || {}).forEach(([k, v]) => v !== undefined && v !== null && v !== '' && url.searchParams.set(k, v));

    const isForm = body instanceof FormData;
    const cacheKey = url.pathname + url.search;
    const key = method === 'GET' ? null : newKey();
    const queueable = key && !isForm && !LIVE_ONLY.test(path) && !(method === 'DELETE' && path === '/profile');
    const queue = async () => {
        await enqueue({ id: key, method, path, query: query || null, body: body || null, at: Date.now(), label: describe(method, path, body) });
        return { queued: true, offline_id: key };
    };

    if (queueable && typeof navigator !== 'undefined' && navigator.onLine === false) return queue();

    let res;
    try {
        res = await fetch(url, { method, headers: headersFor(body, key), body: body ? (isForm ? body : JSON.stringify(body)) : undefined });
    } catch (e) {
        if (method === 'GET' && !raw) {
            const saved = await cache.get(cacheKey);
            if (saved !== undefined) return saved;
            throw offlineError();
        }
        if (queueable) return queue();
        throw navigator.onLine === false ? offlineError() : e;
    }
    if (method === 'GET' && !raw && isOfflineReply(res)) {
        const saved = await cache.get(cacheKey);
        if (saved !== undefined) return saved;
        throw offlineError();
    }
    if (res.status === 401) onUnauthorized();
    if (raw) return res;
    if (res.status === 204) return null;
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new ApiError(res.status, data);
    if (method === 'GET') cache.set(cacheKey, data);
    return data;
}

/** Send one queued outbox change with its original Idempotency-Key. */
export function replay(item) {
    const url = new URL(`/api${item.path}`, location.origin);
    Object.entries(item.query || {}).forEach(([k, v]) => v !== undefined && v !== null && v !== '' && url.searchParams.set(k, v));
    return fetch(url, { method: item.method, headers: headersFor(item.body, item.id), body: item.body ? JSON.stringify(item.body) : undefined });
}

export const api = {
    get: (p, query) => request('GET', p, null, { query }),
    post: (p, body, query) => request('POST', p, body, { query }),
    put: (p, body) => request('PUT', p, body),
    patch: (p, body) => request('PATCH', p, body),
    del: (p, body) => request('DELETE', p, body),
    raw: (p) => request('GET', p, null, { raw: true }),
};
