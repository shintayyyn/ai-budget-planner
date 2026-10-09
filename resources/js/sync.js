// Background cloud sync: replays the offline outbox in order whenever the
// device is online. Each change keeps its Idempotency-Key, so a retry after a
// dropped connection is never applied twice on the server.
import { reactive } from 'vue';
import { outbox, cache } from './offline';
import { replay, token } from './api';

const FAILED_KEY = 'amotan_sync_failed';
const LAST_KEY = 'amotan_last_sync';
const read = (k, d) => { try { return JSON.parse(localStorage.getItem(k)) ?? d; } catch { return d; } };
const write = (k, v) => { try { localStorage.setItem(k, JSON.stringify(v)); } catch {} };

export const sync = reactive({
    online: typeof navigator === 'undefined' ? true : navigator.onLine,
    pending: [],
    failed: read(FAILED_KEY, []),
    syncing: false,
    lastSync: read(LAST_KEY, null),
});

const listeners = { synced: [], queued: [] };
export const onSync = (event, fn) => listeners[event].push(fn);

export async function refreshPending() {
    sync.pending = await outbox.all();
}

export async function enqueue(item) {
    await outbox.put(item);
    await refreshPending();
    listeners.queued.forEach((fn) => fn(item));
}

export async function flush() {
    if (sync.syncing || !navigator.onLine || !token.get()) return;
    sync.syncing = true;
    let done = 0;
    try {
        for (const item of await outbox.all()) {
            let res;
            try { res = await replay(item); } catch { break; }
            // Not signed in, rate limited or a server hiccup: keep it and try later.
            if (res.status === 401 || res.status === 408 || res.status === 429 || res.status >= 500) break;
            if (!res.ok) {
                const body = await res.json().catch(() => ({}));
                sync.failed = [{ ...item, error: body.message || `Rejected (${res.status})`, failedAt: Date.now() }, ...sync.failed].slice(0, 20);
                write(FAILED_KEY, sync.failed);
            } else {
                done++;
            }
            await outbox.remove(item.id);
        }
    } finally {
        sync.syncing = false;
        await refreshPending();
    }
    if (done) {
        sync.lastSync = Date.now();
        write(LAST_KEY, sync.lastSync);
        listeners.synced.forEach((fn) => fn(done));
    }
    return done;
}

export function dismissFailed(id) {
    sync.failed = id ? sync.failed.filter((f) => f.id !== id) : [];
    write(FAILED_KEY, sync.failed);
}

/** Wipe everything on this device (logout), so a shared phone keeps no money data. */
export async function wipeDevice() {
    await Promise.all([outbox.clear(), cache.clear()]);
    dismissFailed();
    sync.pending = [];
    try { localStorage.removeItem(LAST_KEY); } catch {}
    sync.lastSync = null;
}

let started = false;
export function startSync() {
    if (started) return;
    started = true;
    const update = () => {
        sync.online = navigator.onLine;
        if (sync.online) flush();
    };
    window.addEventListener('online', update);
    window.addEventListener('offline', update);
    document.addEventListener('visibilitychange', () => document.visibilityState === 'visible' && flush());
    setInterval(flush, 30000);
    refreshPending().then(flush);
}
