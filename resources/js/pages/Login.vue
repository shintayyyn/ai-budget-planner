<script setup>
import { reactive, ref, computed } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { useAuth } from '../stores/auth';
import { setPref } from '../format';
import Icon from '../components/Icon.vue';
import Mascot from '../components/Mascot.vue';

const props = defineProps({ mode: { type: String, default: 'login' } });
const auth = useAuth();
const router = useRouter();
const route = useRoute();
const form = reactive({ name: '', email: '', password: '', password_confirmation: '' });
const showPw = ref(false);
const touched = reactive({});
const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
const NAME = /^[\p{L}][\p{L} .'-]*$/u;
const pwRules = computed(() => [
    { ok: form.password.length >= 8, text: 'At least 8 characters' },
    { ok: /[A-Za-z]/.test(form.password), text: 'A letter' },
    { ok: /\d/.test(form.password), text: 'A number' },
    { ok: form.password.length <= 72, text: 'At most 72 characters' },
]);
const problems = computed(() => {
    const p = {};
    const email = form.email.trim();
    if (!EMAIL.test(email) || email.length > 255) p.email = 'Enter a valid email like juan@gmail.com';
    if (isRegister.value) {
        const name = form.name.trim();
        if (name.length < 2 || name.length > 60) p.name = 'Name must be 2 to 60 characters';
        else if (!NAME.test(name)) p.name = 'Use letters, spaces, dots, hyphens or apostrophes only';
        if (!pwRules.value.every((r) => r.ok)) p.password = 'Password does not meet the rules below';
        if (form.password_confirmation !== form.password) p.password_confirmation = "Passwords don't match";
    } else if (!form.password) p.password = 'Enter your password';
    return p;
});
const valid = computed(() => !Object.keys(problems.value).length);
const err = (k) => errors.value[k]?.[0] || (touched[k] && problems.value[k]) || '';
const errors = ref({});
const busy = ref(false);
const isRegister = computed(() => props.mode === 'register');

async function submit() {
    Object.assign(touched, { name: true, email: true, password: true, password_confirmation: true });
    if (!valid.value) return;
    busy.value = true;
    errors.value = {};
    try {
        isRegister.value ? await auth.register({ ...form, name: form.name.trim(), email: form.email.trim() }) : await auth.login(form.email.trim(), form.password);
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
            <div class="mb-4 flex items-end gap-3 md:justify-center">
                <Mascot :mood="isRegister ? 'excited' : 'wave'" :size="84" bob />
                <p class="pb-2 text-2xl font-extrabold tracking-tight">Amotan</p>
            </div>
            <h1 class="text-3xl font-bold tracking-tight">Make it to payday,<br />every time.</h1>
            <p class="mt-2 text-indigo-100">Your offline-first money buddy. Works with no signal, syncs when you're online.</p>
        </div>
        <div class="safe-bottom flex-1 rounded-t-3xl bg-white px-6 pt-8 pb-8 md:w-full md:max-w-md md:flex-none md:rounded-3xl dark:bg-slate-900">
            <h2 class="mb-5 text-xl font-semibold">{{ isRegister ? 'Create your account' : 'Welcome back' }}</h2>
            <form class="space-y-4" @submit.prevent="submit">
                <div v-if="isRegister">
                    <label class="label" for="name">Name</label>
                    <input id="name" v-model="form.name" class="input" autocomplete="name" maxlength="60" required :aria-invalid="!!err('name')" @blur="touched.name = true" />
                    <p v-if="err('name')" class="mt-1 text-xs text-rose-600">{{ err('name') }}</p>
                </div>
                <div>
                    <label class="label" for="email">Email</label>
                    <input id="email" autocapitalize="none" autocorrect="off" spellcheck="false" v-model="form.email" type="email" class="input" autocomplete="email" maxlength="255" inputmode="email" required :aria-invalid="!!err('email')" @blur="touched.email = true" />
                    <p v-if="err('email')" class="mt-1 text-xs text-rose-600">{{ err('email') }}</p>
                </div>
                <div>
                    <label class="label" for="pw">Password</label>
                    <div class="relative">
                        <input id="pw" v-model="form.password" :type="showPw ? 'text' : 'password'" class="input pr-12" :autocomplete="isRegister ? 'new-password' : 'current-password'" maxlength="72" required :aria-invalid="!!err('password')" @blur="touched.password = true" />
                        <button type="button" class="absolute inset-y-0 right-0 flex w-12 items-center justify-center text-slate-500 hover:text-indigo-600" :aria-label="showPw ? 'Hide password' : 'Show password'" :aria-pressed="showPw" @click="showPw = !showPw">
                            <svg v-if="!showPw" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z" /><circle cx="12" cy="12" r="3" /></svg>
                            <svg v-else width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 19c-6.5 0-10-7-10-7a18.5 18.5 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c6.5 0 10 7 10 7a18.5 18.5 0 0 1-2.16 3.19M14.12 14.12a3 3 0 1 1-4.24-4.24" /><path d="M1 1l22 22" /></svg>
                        </button>
                    </div>
                    <p v-if="err('password')" class="mt-1 text-xs text-rose-600">{{ err('password') }}</p>
                    <ul v-if="isRegister && form.password" class="mt-2 grid grid-cols-2 gap-1 text-xs" aria-live="polite">
                        <li v-for="r in pwRules" :key="r.text" :class="r.ok ? 'text-emerald-600' : 'text-slate-400'">{{ r.ok ? '✓' : '○' }} {{ r.text }}</li>
                    </ul>
                </div>
                <div v-if="isRegister">
                    <label class="label" for="pw2">Confirm password</label>
                    <input id="pw2" v-model="form.password_confirmation" :type="showPw ? 'text' : 'password'" class="input" autocomplete="new-password" maxlength="72" required :aria-invalid="!!err('password_confirmation')" @blur="touched.password_confirmation = true" />
                    <p v-if="err('password_confirmation')" class="mt-1 text-xs text-rose-600">{{ err('password_confirmation') }}</p>
                </div>
                <button class="btn-primary w-full !py-3" :disabled="busy || (isRegister && !valid)">{{ busy ? 'Please wait…' : isRegister ? 'Create account' : 'Log in' }}</button>
            </form>
            <p class="mt-6 text-center text-sm text-slate-500">
                <template v-if="isRegister">Already have an account? <RouterLink :to="{ path: '/login', query: route.query }" class="font-semibold text-indigo-600">Log in</RouterLink></template>
                <template v-else>New here? <RouterLink :to="{ path: '/register', query: route.query }" class="font-semibold text-indigo-600">Create an account</RouterLink></template>
            </p>
            <p class="mt-8 flex items-center justify-center gap-1.5 text-xs text-slate-400"><Icon name="shield" size="14" />Your AI conversations never leave your device.</p>
        </div>
    </div>
</template>
