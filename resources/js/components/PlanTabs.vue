<template>
    <nav class="mb-4 grid grid-cols-3 gap-1 rounded-2xl bg-slate-100 p-1 text-sm font-medium dark:bg-slate-900">
        <RouterLink v-for="t in tabs" :key="t.to" :to="t.to" class="rounded-xl px-3 py-2 text-center text-slate-500 transition" active-class="!bg-white !text-slate-900 shadow-sm dark:!bg-slate-800 dark:!text-white">{{ t.label }}</RouterLink>
    </nav>
</template>

<script setup>
const tabs = [
    { to: '/plan/budget', label: 'Budget' },
    { to: '/plan/payday', label: 'Payday' },
    { to: '/plan/bills', label: 'Bills & Debts' },
];
</script>
