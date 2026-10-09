<script setup>
import { ref, onMounted } from 'vue';
import { api } from '../api';
import { useUi } from '../stores/ui';
import Icon from '../components/Icon.vue';

const ui = useUi();
const alerts = ref(null);

async function load() {
    try { alerts.value = await api.get('/alerts'); } catch (e) { ui.error(e); }
}
onMounted(load);

async function read(a) {
    if (a.read_at) return;
    await api.post(`/alerts/${a.id}/read`);
    a.read_at = new Date().toISOString();
    ui.unreadAlerts = Math.max(0, ui.unreadAlerts - 1);
}

async function readAll() {
    await api.post('/alerts/read-all');
    alerts.value.forEach((a) => (a.read_at ||= new Date().toISOString()));
    ui.unreadAlerts = 0;
}

const style = {
    danger: { icon: '🚨', ring: 'ring-rose-200 dark:ring-rose-900', bg: 'bg-rose-50 dark:bg-rose-950/40' },
    warning: { icon: '⚠️', ring: 'ring-amber-200 dark:ring-amber-900', bg: 'bg-amber-50 dark:bg-amber-950/40' },
    info: { icon: '🔔', ring: 'ring-sky-200 dark:ring-sky-900', bg: 'bg-sky-50 dark:bg-sky-950/40' },
};
</script>

<template>
    <div class="space-y-3">
        <div class="flex items-center justify-between">
            <p class="text-sm text-slate-500">We check your spending pace, budgets and upcoming bills every time something changes.</p>
            <button v-if="alerts?.some((a) => !a.read_at)" class="shrink-0 text-sm font-medium text-indigo-600" @click="readAll">Mark all read</button>
        </div>

        <div v-if="alerts && !alerts.length" class="card py-10 text-center">
            <p class="text-4xl">✅</p>
            <p class="mt-2 font-medium">All clear</p>
            <p class="text-sm text-slate-500">No overspending risks right now.</p>
        </div>

        <button v-for="a in alerts" :key="a.id" class="flex w-full items-start gap-3 rounded-2xl p-4 text-left ring-1 transition" :class="[style[a.level].ring, a.read_at ? 'bg-white opacity-60 dark:bg-slate-900' : style[a.level].bg]" @click="read(a)">
            <span class="text-xl">{{ style[a.level].icon }}</span>
            <div class="min-w-0 flex-1">
                <p class="font-semibold">{{ a.title }}</p>
                <p class="text-sm text-slate-600 dark:text-slate-300">{{ a.message }}</p>
            </div>
            <span v-if="!a.read_at" class="mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full bg-indigo-600" />
        </button>

        <RouterLink to="/assistant" class="card flex items-center gap-3 !p-3 text-sm">
            <Icon name="sparkles" class="text-indigo-600" />
            <span class="flex-1">Ask the assistant how to get back on track</span>
            <Icon name="chevronRight" size="18" class="text-slate-400" />
        </RouterLink>
    </div>
</template>
