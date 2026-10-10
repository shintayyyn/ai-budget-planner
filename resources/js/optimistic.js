// Offline changes are shown right away: a queued change is also applied to the
// saved copies of the API reads, so screens reflect it before it syncs.
import { cache } from './offline';

export const tmpId = (key) => `tmp-${key}`;
const num = (v) => (v === '' || v === null || v === undefined ? null : Number(v));
const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

async function update(match, fn) {
    for (const key of await cache.keys()) {
        if (typeof key !== 'string' || !match(key)) continue;
        const value = await cache.get(key);
        if (value === undefined || value === null) continue;
        const next = fn(structuredClone(value), key);
        if (next !== undefined) await cache.set(key, next);
    }
}

const exact = (path) => (k) => k === path || k.startsWith(`${path}?`);
const sameId = (a, b) => String(a) === String(b);
const replaceIn = (list, id, patch) => list.map((x) => (sameId(x.id, id) ? { ...x, ...patch } : x));
const removeFrom = (list, id) => list.filter((x) => !sameId(x.id, id));

async function category(id) {
    if (!id) return null;
    const cats = await cache.get('/api/categories');
    return Array.isArray(cats) ? cats.find((c) => sameId(c.id, id)) || null : null;
}

function nextDue(day) {
    const t = new Date();
    const due = new Date(t.getFullYear(), t.getMonth(), Math.min(day || 1, 28));
    if (due < new Date(t.getFullYear(), t.getMonth(), t.getDate())) due.setMonth(due.getMonth() + 1);
    return iso(due);
}

function projection(g) {
    const target = Number(g.target_amount) || 0;
    const saved = Number(g.saved_amount) || 0;
    const remaining = Math.max(0, target - saved);
    const pace = num(g.monthly_contribution);
    let eta = null;
    if (remaining > 0 && pace > 0) {
        const d = new Date();
        d.setDate(d.getDate() + Math.ceil((remaining / pace) * 30.44));
        eta = iso(d);
    }
    return { ...(g.projection || {}), percent: target ? Math.min(100, Math.round((saved / target) * 100)) : 0, remaining, monthly_pace: pace, eta, required_monthly: g.projection?.required_monthly ?? null, on_track: g.projection?.on_track ?? true };
}

const handlers = [
    [/^POST \/transactions$/, async ({ body, id }) => {
        const tx = { id, ...body, amount: Number(body.amount), category: await category(body.category_id), source: body.source || 'manual', pending: true };
        const month = (tx.occurred_on || '').slice(0, 7);
        await update(exact('/api/transactions'), (v, k) => {
            const q = new URL(k, location.origin).searchParams;
            if (!Array.isArray(v.data) || (q.get('month') && q.get('month') !== month) || Number(q.get('page') || 1) !== 1) return;
            if (q.get('type') && q.get('type') !== tx.type) return;
            v.data = [tx, ...v.data].sort((a, b) => (b.occurred_on || '').localeCompare(a.occurred_on || ''));
            v.total = (v.total || 0) + 1;
            return v;
        });
        await update(exact('/api/dashboard'), (v) => {
            if (!Array.isArray(v.recent)) return;
            v.recent = [tx, ...v.recent].slice(0, Math.max(v.recent.length, 1));
            return v;
        });
    }],
    [/^PUT \/transactions\/([^/]+)$/, async ({ body, m }) => {
        const patch = { ...body, amount: Number(body.amount), category: await category(body.category_id) };
        await update(exact('/api/transactions'), (v) => (Array.isArray(v.data) ? { ...v, data: replaceIn(v.data, m[1], patch) } : undefined));
        await update(exact('/api/dashboard'), (v) => (Array.isArray(v.recent) ? { ...v, recent: replaceIn(v.recent, m[1], patch) } : undefined));
    }],
    [/^DELETE \/transactions\/([^/]+)$/, async ({ m }) => {
        await update(exact('/api/transactions'), (v) => (Array.isArray(v.data) ? { ...v, data: removeFrom(v.data, m[1]) } : undefined));
        await update(exact('/api/dashboard'), (v) => (Array.isArray(v.recent) ? { ...v, recent: removeFrom(v.recent, m[1]) } : undefined));
    }],

    [/^POST \/bills$/, async ({ body, id }) => {
        const bill = { id, ...body, amount: Number(body.amount), debt_balance: num(body.debt_balance), interest_rate: num(body.interest_rate), category: await category(body.category_id), next_due: nextDue(body.due_day), paid_this_cycle: false, pending: true };
        await update(exact('/api/bills'), (v) => (Array.isArray(v) ? [...v, bill].sort((a, b) => a.due_day - b.due_day) : undefined));
    }],
    [/^PUT \/bills\/([^/]+)$/, async ({ body, m }) => {
        const patch = { ...body, amount: Number(body.amount), debt_balance: num(body.debt_balance), interest_rate: num(body.interest_rate), category: await category(body.category_id), next_due: nextDue(body.due_day) };
        await update(exact('/api/bills'), (v) => (Array.isArray(v) ? replaceIn(v, m[1], patch) : undefined));
    }],
    [/^DELETE \/bills\/([^/]+)$/, ({ m }) => update(exact('/api/bills'), (v) => (Array.isArray(v) ? removeFrom(v, m[1]) : undefined))],
    [/^POST \/bills\/([^/]+)\/pay$/, ({ body, m }) => update(exact('/api/bills'), (v) => (Array.isArray(v) ? v.map((b) => {
        if (!sameId(b.id, m[1])) return b;
        const paid = Number(body?.amount) || b.amount;
        return { ...b, paid_this_cycle: true, debt_balance: b.is_debt && b.debt_balance !== null ? Math.max(0, b.debt_balance - paid) : b.debt_balance };
    }) : undefined))],

    [/^POST \/goals$/, ({ body, id }) => {
        const goal = { id, ...body, target_amount: Number(body.target_amount), saved_amount: Number(body.saved_amount || 0), monthly_contribution: num(body.monthly_contribution), pending: true };
        goal.projection = projection(goal);
        return update(exact('/api/goals'), (v) => (Array.isArray(v) ? [...v, goal] : undefined));
    }],
    [/^PUT \/goals\/([^/]+)$/, ({ body, m }) => update(exact('/api/goals'), (v) => (Array.isArray(v) ? v.map((g) => {
        if (!sameId(g.id, m[1])) return g;
        const next = { ...g, ...body, target_amount: Number(body.target_amount), saved_amount: Number(body.saved_amount || 0), monthly_contribution: num(body.monthly_contribution) };
        return { ...next, projection: projection(next) };
    }) : undefined))],
    [/^DELETE \/goals\/([^/]+)$/, ({ m }) => update(exact('/api/goals'), (v) => (Array.isArray(v) ? removeFrom(v, m[1]) : undefined))],
    [/^POST \/goals\/([^/]+)\/contribute$/, ({ body, m }) => update(exact('/api/goals'), (v) => (Array.isArray(v) ? v.map((g) => {
        if (!sameId(g.id, m[1])) return g;
        const next = { ...g, saved_amount: Math.max(0, Number(g.saved_amount) + Number(body.amount)) };
        return { ...next, projection: projection(next) };
    }) : undefined))],

    [/^POST \/categories$/, ({ body, id }) => update(exact('/api/categories'), (v) => (Array.isArray(v) ? [...v, { id, ...body, pending: true }] : undefined))],
    [/^PUT \/categories\/([^/]+)$/, ({ body, m }) => update(exact('/api/categories'), (v) => (Array.isArray(v) ? replaceIn(v, m[1], body) : undefined))],
    [/^DELETE \/categories\/([^/]+)$/, ({ m }) => update(exact('/api/categories'), (v) => (Array.isArray(v) ? removeFrom(v, m[1]) : undefined))],

    [/^PUT \/budget$/, ({ body }) => update(exact('/api/budget'), (v, k) => {
        const q = new URL(k, location.origin).searchParams;
        if (q.get('month') && body.month && q.get('month') !== body.month) return;
        if (!Array.isArray(v.categories)) return;
        const lines = Object.fromEntries((body.lines || []).map((l) => [String(l.category_id), Number(l.amount)]));
        v.categories = v.categories.map((c) => ({ ...c, budget: lines[String(c.id)] ?? null }));
        v.total_budget = Object.values(lines).reduce((s, n) => s + n, 0);
        return v;
    })],

    [/^POST \/alerts\/read-all$/, () => update(exact('/api/alerts'), (v) => (Array.isArray(v) ? v.map((a) => ({ ...a, read_at: a.read_at || new Date().toISOString() })) : undefined))],
    [/^POST \/alerts\/([^/]+)\/read$/, ({ m }) => update(exact('/api/alerts'), (v) => (Array.isArray(v) ? replaceIn(v, m[1], { read_at: new Date().toISOString() }) : undefined))],

    [/^POST \/plans\/([^/]+)\/items$/, async ({ body, id, m }) => {
        const me = await cache.get('/api/me');
        await update((k) => k === `/api/plans/${m[1]}`, (p) => (Array.isArray(p.items) ? { ...p, items: [{ id, ...body, amount: Number(body.amount), occurred_on: body.occurred_on || iso(new Date()), user: me ? { id: me.id, name: me.name } : null, pending: true }, ...p.items] } : undefined));
    }],
    [/^DELETE \/plans\/([^/]+)\/items\/([^/]+)$/, ({ m }) => update((k) => k === `/api/plans/${m[1]}`, (p) => (Array.isArray(p.items) ? { ...p, items: removeFrom(p.items, m[2]) } : undefined))],
    [/^POST \/plans\/([^/]+)\/tasks$/, ({ body, id, m }) => update((k) => k === `/api/plans/${m[1]}`, (p) => (Array.isArray(p.tasks) ? { ...p, tasks: [...p.tasks, { id, ...body, estimated_cost: num(body.estimated_cost), done: false, pending: true }] } : undefined))],
    [/^PATCH \/plans\/([^/]+)\/tasks\/([^/]+)$/, ({ body, m }) => update((k) => k === `/api/plans/${m[1]}`, (p) => (Array.isArray(p.tasks) ? { ...p, tasks: replaceIn(p.tasks, m[2], body) } : undefined))],
    [/^DELETE \/plans\/([^/]+)\/tasks\/([^/]+)$/, ({ m }) => update((k) => k === `/api/plans/${m[1]}`, (p) => (Array.isArray(p.tasks) ? { ...p, tasks: removeFrom(p.tasks, m[2]) } : undefined))],
    [/^PATCH \/plans\/([^/]+)\/notes\/([^/]+)$/, ({ body, m }) => update((k) => k === `/api/plans/${m[1]}/notes`, (v) => (Array.isArray(v) ? replaceIn(v, m[2], body) : undefined))],
    [/^DELETE \/plans\/([^/]+)\/(notes|events)\/([^/]+)$/, ({ m }) => update((k) => k === `/api/plans/${m[1]}/${m[2]}`, (v) => (Array.isArray(v) ? removeFrom(v, m[3]) : undefined))],
];

/** Apply a queued change to the saved API reads. Never throws: the change is safe in the outbox either way. */
export async function applyOptimistic(method, path, body, key) {
    const sig = `${method} ${path}`;
    for (const [re, fn] of handlers) {
        const m = sig.match(re);
        if (!m) continue;
        try { await fn({ body: body || {}, id: tmpId(key), m }); } catch (e) { console.warn('Offline preview failed', e); }
        return;
    }
}
