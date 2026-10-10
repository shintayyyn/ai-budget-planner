// On-device language model manager.
// - "device": WebLLM + WebGPU, entirely in the browser. Weights download once, then work offline.
// - "server": optional self-hosted Ollama via the Laravel API (AI_SERVER_DRIVER=ollama).
// - "off":    no language model; the built-in finance engine still answers instantly.
import { reactive } from 'vue';
import { api } from '../api';
import { pref, setPref } from '../format';

export const MODELS = [
    { base: 'Qwen2.5-0.5B-Instruct', label: 'Qwen 2.5 · 0.5B', size: '~400 MB', note: 'Fastest. Best for phones and older devices.' },
    { base: 'Qwen2.5-1.5B-Instruct', label: 'Qwen 2.5 · 1.5B', size: '~1 GB', note: 'Recommended balance of speed and quality.' },
    { base: 'Llama-3.2-1B-Instruct', label: 'Llama 3.2 · 1B', size: '~700 MB', note: 'Good alternative for mid-range phones.' },
    { base: 'Qwen2.5-3B-Instruct', label: 'Qwen 2.5 · 3B', size: '~2 GB', note: 'Smartest answers. Needs a laptop or recent GPU.' },
];

export const ai = reactive({
    backend: pref('ai_backend', 'device'), // device | server | off
    model: pref('ai_model', null),
    status: 'idle', // idle | checking | loading | ready | error | unsupported
    progress: 0,
    progressText: '',
    error: '',
    webgpu: null, // { supported, f16 }
    cached: {},
    serverAvailable: false,
    serverModel: null,
});

let engine = null;
let webllm = null;
let worker = null;

const lib = async () => (webllm ??= await import('@mlc-ai/web-llm'));

export const isMobile = () => /Android|iPhone|iPad|Mobile/i.test(navigator.userAgent) || (navigator.deviceMemory || 8) <= 4;

export async function detectWebGPU() {
    if (ai.webgpu) return ai.webgpu;
    let result = { supported: false, f16: false };
    try {
        const adapter = navigator.gpu && (await navigator.gpu.requestAdapter());
        if (adapter) result = { supported: true, f16: adapter.features.has('shader-f16') };
    } catch {}
    ai.webgpu = result;
    return result;
}

/** Full WebLLM model id for a base name, picking f16 shaders when the GPU supports them. */
export function modelId(base) {
    return `${base}-${ai.webgpu?.f16 ? 'q4f16_1' : 'q4f32_1'}-MLC`;
}

export function recommendedModel() {
    return isMobile() ? 'Qwen2.5-0.5B-Instruct' : 'Qwen2.5-1.5B-Instruct';
}

export async function refreshCacheInfo() {
    await detectWebGPU();
    if (!ai.webgpu.supported) return;
    const { hasModelInCache } = await lib();
    for (const m of MODELS) {
        try { ai.cached[m.base] = await hasModelInCache(modelId(m.base)); } catch { ai.cached[m.base] = false; }
    }
}

export async function checkServer() {
    try {
        const s = await api.get('/ai/status');
        ai.serverAvailable = s.server_driver === 'ollama' && s.ollama_available;
        ai.serverModel = s.ollama_model;
    } catch { ai.serverAvailable = false; }
    return ai.serverAvailable;
}

export function setBackend(backend) {
    ai.backend = backend;
    setPref('ai_backend', backend);
    if (backend === 'server') checkServer();
}

/** Download (first time) and start the on-device model. */
export async function loadModel(base = ai.model || recommendedModel()) {
    await detectWebGPU();
    if (!ai.webgpu.supported) {
        ai.status = 'unsupported';
        return false;
    }
    if (ai.status === 'loading') return false;
    ai.status = 'loading';
    ai.progress = 0;
    ai.progressText = 'Starting…';
    ai.error = '';
    try {
        const { CreateWebWorkerMLCEngine } = await lib();
        if (engine) await unloadModel();
        worker = new Worker(new URL('./llm-worker.js', import.meta.url), { type: 'module' });
        engine = await CreateWebWorkerMLCEngine(worker, modelId(base), {
            initProgressCallback: (p) => {
                ai.progress = Math.round((p.progress || 0) * 100);
                ai.progressText = p.text?.replace(/\[.*?\]\s*/, '') || '';
            },
        });
        ai.model = base;
        setPref('ai_model', base);
        setPref('ai_autoload', true);
        setPref('ai_offline_skip', false);
        ai.cached[base] = true;
        ai.status = 'ready';
        return true;
    } catch (e) {
        console.error(e);
        ai.status = 'error';
        ai.error = /memory|OOM|allocate/i.test(String(e)) ? 'Not enough GPU memory for this model. Try a smaller one.' : (e?.message || String(e));
        engine = null;
        worker?.terminate();
        worker = null;
        return false;
    }
}

export async function unloadModel() {
    try { await engine?.unload(); } catch {}
    worker?.terminate();
    engine = null;
    worker = null;
    if (ai.status === 'ready') ai.status = 'idle';
}

export async function deleteModel(base) {
    const { deleteModelAllInfoInCache } = await lib();
    if (ai.model === base) {
        await unloadModel();
        setPref('ai_autoload', false);
    }
    await deleteModelAllInfoInCache(modelId(base));
    ai.cached[base] = false;
}

/** Start the model automatically on app launch if it was used before and is cached. */
export async function autoStart() {
    if (ai.backend === 'server') return checkServer();
    if (ai.backend !== 'device' || !pref('ai_autoload', false) || !ai.model) return;
    await refreshCacheInfo();
    if (ai.cached[ai.model]) loadModel(ai.model);
}

/** True when some language model can answer right now. */
export function llmReady() {
    return (ai.backend === 'device' && ai.status === 'ready' && !!engine) || (ai.backend === 'server' && ai.serverAvailable);
}

/**
 * Chat completion with optional streaming. Returns the full text.
 * @param {{role: string, content: string}[]} messages
 */
export async function complete(messages, { onToken, json = false, temperature = 0.4, maxTokens = 400 } = {}) {
    if (ai.backend === 'server' && ai.serverAvailable) {
        const res = await api.post('/ai/chat', { messages, json });
        onToken?.(res.content);
        return res.content;
    }
    if (!engine) throw new Error('The on-device model is not loaded.');

    if (!onToken) {
        const r = await engine.chat.completions.create({ messages, temperature, max_tokens: maxTokens, ...(json ? { response_format: { type: 'json_object' } } : {}) });
        return r.choices[0]?.message?.content || '';
    }
    const stream = await engine.chat.completions.create({ messages, temperature, max_tokens: maxTokens, stream: true });
    let text = '';
    for await (const chunk of stream) {
        const delta = chunk.choices[0]?.delta?.content || '';
        if (delta) {
            text += delta;
            onToken(text);
        }
    }
    return text;
}

/** Ask the model for JSON and parse it defensively. */
export async function completeJSON(messages) {
    const text = await complete(messages, { json: ai.backend === 'device', temperature: 0, maxTokens: 200 });
    const match = text.match(/\{[\s\S]*\}/);
    if (!match) return null;
    try { return JSON.parse(match[0]); } catch { return null; }
}
