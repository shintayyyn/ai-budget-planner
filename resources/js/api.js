// Tiny fetch wrapper for the Laravel API (Sanctum bearer tokens).
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
    }
}

let onUnauthorized = () => {};
export const setUnauthorizedHandler = (fn) => { onUnauthorized = fn; };

async function request(method, path, body, { query, raw } = {}) {
    const url = new URL(`/api${path}`, location.origin);
    Object.entries(query || {}).forEach(([k, v]) => v !== undefined && v !== null && v !== '' && url.searchParams.set(k, v));

    const headers = { Accept: 'application/json' };
    const t = token.get();
    if (t) headers.Authorization = `Bearer ${t}`;
    const isForm = body instanceof FormData;
    if (body && !isForm) headers['Content-Type'] = 'application/json';

    const res = await fetch(url, { method, headers, body: body ? (isForm ? body : JSON.stringify(body)) : undefined });
    if (res.status === 401) onUnauthorized();
    if (raw) return res;
    if (res.status === 204) return null;
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new ApiError(res.status, data);
    return data;
}

export const api = {
    get: (p, query) => request('GET', p, null, { query }),
    post: (p, body, query) => request('POST', p, body, { query }),
    put: (p, body) => request('PUT', p, body),
    patch: (p, body) => request('PATCH', p, body),
    del: (p, body) => request('DELETE', p, body),
    raw: (p) => request('GET', p, null, { raw: true }),
};
