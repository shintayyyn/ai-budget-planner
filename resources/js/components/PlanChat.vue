<script setup>
import { ref, computed, onMounted, onBeforeUnmount, nextTick } from 'vue';
import { api } from '../api';
import { useUi } from '../stores/ui';
import { sync } from '../sync';
import Mascot from './Mascot.vue';

const props = defineProps({ planId: [String, Number] });
const ui = useUi();
const messages = ref([]);
const body = ref('');
const list = ref(null);
const loaded = ref(false);
let timer;

const path = computed(() => `/plans/${props.planId}/messages`);
const waiting = computed(() => sync.pending.filter((p) => p.method === 'POST' && p.path === path.value));
const time = (t) => new Date(t).toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
const scrollDown = () => nextTick(() => list.value?.scrollTo({ top: list.value.scrollHeight }));

async function load(poll = false) {
    const last = messages.value.at(-1)?.id;
    try {
        const res = await api.get(path.value, poll && last ? { after: last } : undefined);
        if (!Array.isArray(res)) return;
        messages.value = poll && last ? [...messages.value, ...res] : res;
        if (res.length) scrollDown();
    } catch {} finally { loaded.value = true; }
}

async function send() {
    const text = body.value.trim();
    if (!text) return;
    body.value = '';
    try {
        const res = await api.post(path.value, { body: text });
        if (!res?.queued) messages.value.push(res);
        scrollDown();
    } catch (e) { body.value = text; ui.error(e); }
}

onMounted(() => { load(); timer = setInterval(() => sync.online && document.visibilityState === 'visible' && load(true), 5000); });
onBeforeUnmount(() => clearInterval(timer));
</script>

<template>
    <section class="card flex h-[60vh] flex-col !p-0">
        <div ref="list" class="flex-1 space-y-2 overflow-y-auto p-4" aria-live="polite">
            <div v-if="loaded && !messages.length && !waiting.length" class="py-8 text-center">
                <Mascot mood="happy" :size="64" class="mx-auto" />
                <p class="mt-2 text-sm text-slate-500">Say hi to your barkada! Plan who brings what, share updates, cheer each other on.</p>
            </div>
            <div v-for="m in messages" :key="m.id" class="flex" :class="m.mine ? 'justify-end' : 'justify-start'">
                <div class="max-w-[80%] rounded-2xl px-3 py-2 text-sm" :class="m.mine ? 'rounded-br-md bg-indigo-600 text-white' : 'rounded-bl-md bg-slate-100 dark:bg-slate-800'">
                    <p v-if="!m.mine" class="text-xs font-semibold text-indigo-600 dark:text-indigo-300">{{ m.name }}</p>
                    <p class="whitespace-pre-wrap break-words">{{ m.body }}</p>
                    <p class="mt-0.5 text-right text-[10px] opacity-70">{{ time(m.created_at) }}</p>
                </div>
            </div>
            <div v-for="p in waiting" :key="p.id" class="flex justify-end">
                <div class="max-w-[80%] rounded-2xl rounded-br-md bg-indigo-300 px-3 py-2 text-sm text-white dark:bg-indigo-900">
                    <p class="whitespace-pre-wrap break-words">{{ p.body?.body }}</p>
                    <p class="mt-0.5 text-right text-[10px] opacity-80">☁️ sends when online</p>
                </div>
            </div>
        </div>
        <form class="flex gap-2 border-t border-slate-100 p-3 dark:border-slate-800" @submit.prevent="send">
            <input v-model="body" class="input" maxlength="2000" placeholder="Message the group…" aria-label="Message" />
            <button class="btn-primary shrink-0" :disabled="!body.trim()">Send</button>
        </form>
    </section>
</template>
