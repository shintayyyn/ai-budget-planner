<script setup>
import { ref, reactive, computed, watch } from 'vue';
import { api } from '../api';
import { useUi } from '../stores/ui';
import { money, niceDate, today } from '../format';
import { simulate, paycheckAmount, PRESETS } from '../sim/domino';
import PlanTabs from '../components/PlanTabs.vue';
import Mascot from '../components/Mascot.vue';
import Chart from '../components/Chart.vue';

const ui = useUi();
const data = ref(null);
const error = ref('');
const active = ref('late');
const shocks = reactive({ salaryDelayDays: 5, surpriseAmount: 0, surpriseDay: 3, billIncreasePct: 0, incomeCutPct: 0 });
const dailySpend = ref(0);

async function load() {
    try {
        const [dash, bills] = await Promise.all([api.get('/dashboard'), api.get('/bills')]);
        data.value = { dash, bills };
        const s = dash.safe_to_spend;
        dailySpend.value = Math.round((s.daily_spend_rate || Math.max(0, s.daily_allowance)) * 100) / 100;
        error.value = '';
    } catch (e) { error.value = e.message; }
}
watch(() => ui.refreshKey, load, { immediate: true });

function pick(p) {
    active.value = p.id;
    Object.assign(shocks, { salaryDelayDays: 0, surpriseAmount: 0, surpriseDay: 3, billIncreasePct: 0, incomeCutPct: 0 }, p.shocks);
}

const input = computed(() => {
    if (!data.value) return null;
    const { dash, bills } = data.value;
    return {
        today: today(),
        balance: dash.safe_to_spend.balance,
        nextPayday: dash.safe_to_spend.next_payday,
        payFrequency: dash.user.pay_frequency,
        paycheck: paycheckAmount(dash.user.monthly_income, dash.user.pay_frequency),
        bills,
        dailySpend: Number(dailySpend.value) || 0,
    };
});
const baseline = computed(() => input.value && simulate(input.value));
const scenario = computed(() => input.value && simulate({ ...input.value, shocks: { ...shocks, surpriseAmount: Number(shocks.surpriseAmount) || 0 } }));
const r = computed(() => scenario.value);
const short = (d) => niceDate(d, { weekday: 'short', month: 'short', day: 'numeric' });

const chartData = computed(() => r.value && {
    labels: r.value.timeline.map((t) => niceDate(t.date, { month: 'short', day: 'numeric' })),
    datasets: [
        { label: 'What if', data: r.value.timeline.map((t) => t.balance), borderColor: '#f43f5e', backgroundColor: '#f43f5e22', fill: 'origin', tension: 0.25, pointRadius: 0, borderWidth: 2 },
        { label: 'Normal', data: baseline.value.timeline.map((t) => t.balance), borderColor: '#6366f1', borderDash: [5, 4], tension: 0.25, pointRadius: 0, borderWidth: 2 },
    ],
});
const chartOptions = { interaction: { mode: 'index', intersect: false }, plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, usePointStyle: true } } }, scales: { x: { grid: { display: false }, ticks: { maxTicksLimit: 6 } }, y: { ticks: { maxTicksLimit: 5 } } } };
</script>

<template>
    <div>
        <PlanTabs />
        <div v-if="error && !data" class="card text-center text-slate-500">{{ error }} <button class="btn-ghost mt-3" @click="load">Retry</button></div>
        <div v-else-if="!r" class="h-64 animate-pulse rounded-3xl bg-slate-200 dark:bg-slate-800" />

        <div v-else class="space-y-4">
            <section class="card">
                <h2 class="font-semibold">Domino Check</h2>
                <p class="text-sm text-slate-500">Test your next two pay cycles before anything happens. It runs on this device, even offline.</p>
                <div class="mt-3 grid grid-cols-2 gap-2">
                    <button v-for="p in PRESETS" :key="p.id" class="rounded-xl p-3 text-left text-sm font-medium ring-1" :class="active === p.id ? 'bg-indigo-50 ring-indigo-500 dark:bg-indigo-950/50' : 'ring-slate-200 dark:ring-slate-800'" @click="pick(p)">
                        <span class="text-lg">{{ p.icon }}</span> {{ p.label }}
                    </button>
                </div>
                <details class="mt-3">
                    <summary class="cursor-pointer text-sm font-medium text-indigo-600">Fine-tune</summary>
                    <div class="mt-3 grid grid-cols-2 gap-3">
                        <label class="text-xs text-slate-500">Salary late (days)<input v-model.number="shocks.salaryDelayDays" type="number" min="0" max="30" class="input mt-1" @input="active = ''" /></label>
                        <label class="text-xs text-slate-500">Surprise expense<input v-model.number="shocks.surpriseAmount" type="number" min="0" class="input mt-1" @input="active = ''" /></label>
                        <label class="text-xs text-slate-500">Bills increase (%)<input v-model.number="shocks.billIncreasePct" type="number" min="0" max="300" class="input mt-1" @input="active = ''" /></label>
                        <label class="text-xs text-slate-500">Income cut (%)<input v-model.number="shocks.incomeCutPct" type="number" min="0" max="100" class="input mt-1" @input="active = ''" /></label>
                        <label class="col-span-2 text-xs text-slate-500">Everyday spending per day<input v-model.number="dailySpend" type="number" min="0" class="input mt-1" /></label>
                    </div>
                </details>
            </section>

            <section class="card flex items-center gap-4" :class="r.firstShortfall ? 'ring-rose-200 dark:ring-rose-900' : 'ring-emerald-200 dark:ring-emerald-900'">
                <Mascot :mood="r.firstShortfall ? (r.dominoes.length > 3 ? 'dizzy' : 'worried') : 'cool'" :size="76" bob />
                <div class="min-w-0">
                    <template v-if="r.firstShortfall">
                        <p class="text-sm text-slate-500">First domino falls on</p>
                        <p class="text-xl font-bold text-rose-600">{{ short(r.firstShortfall.date) }}</p>
                        <p class="text-sm">You'd be <b>{{ money(-r.firstShortfall.balance) }}</b> short. A buffer of <b>{{ money(r.bufferNeeded) }}</b> covers this scenario.</p>
                    </template>
                    <template v-else>
                        <p class="text-xl font-bold text-emerald-600">You'd make it 🎉</p>
                        <p class="text-sm">Lowest point: <b>{{ money(r.lowest.balance) }}</b> on {{ short(r.lowest.date) }}.</p>
                    </template>
                </div>
            </section>

            <section class="card">
                <h3 class="mb-2 font-semibold">Your balance, day by day</h3>
                <div class="h-56"><Chart type="line" :data="chartData" :options="chartOptions" /></div>
                <p class="mt-2 text-xs text-slate-500">Paydays: {{ r.paydays.map(short).join(' · ') }}</p>
            </section>

            <section v-if="r.firstShortfall" class="card">
                <h3 class="mb-2 font-semibold">The domino chain</h3>
                <ol class="space-y-2">
                    <li v-for="(e, i) in r.dominoes" :key="i" class="flex items-center gap-3 text-sm">
                        <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg text-xs font-bold text-white" :class="e.balanceAfter < 0 ? 'bg-rose-500' : 'bg-slate-400'">{{ i + 1 }}</span>
                        <span class="flex-1">{{ e.label }} <span class="text-slate-500">· {{ short(e.date) }}</span><span v-if="e.isDebt" class="ml-1 rounded bg-amber-100 px-1 text-[10px] text-amber-800">debt</span></span>
                        <b>−{{ money(e.amount) }}</b>
                    </li>
                </ol>
                <p v-if="r.collisions.length > 1" class="mt-3 rounded-xl bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950/40 dark:text-amber-200">⚠️ {{ r.collisions.map((c) => c.label).join(', ') }} land within a few days of each other.</p>
            </section>

            <section v-if="r.suggestions.length" class="card">
                <h3 class="mb-2 font-semibold">How to stop it</h3>
                <ul class="space-y-2 text-sm">
                    <li v-for="(s, i) in r.suggestions" :key="i" class="flex gap-2">
                        <span>{{ { cut: '✂️', postpone: '📅', 'ask-lender': '📞', buffer: '🛟' }[s.kind] }}</span>
                        <span v-if="s.kind === 'cut'">Spend <b>{{ money(s.amount) }}/day less</b> until {{ short(s.until) }}.</span>
                        <span v-else-if="s.kind === 'postpone'">Move <b>{{ s.name }}</b> ({{ money(s.amount) }}, due {{ short(s.date) }}) to after payday if you can.</span>
                        <span v-else-if="s.kind === 'ask-lender'">Ask about moving the <b>{{ s.name }}</b> payment ({{ short(s.date) }}) before it's late. Lenders are usually more flexible if you ask early.</span>
                        <span v-else>Build a <b>{{ money(s.amount) }}</b> safety buffer so this never bites.</span>
                    </li>
                </ul>
            </section>
            <p class="text-center text-xs text-slate-400">A simulation based on your balance, bills and spending pace. Not financial advice.</p>
        </div>
    </div>
</template>
