// The financial assistant. Numbers always come from the server's finance engine
// (grounded facts); the on-device language model only turns them into friendly
// language. With no model loaded, the built-in answers are used as-is.
import { api } from '../api';
import { money, niceDate, daysUntil, today } from '../format';
import { detectIntent, parseTransaction } from './parse';
import { llmReady, complete, completeJSON } from './engine';

let cache = null;
let cachedAt = 0;

export async function getContext(force = false) {
    if (!force && cache && Date.now() - cachedAt < 60_000) return cache;
    cache = await api.get('/ai/context');
    cachedAt = Date.now();
    return cache;
}

export const invalidateContext = () => { cache = null; };

const SYSTEM = (ctx) => `You are Penny, the friendly money assistant inside "AI Budget Planner". You run privately on the user's own device.
Today is ${ctx.today}. Currency: ${ctx.user.currency}.
Rules:
- Use ONLY the numbers given in FACTS. Never invent or recalculate figures.
- Keep answers short: 2-4 sentences, or up to 4 short bullet points.
- Be warm, practical and non-judgemental. End with one concrete next step when useful.
- You are not a licensed financial advisor. For investing, tax or legal questions give general education only and suggest a professional.
- If FACTS do not contain the answer, say what you can see and suggest where in the app to look.`;

/** Compact overview handed to the model for open-ended questions. */
function overview(ctx) {
    const s = ctx.safe_to_spend;
    const m = ctx.month;
    const top = [...m.categories].sort((a, b) => b.spent - a.spent).slice(0, 4)
        .map((c) => `${c.name} ${money(c.spent)}${c.budget ? ` of ${money(c.budget)}` : ''}`).join('; ');
    return [
        `Balance ${money(s.balance)}. Next payday ${s.next_payday} (${s.days_left} days).`,
        `Safe to spend until payday ${money(s.safe_to_spend)} (${money(s.daily_allowance)}/day) after ${money(s.bills_total)} bills and ${money(s.savings_reserved)} savings.`,
        `Recent spending pace ${money(s.daily_spend_rate)}/day.${s.run_out_date ? ` At this pace money runs out on ${s.run_out_date}.` : ''}`,
        `This month: spent ${money(m.total_spent)} of ${money(m.total_budget)} budget; projected ${money(m.projected_spend)}. Top: ${top || 'none yet'}.`,
        ctx.goals.length ? `Goals: ${ctx.goals.map((g) => `${g.name} ${money(g.saved)}/${money(g.target)}${g.eta ? ` ETA ${g.eta}` : ''}`).join('; ')}.` : 'No savings goals yet.',
        `Monthly income ${money(ctx.user.monthly_income)}, paid ${ctx.user.pay_frequency}.`,
    ].join('\n');
}

const handlers = {
    async afford(ctx, intent) {
        const a = await api.post('/ai/affordability', { amount: intent.amount, monthly: intent.monthly });
        const verdictText = { yes: 'Yes, you can afford it', caution: 'You can, but it will be tight', no: "I'd hold off for now" }[a.verdict];
        let fallback;
        if (a.monthly) {
            fallback = `${verdictText}. ${money(a.amount)}/month is ${a.share_of_discretionary ?? '—'}% of the ${money(a.monthly_discretionary)} you have left each month after bills and savings.`;
        } else if (a.verdict === 'no') {
            fallback = `${verdictText}. ${money(a.amount)} is more than your safe-to-spend of ${money(a.safe_to_spend_before)} until payday (${niceDate(a.next_payday)}).`
                + (a.days_to_save ? ` If you set aside your daily surplus, you could cover it in about ${a.days_to_save} days.` : ' Consider waiting for payday or saving toward it as a goal.');
        } else {
            fallback = `${verdictText}. After buying it you'd have ${money(a.safe_to_spend_after)} left until payday, about ${money(a.daily_allowance_after)}/day for ${a.days_left} days (down from ${money(a.daily_allowance_before)}/day).`;
        }
        return { facts: { affordability: a }, fallback, verdict: a.verdict };
    },

    async safe(ctx) {
        const s = ctx.safe_to_spend;
        let fallback = `You can safely spend ${money(s.safe_to_spend)} until payday on ${niceDate(s.next_payday)}, which is about ${money(s.daily_allowance)} a day for ${s.days_left} days. That already sets aside ${money(s.bills_total)} for bills and ${money(s.savings_reserved)} for savings.`;
        if (s.run_out_date) fallback += ` ⚠️ At your recent pace of ${money(s.daily_spend_rate)}/day you'd run out around ${niceDate(s.run_out_date)}.`;
        else if (s.daily_spend_rate > s.daily_allowance) fallback += ` Your recent pace (${money(s.daily_spend_rate)}/day) is above that, so ease off a little.`;
        else fallback += ` You're on track. Your recent pace is ${money(s.daily_spend_rate)}/day.`;
        return { facts: { safe_to_spend: s }, fallback };
    },

    async spending(ctx, intent) {
        const cats = intent.period === 'last_month' ? ctx.last_month : ctx.month.categories;
        const total = cats.reduce((s, c) => s + c.spent, 0);
        const top = [...cats].sort((a, b) => b.spent - a.spent).filter((c) => c.spent > 0).slice(0, 5);
        const label = intent.period === 'last_month' ? 'Last month' : 'This month';
        const fallback = `${label} you've spent ${money(total)}.\n${top.map((c) => `• ${c.name}: ${money(c.spent)} (${total ? Math.round((c.spent / total) * 100) : 0}%)`).join('\n')}`;
        return { facts: { period: label, total, categories: top }, fallback, link: { to: '/activity', label: 'See all activity' } };
    },

    async budget(ctx) {
        const m = ctx.month;
        const over = m.categories.filter((c) => c.budget && c.spent > c.budget);
        const near = m.categories.filter((c) => c.budget && c.percent >= 80 && c.spent <= c.budget);
        let fallback = `You've spent ${money(m.total_spent)} of your ${money(m.total_budget)} budget this month (day ${m.days_elapsed} of ${m.days_in_month}). Projected month total: ${money(m.projected_spend)}.`;
        if (over.length) fallback += `\nOver budget: ${over.map((c) => `${c.name} (+${money(c.spent - c.budget)})`).join(', ')}.`;
        if (near.length) fallback += `\nClose to the limit: ${near.map((c) => `${c.name} ${c.percent}%`).join(', ')}.`;
        if (!over.length && !near.length) fallback += '\nEvery category is within budget. Nice work!';
        return { facts: { month: { ...m, categories: m.categories.map(({ name, spent, budget, percent }) => ({ name, spent, budget, percent })) } }, fallback, link: { to: '/plan/budget', label: 'Open budget' } };
    },

    async goal(ctx, intent) {
        if (!ctx.goals.length) {
            return { facts: { goals: [] }, fallback: "You don't have any savings goals yet. Create one and I'll tell you how much to save each payday and when you'll get there.", link: { to: '/goals', label: 'Create a goal' } };
        }
        const lines = ctx.goals.map((g) => {
            const bits = [`${g.name}: ${money(g.saved)} of ${money(g.target)} (${g.percent}%)`];
            if (g.eta) bits.push(`on pace to finish ${niceDate(g.eta, { month: 'short', year: 'numeric' })}`);
            if (g.required_monthly) bits.push(`needs ${money(g.required_monthly)}/month to hit ${niceDate(g.target_date, { month: 'short', year: 'numeric' })}${g.on_track === false ? ' (behind)' : ''}`);
            if (!g.eta && !g.required_monthly) bits.push('set a monthly amount to get an estimate');
            return `• ${bits.join(', ')}`;
        });
        let extra = '';
        if (intent.amount) {
            // Monthly surplus = income − bills − current goal saving − typical day-to-day spending.
            const bills = ctx.bills.reduce((s, b) => s + b.amount, 0);
            const goalSaving = ctx.goals.reduce((s, g) => s + (g.monthly_pace || 0), 0);
            const surplus = Math.max(0, ctx.user.monthly_income - bills - goalSaving - ctx.safe_to_spend.daily_spend_rate * 30.44);
            extra = surplus > 0
                ? `\nSaving ${money(intent.amount)} at your current monthly surplus of about ${money(surplus)} would take roughly ${Math.ceil(intent.amount / surplus)} month(s).`
                : `\nRight now there's no monthly surplus to save ${money(intent.amount)}. Trimming wants spending is the fastest way to create one.`;
        }
        return { facts: { goals: ctx.goals, target_amount: intent.amount }, fallback: lines.join('\n') + extra, link: { to: '/goals', label: 'View goals' } };
    },

    async payday() {
        const p = await api.get('/payday');
        const t = p.totals;
        const fallback = `For your ${money(p.income)} paycheck on ${niceDate(p.payday)} (${p.days} days until the next one):\n`
            + [['bills', 'Bills'], ['debt', 'Debt payments'], ['essentials', 'Essentials'], ['savings', 'Savings'], ['buffer', 'Buffer'], ['daily', 'Spending money']]
                .filter(([k]) => t[k]).map(([k, l]) => `• ${l}: ${money(t[k])}`).join('\n')
            + `\nThat gives you ${money(p.daily_allowance)}/day.${p.warnings.length ? `\n⚠️ ${p.warnings[0]}` : ''}`;
        return { facts: { payday_plan: { ...p, allocations: undefined } }, fallback, link: { to: '/plan/payday', label: 'Open payday planner' } };
    },

    async debt(ctx) {
        const debts = ctx.bills.filter((b) => b.is_debt);
        if (!debts.length) return { facts: {}, fallback: "You haven't added any debts. Add loans or credit cards under Bills & Debts and I'll help you plan payoff.", link: { to: '/plan/bills', label: 'Add a debt' } };
        const sorted = [...debts].sort((a, b) => (b.interest_rate || 0) - (a.interest_rate || 0));
        const total = debts.reduce((s, d) => s + (d.debt_balance || 0), 0);
        const fallback = `You owe ${money(total)} across ${debts.length} debt(s). Using the avalanche method, put any extra money toward ${sorted[0].name}${sorted[0].interest_rate ? ` (${sorted[0].interest_rate}% interest)` : ''} first while paying the minimums on the rest.\n`
            + sorted.map((d) => `• ${d.name}: ${money(d.debt_balance || 0)} at ${d.interest_rate ?? '?'}%, min ${money(d.amount)}`).join('\n');
        return { facts: { debts: sorted }, fallback, link: { to: '/plan/bills', label: 'Bills & debts' } };
    },

    async tips(ctx) {
        const wants = ctx.month.categories.filter((c) => c.kind === 'want').sort((a, b) => b.spent - a.spent);
        const tips = [];
        const over = ctx.month.categories.filter((c) => c.budget && c.spent > c.budget);
        if (over.length) tips.push(`Pause spending on ${over.map((c) => c.name).join(' and ')} for the rest of the month. You're already over.`);
        if (wants[0]?.spent > 0) tips.push(`${wants[0].name} is your biggest "want" at ${money(wants[0].spent)}. Cutting it by a quarter frees up ${money(wants[0].spent * 0.25)}.`);
        if (ctx.safe_to_spend.daily_spend_rate > ctx.safe_to_spend.daily_allowance) tips.push(`Try a daily cap of ${money(Math.max(0, ctx.safe_to_spend.daily_allowance))} until payday.`);
        if (!ctx.goals.some((g) => /emergency/i.test(g.name))) tips.push('Start an emergency fund. Even one month of expenses prevents most money crises.');
        tips.push('On payday, move your savings first ("pay yourself first") so it never gets spent.');
        return { facts: { month: ctx.month.categories.map(({ name, kind, spent, budget }) => ({ name, kind, spent, budget })), safe_to_spend: ctx.safe_to_spend }, fallback: tips.slice(0, 4).map((t) => `• ${t}`).join('\n') };
    },

    async general(ctx) {
        return {
            facts: null,
            fallback: `Here's where you stand: ${money(ctx.safe_to_spend.safe_to_spend)} safe to spend until payday (${money(ctx.safe_to_spend.daily_allowance)}/day), and ${money(ctx.month.total_spent)} spent this month. Ask me things like "Can I afford a $200 jacket?", "How much did I spend on food?", or tell me "Spent 12 on lunch".`,
        };
    },
};

/**
 * Answer a message. Returns { text, action?, link?, intent }.
 * `onToken` streams partial text when a language model is available.
 */
export async function answer(text, history = [], { onToken } = {}) {
    const ctx = await getContext();
    const intent = detectIntent(text);

    if (intent.type === 'log') {
        let draft = parseTransaction(text, ctx.categories);
        if (llmReady()) draft = await refineDraft(text, draft, ctx);
        if (draft) {
            const verb = draft.type === 'income' ? 'income of' : '';
            return {
                intent,
                text: `Log ${verb} ${money(draft.amount)}${draft.merchant ? ` at ${draft.merchant}` : ''} for “${draft.description}” under ${draft.category?.icon || ''} ${draft.category?.name || 'Other'} (${niceDate(draft.occurred_on)})?`,
                action: { kind: 'log', draft },
            };
        }
    }

    const handler = handlers[intent.type] || handlers.general;
    const result = await handler(ctx, intent);

    if (!llmReady()) return { intent, text: result.fallback, link: result.link };

    const facts = `${overview(ctx)}${result.facts ? `\nDETAIL: ${JSON.stringify(result.facts)}` : ''}\nDRAFT ANSWER (correct numbers, rephrase naturally): ${result.fallback}`;
    const messages = [
        { role: 'system', content: `${SYSTEM(ctx)}\n\nFACTS:\n${facts}` },
        ...history.slice(-6).map((m) => ({ role: m.role, content: m.text })),
        { role: 'user', content: text },
    ];
    try {
        const reply = await complete(messages, { onToken });
        return { intent, text: reply.trim() || result.fallback, link: result.link, ai: true };
    } catch (e) {
        console.warn('LLM failed, using built-in answer', e);
        return { intent, text: result.fallback, link: result.link };
    }
}

/** Let the model fix up a rule-based transaction parse (category, merchant, description). */
async function refineDraft(text, draft, ctx) {
    const names = ctx.categories.filter((c) => c.kind !== 'income').map((c) => c.name).join(', ');
    try {
        const out = await completeJSON([
            { role: 'system', content: `Extract a money transaction from the user's message. Reply with JSON only: {"type":"expense"|"income","amount":number,"description":string,"merchant":string|null,"category":string}. category must be one of: ${names}, Salary, Other Income.` },
            { role: 'user', content: text },
        ]);
        if (!out || !out.amount) return draft;
        const category = ctx.categories.find((c) => c.name.toLowerCase() === String(out.category || '').toLowerCase()) || draft?.category;
        return {
            ...(draft || {}),
            type: out.type === 'income' ? 'income' : 'expense',
            amount: draft?.amount || Math.round(Number(out.amount) * 100) / 100,
            description: out.description || draft?.description,
            merchant: out.merchant || draft?.merchant || null,
            category,
            category_id: category?.id || null,
            occurred_on: draft?.occurred_on || today(),
            source: 'chat',
        };
    } catch {
        return draft;
    }
}

export const daysToPayday = (ctx) => daysUntil(ctx.safe_to_spend.next_payday);
