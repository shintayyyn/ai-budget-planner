<script setup>
import { ref, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '../api';
import { useUi } from '../stores/ui';
import { sync } from '../sync';
import { parseBuddyCode } from '../qr';
import Mascot from '../components/Mascot.vue';

const props = defineProps({ code: String });
const router = useRouter();
const ui = useUi();
const code = parseBuddyCode(props.code);
const preview = ref(null);
const error = ref('');
const offline = ref(false);
const busy = ref(false);

onMounted(async () => {
    if (!code) return (error.value = "That doesn't look like an Amotan code.");
    if (!sync.online) return (offline.value = true);
    try { preview.value = await api.get(`/connect/${code}`); } catch (e) {
        if (e.offline) offline.value = true; else error.value = e.message;
    }
});

async function connect() {
    busy.value = true;
    try {
        const res = await api.post(`/connect/${code}`);
        if (!res?.queued) ui.toast(`You and ${res.name} are now buddies 🤝`);
        router.replace('/me/qr');
    } catch (e) { ui.error(e); } finally { busy.value = false; }
}
</script>

<template>
    <div class="mx-auto max-w-md pt-6">
        <div v-if="error" class="card py-10 text-center">
            <Mascot mood="thinking" :size="80" class="mx-auto" />
            <p class="mt-2 font-medium">{{ error }}</p>
            <p class="text-sm text-slate-500">Ask your friend to open Amotan and show their latest QR.</p>
            <RouterLink to="/me/qr" class="btn-ghost mt-4">Back</RouterLink>
        </div>
        <div v-else-if="offline" class="card p-6 text-center">
            <Mascot mood="sleepy" :size="96" class="mx-auto" bob />
            <h1 class="mt-2 text-xl font-bold">Add {{ code }}?</h1>
            <p class="mt-1 text-sm text-slate-500">You're offline. Amo will save this on your device and connect you as soon as you're back online.</p>
            <button class="btn-primary mt-5 w-full !py-3" :disabled="busy" @click="connect">Add buddy when online</button>
        </div>
        <div v-else-if="!preview" class="h-64 animate-pulse rounded-3xl bg-slate-200 dark:bg-slate-800" />
        <div v-else class="card p-6 text-center">
            <Mascot :mood="preview.is_self ? 'thinking' : 'celebrate'" :size="96" class="mx-auto" bob />
            <template v-if="preview.is_self">
                <h1 class="mt-2 text-xl font-bold">That's your own QR</h1>
                <p class="mt-1 text-sm text-slate-500">Share it with friends so they can add you.</p>
                <RouterLink to="/me/qr" class="btn-ghost mt-5 w-full">Back to my QR</RouterLink>
            </template>
            <template v-else>
                <p class="mt-2 text-sm text-slate-500">{{ code }}</p>
                <h1 class="text-2xl font-bold">{{ preview.name }}</h1>
                <p class="mt-3 rounded-xl bg-slate-50 p-3 text-xs text-slate-500 dark:bg-slate-800/60">Buddies only see each other's name, and can invite each other to shared plans in one tap. Your balance, budget and transactions stay private.</p>
                <button v-if="!preview.already_connected" class="btn-primary mt-5 w-full !py-3" :disabled="busy" @click="connect">Add {{ preview.name.split(' ')[0] }} as a buddy</button>
                <RouterLink v-else to="/me/qr" class="btn-ghost mt-5 w-full">Already buddies ✓</RouterLink>
            </template>
        </div>
    </div>
</template>
