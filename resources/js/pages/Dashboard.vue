<script setup>
import { ref, computed, watch } from 'vue';
import { api } from '../api';
import { useUi } from '../stores/ui';
import { useAuth } from '../stores/auth';
import { money, niceDate, monthLabel } from '../format';
import Icon from '../components/Icon.vue';
import Progress from '../components/Progress.vue';
import Chart from '../components/Chart.vue';
import Mascot from '../components/Mascot.vue';
import { sync } from '../sync';

const ui = useUi();
const auth = useAuth();
const d = ref(null);
const error = ref('');

async function load() {
    try {
        d.value = await api.get('/dashboard');
        auth.user = d.value.user;
        error.value = '';
    } catch (e) {
        error.value = e.message;
    }
}
watch(() => ui.refreshKey, load, { immediate: true });

const greeting = computed(() => {
    const h = new Date().getHours();
    return h < 12 ? 'Good morning' : h < 18 ? 'Good afternoon' : 'Good evening';
});

const sts = computed(() => d.value?.safe_to_spend);
const health = computed(() => {
    const s = sts.value;
    if (!s) return null;
    if (s.safe_to_spend < 0 || s.run_out_date) return { tone: 'danger', label: 'At risk', text: s.run_out_date ? `At your pace, money runs out ${niceDate(s.run_out_date)}` : 'Bills are more than your balance' };
    if (s.daily_spend_rate > s.daily_allowance) return { tone: 'warning', label: 'Tight', text: `You're spending ${money(s.daily_spend_rate)}/day. Aim for ${money(s.daily_allowance)}.` };
    if (!s.daily_spend_rate) return { tone: 'good', label: 'On track', text: `Keep it to ${money(Math.max(0, s.daily_allowance))}/day. Log expenses to track your pace.` };
    return { tone: 'good', label: 'On track', text: `You're spending ${money(s.daily_spend_rate)}/day on average. Nice.` };
});

const mood = computed(() => (!sync.online ? 'sleepy' : { good: 'happy', warning: 'thinking', danger: 'worried' }[health.value?.tone] || 'happy'));

const trendData = computed(() => d.value && {
    labels: d.value.trend.map((t) => monthLabel(t.month).split(' ')[0].slice(0, 3)),
    datasets: [
        { label: 'Income', data: d.value.trend.map((t) => t.income), backgroundColor: '#10b981', borderRadius: 6, maxBarThickness: 18 },
        { label: 'Spending', data: d.value.trend.map((t) => t.expense), backgroundColor: '#6366f1', borderRadius: 6, maxBarThickness: 18 },
    ],
});

const topCats = computed(() => (d.value?.month.categories || []).filter((c) => c.spent > 0).sort((a, b) => b.spent - a.spent));
const donutData = computed(() => ({
    labels: topCats.value.map((c) => c.name),
    datasets: [{ data: topCats.value.map((c) => c.spent), backgroundColor: topCats.value.map((c) => c.color), borderWidth: 0 }],
}));

const alertTone = { danger: 'bg-rose-50 text-rose-900 ring-rose-200 dark:bg-rose-950/40 dark:text-rose-200 dark:ring-rose-900', warning: 'bg-amber-50 text-amber-900 ring-amber-200 dark:bg-amber-950/40 dark:text-amber-200 dark:ring-amber-900', info: 'bg-sky-50 text-sky-900 ring-sky-200 dark:bg-sky-950/40 dark:text-sky-200 dark:ring-sky-900' };
</script>

<template>
    <div v-if="error && !d" class="card text-center text-slate-500">{{ error }} <button class="btn-ghost mt-3" @click="load">Retry</button></div>

    <div v-else-if="!d" class="space-y-4">
        <div class="h-52 animate-pulse rounded-3xl bg-slate-200 dark:bg-slate-800" />
        <div class="h-24 animate-pulse rounded-2xl bg-slate-200 dark:bg-slate-800" />
        <div class="h-40 animate-pulse rounded-2xl bg-slate-200 dark:bg-slate-800" />
    </div>

    <div v-else class="space-y-5">
        <p class="hidden text-2xl font-bold md:block">{{ greeting }}, {{ d.user.name.split(' ')[0] }}</p>

        <div class="grid gap-5 lg:grid-cols-5">
            <!-- Safe to spend hero -->
            <section class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-indigo-600 via-indigo-600 to-violet-700 p-5 text-white shadow-lg shadow-indigo-600/20 lg:col-span-3">
                <div class="absolute -top-10 -right-10 h-40 w-40 rounded-full bg-white/10" />
                <div class="flex items-center justify-between">
                    <p class="text-sm text-indigo-100">Safe to spend until payday</p>
                    <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="{ good: 'bg-emerald-400/25 text-emerald-50', warning: 'bg-amber-400/30 text-amber-50', danger: 'bg-rose-500/40 text-rose-50' }[health.tone]">{{ health.label }}</span>
                </div>
                <div class="mt-1 flex items-center justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-4xl font-bold tracking-tight">{{ money(sts.safe_to_spend) }}</p>
                        <p class="mt-1 text-sm text-indigo-100">{{ health.text }}</p>
                    </div>
                    <Mascot :mood="mood" :size="76" bob class="-mb-2" />
                </div>

                <div class="mt-5 grid grid-cols-3 gap-2 text-center">
                    <div class="rounded-2xl bg-white/10 p-2.5">
                        <p class="text-lg font-bold">{{ money(Math.max(0, sts.daily_allowance), { compact: sts.daily_allowance >= 100 }) }}</p>
                        <p class="text-[11px] text-indigo-100">per day</p>
                    </div>
                    <div class="rounded-2xl bg-white/10 p-2.5">
                        <p class="text-lg font-bold">{{ sts.days_left }}</p>
                        <p class="text-[11px] text-indigo-100">days to payday</p>
                    </div>
                    <div class="rounded-2xl bg-white/10 p-2.5">
                        <p class="text-lg font-bold">{{ money(sts.balance, { compact: true }) }}</p>
                        <p class="text-[11px] text-indigo-100">balance</p>
                    </div>
                </div>
                <p class="mt-3 text-xs text-indigo-100/90">Already set aside: {{ money(sts.bills_total) }} for bills and {{ money(sts.savings_reserved) }} for goals · Payday {{ niceDate(sts.next_payday, { weekday: 'short', month: 'short', day: 'numeric' }) }}</p>
            </section>

            <!-- Quick actions -->
            <section class="grid grid-cols-4 gap-2 lg:col-span-2 lg:grid-cols-2">
                <button class="card flex flex-col items-center justify-center gap-1.5 !p-3 text-xs font-medium" @click="ui.openAdd()"><span class="rounded-xl bg-indigo-50 p-2 text-indigo-600 dark:bg-indigo-950"><Icon name="plus" /></span>Add</button>
                <button class="card flex flex-col items-center justify-center gap-1.5 !p-3 text-xs font-medium" @click="ui.openAdd({ scan: true })"><span class="rounded-xl bg-emerald-50 p-2 text-emerald-600 dark:bg-emerald-950"><Icon name="camera" /></span>Scan</button>
                <RouterLink to="/assistant" class="card flex flex-col items-center justify-center gap-1.5 !p-3 text-xs font-medium"><span class="rounded-xl bg-violet-50 p-2 text-violet-600 dark:bg-violet-950"><Icon name="sparkles" /></span>Ask AI</RouterLink>
                <RouterLink to="/plan/payday" class="card flex flex-col items-center justify-center gap-1.5 !p-3 text-xs font-medium"><span class="rounded-xl bg-amber-50 p-2 text-amber-600 dark:bg-amber-950"><Icon name="calendar" /></span>Payday</RouterLink>
            </section>
        </div>

        <div v-if="sync.pending.length" class="flex items-center gap-3 rounded-2xl bg-sky-50 p-3 text-sm text-sky-900 ring-1 ring-sky-200 dark:bg-sky-950/40 dark:text-sky-200 dark:ring-sky-900">
            <span class="text-xl">☁️</span>
            <p class="flex-1"><b>{{ sync.pending.length }} change{{ sync.pending.length === 1 ? '' : 's' }}</b> saved on this device. Totals update after they sync.</p>
        </div>

        <RouterLink to="/plan/domino" class="card flex items-center gap-3 transition hover:ring-indigo-300">
            <Mascot mood="thinking" :size="48" />
            <div class="min-w-0 flex-1">
                <p class="font-semibold">Domino Check</p>
                <p class="text-sm text-slate-500">What if payday is late? See which bill would tip over first, before it happens.</p>
            </div>
            <Icon name="chevronRight" size="18" class="text-slate-400" />
        </RouterLink>

        <!-- Alerts -->
        <section v-if="d.alerts.length" class="space-y-2">
            <RouterLink v-for="a in d.alerts" :key="a.id" to="/alerts" class="flex items-start gap-3 rounded-2xl p-3 ring-1" :class="alertTone[a.level]">
                <Icon name="alert" size="18" class="mt-0.5 shrink-0" />
                <div class="min-w-0 text-sm"><p class="font-semibold">{{ a.title }}</p><p class="opacity-80">{{ a.message }}</p></div>
            </RouterLink>
            <RouterLink v-if="d.unread_alerts > d.alerts.length" to="/alerts" class="block text-center text-sm font-medium text-indigo-600">See all {{ d.unread_alerts }} alerts</RouterLink>
        </section>

        <div class="grid gap-5 lg:grid-cols-2">
            <!-- Month budget -->
            <section class="card">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="font-semibold">{{ monthLabel(d.month.month) }}</h2>
                    <RouterLink to="/plan/budget" class="text-sm font-medium text-indigo-600">Budget</RouterLink>
                </div>
                <div class="flex items-end justify-between">
                    <p class="text-2xl font-bold">{{ money(d.month.total_spent) }}</p>
                    <p class="text-sm text-slate-500">of {{ money(d.month.total_budget) }}</p>
                </div>
                <Progress class="mt-2" :value="d.month.total_spent" :max="d.month.total_budget" height="h-2.5" />
                <p class="mt-2 text-xs text-slate-500">Projected month total: <b :class="d.month.projected_spend > d.month.total_budget ? 'text-rose-600' : 'text-emerald-600'">{{ money(d.month.projected_spend) }}</b> · day {{ d.month.days_elapsed }}/{{ d.month.days_in_month }}</p>

                <div v-if="topCats.length" class="mt-4 flex items-center gap-4">
                    <div class="h-28 w-28 shrink-0"><Chart type="doughnut" :data="donutData" :options="{ cutout: '70%', plugins: { legend: { display: false } } }" /></div>
                    <ul class="min-w-0 flex-1 space-y-1.5 text-sm">
                        <li v-for="c in topCats.slice(0, 4)" :key="c.id" class="flex items-center gap-2">
                            <span class="h-2.5 w-2.5 shrink-0 rounded-full" :style="{ background: c.color }" />
                            <span class="truncate">{{ c.name }}</span>
                            <span class="ml-auto font-medium">{{ money(c.spent, { compact: true }) }}</span>
                        </li>
                    </ul>
                </div>
            </section>

            <!-- Trend -->
            <section class="card">
                <h2 class="mb-3 font-semibold">Income vs spending</h2>
                <div class="h-48"><Chart type="bar" :data="trendData" :options="{ plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, usePointStyle: true } } }, scales: { x: { grid: { display: false } }, y: { ticks: { maxTicksLimit: 4 } } } }" /></div>
            </section>
        </div>

        <div class="grid gap-5 lg:grid-cols-2">
            <!-- Goals -->
            <section class="card">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="font-semibold">Savings goals</h2>
                    <RouterLink to="/goals" class="text-sm font-medium text-indigo-600">All goals</RouterLink>
                </div>
                <p v-if="!d.goals.length" class="text-sm text-slate-500">No goals yet. <RouterLink to="/goals" class="font-medium text-indigo-600">Create one</RouterLink> and start saving with a plan.</p>
                <div v-for="g in d.goals" :key="g.id" class="mb-3 last:mb-0">
                    <div class="mb-1 flex items-center justify-between text-sm">
                        <span class="font-medium">{{ g.icon }} {{ g.name }}</span>
                        <span class="text-slate-500">{{ money(g.saved_amount, { compact: true }) }} / {{ money(g.target_amount, { compact: true }) }}</span>
                    </div>
                    <Progress :value="g.saved_amount" :max="g.target_amount" color="#10b981" />
                    <p class="mt-1 text-xs text-slate-500">
                        <template v-if="g.projection.remaining <= 0">Goal reached! 🎉</template>
                        <template v-else-if="g.projection.eta">On pace for {{ niceDate(g.projection.eta, { month: 'short', year: 'numeric' }) }}</template>
                        <template v-else-if="g.projection.required_monthly">Save {{ money(g.projection.required_monthly) }}/month to hit {{ niceDate(g.target_date, { month: 'short', year: 'numeric' }) }}</template>
                        <template v-else>Set a monthly amount to get an estimate</template>
                    </p>
                </div>
            </section>

            <!-- Recent -->
            <section class="card">
                <div class="mb-2 flex items-center justify-between">
                    <h2 class="font-semibold">Recent activity</h2>
                    <RouterLink to="/activity" class="text-sm font-medium text-indigo-600">See all</RouterLink>
                </div>
                <p v-if="!d.recent.length" class="py-4 text-center text-sm text-slate-500">No transactions yet. Tap + to add one.</p>
                <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                    <li v-for="t in d.recent" :key="t.id" class="flex items-center gap-3 py-2.5">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl text-lg" :style="{ background: (t.category?.color || '#64748b') + '22' }">{{ t.category?.icon || '💸' }}</span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium">{{ t.description || t.merchant || t.category?.name }}</p>
                            <p class="text-xs text-slate-500">{{ t.category?.name }} · {{ niceDate(t.occurred_on) }}</p>
                        </div>
                        <p class="text-sm font-semibold" :class="t.type === 'income' ? 'text-emerald-600' : ''">{{ t.type === 'income' ? '+' : '−' }}{{ money(t.amount) }}</p>
                    </li>
                </ul>
            </section>
        </div>
    </div>
</template>
