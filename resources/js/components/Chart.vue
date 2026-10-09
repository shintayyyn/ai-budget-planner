<script setup>
import { onMounted, onBeforeUnmount, ref, watch } from 'vue';
import { Chart, BarController, BarElement, CategoryScale, LinearScale, DoughnutController, ArcElement, Tooltip, Legend } from 'chart.js';

Chart.register(BarController, BarElement, CategoryScale, LinearScale, DoughnutController, ArcElement, Tooltip, Legend);

const props = defineProps({ type: String, data: Object, options: Object });
const canvas = ref(null);
let chart = null;

function render() {
    chart?.destroy();
    const dark = document.documentElement.classList.contains('dark');
    Chart.defaults.color = dark ? '#94a3b8' : '#64748b';
    Chart.defaults.borderColor = dark ? '#1e293b' : '#e2e8f0';
    Chart.defaults.font.family = 'ui-sans-serif, system-ui, sans-serif';
    chart = new Chart(canvas.value, { type: props.type, data: props.data, options: { responsive: true, maintainAspectRatio: false, ...props.options } });
}

onMounted(render);
watch(() => props.data, render, { deep: true });
onBeforeUnmount(() => chart?.destroy());
</script>

<template>
    <div class="relative h-full w-full"><canvas ref="canvas" /></div>
</template>
