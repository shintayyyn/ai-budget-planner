<script setup>
import { ref, reactive, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api } from '../api';
import { useUi } from '../stores/ui';
import { useAuth } from '../stores/auth';
import { money as fmt, niceDate, today } from '../format';
import { invalidateContext } from '../ai/assistant';
import Icon from '../components/Icon.vue';
import Sheet from '../components/Sheet.vue';
import Progress from '../components/Progress.vue';
import QrCode from '../components/QrCode.vue';
import GoalTabs from '../components/GoalTabs.vue';
import Mascot from '../components/Mascot.vue';
import PlanChat from '../components/PlanChat.vue';
import PlanNotes from '../components/PlanNotes.vue';
import PlanCalendar from '../components/PlanCalendar.vue';

const props = defineProps({ id: String });
const route = useRoute();
const router = useRouter();
const ui = useUi();
const auth = useAuth();

const plan = ref(null);
const tab = ref('money');
const inviteOpen = ref(!!route.query.invite);
const itemOpen = ref(false);
const editOpen = ref(false);
const emails = ref('');
const busy = ref(false);
const item = reactive({ kind: 'contribution', amount: '', description: '', occurred_on: today(), record_personal: true });
const task = reactive({ title: '', estimated_cost: '', assignee_id: null });
const edit = reactive({});

const money = (n) => fmt(n, { currency: plan.value?.currency });
const isGroup = computed(() => plan.value?.visibility === 'group');
const s = computed(() => plan.value?.summary);
const me = computed(() => s.value?.members.find((m) => m.id === auth.user.id));
const taskCost = computed(() => (plan.value?.tasks || []).reduce((t, x) => t + (x.estimated_cost || 0), 0));

async function load() {
    try { plan.value = await api.get(`/plans/${props.id}`); } catch (e) {
        if (e.status === 404) { ui.toast('Plan not found or you are no longer a member', 'error'); router.replace('/goals/together'); } else ui.error(e);
    }
}

// Light polling so everyone sees each other's updates.
let timer;
const onVisible = () => document.visibilityState === 'visible' && load();
onMounted(() => {
    load();
    timer = setInterval(() => document.visibilityState === 'visible' && !itemOpen.value && !editOpen.value && load(), 20000);
    document.addEventListener('visibilitychange', onVisible);
    if (route.query.invite) router.replace({ query: {} });
});
onBeforeUnmount(() => { clearInterval(timer); document.removeEventListener('visibilitychange', onVisible); });

async function run(fn, success) {
    busy.value = true;
    try {
        const res = await fn();
        if (res && res.id) plan.value = res;
        if (success) ui.toast(success);
        return res;
    } catch (e) { ui.error(e); } finally { busy.value = false; }
}

function openItem(kind) {
    Object.assign(item, { kind, amount: '', description: '', occurred_on: today(), record_personal: true });
    itemOpen.value = true;
}

async function saveItem() {
    const ok = await run(() => api.post(`/plans/${props.id}/items`, { ...item, amount: Number(item.amount) }), item.kind === 'contribution' ? 'Added to the pot 🎉' : 'Expense added');
    if (ok) {
        itemOpen.value = false;
        if (item.record_personal) { invalidateContext(); ui.changed(); }
    }
}

async function removeItem(i) {
    if (!confirm('Remove this entry? Any matching personal transaction is removed too.')) return;
    await run(() => api.del(`/plans/${props.id}/items/${i.id}`), 'Removed');
    invalidateContext();
    ui.changed();
}

async function addTask() {
    if (!task.title.trim()) return;
    await run(() => api.post(`/plans/${props.id}/tasks`, { ...task, estimated_cost: task.estimated_cost === '' ? null : Number(task.estimated_cost) }));
    Object.assign(task, { title: '', estimated_cost: '', assignee_id: null });
}
const toggleTask = (t) => run(() => api.patch(`/plans/${props.id}/tasks/${t.id}`, { done: !t.done }));
const removeTask = (t) => run(() => api.del(`/plans/${props.id}/tasks/${t.id}`));

// Fair share: a private monthly comfort amount and an anonymous one-month pause.
const capacity = ref('');
watch(() => plan.value?.fair?.me.capacity, (v) => (capacity.value = v ?? ''), { immediate: true });
const saveCapacity = () => run(() => api.patch(`/plans/${props.id}/me`, { capacity: capacity.value === '' ? null : Number(capacity.value) }), 'Saved privately 🔒');
const togglePause = () => run(() => api.patch(`/plans/${props.id}/me`, { paused: !plan.value.fair.me.paused }), plan.value.fair.me.paused ? 'Welcome back!' : 'Paused for this month. Nobody sees it was you.');

// Buddies (connected by personal QR) can be invited without typing an email.
const buddies = ref([]);
const picked = ref([]);
watch(inviteOpen, async (open) => { if (open) { picked.value = []; try { buddies.value = await api.get('/connections'); } catch {} } }, { immediate: true });
const buddyChoices = computed(() => buddies.value.filter((b) => !s.value?.members.some((m) => m.id === b.id)));
const togglePick = (id) => (picked.value = picked.value.includes(id) ? picked.value.filter((x) => x !== id) : [...picked.value, id]);
async function inviteBuddies() {
    const res = await run(() => api.post(`/plans/${props.id}/invites`, { user_ids: picked.value }));
    if (res && !res.queued) {
        plan.value = res.plan;
        picked.value = [];
        ui.toast(`${res.invited} invite${res.invited === 1 ? '' : 's'} sent`);
    }
}

async function sendInvites() {
    const list = emails.value.split(/[\s,;]+/).map((e) => e.trim()).filter(Boolean);
    if (!list.length) return;
    const res = await run(() => api.post(`/plans/${props.id}/invites`, { emails: list }));
    if (res && !res.queued) {
        plan.value = res.plan;
        emails.value = '';
        ui.toast(`${res.invited} invite${res.invited === 1 ? '' : 's'} sent. They'll see it when they open the app.`);
    }
}
const cancelInvite = (i) => run(async () => { await api.del(`/plans/${props.id}/invites/${i.id}`); return api.get(`/plans/${props.id}`); });
const resetCode = () => confirm('Reset the code? Old QR codes and links stop working.') && run(() => api.post(`/plans/${props.id}/code`), 'New invite code created');

async function copyLink() {
    try { await navigator.clipboard.writeText(plan.value.join_url); ui.toast('Link copied'); } catch { ui.toast(plan.value.join_url); }
}
async function share() {
    try { await navigator.share({ title: plan.value.name, text: `Join "${plan.value.name}" on Amotan. Code: ${plan.value.invite_code}`, url: plan.value.join_url }); } catch {}
}
const canShare = !!navigator.share;

function openEdit() {
    Object.assign(edit, { name: plan.value.name, icon: plan.value.icon, description: plan.value.description || '', target_amount: plan.value.target_amount ?? '', target_date: plan.value.target_date || '', visibility: plan.value.visibility });
    editOpen.value = true;
}
async function saveEdit() {
    const ok = await run(() => api.patch(`/plans/${props.id}`, { ...edit, target_amount: edit.target_amount === '' ? null : Number(edit.target_amount), target_date: edit.target_date || null }), 'Plan updated');
    if (ok) editOpen.value = false;
}
async function makeGroup() {
    await run(() => api.patch(`/plans/${props.id}`, { visibility: 'group' }), 'Now a group plan. Invite people!');
    inviteOpen.value = true;
}
const removeMember = (m) => confirm(`Remove ${m.name} from the plan?`) && run(() => api.del(`/plans/${props.id}/members/${m.id}`), `${m.name} removed`);
const makeOwner = (m) => confirm(`Make ${m.name} the owner? You'll become a regular member.`) && run(() => api.post(`/plans/${props.id}/members/${m.id}/owner`), 'Ownership transferred');

async function leave() {
    if (!confirm('Leave this plan?')) return;
    await run(() => api.post(`/plans/${props.id}/leave`));
    router.replace('/goals/together');
}
async function destroy() {
    if (!confirm(`Delete "${plan.value.name}" for everyone? This cannot be undone.`)) return;
    await run(() => api.del(`/plans/${props.id}`));
    router.replace('/goals/together');
}

const who = (u) => (u?.id === auth.user.id ? 'you' : u?.name.split(' ')[0] || 'someone');
function itemTitle(i) {
    if (i.kind === 'contribution') return `${who(i.user) === 'you' ? 'You' : who(i.user)} added to the pot`;
    if (i.kind === 'settlement') return `🤝 ${who(i.user) === 'you' ? 'You' : who(i.user)} paid ${who(i.to_user)} back`;
    return i.description || 'Shared expense';
}

async function markSettled(t) {
    if (!confirm(`Record that ${t.from_id === auth.user.id ? 'you' : t.from} paid ${t.to_id === auth.user.id ? 'you' : t.to} ${money(t.amount)}?`)) return;
    await run(() => api.post(`/plans/${props.id}/items`, { kind: 'settlement', amount: t.amount, from_user_id: t.from_id, to_user_id: t.to_id }), 'Settlement recorded 🤝');
}

function exportCsv() {
    const rows = [['Date', 'Type', 'Member', 'To', 'Amount', 'Description']];
    plan.value.items.forEach((i) => rows.push([i.occurred_on, i.kind, i.user?.name || '', i.to_user?.name || '', i.amount, i.description || '']));
    rows.push([], ['Member', 'Pot', 'Paid', 'Fair share', 'Balance']);
    s.value.members.forEach((m) => rows.push([m.name, m.contributed, m.paid_expenses, m.fair_share, m.balance]));
    const csv = rows.map((r) => r.map((v) => `"${String(v ?? '').replace(/"/g, '""')}"`).join(',')).join('\n');
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }));
    a.download = `${plan.value.name.replace(/[^\w-]+/g, '-')}-${today()}.csv`;
    a.click();
}

const initials = (n) => n.split(' ').map((p) => p[0]).slice(0, 2).join('').toUpperCase();
const COLORS = ['#6366f1', '#ec4899', '#10b981', '#f59e0b', '#0ea5e9', '#a855f7', '#ef4444', '#14b8a6'];
const color = (id) => COLORS[id % COLORS.length];
</script>

<template>
    <div>
        <GoalTabs />
        <div v-if="!plan" class="h-64 animate-pulse rounded-3xl bg-slate-200 dark:bg-slate-800" />

        <div v-else class="space-y-4">
            <!-- Header -->
            <section class="rounded-3xl bg-gradient-to-br from-emerald-500 to-teal-600 p-5 text-white">
                <div class="flex items-start gap-3">
                    <span class="flex h-14 w-14 items-center justify-center rounded-2xl bg-white/20 text-3xl">{{ plan.icon }}</span>
                    <div class="min-w-0 flex-1">
                        <h1 class="truncate text-xl font-bold">{{ plan.name }}</h1>
                        <p class="text-sm text-emerald-50">
                            {{ isGroup ? '👥 Group' : '🔒 Private' }}<template v-if="plan.target_date"> · {{ niceDate(plan.target_date, { weekday: 'short', month: 'short', day: 'numeric' }) }}</template>
                        </p>
                    </div>
                    <button v-if="plan.is_owner" class="rounded-full bg-white/15 p-2" aria-label="Edit plan" @click="openEdit"><Icon name="edit" size="18" /></button>
                </div>
                <p v-if="plan.description" class="mt-3 text-sm text-emerald-50">{{ plan.description }}</p>

                <div v-if="plan.target_amount" class="mt-4">
                    <div class="mb-1 flex justify-between text-sm"><span><b class="text-lg">{{ money(s.contributed) }}</b> of {{ money(plan.target_amount) }}</span><span>{{ s.percent }}%</span></div>
                    <div class="h-2.5 overflow-hidden rounded-full bg-white/25"><div class="h-full rounded-full bg-white" :style="{ width: s.percent + '%' }" /></div>
                    <p class="mt-1.5 text-xs text-emerald-50">
                        {{ s.remaining > 0 ? `${money(s.remaining)} to go` : 'Target reached! 🎉' }}<template v-if="isGroup && s.members.length > 1 && s.remaining > 0"> · {{ money(s.remaining / s.members.length) }} each</template>
                    </p>
                </div>
                <div class="mt-4 grid grid-cols-3 gap-2 text-center text-sm">
                    <div class="rounded-xl bg-white/15 p-2"><p class="font-bold">{{ money(s.contributed) }}</p><p class="text-[11px] text-emerald-50">saved</p></div>
                    <div class="rounded-xl bg-white/15 p-2"><p class="font-bold">{{ money(s.spent) }}</p><p class="text-[11px] text-emerald-50">spent</p></div>
                    <div class="rounded-xl bg-white/15 p-2"><p class="font-bold">{{ money(s.share_per_person) }}</p><p class="text-[11px] text-emerald-50">{{ isGroup ? 'per person' : 'spent so far' }}</p></div>
                </div>
            </section>

            <!-- Fair share -->
            <section v-if="plan.fair" class="card">
                <div class="flex items-start gap-3">
                    <Mascot :mood="plan.fair.gap > 0 ? 'thinking' : 'proud'" :size="52" />
                    <div class="min-w-0 flex-1">
                        <h2 class="font-semibold">Fair share</h2>
                        <p class="text-sm text-slate-500">Split by what each person can comfortably give. Everyone's amount stays private.</p>
                    </div>
                </div>
                <div class="mt-3 grid grid-cols-2 gap-2 text-center">
                    <div class="rounded-xl bg-emerald-50 p-3 dark:bg-emerald-950/40"><p class="text-xl font-bold">{{ plan.fair.me.paused ? 'Paused' : money(plan.fair.me.suggested) }}</p><p class="text-xs text-slate-500">your share / month</p></div>
                    <div class="rounded-xl bg-slate-50 p-3 dark:bg-slate-800/60"><p class="text-xl font-bold">{{ money(plan.fair.monthly_need) }}</p><p class="text-xs text-slate-500">group needs / month · {{ plan.fair.months_left }} mo left</p></div>
                </div>
                <p v-if="plan.fair.paused_count" class="mt-2 text-xs text-slate-500">🌙 {{ plan.fair.paused_count }} member{{ plan.fair.paused_count === 1 ? ' is' : 's are' }} taking a breather this month. Who it is stays private.</p>
                <p v-if="plan.fair.gap > 0" class="mt-2 rounded-xl bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
                    Together you can comfortably cover {{ money(plan.fair.covered) }} of {{ money(plan.fair.monthly_need) }} a month. Instead of asking anyone for more, a realistic date is <b>{{ plan.fair.suggested_date ? niceDate(plan.fair.suggested_date, { month: 'short', year: 'numeric' }) : 'later' }}</b>.
                </p>
                <form class="mt-3 flex items-end gap-2" @submit.prevent="saveCapacity">
                    <div class="flex-1">
                        <label class="label" for="cap">I can comfortably give per month (only you see this)</label>
                        <input id="cap" v-model="capacity" type="number" min="0" inputmode="decimal" class="input" placeholder="No limit" />
                    </div>
                    <button class="btn-ghost shrink-0" :disabled="busy">Save</button>
                </form>
                <button class="mt-2 w-full text-center text-xs font-medium text-indigo-600" :disabled="busy" @click="togglePause">{{ plan.fair.me.paused ? 'Resume my share' : 'Pause me this month (anonymous)' }}</button>
            </section>

            <!-- Members -->
            <section class="card !p-3">
                <div class="flex items-center gap-2">
                    <div class="flex -space-x-2">
                        <span v-for="m in s.members.slice(0, 6)" :key="m.id" class="flex h-9 w-9 items-center justify-center rounded-full text-xs font-bold text-white ring-2 ring-white dark:ring-slate-900" :style="{ background: color(m.id) }" :title="m.name">{{ initials(m.name) }}</span>
                    </div>
                    <p class="flex-1 text-sm text-slate-500">{{ s.members.length }} member{{ s.members.length === 1 ? '' : 's' }}<template v-if="plan.invites.length"> · {{ plan.invites.length }} invited</template></p>
                    <button v-if="isGroup" class="btn-primary !px-3 !py-2 text-sm" @click="inviteOpen = true"><Icon name="plus" size="16" />Invite</button>
                    <button v-else-if="plan.is_owner" class="btn-ghost !px-3 !py-2 text-sm" @click="makeGroup">👥 Make it a group</button>
                </div>
            </section>

            <!-- Tabs -->
            <nav class="no-scrollbar flex gap-1 overflow-x-auto rounded-2xl bg-slate-100 p-1 text-sm font-medium dark:bg-slate-900">
                <button v-for="t in [['money', 'Money'], ...(isGroup ? [['chat', 'Chat'], ['split', 'Split']] : []), ['tasks', 'To-do'], ['notes', 'Notes'], ['calendar', 'Dates'], ['people', isGroup ? 'People' : 'Settings']]" :key="t[0]" class="shrink-0 grow rounded-xl px-3 py-2 transition" :class="tab === t[0] ? 'bg-white shadow-sm dark:bg-slate-800' : 'text-slate-500'" @click="tab = t[0]">{{ t[1] }}</button>
            </nav>

            <!-- Money -->
            <section v-if="tab === 'money'" class="space-y-3">
                <div class="grid grid-cols-2 gap-2">
                    <button class="btn-primary" @click="openItem('contribution')">🐷 Add money</button>
                    <button class="btn-ghost" @click="openItem('expense')">🧾 Add expense</button>
                </div>
                <button v-if="plan.items.length" class="w-full text-center text-xs font-medium text-indigo-600" @click="exportCsv">⬇ Export to CSV for the group chat</button>
                <p v-if="me && isGroup" class="text-center text-xs text-slate-500">You've put in {{ money(me.contributed) }} and paid {{ money(me.paid_expenses) }} in expenses.</p>
                <div v-if="!plan.items.length" class="card py-8 text-center text-sm text-slate-500">Nothing yet. Add money to the pot, or log something someone paid for.</div>
                <ul v-else class="card divide-y divide-slate-100 !p-0 dark:divide-slate-800">
                    <li v-for="i in plan.items" :key="i.id" class="flex items-center gap-3 px-4 py-3">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-xs font-bold text-white" :style="{ background: color(i.user_id) }">{{ initials(i.user.name) }}</span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium">{{ itemTitle(i) }}</p>
                            <p class="text-xs text-slate-500">{{ i.kind === 'expense' ? `Paid by ${who(i.user)} · ` : (i.description ? i.description + ' · ' : '') }}{{ niceDate(i.occurred_on) }}</p>
                        </div>
                        <span class="text-sm font-semibold" :class="{ 'text-emerald-600': i.kind === 'contribution', 'text-slate-400': i.kind === 'settlement' }">{{ i.kind === 'contribution' ? '+' : '' }}{{ money(i.amount) }}</span>
                        <button v-if="i.mine || plan.is_owner" class="p-1 text-slate-400 hover:text-rose-600" aria-label="Remove" @click="removeItem(i)"><Icon name="trash" size="16" /></button>
                    </li>
                </ul>
            </section>

            <!-- Split -->
            <PlanChat v-if="tab === 'chat'" :plan-id="props.id" />
            <PlanNotes v-if="tab === 'notes'" :plan-id="props.id" :owner-id="plan.owner_id" />
            <PlanCalendar v-if="tab === 'calendar'" :plan-id="props.id" :owner-id="plan.owner_id" :target-date="plan.target_date" :plan-name="plan.name" />

            <section v-if="tab === 'split'" class="space-y-3">
                <div class="card">
                    <h3 class="font-semibold">Settle up</h3>
                    <p class="mb-3 text-xs text-slate-500">Shared expenses ({{ money(s.spent) }}) split equally: {{ money(s.share_per_person) }} each. Pay each other however you like (cash, GCash, Maya, bank), then mark it here. The app never moves money.</p>
                    <p v-if="!s.settlements.length" class="rounded-xl bg-emerald-50 p-3 text-sm text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200">✅ Everyone is even. Nothing to settle.</p>
                    <ul class="space-y-2">
                        <li v-for="(t, k) in s.settlements" :key="k" class="flex items-center gap-2 rounded-xl p-3 text-sm" :class="t.from_id === auth.user.id ? 'bg-amber-50 dark:bg-amber-950/40' : t.to_id === auth.user.id ? 'bg-emerald-50 dark:bg-emerald-950/40' : 'bg-slate-50 dark:bg-slate-800/60'">
                            <div class="min-w-0 flex-1">
                                <p><b>{{ t.from_id === auth.user.id ? 'You' : t.from }}</b> <span class="text-slate-500">pay{{ t.from_id === auth.user.id ? '' : 's' }}</span> <b>{{ t.to_id === auth.user.id ? 'you' : t.to }}</b></p>
                                <p v-if="t.to_payment_handle && t.from_id === auth.user.id" class="truncate text-xs text-slate-500">Send to: {{ t.to_payment_handle }}</p>
                            </div>
                            <span class="font-bold">{{ money(t.amount) }}</span>
                            <button v-if="[t.from_id, t.to_id].includes(auth.user.id)" class="rounded-lg bg-white px-2 py-1 text-xs font-medium ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-700" :disabled="busy" @click="markSettled(t)">{{ t.to_id === auth.user.id ? 'Got it' : 'Mark paid' }}</button>
                        </li>
                    </ul>
                </div>
                <div class="card overflow-x-auto !p-0">
                    <table class="w-full text-sm">
                        <thead class="text-xs text-slate-500"><tr><th class="p-3 text-left font-medium">Member</th><th class="p-3 text-right font-medium">Pot</th><th class="p-3 text-right font-medium">Paid</th><th class="p-3 text-right font-medium">Balance</th></tr></thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <tr v-for="m in s.members" :key="m.id">
                                <td class="p-3">{{ m.id === auth.user.id ? 'You' : m.name }}</td>
                                <td class="p-3 text-right">{{ money(m.contributed) }}</td>
                                <td class="p-3 text-right">{{ money(m.paid_expenses) }}</td>
                                <td class="p-3 text-right font-semibold" :class="m.balance > 0 ? 'text-emerald-600' : m.balance < 0 ? 'text-rose-600' : ''">{{ m.balance > 0 ? '+' : '' }}{{ money(m.balance) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- Checklist -->
            <section v-if="tab === 'tasks'" class="space-y-3">
                <form class="card space-y-2 !p-3" @submit.prevent="addTask">
                    <input v-model="task.title" class="input" placeholder="Add a to-do, e.g. Book the cottage" maxlength="200" aria-label="Task" />
                    <div class="flex gap-2">
                        <input v-model="task.estimated_cost" type="number" inputmode="decimal" min="0" class="input" placeholder="Est. cost" aria-label="Estimated cost" />
                        <select v-if="isGroup" v-model="task.assignee_id" class="input" aria-label="Assign to">
                            <option :value="null">Anyone</option>
                            <option v-for="m in s.members" :key="m.id" :value="m.id">{{ m.id === auth.user.id ? 'Me' : m.name }}</option>
                        </select>
                        <button class="btn-primary shrink-0" :disabled="!task.title.trim() || busy">Add</button>
                    </div>
                </form>
                <p v-if="taskCost" class="text-center text-xs text-slate-500">Estimated total {{ money(taskCost) }}<template v-if="plan.target_amount"> vs target {{ money(plan.target_amount) }}<span v-if="taskCost > plan.target_amount" class="text-rose-600"> (over by {{ money(taskCost - plan.target_amount) }})</span></template></p>
                <ul class="card divide-y divide-slate-100 !p-0 dark:divide-slate-800">
                    <li v-if="!plan.tasks.length" class="p-6 text-center text-sm text-slate-500">No to-dos yet.</li>
                    <li v-for="t in plan.tasks" :key="t.id" class="flex items-center gap-3 px-4 py-3">
                        <button class="flex h-6 w-6 shrink-0 items-center justify-center rounded-lg ring-2" :class="t.done ? 'bg-emerald-500 text-white ring-emerald-500' : 'ring-slate-300 dark:ring-slate-600'" :aria-label="t.done ? 'Mark not done' : 'Mark done'" @click="toggleTask(t)"><Icon v-if="t.done" name="check" size="14" /></button>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm" :class="t.done && 'text-slate-400 line-through'">{{ t.title }}</p>
                            <p class="text-xs text-slate-500"><template v-if="t.assignee">{{ t.assignee.id === auth.user.id ? 'You' : t.assignee.name }}</template><template v-if="t.assignee && t.estimated_cost"> · </template><template v-if="t.estimated_cost">~{{ money(t.estimated_cost) }}</template></p>
                        </div>
                        <button class="p-1 text-slate-400 hover:text-rose-600" aria-label="Delete task" @click="removeTask(t)"><Icon name="trash" size="16" /></button>
                    </li>
                </ul>
            </section>

            <!-- People / settings -->
            <section v-if="tab === 'people'" class="space-y-3">
                <ul v-if="isGroup" class="card divide-y divide-slate-100 !p-0 dark:divide-slate-800">
                    <li v-for="m in s.members" :key="m.id" class="flex items-center gap-3 px-4 py-3">
                        <span class="flex h-9 w-9 items-center justify-center rounded-full text-xs font-bold text-white" :style="{ background: color(m.id) }">{{ initials(m.name) }}</span>
                        <p class="flex-1 text-sm">{{ m.name }}<span v-if="m.id === auth.user.id" class="text-slate-500"> (you)</span><span v-if="m.role === 'owner'" class="ml-1 rounded-full bg-indigo-100 px-1.5 py-0.5 text-[10px] font-semibold text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300">OWNER</span></p>
                        <template v-if="plan.is_owner && m.role !== 'owner'">
                            <button class="text-xs font-medium text-indigo-600" @click="makeOwner(m)">Make owner</button>
                            <button class="text-xs font-medium text-rose-600" @click="removeMember(m)">Remove</button>
                        </template>
                    </li>
                    <li v-for="i in plan.invites" :key="'i' + i.id" class="flex items-center gap-3 px-4 py-3 text-sm text-slate-500">
                        <span class="flex h-9 w-9 items-center justify-center rounded-full bg-slate-100 dark:bg-slate-800">✉️</span>
                        <span class="flex-1 truncate">{{ i.email }} · invited</span>
                        <button class="text-xs font-medium text-rose-600" @click="cancelInvite(i)">Cancel</button>
                    </li>
                </ul>
                <div class="card space-y-2">
                    <button v-if="plan.is_owner" class="btn-ghost w-full justify-start" @click="openEdit"><Icon name="edit" size="18" />Edit plan & privacy</button>
                    <button v-if="!plan.is_owner" class="btn-danger w-full justify-start" @click="leave"><Icon name="logout" size="18" />Leave plan</button>
                    <button v-if="plan.is_owner" class="btn-danger w-full justify-start" @click="destroy"><Icon name="trash" size="18" />Delete plan for everyone</button>
                </div>
            </section>
        </div>

        <!-- Invite sheet -->
        <Sheet :open="inviteOpen && isGroup" title="Invite people" @close="inviteOpen = false">
            <template v-if="plan">
                <div class="flex flex-col items-center">
                    <QrCode :value="plan.join_url" />
                    <p class="mt-3 text-xs text-slate-500">Scan with any phone camera, or enter the code</p>
                    <p class="mt-1 font-mono text-2xl font-bold tracking-[0.3em]">{{ plan.invite_code }}</p>
                </div>
                <div class="mt-4 grid gap-2" :class="canShare ? 'grid-cols-2' : 'grid-cols-1'">
                    <button class="btn-ghost" @click="copyLink">🔗 Copy link</button>
                    <button v-if="canShare" class="btn-ghost" @click="share">📤 Share</button>
                </div>
                <div v-if="buddyChoices.length" class="mt-5">
                    <p class="label">Your Amotan buddies</p>
                    <div class="flex flex-wrap gap-2">
                        <button v-for="b in buddyChoices" :key="b.id" type="button" class="chip ring-1" :class="picked.includes(b.id) ? 'bg-indigo-600 text-white ring-indigo-600' : 'ring-slate-200 dark:ring-slate-700'" @click="togglePick(b.id)">{{ b.name }}</button>
                    </div>
                    <button class="btn-primary mt-2 w-full" :disabled="!picked.length || busy" @click="inviteBuddies">Invite {{ picked.length || '' }} {{ picked.length === 1 ? 'buddy' : 'buddies' }}</button>
                </div>
                <p v-else class="mt-5 text-xs text-slate-500">Tip: scan a friend's <RouterLink to="/me/qr" class="font-medium text-indigo-600">Amotan QR</RouterLink> to make them a buddy, then invite them here in one tap.</p>
                <form class="mt-5" @submit.prevent="sendInvites">
                    <label class="label" for="ie">Or invite by email</label>
                    <div class="flex gap-2">
                        <input id="ie" v-model="emails" class="input" placeholder="ana@mail.com, ben@mail.com" />
                        <button class="btn-primary shrink-0" :disabled="!emails.trim() || busy">Invite</button>
                    </div>
                    <p class="mt-1 text-xs text-slate-500">They'll see the invitation when they log in with that email, or after they sign up.</p>
                </form>
                <button v-if="plan.is_owner" class="mt-4 w-full text-center text-xs font-medium text-slate-500 underline" @click="resetCode">Reset code (stops old QR codes and links)</button>
            </template>
        </Sheet>

        <!-- Add money / expense -->
        <Sheet :open="itemOpen" :title="item.kind === 'contribution' ? 'Add money to the pot' : 'Add a shared expense'" @close="itemOpen = false">
            <form class="space-y-3" @submit.prevent="saveItem">
                <input v-model="item.amount" type="number" inputmode="decimal" min="0.01" step="0.01" class="input !py-3 text-2xl font-semibold" placeholder="0.00" required aria-label="Amount" />
                <input v-model="item.description" class="input" :placeholder="item.kind === 'contribution' ? 'Note (optional)' : 'What was it? e.g. Gas, snacks'" maxlength="255" aria-label="Description" />
                <input v-model="item.occurred_on" type="date" class="input" aria-label="Date" />
                <label class="flex items-start gap-2 text-sm">
                    <input v-model="item.record_personal" type="checkbox" class="mt-0.5 h-4 w-4 accent-indigo-600" />
                    <span>Also record it in my own transactions<br /><span class="text-xs text-slate-500">Keeps your personal balance and budget accurate.</span></span>
                </label>
                <p v-if="item.kind === 'expense' && isGroup" class="rounded-xl bg-slate-50 p-3 text-xs text-slate-500 dark:bg-slate-800/60">Expenses are split equally between all {{ s.members.length }} members. Check the Split tab to see who owes whom.</p>
                <button class="btn-primary w-full" :disabled="busy">Save</button>
            </form>
        </Sheet>

        <!-- Edit plan (owner) -->
        <Sheet :open="editOpen" title="Edit plan" @close="editOpen = false">
            <form class="space-y-3" @submit.prevent="saveEdit">
                <div class="flex gap-2">
                    <input v-model="edit.icon" class="input !w-16 text-center text-xl" maxlength="4" aria-label="Icon" />
                    <input v-model="edit.name" class="input" required maxlength="100" aria-label="Name" />
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div><label class="label" for="et">Target</label><input id="et" v-model="edit.target_amount" type="number" inputmode="decimal" min="1" class="input" /></div>
                    <div><label class="label" for="ed">Date</label><input id="ed" v-model="edit.target_date" type="date" class="input" /></div>
                </div>
                <textarea v-model="edit.description" rows="2" class="input" placeholder="Notes" aria-label="Notes" />
                <div>
                    <span class="label">Privacy</span>
                    <div class="grid grid-cols-2 gap-2">
                        <button type="button" class="chip ring-1" :class="edit.visibility === 'private' ? 'bg-indigo-600 text-white ring-indigo-600' : 'ring-slate-200 dark:ring-slate-700'" @click="edit.visibility = 'private'">🔒 Private</button>
                        <button type="button" class="chip ring-1" :class="edit.visibility === 'group' ? 'bg-indigo-600 text-white ring-indigo-600' : 'ring-slate-200 dark:ring-slate-700'" @click="edit.visibility = 'group'">👥 Group</button>
                    </div>
                    <p v-if="edit.visibility === 'private' && s.members.length > 1" class="mt-1 text-xs text-amber-600">Remove the other members first to make it private.</p>
                </div>
                <button class="btn-primary w-full" :disabled="busy">Save</button>
            </form>
        </Sheet>
    </div>
</template>
