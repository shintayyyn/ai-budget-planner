<template>
    <span class="inline-block shrink-0 animate-spin rounded-full border-2 border-current border-r-transparent align-[-2px] opacity-80" :style="{ width: `${size}px`, height: `${size}px` }" role="status" aria-label="Loading" />
</template>

<script setup>
defineProps({ size: { type: [Number, String], default: 16 } });
</script>
