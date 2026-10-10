// Count of user-visible requests and navigations in flight, for the top loading bar.
import { reactive } from 'vue';

export const progress = reactive({ active: 0 });

export async function track(promise) {
    progress.active++;
    try { return await promise; } finally { progress.active = Math.max(0, progress.active - 1); }
}
