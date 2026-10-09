import { defineStore } from 'pinia';
import { api, token } from '../api';

export const useAuth = defineStore('auth', {
    state: () => ({ user: null, loaded: false }),
    getters: {
        loggedIn: (s) => !!s.user,
    },
    actions: {
        async load() {
            if (this.loaded) return;
            if (token.get()) {
                try { this.user = await api.get('/me'); } catch { this.user = null; }
            }
            this.loaded = true;
        },
        async login(email, password) {
            const res = await api.post('/login', { email, password, device: deviceName() });
            token.set(res.token);
            this.user = res.user;
        },
        async register(form) {
            const res = await api.post('/register', { ...form, device: deviceName() });
            token.set(res.token);
            this.user = res.user;
        },
        async logout() {
            try { await api.post('/logout'); } catch {}
            this.clear();
        },
        clear() {
            token.set(null);
            this.user = null;
            navigator.serviceWorker?.controller?.postMessage('clear-data');
        },
        async update(patch) {
            this.user = await api.patch('/profile', patch);
        },
    },
});

function deviceName() {
    const ua = navigator.userAgent;
    const os = /iPhone|iPad/.test(ua) ? 'iOS' : /Android/.test(ua) ? 'Android' : /Mac/.test(ua) ? 'Mac' : /Windows/.test(ua) ? 'Windows' : 'Web';
    return `${os} browser`;
}
