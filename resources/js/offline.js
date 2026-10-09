// On-device storage for offline-first use (IndexedDB):
//  - "kv": the last copy of every API read, so screens open with no signal.
//  - "outbox": changes made offline, replayed to the cloud when back online.
const DB_NAME = 'amotan';
let dbPromise;

function open() {
    dbPromise ??= new Promise((resolve, reject) => {
        if (!('indexedDB' in globalThis)) return reject(new Error('IndexedDB unavailable'));
        const req = indexedDB.open(DB_NAME, 1);
        req.onupgradeneeded = () => {
            req.result.createObjectStore('kv');
            req.result.createObjectStore('outbox', { keyPath: 'id' });
        };
        req.onsuccess = () => resolve(req.result);
        req.onerror = () => reject(req.error);
    });
    return dbPromise;
}

async function run(store, mode, fn) {
    const db = await open();
    return new Promise((resolve, reject) => {
        const tx = db.transaction(store, mode);
        const req = fn(tx.objectStore(store));
        tx.oncomplete = () => resolve(req?.result);
        tx.onerror = () => reject(tx.error);
    });
}

export const cache = {
    get: (key) => run('kv', 'readonly', (s) => s.get(key)).catch(() => undefined),
    set: (key, value) => run('kv', 'readwrite', (s) => s.put(value, key)).catch(() => {}),
    clear: () => run('kv', 'readwrite', (s) => s.clear()).catch(() => {}),
};

export const outbox = {
    all: () => run('outbox', 'readonly', (s) => s.getAll()).then((r) => (r || []).sort((a, b) => a.at - b.at)).catch(() => []),
    put: (item) => run('outbox', 'readwrite', (s) => s.put(item)),
    remove: (id) => run('outbox', 'readwrite', (s) => s.delete(id)).catch(() => {}),
    clear: () => run('outbox', 'readwrite', (s) => s.clear()).catch(() => {}),
};

export const newKey = () => globalThis.crypto?.randomUUID?.() ?? `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}`;

const LABELS = [
    [/^POST \/transactions$/, (b) => `${b?.type === 'income' ? 'Income' : 'Expense'}: ${b?.description || ''} ${b?.amount ?? ''}`.trim()],
    [/^PUT \/transactions\//, () => 'Edit transaction'],
    [/^DELETE \/transactions\//, () => 'Delete transaction'],
    [/^POST \/bills$/, (b) => `New bill: ${b?.name || ''}`],
    [/\/bills\/\d+\/paid$/, () => 'Mark bill paid'],
    [/^POST \/goals$/, (b) => `New goal: ${b?.name || ''}`],
    [/\/goals\/\d+\/contribute$/, (b) => `Add to goal: ${b?.amount ?? ''}`],
    [/^POST \/connect\//, () => 'Add buddy'],
    [/^POST \/plans\/\d+\/items$/, (b) => `Plan entry: ${b?.description || b?.kind || ''} ${b?.amount ?? ''}`.trim()],
    [/^PATCH \/plans\/\d+\/me$/, () => 'Fair-share settings'],
    [/^POST \/plans\/\d+\/messages$/, (b) => `Chat: ${(b?.body || '').slice(0, 40)}`],
    [/^POST \/plans\/\d+\/notes$/, (b) => `Note: ${b?.title || ''}`],
    [/^PATCH \/plans\/\d+\/notes\//, () => 'Edit note'],
    [/^POST \/plans\/\d+\/events$/, (b) => `Calendar: ${b?.title || ''} ${b?.date || ''}`.trim()],
    [/^POST \/join\//, () => 'Join plan'],
    [/^PATCH \/profile$/, () => 'Profile settings'],
];

export function describe(method, path, body) {
    const sig = `${method} ${path}`;
    const hit = LABELS.find(([re]) => re.test(sig));
    return hit ? hit[1](body) : `${method === 'DELETE' ? 'Delete' : 'Update'} ${path.split('/')[1] || 'item'}`;
}
