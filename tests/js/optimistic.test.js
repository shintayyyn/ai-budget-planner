import { describe, it, expect, vi, beforeEach } from 'vitest';

const store = new Map();
vi.mock('../../resources/js/offline', () => ({
    cache: {
        get: async (k) => store.get(k),
        set: async (k, v) => { store.set(k, v); },
        keys: async () => [...store.keys()],
    },
}));
globalThis.location ??= { origin: 'http://localhost' };

const { applyOptimistic, tmpId } = await import('../../resources/js/optimistic');

describe('offline previews', () => {
    beforeEach(() => store.clear());

    it('adds and edits an offline transaction in the saved lists', async () => {
        store.set('/api/categories', [{ id: 3, name: 'Food', icon: '🍔' }]);
        store.set('/api/transactions?month=2026-10&page=1&per_page=40', { data: [{ id: 1, occurred_on: '2026-10-01' }], total: 1 });
        store.set('/api/transactions?month=2026-09&page=1&per_page=40', { data: [], total: 0 });
        store.set('/api/dashboard', { recent: [{ id: 1 }] });

        await applyOptimistic('POST', '/transactions', { type: 'expense', amount: '4.5', category_id: 3, occurred_on: '2026-10-05', description: 'Coffee' }, 'k1');
        const oct = store.get('/api/transactions?month=2026-10&page=1&per_page=40');
        expect(oct.data[0]).toMatchObject({ id: tmpId('k1'), amount: 4.5, category: { name: 'Food' }, pending: true });
        expect(oct.total).toBe(2);
        expect(store.get('/api/transactions?month=2026-09&page=1&per_page=40').data).toHaveLength(0);
        expect(store.get('/api/dashboard').recent[0].id).toBe(tmpId('k1'));

        await applyOptimistic('PUT', `/transactions/${tmpId('k1')}`, { type: 'expense', amount: '5', category_id: 3, occurred_on: '2026-10-05', description: 'Latte' }, 'k2');
        expect(store.get('/api/transactions?month=2026-10&page=1&per_page=40').data[0]).toMatchObject({ description: 'Latte', amount: 5 });

        await applyOptimistic('DELETE', '/transactions/1', null, 'k3');
        expect(store.get('/api/transactions?month=2026-10&page=1&per_page=40').data.map((t) => t.id)).toEqual([tmpId('k1')]);
    });

    it('updates goal progress for an offline contribution', async () => {
        store.set('/api/goals', [{ id: 7, target_amount: 1000, saved_amount: 100, monthly_contribution: null, projection: { percent: 10 } }]);
        await applyOptimistic('POST', '/goals/7/contribute', { amount: 150 }, 'k4');
        expect(store.get('/api/goals')[0]).toMatchObject({ saved_amount: 250, projection: { percent: 25, remaining: 750 } });
    });

    it('applies a budget edit to the matching month only', async () => {
        store.set('/api/budget?month=2026-10', { categories: [{ id: 3, budget: 100 }, { id: 4, budget: null }], total_budget: 100 });
        store.set('/api/budget?month=2026-09', { categories: [{ id: 3, budget: 100 }], total_budget: 100 });
        await applyOptimistic('PUT', '/budget', { month: '2026-10', lines: [{ category_id: 4, amount: 60 }] }, 'k5');
        expect(store.get('/api/budget?month=2026-10')).toMatchObject({ categories: [{ budget: null }, { budget: 60 }], total_budget: 60 });
        expect(store.get('/api/budget?month=2026-09').total_budget).toBe(100);
    });

    it('ignores changes it has no preview for', async () => {
        await expect(applyOptimistic('POST', '/unknown', {}, 'k6')).resolves.toBeUndefined();
    });
});
