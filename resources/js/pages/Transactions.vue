<script setup>
import { ref, computed, watch } from 'vue';
import { api } from '../api';
import { useUi } from '../stores/ui';
import { money, niceDate, monthLabel, shiftMonth, thisMonth } from '../format';
import { invalidateContext } from '../ai/assistant';
import Icon from '../components/Icon.vue';
import Sheet from '../components/Sheet.vue';
import AddTransactionSheet from '../components/AddTransactionSheet.vue';
import { sync } from '../sync';

const ui = useUi();
const month = ref(thisMonth());
const type = ref('');
const q = ref('');
const items = ref([]);
const page = ref(1);
const lastPage = ref(1);
const loading = ref(false);
const selected = ref(null);
const editing = ref(null);

async function load(reset = true) {
    loading.value = true;
    if (reset) page.value = 1;
    try {
        const res = await api.get('/transactions', { month: month.value, type: type.value, q: q.value, page: page.value, per_page: 40 });
        items.value = reset ? res.data : [...items.value, ...res.data];
        lastPage.value = res.last_page;
    } catch (e) { ui.error(e); } finally { loading.value = false; }
}

let timer;
watch(q, () => { clearTimeout(timer); timer = setTimeout(load, 300); });
watch([month, type], () => load());
watch(() => ui.refreshKey, () => load(), { immediate: true });

const groups = computed(() => {
    const map = new Map();
    for (const t of items.value) {
        if (!map.has(t.occurred_on)) map.set(t.occurred_on, []);
        map.get(t.occurred_on).push(t);
    }
    return [...map.entries()].map(([date, list]) => ({ date, list, net: list.reduce((s, t) => s + (t.type === 'income' ? t.amount : -t.amount), 0) }));
});

const totals = computed(() => ({
    out: items.value.filter((t) => t.type === 'expense').reduce((s, t) => s + t.amount, 0),
    in: items.value.filter((t) => t.type === 'income').reduce((s, t) => s + t.amount, 0),
}));

async function remove(t) {
    if (!confirm(`Delete "${t.description || t.merchant || 'transaction'}"?`)) return;
    try {
        await api.del(`/transactions/${t.id}`);
        selected.value = null;
        invalidateContext();
        ui.changed();
        ui.toast('Transaction deleted');
    } catch (e) { ui.error(e); }
}

async function viewReceipt(t) {
    const res = await api.raw(`/transactions/${t.id}/receipt`);
    if (!res.ok) return ui.toast('Receipt not found', 'error');
    window.open(URL.createObjectURL(await res.blob()), '_blank');
}

const sourceLabel = { manual: 'Added manually', chat: 'Logged via assistant', receipt: 'Scanned receipt', bill: 'Bill payment' };
</script>

<template>
    <div class="space-y-4">
        <section v-if="sync.pending.length" class="card !p-3">
            <p class="mb-1 flex items-center gap-1.5 text-xs font-semibold text-sky-700 dark:text-sky-300">☁️ Saved on this device · waiting to sync</p>
            <ul class="divide-y divide-slate-100 text-sm dark:divide-slate-800">
                <li v-for="p in sync.pending" :key="p.id" class="py-1.5">{{ p.label }}</li>
            </ul>
        </section>
        <div class="flex items-center justify-between">
            <button class="rounded-full p-2 hover:bg-slate-200 dark:hover:bg-slate-800" aria-label="Previous month" @click="month = shiftMonth(month, -1)"><Icon name="chevronLeft" /></button>
            <h2 class="font-semibold">{{ monthLabel(month) }}</h2>
            <button class="rounded-full p-2 hover:bg-slate-200 disabled:opacity-30 dark:hover:bg-slate-800" :disabled="month >= thisMonth()" aria-label="Next month" @click="month = shiftMonth(month, 1)"><Icon name="chevronRight" /></button>
        </div>

        <div class="grid grid-cols-2 gap-3">
            <div class="card !p-3"><p class="text-xs text-slate-500">Money out</p><p class="text-lg font-bold">{{ money(totals.out) }}</p></div>
            <div class="card !p-3"><p class="text-xs text-slate-500">Money in</p><p class="text-lg font-bold text-emerald-600">{{ money(totals.in) }}</p></div>
        </div>

        <div class="flex gap-2">
            <div class="relative flex-1">
                <Icon name="search" size="18" class="absolute top-3 left-3 text-slate-400" />
                <input v-model="q" class="input !pl-10" placeholder="Search" aria-label="Search transactions" />
            </div>
            <select v-model="type" class="input !w-32" aria-label="Type">
                <option value="">All</option><option value="expense">Expenses</option><option value="income">Income</option>
            </select>
        </div>

        <div v-if="!loading && !items.length" class="card py-10 text-center">
            <p class="text-4xl">🧾</p>
            <p class="mt-2 font-medium">No transactions{{ q ? ' match your search' : ' this month' }}</p>
            <button class="btn-primary mt-4" @click="ui.openAdd()"><Icon name="plus" size="18" />Add one</button>
        </div>

        <section v-for="g in groups" :key="g.date">
            <div class="mb-1.5 flex justify-between px-1 text-xs font-medium text-slate-500">
                <span>{{ niceDate(g.date, { weekday: 'long', month: 'short', day: 'numeric' }) }}</span>
                <span>{{ g.net >= 0 ? '+' : '−' }}{{ money(Math.abs(g.net)) }}</span>
            </div>
            <TransitionGroup tag="ul" name="list" class="card relative divide-y divide-slate-100 !p-0 dark:divide-slate-800">
                <li v-for="t in g.list" :key="t.id">
                    <button class="flex w-full items-center gap-3 px-4 py-3 text-left" @click="selected = t">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl text-lg" :style="{ background: (t.category?.color || '#64748b') + '22' }">{{ t.category?.icon || '💸' }}</span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium">{{ t.description || t.merchant || t.category?.name }}</p>
                            <p class="truncate text-xs text-slate-500">{{ t.category?.name }}<template v-if="t.merchant && t.merchant !== t.description"> · {{ t.merchant }}</template><template v-if="t.source === 'receipt'"> · 🧾</template><template v-if="t.source === 'chat'"> · ✨</template></p>
                        </div>
                        <p class="text-sm font-semibold" :class="t.type === 'income' ? 'text-emerald-600' : ''">{{ t.type === 'income' ? '+' : '−' }}{{ money(t.amount) }}</p>
                    </button>
                </li>
            </TransitionGroup>
        </section>

        <button v-if="page < lastPage" class="btn-ghost w-full" :disabled="loading" @click="page++; load(false)">Load more</button>

        <Sheet :open="!!selected" title="Transaction" @close="selected = null">
            <template v-if="selected">
                <div class="py-2 text-center">
                    <span class="text-4xl">{{ selected.category?.icon }}</span>
                    <p class="mt-2 text-3xl font-bold" :class="selected.type === 'income' && 'text-emerald-600'">{{ selected.type === 'income' ? '+' : '−' }}{{ money(selected.amount) }}</p>
                    <p class="text-slate-500">{{ selected.description }}</p>
                </div>
                <dl class="mt-4 divide-y divide-slate-100 text-sm dark:divide-slate-800">
                    <div class="flex justify-between py-2"><dt class="text-slate-500">Category</dt><dd>{{ selected.category?.name || '—' }}</dd></div>
                    <div class="flex justify-between py-2"><dt class="text-slate-500">Merchant</dt><dd>{{ selected.merchant || '—' }}</dd></div>
                    <div class="flex justify-between py-2"><dt class="text-slate-500">Date</dt><dd>{{ niceDate(selected.occurred_on, { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' }) }}</dd></div>
                    <div class="flex justify-between py-2"><dt class="text-slate-500">Source</dt><dd>{{ sourceLabel[selected.source] }}</dd></div>
                </dl>
                <div class="mt-5 grid grid-cols-2 gap-2">
                    <button v-if="selected.receipt_path" class="btn-ghost col-span-2" @click="viewReceipt(selected)"><Icon name="receipt" size="18" />View receipt</button>
                    <button class="btn-ghost" @click="editing = selected; selected = null"><Icon name="edit" size="18" />Edit</button>
                    <button class="btn-danger" @click="remove(selected)"><Icon name="trash" size="18" />Delete</button>
                </div>
            </template>
        </Sheet>

        <AddTransactionSheet :open="!!editing" :editing="editing" @close="editing = null" />
    </div>
</template>
