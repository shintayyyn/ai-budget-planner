<script setup>
import { reactive, ref, computed } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { useAuth } from '../stores/auth';
import { setPref } from '../format';
import Icon from '../components/Icon.vue';

const props = defineProps({ mode: { type: String, default: 'login' } });
const auth = useAuth();
const router = useRouter();
const route = useRoute();
const form = reactive({ name: '', email: '', password: '' });
const errors = ref({});
const busy = ref(false);
const isRegister = computed(() => props.mode === 'register');

async function submit() {
    busy.value = true;
    errors.value = {};
    try {
        isRegister.value ? await auth.register(form) : await auth.login(form.email, form.password);
        if (route.query.next) setPref('after_onboarding', route.query.next);
        router.replace(auth.user.onboarded ? (route.query.next || '/') : '/welcome');
    } catch (e) {
        errors.value = e.errors && Object.keys(e.errors).length ? e.errors : { email: [e.message] };
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="safe-top flex min-h-dvh flex-col bg-gradient-to-b from-indigo-600 to-violet-700 md:items-center md:justify-center md:p-6">
        <div class="px-6 pt-14 pb-10 text-white md:pt-0 md:text-center">
            <img src="/icons/icon-192.png" class="mb-5 h-14 w-14 rounded-2xl ring-4 ring-white/20 md:mx-auto" alt="" />
            <h1 class="text-3xl font-bold tracking-tight">Make it to payday,<br />every time.</h1>
            <p class="mt-2 text-indigo-100">Budgeting with a private AI that runs on your own device.</p>
        </div>
        <div class="safe-bottom flex-1 rounded-t-3xl bg-white px-6 pt-8 pb-8 md:w-full md:max-w-md md:flex-none md:rounded-3xl dark:bg-slate-900">
            <h2 class="mb-5 text-xl font-semibold">{{ isRegister ? 'Create your account' : 'Welcome back' }}</h2>
            <form class="space-y-4" @submit.prevent="submit">
                <div v-if="isRegister">
                    <label class="label" for="name">Name</label>
                    <input id="name" v-model="form.name" class="input" autocomplete="name" required />
                    <p v-if="errors.name" class="mt-1 text-xs text-rose-600">{{ errors.name[0] }}</p>
                </div>
                <div>
                    <label class="label" for="email">Email</label>
                    <input id="email" v-model="form.email" type="email" class="input" autocomplete="email" required />
                    <p v-if="errors.email" class="mt-1 text-xs text-rose-600">{{ errors.email[0] }}</p>
                </div>
                <div>
                    <label class="label" for="pw">Password</label>
                    <input id="pw" v-model="form.password" type="password" class="input" :autocomplete="isRegister ? 'new-password' : 'current-password'" :minlength="isRegister ? 8 : undefined" required />
                    <p v-if="errors.password" class="mt-1 text-xs text-rose-600">{{ errors.password[0] }}</p>
                </div>
                <button class="btn-primary w-full !py-3" :disabled="busy">{{ busy ? 'Please wait…' : isRegister ? 'Create account' : 'Log in' }}</button>
            </form>
            <p class="mt-6 text-center text-sm text-slate-500">
                <template v-if="isRegister">Already have an account? <RouterLink :to="{ path: '/login', query: route.query }" class="font-semibold text-indigo-600">Log in</RouterLink></template>
                <template v-else>New here? <RouterLink :to="{ path: '/register', query: route.query }" class="font-semibold text-indigo-600">Create an account</RouterLink></template>
            </p>
            <p class="mt-8 flex items-center justify-center gap-1.5 text-xs text-slate-400"><Icon name="shield" size="14" />Your AI conversations never leave your device.</p>
        </div>
    </div>
</template>
