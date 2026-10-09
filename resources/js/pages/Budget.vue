<script setup>
import { ref, computed, watch } from 'vue';
import { api } from '../api';
import { useUi } from '../stores/ui';
import { useAuth } from '../stores/auth';
import { money, monthLabel, shiftMonth, thisMonth } from '../format';
import { invalidateContext } from '../ai/assistant';
import Icon from '../components/Icon.vue';
import Progress from '../components/Progress.vue';
import PlanTabs from '../components/PlanTabs.vue';
import Sheet from '../components/Sheet.vue';

const ui = useUi();
const auth = useAuth();
const month = ref(thisMonth());
const data = ref(null);
const categories = ref([]);
const editOpen = ref(false);
const draft = ref([]);
const genOpen = ref(false);
const preview = ref(null);
const busy = ref(false);

async function load() {
    try {
        [data.value, categories.value] = await Promise.all([api.get('/budget', { month: month.value }), categories.value.length ? categories.value : api.get('/categories')]);
    } catch (e) { ui.error(e); }
}
watch([month, () => ui.refreshKey], load, { immediate: true });

const groups = computed(() => {
    const cats = data.value?.categories || [];
    return [
        { kind: 'need', label: 'Needs', hint: 'Bills and essentials' },
        { kind: 'want', label: 'Wants', hint: 'Lifestyle and fun' },
        { kind: 'savings', label: 'Savings', hint: 'Paying your future self' },
    ].map((g) => ({ ...g, items: cats.filter((c) => c.kind === g.kind), budget: cats.filter((c) => c.kind === g.kind).reduce((s, c) => s + (c.budget || 0), 0), spent: cats.filter((c) => c.kind === g.kind).reduce((s, c) => s + c.spent, 0) }))
        .filter((g) => g.items.length);
});

const leftToBudget = computed(() => (data.value ? Number(auth.user?.monthly_income || 0) - data.value.total_budget : 0));

function openEdit() {
    const byId = Object.fromEntries((data.value?.categories || []).map((c) => [c.id, c.budget]));
    draft.value = categories.value.filter((c) => c.kind !== 'income').map((c) => ({ category_id: c.id, name: c.name, icon: c.icon, kind: c.kind, amount: byId[c.id] ?? '' }));
    editOpen.value = true;
}

async function saveEdit() {
    busy.value = true;
    try {
        data.value = await api.put('/budget', { month: month.value, lines: draft.value.filter((l) => l.amount !== '' && l.amount !== null).map((l) => ({ category_id: l.category_id, amount: Number(l.amount) })) });
        editOpen.value = false;
        invalidateContext();
        ui.changed();
        ui.toast('Budget saved');
    } catch (e) { ui.error(e); } finally { busy.value = false; }
}

async function openGenerate() {
    genOpen.value = true;
    preview.value = null;
    try { preview.value = await api.post('/budget/generate', null, { month: month.value, save: 0 }); } catch (e) { ui.error(e); genOpen.value = false; }
}

async function applyGenerated() {
    busy.value = true;
    try {
        await api.post('/budget/generate', null, { month: month.value, save: 1 });
        genOpen.value = false;
        invalidateContext();
        ui.changed();
        ui.toast('Smart budget applied');
    } catch (e) { ui.error(e); } finally { busy.value = false; }
}
const draftTotal = computed(() => draft.value.reduce((s, l) => s + Number(l.amount || 0), 0));
</script>

<template>
    <div>
        <PlanTabs />

        <div class="mb-4 flex items-center justify-between">
            <button class="rounded-full p-2 hover:bg-slate-200 dark:hover:bg-slate-800" aria-label="Previous month" @click="month = shiftMonth(month, -1)"><Icon name="chevronLeft" /></button>
            <h2 class="font-semibold">{{ monthLabel(month) }}</h2>
            <button class="rounded-full p-2 hover:bg-slate-200 dark:hover:bg-slate-800" aria-label="Next month" @click="month = shiftMonth(month, 1)"><Icon name="chevronRight" /></button>
        </div>

        <div v-if="!data" class="h-64 animate-pulse rounded-2xl bg-slate-200 dark:bg-slate-800" />

        <div v-else class="space-y-4">
            <section class="card">
                <div class="flex items-end justify-between">
                    <div>
                        <p class="text-xs text-slate-500">Spent</p>
                        <p class="text-2xl font-bold">{{ money(data.total_spent) }}</p>
                    </div>
                    <div class="text-right">
                        <p class="text-xs text-slate-500">Budget</p>
                        <p class="text-lg font-semibold">{{ money(data.total_budget) }}</p>
                    </div>
                </div>
                <Progress class="mt-3" :value="data.total_spent" :max="data.total_budget" height="h-3" />
                <p class="mt-2 text-xs text-slate-500">
                    {{ data.total_budget >= data.total_spent ? `${money(data.total_budget - data.total_spent)} left` : `${money(data.total_spent - data.total_budget)} over` }}
                    <template v-if="month === thisMonth()"> · projected {{ money(data.projected_spend) }}</template>
                    <template v-if="leftToBudget > 1"> · {{ money(leftToBudget) }} of income not yet budgeted</template>
                </p>
                <div class="mt-4 grid grid-cols-2 gap-2">
                    <button class="btn-primary" @click="openGenerate"><Icon name="sparkles" size="18" />Smart budget</button>
                    <button class="btn-ghost" @click="openEdit"><Icon name="edit" size="18" />Edit amounts</button>
                </div>
            </section>

            <p v-if="!data.total_budget" class="card text-center text-sm text-slate-500">No budget for this month yet. Tap <b>Smart budget</b> to build one from your income, bills, goals and spending history.</p>

            <section v-for="g in groups" :key="g.kind" class="card">
                <div class="mb-3 flex items-baseline justify-between">
                    <div><h3 class="font-semibold">{{ g.label }}</h3><p class="text-xs text-slate-500">{{ g.hint }}</p></div>
                    <p class="text-sm"><b>{{ money(g.spent, { compact: true }) }}</b> <span class="text-slate-500">/ {{ money(g.budget, { compact: true }) }}</span></p>
                </div>
                <ul class="space-y-3.5">
                    <li v-for="c in g.items" :key="c.id">
                        <div class="mb-1 flex items-center justify-between text-sm">
                            <span class="font-medium">{{ c.icon }} {{ c.name }}</span>
                            <span :class="c.budget && c.spent > c.budget ? 'font-semibold text-rose-600' : 'text-slate-500'">
                                {{ money(c.spent, { compact: true }) }}<template v-if="c.budget"> / {{ money(c.budget, { compact: true }) }}</template><template v-else> · no budget</template>
                            </span>
                        </div>
                        <Progress v-if="c.budget" :value="c.spent" :max="c.budget" :color="g.kind === 'savings' ? '#10b981' : undefined" />
                    </li>
                </ul>
            </section>
        </div>

        <Sheet :open="editOpen" title="Edit budget" @close="editOpen = false">
            <p class="mb-3 text-sm text-slate-500">Total: <b>{{ money(draftTotal) }}</b></p>
            <div class="space-y-2">
                <div v-for="l in draft" :key="l.category_id" class="flex items-center gap-3">
                    <span class="w-36 shrink-0 truncate text-sm">{{ l.icon }} {{ l.name }}</span>
                    <input v-model="l.amount" type="number" inputmode="decimal" min="0" class="input !py-2" placeholder="0" :aria-label="`${l.name} budget`" />
                </div>
            </div>
            <button class="btn-primary mt-5 w-full" :disabled="busy" @click="saveEdit">Save budget</button>
        </Sheet>

        <Sheet :open="genOpen" title="Smart budget" @close="genOpen = false">
            <div v-if="!preview" class="py-10 text-center text-slate-500">Building your budget…</div>
            <template v-else>
                <p class="text-sm text-slate-500">Built from your {{ money(preview.income) }} monthly income, fixed bills, savings goals and the last 3 months of spending.</p>
                <div class="my-4 grid grid-cols-3 gap-2 text-center">
                    <div class="rounded-2xl bg-slate-50 p-3 dark:bg-slate-800"><p class="text-xs text-slate-500">Needs</p><p class="font-bold">{{ money(preview.split.need, { compact: true }) }}</p></div>
                    <div class="rounded-2xl bg-slate-50 p-3 dark:bg-slate-800"><p class="text-xs text-slate-500">Wants</p><p class="font-bold">{{ money(preview.split.want, { compact: true }) }}</p></div>
                    <div class="rounded-2xl bg-slate-50 p-3 dark:bg-slate-800"><p class="text-xs text-slate-500">Savings</p><p class="font-bold text-emerald-600">{{ money(preview.split.savings, { compact: true }) }}</p></div>
                </div>
                <div v-for="n in preview.notes" :key="n" class="mb-2 rounded-xl bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950/40 dark:text-amber-200">💡 {{ n }}</div>
                <ul class="mt-3 divide-y divide-slate-100 text-sm dark:divide-slate-800">
                    <li v-for="l in preview.lines" :key="l.category_id" class="flex justify-between py-2"><span>{{ l.icon }} {{ l.name }}</span><b>{{ money(l.amount) }}</b></li>
                </ul>
                <button class="btn-primary mt-5 w-full" :disabled="busy" @click="applyGenerated">Use this budget</button>
            </template>
        </Sheet>
    </div>
</template>
