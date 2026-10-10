<script setup>
import Spinner from '../components/Spinner.vue';
import { ref, reactive, computed, watch } from 'vue';
import { api } from '../api';
import { useUi } from '../stores/ui';
import { money, niceDate, daysUntil } from '../format';
import { invalidateContext } from '../ai/assistant';
import Icon from '../components/Icon.vue';
import Sheet from '../components/Sheet.vue';
import PlanTabs from '../components/PlanTabs.vue';

const ui = useUi();
const bills = ref([]);
const categories = ref([]);
const open = ref(false);
const editingId = ref(null);
const errors = ref({});
const loaded = ref(false);
const busy = ref(false);
const blank = () => ({ name: '', amount: '', due_day: 1, category_id: null, is_debt: false, debt_balance: '', interest_rate: '' });
const form = reactive(blank());

async function load() {
    try {
        [bills.value, categories.value] = await Promise.all([api.get('/bills'), categories.value.length ? categories.value : api.get('/categories')]);
    } catch (e) { ui.error(e); } finally { loaded.value = true; }
}
watch(() => ui.refreshKey, load, { immediate: true });

const regular = computed(() => bills.value.filter((b) => !b.is_debt));
const debts = computed(() => [...bills.value.filter((b) => b.is_debt)].sort((a, b) => (b.interest_rate || 0) - (a.interest_rate || 0)));
const monthlyTotal = computed(() => bills.value.reduce((s, b) => s + b.amount, 0));
const debtTotal = computed(() => debts.value.reduce((s, b) => s + (b.debt_balance || 0), 0));

/** Months to pay off at the minimum payment (standard amortisation). */
function payoffMonths(b) {
    if (!b.debt_balance || !b.amount) return null;
    const r = (b.interest_rate || 0) / 100 / 12;
    if (r === 0) return Math.ceil(b.debt_balance / b.amount);
    if (b.amount <= b.debt_balance * r) return Infinity;
    return Math.ceil(-Math.log(1 - (r * b.debt_balance) / b.amount) / Math.log(1 + r));
}

function edit(b) {
    editingId.value = b?.id || null;
    Object.assign(form, blank(), b ? { ...b, debt_balance: b.debt_balance ?? '', interest_rate: b.interest_rate ?? '' } : {});
    errors.value = {};
    open.value = true;
}

async function save() {
    const payload = { ...form, amount: Number(form.amount), debt_balance: form.is_debt && form.debt_balance !== '' ? Number(form.debt_balance) : null, interest_rate: form.is_debt && form.interest_rate !== '' ? Number(form.interest_rate) : null };
    busy.value = true;
    try {
        editingId.value ? await api.put(`/bills/${editingId.value}`, payload) : await api.post('/bills', payload);
        open.value = false;
        invalidateContext();
        ui.changed();
        ui.toast(editingId.value ? `${form.name} updated` : `${form.name} added to your bills`);
    } catch (e) { errors.value = e.errors || {}; if (!e.errors) ui.error(e); } finally { busy.value = false; }
}

async function remove() {
    if (!confirm(`Delete ${form.name}?`)) return;
    busy.value = true;
    try {
        await api.del(`/bills/${editingId.value}`);
        open.value = false;
        invalidateContext();
        ui.changed();
        ui.toast(`${form.name} deleted`);
    } catch (e) { ui.error(e); } finally { busy.value = false; }
}

async function pay(b) {
    try {
        await api.post(`/bills/${b.id}/pay`);
        invalidateContext();
        ui.changed();
        ui.toast(`${b.name} paid. Logged as an expense.`);
    } catch (e) { ui.error(e); }
}

const dueLabel = (b) => {
    if (b.paid_this_cycle) return 'Paid ✓';
    const n = daysUntil(b.next_due);
    return n === 0 ? 'Due today' : n === 1 ? 'Due tomorrow' : `Due ${niceDate(b.next_due)}`;
};
</script>

<template>
    <div>
        <PlanTabs />

        <div class="mb-4 grid grid-cols-2 gap-3">
            <div class="card !p-3"><p class="text-xs text-slate-500">Monthly bills</p><p class="text-lg font-bold">{{ money(monthlyTotal) }}</p></div>
            <div class="card !p-3"><p class="text-xs text-slate-500">Total debt</p><p class="text-lg font-bold text-violet-600">{{ money(debtTotal) }}</p></div>
        </div>

        <section class="card mb-4">
            <div class="mb-2 flex items-center justify-between">
                <h3 class="font-semibold">Recurring bills</h3>
                <button class="text-sm font-medium text-indigo-600" @click="edit(null)">+ Add</button>
            </div>
            <div v-if="!loaded" class="space-y-2 py-2"><div v-for="n in 3" :key="n" class="skeleton h-10" /></div>
            <p v-else-if="!regular.length" class="py-3 text-sm text-slate-500">No bills yet.</p>
            <TransitionGroup tag="ul" name="list" class="relative divide-y divide-slate-100 dark:divide-slate-800">
                <li v-for="b in regular" :key="b.id" class="flex items-center gap-3 py-2.5">
                    <button class="min-w-0 flex-1 text-left" @click="edit(b)">
                        <p class="truncate text-sm font-medium">{{ b.category?.icon || '🧾' }} {{ b.name }}</p>
                        <p class="text-xs" :class="b.paid_this_cycle ? 'text-emerald-600' : daysUntil(b.next_due) <= 3 ? 'font-medium text-amber-600' : 'text-slate-500'">{{ dueLabel(b) }}</p>
                    </button>
                    <span class="text-sm font-semibold">{{ money(b.amount) }}</span>
                    <button v-if="!b.paid_this_cycle" class="rounded-lg bg-slate-100 px-2.5 py-1 text-xs font-medium hover:bg-slate-200 dark:bg-slate-800" @click="pay(b)">Mark paid</button>
                </li>
            </TransitionGroup>
        </section>

        <section class="card">
            <div class="mb-1 flex items-center justify-between">
                <h3 class="font-semibold">Debts</h3>
                <button class="text-sm font-medium text-indigo-600" @click="edit(null); form.is_debt = true">+ Add</button>
            </div>
            <p class="mb-2 text-xs text-slate-500">Ordered by interest rate (avalanche method). Extra money should go to the top one first.</p>
            <p v-if="!debts.length" class="py-3 text-sm text-slate-500">No debts tracked. 🎉</p>
            <TransitionGroup tag="ul" name="list" class="relative space-y-3">
                <li v-for="(b, i) in debts" :key="b.id" class="rounded-2xl bg-slate-50 p-3 dark:bg-slate-800/60">
                    <div class="flex items-center gap-2">
                        <span v-if="i === 0" class="rounded-full bg-violet-600 px-2 py-0.5 text-[10px] font-bold text-white">FOCUS</span>
                        <button class="min-w-0 flex-1 truncate text-left text-sm font-semibold" @click="edit(b)">{{ b.name }}</button>
                        <span class="text-sm font-bold">{{ money(b.debt_balance || 0) }}</span>
                    </div>
                    <p class="mt-1 text-xs text-slate-500">
                        {{ b.interest_rate ?? '?' }}% APR · {{ money(b.amount) }}/month · {{ dueLabel(b) }}
                        <template v-if="payoffMonths(b) === Infinity"> · ⚠️ payment doesn't cover interest</template>
                        <template v-else-if="payoffMonths(b)"> · paid off in ~{{ payoffMonths(b) }} months</template>
                    </p>
                    <button v-if="!b.paid_this_cycle" class="mt-2 rounded-lg bg-white px-2.5 py-1 text-xs font-medium ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-700" @click="pay(b)">Mark payment made</button>
                </li>
            </TransitionGroup>
        </section>

        <Sheet :open="open" :title="editingId ? 'Edit' : form.is_debt ? 'Add debt' : 'Add bill'" @close="open = false">
            <form class="space-y-3" @submit.prevent="save">
                <div>
                    <label class="label" for="bn">Name</label>
                    <input id="bn" v-model="form.name" class="input" required maxlength="100" />
                    <p v-if="errors.name" class="mt-1 text-xs text-rose-600">{{ errors.name[0] }}</p>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="label" for="ba">{{ form.is_debt ? 'Monthly payment' : 'Amount' }}</label>
                        <input id="ba" v-model="form.amount" type="number" inputmode="decimal" min="0" step="0.01" class="input" required />
                    </div>
                    <div>
                        <label class="label" for="bd">Due day</label>
                        <select id="bd" v-model.number="form.due_day" class="input"><option v-for="d in 31" :key="d" :value="d">{{ d }}</option></select>
                    </div>
                </div>
                <div>
                    <label class="label" for="bc">Category</label>
                    <select id="bc" v-model="form.category_id" class="input">
                        <option :value="null">Automatic</option>
                        <option v-for="c in categories.filter((c) => c.kind !== 'income')" :key="c.id" :value="c.id">{{ c.icon }} {{ c.name }}</option>
                    </select>
                </div>
                <label class="flex items-center gap-2 text-sm"><input v-model="form.is_debt" type="checkbox" class="h-4 w-4 accent-indigo-600" />Loan or credit card</label>
                <div v-if="form.is_debt" class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="label" for="bb">Balance owed</label>
                        <input id="bb" v-model="form.debt_balance" type="number" inputmode="decimal" min="0" class="input" />
                    </div>
                    <div>
                        <label class="label" for="bi">Interest rate (APR %)</label>
                        <input id="bi" v-model="form.interest_rate" type="number" inputmode="decimal" min="0" max="100" step="0.1" class="input" />
                    </div>
                </div>
                <button class="btn-primary w-full" :disabled="busy"><Spinner v-if="busy" />{{ busy ? 'Saving…' : 'Save' }}</button>
                <button v-if="editingId" type="button" class="btn-danger w-full" :disabled="busy" @click="remove"><Icon name="trash" size="18" />Delete</button>
            </form>
        </Sheet>
    </div>
</template>
