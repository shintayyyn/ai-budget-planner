import { describe, it, expect } from 'vitest';
import { simulate, shiftPayday, paycheckAmount, iso } from '../../resources/js/sim/domino';

const base = {
    today: '2026-03-01',
    balance: 1000,
    nextPayday: '2026-03-15',
    payFrequency: 'semimonthly',
    paycheck: 1500,
    dailySpend: 20,
    bills: [
        { id: 1, name: 'Rent', amount: 600, due_day: 10, last_paid_on: null, is_debt: false },
        { id: 2, name: 'Loan', amount: 150, due_day: 16, last_paid_on: null, is_debt: true },
    ],
};

describe('domino check', () => {
    it('stays above zero in the base case', () => {
        const r = simulate(base);
        expect(r.firstShortfall).toBeNull();
        expect(r.bufferNeeded).toBe(0);
        expect(r.paydays).toEqual(['2026-03-15', '2026-03-31']);
        expect(r.timeline[0].balance).toBe(980);
    });

    it('finds the first short day and the dominoes when salary is late', () => {
        const r = simulate({ ...base, shocks: { salaryDelayDays: 5 } });
        expect(r.paydays[0]).toBe('2026-03-20');
        // 1000 - 600 rent - 20/day, then the 150 loan on Mar 16 tips it before the late payday.
        expect(r.firstShortfall.date).toBe('2026-03-16');
        expect(r.dominoes.map((d) => d.label)).toContain('Rent');
        expect(r.bufferNeeded).toBeGreaterThan(0);
        expect(r.suggestions.map((s) => s.kind)).toEqual(['cut', 'postpone', 'buffer']);
        expect(r.collisions.map((c) => c.label)).toContain('Loan');
    });

    it('skips bills already paid this cycle and applies shocks', () => {
        const paid = simulate({ ...base, bills: [{ ...base.bills[0], last_paid_on: '2026-02-20' }], dailySpend: 0 });
        expect(paid.timeline.find((t) => t.date === '2026-03-10').events).toHaveLength(0);

        const shocked = simulate({ ...base, dailySpend: 0, bills: [], shocks: { surpriseAmount: 1200, surpriseDay: 2, incomeCutPct: 50 } });
        expect(shocked.firstShortfall.date).toBe('2026-03-03');
        expect(shocked.timeline.find((t) => t.date === '2026-03-15').balance).toBe(550);
    });

    it('shifts paydays like the server', () => {
        expect(iso(shiftPayday(new Date(2026, 0, 31), 'monthly'))).toBe('2026-02-28');
        expect(iso(shiftPayday(new Date(2026, 1, 28), 'semimonthly'))).toBe('2026-03-15');
        expect(iso(shiftPayday(new Date(2026, 2, 15), 'semimonthly', -1))).toBe('2026-02-28');
        expect(paycheckAmount(2600, 'biweekly')).toBe(1200);
    });
});
