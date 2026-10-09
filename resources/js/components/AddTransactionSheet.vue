<script setup>
import { ref, reactive, watch, computed } from 'vue';
import Sheet from './Sheet.vue';
import Icon from './Icon.vue';
import { api } from '../api';
import { useUi } from '../stores/ui';
import { today } from '../format';
import { parseTransaction } from '../ai/parse';
import { scanReceipt } from '../ai/receipt';
import { invalidateContext } from '../ai/assistant';

const props = defineProps({ open: Boolean, editing: Object });
const emit = defineEmits(['close', 'saved']);
const ui = useUi();

const categories = ref([]);
const saving = ref(false);
const errors = ref({});
const quick = ref('');
const scan = reactive({ busy: false, status: '', progress: 0, preview: null, file: null });
const fileInput = ref(null);

const blank = () => ({ type: 'expense', amount: '', category_id: null, description: '', merchant: '', occurred_on: today(), source: 'manual', receipt_path: null });
const form = reactive(blank());

const visibleCats = computed(() => categories.value.filter((c) => (form.type === 'income' ? c.kind === 'income' : c.kind !== 'income')));

watch(() => props.open, async (open) => {
    if (!open) return;
    Object.assign(form, blank(), props.editing ? { ...props.editing, amount: String(props.editing.amount) } : {}, ui.addPreset || {});
    errors.value = {};
    quick.value = '';
    Object.assign(scan, { busy: false, status: '', progress: 0, preview: null, file: null });
    if (!categories.value.length) categories.value = await api.get('/categories');
    if (ui.addPreset?.scan) setTimeout(() => fileInput.value?.click(), 150);
});

function applyQuick() {
    const draft = parseTransaction(quick.value, categories.value);
    if (!draft) return ui.toast('Include an amount, e.g. "lunch 12.50 at Chipotle"', 'error');
    Object.assign(form, { type: draft.type, amount: String(draft.amount), category_id: draft.category_id, description: draft.description, merchant: draft.merchant || '', occurred_on: draft.occurred_on });
    quick.value = '';
}

async function onFile(e) {
    const file = e.target.files?.[0];
    e.target.value = '';
    if (!file) return;
    scan.busy = true;
    scan.file = file;
    scan.preview = URL.createObjectURL(file);
    scan.status = 'Loading the on-device scanner';
    try {
        const { draft } = await scanReceipt(file, categories.value, (m) => {
            scan.status = m.status ? m.status[0].toUpperCase() + m.status.slice(1) : '';
            scan.progress = Math.round((m.progress || 0) * 100);
        });
        Object.assign(form, {
            type: 'expense',
            amount: draft.amount ? String(draft.amount) : '',
            merchant: draft.merchant || '',
            description: draft.description || '',
            occurred_on: draft.occurred_on,
            category_id: draft.category_id,
            source: 'receipt',
        });
        ui.toast(draft.amount ? 'Receipt read. Check the details and save.' : "Couldn't find the total. Please enter it.", draft.amount ? 'success' : 'error');
    } catch (err) {
        console.error(err);
        ui.toast('Could not read that image. Try a clearer photo.', 'error');
    } finally {
        scan.busy = false;
    }
}

async function save() {
    saving.value = true;
    errors.value = {};
    try {
        if (scan.file && !form.receipt_path) {
            const fd = new FormData();
            fd.append('receipt', scan.file);
            form.receipt_path = (await api.post('/transactions/receipt', fd)).receipt_path;
        }
        const payload = { ...form, amount: Number(form.amount) };
        const saved = props.editing ? await api.put(`/transactions/${props.editing.id}`, payload) : await api.post('/transactions', payload);
        invalidateContext();
        ui.changed();
        ui.toast(props.editing ? 'Transaction updated' : `${form.type === 'income' ? 'Income' : 'Expense'} saved`);
        emit('saved', saved);
        emit('close');
    } catch (e) {
        errors.value = e.errors || {};
        if (!e.errors) ui.error(e);
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <Sheet :open="open" :title="editing ? 'Edit transaction' : 'Add transaction'" @close="emit('close')">
        <div class="mb-4 grid grid-cols-2 gap-1 rounded-2xl bg-slate-100 p-1 text-sm font-semibold dark:bg-slate-800">
            <button v-for="t in ['expense', 'income']" :key="t" class="rounded-xl py-2 capitalize transition" :class="form.type === t ? 'bg-white shadow-sm dark:bg-slate-900' : 'text-slate-500'" @click="form.type = t; form.category_id = null">{{ t }}</button>
        </div>

        <div v-if="!editing" class="mb-4 flex gap-2">
            <form class="flex flex-1 gap-2" @submit.prevent="applyQuick">
                <input v-model="quick" class="input" placeholder='Quick add: "coffee 4.50 at Starbucks"' aria-label="Quick add" />
            </form>
            <button class="btn-ghost shrink-0 !px-3" :disabled="scan.busy" title="Scan a receipt" aria-label="Scan a receipt" @click="fileInput.click()"><Icon name="camera" size="20" /></button>
            <input ref="fileInput" type="file" accept="image/*" capture="environment" class="hidden" @change="onFile" />
        </div>

        <div v-if="scan.preview" class="mb-4 flex items-center gap-3 rounded-2xl bg-slate-50 p-3 dark:bg-slate-800/60">
            <img :src="scan.preview" class="h-16 w-12 rounded-lg object-cover" alt="Receipt preview" />
            <div class="min-w-0 flex-1 text-sm">
                <p class="font-medium">{{ scan.busy ? scan.status : 'Receipt scanned on this device' }}</p>
                <div v-if="scan.busy" class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700"><div class="h-full bg-indigo-600 transition-all" :style="{ width: scan.progress + '%' }" /></div>
                <p v-else class="text-slate-500">The photo is attached when you save.</p>
            </div>
        </div>

        <form class="space-y-4" @submit.prevent="save">
            <div>
                <label class="label" for="amt">Amount</label>
                <input id="amt" v-model="form.amount" type="number" inputmode="decimal" step="0.01" min="0" class="input !py-3 text-2xl font-semibold" placeholder="0.00" required />
                <p v-if="errors.amount" class="mt-1 text-xs text-rose-600">{{ errors.amount[0] }}</p>
            </div>

            <div>
                <span class="label">Category <span class="font-normal">(we'll pick one if you skip this)</span></span>
                <div class="grid grid-cols-4 gap-2">
                    <button v-for="c in visibleCats" :key="c.id" type="button" class="flex flex-col items-center gap-1 rounded-xl p-2 text-[11px] leading-tight ring-1 transition"
                        :class="form.category_id === c.id ? 'bg-indigo-50 ring-indigo-500 dark:bg-indigo-950/50' : 'ring-slate-200 dark:ring-slate-800'"
                        @click="form.category_id = form.category_id === c.id ? null : c.id">
                        <span class="text-xl">{{ c.icon }}</span><span class="line-clamp-1">{{ c.name }}</span>
                    </button>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div class="col-span-2">
                    <label class="label" for="desc">Description</label>
                    <input id="desc" v-model="form.description" class="input" placeholder="What was it for?" maxlength="255" />
                </div>
                <div>
                    <label class="label" for="merch">Merchant</label>
                    <input id="merch" v-model="form.merchant" class="input" placeholder="Optional" maxlength="255" />
                </div>
                <div>
                    <label class="label" for="date">Date</label>
                    <input id="date" v-model="form.occurred_on" type="date" class="input" required />
                </div>
            </div>

            <button class="btn-primary w-full !py-3" :disabled="saving || scan.busy">{{ saving ? 'Saving…' : 'Save' }}</button>
        </form>
    </Sheet>
</template>
