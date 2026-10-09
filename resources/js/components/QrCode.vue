<script setup>
import { ref, watch, onMounted } from 'vue';
import QRCode from 'qrcode';

// Generated on the device; the link never goes to a QR web service.
const props = defineProps({ value: String, size: { type: Number, default: 220 } });
const svg = ref('');

async function render() {
    svg.value = props.value ? await QRCode.toString(props.value, { type: 'svg', margin: 1, errorCorrectionLevel: 'M', color: { dark: '#0f172a', light: '#ffffff' } }) : '';
}
onMounted(render);
watch(() => props.value, render);
</script>

<template>
    <div class="rounded-2xl bg-white p-3 shadow-sm ring-1 ring-slate-200" :style="{ width: size + 'px', height: size + 'px' }" role="img" aria-label="QR code for the invite link" v-html="svg" />
</template>
