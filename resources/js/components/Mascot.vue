<script setup>
import { computed, ref, onBeforeUnmount } from 'vue';
import { mascotSvg, moodAction, MASCOT_NAME, REACTIONS } from '../mascot';

const props = defineProps({
    mood: { type: String, default: 'happy' },
    size: { type: [Number, String], default: 64 },
    bob: { type: Boolean, default: false },
    action: { type: String, default: null },
    interactive: { type: Boolean, default: true },
});
const emit = defineEmits(['react']);

const uid = `amo-${Math.random().toString(36).slice(2, 8)}`;
const reaction = ref(null);
let timer;
const shown = computed(() => reaction.value || props.mood);
const svg = computed(() => mascotSvg(shown.value, uid));
const anim = computed(() => {
    const a = reaction.value ? 'jump' : props.action || (props.bob ? moodAction(props.mood) : null);
    return a && a !== 'none' ? `amo-${a}` : '';
});

function react() {
    if (!props.interactive) return;
    const options = REACTIONS.filter((r) => r !== shown.value);
    reaction.value = options[Math.floor(Math.random() * options.length)];
    emit('react', reaction.value);
    clearTimeout(timer);
    timer = setTimeout(() => { reaction.value = null; }, 1800);
}
onBeforeUnmount(() => clearTimeout(timer));
</script>

<template>
    <span
        class="inline-block shrink-0 select-none"
        :class="[anim, interactive && 'cursor-pointer']"
        :style="{ width: size + 'px', height: size + 'px' }"
        :role="interactive ? 'button' : 'img'"
        :tabindex="interactive ? 0 : undefined"
        :aria-label="`${MASCOT_NAME}, the Amotan mascot (${shown})${interactive ? '. Tap to play' : ''}`"
        @click.stop="react"
        @keydown.enter.prevent="react"
        v-html="svg"
    />
</template>
