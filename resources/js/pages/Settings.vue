<script setup>
import { reactive, ref, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '../api';
import { useAuth } from '../stores/auth';
import { useUi } from '../stores/ui';
import { pref, setPref, applyTheme, today } from '../format';
import { ai, MODELS, setBackend, loadModel, unloadModel, deleteModel, refreshCacheInfo, recommendedModel, detectWebGPU, checkServer } from '../ai/engine';
import { invalidateContext } from '../ai/assistant';
import Icon from '../components/Icon.vue';
import Sheet from '../components/Sheet.vue';

const auth = useAuth();
const ui = useUi();
const router = useRouter();

const profile = reactive({
    name: auth.user.name,
    currency: auth.user.currency,
    monthly_income: auth.user.monthly_income,
    pay_frequency: auth.user.pay_frequency,
    next_payday: auth.user.next_payday,
    current_balance: auth.user.current_balance,
    alert_threshold: auth.user.alert_threshold,
    payment_handle: auth.user.payment_handle || '',
});
const theme = ref(pref('theme', 'system'));
const storage = ref(null);
const categories = ref([]);
const catForm = reactive({ id: null, name: '', icon: '💸', kind: 'want', keywords: '' });
const catOpen = ref(false);
const deleteOpen = ref(false);
const deletePw = ref('');

onMounted(async () => {
    await detectWebGPU();
    refreshCacheInfo();
    checkServer();
    categories.value = await api.get('/categories');
    if (navigator.storage?.estimate) storage.value = await navigator.storage.estimate();
});

async function saveProfile() {
    try {
        await auth.update({ ...profile, monthly_income: Number(profile.monthly_income), current_balance: Number(profile.current_balance), alert_threshold: Number(profile.alert_threshold) });
        invalidateContext();
        ui.changed();
        ui.toast('Settings saved');
    } catch (e) { ui.error(e); }
}

function setTheme(t) {
    theme.value = t;
    setPref('theme', t);
    applyTheme(t);
}

async function useModel(base) {
    ai.model = base;
    setPref('ai_model', base);
    await loadModel(base);
}

async function removeModel(base) {
    if (!confirm('Remove this model from the device? You can download it again later.')) return;
    await deleteModel(base);
    if (navigator.storage?.estimate) storage.value = await navigator.storage.estimate();
}

function editCategory(c) {
    Object.assign(catForm, c ? { ...c, keywords: (c.keywords || []).join(', ') } : { id: null, name: '', icon: '💸', kind: 'want', keywords: '' });
    catOpen.value = true;
}

async function saveCategory() {
    const payload = { ...catForm, keywords: catForm.keywords.split(',').map((k) => k.trim()).filter(Boolean) };
    try {
        catForm.id ? await api.put(`/categories/${catForm.id}`, payload) : await api.post('/categories', payload);
        categories.value = await api.get('/categories');
        catOpen.value = false;
        invalidateContext();
    } catch (e) { ui.error(e); }
}

async function deleteCategory() {
    if (!confirm(`Delete ${catForm.name}? Its transactions become uncategorised.`)) return;
    await api.del(`/categories/${catForm.id}`);
    categories.value = await api.get('/categories');
    catOpen.value = false;
}

async function exportCsv() {
    const rows = [['Date', 'Type', 'Amount', 'Category', 'Description', 'Merchant', 'Source']];
    let page = 1;
    let last = 1;
    do {
        const res = await api.get('/transactions', { page, per_page: 100 });
        res.data.forEach((t) => rows.push([t.occurred_on, t.type, t.amount, t.category?.name || '', t.description || '', t.merchant || '', t.source]));
        last = res.last_page;
        page++;
    } while (page <= last);
    const csv = rows.map((r) => r.map((v) => `"${String(v).replace(/"/g, '""')}"`).join(',')).join('\n');
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }));
    a.download = `budget-transactions-${today()}.csv`;
    a.click();
}

async function logout() {
    await unloadModel();
    await auth.logout();
    router.replace('/login');
}

async function deleteAccount() {
    try {
        await api.del('/profile', { password: deletePw.value });
        auth.clear();
        router.replace('/register');
    } catch (e) { ui.error(e); }
}

const gb = (b) => (b / 1024 ** 3).toFixed(2);
</script>

<template>
    <div class="space-y-5">
        <!-- Profile -->
        <section class="card">
            <h2 class="mb-3 font-semibold">Money settings</h2>
            <form class="grid grid-cols-2 gap-3" @submit.prevent="saveProfile">
                <div class="col-span-2"><label class="label" for="sn">Name</label><input id="sn" v-model="profile.name" class="input" /></div>
                <div><label class="label" for="sc">Currency</label><input id="sc" v-model="profile.currency" maxlength="3" class="input uppercase" /></div>
                <div><label class="label" for="si">Monthly take-home pay</label><input id="si" v-model="profile.monthly_income" type="number" inputmode="decimal" class="input" /></div>
                <div>
                    <label class="label" for="sf">Pay frequency</label>
                    <select id="sf" v-model="profile.pay_frequency" class="input">
                        <option value="weekly">Weekly</option><option value="biweekly">Every 2 weeks</option><option value="semimonthly">Twice a month</option><option value="monthly">Monthly</option>
                    </select>
                </div>
                <div><label class="label" for="sp">Next payday</label><input id="sp" v-model="profile.next_payday" type="date" class="input" /></div>
                <div>
                    <label class="label" for="sb">Current balance</label>
                    <input id="sb" v-model="profile.current_balance" type="number" inputmode="decimal" class="input" />
                </div>
                <div>
                    <label class="label" for="st">Warn me at (% of budget)</label>
                    <input id="st" v-model="profile.alert_threshold" type="number" min="50" max="100" class="input" />
                </div>
                <div class="col-span-2">
                    <label class="label" for="ph">How friends can pay you back (optional)</label>
                    <input id="ph" v-model="profile.payment_handle" maxlength="120" class="input" placeholder="e.g. GCash 0917 123 4567 · Maya · bank details" />
                    <p class="mt-1 text-xs text-slate-500">Shown only to members of your group plans when they owe you.</p>
                </div>
                <p class="col-span-2 text-xs text-slate-500">Your balance updates automatically as you add transactions. Correct it here if it drifts from your bank.</p>
                <button class="btn-primary col-span-2">Save</button>
            </form>
        </section>

        <!-- AI -->
        <section class="card">
            <h2 class="flex items-center gap-2 font-semibold"><Icon name="cpu" size="20" />AI assistant</h2>
            <p class="mb-3 text-sm text-slate-500">Choose where the assistant's language model runs. Your numbers are always calculated by the app itself.</p>
            <div class="grid gap-2">
                <label v-for="b in [
                    { id: 'device', title: 'On this device (recommended)', text: 'WebLLM + WebGPU in your browser. Private and works offline after a one-time download.' },
                    { id: 'server', title: 'Self-hosted (Ollama)', text: ai.serverAvailable ? `Connected: ${ai.serverModel}` : 'Runs on your own server. Set AI_SERVER_DRIVER=ollama in .env.' },
                    { id: 'off', title: 'Instant mode', text: 'No language model. Rule-based answers on any device, even very old ones.' },
                ]" :key="b.id" class="flex cursor-pointer gap-3 rounded-xl p-3 ring-1" :class="ai.backend === b.id ? 'bg-indigo-50 ring-indigo-500 dark:bg-indigo-950/40' : 'ring-slate-200 dark:ring-slate-800'">
                    <input type="radio" name="backend" :value="b.id" :checked="ai.backend === b.id" class="mt-1 accent-indigo-600" @change="setBackend(b.id)" />
                    <span><span class="block text-sm font-medium">{{ b.title }}</span><span class="text-xs text-slate-500">{{ b.text }}</span></span>
                </label>
            </div>

            <template v-if="ai.backend === 'device'">
                <p v-if="ai.webgpu && !ai.webgpu.supported" class="mt-4 rounded-xl bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950/40 dark:text-amber-200">This browser doesn't support WebGPU, so the assistant uses instant mode here. Use Chrome or Edge on desktop or Android, or Safari 18+ on iPhone, for the full on-device AI.</p>
                <ul v-else class="mt-4 space-y-2">
                    <li v-for="m in MODELS" :key="m.base" class="rounded-xl bg-slate-50 p-3 dark:bg-slate-800/60">
                        <div class="flex items-center gap-2">
                            <p class="flex-1 text-sm font-medium">{{ m.label }} <span class="font-normal text-slate-500">· {{ m.size }}</span>
                                <span v-if="m.base === recommendedModel()" class="ml-1 rounded-full bg-indigo-100 px-1.5 py-0.5 text-[10px] font-semibold text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300">BEST FOR THIS DEVICE</span>
                            </p>
                            <span v-if="ai.model === m.base && ai.status === 'ready'" class="text-xs font-semibold text-emerald-600">● Running</span>
                        </div>
                        <p class="text-xs text-slate-500">{{ m.note }}</p>
                        <div v-if="ai.status === 'loading' && ai.model === m.base" class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700"><div class="h-full bg-indigo-600" :style="{ width: ai.progress + '%' }" /></div>
                        <div class="mt-2 flex gap-2">
                            <button v-if="!(ai.model === m.base && ai.status === 'ready')" class="btn-ghost !px-3 !py-1.5 text-xs" :disabled="ai.status === 'loading'" @click="useModel(m.base)">
                                <Icon :name="ai.cached[m.base] ? 'sparkles' : 'download'" size="14" />{{ ai.cached[m.base] ? 'Use' : 'Download & use' }}
                            </button>
                            <button v-else class="btn-ghost !px-3 !py-1.5 text-xs" @click="unloadModel()">Stop</button>
                            <button v-if="ai.cached[m.base]" class="btn-danger !px-3 !py-1.5 text-xs" @click="removeModel(m.base)"><Icon name="trash" size="14" />Remove</button>
                        </div>
                    </li>
                </ul>
                <p v-if="ai.status === 'error'" class="mt-2 text-sm text-rose-600">{{ ai.error }}</p>
                <p v-if="storage" class="mt-3 text-xs text-slate-500">App storage on this device: {{ gb(storage.usage) }} GB used of {{ gb(storage.quota) }} GB available.</p>
            </template>
        </section>

        <!-- Appearance -->
        <section class="card">
            <h2 class="mb-3 font-semibold">Appearance</h2>
            <div class="grid grid-cols-3 gap-2">
                <button v-for="t in ['system', 'light', 'dark']" :key="t" class="chip capitalize ring-1" :class="theme === t ? 'bg-indigo-600 text-white ring-indigo-600' : 'ring-slate-200 dark:ring-slate-700'" @click="setTheme(t)">{{ t }}</button>
            </div>
        </section>

        <!-- Categories -->
        <section class="card">
            <div class="mb-2 flex items-center justify-between">
                <h2 class="font-semibold">Categories</h2>
                <button class="text-sm font-medium text-indigo-600" @click="editCategory(null)">+ Add</button>
            </div>
            <p class="mb-3 text-xs text-slate-500">Keywords teach the app (and the AI) how to auto-categorise your expenses.</p>
            <div class="flex flex-wrap gap-2">
                <button v-for="c in categories" :key="c.id" class="chip bg-slate-100 dark:bg-slate-800" @click="editCategory(c)">{{ c.icon }} {{ c.name }}</button>
            </div>
        </section>

        <!-- Data & account -->
        <section class="card space-y-2">
            <h2 class="mb-1 font-semibold">Data & account</h2>
            <button class="btn-ghost w-full justify-start" @click="exportCsv"><Icon name="download" size="18" />Export transactions (CSV)</button>
            <button class="btn-ghost w-full justify-start" @click="logout"><Icon name="logout" size="18" />Log out</button>
            <button class="btn-danger w-full justify-start" @click="deleteOpen = true"><Icon name="trash" size="18" />Delete account</button>
            <p class="pt-2 text-center text-xs text-slate-400">AI Budget Planner · signed in as {{ auth.user.email }}<br />Not financial advice. For investment or tax decisions, talk to a licensed professional.</p>
        </section>

        <Sheet :open="catOpen" :title="catForm.id ? 'Edit category' : 'New category'" @close="catOpen = false">
            <form class="space-y-3" @submit.prevent="saveCategory">
                <div class="flex gap-2">
                    <input v-model="catForm.icon" class="input !w-16 text-center text-xl" maxlength="4" aria-label="Icon" />
                    <input v-model="catForm.name" class="input" placeholder="Name" required aria-label="Name" />
                </div>
                <div>
                    <label class="label" for="ck">Type</label>
                    <select id="ck" v-model="catForm.kind" class="input"><option value="need">Need</option><option value="want">Want</option><option value="savings">Savings</option><option value="income">Income</option></select>
                </div>
                <div>
                    <label class="label" for="ckw">Keywords (comma separated)</label>
                    <input id="ckw" v-model="catForm.keywords" class="input" placeholder="e.g. netflix, spotify" />
                </div>
                <button class="btn-primary w-full">Save</button>
                <button v-if="catForm.id" type="button" class="btn-danger w-full" @click="deleteCategory">Delete</button>
            </form>
        </Sheet>

        <Sheet :open="deleteOpen" title="Delete account" @close="deleteOpen = false">
            <p class="mb-3 text-sm text-slate-500">This permanently deletes your account and all budgets, transactions, goals and receipts. It cannot be undone.</p>
            <input v-model="deletePw" type="password" class="input" placeholder="Confirm with your password" autocomplete="current-password" aria-label="Password" />
            <button class="btn-danger mt-3 w-full" :disabled="!deletePw" @click="deleteAccount">Delete everything</button>
        </Sheet>
    </div>
</template>
