<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '../api';
import { useUi } from '../stores/ui';
import { money, niceDate, today } from '../format';
import Icon from '../components/Icon.vue';
import Sheet from '../components/Sheet.vue';
import Progress from '../components/Progress.vue';
import GoalTabs from '../components/GoalTabs.vue';
import QrScanSheet from '../components/QrScanSheet.vue';
import { parseBuddyCode } from '../qr';

const ui = useUi();
const router = useRouter();
const data = ref(null);
const createOpen = ref(false);
const scanOpen = ref(false);
const code = ref('');
const busy = ref(false);
const errors = ref({});
const canScan = 'BarcodeDetector' in window && !!navigator.mediaDevices;

const TYPES = [
    { id: 'outing', label: 'Outing', icon: '🎉' },
    { id: 'trip', label: 'Trip', icon: '✈️' },
    { id: 'goal', label: 'Goal', icon: '🎯' },
    { id: 'household', label: 'Household', icon: '🏠' },
    { id: 'other', label: 'Other', icon: '✨' },
];
const blank = () => ({ name: '', icon: '🎉', type: 'outing', visibility: 'group', target_amount: '', target_date: '', description: '', emails: '' });
const form = reactive(blank());

async function load() {
    try { data.value = await api.get('/plans'); } catch (e) { ui.error(e); }
}
onMounted(load);

function pickType(t) {
    form.type = t.id;
    form.icon = t.icon;
}

async function create() {
    busy.value = true;
    errors.value = {};
    try {
        const emails = form.visibility === 'group' ? form.emails.split(/[\s,;]+/).map((e) => e.trim()).filter(Boolean) : [];
        const plan = await api.post('/plans', {
            ...form,
            emails,
            target_amount: form.target_amount === '' ? null : Number(form.target_amount),
            target_date: form.target_date || null,
        });
        createOpen.value = false;
        Object.assign(form, blank());
        if (plan?.queued) return;
        router.push(`/plans/${plan.id}${plan.visibility === 'group' ? '?invite=1' : ''}`);
    } catch (e) {
        errors.value = e.errors || {};
        if (!e.errors) ui.error(e);
    } finally { busy.value = false; }
}

function joinCode(c = code.value) {
    if (parseBuddyCode(c)) return router.push(`/u/${parseBuddyCode(c)}`);
    const clean = c.trim().replace(/.*\/join\//i, '').toUpperCase();
    if (clean.length < 6) return ui.toast('Enter the 8-character invite code', 'error');
    scanOpen.value = false;
    router.push(`/join/${clean}`);
}

async function respond(invite, accept) {
    try {
        const plan = await api.post(`/invites/${invite.id}`, { accept });
        ui.changed();
        if (accept) {
            ui.toast(`Joined ${invite.plan.name} 🎉`);
            router.push(`/plans/${plan.id}`);
        } else load();
    } catch (e) { ui.error(e); }
}

const plans = computed(() => data.value?.plans || []);
</script>

<template>
    <div class="space-y-4">
        <GoalTabs />

        <p class="text-sm text-slate-500">Plan an outing, trip or shared pot. Keep it <b>private</b> or invite your <b>group</b> with a QR code, link or email, then save, spend and settle up together.</p>

        <div class="grid grid-cols-2 gap-2">
            <button class="btn-primary" @click="createOpen = true"><Icon name="plus" size="18" />New plan</button>
            <button v-if="canScan" class="btn-ghost" @click="scanOpen = true"><Icon name="camera" size="18" />Scan QR</button>
            <RouterLink to="/me/qr" class="btn-ghost" :class="canScan ? 'col-span-2' : ''">🪪 My QR &amp; buddies</RouterLink>
            <form class="flex gap-2" :class="canScan ? 'col-span-2' : ''" @submit.prevent="joinCode()">
                <input v-model="code" class="input uppercase" placeholder="Invite code" maxlength="60" aria-label="Invite code" />
                <button class="btn-ghost shrink-0">Join</button>
            </form>
        </div>

        <section v-if="data?.invites.length" class="space-y-2">
            <h3 class="text-sm font-semibold text-slate-500">Invitations</h3>
            <div v-for="i in data.invites" :key="i.id" class="card flex items-center gap-3 !p-3 ring-indigo-300 dark:ring-indigo-800">
                <span class="text-2xl">{{ i.plan.icon }}</span>
                <div class="min-w-0 flex-1">
                    <p class="truncate font-medium">{{ i.plan.name }}</p>
                    <p class="text-xs text-slate-500">From {{ i.inviter.name }}</p>
                </div>
                <button class="btn-ghost !px-3 !py-1.5 text-xs" @click="respond(i, false)">Decline</button>
                <button class="btn-primary !px-3 !py-1.5 text-xs" @click="respond(i, true)">Join</button>
            </div>
        </section>

        <div v-if="data && !plans.length" class="card py-10 text-center">
            <p class="text-4xl">👥</p>
            <p class="mt-2 font-medium">No shared plans yet</p>
            <p class="text-sm text-slate-500">Weekend outing? Group trip? Shared rent? Start one and invite your people.</p>
        </div>

        <div class="grid gap-3 md:grid-cols-2">
            <RouterLink v-for="p in plans" :key="p.id" :to="`/plans/${p.id}`" class="card block transition hover:ring-indigo-300">
                <div class="flex items-start gap-3">
                    <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-indigo-50 text-2xl dark:bg-indigo-950/50">{{ p.icon }}</span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate font-semibold">{{ p.name }}</p>
                        <p class="text-xs text-slate-500">
                            {{ p.visibility === 'private' ? '🔒 Private' : `👥 ${p.members_count} member${p.members_count === 1 ? '' : 's'}` }}
                            <template v-if="p.target_date"> · {{ niceDate(p.target_date, { month: 'short', day: 'numeric' }) }}</template>
                            <template v-if="p.my_role === 'owner'"> · Owner</template>
                        </p>
                    </div>
                    <Icon name="chevronRight" size="18" class="text-slate-400" />
                </div>
                <template v-if="p.target_amount">
                    <div class="mt-3 mb-1 flex justify-between text-sm">
                        <span><b>{{ money(p.summary.contributed, { currency: p.currency }) }}</b> <span class="text-slate-500">of {{ money(p.target_amount, { currency: p.currency }) }}</span></span>
                        <span class="text-slate-500">{{ p.summary.percent }}%</span>
                    </div>
                    <Progress :value="p.summary.contributed" :max="p.target_amount" color="#10b981" />
                </template>
                <p v-else class="mt-3 text-sm text-slate-500">Spent together: <b class="text-slate-900 dark:text-white">{{ money(p.summary.spent, { currency: p.currency }) }}</b></p>
            </RouterLink>
        </div>

        <Sheet :open="createOpen" title="New plan" @close="createOpen = false">
            <form class="space-y-4" @submit.prevent="create">
                <div class="grid grid-cols-5 gap-1.5">
                    <button v-for="t in TYPES" :key="t.id" type="button" class="flex flex-col items-center rounded-xl p-2 text-[11px] ring-1" :class="form.type === t.id ? 'bg-indigo-50 ring-indigo-500 dark:bg-indigo-950/50' : 'ring-slate-200 dark:ring-slate-800'" @click="pickType(t)">
                        <span class="text-xl">{{ t.icon }}</span>{{ t.label }}
                    </button>
                </div>
                <div class="flex gap-2">
                    <input v-model="form.icon" class="input !w-16 text-center text-xl" maxlength="4" aria-label="Icon" />
                    <input v-model="form.name" class="input" placeholder="e.g. Beach day with friends" required maxlength="100" aria-label="Plan name" />
                </div>
                <p v-if="errors.name" class="text-xs text-rose-600">{{ errors.name[0] }}</p>

                <div>
                    <span class="label">Who can see it?</span>
                    <div class="grid grid-cols-2 gap-2">
                        <button type="button" class="rounded-xl p-3 text-left ring-1" :class="form.visibility === 'private' ? 'bg-indigo-50 ring-indigo-500 dark:bg-indigo-950/50' : 'ring-slate-200 dark:ring-slate-800'" @click="form.visibility = 'private'">
                            <p class="text-sm font-semibold">🔒 Private</p><p class="text-xs text-slate-500">Only you</p>
                        </button>
                        <button type="button" class="rounded-xl p-3 text-left ring-1" :class="form.visibility === 'group' ? 'bg-indigo-50 ring-indigo-500 dark:bg-indigo-950/50' : 'ring-slate-200 dark:ring-slate-800'" @click="form.visibility = 'group'">
                            <p class="text-sm font-semibold">👥 Group</p><p class="text-xs text-slate-500">Invite people to manage it together</p>
                        </button>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div><label class="label" for="pt">Target (optional)</label><input id="pt" v-model="form.target_amount" type="number" inputmode="decimal" min="1" class="input" placeholder="Budget or goal" /></div>
                    <div><label class="label" for="pd">Date (optional)</label><input id="pd" v-model="form.target_date" type="date" :min="today()" class="input" /></div>
                </div>
                <div><label class="label" for="pdesc">Notes</label><textarea id="pdesc" v-model="form.description" rows="2" class="input" placeholder="Where, when, what's included…" /></div>
                <div v-if="form.visibility === 'group'">
                    <label class="label" for="pe">Invite by email (optional)</label>
                    <input id="pe" v-model="form.emails" class="input" placeholder="ana@mail.com, ben@mail.com" />
                    <p class="mt-1 text-xs text-slate-500">You'll also get a QR code and link to share once it's created.</p>
                </div>
                <button class="btn-primary w-full" :disabled="busy">{{ busy ? 'Creating…' : 'Create plan' }}</button>
            </form>
        </Sheet>

        <QrScanSheet :open="scanOpen" @close="scanOpen = false" @code="joinCode" @buddy="(c) => router.push(`/u/${c}`)" />
    </div>
</template>
