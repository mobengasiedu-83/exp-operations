import { onMounted, onUnmounted, ref, watch, type Ref } from 'vue';
import { live } from '@/routes/exp';
import type { LiveFilters, LiveSnapshot } from '@/types/live-operations';

function query(filters: LiveFilters): Record<string, string> {
    return Object.fromEntries(
        Object.entries(filters).filter(([, value]) => value !== ''),
    );
}

export function useLiveSnapshot(
    filters: Ref<LiveFilters>,
    refreshSeconds: Ref<number>,
    paused: Ref<boolean>,
    enabled: Ref<boolean> = ref(true),
) {
    const snapshot = ref<LiveSnapshot | null>(null);
    const error = ref('');
    const loading = ref(false);
    let timer: ReturnType<typeof setInterval> | undefined;

    async function refresh() {
        if (!enabled.value) {
            return;
        }

        loading.value = true;

        try {
            const response = await fetch(live.url({ query: query(filters.value) }), {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                throw new Error(`Request failed with ${response.status}`);
            }

            snapshot.value = (await response.json()) as LiveSnapshot;
            error.value = '';
        } catch {
            error.value = 'Unable to load live operations data.';
        } finally {
            loading.value = false;
        }
    }

    function schedule() {
        if (timer) {
            clearInterval(timer);
        }

        if (paused.value || !enabled.value) {
            return;
        }

        timer = setInterval(() => void refresh(), Math.max(refreshSeconds.value, 2) * 1000);
    }

    onMounted(() => {
        if (!enabled.value) {
            return;
        }

        void refresh();
        schedule();
    });

    onUnmounted(() => {
        if (timer) {
            clearInterval(timer);
        }
    });

    watch([refreshSeconds, paused, enabled], schedule);

    return { snapshot, error, loading, refresh };
}
