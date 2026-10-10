<script setup>
import { reactive } from 'vue';
import { useUi } from '../stores/ui';

const ui = useUi();

const STYLE = {
    success: { icon: 'M20 6 9 17l-5-5', tint: 'bg-emerald-500/12 text-emerald-600 dark:text-emerald-400', bar: 'bg-emerald-500' },
    error: { icon: 'M12 8v5M12 16.5v.01', tint: 'bg-rose-500/12 text-rose-600 dark:text-rose-400', bar: 'bg-rose-500' },
    info: { icon: 'M12 11v5M12 7.5v.01', tint: 'bg-indigo-500/12 text-indigo-600 dark:text-indigo-400', bar: 'bg-indigo-500' },
};
const style = (t) => STYLE[t.type] || STYLE.success;

// Swipe a toast sideways to dismiss it.
const drag = reactive({});
function down(t, e) {
    drag[t.id] = { x0: e.clientX, dx: 0 };
    ui.pause(t.id);
}
function move(t, e) {
    if (drag[t.id]) drag[t.id].dx = e.clientX - drag[t.id].x0;
}
function up(t) {
    const d = drag[t.id];
    delete drag[t.id];
    if (d && Math.abs(d.dx) > 80) ui.dismiss(t.id);
    else ui.resume(t.id);
}
const offset = (t) => {
    const dx = drag[t.id]?.dx || 0;
    return dx ? { transform: `translateX(${dx}px)`, opacity: 1 - Math.min(0.7, Math.abs(dx) / 240), transition: 'none' } : {};
};
</script>

<template>
    <div class="pointer-events-none fixed inset-x-0 top-[calc(env(safe-area-inset-top)+0.75rem)] z-[60] flex flex-col items-center gap-2 px-4" aria-live="polite">
        <TransitionGroup name="toast">
            <div
                v-for="t in ui.toasts"
                :key="t.id"
                role="status"
                class="toast-card pointer-events-auto relative w-full max-w-sm touch-pan-y select-none overflow-hidden rounded-2xl bg-white shadow-[0_12px_40px_-12px_rgb(15_23_42/0.35)] ring-1 ring-slate-900/[0.06] dark:bg-slate-900 dark:ring-white/10"
                :style="offset(t)"
                @mouseenter="ui.pause(t.id)"
                @mouseleave="ui.resume(t.id)"
                @pointerdown="down(t, $event)"
                @pointermove="move(t, $event)"
                @pointerup="up(t)"
                @pointercancel="up(t)"
            >
                <div class="flex items-start gap-3 py-3 pr-3 pl-3.5">
                    <span class="mt-px flex h-7 w-7 shrink-0 items-center justify-center rounded-full" :class="style(t).tint">
                        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path :d="style(t).icon" /></svg>
                    </span>
                    <div class="min-w-0 flex-1 pt-[3px]">
                        <p class="text-[13.5px] leading-snug font-semibold text-slate-800 dark:text-slate-100">{{ t.message }}</p>
                        <p v-if="t.detail" class="mt-0.5 text-xs leading-snug text-slate-500 dark:text-slate-400">{{ t.detail }}</p>
                    </div>
                    <button class="-mr-1 rounded-full p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-800 dark:hover:text-slate-200" aria-label="Dismiss" @pointerdown.stop @click="ui.dismiss(t.id)">
                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12" /></svg>
                    </button>
                </div>
                <div class="absolute inset-x-0 bottom-0 h-[3px] bg-slate-900/5 dark:bg-white/5">
                    <div class="toast-timer h-full origin-left" :class="style(t).bar" :style="{ animationDuration: `${t.duration}ms`, animationPlayState: t.paused ? 'paused' : 'running' }" />
                </div>
            </div>
        </TransitionGroup>
    </div>
</template>
