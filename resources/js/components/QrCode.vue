<script setup>
import { ref, watch, onMounted } from 'vue';
import QRCode from 'qrcode';
import Mascot from './Mascot.vue';

// Generated on the device (works offline); the link never goes to a QR web service.
const props = defineProps({
    value: String,
    size: { type: Number, default: 220 },
    badge: { type: Boolean, default: false },
    label: { type: String, default: 'QR code for the invite link' },
});
const svg = ref('');

async function render() {
    svg.value = props.value ? await QRCode.toString(props.value, { type: 'svg', margin: 1, errorCorrectionLevel: props.badge ? 'H' : 'M', color: { dark: '#0f172a', light: '#ffffff' } }) : '';
}
onMounted(render);
watch(() => [props.value, props.badge], render);
</script>

<template>
    <div class="relative" :style="{ width: size + 'px', height: size + 'px' }">
        <div class="h-full w-full rounded-2xl bg-white p-3 shadow-sm ring-1 ring-slate-200" role="img" :aria-label="label" v-html="svg" />
        <span v-if="badge && svg" class="absolute top-1/2 left-1/2 flex -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-2xl bg-white p-0.5 ring-4 ring-white">
            <Mascot :size="Math.round(size * 0.2)" />
        </span>
    </div>
</template>
