<script setup>
import { ref, nextTick, onMounted, computed } from 'vue';
import { api } from '../api';
import { useUi } from '../stores/ui';
import { useAuth } from '../stores/auth';
import { money, niceDate, pref, setPref } from '../format';
import { answer, invalidateContext } from '../ai/assistant';
import { ai, llmReady } from '../ai/engine';
import Icon from '../components/Icon.vue';
import AiModelCard from '../components/AiModelCard.vue';

const ui = useUi();
const auth = useAuth();
const historyKey = `chat_${auth.user?.id}`;
const messages = ref(pref(historyKey, []));
const input = ref('');
const busy = ref(false);
const scroller = ref(null);
const showModel = ref(!llmReady());

const suggestions = computed(() => {
    const c = auth.user?.currency === 'USD' ? '$' : '';
    return [
        'How much can I spend today?',
        `Can I afford a ${c}120 jacket?`,
        'Where did my money go this month?',
        'Am I on track with my budget?',
        `Spent ${c}14.50 on lunch at Chipotle`,
        'When will I reach my goals?',
        'Plan my next paycheck',
        'Give me tips to save more',
    ];
});

function persist() {
    setPref(historyKey, messages.value.slice(-60).map(({ streaming, ...m }) => m));
}

async function scrollDown() {
    await nextTick();
    scroller.value?.scrollTo({ top: scroller.value.scrollHeight, behavior: 'smooth' });
}

async function send(text = input.value) {
    text = text.trim();
    if (!text || busy.value) return;
    input.value = '';
    busy.value = true;
    const history = messages.value.filter((m) => !m.action).slice(-6);
    messages.value.push({ role: 'user', text, at: Date.now() });
    const reply = { role: 'assistant', text: '', streaming: true, at: Date.now() };
    messages.value.push(reply);
    const idx = messages.value.length - 1;
    scrollDown();
    try {
        const res = await answer(text, history, {
            onToken: (t) => {
                messages.value[idx].text = t;
                scrollDown();
            },
        });
        Object.assign(messages.value[idx], { text: res.text, action: res.action, link: res.link, ai: res.ai, streaming: false });
    } catch (e) {
        Object.assign(messages.value[idx], { text: navigator.onLine ? `Sorry, something went wrong: ${e.message}` : "You're offline, so I can't fetch your latest numbers. Try again when you're back online.", streaming: false });
    } finally {
        busy.value = false;
        persist();
        scrollDown();
    }
}

async function confirmLog(m) {
    try {
        const { category, ...draft } = m.action.draft;
        const saved = await api.post('/transactions', draft);
        m.action.done = true;
        m.text = `✅ Logged ${money(saved.amount)} under ${saved.category?.icon || ''} ${saved.category?.name || 'Other'}.`;
        invalidateContext();
        ui.changed();
        persist();
    } catch (e) { ui.error(e); }
}

function editLog(m) {
    const { category, ...draft } = m.action.draft;
    ui.openAdd({ ...draft, amount: String(draft.amount), merchant: draft.merchant || '' });
    m.action.done = true;
    m.text = 'Opened the form so you can adjust it.';
    persist();
}

function cancelLog(m) {
    m.action.done = true;
    m.text = 'No problem, nothing was logged.';
    persist();
}

function clearChat() {
    messages.value = [];
    persist();
}

onMounted(scrollDown);
</script>

<template>
    <div class="flex h-[calc(100dvh-3.5rem-4rem-env(safe-area-inset-top)-env(safe-area-inset-bottom))] flex-col md:h-[calc(100dvh-4rem)]">
        <div class="flex items-center justify-between pb-2">
            <button class="flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium" :class="llmReady() ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300' : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300'" @click="showModel = !showModel">
                <Icon name="cpu" size="14" />{{ llmReady() ? (ai.backend === 'server' ? 'Self-hosted AI' : 'On-device AI') : ai.status === 'loading' ? `Loading AI ${ai.progress}%` : 'Instant mode' }}
            </button>
            <button v-if="messages.length" class="text-xs font-medium text-slate-500" @click="clearChat">Clear chat</button>
        </div>

        <AiModelCard v-if="showModel" compact class="mb-3" />

        <div ref="scroller" class="-mx-4 flex-1 space-y-3 overflow-y-auto px-4 pb-4 md:-mx-2 md:px-2">
            <div v-if="!messages.length" class="pt-4 text-center">
                <div class="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-gradient-to-br from-indigo-500 to-violet-600 text-white"><Icon name="sparkles" size="28" /></div>
                <h2 class="text-lg font-semibold">Hi, I'm Penny</h2>
                <p class="mx-auto max-w-xs text-sm text-slate-500">Ask me about your spending, savings and whether you can afford something, or just tell me what you spent.</p>
                <div class="mt-5 flex flex-wrap justify-center gap-2">
                    <button v-for="s in suggestions" :key="s" class="chip bg-white text-left ring-1 ring-slate-200 hover:ring-indigo-400 dark:bg-slate-900 dark:ring-slate-700" @click="send(s)">{{ s }}</button>
                </div>
            </div>

            <div v-for="(m, i) in messages" :key="i" class="flex" :class="m.role === 'user' ? 'justify-end' : 'justify-start'">
                <div class="max-w-[85%] rounded-3xl px-4 py-2.5 text-[15px] leading-relaxed" :class="m.role === 'user' ? 'rounded-br-lg bg-indigo-600 text-white' : 'rounded-bl-lg bg-white ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800'">
                    <p v-if="m.streaming && !m.text" class="flex gap-1 py-1.5"><span v-for="n in 3" :key="n" class="h-2 w-2 animate-bounce rounded-full bg-slate-400" :style="{ animationDelay: n * 120 + 'ms' }" /></p>
                    <p v-else class="whitespace-pre-line">{{ m.text }}</p>

                    <div v-if="m.action?.kind === 'log' && !m.action.done" class="mt-3 grid grid-cols-3 gap-2">
                        <button class="btn-primary !px-2 !py-1.5 text-xs" @click="confirmLog(m)"><Icon name="check" size="14" />Log it</button>
                        <button class="btn-ghost !px-2 !py-1.5 text-xs" @click="editLog(m)">Edit</button>
                        <button class="btn-ghost !px-2 !py-1.5 text-xs" @click="cancelLog(m)">Cancel</button>
                    </div>
                    <RouterLink v-if="m.link && !m.streaming" :to="m.link.to" class="mt-2 inline-flex items-center gap-1 text-sm font-medium text-indigo-600 dark:text-indigo-400">{{ m.link.label }}<Icon name="chevronRight" size="14" /></RouterLink>
                    <p v-if="m.role === 'assistant' && !m.streaming" class="mt-1 text-[10px] text-slate-400">{{ m.ai ? '✨ AI · on your device' : '⚡ Built-in answer' }}</p>
                </div>
            </div>
        </div>

        <form class="flex items-end gap-2 border-t border-slate-200 bg-slate-50 pt-3 pb-3 dark:border-slate-800 dark:bg-slate-950" @submit.prevent="send()">
            <textarea v-model="input" rows="1" class="input max-h-32 resize-none !rounded-2xl" placeholder='Ask anything or "spent 12 on lunch"' aria-label="Message" @keydown.enter.exact.prevent="send()" />
            <button class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-indigo-600 text-white disabled:opacity-40" :disabled="!input.trim() || busy" aria-label="Send"><Icon name="send" size="20" /></button>
        </form>
    </div>
</template>
