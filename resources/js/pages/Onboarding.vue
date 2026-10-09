<script setup>
import { reactive, ref, computed } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '../api';
import { useAuth } from '../stores/auth';
import { useUi } from '../stores/ui';
import { money, today, pref, setPref } from '../format';
import Icon from '../components/Icon.vue';
import AiModelCard from '../components/AiModelCard.vue';

const auth = useAuth();
const ui = useUi();
const router = useRouter();
const step = ref(0);
const busy = ref(false);
const result = ref(null);

const CURRENCIES = ['USD', 'EUR', 'GBP', 'PHP', 'INR', 'AUD', 'CAD', 'SGD', 'JPY', 'NGN', 'ZAR', 'MXN', 'BRL', 'IDR', 'MYR', 'AED'];
const guessCurrency = () => {
    try {
        const region = new Intl.Locale(navigator.language).maximize().region;
        return { US: 'USD', GB: 'GBP', PH: 'PHP', IN: 'INR', AU: 'AUD', CA: 'CAD', SG: 'SGD', JP: 'JPY', NG: 'NGN', ZA: 'ZAR', MX: 'MXN', BR: 'BRL', ID: 'IDR', MY: 'MYR', AE: 'AED' }[region] || 'EUR';
    } catch { return 'USD'; }
};

const profile = reactive({
    currency: auth.user.currency !== 'USD' ? auth.user.currency : guessCurrency(),
    monthly_income: auth.user.monthly_income || '',
    pay_frequency: auth.user.pay_frequency || 'monthly',
    next_payday: auth.user.next_payday || '',
    current_balance: '',
});
const bills = ref([{ name: 'Rent', amount: '', due_day: 1, is_debt: false }]);
const goal = reactive({ name: 'Emergency fund', icon: '🛟', target_amount: '', monthly_contribution: '' });

const steps = ['Income', 'Bills', 'Goal', 'AI'];
const canNext = computed(() => [profile.monthly_income > 0 && profile.next_payday && profile.current_balance !== '', true, true, true][step.value]);

const addBill = () => bills.value.push({ name: '', amount: '', due_day: 1, is_debt: false });

const nextPath = pref('after_onboarding', null);
function goNext() {
    setPref('after_onboarding', null);
    router.replace(nextPath || '/');
}

async function finish() {
    busy.value = true;
    try {
        for (const b of bills.value.filter((b) => b.name && Number(b.amount) > 0)) {
            await api.post('/bills', { ...b, amount: Number(b.amount) });
        }
        if (goal.name && Number(goal.target_amount) > 0) {
            await api.post('/goals', { ...goal, target_amount: Number(goal.target_amount), monthly_contribution: Number(goal.monthly_contribution) || null, priority: 1 });
        }
        const res = await api.post('/onboard', {
            ...profile,
            monthly_income: Number(profile.monthly_income),
            current_balance: Number(profile.current_balance),
        });
        auth.user = res.user;
        result.value = res.budget;
        step.value = 4;
    } catch (e) {
        ui.error(e);
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="safe-top safe-bottom mx-auto flex min-h-dvh max-w-lg flex-col px-5 py-6">
        <div v-if="step < 4" class="mb-6">
            <div class="mb-3 flex gap-1.5">
                <div v-for="(s, i) in steps" :key="s" class="h-1.5 flex-1 rounded-full" :class="i <= step ? 'bg-indigo-600' : 'bg-slate-200 dark:bg-slate-800'" />
            </div>
            <p class="text-sm text-slate-500">Step {{ step + 1 }} of {{ steps.length }}</p>
        </div>

        <!-- Step 1: income -->
        <section v-if="step === 0" class="flex-1 space-y-4">
            <h1 class="text-2xl font-bold">Hi {{ auth.user.name.split(' ')[0] }} 👋 Let's plan your money.</h1>
            <p class="text-slate-500">Your answers personalise your budget and payday plan. You can change them any time.</p>
            <div class="grid grid-cols-3 gap-3">
                <div>
                    <label class="label" for="cur">Currency</label>
                    <select id="cur" v-model="profile.currency" class="input"><option v-for="c in CURRENCIES" :key="c">{{ c }}</option></select>
                </div>
                <div class="col-span-2">
                    <label class="label" for="inc">Take-home pay per month</label>
                    <input id="inc" v-model="profile.monthly_income" type="number" inputmode="decimal" min="0" class="input" placeholder="e.g. 3200" />
                </div>
            </div>
            <div>
                <span class="label">How often are you paid?</span>
                <div class="grid grid-cols-2 gap-2">
                    <button v-for="f in [['weekly', 'Weekly'], ['biweekly', 'Every 2 weeks'], ['semimonthly', 'Twice a month'], ['monthly', 'Monthly']]" :key="f[0]" type="button" class="chip ring-1"
                        :class="profile.pay_frequency === f[0] ? 'bg-indigo-600 text-white ring-indigo-600' : 'ring-slate-200 dark:ring-slate-700'" @click="profile.pay_frequency = f[0]">{{ f[1] }}</button>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="label" for="pd">Next payday</label>
                    <input id="pd" v-model="profile.next_payday" type="date" :min="today()" class="input" />
                </div>
                <div>
                    <label class="label" for="bal">Money you have now</label>
                    <input id="bal" v-model="profile.current_balance" type="number" inputmode="decimal" class="input" placeholder="Bank + cash" />
                </div>
            </div>
        </section>

        <!-- Step 2: bills -->
        <section v-if="step === 1" class="flex-1 space-y-4">
            <h1 class="text-2xl font-bold">What bills and debts do you pay each month?</h1>
            <p class="text-slate-500">Rent, utilities, phone, subscriptions, loan or card payments. These get set aside first.</p>
            <div v-for="(b, i) in bills" :key="i" class="card space-y-3">
                <div class="flex gap-2">
                    <input v-model="b.name" class="input" placeholder="Name, e.g. Electricity" aria-label="Bill name" />
                    <button class="rounded-xl px-2 text-slate-400" aria-label="Remove" @click="bills.splice(i, 1)"><Icon name="trash" size="18" /></button>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <input v-model="b.amount" type="number" inputmode="decimal" class="input" :placeholder="`Amount (${profile.currency})`" aria-label="Amount" />
                    <select v-model.number="b.due_day" class="input" aria-label="Due day"><option v-for="d in 31" :key="d" :value="d">Due on day {{ d }}</option></select>
                </div>
                <label class="flex items-center gap-2 text-sm"><input v-model="b.is_debt" type="checkbox" class="h-4 w-4 accent-indigo-600" />This is a loan or credit card payment</label>
            </div>
            <button class="btn-ghost w-full" @click="addBill"><Icon name="plus" size="18" />Add another</button>
        </section>

        <!-- Step 3: goal -->
        <section v-if="step === 2" class="flex-1 space-y-4">
            <h1 class="text-2xl font-bold">What are you saving for?</h1>
            <p class="text-slate-500">Most people start with an emergency fund of 1–3 months of expenses. You can add more goals later.</p>
            <div class="card space-y-3">
                <div class="flex gap-2">
                    <input v-model="goal.icon" class="input !w-16 text-center text-xl" maxlength="4" aria-label="Icon" />
                    <input v-model="goal.name" class="input" placeholder="Goal name" aria-label="Goal name" />
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <input v-model="goal.target_amount" type="number" inputmode="decimal" class="input" placeholder="Target amount" aria-label="Target amount" />
                    <input v-model="goal.monthly_contribution" type="number" inputmode="decimal" class="input" placeholder="Save per month" aria-label="Monthly saving" />
                </div>
            </div>
            <p class="text-sm text-slate-500">Skip this step if you'd rather set goals later.</p>
        </section>

        <!-- Step 4: AI -->
        <section v-if="step === 3" class="flex-1 space-y-4">
            <h1 class="text-2xl font-bold">Meet your private AI assistant</h1>
            <p class="text-slate-500">It answers questions like “Can I afford this?” and logs expenses from chat. The model runs on <b>this device</b>, so your finances never go to an AI company.</p>
            <AiModelCard />
            <p class="text-sm text-slate-500">You can keep going while it downloads, or skip and set it up later in Settings.</p>
        </section>

        <!-- Done -->
        <section v-if="step === 4 && result" class="flex-1 space-y-4">
            <div class="text-5xl">🎉</div>
            <h1 class="text-2xl font-bold">Your budget is ready</h1>
            <p class="text-slate-500">Based on {{ money(result.income) }}/month:</p>
            <div class="grid grid-cols-3 gap-2 text-center">
                <div class="card !p-3"><p class="text-xs text-slate-500">Needs</p><p class="font-bold">{{ money(result.split.need, { compact: true }) }}</p></div>
                <div class="card !p-3"><p class="text-xs text-slate-500">Wants</p><p class="font-bold">{{ money(result.split.want, { compact: true }) }}</p></div>
                <div class="card !p-3"><p class="text-xs text-slate-500">Savings</p><p class="font-bold text-emerald-600">{{ money(result.split.savings, { compact: true }) }}</p></div>
            </div>
            <div v-for="n in result.notes" :key="n" class="rounded-2xl bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950/40 dark:text-amber-200">💡 {{ n }}</div>
            <button class="btn-primary w-full !py-3" @click="goNext">{{ nextPath ? 'Continue to your invite' : 'Go to my dashboard' }}</button>
        </section>

        <div v-if="step < 4" class="mt-6 flex gap-3">
            <button v-if="step > 0" class="btn-ghost flex-1" @click="step--">Back</button>
            <button v-if="step < 3" class="btn-primary flex-[2]" :disabled="!canNext" @click="step++">Continue</button>
            <button v-else class="btn-primary flex-[2]" :disabled="busy" @click="finish">{{ busy ? 'Building your plan…' : 'Build my budget' }}</button>
        </div>
    </div>
</template>
