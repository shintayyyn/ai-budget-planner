<script setup>
import { computed, ref, watch } from 'vue';
import Icon from './Icon.vue';
import Spinner from './Spinner.vue';
import { ready, skipAi, prepareOffline } from '../readiness';
import { useAuth } from '../stores/auth';

const auth = useAuth();
const open = ref(false);
const minimized = ref(false);
let hideTimer;

watch(() => ready.active, (active) => {
    clearTimeout(hideTimer);
    if (active) { open.value = true; minimized.value = false; }
    else if (!ready.errors) hideTimer = setTimeout(() => (open.value = false), 4000);
});

const STEPS = [
    { key: 'files', label: 'App & receipt scanner' },
    { key: 'data', label: 'Your screens & data' },
    { key: 'ai', label: 'On-device AI' },
];
const title = computed(() => (ready.active ? 'Getting ready for offline' : ready.errors ? 'Almost ready for offline' : 'Ready to use offline'));
const retry = () => prepareOffline(auth.user?.id);
</script>

<template>
    <Transition name="sheet-pop">
        <div v-if="open" class="fixed inset-x-4 bottom-[calc(9.5rem+env(safe-area-inset-bottom))] z-40 md:inset-x-auto md:right-6 md:bottom-6 md:w-96" role="status" aria-live="polite">
            <div class="rounded-2xl bg-white p-4 shadow-xl ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <div class="flex items-center gap-3">
                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl text-white" :class="ready.active ? 'bg-indigo-600' : ready.errors ? 'bg-amber-500' : 'bg-emerald-500'">
                        <Spinner v-if="ready.active" :size="18" />
                        <Icon v-else :name="ready.errors ? 'wifiOff' : 'check'" size="18" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold">{{ title }}</p>
                        <p class="text-xs text-slate-500">{{ ready.active ? 'Keep the app open and stay online until 100%.' : ready.errors ? 'Some things need a connection to finish.' : 'Everything is saved on this device.' }}</p>
                    </div>
                    <p class="text-lg font-bold tabular-nums" :class="ready.percent === 100 ? 'text-emerald-600' : 'text-indigo-600'">{{ ready.percent }}%</p>
                    <button class="rounded-full p-1 text-slate-400 hover:text-slate-600" :aria-label="minimized ? 'Show details' : 'Hide details'" @click="minimized = !minimized">
                        <Icon name="chevronRight" size="18" class="transition-transform" :class="minimized ? '-rotate-90' : 'rotate-90'" />
                    </button>
                </div>
                <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                    <div class="h-full rounded-full transition-[width] duration-500 ease-out" :class="ready.percent === 100 ? 'bg-emerald-500' : 'bg-indigo-600'" :style="{ width: `${ready.percent}%` }" />
                </div>
                <ul v-if="!minimized" class="mt-3 space-y-2">
                    <li v-for="s in STEPS" :key="s.key" class="flex items-start gap-2 text-sm">
                        <span class="mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center">
                            <Spinner v-if="ready.steps[s.key].state === 'running'" :size="14" class="text-indigo-600" />
                            <span v-else-if="ready.steps[s.key].state === 'done'" class="text-emerald-600">✓</span>
                            <span v-else-if="ready.steps[s.key].state === 'error'" class="text-amber-600">!</span>
                            <span v-else class="text-slate-400">–</span>
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="flex justify-between gap-2">
                                <span :class="ready.steps[s.key].state === 'skipped' && 'text-slate-400'">{{ s.label }}</span>
                                <span v-if="ready.steps[s.key].state === 'running'" class="text-xs tabular-nums text-slate-500">{{ Math.floor(ready.steps[s.key].p * 100) }}%</span>
                            </span>
                            <span v-if="ready.steps[s.key].note" class="block text-xs text-slate-500">{{ ready.steps[s.key].note }}</span>
                        </span>
                        <button v-if="s.key === 'ai' && ready.steps.ai.state === 'running'" class="text-xs font-medium text-slate-500 underline" @click="skipAi">Skip</button>
                    </li>
                </ul>
                <div v-if="!ready.active" class="mt-3 flex justify-end gap-2">
                    <button v-if="ready.errors" class="btn-primary !py-1.5 text-sm" @click="retry">Try again</button>
                    <button class="btn-ghost !py-1.5 text-sm" @click="open = false">Close</button>
                </div>
            </div>
        </div>
    </Transition>
</template>
