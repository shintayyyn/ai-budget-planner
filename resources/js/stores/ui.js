import { defineStore } from 'pinia';

let id = 0;

export const useUi = defineStore('ui', {
    state: () => ({ toasts: [], addOpen: false, addPreset: null, unreadAlerts: 0, refreshKey: 0 }),
    actions: {
        toast(message, type = 'success') {
            const t = { id: ++id, message, type };
            this.toasts.push(t);
            setTimeout(() => { this.toasts = this.toasts.filter((x) => x.id !== t.id); }, 3200);
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
