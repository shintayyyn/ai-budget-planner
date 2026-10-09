<script setup>
import { computed } from 'vue';
import { mascotSvg, MASCOT_NAME } from '../mascot';

const props = defineProps({
    mood: { type: String, default: 'happy' },
    size: { type: [Number, String], default: 64 },
    bob: { type: Boolean, default: false },
});

const uid = `amo-${Math.random().toString(36).slice(2, 8)}`;
const svg = computed(() => mascotSvg(props.mood, uid));
</script>

<template>
    <span class="inline-block shrink-0" :class="bob && 'amo-bob'" :style="{ width: size + 'px', height: size + 'px' }" role="img" :aria-label="`${MASCOT_NAME}, the Amotan mascot (${mood})`" v-html="svg" />
</template>
