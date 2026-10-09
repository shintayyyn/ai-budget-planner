<script setup>
import { computed } from 'vue';

const props = defineProps({ value: Number, max: Number, color: String, height: { type: String, default: 'h-2' } });
const pct = computed(() => (props.max ? Math.min(100, Math.max(0, (props.value / props.max) * 100)) : 0));
const over = computed(() => props.max && props.value > props.max);
const tone = computed(() => props.color || (over.value ? '#e11d48' : pct.value >= 80 ? '#f59e0b' : '#4f46e5'));
</script>

<template>
    <div class="w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800" :class="height">
        <div class="h-full rounded-full transition-all duration-500" :style="{ width: pct + '%', background: tone }" />
    </div>
</template>
