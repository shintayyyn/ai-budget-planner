import { createRouter, createWebHistory } from 'vue-router';
import { useAuth } from './stores/auth';
import { setUnauthorizedHandler } from './api';

const routes = [
    { path: '/login', component: () => import('./pages/Login.vue'), meta: { guest: true } },
    { path: '/register', component: () => import('./pages/Login.vue'), meta: { guest: true }, props: { mode: 'register' } },
    { path: '/welcome', component: () => import('./pages/Onboarding.vue'), meta: { bare: true } },
    { path: '/', component: () => import('./pages/Dashboard.vue'), meta: { title: 'Home', tab: 'home' } },
    { path: '/activity', component: () => import('./pages/Transactions.vue'), meta: { title: 'Activity', tab: 'activity' } },
    { path: '/assistant', component: () => import('./pages/Assistant.vue'), meta: { title: 'Assistant', tab: 'assistant', full: true } },
    { path: '/plan', redirect: '/plan/budget' },
    { path: '/plan/budget', component: () => import('./pages/Budget.vue'), meta: { title: 'Budget', tab: 'plan' } },
    { path: '/plan/payday', component: () => import('./pages/Payday.vue'), meta: { title: 'Payday Planner', tab: 'plan' } },
    { path: '/plan/domino', component: () => import('./pages/Domino.vue'), meta: { title: 'Domino Check', tab: 'plan' } },
    { path: '/plan/bills', component: () => import('./pages/Bills.vue'), meta: { title: 'Bills & Debts', tab: 'plan' } },
    { path: '/goals', component: () => import('./pages/Goals.vue'), meta: { title: 'Savings Goals', tab: 'goals' } },
    { path: '/goals/together', component: () => import('./pages/Plans.vue'), meta: { title: 'Plan Together', tab: 'goals' } },
    { path: '/plans/:id', component: () => import('./pages/PlanDetail.vue'), meta: { title: 'Shared Plan', tab: 'goals' }, props: true },
    { path: '/join/:code', component: () => import('./pages/Join.vue'), meta: { title: 'Join Plan', tab: 'goals' }, props: true },
    { path: '/me/qr', component: () => import('./pages/MyQr.vue'), meta: { title: 'My QR', tab: 'goals' } },
    { path: '/u/:code', component: () => import('./pages/Connect.vue'), meta: { title: 'Add Buddy', tab: 'goals' }, props: true },
    { path: '/alerts', component: () => import('./pages/Alerts.vue'), meta: { title: 'Alerts' } },
    { path: '/settings', component: () => import('./pages/Settings.vue'), meta: { title: 'Settings' } },
    { path: '/:pathMatch(.*)*', redirect: '/' },
];

const router = createRouter({
    history: createWebHistory(),
    routes,
    scrollBehavior: () => ({ top: 0 }),
});

router.beforeEach(async (to) => {
    const auth = useAuth();
    await auth.load();
    if (!auth.loggedIn && !to.meta.guest) return { path: '/login', query: to.fullPath !== '/' ? { next: to.fullPath } : {} };
    if (auth.loggedIn && to.meta.guest) return '/';
    if (auth.loggedIn && !auth.user.onboarded && to.path !== '/welcome') return '/welcome';
    document.title = to.meta.title ? `${to.meta.title} · Amotan` : 'Amotan';
});

/** Load every screen in the background so switching tabs is instant. */
export function prefetchPages() {
    for (const r of routes) if (typeof r.component === 'function') r.component().catch(() => {});
}

setUnauthorizedHandler(() => {
    const auth = useAuth();
    if (auth.user) {
        auth.clear();
        router.replace('/login');
    }
});

export default router;
