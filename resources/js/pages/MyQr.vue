<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import QRCode from 'qrcode';
import { api } from '../api';
import { useAuth } from '../stores/auth';
import { useUi } from '../stores/ui';
import { sync } from '../sync';
import { mascotSvg } from '../mascot';
import { buddyLink, parseBuddyCode } from '../qr';
import QrCode from '../components/QrCode.vue';
import QrScanSheet from '../components/QrScanSheet.vue';
import Mascot from '../components/Mascot.vue';
import Icon from '../components/Icon.vue';
import GoalTabs from '../components/GoalTabs.vue';

const auth = useAuth();
const ui = useUi();
const router = useRouter();
const code = computed(() => auth.user?.share_code);
const link = computed(() => code.value && buddyLink(code.value));
const buddies = ref([]);
const friendCode = ref('');
const scanOpen = ref(false);
const canScan = 'BarcodeDetector' in window && !!navigator.mediaDevices;

async function loadBuddies() {
    try { buddies.value = await api.get('/connections'); } catch {}
}
onMounted(loadBuddies);

const loadImage = (src) => new Promise((resolve, reject) => { const img = new Image(); img.onload = () => resolve(img); img.onerror = reject; img.src = src; });

/** Draws a shareable card (mascot + QR + code) on the device, no server involved. */
async function cardFile() {
    const W = 720, H = 1000;
    const c = document.createElement('canvas');
    c.width = W; c.height = H;
    const ctx = c.getContext('2d');
    const bg = ctx.createLinearGradient(0, 0, W, H);
    bg.addColorStop(0, '#6366f1'); bg.addColorStop(1, '#7c3aed');
    ctx.fillStyle = bg; ctx.fillRect(0, 0, W, H);
    ctx.fillStyle = '#fff';
    ctx.beginPath(); ctx.roundRect ? ctx.roundRect(50, 190, W - 100, H - 250, 48) : ctx.rect(50, 190, W - 100, H - 250); ctx.fill();

    const svgUrl = (mood) => 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(mascotSvg(mood, `card-${mood}`));
    ctx.drawImage(await loadImage(svgUrl('celebrate')), W / 2 - 110, 20, 220, 220);

    ctx.textAlign = 'center';
    ctx.fillStyle = '#0f172a';
    ctx.font = '700 44px system-ui, sans-serif';
    ctx.fillText(auth.user.name, W / 2, 300);
    ctx.fillStyle = '#64748b';
    ctx.font = '500 26px system-ui, sans-serif';
    ctx.fillText('Scan to add me as a buddy on Amotan', W / 2, 345);

    const qr = document.createElement('canvas');
    await QRCode.toCanvas(qr, link.value, { width: 460, margin: 1, errorCorrectionLevel: 'H', color: { dark: '#0f172a', light: '#ffffff' } });
    ctx.drawImage(qr, W / 2 - 230, 380);
    ctx.fillStyle = '#fff';
    ctx.beginPath(); ctx.arc(W / 2, 610, 58, 0, Math.PI * 2); ctx.fill();
    ctx.drawImage(await loadImage(svgUrl('happy')), W / 2 - 52, 558, 104, 104);

    ctx.fillStyle = '#0f172a';
    ctx.font = '800 48px ui-monospace, monospace';
    ctx.fillText(code.value, W / 2, 905);
    ctx.fillStyle = '#ffffff';
    ctx.font = '700 28px system-ui, sans-serif';
    ctx.fillText('Amotan · offline-first money buddy', W / 2, H - 22);

    const blob = await new Promise((r) => c.toBlob(r, 'image/png'));
    return new File([blob], `amotan-${code.value}.png`, { type: 'image/png' });
}

async function share() {
    const text = `Add me on Amotan! My code: ${code.value}`;
    try {
        const file = await cardFile();
        if (navigator.canShare?.({ files: [file] })) return await navigator.share({ files: [file], title: 'My Amotan QR', text: `${text}\n${link.value}` });
        if (navigator.share) return await navigator.share({ title: 'My Amotan QR', text, url: link.value });
        download(file);
    } catch (e) {
        if (e?.name !== 'AbortError') ui.error(e);
    }
}

function download(file) {
    const a = document.createElement('a');
    a.href = URL.createObjectURL(file);
    a.download = file.name;
    a.click();
    setTimeout(() => URL.revokeObjectURL(a.href), 2000);
}
const downloadCard = async () => download(await cardFile());

async function copy(text, label) {
    try { await navigator.clipboard.writeText(text); ui.toast(`${label} copied`); } catch { ui.toast(text); }
}

async function reset() {
    if (!confirm('Get a new code? Your old QR stops working, but existing buddies stay.')) return;
    try {
        auth.user = await api.post('/profile/share-code');
        ui.toast('New code created');
    } catch (e) { ui.error(e); }
}

function addFriend(c = friendCode.value) {
    const parsed = parseBuddyCode(c);
    if (!parsed) return ui.toast('Enter a code like AMO-7KX3PQ', 'error');
    scanOpen.value = false;
    router.push(`/u/${parsed}`);
}

async function removeBuddy(b) {
    if (!confirm(`Remove ${b.name} from your buddies?`)) return;
    await api.del(`/connections/${b.id}`);
    loadBuddies();
}
</script>

<template>
    <div>
        <GoalTabs />
        <div class="space-y-4">
            <section class="card flex flex-col items-center text-center">
                <div class="flex items-center gap-2">
                    <Mascot mood="wink" :size="56" bob />
                    <div class="text-left">
                        <h2 class="text-lg font-bold">My Amotan QR</h2>
                        <p class="text-xs text-slate-500">Made for you automatically · works offline</p>
                    </div>
                </div>

                <template v-if="code">
                    <QrCode class="mt-4" :value="link" :size="240" badge :label="`Your Amotan QR code ${code}`" />
                    <p class="mt-3 font-semibold">{{ auth.user.name }}</p>
                    <button class="mt-1 font-mono text-2xl font-bold tracking-[0.2em]" title="Copy code" @click="copy(code, 'Code')">{{ code }}</button>
                    <div class="mt-4 grid w-full grid-cols-3 gap-2">
                        <button class="btn-primary" @click="share"><Icon name="send" size="16" />Share</button>
                        <button class="btn-ghost" @click="downloadCard"><Icon name="download" size="16" />Save</button>
                        <button class="btn-ghost" @click="copy(link, 'Link')">🔗 Link</button>
                    </div>
                    <p class="mt-3 text-xs text-slate-500">Friends scan this to become buddies, so you can invite each other to shared plans without sharing emails.</p>
                    <button class="mt-2 text-xs font-medium text-slate-500 underline disabled:opacity-40" :disabled="!sync.online" @click="reset">{{ sync.online ? 'Get a new code' : 'Go online to get a new code' }}</button>
                </template>
                <p v-else class="mt-4 text-sm text-slate-500">Connect to the internet once and Amo will make your personal code.</p>
            </section>

            <section class="card">
                <h3 class="mb-2 font-semibold">Add a buddy</h3>
                <div class="flex gap-2">
                    <button v-if="canScan" class="btn-ghost shrink-0" @click="scanOpen = true"><Icon name="camera" size="18" />Scan</button>
                    <form class="flex flex-1 gap-2" @submit.prevent="addFriend()">
                        <input v-model="friendCode" class="input uppercase" placeholder="AMO-XXXXXX" maxlength="80" aria-label="Friend's code" />
                        <button class="btn-primary shrink-0">Add</button>
                    </form>
                </div>
            </section>

            <section class="card">
                <h3 class="mb-2 font-semibold">Buddies <span class="text-sm font-normal text-slate-500">· {{ buddies.length }}</span></h3>
                <p v-if="!buddies.length" class="text-sm text-slate-500">No buddies yet. Share your QR, or scan a friend's.</p>
                <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                    <li v-for="b in buddies" :key="b.id" class="flex items-center gap-3 py-2.5">
                        <span class="flex h-9 w-9 items-center justify-center rounded-full bg-emerald-100 text-sm font-bold text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300">{{ b.name.slice(0, 1).toUpperCase() }}</span>
                        <span class="flex-1 text-sm font-medium">{{ b.name }}</span>
                        <button class="text-xs font-medium text-rose-600" @click="removeBuddy(b)">Remove</button>
                    </li>
                </ul>
            </section>
        </div>
        <QrScanSheet :open="scanOpen" title="Scan a friend's QR" @close="scanOpen = false" @buddy="addFriend" @code="(c) => router.push(`/join/${c}`)" />
    </div>
</template>
