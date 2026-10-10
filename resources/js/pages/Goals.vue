<script setup>
import { ref, reactive, watch } from 'vue';
import { api } from '../api';
import { useUi } from '../stores/ui';
import { money, niceDate, today } from '../format';
import { invalidateContext } from '../ai/assistant';
import Icon from '../components/Icon.vue';
import Sheet from '../components/Sheet.vue';
import GoalTabs from '../components/GoalTabs.vue';

const ui = useUi();
const goals = ref(null);
const open = ref(false);
const editingId = ref(null);
const errors = ref({});
const blank = () => ({ name: '', icon: '🎯', target_amount: '', saved_amount: '', target_date: '', monthly_contribution: '', priority: 2 });
const form = reactive(blank());
const moveGoal = ref(null);
const moveAmount = ref('');
const whatIf = reactive({});

async function load() {
    try { goals.value = await api.get('/goals'); } catch (e) { ui.error(e); }
}
watch(() => ui.refreshKey, load, { immediate: true });

const ICONS = ['🎯', '🛟', '✈️', '🏠', '🚗', '💻', '🎓', '💍', '👶', '🎁', '📱', '🏖️'];

function edit(g) {
    editingId.value = g?.id || null;
    Object.assign(form, blank(), g ? { ...g, target_date: g.target_date || '', monthly_contribution: g.monthly_contribution ?? '' } : {});
    errors.value = {};
    open.value = true;
}

async function save() {
    const payload = {
        ...form,
        target_amount: Number(form.target_amount),
        saved_amount: Number(form.saved_amount || 0),
        monthly_contribution: form.monthly_contribution === '' ? null : Number(form.monthly_contribution),
        target_date: form.target_date || null,
    };
    try {
        editingId.value ? await api.put(`/goals/${editingId.value}`, payload) : await api.post('/goals', payload);
        open.value = false;
        invalidateContext();
        load();
        ui.toast('Goal saved');
    } catch (e) { errors.value = e.errors || {}; if (!e.errors) ui.error(e); }
}

async function remove() {
    if (!confirm(`Delete ${form.name}? Saved money stays in your balance records.`)) return;
    await api.del(`/goals/${editingId.value}`);
    open.value = false;
    invalidateContext();
    load();
}

async function move(sign) {
    const amount = Number(moveAmount.value) * sign;
    if (!amount) return;
    try {
        await api.post(`/goals/${moveGoal.value.id}/contribute`, { amount });
        ui.toast(sign > 0 ? `Added ${money(Math.abs(amount))} to ${moveGoal.value.name} 🎉` : 'Withdrawn');
        moveGoal.value = null;
        moveAmount.value = '';
        invalidateContext();
        ui.changed();
    } catch (e) { ui.error(e); }
}

/** "What if I saved X a month?" -> finish date. */
function whatIfDate(g) {
    const m = Number(whatIf[g.id]);
    if (!m) return null;
    const d = new Date();
    d.setDate(d.getDate() + Math.ceil((g.projection.remaining / m) * 30.44));
    return d.toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
}

const ring = (pct) => `conic-gradient(#10b981 ${pct * 3.6}deg, rgb(148 163 184 / .2) 0)`;
</script>

<template>
    <div class="space-y-4">
        <GoalTabs />
        <div class="flex items-center justify-between">
            <p class="text-sm text-slate-500">Save with a plan. We'll tell you how much each payday and when you'll get there.</p>
            <button class="btn-primary shrink-0" @click="edit(null)"><Icon name="plus" size="18" />New goal</button>
        </div>

        <div v-if="goals && !goals.length" class="card py-10 text-center">
            <p class="text-4xl">🎯</p>
            <p class="mt-2 font-medium">No goals yet</p>
            <p class="text-sm text-slate-500">Start with an emergency fund of 1–3 months of expenses.</p>
        </div>

        <TransitionGroup tag="div" name="list" class="relative grid gap-4 md:grid-cols-2">
            <section v-for="g in goals" :key="g.id" class="card">
                <div class="flex items-start gap-4">
                    <div class="relative flex h-16 w-16 shrink-0 items-center justify-center rounded-full" :style="{ background: ring(g.projection.percent) }">
                        <div class="flex h-12 w-12 items-center justify-center rounded-full bg-white text-2xl dark:bg-slate-900">{{ g.icon }}</div>
                    </div>
                    <div class="min-w-0 flex-1">
                        <button class="block truncate text-left font-semibold" @click="edit(g)">{{ g.name }}</button>
                        <p class="text-sm"><b>{{ money(g.saved_amount) }}</b> <span class="text-slate-500">of {{ money(g.target_amount) }} · {{ g.projection.percent }}%</span></p>
                        <p v-if="g.projection.remaining <= 0" class="mt-1 text-sm font-medium text-emerald-600">Goal reached! 🎉</p>
                        <template v-else>
                            <p class="mt-1 text-xs text-slate-500">
                                <template v-if="g.projection.eta">At {{ money(g.projection.monthly_pace) }}/month, done by <b>{{ niceDate(g.projection.eta, { month: 'short', year: 'numeric' }) }}</b>.</template>
                                <template v-else-if="!g.projection.required_monthly">Set a monthly amount to get a finish date.</template>
                            </p>
                            <p v-if="g.projection.required_monthly" class="mt-0.5 text-xs" :class="g.projection.on_track ? 'text-emerald-600' : 'text-amber-600'">
                                Need {{ money(g.projection.required_monthly) }}/month to hit {{ niceDate(g.target_date, { month: 'short', year: 'numeric' }) }}{{ g.projection.on_track ? ' ✓ on track' : ' · behind' }}
                            </p>
                        </template>
                    </div>
                </div>
                <div v-if="g.projection.remaining > 0" class="mt-3 flex items-center gap-2 rounded-xl bg-slate-50 p-2 text-xs dark:bg-slate-800/60">
                    <span class="shrink-0 text-slate-500">What if I saved</span>
                    <input v-model="whatIf[g.id]" type="number" inputmode="decimal" min="0" class="w-20 rounded-lg bg-white px-2 py-1 ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-700" placeholder="amount" :aria-label="`What-if monthly amount for ${g.name}`" />
                    <span class="text-slate-500">/mo?</span>
                    <b v-if="whatIfDate(g)" class="ml-auto">{{ whatIfDate(g) }}</b>
                </div>
                <div class="mt-3 grid grid-cols-2 gap-2">
                    <button class="btn-primary !py-2" @click="moveGoal = g"><Icon name="plus" size="16" />Add money</button>
                    <button class="btn-ghost !py-2" @click="edit(g)"><Icon name="edit" size="16" />Edit</button>
                </div>
            </section>
        </TransitionGroup>

        <Sheet :open="open" :title="editingId ? 'Edit goal' : 'New goal'" @close="open = false">
            <form class="space-y-3" @submit.prevent="save">
                <div class="flex flex-wrap gap-1.5">
                    <button v-for="i in ICONS" :key="i" type="button" class="h-10 w-10 rounded-xl text-xl" :class="form.icon === i ? 'bg-indigo-100 ring-2 ring-indigo-500 dark:bg-indigo-950' : 'bg-slate-100 dark:bg-slate-800'" @click="form.icon = i">{{ i }}</button>
                </div>
                <div>
                    <label class="label" for="gn">Goal name</label>
                    <input id="gn" v-model="form.name" class="input" required maxlength="100" placeholder="e.g. Japan trip" />
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="label" for="gt">Target amount</label>
                        <input id="gt" v-model="form.target_amount" type="number" inputmode="decimal" min="1" class="input" required />
                    </div>
                    <div>
                        <label class="label" for="gs">Already saved</label>
                        <input id="gs" v-model="form.saved_amount" type="number" inputmode="decimal" min="0" class="input" placeholder="0" />
                    </div>
                    <div>
                        <label class="label" for="gd">Target date (optional)</label>
                        <input id="gd" v-model="form.target_date" type="date" :min="today()" class="input" />
                        <p v-if="errors.target_date" class="mt-1 text-xs text-rose-600">{{ errors.target_date[0] }}</p>
                    </div>
                    <div>
                        <label class="label" for="gm">Save per month</label>
                        <input id="gm" v-model="form.monthly_contribution" type="number" inputmode="decimal" min="0" class="input" placeholder="Optional" />
                    </div>
                </div>
                <div>
                    <span class="label">Priority</span>
                    <div class="grid grid-cols-3 gap-2">
                        <button v-for="p in [[1, 'High'], [2, 'Medium'], [3, 'Low']]" :key="p[0]" type="button" class="chip ring-1" :class="form.priority === p[0] ? 'bg-indigo-600 text-white ring-indigo-600' : 'ring-slate-200 dark:ring-slate-700'" @click="form.priority = p[0]">{{ p[1] }}</button>
                    </div>
                </div>
                <button class="btn-primary w-full">Save goal</button>
                <button v-if="editingId" type="button" class="btn-danger w-full" @click="remove"><Icon name="trash" size="18" />Delete goal</button>
            </form>
        </Sheet>

        <Sheet :open="!!moveGoal" :title="moveGoal ? `${moveGoal.icon} ${moveGoal.name}` : ''" @close="moveGoal = null">
            <template v-if="moveGoal">
                <p class="mb-3 text-sm text-slate-500">Moving money into a goal takes it out of your spending balance.</p>
                <input v-model="moveAmount" type="number" inputmode="decimal" min="0" class="input !py-3 text-2xl font-semibold" placeholder="0.00" aria-label="Amount" />
                <div class="mt-2 flex gap-2">
                    <button v-for="q in [10, 25, 50, 100]" :key="q" class="chip flex-1 bg-slate-100 dark:bg-slate-800" @click="moveAmount = q">{{ money(q, { compact: true }) }}</button>
                </div>
                <div class="mt-4 grid grid-cols-2 gap-2">
                    <button class="btn-ghost" :disabled="!moveAmount" @click="move(-1)">Withdraw</button>
                    <button class="btn-primary" :disabled="!moveAmount" @click="move(1)">Add to goal</button>
                </div>
            </template>
        </Sheet>
    </div>
</template>
