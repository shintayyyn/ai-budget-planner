<script setup>
import { ref, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '../api';
import { useUi } from '../stores/ui';
import { money, niceDate } from '../format';

const props = defineProps({ code: String });
const router = useRouter();
const ui = useUi();
const plan = ref(null);
const error = ref('');
const busy = ref(false);

onMounted(async () => {
    try {
        plan.value = await api.get(`/join/${props.code}`);
        if (plan.value.already_member) router.replace(`/plans/${plan.value.id}`);
    } catch (e) { error.value = e.message; }
});

async function join() {
    busy.value = true;
    try {
        const p = await api.post(`/join/${props.code}`);
        ui.toast(`You joined ${p.name} 🎉`);
        router.replace(`/plans/${p.id}`);
    } catch (e) { ui.error(e); } finally { busy.value = false; }
}
</script>

<template>
    <div class="mx-auto max-w-md pt-6">
        <div v-if="error" class="card py-10 text-center">
            <p class="text-4xl">🔗</p>
            <p class="mt-2 font-medium">{{ error }}</p>
            <p class="text-sm text-slate-500">Ask the organiser for a fresh QR code or invite code.</p>
            <RouterLink to="/goals/together" class="btn-ghost mt-4">Back</RouterLink>
        </div>
        <div v-else-if="!plan" class="h-64 animate-pulse rounded-3xl bg-slate-200 dark:bg-slate-800" />
        <div v-else class="card p-6 text-center">
            <p class="text-5xl">{{ plan.icon }}</p>
            <p class="mt-3 text-sm text-slate-500">{{ plan.owner }} invited you to</p>
            <h1 class="text-2xl font-bold">{{ plan.name }}</h1>
            <p v-if="plan.description" class="mt-2 text-sm text-slate-600 dark:text-slate-300">{{ plan.description }}</p>
            <div class="mt-4 flex justify-center gap-4 text-sm text-slate-500">
                <span>👥 {{ plan.members_count }} member{{ plan.members_count === 1 ? '' : 's' }}</span>
                <span v-if="plan.target_amount">🎯 {{ money(plan.target_amount, { currency: plan.currency }) }}</span>
                <span v-if="plan.target_date">📅 {{ niceDate(plan.target_date, { month: 'short', day: 'numeric' }) }}</span>
            </div>
            <p class="mt-4 rounded-xl bg-slate-50 p-3 text-xs text-slate-500 dark:bg-slate-800/60">Members can see each other's names, contributions and shared expenses in this plan, but never your personal budget, balance or other transactions.</p>
            <button class="btn-primary mt-5 w-full !py-3" :disabled="busy" @click="join">Join plan</button>
        </div>
    </div>
</template>
