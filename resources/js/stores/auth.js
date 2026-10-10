import { defineStore } from 'pinia';
import { api, token } from '../api';
import { cache } from '../offline';
import { wipeDevice, flush } from '../sync';
import { resetReadiness } from '../readiness';

export const useAuth = defineStore('auth', {
    state: () => ({ user: null, loaded: false }),
    getters: {
        loggedIn: (s) => !!s.user,
    },
    actions: {
        async load() {
            if (this.loaded) return;
            if (token.get()) {
                try {
                    this.user = await api.get('/me');
                } catch (e) {
                    // Offline with no saved copy yet: stay signed in rather than locking the user out.
                    this.user = e?.offline ? { name: '', onboarded: true, currency: 'USD' } : null;
                }
            }
            this.loaded = true;
        },
        async login(email, password) {
            const res = await api.post('/login', { email, password, device: deviceName() });
            await this.signedIn(res);
        },
        async register(form) {
            const res = await api.post('/register', { ...form, device: deviceName() });
            await this.signedIn(res);
        },
        async signedIn(res) {
            await wipeDevice();
            resetReadiness();
            token.set(res.token);
            this.user = res.user;
            cache.set('/api/me', res.user);
        },
        async logout() {
            try { await api.post('/logout'); } catch {}
            this.clear();
        },
        clear() {
            token.set(null);
            this.user = null;
            navigator.serviceWorker?.controller?.postMessage('clear-data');
            wipeDevice();
        },
        async update(patch) {
            const res = await api.patch('/profile', patch);
            this.user = res?.queued ? { ...this.user, ...patch } : res;
            cache.set('/api/me', this.user);
        },
        async syncNow() {
            return flush();
        },
    },
});

function deviceName() {
    const ua = navigator.userAgent;
    const os = /iPhone|iPad/.test(ua) ? 'iOS' : /Android/.test(ua) ? 'Android' : /Mac/.test(ua) ? 'Mac' : /Windows/.test(ua) ? 'Windows' : 'Web';
    return `${os} browser`;
}
