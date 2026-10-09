<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { api } from '../api';
import { useUi } from '../stores/ui';
import { useAuth } from '../stores/auth';
import { sync } from '../sync';
import { iso } from '../sim/domino';

const props = defineProps({ planId: [String, Number], ownerId: Number, targetDate: String, planName: String });
const ui = useUi();
const auth = useAuth();
const events = ref([]);
const month = ref(new Date(new Date().getFullYear(), new Date().getMonth(), 1));
const selected = ref(iso(new Date()));
const draft = reactive({ title: '', time: '', note: '' });

const path = computed(() => `/plans/${props.planId}/events`);
const waiting = computed(() => sync.pending.filter((p) => p.method === 'POST' && p.path === path.value).map((p) => ({ ...p.body, id: p.id, pending: true })));
const all = computed(() => [
    ...events.value,
    ...waiting.value,
    ...(props.targetDate ? [{ id: 'target', title: `🎯 ${props.planName} target date`, date: props.targetDate, fixed: true }] : []),
]);
const byDay = computed(() => all.value.reduce((m, e) => ((m[e.date] ||= []).push(e), m), {}));
const days = computed(() => {
    const y = month.value.getFullYear(), m = month.value.getMonth();
    const lead = new Date(y, m, 1).getDay();
    const count = new Date(y, m + 1, 0).getDate();
    return [...Array(lead).fill(null), ...Array.from({ length: count }, (_, i) => iso(new Date(y, m, i + 1)))];
});
const label = computed(() => month.value.toLocaleDateString(undefined, { month: 'long', year: 'numeric' }));
const upcoming = computed(() => all.value.filter((e) => e.date >= iso(new Date())).sort((a, b) => (a.date + (a.time || '')).localeCompare(b.date + (b.time || ''))).slice(0, 5));
const shift = (n) => (month.value = new Date(month.value.getFullYear(), month.value.getMonth() + n, 1));
const nice = (d) => new Date(d + 'T00:00').toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' });

async function load() { try { const r = await api.get(path.value); if (Array.isArray(r)) events.value = r; } catch {} }
onMounted(load);

async function add() {
    if (!draft.title.trim()) return;
    try {
        const res = await api.post(path.value, { title: draft.title, date: selected.value, time: draft.time || null, note: draft.note || null });
        Object.assign(draft, { title: '', time: '', note: '' });
        if (!res?.queued) { load(); ui.toast('Added to the calendar'); }
    } catch (e) { ui.error(e); }
}
async function remove(e) { if (!confirm(`Remove "${e.title}"?`)) return; try { await api.del(`${path.value}/${e.id}`); load(); } catch (err) { ui.error(err); } }
</script>

<template>
    <div class="space-y-3">
        <section class="card">
            <div class="mb-3 flex items-center justify-between">
                <button class="btn-ghost !px-3 !py-1" aria-label="Previous month" @click="shift(-1)">‹</button>
                <h3 class="font-semibold">{{ label }}</h3>
                <button class="btn-ghost !px-3 !py-1" aria-label="Next month" @click="shift(1)">›</button>
            </div>
            <div class="grid grid-cols-7 gap-1 text-center text-[11px] text-slate-400"><span v-for="d in ['S', 'M', 'T', 'W', 'T', 'F', 'S']" :key="d + Math.random()">{{ d }}</span></div>
            <div class="mt-1 grid grid-cols-7 gap-1">
                <span v-for="(d, i) in days" :key="i">
                    <button v-if="d" class="relative flex aspect-square w-full flex-col items-center justify-center rounded-xl text-sm" :class="[d === selected ? 'bg-indigo-600 text-white' : 'hover:bg-slate-100 dark:hover:bg-slate-800', d === iso(new Date()) && d !== selected ? 'font-bold text-indigo-600' : '']" @click="selected = d">
                        {{ Number(d.slice(8)) }}
                        <span v-if="byDay[d]" class="absolute bottom-1 h-1.5 w-1.5 rounded-full" :class="d === selected ? 'bg-white' : 'bg-amber-500'"></span>
                    </button>
                </span>
            </div>
        </section>

        <section class="card">
            <h3 class="mb-2 font-semibold">{{ nice(selected) }}</h3>
            <ul v-if="byDay[selected]" class="mb-3 space-y-2">
                <li v-for="e in byDay[selected]" :key="e.id" class="flex items-start gap-2 rounded-xl bg-slate-50 p-2 text-sm dark:bg-slate-800/60">
                    <span class="flex-1"><b>{{ e.time ? e.time.slice(0, 5) + ' · ' : '' }}{{ e.title }}</b><span v-if="e.note" class="block text-xs text-slate-500">{{ e.note }}</span><span v-if="e.pending" class="block text-xs text-sky-600">☁️ syncs when online</span><span v-else-if="e.user" class="block text-[11px] text-slate-400">by {{ e.user.name }}</span></span>
                    <button v-if="!e.fixed && !e.pending && (e.user_id === auth.user.id || ownerId === auth.user.id)" class="text-xs text-rose-600" @click="remove(e)">Remove</button>
                </li>
            </ul>
            <form class="space-y-2" @submit.prevent="add">
                <div class="flex gap-2">
                    <input v-model="draft.title" class="input" maxlength="120" placeholder="Add event (e.g. Pay deposit)" aria-label="Event title" />
                    <input v-model="draft.time" type="time" class="input w-32 shrink-0" aria-label="Time" />
                </div>
                <input v-model="draft.note" class="input" maxlength="500" placeholder="Note (optional)" aria-label="Event note" />
                <button class="btn-primary w-full" :disabled="!draft.title.trim()">Add to {{ nice(selected) }}</button>
            </form>
        </section>

        <section v-if="upcoming.length" class="card">
            <h3 class="mb-2 font-semibold">Coming up</h3>
            <ul class="space-y-1 text-sm">
                <li v-for="e in upcoming" :key="e.id" class="flex justify-between gap-2"><span class="truncate">{{ e.title }}</span><span class="shrink-0 text-slate-500">{{ nice(e.date) }}</span></li>
            </ul>
        </section>
    </div>
</template>
