import { defineStore } from 'pinia';

let id = 0;

export const useUi = defineStore('ui', {
    state: () => ({ toasts: [], addOpen: false, addPreset: null, unreadAlerts: 0, refreshKey: 0 }),
    actions: {
        /** type: 'success' | 'error' | 'info'. */
        toast(message, type = 'success') {
            const t = { id: ++id, message, type, at: Date.now() };
            this.toasts = [...this.toasts.slice(-2), t];
            setTimeout(() => this.dismiss(t.id), type === 'error' ? 5000 : 3200);
        },
        dismiss(tid) {
            this.toasts = this.toasts.filter((x) => x.id !== tid);
        },
        /** A change was queued offline: fold that into the toast just shown for it, if any. */
        toastOffline() {
            const last = this.toasts.at(-1);
            if (last && last.type === 'success' && Date.now() - last.at < 1000) {
                last.message = `${last.message} · saved offline, will sync`;
                last.type = 'info';
            } else {
                this.toast("Saved on this device. Amo will sync it when you're online.", 'info');
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
