import { describe, it, expect, vi } from 'vitest';

vi.mock('../../resources/js/stores/auth', () => ({ useAuth: () => ({ user: { currency: 'USD' } }) }));
vi.mock('../../resources/js/ai/engine', () => ({ llmReady: () => false, completeJSON: async () => null }));

const { parseAmount, parseDate, parseTransaction, detectIntent } = await import('../../resources/js/ai/parse');
const { extractReceipt } = await import('../../resources/js/ai/receipt');

const categories = [
    { id: 1, name: 'Dining Out', kind: 'want', keywords: ['lunch', 'coffee', 'pizza', 'starbucks'] },
    { id: 2, name: 'Groceries', kind: 'need', keywords: ['grocery', 'trader joe', 'aldi'] },
    { id: 3, name: 'Transport', kind: 'need', keywords: ['uber', 'fuel'] },
    { id: 4, name: 'Other', kind: 'want', keywords: [] },
    { id: 5, name: 'Salary', kind: 'income', keywords: ['salary', 'paycheck'] },
    { id: 6, name: 'Other Income', kind: 'income', keywords: [] },
];
const now = new Date(2026, 9, 9); // Fri 9 Oct 2026

describe('parseAmount', () => {
    it.each([
        ['spent $12.50 on lunch', 12.5],
        ['paid 1,250 rent', 1250],
        ['₱500 groceries', 500],
        ['laptop 1.2k', 1200],
        ['coffee 4', 4],
        ['no money here', null],
    ])('%s -> %s', (text, expected) => expect(parseAmount(text)).toBe(expected));
});

describe('parseDate', () => {
    it('understands relative dates', () => {
        expect(parseDate('yesterday', now)).toBe('2026-10-08');
        expect(parseDate('3 days ago', now)).toBe('2026-10-06');
        expect(parseDate('last monday', now)).toBe('2026-10-05');
        expect(parseDate('on oct 2', now)).toBe('2026-10-02');
        expect(parseDate('today', now)).toBe('2026-10-09');
    });
});

describe('parseTransaction', () => {
    it('extracts an expense with merchant and category', () => {
        const t = parseTransaction('spent 18.75 on pizza at Pizza Hut yesterday', categories);
        expect(t).toMatchObject({ type: 'expense', amount: 18.75, merchant: 'Pizza Hut', category_id: 1, source: 'chat' });
    });
    it('detects income', () => {
        const t = parseTransaction('got paid salary 2400', categories);
        expect(t).toMatchObject({ type: 'income', amount: 2400, category_id: 5 });
    });
    it('falls back to Other', () => {
        expect(parseTransaction('bought a thing 30', categories).category_id).toBe(4);
    });
});

describe('detectIntent', () => {
    it.each([
        ['Can I afford a $120 jacket?', 'afford'],
        ['can I afford netflix for 15 a month', 'afford'],
        ['spent 12 on lunch', 'log'],
        ['uber 23.40', 'log'],
        ['How much can I spend today?', 'safe'],
        ['Where did my money go this month?', 'spending'],
        ['Am I on track with my budget?', 'budget'],
        ['When will I reach my goals?', 'goal'],
        ['Plan my next paycheck', 'payday'],
        ['How do I pay off my credit card?', 'debt'],
        ['give me tips to save more', 'tips'],
        ['hello', 'general'],
    ])('%s -> %s', (text, type) => expect(detectIntent(text).type).toBe(type));

    it.each([
        ['What if my salary is 7 days late?', 'whatif'],
        ['what if I get a surprise expense of 3000', 'whatif'],
        ['Does this work without internet?', 'app'],
        ['How do I share my QR code?', 'app'],
        ['how does fair share work in barkada plans', 'app'],
        ['Is my data private?', 'app'],
        ['What is Amotan?', 'app'],
    ])('%s -> %s', (text, type) => expect(detectIntent(text).type).toBe(type));

    it('reads what-if shocks', () => {
        expect(detectIntent('what if my salary is 7 days late').shocks).toMatchObject({ salaryDelayDays: 7, surpriseAmount: 0 });
        expect(detectIntent('what if a surprise hospital bill of 5000 comes').shocks.surpriseAmount).toBe(5000);
        expect(detectIntent('how do I share my QR code?').topic).toBe('qr');
    });

    it('flags monthly affordability', () => {
        expect(detectIntent('can I afford a 40 monthly subscription').monthly).toBe(true);
    });
});

describe('extractReceipt', () => {
    it('finds total, date and merchant and ignores subtotal/tax', () => {
        const r = extractReceipt(`TRADER JOE'S\n2001 Market St\nTel 415-555-0100\n10/07/2026 14:32\nBANANAS 1.29\nOLIVE OIL 11.99\nSUBTOTAL 27.55\nTAX 2.27\nTOTAL 29.82\nVISA ****1234 29.82`);
        expect(r.total).toBe(29.82);
        expect(r.date).toBe('2026-10-07');
        expect(r.merchant).toBe("TRADER JOE'S");
    });
    it('falls back to the largest amount', () => {
        expect(extractReceipt('CAFE\nlatte 4.50\nmuffin 3.25\n7.75').total).toBe(7.75);
    });
});
