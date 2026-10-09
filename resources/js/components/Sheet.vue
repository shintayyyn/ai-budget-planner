<script setup>
import { watch, onBeforeUnmount } from 'vue';
import Icon from './Icon.vue';

const props = defineProps({ open: Boolean, title: String, wide: Boolean });
const emit = defineEmits(['close']);

const onKey = (e) => e.key === 'Escape' && emit('close');
watch(() => props.open, (o) => {
    document.body.style.overflow = o ? 'hidden' : '';
    o ? addEventListener('keydown', onKey) : removeEventListener('keydown', onKey);
});
onBeforeUnmount(() => { document.body.style.overflow = ''; removeEventListener('keydown', onKey); });
</script>

<template>
    <Teleport to="body">
        <Transition enter-from-class="opacity-0" leave-to-class="opacity-0" enter-active-class="transition duration-200" leave-active-class="transition duration-150">
            <div v-if="open" class="fixed inset-0 z-50 flex items-end justify-center bg-slate-950/50 backdrop-blur-[2px] md:items-center md:p-6" @click.self="emit('close')">
                <div role="dialog" :aria-label="title" class="safe-bottom max-h-[92dvh] w-full overflow-y-auto rounded-t-3xl bg-white shadow-2xl dark:bg-slate-900 md:rounded-3xl" :class="wide ? 'md:max-w-2xl' : 'md:max-w-md'">
                    <div class="sticky top-0 z-10 flex items-center justify-between bg-white/95 px-5 pt-3 pb-2 backdrop-blur dark:bg-slate-900/95">
                        <div class="absolute top-2 left-1/2 h-1 w-10 -translate-x-1/2 rounded-full bg-slate-300 md:hidden dark:bg-slate-700" />
                        <h2 class="pt-2 text-lg font-semibold">{{ title }}</h2>
                        <button class="mt-2 rounded-full p-1.5 text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800" aria-label="Close" @click="emit('close')"><Icon name="x" /></button>
                    </div>
                    <div class="px-5 pb-6"><slot /></div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
