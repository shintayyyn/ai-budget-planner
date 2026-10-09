<template>
    <nav class="mb-4 grid grid-cols-2 gap-1 rounded-2xl bg-slate-100 p-1 text-sm font-medium dark:bg-slate-900">
        <RouterLink to="/goals" class="rounded-xl px-3 py-2 text-center text-slate-500 transition" exact-active-class="!bg-white !text-slate-900 shadow-sm dark:!bg-slate-800 dark:!text-white">🎯 Personal</RouterLink>
        <RouterLink to="/goals/together" class="rounded-xl px-3 py-2 text-center text-slate-500 transition" :class="$route.path.startsWith('/goals/together') || $route.path.startsWith('/plans') ? '!bg-white !text-slate-900 shadow-sm dark:!bg-slate-800 dark:!text-white' : ''">👥 Together</RouterLink>
    </nav>
</template>
