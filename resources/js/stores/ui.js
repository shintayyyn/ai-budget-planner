import { defineStore } from 'pinia';

let id = 0;
const timers = new Map();

export const useUi = defineStore('ui', {
    state: () => ({ toasts: [], addOpen: false, addPreset: null, unreadAlerts: 0, refreshKey: 0 }),
    actions: {
        /** type: 'success' | 'error' | 'info'. */
        toast(message, type = 'success') {
            const duration = type === 'error' ? 5000 : 3500;
            const t = { id: ++id, message, type, at: Date.now(), duration, paused: false };
            this.toasts.slice(0, -2).forEach((x) => this.dismiss(x.id));
            this.toasts.push(t);
            timers.set(t.id, { left: duration, start: Date.now(), h: setTimeout(() => this.dismiss(t.id), duration) });
        },
        dismiss(tid) {
            clearTimeout(timers.get(tid)?.h);
            timers.delete(tid);
            this.toasts = this.toasts.filter((x) => x.id !== tid);
        },
        /** Hovering or touching a toast holds it on screen. */
        pause(tid) {
            const tm = timers.get(tid);
            const t = this.toasts.find((x) => x.id === tid);
            if (!tm || !t || t.paused) return;
            clearTimeout(tm.h);
            tm.left -= Date.now() - tm.start;
            t.paused = true;
        },
        resume(tid) {
            const tm = timers.get(tid);
            const t = this.toasts.find((x) => x.id === tid);
            if (!tm || !t || !t.paused) return;
            tm.start = Date.now();
            tm.h = setTimeout(() => this.dismiss(tid), Math.max(600, tm.left));
            t.paused = false;
        },
        /** A change was queued offline: fold that into the toast just shown for it, if any. */
        toastOffline() {
            const last = this.toasts.at(-1);
            if (last && last.type === 'success' && Date.now() - last.at < 1000) {
                last.detail = "Saved offline. It'll sync when you're back online.";
                last.type = 'info';
            } else {
                this.toast("Saved on this device. It'll sync when you're online.", 'info');
            }
        },
        error(e) {
            this.toast(e?.message || 'Something went wrong', 'error');
        },
        openAdd(preset = null) {
            this.addPreset = preset;
            this.addOpen = true;
        },
        /** Tell open pages that data changed (e.g. after adding a transaction). */
        changed() {
            this.refreshKey++;
        },
    },
});
