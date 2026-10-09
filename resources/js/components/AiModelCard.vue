<script setup>
import { onMounted, computed } from 'vue';
import Icon from './Icon.vue';
import { ai, MODELS, loadModel, recommendedModel, refreshCacheInfo, detectWebGPU, checkServer } from '../ai/engine';

defineProps({ compact: Boolean });

const selected = computed(() => ai.model || recommendedModel());
const info = computed(() => MODELS.find((m) => m.base === selected.value));

onMounted(async () => {
    await detectWebGPU();
    refreshCacheInfo();
    if (ai.backend === 'server') checkServer();
});
</script>

<template>
    <div class="rounded-2xl bg-gradient-to-br from-indigo-50 to-violet-50 p-4 ring-1 ring-indigo-100 dark:from-indigo-950/40 dark:to-violet-950/30 dark:ring-indigo-900/50">
        <div class="flex items-start gap-3">
            <div class="rounded-xl bg-indigo-600 p-2 text-white"><Icon name="cpu" size="20" /></div>
            <div class="min-w-0 flex-1">
                <template v-if="ai.backend === 'off'">
                    <p class="font-semibold">Instant mode</p>
                    <p class="text-sm text-slate-600 dark:text-slate-400">Answers come from the built-in finance engine. Turn on the on-device AI in Settings for conversational replies.</p>
                </template>
                <template v-else-if="ai.backend === 'server'">
                    <p class="font-semibold">Self-hosted AI {{ ai.serverAvailable ? '· connected' : '· unavailable' }}</p>
                    <p class="text-sm text-slate-600 dark:text-slate-400">{{ ai.serverAvailable ? `Using ${ai.serverModel} on your own server.` : 'Ollama is not reachable. Instant answers are being used instead.' }}</p>
                </template>
                <template v-else-if="ai.status === 'ready'">
                    <p class="font-semibold">On-device AI ready <span class="ml-1 inline-block h-2 w-2 rounded-full bg-emerald-500" /></p>
                    <p class="text-sm text-slate-600 dark:text-slate-400">{{ info?.label }} is running privately on this device. Nothing you type leaves it.</p>
                </template>
                <template v-else-if="ai.status === 'loading'">
                    <p class="font-semibold">{{ ai.cached[selected] ? 'Starting' : 'Downloading' }} {{ info?.label }}… {{ ai.progress }}%</p>
                    <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-indigo-100 dark:bg-indigo-950"><div class="h-full bg-indigo-600 transition-all" :style="{ width: ai.progress + '%' }" /></div>
                    <p v-if="!compact" class="mt-1 truncate text-xs text-slate-500">{{ ai.progressText }}</p>
                </template>
                <template v-else-if="ai.webgpu && !ai.webgpu.supported || ai.status === 'unsupported'">
                    <p class="font-semibold">Instant mode on this device</p>
                    <p class="text-sm text-slate-600 dark:text-slate-400">This browser doesn't support WebGPU, so the built-in finance engine answers instead. All features still work. Try Chrome, Edge or Safari 18+ for the full AI.</p>
                </template>
                <template v-else>
                    <p class="font-semibold">Private on-device AI</p>
                    <p class="text-sm text-slate-600 dark:text-slate-400">
                        {{ ai.cached[selected] ? `${info?.label} is saved on this device.` : `One-time download of ${info?.label} (${info?.size}). After that it works offline.` }}
                    </p>
                    <p v-if="ai.status === 'error'" class="mt-1 text-sm text-rose-600">{{ ai.error }}</p>
                    <button class="btn-primary mt-3 w-full sm:w-auto" @click="loadModel(selected)">
                        <Icon :name="ai.cached[selected] ? 'sparkles' : 'download'" size="18" />
                        {{ ai.cached[selected] ? 'Start AI' : 'Download & start' }}
                    </button>
                </template>
            </div>
        </div>
    </div>
</template>
