<script setup>
import { ref, reactive, computed, watch } from 'vue';
import { api } from '../api';
import { useUi } from '../stores/ui';
import { money, niceDate } from '../format';
import { invalidateContext } from '../ai/assistant';
import Icon from '../components/Icon.vue';
import PlanTabs from '../components/PlanTabs.vue';

const ui = useUi();
const plan = ref(null);
const history = ref([]);
const form = reactive({ income: '', payday: '', next_payday: '' });
const busy = ref(false);

const GROUPS = {
    bills: { label: 'Bills', color: '#6366f1', icon: '🧾' },
    debt: { label: 'Debt payments', color: '#a855f7', icon: '💳' },
    essentials: { label: 'Essentials', color: '#0ea5e9', icon: '🛒' },
    savings: { label: 'Savings', color: '#10b981', icon: '🐷' },
    buffer: { label: 'Safety buffer', color: '#f59e0b', icon: '🛟' },
    daily: { label: 'Spending money', color: '#ec4899', icon: '☕' },
};

async function load(params = {}) {
    try {
        plan.value = await api.get('/payday', params);
        Object.assign(form, { income: plan.value.income, payday: plan.value.payday, next_payday: plan.value.next_payday });
    } catch (e) { ui.error(e); }
}
async function loadHistory() {
    try { history.value = await api.get('/payday/history'); } catch {}
}
watch(() => ui.refreshKey, () => { load(); loadHistory(); }, { immediate: true });

function recalc() {
    load({ income: form.income, payday: form.payday, next_payday: form.next_payday });
}

const grouped = computed(() => {
    if (!plan.value) return [];
    return Object.entries(GROUPS).map(([key, meta]) => ({ key, ...meta, items: plan.value.allocations.filter((a) => a.group === key), total: plan.value.totals[key] || 0 })).filter((g) => g.items.length);
});

async function savePlan() {
    busy.value = true;
    try {
        await api.post('/payday', { income: form.income, payday: form.payday, next_payday: form.next_payday });
        ui.toast('Plan saved');
        loadHistory();
    } catch (e) { ui.error(e); } finally { busy.value = false; }
}

async function markPaid(item) {
    try {
        await api.post(`/bills/${item.bill_id}/pay`, item.extra ? { amount: item.amount } : {});
        invalidateContext();
        ui.changed();
        ui.toast(`${item.name} marked as paid`);
    } catch (e) { ui.error(e); }
}
</script>

<template>
    <div>
        <PlanTabs />

        <section class="card mb-4">
            <p class="mb-3 text-sm text-slate-500">Split your paycheck so it lasts until the next one. Bills come first, then essentials and savings, and what's left becomes your daily spending money.</p>
            <form class="grid grid-cols-2 gap-3 md:grid-cols-4" @submit.prevent="recalc">
                <div class="col-span-2 md:col-span-1">
                    <label class="label" for="inc">Paycheck amount</label>
                    <input id="inc" v-model="form.income" type="number" inputmode="decimal" min="0" class="input" />
                </div>
                <div>
                    <label class="label" for="pd">Payday</label>
                    <input id="pd" v-model="form.payday" type="date" class="input" />
                </div>
                <div>
                    <label class="label" for="npd">Next payday</label>
                    <input id="npd" v-model="form.next_payday" type="date" :min="form.payday" class="input" />
                </div>
                <button class="btn-ghost col-span-2 self-end md:col-span-1">Recalculate</button>
            </form>
        </section>

        <div v-if="!plan" class="h-64 animate-pulse rounded-2xl bg-slate-200 dark:bg-slate-800" />

        <div v-else class="space-y-4">
            <section class="rounded-3xl bg-gradient-to-br from-pink-500 to-rose-600 p-5 text-white">
                <p class="text-sm text-pink-100">Your daily spending money</p>
                <p class="text-4xl font-bold">{{ money(plan.daily_allowance) }}<span class="text-lg font-medium text-pink-100"> / day</span></p>
                <p class="mt-1 text-sm text-pink-100">{{ money(plan.weekly_allowance) }} a week for {{ plan.days }} days · {{ niceDate(plan.payday) }} → {{ niceDate(plan.next_payday) }}</p>

                <div class="mt-4 flex h-3 overflow-hidden rounded-full bg-white/20">
                    <div v-for="g in grouped" :key="g.key" :style="{ width: (g.total / plan.income * 100) + '%', background: g.color }" :title="g.label" />
                </div>
                <div class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs text-pink-50">
                    <span v-for="g in grouped" :key="g.key" class="flex items-center gap-1"><span class="h-2 w-2 rounded-full" :style="{ background: g.color }" />{{ g.label }}</span>
                </div>
            </section>

            <div v-for="w in plan.warnings" :key="w" class="rounded-2xl bg-amber-50 p-3 text-sm text-amber-900 ring-1 ring-amber-200 dark:bg-amber-950/40 dark:text-amber-200 dark:ring-amber-900">⚠️ {{ w }}</div>

            <section v-for="(g, i) in grouped" :key="g.key" class="card">
                <div class="mb-2 flex items-center justify-between">
                    <h3 class="flex items-center gap-2 font-semibold"><span class="flex h-6 w-6 items-center justify-center rounded-full text-xs font-bold text-white" :style="{ background: g.color }">{{ i + 1 }}</span>{{ g.icon }} {{ g.label }}</h3>
                    <p class="font-semibold">{{ money(g.total) }}</p>
                </div>
                <ul class="divide-y divide-slate-100 text-sm dark:divide-slate-800">
                    <li v-for="a in g.items" :key="a.name + (a.due || '')" class="flex items-center gap-2 py-2">
                        <div class="min-w-0 flex-1">
                            <p class="truncate">{{ a.name }}</p>
                            <p v-if="a.due" class="text-xs text-slate-500">Due {{ niceDate(a.due) }}</p>
                            <p v-if="g.key === 'daily'" class="text-xs text-slate-500">Groceries top-ups, coffee, fun. Anything not planned above.</p>
                        </div>
                        <span class="font-medium">{{ money(a.amount) }}</span>
                        <button v-if="a.bill_id" class="rounded-lg bg-slate-100 px-2 py-1 text-xs font-medium hover:bg-slate-200 dark:bg-slate-800" @click="markPaid(a)"><Icon name="check" size="14" class="inline" /> Paid</button>
                    </li>
                </ul>
            </section>

            <button class="btn-primary w-full" :disabled="busy" @click="savePlan"><Icon name="check" size="18" />Save this plan</button>

            <section v-if="history.length" class="card">
                <h3 class="mb-2 font-semibold">Past plans</h3>
                <ul class="divide-y divide-slate-100 text-sm dark:divide-slate-800">
                    <li v-for="h in history" :key="h.id" class="flex justify-between py-2">
                        <span>{{ niceDate(h.payday, { month: 'short', day: 'numeric', year: 'numeric' }) }} · {{ money(h.income, { compact: true }) }}</span>
                        <span class="text-slate-500">{{ money(h.daily_allowance) }}/day</span>
                    </li>
                </ul>
            </section>
        </div>
    </div>
</template>
