// "Getting ready for offline": after login, save the app files, every screen's data
// and the on-device AI model, with one progress bar so the user knows when it's safe to go offline.
import { reactive, watch } from 'vue';
import { api } from './api';
import { ai, detectWebGPU, loadModel, recommendedModel, unloadModel, MODELS } from './ai/engine';
import { pref, setPref, thisMonth } from './format';

const VERSION = 'v4';
const WEIGHTS = { files: 1, data: 1, ai: 3 };
const blankStep = () => ({ p: 0, state: 'pending', note: '' });

export const ready = reactive({
    active: false,
    finished: false,
    percent: 0,
    errors: 0,
    steps: { files: blankStep(), data: blankStep(), ai: blankStep() },
});

function recompute() {
    let total = 0;
    let got = 0;
    for (const [k, s] of Object.entries(ready.steps)) {
        if (s.state === 'skipped') continue;
        total += WEIGHTS[k];
        got += WEIGHTS[k] * (s.state === 'done' ? 1 : Math.min(1, s.p));
    }
    ready.percent = total ? Math.floor((got / total) * 100) : 100;
}

const finish = (key, state, note = '') => {
    Object.assign(ready.steps[key], { state, note, p: state === 'done' ? 1 : ready.steps[key].p });
    if (state === 'error') ready.errors++;
    recompute();
};

const wait = (ms) => new Promise((r) => setTimeout(r, ms));

async function saveFiles() {
    const step = ready.steps.files;
    step.state = 'running';
    const reg = 'serviceWorker' in navigator ? await Promise.race([navigator.serviceWorker.ready, wait(15000)]) : null;
    if (!reg?.active) return finish('files', 'error', "Offline mode isn't available in this browser");
    const result = await new Promise((resolve) => {
        const ch = new MessageChannel();
        ch.port1.onmessage = ({ data }) => {
            step.p = data.total ? data.done / data.total : 1;
            recompute();
            if (data.finished) resolve(data);
        };
        reg.active.postMessage({ type: 'prepare' }, [ch.port2]);
        wait(300000).then(() => resolve({ failed: 1 }));
    });
    finish('files', result.failed ? 'error' : 'done', result.failed ? `${result.failed} file(s) couldn't be saved` : '');
}

async function saveData() {
    const step = ready.steps.data;
    step.state = 'running';
    const month = thisMonth();
    const reads = [
        ['/me'], ['/dashboard'], ['/transactions', { month, page: 1, per_page: 40 }], ['/budget', { month }],
        ['/categories'], ['/bills'], ['/goals'], ['/payday'], ['/payday/history'], ['/alerts'],
        ['/connections'], ['/ai/context'], ['/ai/status'],
    ];
    let total = reads.length + 1;
    let done = 0;
    let failed = 0;
    const get = async ([path, query]) => {
        try { return await api.get(path, query); } catch { failed++; return null; } finally {
            step.p = ++done / total;
            recompute();
        }
    };
    const [plans] = await Promise.all([get(['/plans']), ...reads.map(get)]);
    const ids = (plans?.plans || []).map((p) => p.id);
    total += ids.length * 4;
    await Promise.all(ids.flatMap((id) => ['', '/notes', '/events', '/messages'].map((sub) => get([`/plans/${id}${sub}`]))));
    finish('data', failed ? 'error' : 'done', failed ? `${failed} screen(s) couldn't be saved` : '');
}

async function prepareAi() {
    const step = ready.steps.ai;
    if (ai.backend !== 'device') return finish('ai', 'skipped', 'AI is off; instant answers work offline');
    await detectWebGPU();
    if (!ai.webgpu.supported) return finish('ai', 'skipped', "This browser can't run the on-device AI. Instant answers still work offline.");
    if (pref('ai_offline_skip', false)) return finish('ai', 'skipped', 'Skipped. You can download it in Settings.');
    if (ai.status === 'ready') return finish('ai', 'done');
    step.state = 'running';
    const base = ai.model || recommendedModel();
    const model = MODELS.find((m) => m.base === base);
    step.note = model ? `${model.label} · ${model.size}, one time` : '';
    const stop = watch(() => ai.progress, (v) => { step.p = v / 100; recompute(); }, { immediate: true });
    // autoStart may already be loading it.
    let ok = ai.status === 'loading' ? await new Promise((r) => { const s = watch(() => ai.status, (v) => v !== 'loading' && (s(), r(v === 'ready'))); }) : await loadModel(base);
    if (!ok && ai.status !== 'ready' && step.state === 'running' && ai.status !== 'error') ok = await loadModel(base);
    stop();
    if (step.state === 'skipped') return;
    ok || ai.status === 'ready' ? finish('ai', 'done') : finish('ai', 'error', ai.error || "The AI model couldn't be downloaded");
}

/** Stop downloading the AI model; everything else stays offline-ready. */
export async function skipAi() {
    setPref('ai_offline_skip', true);
    finish('ai', 'skipped', 'Skipped. You can download it in Settings.');
    if (ai.status === 'loading') await unloadModel();
}

export function needsPrepare(userId) {
    const r = pref('offline_ready', null);
    return !r || r.version !== VERSION || r.user !== userId;
}

export const resetReadiness = () => setPref('offline_ready', null);

export async function prepareOffline(userId) {
    if (ready.active || !navigator.onLine) return false;
    Object.assign(ready, { active: true, finished: false, percent: 0, errors: 0 });
    for (const k of Object.keys(ready.steps)) ready.steps[k] = blankStep();
    await Promise.all([saveFiles(), saveData(), prepareAi()]);
    recompute();
    Object.assign(ready, { active: false, finished: true });
    if (!ready.errors) setPref('offline_ready', { version: VERSION, user: userId, at: Date.now() });
    return !ready.errors;
}
