<script setup>
import { ref, watch, onBeforeUnmount } from 'vue';
import Sheet from './Sheet.vue';
import { parseBuddyCode } from '../qr';

// In-app QR scanning with the browser's built-in BarcodeDetector (on-device).
// Where it's unsupported, people can scan with their camera app or type the code.
const props = defineProps({ open: Boolean, title: { type: String, default: 'Scan QR' } });
const emit = defineEmits(['close', 'code', 'buddy']);
const video = ref(null);
const error = ref('');
let stream = null;
let timer = null;

async function start() {
    error.value = '';
    try {
        const detector = new window.BarcodeDetector({ formats: ['qr_code'] });
        stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
        video.value.srcObject = stream;
        await video.value.play();
        timer = setInterval(async () => {
            try {
                const [hit] = await detector.detect(video.value);
                if (!hit) return;
                const buddy = parseBuddyCode(hit.rawValue);
                if (buddy) {
                    stop();
                    return emit('buddy', buddy);
                }
                const m = hit.rawValue.match(/\/join\/([A-Z0-9]{6,12})/i) || hit.rawValue.match(/^([A-Z0-9]{6,12})$/i);
                if (m) {
                    stop();
                    emit('code', m[1].toUpperCase());
                }
            } catch {}
        }, 300);
    } catch (e) {
        error.value = e?.name === 'NotAllowedError' ? 'Camera permission was denied.' : 'Could not start the camera.';
    }
}

function stop() {
    clearInterval(timer);
    stream?.getTracks().forEach((t) => t.stop());
    stream = null;
}

watch(() => props.open, (o) => (o ? setTimeout(start, 50) : stop()));
onBeforeUnmount(stop);
</script>

<template>
    <Sheet :open="open" :title="title" @close="stop(); emit('close')">
        <div class="overflow-hidden rounded-2xl bg-black">
            <video ref="video" class="aspect-square w-full object-cover" playsinline muted />
        </div>
        <p v-if="error" class="mt-3 text-sm text-rose-600">{{ error }}</p>
        <p v-else class="mt-3 text-center text-sm text-slate-500">Point your camera at a plan invite or a friend's Amotan QR.</p>
    </Sheet>
</template>
