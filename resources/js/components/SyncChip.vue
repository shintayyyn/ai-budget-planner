<script setup>
import { computed } from 'vue';
import { sync } from '../sync';

const state = computed(() => {
    const n = sync.pending.length;
    if (!sync.online) return { dot: 'bg-amber-500', text: n ? `Offline · ${n} saved` : 'Offline', cls: 'bg-amber-50 text-amber-800 dark:bg-amber-950/50 dark:text-amber-200' };
    if (sync.syncing) return { dot: 'bg-sky-500 animate-pulse', text: 'Syncing…', cls: 'bg-sky-50 text-sky-800 dark:bg-sky-950/50 dark:text-sky-200' };
    if (n) return { dot: 'bg-sky-500', text: `${n} to sync`, cls: 'bg-sky-50 text-sky-800 dark:bg-sky-950/50 dark:text-sky-200' };
    if (sync.failed.length) return { dot: 'bg-rose-500', text: 'Sync issue', cls: 'bg-rose-50 text-rose-700 dark:bg-rose-950/50 dark:text-rose-200' };
    return { dot: 'bg-emerald-500', text: 'Synced', cls: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300' };
});
</script>

<template>
    <RouterLink to="/settings#sync" class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium" :class="state.cls" :aria-label="`Sync status: ${state.text}`">
        <span class="h-2 w-2 rounded-full" :class="state.dot" />{{ state.text }}
    </RouterLink>
</template>
