<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { api } from '../api';
import { useUi } from '../stores/ui';
import { useAuth } from '../stores/auth';
import { sync } from '../sync';

const props = defineProps({ planId: [String, Number], ownerId: Number });
const ui = useUi();
const auth = useAuth();
const notes = ref([]);
const draft = reactive({ title: '', body: '' });
const open = ref(null);

const path = computed(() => `/plans/${props.planId}/notes`);
const waiting = computed(() => sync.pending.filter((p) => p.method === 'POST' && p.path === path.value));
const canDelete = (n) => n.user_id === auth.user.id || props.ownerId === auth.user.id;

async function load() { try { const r = await api.get(path.value); if (Array.isArray(r)) notes.value = r; } catch {} }
onMounted(load);

async function add() {
    if (!draft.title.trim()) return;
    try {
        const res = await api.post(path.value, { ...draft });
        Object.assign(draft, { title: '', body: '' });
        ui.toast('Note added');
        if (!res?.queued) load();
    } catch (e) { ui.error(e); }
}
async function pin(n) { try { await api.patch(`${path.value}/${n.id}`, { pinned: !n.pinned }); load(); } catch (e) { ui.error(e); } }
async function save(n) { try { await api.patch(`${path.value}/${n.id}`, { title: n.title, body: n.body }); open.value = null; ui.toast('Note saved'); load(); } catch (e) { ui.error(e); } }
async function remove(n) { if (!confirm('Delete this note?')) return; try { await api.del(`${path.value}/${n.id}`); load(); ui.toast('Note deleted'); } catch (e) { ui.error(e); } }
</script>

<template>
    <div class="space-y-3">
        <form class="card space-y-2" @submit.prevent="add">
            <input v-model="draft.title" class="input" maxlength="120" placeholder="Note title (e.g. Packing list)" aria-label="Note title" />
            <textarea v-model="draft.body" class="input min-h-20" placeholder="Write something for the group…" aria-label="Note"></textarea>
            <button class="btn-primary w-full" :disabled="!draft.title.trim()">Add note</button>
        </form>
        <div v-for="p in waiting" :key="p.id" class="card border-l-4 border-sky-400">
            <p class="font-semibold">{{ p.body?.title }}</p>
            <p class="text-xs text-sky-600">☁️ saves to the group when online</p>
        </div>
        <p v-if="!notes.length && !waiting.length" class="card text-center text-sm text-slate-500">No notes yet. Keep packing lists, addresses and ideas here.</p>
        <article v-for="n in notes" :key="n.id" class="card" :class="n.pinned ? 'ring-amber-300 dark:ring-amber-700' : ''">
            <template v-if="open === n.id">
                <input v-model="n.title" class="input mb-2" maxlength="120" aria-label="Note title" />
                <textarea v-model="n.body" class="input min-h-28" aria-label="Note"></textarea>
                <div class="mt-2 flex gap-2"><button class="btn-primary flex-1" @click="save(n)">Save</button><button class="btn-ghost" @click="open = null; load()">Cancel</button></div>
            </template>
            <template v-else>
                <div class="flex items-start gap-2">
                    <h3 class="flex-1 font-semibold">{{ n.pinned ? '📌 ' : '' }}{{ n.title }}</h3>
                    <button class="text-xs text-slate-500" @click="pin(n)">{{ n.pinned ? 'Unpin' : 'Pin' }}</button>
                    <button class="text-xs text-indigo-600" @click="open = n.id">Edit</button>
                    <button v-if="canDelete(n)" class="text-xs text-rose-600" @click="remove(n)">Delete</button>
                </div>
                <p v-if="n.body" class="mt-1 whitespace-pre-wrap text-sm text-slate-600 dark:text-slate-300">{{ n.body }}</p>
                <p class="mt-2 text-[11px] text-slate-400">{{ n.user?.name }} · {{ new Date(n.updated_at).toLocaleDateString() }}</p>
            </template>
        </article>
    </div>
</template>
