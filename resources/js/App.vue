<script setup>
import { computed, onMounted, onBeforeUnmount, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import Icon from './components/Icon.vue';
import AddTransactionSheet from './components/AddTransactionSheet.vue';
import { useAuth } from './stores/auth';
import { useUi } from './stores/ui';
import { api } from './api';
import { autoStart, ai } from './ai/engine';
import { pref, setPref } from './format';

const route = useRoute();
const router = useRouter();
const auth = useAuth();
const ui = useUi();

const tabs = [
    { key: 'home', to: '/', label: 'Home', icon: 'home' },
    { key: 'activity', to: '/activity', label: 'Activity', icon: 'list' },
    { key: 'assistant', to: '/assistant', label: 'Assistant', icon: 'sparkles' },
    { key: 'plan', to: '/plan/budget', label: 'Plan', icon: 'wallet' },
    { key: 'goals', to: '/goals', label: 'Goals', icon: 'target' },
];

const chrome = computed(() => auth.loggedIn && !route.meta.guest && !route.meta.bare);
const showFab = computed(() => chrome.value && !route.meta.full);

// Offline + install prompt (Android/desktop) and iOS "Add to Home Screen" hint.
const online = ref(navigator.onLine);
const setOnline = () => (online.value = navigator.onLine);
const installEvent = ref(null);
const standalone = matchMedia('(display-mode: standalone)').matches || navigator.standalone;
const isIOS = /iPhone|iPad|iPod/.test(navigator.userAgent);
const installDismissed = ref(pref('install_dismissed', false));
const showInstall = computed(() => chrome.value && !standalone && !installDismissed.value && (installEvent.value || isIOS));

const onBeforeInstall = (e) => { e.preventDefault(); installEvent.value = e; };

async function install() {
    if (installEvent.value) {
        installEvent.value.prompt();
        await installEvent.value.userChoice;
        installEvent.value = null;
    }
    dismissInstall();
}
function dismissInstall() {
    installDismissed.value = true;
    setPref('install_dismissed', true);
}

async function refreshUnread() {
    if (!auth.loggedIn) return;
    try { ui.unreadAlerts = (await api.get('/alerts')).filter((a) => !a.read_at).length; } catch {}
}

onMounted(() => {
    addEventListener('online', setOnline);
    addEventListener('offline', setOnline);
    addEventListener('beforeinstallprompt', onBeforeInstall);
    if (auth.loggedIn) autoStart();
});
onBeforeUnmount(() => {
    removeEventListener('online', setOnline);
    removeEventListener('offline', setOnline);
    removeEventListener('beforeinstallprompt', onBeforeInstall);
});
watch(() => auth.loggedIn, (v) => v && autoStart());
watch(() => ui.refreshKey, refreshUnread);
watch(() => route.path, refreshUnread, { immediate: true });

// PWA shortcut: /activity?add=1 opens the add sheet.
watch(() => route.query.add, (v) => {
    if (v) {
        ui.openAdd();
        router.replace({ query: {} });
    }
}, { immediate: true });
</script>

<template>
    <div v-if="!chrome" class="min-h-dvh">
        <RouterView />
    </div>

    <div v-else class="min-h-dvh md:flex">
        <!-- Desktop sidebar -->
        <aside class="sticky top-0 hidden h-dvh w-64 shrink-0 flex-col border-r border-slate-200 bg-white px-4 py-6 md:flex dark:border-slate-800 dark:bg-slate-900">
            <div class="mb-8 flex items-center gap-2 px-2">
                <img src="/icons/icon-192.png" class="h-9 w-9 rounded-xl" alt="" />
                <div>
                    <p class="font-bold leading-tight">Budget AI</p>
                    <p class="text-xs text-slate-500">Private · on-device</p>
                </div>
            </div>
            <nav class="space-y-1">
                <RouterLink v-for="t in tabs" :key="t.key" :to="t.to" class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800"
                    :class="route.meta.tab === t.key && '!bg-indigo-50 !text-indigo-700 dark:!bg-indigo-950/60 dark:!text-indigo-300'">
                    <Icon :name="t.icon" size="20" />{{ t.label }}
                </RouterLink>
                <RouterLink to="/alerts" class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800" active-class="!bg-indigo-50 !text-indigo-700 dark:!bg-indigo-950/60 dark:!text-indigo-300">
                    <Icon name="bell" size="20" />Alerts
                    <span v-if="ui.unreadAlerts" class="ml-auto rounded-full bg-rose-500 px-2 text-xs text-white">{{ ui.unreadAlerts }}</span>
                </RouterLink>
                <RouterLink to="/settings" class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800" active-class="!bg-indigo-50 !text-indigo-700 dark:!bg-indigo-950/60 dark:!text-indigo-300">
                    <Icon name="gear" size="20" />Settings
                </RouterLink>
            </nav>
            <button class="btn-primary mt-6" @click="ui.openAdd()"><Icon name="plus" size="18" />Add transaction</button>
            <div class="mt-auto rounded-xl bg-slate-50 p-3 text-xs text-slate-500 dark:bg-slate-800/60">
                <p class="flex items-center gap-1.5 font-medium text-slate-700 dark:text-slate-300"><Icon name="shield" size="14" />AI runs on this device</p>
                <p class="mt-0.5">{{ ai.status === 'ready' ? 'Model loaded' : ai.backend === 'off' ? 'Instant mode' : 'Model not loaded' }}</p>
            </div>
        </aside>

        <div class="min-w-0 flex-1">
            <!-- Mobile header -->
            <header class="safe-top sticky top-0 z-30 border-b border-slate-200/60 bg-slate-50/85 backdrop-blur-lg md:hidden dark:border-slate-800/60 dark:bg-slate-950/85">
                <div class="flex h-14 items-center justify-between px-4">
                    <h1 class="text-lg font-bold">{{ route.meta.title }}</h1>
                    <div class="flex items-center gap-1">
                        <RouterLink to="/alerts" class="relative rounded-full p-2 text-slate-600 dark:text-slate-300" aria-label="Alerts">
                            <Icon name="bell" />
                            <span v-if="ui.unreadAlerts" class="absolute top-1 right-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-rose-500 px-1 text-[10px] font-bold text-white">{{ ui.unreadAlerts }}</span>
                        </RouterLink>
                        <RouterLink to="/settings" class="rounded-full p-2 text-slate-600 dark:text-slate-300" aria-label="Settings"><Icon name="gear" /></RouterLink>
                    </div>
                </div>
            </header>

            <div v-if="!online" class="flex items-center justify-center gap-2 bg-amber-100 px-4 py-1.5 text-xs font-medium text-amber-900 dark:bg-amber-900/40 dark:text-amber-200">
                <Icon name="wifiOff" size="14" />Offline. Showing your last synced data, and the on-device AI still works.
            </div>

            <div v-if="showInstall" class="mx-4 mt-3 flex items-center gap-3 rounded-2xl bg-indigo-600 p-3 text-white md:mx-8 md:mt-6">
                <img src="/icons/icon-192.png" class="h-10 w-10 rounded-xl ring-2 ring-white/30" alt="" />
                <p class="flex-1 text-sm">
                    <b>Install Budget AI</b><br />
                    <span v-if="installEvent" class="opacity-90">Add it to your home screen for quick, offline access.</span>
                    <span v-else class="opacity-90">Tap Share, then “Add to Home Screen”.</span>
                </p>
                <button v-if="installEvent" class="rounded-xl bg-white px-3 py-1.5 text-sm font-semibold text-indigo-700" @click="install">Install</button>
                <button class="p-1 opacity-80" aria-label="Dismiss" @click="dismissInstall"><Icon name="x" size="18" /></button>
            </div>

            <main class="mx-auto w-full max-w-5xl px-4 pt-4 pb-28 md:px-8 md:pt-8 md:pb-10" :class="route.meta.full && '!pb-0'">
                <RouterView v-slot="{ Component }">
                    <component :is="Component" :key="route.path" />
                </RouterView>
            </main>
        </div>

        <!-- Floating add button (mobile) -->
        <button v-if="showFab" class="fixed right-4 bottom-[calc(5.5rem+env(safe-area-inset-bottom))] z-30 flex h-14 w-14 items-center justify-center rounded-2xl bg-indigo-600 text-white shadow-lg shadow-indigo-600/30 active:scale-95 md:hidden" aria-label="Add transaction" @click="ui.openAdd()">
            <Icon name="plus" size="26" />
        </button>

        <!-- Bottom tab bar (mobile) -->
        <nav class="safe-bottom fixed inset-x-0 bottom-0 z-30 border-t border-slate-200 bg-white/95 backdrop-blur-lg md:hidden dark:border-slate-800 dark:bg-slate-900/95">
            <div class="grid h-16 grid-cols-5">
                <RouterLink v-for="t in tabs" :key="t.key" :to="t.to" class="flex flex-col items-center justify-center gap-0.5 text-[11px] font-medium text-slate-500"
                    :class="route.meta.tab === t.key && '!text-indigo-600 dark:!text-indigo-400'">
                    <span v-if="t.key === 'assistant'" class="-mt-5 flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-br from-indigo-500 to-violet-600 text-white shadow-lg shadow-indigo-500/30"><Icon :name="t.icon" size="24" /></span>
                    <Icon v-else :name="t.icon" />
                    {{ t.label }}
                </RouterLink>
            </div>
        </nav>
    </div>

    <AddTransactionSheet :open="ui.addOpen" @close="ui.addOpen = false; ui.addPreset = null" />

    <!-- Toasts -->
    <div class="pointer-events-none fixed inset-x-0 top-[calc(env(safe-area-inset-top)+0.75rem)] z-[60] flex flex-col items-center gap-2 px-4">
        <TransitionGroup enter-from-class="opacity-0 -translate-y-2" leave-to-class="opacity-0" enter-active-class="transition" leave-active-class="transition">
            <div v-for="t in ui.toasts" :key="t.id" class="pointer-events-auto max-w-sm rounded-2xl px-4 py-2.5 text-sm font-medium shadow-lg" :class="t.type === 'error' ? 'bg-rose-600 text-white' : 'bg-slate-900 text-white dark:bg-white dark:text-slate-900'">{{ t.message }}</div>
        </TransitionGroup>
    </div>
</template>
