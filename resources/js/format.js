import { useAuth } from './stores/auth';

export function money(amount, opts = {}) {
    const currency = opts.currency || useAuth().user?.currency || 'USD';
    const n = Number(amount || 0);
    try {
        return new Intl.NumberFormat(undefined, {
            style: 'currency',
            currency,
            maximumFractionDigits: opts.compact ? 0 : 2,
            minimumFractionDigits: opts.compact ? 0 : 2,
        }).format(n);
    } catch {
        return `${currency} ${n.toFixed(2)}`;
    }
}

export const today = () => toISO(new Date());

export function toISO(d) {
    const z = new Date(d.getTime() - d.getTimezoneOffset() * 60000);
    return z.toISOString().slice(0, 10);
}

export function niceDate(iso, opts = { month: 'short', day: 'numeric' }) {
    if (!iso) return '';
    const d = new Date(`${String(iso).slice(0, 10)}T00:00:00`);
    const t = today();
    if (iso === t) return 'Today';
    const y = new Date(); y.setDate(y.getDate() - 1);
    if (iso === toISO(y)) return 'Yesterday';
    return d.toLocaleDateString(undefined, opts);
}

export function monthLabel(ym) {
    const [y, m] = ym.split('-').map(Number);
    return new Date(y, m - 1, 1).toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
}

export function shiftMonth(ym, delta) {
    const [y, m] = ym.split('-').map(Number);
    const d = new Date(y, m - 1 + delta, 1);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
}

export const thisMonth = () => today().slice(0, 7);

export function daysUntil(iso) {
    const a = new Date(`${today()}T00:00:00`);
    const b = new Date(`${iso}T00:00:00`);
    return Math.round((b - a) / 86400000);
}

export function pref(key, fallback) {
    try {
        const v = localStorage.getItem(`abp_${key}`);
        return v === null ? fallback : JSON.parse(v);
    } catch { return fallback; }
}

export function setPref(key, value) {
    try { localStorage.setItem(`abp_${key}`, JSON.stringify(value)); } catch {}
}

export function applyTheme(mode = pref('theme', 'system')) {
    const dark = mode === 'dark' || (mode === 'system' && matchMedia('(prefers-color-scheme: dark)').matches);
    document.documentElement.classList.toggle('dark', dark);
    document.querySelector('meta[name=theme-color]')?.setAttribute('content', dark ? '#020617' : '#4f46e5');
}
