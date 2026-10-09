// Domino Check: a local, day-by-day stress test of the next pay cycles.
// It answers "what tips over first if payday is late / a surprise bill lands?"
// Runs entirely on the device, so it works offline.
const parse = (s) => { const [y, m, d] = String(s).slice(0, 10).split('-').map(Number); return new Date(y, m - 1, d); };
export const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const addDays = (d, n) => new Date(d.getFullYear(), d.getMonth(), d.getDate() + n);
const daysIn = (y, m) => new Date(y, m + 1, 0).getDate();
const diffDays = (a, b) => Math.round((b - a) / 86400000);
const r2 = (n) => Math.round(n * 100) / 100;

function addMonths(d, n) {
    const t = new Date(d.getFullYear(), d.getMonth() + n, 1);
    return new Date(t.getFullYear(), t.getMonth(), Math.min(d.getDate(), daysIn(t.getFullYear(), t.getMonth())));
}

/** Mirrors FinanceService::shiftPayday on the server. */
export function shiftPayday(d, frequency, dir = 1) {
    if (frequency === 'weekly') return addDays(d, 7 * dir);
    if (frequency === 'biweekly') return addDays(d, 14 * dir);
    if (frequency === 'semimonthly') {
        const last = daysIn(d.getFullYear(), d.getMonth());
        if (dir > 0) return d.getDate() < 15 ? new Date(d.getFullYear(), d.getMonth(), 15) : d.getDate() === last ? new Date(d.getFullYear(), d.getMonth() + 1, 15) : new Date(d.getFullYear(), d.getMonth(), last);
        return d.getDate() > 15 ? new Date(d.getFullYear(), d.getMonth(), 15) : new Date(d.getFullYear(), d.getMonth(), 0);
    }
    return addMonths(d, dir);
}

export function paycheckAmount(monthlyIncome, frequency) {
    return r2({ weekly: (monthlyIncome * 12) / 52, biweekly: (monthlyIncome * 12) / 26, semimonthly: monthlyIncome / 2 }[frequency] ?? monthlyIncome);
}

function billDueDates(bill, start, end) {
    const out = [];
    for (let m = new Date(start.getFullYear(), start.getMonth(), 1); m <= end; m = new Date(m.getFullYear(), m.getMonth() + 1, 1)) {
        const due = new Date(m.getFullYear(), m.getMonth(), Math.min(bill.due_day, daysIn(m.getFullYear(), m.getMonth())));
        // Same rule as Bill::isPaidFor: paid within the 25 days before the due date counts.
        const paid = bill.last_paid_on && parse(bill.last_paid_on) >= addDays(due, -25);
        if (due >= start && due <= end && !paid) out.push(due);
    }
    return out;
}

export const PRESETS = [
    { id: 'late', label: 'Salary 5 days late', icon: '⏰', shocks: { salaryDelayDays: 5 } },
    { id: 'surprise', label: 'Surprise expense', icon: '🏥', shocks: { surpriseAmount: 3000, surpriseDay: 3 } },
    { id: 'bills', label: 'Bills up 30%', icon: '⚡', shocks: { billIncreasePct: 30 } },
    { id: 'cut', label: 'Income cut 20%', icon: '✂️', shocks: { incomeCutPct: 20 } },
];

/**
 * @param {object} input today, balance, nextPayday, payFrequency, paycheck, bills[], dailySpend, shocks
 */
export function simulate({ today, balance, nextPayday, payFrequency = 'monthly', paycheck = 0, bills = [], dailySpend = 0, shocks = {} }) {
    const { salaryDelayDays = 0, surpriseAmount = 0, surpriseDay = 0, billIncreasePct = 0, incomeCutPct = 0 } = shocks;
    const start = parse(today);
    let p = parse(nextPayday || iso(addDays(start, 30)));
    while (p <= start) p = shiftPayday(p, payFrequency, 1);
    const scheduled = [p, shiftPayday(p, payFrequency, 1)];
    const paydays = [addDays(scheduled[0], salaryDelayDays), scheduled[1]].sort((a, b) => a - b);
    const end = new Date(Math.min(addDays(scheduled[1], 1 + salaryDelayDays), addDays(start, 75)));

    const events = new Map();
    const push = (d, e) => { const k = iso(d); events.set(k, [...(events.get(k) || []), e]); };
    paydays.forEach((d, i) => push(d, { type: 'payday', label: i === 0 && salaryDelayDays ? `Payday (${salaryDelayDays} days late)` : 'Payday', amount: r2(paycheck * (1 - incomeCutPct / 100)) }));
    bills.forEach((b) => billDueDates(b, start, end).forEach((d) => push(d, {
        type: 'bill', label: b.name, amount: r2(b.amount * (1 + billIncreasePct / 100)), isDebt: !!b.is_debt, billId: b.id,
    })));
    if (surpriseAmount > 0) push(addDays(start, surpriseDay), { type: 'surprise', label: 'Surprise expense', amount: r2(surpriseAmount) });

    const timeline = [];
    let bal = balance;
    for (let d = start, i = 0; d <= end; d = addDays(d, 1), i++) {
        const today_ = (events.get(iso(d)) || []).sort((a, b) => (a.type === 'payday' ? -1 : b.type === 'payday' ? 1 : 0));
        for (const e of today_) bal += e.type === 'payday' ? e.amount : -e.amount;
        bal -= dailySpend;
        timeline.push({ date: iso(d), day: i, balance: r2(bal), events: today_ });
    }

    const firstShortfall = timeline.find((t) => t.balance < 0) || null;
    const lowest = timeline.reduce((m, t) => (t.balance < m.balance ? t : m), timeline[0]);
    const bufferNeeded = lowest.balance < 0 ? Math.ceil(-lowest.balance) : 0;

    let dominoes = [];
    let collisions = [];
    const suggestions = [];
    if (firstShortfall) {
        const lastPay = [...timeline].reverse().find((t) => t.day <= firstShortfall.day && t.events.some((e) => e.type === 'payday'));
        const from = lastPay ? lastPay.day : 0;
        dominoes = timeline.filter((t) => t.day >= from && t.day <= firstShortfall.day)
            .flatMap((t) => t.events.filter((e) => e.type !== 'payday').map((e) => ({ ...e, date: t.date, balanceAfter: t.balance })));
        const sd = parse(firstShortfall.date);
        collisions = timeline.filter((t) => Math.abs(diffDays(sd, parse(t.date))) <= 3)
            .flatMap((t) => t.events.filter((e) => e.type === 'bill').map((e) => ({ ...e, date: t.date })));

        const days = firstShortfall.day + 1;
        suggestions.push({ kind: 'cut', amount: Math.ceil((bufferNeeded / Math.max(days, 1)) * 100) / 100, until: firstShortfall.date });
        let covered = 0;
        for (const e of dominoes.filter((x) => x.type === 'bill').sort((a, b) => (a.isDebt - b.isDebt) || b.amount - a.amount)) {
            if (covered >= bufferNeeded) break;
            suggestions.push({ kind: e.isDebt ? 'ask-lender' : 'postpone', name: e.label, amount: e.amount, date: e.date });
            covered += e.amount;
        }
        suggestions.push({ kind: 'buffer', amount: bufferNeeded });
    }

    return { timeline, paydays: paydays.map(iso), firstShortfall, lowest, bufferNeeded, dominoes, collisions, suggestions };
}
