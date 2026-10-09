// Rule-based natural language understanding. Works instantly on every device
// with no model at all, and gives the language model a head start when one is loaded.
import { toISO } from '../format';

const AMOUNT = /(?:(?:[$€£₱¥₹₩]|usd|php|eur|gbp|inr|aud|cad|sgd)\s*)?(\d{1,3}(?:,\d{3})+|\d+)(?:\.(\d{1,2}))?\s*(k\b)?(?:\s*(?:dollars|bucks|pesos|euros|pounds|usd|php|eur|gbp))?/i;

export function parseAmount(text) {
    const m = text.replace(/(\d)\s+(?=\d{3}\b)/g, '$1').match(AMOUNT);
    if (!m) return null;
    let n = parseFloat(`${m[1].replace(/,/g, '')}.${m[2] || 0}`);
    if (m[3]) n *= 1000;
    return n > 0 ? Math.round(n * 100) / 100 : null;
}

const DAYS = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
const MONTHS = ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'];

export function parseDate(text, now = new Date()) {
    const t = text.toLowerCase();
    const d = new Date(now);
    if (/\byesterday\b/.test(t)) { d.setDate(d.getDate() - 1); return toISO(d); }
    const ago = t.match(/(\d+)\s+days?\s+ago/);
    if (ago) { d.setDate(d.getDate() - Number(ago[1])); return toISO(d); }
    const iso = t.match(/\b(20\d{2})-(\d{2})-(\d{2})\b/);
    if (iso) return iso[0];
    const md = t.match(new RegExp(`\\b(${MONTHS.join('|')})[a-z]*\\.?\\s+(\\d{1,2})\\b`));
    if (md) {
        const r = new Date(now.getFullYear(), MONTHS.indexOf(md[1]), Number(md[2]));
        if (r > now) r.setFullYear(r.getFullYear() - 1);
        return toISO(r);
    }
    const wd = t.match(new RegExp(`\\b(?:last|on)\\s+(${DAYS.join('|')})\\b`));
    if (wd) {
        const target = DAYS.indexOf(wd[1]);
        let diff = (d.getDay() - target + 7) % 7 || 7;
        d.setDate(d.getDate() - diff);
        return toISO(d);
    }
    return toISO(now);
}

export function guessCategory(text, categories, type = 'expense') {
    const t = text.toLowerCase();
    const pool = categories.filter((c) => (type === 'income' ? c.kind === 'income' : c.kind !== 'income'));
    let best = null;
    let len = 0;
    for (const c of pool) {
        for (const kw of [c.name, ...(c.keywords || [])]) {
            const k = kw.toLowerCase();
            if (k && t.includes(k) && k.length > len) { best = c; len = k.length; }
        }
    }
    return best || pool.find((c) => c.name === (type === 'income' ? 'Other Income' : 'Other')) || null;
}

const INCOME_WORDS = /\b(earned|received|got paid|salary|paycheck|income|refund|sold|bonus|freelance)\b/i;

/** "spent 12.50 on lunch at Chipotle yesterday" -> transaction draft */
export function parseTransaction(text, categories) {
    const amount = parseAmount(text);
    if (!amount) return null;
    const type = INCOME_WORDS.test(text) ? 'income' : 'expense';
    const merchantMatch = text.match(/\b(?:at|from|@)\s+([A-Za-z0-9'&.\- ]{2,40}?)(?=\s+(?:for|on|yesterday|today|last|\d)|[,.!?]|$)/i);
    const merchant = merchantMatch ? titleCase(merchantMatch[1].trim()) : null;
    const forMatch = text.match(/\b(?:on|for)\s+(?:a\s+|an\s+|some\s+|the\s+)?([A-Za-z][A-Za-z'\- ]{1,40}?)(?=\s+(?:at|from|yesterday|today|last|on)\b|[,.!?]|$)/i);
    let description = forMatch ? forMatch[1].trim() : text
        .replace(AMOUNT, '')
        .replace(/\b(i|just|spent|paid|bought|buy|got|for|on|at|a|an|the|today|yesterday|log|add|expense|of)\b/gi, ' ')
        .replace(/\s+/g, ' ')
        .trim();
    if (merchant && description.toLowerCase() === merchant.toLowerCase()) description = merchant;
    const category = guessCategory(`${description} ${merchant || ''} ${text}`, categories, type);

    return {
        type,
        amount,
        description: capitalize(description || merchant || (type === 'income' ? 'Income' : 'Expense')),
        merchant,
        category_id: category?.id || null,
        category,
        occurred_on: parseDate(text),
        source: 'chat',
    };
}

/** Classify what the user wants from the assistant. */
export function detectIntent(text) {
    const t = text.toLowerCase().trim();
    const amount = parseAmount(t);
    const question = /\?|^(how|what|when|where|can|should|am|do|is|will|why|which|tell|show|give)\b/.test(t);

    if (amount && /\b(afford|can i (buy|get|spend)|should i (buy|get|spend)|ok to (buy|spend)|is it okay to)\b/.test(t)) {
        return { type: 'afford', amount, monthly: /\b(per month|monthly|a month|\/mo|subscription|every month)\b/.test(t) };
    }
    if (amount && !question && (/\b(spent|paid|bought|cost|log|add|earned|received|got paid)\b/.test(t) || t.split(/\s+/).length <= 6)) {
        return { type: 'log' };
    }
    if (/\b(safe to spend|left (until|till|before) (payday|pay day)|daily (allowance|limit|budget)|per day|run out|last (until|till)|how much (can|do) i (have|spend)|money left)\b/.test(t)) {
        return { type: 'safe' };
    }
    if (/\b(goal|save for|saving for|when will i (reach|have|hit)|how long (to|will it take)|reach my)\b/.test(t)) {
        return { type: 'goal', amount };
    }
    if (/\b(payday|paycheck|pay day|allocate|split my (salary|pay))\b/.test(t)) {
        return { type: 'payday' };
    }
    if (/\b(how much (did|have) i (spend|spent)|spent on|spending (on|this|last)|where (did|does) my money|biggest (expense|spend)|top (categor|spend)|compare|breakdown)\b/.test(t)) {
        return { type: 'spending', period: /last month/.test(t) ? 'last_month' : 'month' };
    }
    if (/\b(budget|over ?spend|on track|overspend)\b/.test(t)) {
        return { type: 'budget' };
    }
    if (/\b(debt|loan|credit card|pay off|interest)\b/.test(t)) {
        return { type: 'debt' };
    }
    if (/\b(tip|advice|save more|cut|reduce|improve|habit|suggest)\b/.test(t)) {
        return { type: 'tips' };
    }
    return { type: 'general' };
}

const titleCase = (s) => s.replace(/\w\S*/g, (w) => w[0].toUpperCase() + w.slice(1));
const capitalize = (s) => (s ? s[0].toUpperCase() + s.slice(1) : s);
